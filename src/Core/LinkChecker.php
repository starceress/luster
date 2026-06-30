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
