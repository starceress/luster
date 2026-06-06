<?php

declare(strict_types=1);

namespace Luster\Import;

use Luster\Core\NetworkAddressGuard;

/**
 * Handles all three image migration modes for the import wizard.
 *
 * Mode 1 (path): copy() from an absolute path on the same server.
 * Mode 2 (url):  fetch via file_get_contents() from a remote URL.
 * Mode 3 (zip):  extract a matching entry from an already-open ZipArchive.
 *
 * All modes:
 *  - Validate MIME with finfo (jpeg/png/gif/webp only)
 *  - Generate a new randomised Luster filename
 *  - Ensure the target directory exists and has a protective .htaccess
 *  - Return the new filename on success, or null on failure
 *
 * This class is intentionally free of any database access. The caller is
 * responsible for persisting the returned filename.
 */
final class ImageMigrator
{
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /** Base path to public/uploads/  (no trailing slash) */
    private string $uploadsBase;

    public function __construct(string $lusterPublicDir)
    {
        $this->uploadsBase = rtrim($lusterPublicDir, '/\\') . '/uploads';
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
     * Downloads an image from a remote URL.
     *
     * Returns null if file_get_contents() is disabled, the request times out,
     * or the response is not a valid image. Failures are non-fatal.
     *
     * @param string $url           Full URL to the image.
     * @param string $targetSubDir  Subdirectory under uploads/
     * @param string $prefix        Filename prefix
     * @return string|null  New filename on success; null on any failure.
     */
    public function fetchFromUrl(string $url, string $targetSubDir, string $prefix): ?string
    {
        if (!function_exists('file_get_contents')) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = NetworkAddressGuard::normalizeHost((string) ($parts['host'] ?? ''));
        if ($host === '' || NetworkAddressGuard::validatePublicHost($host) !== null) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout'         => 10,
                'follow_location' => false,
                'max_redirects'   => 0,
                'user_agent'      => 'Luster-Import/1.0',
            ],
            'https' => [
                'timeout'         => 10,
                'follow_location' => false,
                'max_redirects'   => 0,
                'user_agent'      => 'Luster-Import/1.0',
            ],
        ]);

        $data = @file_get_contents($url, false, $context, 0, 10 * 1024 * 1024);
        if ($data === false || $data === '') {
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
     * and a protective .htaccess, then returns its absolute path.
     */
    private function ensureDir(string $subDir): string
    {
        $dir = $this->uploadsBase . '/' . $subDir;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents(
                $htaccess,
                "Options -Indexes\n" .
                "<FilesMatch \"\\.php$\">\n" .
                "  Deny from all\n" .
                "</FilesMatch>\n" .
                "<FilesMatch \"\\.(jpg|jpeg|png|gif|webp)$\">\n" .
                "  Allow from all\n" .
                "</FilesMatch>\n"
            );
        }

        return $dir;
    }

    /**
     * Generates a new randomised filename using bin2hex(random_bytes(8)).
     */
    private function generateFilename(string $prefix, string $ext): string
    {
        return $prefix . bin2hex(random_bytes(8)) . '.' . $ext;
    }
}
