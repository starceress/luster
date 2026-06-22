<?php

declare(strict_types=1);

namespace Luster\Core;

final class LinkChecker
{
    private const TIMEOUT        = 10;
    private const MAX_BODY_BYTES = 262144; // 256 KB — enough for a typical HTML page
    private const MAX_HOPS       = 5;

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

        [$code, , $location] = self::singleGet($url);

        if ($code === 0) {
            return ['status' => 'dead', 'redirect_url' => null, 'http_code' => 0];
        }

        if ($code >= 200 && $code < 300) {
            return ['status' => 'ok', 'redirect_url' => null, 'http_code' => $code];
        }

        if ($code >= 300 && $code < 400) {
            $resolved = $location !== null ? UrlNormalizer::resolveLocation($url, $location) : null;
            return ['status' => 'redirect', 'redirect_url' => $resolved, 'http_code' => $code];
        }

        return ['status' => 'dead', 'redirect_url' => null, 'http_code' => $code];
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

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Makes a single non-redirecting GET request with TLS verification enabled.
     *
     * @return array{int, string, ?string}  [http_code, body, location_header_value]
     */
    private static function singleGet(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '' && NetworkAddressGuard::validatePublicHost($host) !== null) {
            return [0, '', null];
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
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $http_response_header = null;
        $body = @file_get_contents($url, false, $context, 0, self::MAX_BODY_BYTES);
        $body = is_string($body) ? $body : '';

        if (!is_array($http_response_header)) {
            return [0, '', null];
        }

        $code     = self::parseStatusCode($http_response_header[0] ?? '');
        $location = self::parseLocation($http_response_header);

        return [$code, $body, $location];
    }

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
}
