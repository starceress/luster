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
     * relative paths (e.g. "/new-path", "other.php" or "?v=2") become absolute URLs.
     * It doesn't check the scheme: use resolveHttpLocation() before following one.
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

        // Query-only (e.g. ?version=2) — same path, new query
        if (str_starts_with($location, '?')) {
            return self::withoutQueryOrFragment($requestUrl) . $location;
        }

        // Fragment-only (e.g. #top) — same address
        if (str_starts_with($location, '#')) {
            return (preg_replace('/#.*$/s', '', $requestUrl) ?? $requestUrl) . $location;
        }

        // Relative path — resolve against the directory of the request URL. Its query is
        // ignored, so a "/" inside the query can't move the directory.
        $path = self::withoutQueryOrFragment($requestUrl);
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://[^/]*$#', $path)) {
            return $path . '/' . $location; // no path at all, e.g. https://example.com
        }
        $base = preg_replace('#/[^/]*$#', '/', $path) ?? ($path . '/');
        return $base . $location;
    }

    /**
     * Like resolveLocation(), for redirects that are about to be followed: returns
     * null unless the result is an http(s) URL with a host.
     *
     * A Location with any other scheme (file:, ftp:, javascript:, data:, C:/…) is
     * rejected before resolving. resolveLocation() would otherwise treat it as a
     * relative path and turn file:///etc/passwd into https://host/dir/file:///etc/passwd,
     * which passes a scheme check made afterwards. No relative reference can start
     * with "word:" (RFC 3986), so nothing valid is lost.
     */
    public static function resolveHttpLocation(string $requestUrl, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $location) && !preg_match('#^https?://#i', $location)) {
            return null;
        }

        $resolved = self::resolveLocation($requestUrl, $location);
        $scheme   = strtolower((string) parse_url($resolved, PHP_URL_SCHEME));
        $host     = (string) parse_url($resolved, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        return $resolved;
    }

    /** The URL with its query string and fragment removed. */
    private static function withoutQueryOrFragment(string $url): string
    {
        return preg_replace('/[?#].*$/s', '', $url) ?? $url;
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
