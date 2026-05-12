<?php

declare(strict_types=1);

namespace Luster\Core;

final class LinkChecker
{
    private const TIMEOUT        = 10;
    private const MAX_BODY_BYTES = 262144; // 256 KB — enough for a typical HTML page

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
            return ['status' => 'dead', 'redirect_url' => null, 'http_code' => 0];
        }

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::TIMEOUT,
                'follow_location' => 0,
                'ignore_errors'   => true,
                'header'          => "User-Agent: Mozilla/5.0 (compatible; LusterLinkChecker/1.0)\r\n",
            ],
            'ssl'  => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        // $http_response_header is populated in the local scope by file_get_contents()
        // when an HTTP wrapper is used. Pre-assign null so it is always defined here.
        $http_response_header = null;
        @file_get_contents($url, false, $context);

        if (!is_array($http_response_header)) {
            return ['status' => 'dead', 'redirect_url' => null, 'http_code' => 0];
        }

        $code = self::parseStatusCode($http_response_header[0] ?? '');

        if ($code >= 200 && $code < 300) {
            return ['status' => 'ok', 'redirect_url' => null, 'http_code' => $code];
        }

        if ($code >= 300 && $code < 400) {
            $raw      = self::parseLocation($http_response_header);
            $location = $raw !== null ? self::resolveLocation($url, $raw) : null;
            return ['status' => 'redirect', 'redirect_url' => $location, 'http_code' => $code];
        }

        return ['status' => 'dead', 'redirect_url' => null, 'http_code' => $code];
    }

    /**
     * Fetches $affiliateUrl (following redirects) and searches the body for $ownerUrl.
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
        $host = parse_url($affiliateUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '' || NetworkAddressGuard::validatePublicHost($host) !== null) {
            return 'unchecked';
        }

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'ignore_errors'   => true,
                'header'          => "User-Agent: Mozilla/5.0 (compatible; LusterLinkChecker/1.0)\r\n",
            ],
            'ssl'  => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $body = @file_get_contents($affiliateUrl, false, $context, 0, self::MAX_BODY_BYTES);
        if (!is_string($body) || $body === '') {
            return 'unchecked';
        }

        // Build a normalized search needle: host without www + path without trailing slash.
        // This matches http/https, www./non-www, and trailing-slash variants.
        $needle = self::normalizeUrlForSearch($ownerUrl);
        if ($needle === '') {
            return 'unchecked';
        }

        return stripos($body, $needle) !== false ? 'found' : 'missing';
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private static function parseStatusCode(string $statusLine): int
    {
        // Handles "HTTP/1.1 200 OK", "HTTP/1.0 301 Moved", "HTTP/2 404", etc.
        if (preg_match('/HTTP\/\S+\s+(\d{3})/', $statusLine, $m)) {
            return (int) $m[1];
        }
        return 0;
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

    /**
     * Resolves a Location header value against the original request URL so
     * relative paths (e.g. "/new-path" or "../other") become absolute URLs.
     */
    private static function resolveLocation(string $requestUrl, string $location): string
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
     * Strips the scheme and "www." prefix, then trims the trailing slash.
     * Used for scheme- and www-agnostic reciprocal matching.
     */
    private static function normalizeUrlForSearch(string $url): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./i', '', $host) ?? $host;
        $path = rtrim((string) (parse_url($url, PHP_URL_PATH) ?? '/'), '/');
        return $host . $path;
    }
}
