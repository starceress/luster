<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles image file migration for the import wizard across three
 modes: same-server path copy, remote URL fetch (SSRF-safe via
 NetworkAddressGuard), and ZIP archive extraction. Every mode
 validates the MIME type with finfo, generates a randomised
 filename, and writes to the appropriate uploads/ subdirectory.
 This class has no database access -- callers persist the returned
 filename themselves.
*/

declare(strict_types=1);

namespace Luster\Import;

use Luster\Core\NetworkAddressGuard;
use Luster\Core\UrlNormalizer;

/**
 * Migrates image files for the import wizard across three modes.
 *
 * Mode 1 — same-server path: copies a file by absolute path using copy().
 * Mode 2 — remote URL: downloads via cURL with DNS-pinned connections and SSRF
 *           protection from NetworkAddressGuard.
 * Mode 3 — ZIP archive: extracts a named entry from an open ZipArchive.
 *
 * Every mode validates the MIME type with finfo (jpeg/png/gif/webp only),
 * generates a randomised filename, writes to the appropriate uploads/ subdirectory,
 * and creates a protective .htaccess on first use. Failures are non-fatal and return
 * null so callers can log a warning and continue the import. This class has no
 * database access — callers persist the returned filename themselves.
 */
final class ImageMigrator
{
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /** Redirects fetchFromUrl() follows itself, after the first request. */
    private const MAX_REDIRECTS = 3;

    /** Size cap for every response body, redirects included. */
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const TIMEOUT_SECONDS = 10;

    /** Base path to public/uploads/ (no trailing slash). */
    private string $uploadsBase;

    /** @var callable(array{url: string, resolve: string, connect_timeout: int, timeout: int, verify_peer: bool, verify_host: bool, max_bytes: int}): array{code: int, location: ?string, body: string} */
    private $transfer;

    /**
     * @param string        $lusterPublicDir  Absolute path to Luster's public/ directory.
     * @param callable|null $transfer         One HTTP request (see curlTransfer()). Injected
     *                                        by tests only; the checks around it still run.
     */
    public function __construct(string $lusterPublicDir, ?callable $transfer = null)
    {
        $this->uploadsBase = rtrim($lusterPublicDir, '/\\') . '/uploads';
        $this->transfer    = $transfer ?? self::curlTransfer(...);
    }

    // -----------------------------------------------------------------------
    // Mode 1 — Same-server path copy
    // -----------------------------------------------------------------------

    /**
     * Copies a file from an absolute path on this server.
     *
     * @param string $sourcePath  Absolute path to the source image file.
     * @param string $targetSubDir  Subdirectory under uploads/ (e.g. 'affiliates')
     * @param string $prefix      Filename prefix (e.g. 'af_')
     * @return string|null  New filename on success; null if file missing/invalid.
     */
    public function copyFromPath(string $sourcePath, string $targetSubDir, string $prefix): ?string
    {
        if (!is_file($sourcePath)) {
            return null;
        }

        $mime = $this->detectMime($sourcePath);
        if ($mime === null) {
            return null;
        }

        $targetDir  = $this->ensureDir($targetSubDir);
        $newFilename = $this->generateFilename($prefix, self::ALLOWED_MIMES[$mime]);

        if (!copy($sourcePath, $targetDir . '/' . $newFilename)) {
            return null;
        }

        return $newFilename;
    }

    // -----------------------------------------------------------------------
    // Mode 2 — Remote URL fetch
    // -----------------------------------------------------------------------

    /**
     * Downloads an image from a remote URL via cURL with DNS-pinned connections.
     *
     * Redirects are followed here, one hop at a time (at most MAX_REDIRECTS), never
     * by cURL itself. Every hop is checked the same way as the first request: http or
     * https only, the host checked by NetworkAddressGuard, and the connection pinned
     * to the IPs it approved on that hop's own port (an http→https hop is pinned to
     * 443). A Location with any other scheme ends the download before another request
     * is made (UrlNormalizer::resolveHttpLocation()). Every hop keeps TLS verification,
     * the timeouts and the size cap; a redirect's body is never stored.
     *
     * Returns null if cURL is unavailable, a host is private or reserved, the request
     * fails or times out, there are too many redirects, or the final response is not
     * a valid image. Failures are non-fatal.
     *
     * @param string $url           Full URL to the image.
     * @param string $targetSubDir  Subdirectory under uploads/
     * @param string $prefix        Filename prefix
     * @return string|null  New filename on success; null on any failure.
     */
    public function fetchFromUrl(string $url, string $targetSubDir, string $prefix): ?string
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $request = self::buildRequest($current);
            if ($request === null) {
                return null;
            }

            $response = ($this->transfer)($request);
            $code     = (int) ($response['code'] ?? 0);
            $body     = (string) ($response['body'] ?? '');

            // The transfer caps the body; anything over the cap is a failure, whatever its status.
            if (strlen($body) > $request['max_bytes']) {
                return null;
            }

            if ($code >= 200 && $code < 300) {
                return $this->storeDownloaded($body, $targetSubDir, $prefix);
            }

            if ($code < 300 || $code >= 400) {
                return null;
            }

            $location = $response['location'] ?? null;
            $next     = is_string($location) ? UrlNormalizer::resolveHttpLocation($current, $location) : null;
            if ($next === null) {
                return null;
            }
            $current = $next;
        }

        return null;
    }

    /**
     * The request for one hop, or null when $url must not be fetched: not http(s),
     * no host, or a host NetworkAddressGuard rejects or can't resolve.
     *
     * @return array{url: string, resolve: string, connect_timeout: int, timeout: int, verify_peer: bool, verify_host: bool, max_bytes: int}|null
     */
    private static function buildRequest(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host        = NetworkAddressGuard::normalizeHost((string) ($parts['host'] ?? ''));
        $guardResult = NetworkAddressGuard::validateAndResolve($host);
        if ($guardResult['error'] !== null || $guardResult['ips'] === []) {
            return null;
        }

        $port = (int) (($parts['port'] ?? null) ?: ($scheme === 'https' ? 443 : 80));

        return [
            'url'             => $url,
            'resolve'         => NetworkAddressGuard::buildCurlResolveEntry($host, $port, $guardResult['ips']),
            'connect_timeout' => self::TIMEOUT_SECONDS,
            'timeout'         => self::TIMEOUT_SECONDS,
            'verify_peer'     => true,
            'verify_host'     => true,
            'max_bytes'       => self::MAX_BYTES,
        ];
    }

    /**
     * One HTTP request, exactly as described: no redirects followed, connection
     * pinned to the resolve entry, body capped. A body over the cap comes back as a
     * failed transfer (code 0) rather than a truncated file.
     *
     * @param array{url: string, resolve: string, connect_timeout: int, timeout: int, verify_peer: bool, verify_host: bool, max_bytes: int} $request
     * @return array{code: int, location: ?string, body: string}
     */
    private static function curlTransfer(array $request): array
    {
        $failed = ['code' => 0, 'location' => null, 'body' => ''];
        if (!function_exists('curl_init')) {
            return $failed;
        }

        $ch = curl_init($request['url']);
        if ($ch === false) {
            return $failed;
        }

        $maxBytes  = $request['max_bytes'];
        $bodyParts = [];
        $bodyLen   = 0;
        $tooBig    = false;
        $location  = null;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_CONNECTTIMEOUT => $request['connect_timeout'],
            CURLOPT_TIMEOUT        => $request['timeout'],
            CURLOPT_USERAGENT      => 'Luster-Import/1.0',
            CURLOPT_SSL_VERIFYPEER => $request['verify_peer'],
            CURLOPT_SSL_VERIFYHOST => $request['verify_host'] ? 2 : 0,
            CURLOPT_RESOLVE        => [$request['resolve']],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$bodyParts, &$bodyLen, &$tooBig, $maxBytes): int {
                $len = strlen($chunk);
                if ($bodyLen + $len > $maxBytes) {
                    $tooBig = true;
                    return 0;
                }
                $bodyParts[] = $chunk;
                $bodyLen += $len;
                return $len;
            },
        ]);

        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($tooBig) {
            return $failed;
        }

        return ['code' => $code, 'location' => $location, 'body' => implode('', $bodyParts)];
    }

    /** Checks a downloaded body is an allowed image and stores it. */
    private function storeDownloaded(string $data, string $targetSubDir, string $prefix): ?string
    {
        if ($data === '') {
            return null;
        }

        // Write to a temp file so finfo can inspect it
        $tmpPath = tempnam(sys_get_temp_dir(), 'lstr_img_');
        if ($tmpPath === false) {
            return null;
        }

        try {
            file_put_contents($tmpPath, $data);
            return $this->validateAndStore($tmpPath, $targetSubDir, $prefix);
        } finally {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Mode 3 — ZIP archive entry
    // -----------------------------------------------------------------------

    /**
     * Extracts a single entry from an open ZipArchive and stores it.
     *
     * The ZIP's internal path is intentionally ignored — only the entry index
     * is used to locate the data, preventing any path traversal.
     *
     * @param \ZipArchive $zip          Open archive.
     * @param int         $entryIndex   Entry index within the archive.
     * @param string      $targetSubDir Subdirectory under uploads/
     * @param string      $prefix       Filename prefix
     * @return string|null  New filename on success; null on any failure.
     */
    public function matchFromZipEntry(\ZipArchive $zip, int $entryIndex, string $targetSubDir, string $prefix): ?string
    {
        $stat = $zip->statIndex($entryIndex);
        if ($stat === false || $stat['size'] > 10 * 1024 * 1024) {
            return null;
        }

        $stream = $zip->getStream($zip->getNameIndex($entryIndex));
        if ($stream === false) {
            return null;
        }

        $data = stream_get_contents($stream, 10 * 1024 * 1024 + 1);
        fclose($stream);

        if ($data === false || $data === '') {
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'lstr_zip_');
        if ($tmpPath === false) {
            return null;
        }

        try {
            file_put_contents($tmpPath, $data);
            return $this->validateAndStore($tmpPath, $targetSubDir, $prefix);
        } finally {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Mode 3b — ZIP lookup by basename (pre-uploaded ZIP)
    // -----------------------------------------------------------------------

    /**
     * Searches an open ZipArchive for a file matching $originalName by basename
     * (case-insensitive). Ignores internal directory paths inside the ZIP.
     *
     * Call this during run() when the user pre-uploaded a ZIP in the Scope step.
     * Unlike matchFromZipEntry(), which requires the caller to know the entry
     * index, this method searches the entire archive by filename.
     *
     * Returns the new Luster filename on success, null if no matching entry is
     * found or the matched file fails MIME validation.
     *
     * @param \ZipArchive $zip          Open archive.
     * @param string      $originalName Source filename to search for.
     * @param string      $targetSubDir Subdirectory under uploads/
     * @param string      $prefix       Filename prefix for the new Luster filename
     */
    public function copyFromZip(\ZipArchive $zip, string $originalName, string $targetSubDir, string $prefix): ?string
    {
        $needle = strtolower(basename($originalName));
        if ($needle === '') {
            return null;
        }

        $count = $zip->count();
        for ($i = 0; $i < $count; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }
            if (strtolower(basename($entryName)) !== $needle) {
                continue;
            }
            return $this->matchFromZipEntry($zip, $i, $targetSubDir, $prefix);
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Validates a temp file's MIME type and moves it to the target upload dir.
     * Returns the new filename or null on failure.
     */
    private function validateAndStore(string $tmpPath, string $targetSubDir, string $prefix): ?string
    {
        $mime = $this->detectMime($tmpPath);
        if ($mime === null) {
            return null;
        }

        $targetDir   = $this->ensureDir($targetSubDir);
        $newFilename = $this->generateFilename($prefix, self::ALLOWED_MIMES[$mime]);
        $targetPath  = $targetDir . '/' . $newFilename;

        if (!copy($tmpPath, $targetPath)) {
            return null;
        }

        return $newFilename;
    }

    /**
     * Validates MIME type of a file. Returns the MIME string if allowed, null otherwise.
     */
    private function detectMime(string $path): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path);
        return isset(self::ALLOWED_MIMES[$mime]) ? $mime : null;
    }

    /**
     * Ensures the target upload subdirectory exists with correct permissions
     * and a protective .htaccess, then returns its absolute path. Delegates
     * to UploadDirectoryGuard, the single shared source of truth also used
     * by the admin upload controllers (ListingController, JoinedController,
     * WishlistAdminController) — this method used to write its own,
     * separate, weaker .htaccess (bare "\.php$", Apache 2.2-only
     * Deny-from-all syntax, no PHP-engine-off directives), which — since the
     * admin helper only ever fills a gap and never overwrites an existing
     * file — could get created here first by an import and then permanently
     * shadow the stronger version an admin upload would otherwise have
     * written to the same directory.
     */
    private function ensureDir(string $subDir): string
    {
        return \Luster\Core\UploadDirectoryGuard::ensure($this->uploadsBase . '/' . $subDir);
    }

    /**
     * Generates a new randomised filename using bin2hex(random_bytes(8)).
     */
    private function generateFilename(string $prefix, string $ext): string
    {
        return $prefix . bin2hex(random_bytes(8)) . '.' . $ext;
    }
}
