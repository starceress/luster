<?php

declare(strict_types=1);

namespace Luster\Core;

/**
 * Checks whether a fanlisting URL is registered on TFL or TAFL by fetching
 * the appropriate network's search page and looking for the URL in the body.
 *
 * Behaviour contract:
 *   - Only the approved host list is ever contacted (SSRF protection).
 *   - TLS certificate validation is always enabled.
 *   - Results are cached in $cacheDir for CACHE_TTL seconds (7 days).
 *   - Expired or missing cache entries trigger a live fetch on next call.
 *   - Free — no API key or gating required.
 */
final class NetworkDirectoryService
{
    public const CACHE_TTL = 86400 * 7;  // 7 days in seconds

    /** Hostnames this service is allowed to contact. */
    private const ALLOWED_HOSTS = [
        'thefanlistings.org',
        'www.thefanlistings.org',
        'animefanlistings.com',
        'www.animefanlistings.com',
    ];

    /**
     * Search endpoint patterns per network slug.
     * %s is replaced with the URL-encoded listing URL being checked.
     */
    private const ENDPOINTS = [
        'tfl'  => 'https://thefanlistings.org/?s=%s',
        'tafl' => 'https://animefanlistings.com/?s=%s',
    ];

    private const TIMEOUT        = 10;
    private const MAX_BODY_BYTES = 524288;  // 512 KB

    public function __construct(private readonly string $cacheDir) {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Returns the network status for $listingUrl on $network, using the cache
     * when fresh and making a live HTTP fetch otherwise.
     *
     * @param  string $network  'tfl' | 'tafl'
     * @return array{found: bool, http_code: int, checked_at: int, error: string|null}
     */
    public function lookup(string $listingUrl, string $network): array
    {
        $cached = $this->readCache($listingUrl, $network);
        if ($cached !== null) {
            return $cached;
        }

        return $this->fetch($listingUrl, $network);
    }

    /**
     * Forces a live fetch regardless of cache state.
     *
     * @return array{found: bool, http_code: int, checked_at: int, error: string|null}
     */
    public function refresh(string $listingUrl, string $network): array
    {
        return $this->fetch($listingUrl, $network);
    }

    /**
     * Returns the raw cached result without any live fetch, or null when the
     * cache is missing or expired.
     *
     * @return array{found: bool, http_code: int, checked_at: int, error: string|null}|null
     */
    public function getCached(string $listingUrl, string $network): ?array
    {
        return $this->readCache($listingUrl, $network);
    }

    /**
     * Removes the cached result for one (listingUrl, network) pair.
     */
    public function clearCache(string $listingUrl, string $network): void
    {
        $file = $this->cacheFile($listingUrl, $network);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Returns the list of supported network slugs. */
    public static function networks(): array
    {
        return array_keys(self::ENDPOINTS);
    }

    /** Returns a human-readable label for a network slug. */
    public static function networkLabel(string $network): string
    {
        return match ($network) {
            'tfl'  => 'TFL',
            'tafl' => 'TAFL',
            default => strtoupper($network),
        };
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @return array{found: bool, http_code: int, checked_at: int, error: string|null}
     */
    private function fetch(string $listingUrl, string $network): array
    {
        if (!isset(self::ENDPOINTS[$network])) {
            return $this->result(false, 0, 'Unknown network: ' . $network);
        }

        $searchUrl = sprintf(self::ENDPOINTS[$network], rawurlencode($listingUrl));
        $host      = (string) parse_url($searchUrl, PHP_URL_HOST);

        if (!in_array(strtolower($host), self::ALLOWED_HOSTS, true)) {
            return $this->result(false, 0, 'Host not in allowed list: ' . $host);
        }

        $guardError = NetworkAddressGuard::validatePublicHost($host);
        if ($guardError !== null) {
            return $this->result(false, 0, $guardError);
        }

        [$code, $body] = $this->get($searchUrl);

        if ($code === 0) {
            $result = $this->result(false, 0, 'HTTP request failed');
        } else {
            // "found" = the listing URL (or a normalised variant) appears in the body
            $needle = UrlNormalizer::normalizeUrlForSearch($listingUrl);
            $found  = $needle !== '' && $code >= 200 && $code < 300
                   && stripos($body, $needle) !== false;
            $result = $this->result($found, $code, null);
        }

        $this->writeCache($listingUrl, $network, $result);
        return $result;
    }

    /** @return array{int, string} [http_code, body] */
    private function get(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'timeout'         => self::TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 3,
                'ignore_errors'   => true,
                'header'          => "User-Agent: Mozilla/5.0 (compatible; LusterNetworkDir/1.0)\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $http_response_header = null;
        $body = @file_get_contents($url, false, $context, 0, self::MAX_BODY_BYTES);
        $body = is_string($body) ? $body : '';

        if (!is_array($http_response_header)) {
            return [0, ''];
        }

        $statusLine = $http_response_header[0] ?? '';
        preg_match('/HTTP\/[\d.]+\s+(\d+)/', $statusLine, $m);
        $code = isset($m[1]) ? (int) $m[1] : 0;

        return [$code, $body];
    }

    /**
     * @return array{found: bool, http_code: int, checked_at: int, error: string|null}
     */
    private function result(bool $found, int $httpCode, ?string $error): array
    {
        return [
            'found'      => $found,
            'http_code'  => $httpCode,
            'checked_at' => time(),
            'error'      => $error,
        ];
    }

    private function cacheFile(string $listingUrl, string $network): string
    {
        $key = md5($network . '|' . strtolower(trim($listingUrl)));
        return rtrim($this->cacheDir, '/') . '/netdir_' . $key . '.json';
    }

    /**
     * @return array{found: bool, http_code: int, checked_at: int, error: string|null}|null
     */
    private function readCache(string $listingUrl, string $network): ?array
    {
        $file = $this->cacheFile($listingUrl, $network);
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['checked_at'])) {
            return null;
        }

        if ((time() - (int) $data['checked_at']) > self::CACHE_TTL) {
            return null;  // expired
        }

        return [
            'found'      => (bool)  ($data['found']      ?? false),
            'http_code'  => (int)   ($data['http_code']  ?? 0),
            'checked_at' => (int)   ($data['checked_at'] ?? 0),
            'error'      => isset($data['error']) && $data['error'] !== null ? (string) $data['error'] : null,
        ];
    }

    private function writeCache(string $listingUrl, string $network, array $result): void
    {
        if (!is_dir($this->cacheDir) || !is_writable($this->cacheDir)) {
            return;
        }
        $file = $this->cacheFile($listingUrl, $network);
        @file_put_contents($file, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
