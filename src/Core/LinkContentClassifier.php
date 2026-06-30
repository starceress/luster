<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Classifies an already-fetched page body to surface advisory flags (parked domain,
 directory listing, meta-refresh redirect) that a plain HTTP status code cannot reveal.
 Operates purely on a string — no I/O, no second HTTP request.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * Pure-function classifier for link-checker body content.
 *
 * Every method is static and operates on a string body only — no I/O, no side effects.
 * Results are advisory: false positives are possible and expected; admins verify manually.
 */
final class LinkContentClassifier
{
    private const PARKED_MARKERS = [
        'this domain is for sale',
        'domain for sale',
        'buy this domain',
        'buy this website',
        'sedoparking',
        'parking page',
        'register this domain',
        'is parked free, courtesy of',
        'hugedomains.com',
        'afternic.com',
    ];

    /**
     * Classifies a page body and returns advisory flags.
     *
     * @return array{parked:bool, directory_listing:bool, meta_refresh:?string}
     */
    public static function classify(string $body): array
    {
        return [
            'parked'            => self::isParkedDomain($body),
            'directory_listing' => self::isDirectoryListing($body),
            'meta_refresh'      => self::extractMetaRefresh($body),
        ];
    }

    /** Returns true when the body contains common domain-parking page markers. */
    public static function isParkedDomain(string $body): bool
    {
        $lower = strtolower($body);
        foreach (self::PARKED_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }
        return false;
    }

    /** Returns true when the body looks like an Apache/nginx autoindex directory listing. */
    public static function isDirectoryListing(string $body): bool
    {
        return str_contains($body, 'Index of /')
            || preg_match('/<title>\s*Index of\b/i', $body) === 1;
    }

    /**
     * Returns the meta-refresh target URL when one is found in the body, or null.
     * Matches <meta http-equiv="refresh" content="N; url=..."> in any attribute order.
     */
    public static function extractMetaRefresh(string $body): ?string
    {
        // Find a <meta ... http-equiv="refresh" ...> tag (any attribute order)
        if (preg_match(
            '/<meta\b[^>]*http-equiv\s*=\s*["\']refresh["\'][^>]*>/i',
            $body,
            $tag,
        ) !== 1) {
            return null;
        }

        // Extract the content attribute value from the matched tag
        if (preg_match('~\bcontent\s*=\s*["\']([^"\']*)["\']~i', $tag[0], $contentMatch) !== 1) {
            return null;
        }

        // Extract URL from content value (e.g. "0; url=https://example.com")
        if (preg_match('/\burl\s*=\s*([^"\';\s>]+)/i', $contentMatch[1], $urlMatch) === 1) {
            return trim($urlMatch[1]);
        }

        return null;
    }
}
