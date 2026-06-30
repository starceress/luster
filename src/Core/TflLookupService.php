<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Searches TFL and TAFL for the approval-page URL of a fanlisting, given its
 subject. Fetches the network's approved-listings index page via the existing
 DNS-pinned, TLS-verified LinkChecker transport and caches the parsed link map
 in lstr_site_settings for 24 hours. Returns up to 10 candidate matches for
 the admin to review; the admin must explicitly save any URL they want to keep.
*/

declare(strict_types=1);

namespace Luster\Core;

use Luster\Repositories\SiteSettingsRepo;

/**
 * TFL/TAFL approval URL lookup helper.
 *
 * Fetches and caches the approved-listings index page for TFL or TAFL, parses
 * all hyperlinks, and searches for text matching the given fanlisting subject.
 * Results are candidates only — the admin confirms before saving.
 */
final class TflLookupService
{
    private const TFL_INDEX_URL  = 'https://thefanlistings.org/approved.php';
    private const TAFL_INDEX_URL = 'https://www.animefanlistings.com/approved.php';

    private const CACHE_TTL = 86400; // 24 hours in seconds

    private const CACHE_KEYS = [
        'tfl'  => ['links' => 'tfl_link_map_json',  'at' => 'tfl_link_map_fetched_at'],
        'tafl' => ['links' => 'tafl_link_map_json', 'at' => 'tafl_link_map_fetched_at'],
    ];

    /**
     * Searches TFL or TAFL for a fanlisting's approval URL by subject.
     *
     * Fetches (or reads from cache) the network's approved-listings index page,
     * then searches all hyperlinks for text matching $subject. Returns:
     *   url        — the exact-match URL when one is found, else null
     *   candidates — up to 10 partial/fuzzy matches for the admin to choose from
     *   error      — human-readable message when the page could not be fetched
     *
     * @return array{url: ?string, candidates: list<array{text: string, url: string}>, error: ?string}
     */
    public static function lookup(string $subject, string $network, SiteSettingsRepo $siteRepo): array
    {
        if (!in_array($network, ['tfl', 'tafl'], true)) {
            return ['url' => null, 'candidates' => [], 'error' => 'Unknown network.'];
        }

        [$links, $fetchError] = self::getCachedLinks($network, $siteRepo);

        if ($links === null) {
            return ['url' => null, 'candidates' => [], 'error' => $fetchError ?? 'Could not fetch the network index page.'];
        }

        return self::searchLinks($links, $subject) + ['error' => null];
    }

    /**
     * Returns the cache age in seconds for the given network, or null if not cached.
     */
    public static function cacheAge(string $network, SiteSettingsRepo $siteRepo): ?int
    {
        $keyAt = self::CACHE_KEYS[$network]['at'] ?? null;
        if ($keyAt === null) {
            return null;
        }
        $at = $siteRepo->get($keyAt);
        if ($at === null || $at === '') {
            return null;
        }
        return max(0, time() - (int) $at);
    }

    /**
     * Forces a fresh fetch of the network index page and updates the cache.
     * Returns true on success, false if the fetch failed.
     */
    public static function refreshCache(string $network, SiteSettingsRepo $siteRepo): bool
    {
        if (!in_array($network, ['tfl', 'tafl'], true)) {
            return false;
        }

        $url  = $network === 'tfl' ? self::TFL_INDEX_URL : self::TAFL_INDEX_URL;
        $html = LinkChecker::safeGet($url);

        if ($html === null || $html === '') {
            return false;
        }

        $links = self::parseLinks($html);
        self::storeCache($network, $links, $siteRepo);
        return true;
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * @return array{list<array{text: string, url: string}>|null, ?string}
     *   [links or null, error message or null]
     */
    private static function getCachedLinks(string $network, SiteSettingsRepo $siteRepo): array
    {
        $keys      = self::CACHE_KEYS[$network];
        $fetchedAt = $siteRepo->get($keys['at']);
        $cached    = $siteRepo->get($keys['links']);

        if ($fetchedAt !== null && $cached !== null && (time() - (int) $fetchedAt) < self::CACHE_TTL) {
            $links = json_decode($cached, true);
            if (is_array($links)) {
                return [$links, null];
            }
        }

        // Cache expired or invalid — fetch fresh
        $url  = $network === 'tfl' ? self::TFL_INDEX_URL : self::TAFL_INDEX_URL;
        $html = LinkChecker::safeGet($url);

        if ($html === null) {
            return [null, 'Could not connect to the network index page. The site may be down or temporarily unreachable.'];
        }

        if ($html === '') {
            return [null, 'The network index page returned an empty response.'];
        }

        $links = self::parseLinks($html);
        self::storeCache($network, $links, $siteRepo);
        return [$links, null];
    }

    /**
     * Extracts all [text, url] pairs from the HTML body.
     *
     * @return list<array{text: string, url: string}>
     */
    private static function parseLinks(string $html): array
    {
        $links = [];

        if (!preg_match_all('/<a\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            return $links;
        }

        foreach ($matches as $m) {
            $href = trim((string) $m[1]);
            $text = trim(strip_tags((string) $m[2]));
            if ($href === '' || $text === '') {
                continue;
            }
            // Skip navigation/utility links (empty or very short text, anchor-only hrefs)
            if (strlen($text) < 2 || $href[0] === '#') {
                continue;
            }
            $links[] = ['text' => $text, 'url' => $href];
        }

        return $links;
    }

    /**
     * Stores the parsed link list in lstr_site_settings as compact JSON.
     *
     * @param list<array{text: string, url: string}> $links
     */
    private static function storeCache(string $network, array $links, SiteSettingsRepo $siteRepo): void
    {
        $keys = self::CACHE_KEYS[$network];
        $json = (string) json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // TEXT column limit is 65 535 bytes; truncate the JSON array at a safe boundary
        if (strlen($json) > 60000) {
            // Re-encode a truncated slice — keep as many entries as fit
            for ($n = count($links); $n > 0; $n--) {
                $j = (string) json_encode(array_slice($links, 0, $n), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (strlen($j) <= 60000) {
                    $json = $j;
                    break;
                }
            }
        }
        $siteRepo->set($keys['links'], $json);
        $siteRepo->set($keys['at'], (string) time());
    }

    /**
     * Searches the link list for entries whose text matches $subject.
     *
     * Returns an exact match when the normalised text is identical to the
     * normalised subject, otherwise up to 10 partial matches.
     *
     * @param list<array{text: string, url: string}> $links
     * @return array{url: ?string, candidates: list<array{text: string, url: string}>}
     */
    private static function searchLinks(array $links, string $subject): array
    {
        $subNorm    = self::norm($subject);
        $exactUrl   = null;
        $candidates = [];

        if ($subNorm === '') {
            return ['url' => null, 'candidates' => []];
        }

        foreach ($links as $entry) {
            $textNorm = self::norm($entry['text']);

            if ($textNorm === $subNorm) {
                $exactUrl = $entry['url'];
                break;
            }

            if (str_contains($textNorm, $subNorm) || str_contains($subNorm, $textNorm)) {
                $candidates[] = $entry;
            }
        }

        return [
            'url'        => $exactUrl,
            'candidates' => array_slice($candidates, 0, 10),
        ];
    }

    /** Normalises a string for case/punctuation-insensitive comparison. */
    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/[^a-z0-9\s]/u', '', $s);
        return (string) preg_replace('/\s+/', ' ', $s);
    }
}
