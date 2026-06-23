<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 URL utility helpers for resolving relative redirect Location headers into
 absolute URLs and for producing scheme/www-agnostic search needles. Extracted
 from LinkChecker so the normalisation logic is reusable across the codebase
 without pulling in HTTP client dependencies.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * URL utility helpers used by LinkChecker and any other caller that needs to
 * resolve relative Location headers or produce scheme/www-agnostic search needles.
 */
final class UrlNormalizer
{
    /**
     * Resolves a Location header value against the original request URL so that
     * relative paths (e.g. "/new-path" or "other.php") become absolute URLs.
     */
    public static function resolveLocation(string $requestUrl, string $location): string
    {
        // Already absolute
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        // Protocol-relative (e.g. //example.com/path)
        if (str_starts_with($location, '//')) {
            $scheme = (string) (parse_url($requestUrl, PHP_URL_SCHEME) ?? 'https');
            return $scheme . ':' . $location;
        }

        // Root-relative (e.g. /new-path)
        if (str_starts_with($location, '/')) {
            $scheme = (string) (parse_url($requestUrl, PHP_URL_SCHEME) ?? 'https');
            $host   = (string) (parse_url($requestUrl, PHP_URL_HOST)   ?? '');
            $port   = parse_url($requestUrl, PHP_URL_PORT);
            $origin = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
            return $origin . $location;
        }

        // Relative path — resolve against directory of the request URL
        $base = preg_replace('#/[^/]*$#', '/', $requestUrl) ?? ($requestUrl . '/');
        return $base . $location;
    }

    /**
     * Strips scheme and "www." prefix, then trims the trailing slash.
     * Produces a scheme- and www-agnostic needle for reciprocal-link matching.
     */
    public static function normalizeUrlForSearch(string $url): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./i', '', $host) ?? $host;
        $path = rtrim((string) (parse_url($url, PHP_URL_PATH) ?? '/'), '/');
        return $host . $path;
    }

    /**
     * Upgrades a plain "http://" URL to "https://" by prefix swap. Leaves
     * "https://", protocol-relative, and empty values untouched.
     */
    public static function httpsify(string $url): string
    {
        return str_starts_with($url, 'http://') ? 'https://' . substr($url, 7) : $url;
    }
}
