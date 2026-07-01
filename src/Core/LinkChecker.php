<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 HTTP link checker for affiliate and joined-listing URLs. Classifies each
 URL as ok, dead, or redirect and can optionally perform a reciprocal check
 to verify that the target page links back to the owner's site. All outbound
 requests are routed through NetworkAddressGuard to prevent SSRF against
 private/loopback addresses.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * HTTP link checker for affiliate and joined-listing URLs.
 *
 * check() makes a single non-redirecting GET and classifies the URL as
 * 'ok', 'redirect', or 'dead'. checkReciprocal() fetches a page (following
 * up to MAX_HOPS redirects) and searches the body for the owner's URL.
 *
 * All outbound connections are routed through NetworkAddressGuard to prevent
 * SSRF — any host that resolves to a private/loopback IP is rejected before
 * the TCP connection is opened.
 */
final class LinkChecker
{
    private const TIMEOUT        = 10;
    private const MAX_BODY_BYTES = 262144; // 256 KB — enough for a typical HTML page
    private const MAX_HOPS       = 5;

    /** Common sub-paths tried after a 'missing' reciprocal result (Step 4.2). */
    private const RECIPROCAL_SUB_PATHS = ['/fanlistings/', '/affiliates/', '/links/'];

    /** Common member-list path candidates tried during owner-listed discovery (Step 4.3). */
    private const MEMBER_PATH_CANDIDATES = [
        '/members/', '/members.php', '/fans/', '/fans.php',
        '/members/list/', '/members/all/', '/fan-list/',
    ];

    /** Maximum outbound requests per checkOwnerListed() call (Step 4.3). */
    private const OWNER_LISTED_MAX_REQUESTS = 5;

    /**
     * Sends a single GET request (no redirect following) and classifies the URL.
     *
     * Returns:
     *   status       — 'ok' | 'dead' | 'redirect'
     *   redirect_url — the resolved absolute Location URL when status='redirect', else null
     *   http_code    — raw HTTP status code; 0 means the connection failed entirely
     */
    public static function check(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || NetworkAddressGuard::validatePublicHost($host) !== null) {
            return ['status' => 'dead', 'redirect_url' => null, 'http_code' => 0, 'content_flags' => null];
        }

        [$code, $body, $location] = self::singleGet($url);

        if ($code === 0) {
            return ['status' => 'dead', 'redirect_url' => null, 'http_code' => 0, 'content_flags' => null];
        }

        if ($code >= 200 && $code < 300) {
            return [
                'status'        => 'ok',
                'redirect_url'  => null,
                'http_code'     => $code,
                'content_flags' => LinkContentClassifier::classify($body),
            ];
        }

        if ($code >= 300 && $code < 400) {
            $resolved = $location !== null ? UrlNormalizer::resolveLocation($url, $location) : null;
            return ['status' => 'redirect', 'redirect_url' => $resolved, 'http_code' => $code, 'content_flags' => null];
        }

        return ['status' => 'dead', 'redirect_url' => null, 'http_code' => $code, 'content_flags' => null];
    }

    /**
     * Fetches $affiliateUrl (manually following redirects hop-by-hop, revalidating
     * each destination host with NetworkAddressGuard) and searches the body for $ownerUrl.
     *
     * Each redirect target is checked against NetworkAddressGuard before fetching,
     * preventing open-redirect SSRF where a public URL redirects to a private host.
     *
     * Matching is scheme- and www-agnostic: `http://example.com/path`,
     * `https://www.example.com/path`, and `//example.com/path` all match
     * an $ownerUrl of `https://example.com/path`.
     *
     * Returns:
     *   'found'     — back-link detected in the response body
     *   'missing'   — fetch succeeded but no back-link found
     *   'unchecked' — fetch failed or host is private (result indeterminate)
     */
    public static function checkReciprocal(string $affiliateUrl, string $ownerUrl): string
    {
        $needle = UrlNormalizer::normalizeUrlForSearch($ownerUrl);
        if ($needle === '') {
            return 'unchecked';
        }

        $url = $affiliateUrl;

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || $host === '' || NetworkAddressGuard::validatePublicHost($host) !== null) {
                return 'unchecked';
            }

            [$code, $body, $location] = self::singleGet($url);

            if ($code === 0) {
                return 'unchecked';
            }

            if ($code >= 200 && $code < 300) {
                return $body !== '' && stripos($body, $needle) !== false ? 'found' : 'missing';
            }

            if ($code >= 300 && $code < 400) {
                if ($location === null) {
                    return 'unchecked';
                }
                $url = UrlNormalizer::resolveLocation($url, $location);
                continue;
            }

            return 'unchecked';
        }

        return 'unchecked';
    }

    /**
     * Like checkReciprocal() but, on a 'missing' result, tries up to three
     * common sub-paths on the same origin (/fanlistings/, /affiliates/, /links/)
     * before giving up. Cross-origin redirect targets of $affiliateUrl are not
     * tried for sub-paths (the sub-paths are always on the original host).
     *
     * Returns 'found', 'missing', or 'unchecked' (same contract as checkReciprocal).
     */
    public static function checkReciprocalDeep(string $affiliateUrl, string $ownerUrl): string
    {
        $result = self::checkReciprocal($affiliateUrl, $ownerUrl);
        if ($result !== 'missing') {
            return $result;
        }

        $scheme = strtolower((string) (parse_url($affiliateUrl, PHP_URL_SCHEME) ?? ''));
        $host   = parse_url($affiliateUrl, PHP_URL_HOST);

        if (!is_string($host) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return 'missing';
        }

        // Reject if the URL embeds credentials — don't pass user:pass to sub-paths.
        if (parse_url($affiliateUrl, PHP_URL_USER) !== null) {
            return 'missing';
        }

        if (NetworkAddressGuard::validatePublicHost($host) !== null) {
            return 'unchecked';
        }

        foreach (self::RECIPROCAL_SUB_PATHS as $path) {
            $candidate = $scheme . '://' . $host . $path;
            if (self::checkReciprocal($candidate, $ownerUrl) === 'found') {
                return 'found';
            }
        }

        return 'missing';
    }

    /**
     * Checks whether $ownerName appears as a member on the joined fanlisting at $joinedUrl.
     *
     * Strategy:
     *   1. Fetch $joinedUrl and look for a members-page link in the HTML.
     *   2. If a separate members page is discovered, fetch it.
     *   3. If no link is found, probe MEMBER_PATH_CANDIDATES on the same origin.
     *   4. Search the members-page body for $ownerName (case-insensitive).
     *   5. If pagination markers are detected, never report 'not_found'.
     *
     * Total outbound requests are capped at OWNER_LISTED_MAX_REQUESTS.
     *
     * Returns an array:
     *   status      — 'listed' | 'not_found' | 'unchecked'
     *   members_url — the URL that was actually searched (or null)
     *   heuristic   — true when status='listed'; a name-only match has no duplicate
     *                 protection, so callers must treat it as a heuristic indicator
     */
    public static function checkOwnerListed(string $joinedUrl, string $ownerName): array
    {
        $unchecked = ['status' => 'unchecked', 'members_url' => null, 'heuristic' => false];

        $ownerName = trim($ownerName);
        if ($ownerName === '') {
            return $unchecked;
        }

        $scheme = strtolower((string) (parse_url($joinedUrl, PHP_URL_SCHEME) ?? ''));
        $host   = parse_url($joinedUrl, PHP_URL_HOST);

        if (!is_string($host) || $host === '' || !in_array($scheme, ['http', 'https'], true)) {
            return $unchecked;
        }

        if (NetworkAddressGuard::validatePublicHost($host) !== null) {
            return $unchecked;
        }

        $requestsLeft = self::OWNER_LISTED_MAX_REQUESTS;

        // Step 1 — fetch the listing's homepage and hunt for a members-page link.
        $homepageBody = self::safeGet($joinedUrl);
        $requestsLeft--;

        $membersUrl  = null;
        $membersBody = null;

        if ($homepageBody !== null) {
            $membersUrl = self::discoverMembersLink($homepageBody, $joinedUrl);

            if ($membersUrl !== null) {
                if ($membersUrl === $joinedUrl) {
                    // The members page IS the homepage — no additional fetch needed.
                    $membersBody = $homepageBody;
                } elseif ($requestsLeft > 0) {
                    // Discovered a separate members page; fetch it now.
                    $membersBody = self::safeGet($membersUrl);
                    $requestsLeft--;
                }
            }
        }

        // Step 2 — if no link was found on the homepage, probe canonical sub-paths.
        if ($membersUrl === null && $requestsLeft > 0) {
            foreach (self::MEMBER_PATH_CANDIDATES as $path) {
                if ($requestsLeft <= 0) {
                    break;
                }
                $candidate     = $scheme . '://' . $host . $path;
                $candidateHost = parse_url($candidate, PHP_URL_HOST);
                if (!is_string($candidateHost) || NetworkAddressGuard::validatePublicHost($candidateHost) !== null) {
                    continue;
                }
                $body = self::safeGet($candidate);
                $requestsLeft--;
                if ($body !== null && trim($body) !== '') {
                    // Body is already fetched; no second request needed.
                    $membersUrl  = $candidate;
                    $membersBody = $body;
                    break;
                }
            }
        }

        if ($membersUrl === null || $membersBody === null) {
            return $unchecked;
        }

        // Step 3 — search the members-page body for the owner name.
        $text = self::normalizeVisibleText($membersBody);
        if (stripos($text, $ownerName) !== false) {
            return ['status' => 'listed', 'members_url' => $membersUrl, 'heuristic' => true];
        }

        // Step 4 — if pagination detected, don't falsely report 'not_found'.
        if (self::hasPaginationHints($membersBody)) {
            return $unchecked;
        }

        return ['status' => 'not_found', 'members_url' => $membersUrl, 'heuristic' => false];
    }

    // -----------------------------------------------------------------------
    // Private helpers for checkOwnerListed
    // -----------------------------------------------------------------------

    /**
     * Scans $html (already fetched) for a hyperlink whose anchor text or href
     * suggests it leads to a members / fans list page. Returns the absolute URL
     * of the best candidate, or null if none found.
     */
    private static function discoverMembersLink(string $html, string $baseUrl): ?string
    {
        // Matches <a href="..."> with anchor text like "members", "fans", "fan list", etc.
        $pattern = '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>([^<]{1,60})<\/a>/i';
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER);

        $keywords = ['member', 'fan list', 'fans', 'fan-list', 'join', 'listed'];
        foreach ($matches as $m) {
            $anchor = strtolower(trim($m[2]));
            foreach ($keywords as $kw) {
                if (str_contains($anchor, $kw)) {
                    $resolved = UrlNormalizer::resolveLocation($baseUrl, $m[1]);
                    $rHost    = parse_url($resolved, PHP_URL_HOST);
                    $bHost    = parse_url($baseUrl, PHP_URL_HOST);
                    // Only follow same-host links.
                    if ($rHost === $bHost) {
                        return $resolved;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Strips HTML tags and decodes entities to produce searchable plain text.
     */
    private static function normalizeVisibleText(string $html): string
    {
        // Remove <script> and <style> blocks first.
        $text = preg_replace('@<(script|style)[^>]*>.*?</\1>@si', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    /**
     * Returns true if $html contains common pagination indicators, which means
     * we cannot conclude a name is absent just because it's not on this page.
     */
    private static function hasPaginationHints(string $html): bool
    {
        $patterns = [
            '/\bpage\s+\d+\s+of\s+\d+/i',
            '/\bnext\s+page\b/i',
            '/[?&]page=\d+/i',
            '/class=["\'][^"\']*\bpaginat/i',
            '/rel=["\']next["\']/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $html)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetches $url (following up to MAX_HOPS redirects, DNS-pinned and TLS-verified)
     * and returns the response body when the final status is 2xx, or null on any
     * failure (connection error, SSRF guard rejection, non-2xx final code).
     *
     * Uses the same NetworkAddressGuard-validated, 256 KB-capped transport as
     * check() and checkReciprocal(). Safe for fetching TFL/TAFL category pages.
     */
    public static function safeGet(string $url): ?string
    {
        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            $host = parse_url($url, PHP_URL_HOST);
            if (!is_string($host) || $host === '' || NetworkAddressGuard::validatePublicHost($host) !== null) {
                return null;
            }

            [$code, $body, $location] = self::singleGet($url);

            if ($code === 0) {
                return null;
            }

            if ($code >= 200 && $code < 300) {
                return $body;
            }

            if ($code >= 300 && $code < 400 && $location !== null) {
                $url = UrlNormalizer::resolveLocation($url, $location);
                continue;
            }

            return null;
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Makes a single non-redirecting GET request with TLS verification and
     * DNS-pinned connection via CURLOPT_RESOLVE to prevent rebinding.
     *
     * @return array{int, string, ?string}  [http_code, body, location_header_value]
     */
    private static function singleGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            return [0, '', null];
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return [0, '', null];
        }

        $result = NetworkAddressGuard::validateAndResolve($host);
        if ($result['error'] !== null || $result['ips'] === []) {
            return [0, '', null];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [0, '', null];
        }

        $responseHeaders = [];
        $bodyParts       = [];
        $bodyLen         = 0;
        $maxBody         = self::MAX_BODY_BYTES;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LusterLinkChecker/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                $responseHeaders[] = $header;
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$bodyParts, &$bodyLen, $maxBody): int {
                $len = strlen($chunk);
                if ($bodyLen + $len > $maxBody) {
                    $bodyParts[] = substr($chunk, 0, $maxBody - $bodyLen);
                    $bodyLen = $maxBody;
                    return 0;
                }
                $bodyParts[] = $chunk;
                $bodyLen += $len;
                return $len;
            },
        ]);

        $port    = parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        $resolve = [];
        foreach ($result['ips'] as $ip) {
            $resolve[] = $host . ':' . $port . ':' . $ip;
        }
        curl_setopt($ch, CURLOPT_RESOLVE, $resolve);

        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = implode('', $bodyParts);

        if ($code === 0) {
            return [0, '', null];
        }

        $location = self::parseLocation($responseHeaders);
        return [$code, $body, $location];
    }

    /**
     * @param string[] $headers
     */
    private static function parseLocation(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Location:') === 0) {
                return trim(substr($header, 9));
            }
        }
        return null;
    }
}
