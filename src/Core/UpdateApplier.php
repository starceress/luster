<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles the file-system side of applying Luster updates. Downloads the
 release archive from GitHub, verifies its SHA-256 checksum, creates a
 backup of the current installation, and extracts the new files with
 explicit skip rules for user-modified config and uploads. The controller
 owns the UI workflow; this class owns the risky disk operations.
*/

declare(strict_types=1);

namespace Luster\Core;

use RuntimeException;
use ZipArchive;

/**
 * Handles the file-system side of Luster updates.
 *
 * The controller owns the UI and session state; this class owns the risky work:
 * fresh release metadata fetches, checksum verification, archive extraction,
 * backup generation, and applying files with explicit skip rules.
 */
final class UpdateApplier
{
    private const GITHUB_API = 'https://api.github.com/repos/' . Version::GITHUB_REPO . '/releases/latest';
    private const FETCH_TIMEOUT = 30;

    private const REQUIRED_EXTENSIONS = [
        'pdo',
        'pdo_mysql',
        'mbstring',
        'json',
        'zip',
        'openssl',
    ];

    private const WRITABLE_PATHS = [
        'src',
        'templates',
        'public',
        'config',
    ];

/** @var callable(string): string|false */
    private $httpFetcher;

    public function __construct(
        private readonly string $basePath,
        private readonly Database $db,
        ?callable $httpFetcher = null,
        private readonly string $enthTablePrefix = '',
    ) {
        $this->httpFetcher = $httpFetcher ?? $this->defaultFetcher();
    }

    /**
     * Downloads, verifies, extracts, and preflights the newest release.
     *
     * @return array<string,mixed>
     */
    public function prepare(): array
    {
        $release = $this->fetchLatestRelease();
        $version = (string) $release['version'];

        $isNewer = UpdateChecker::compareVersions(Version::CURRENT, $version);
        $isNewerByDate = false;
        if ($isNewer !== true && version_compare($version, ltrim(Version::CURRENT, 'v'), '<=') && isset($release['published_at'])) {
            $remoteTs = strtotime((string) $release['published_at']);
            $builtTs  = strtotime(Version::BUILT_AT);
            $isNewerByDate = ($remoteTs !== false && $builtTs !== false && $remoteTs > $builtTs);
        }
        if ($isNewer !== true && !$isNewerByDate) {
            return [
                'ok' => false,
                'up_to_date' => true,
                'version' => $version,
                'release_url' => $release['html_url'],
            ];
        }

        $package = $this->resolvePackage($release);
        if (($package['manual_required'] ?? false) === true) {
            return array_merge($package, [
                'ok' => false,
                'version' => $version,
                'release_url' => $release['html_url'],
            ]);
        }

        $this->ensureZipAvailable();
        $cacheDir = $this->cacheDir();

        $lockAcquired = false;
        $downloadPath = null;
        $stagingDir = null;

        try {
            $this->acquireLock();
            $lockAcquired = true;

            $token = bin2hex(random_bytes(8));
            $downloadPath = $cacheDir . DIRECTORY_SEPARATOR . 'luster_dl_' . $token . '.zip';
            $stagingDir = $cacheDir . DIRECTORY_SEPARATOR . 'luster_update_' . $token;

            $this->downloadToFile((string) $package['zip_url'], $downloadPath);
            $actualHash = hash_file('sha256', $downloadPath);
            if ($actualHash === false || !hash_equals(strtolower((string) $package['sha256']), strtolower($actualHash))) {
                throw new RuntimeException('Downloaded update ZIP failed SHA256 verification.');
            }

            if (!mkdir($stagingDir, 0755, true) && !is_dir($stagingDir)) {
                throw new RuntimeException('Could not create update staging directory.');
            }

            $this->extractZipSafely($downloadPath, $stagingDir);
            $this->detectAppRoot($stagingDir);

            $preflight = $this->runPreflight((int) filesize($downloadPath));

            return [
                'ok' => true,
                'version' => $version,
                'release_url' => $release['html_url'],
                'release_title' => $release['name'],
                'zip_name' => $package['zip_name'],
                'sha256' => strtolower((string) $package['sha256']),
                'download_path' => $downloadPath,
                'staging_dir' => $stagingDir,
                'preflight' => $preflight,
            ];
        } catch (\Throwable $e) {
            if ($stagingDir !== null && is_dir($stagingDir)) {
                $this->removeDirectory($stagingDir);
            }
            if ($downloadPath !== null && is_file($downloadPath)) {
                @unlink($downloadPath);
            }
            if ($lockAcquired) {
                $this->releaseLock();
            }

            throw $e;
        }
    }

    /**
     * @return array<int,array{key:string,label:string,ok:bool,detail:string}>
     */
    public function runPreflight(int $zipSize): array
    {
        $cacheDir = $this->cacheDir();
        $results = [];

        $results[] = [
            'key' => 'php',
            'label' => 'PHP version',
            'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'detail' => 'Running PHP ' . PHP_VERSION . '; Luster requires PHP 8.1 or newer.',
        ];

        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn(string $ext): bool => !extension_loaded($ext),
        ));
        $results[] = [
            'key' => 'extensions',
            'label' => 'PHP extensions',
            'ok' => $missing === [],
            'detail' => $missing === []
                ? 'Required extensions are loaded: ' . implode(', ', self::REQUIRED_EXTENSIONS) . '.'
                : 'Missing: ' . implode(', ', $missing) . '.',
        ];

        $results[] = [
            'key' => 'cache_writable',
            'label' => 'Update staging',
            'ok' => is_dir($cacheDir) && is_writable($cacheDir),
            'detail' => $this->relativePath($cacheDir) . ' must be writable.',
        ];

        foreach (self::WRITABLE_PATHS as $rel) {
            $path = $this->path($rel);
            $results[] = [
                'key' => 'writable_' . str_replace(['/', '\\'], '_', $rel),
                'label' => $rel . ' writable',
                'ok' => is_dir($path) && is_writable($path),
                'detail' => $rel . '/ must be writable so updated files can be copied.',
            ];
        }

        $free = disk_free_space($this->basePath);
        $needed = $zipSize * 2;
        $results[] = [
            'key' => 'disk',
            'label' => 'Disk space',
            'ok' => $free !== false && $free >= $needed,
            'detail' => $free === false
                ? 'Could not determine available disk space.'
                : 'Available: ' . $this->formatBytes((int) $free) . '; required: at least ' . $this->formatBytes($needed) . '.',
        ];

        return $results;
    }

    /**
     * Creates a rollback-profile backup ZIP in storage/cache (excludes public/uploads/).
     *
     * @return array{path:string,filename:string}
     */
    public function createBackupZip(): array
    {
        return (new BackupService($this->db, $this->basePath, $this->enthTablePrefix))->createZip('rollback');
    }

    /**
     * Applies staged files over the live app.
     *
     * @return array{copied:int,skipped:int}
     */
    public function apply(string $stagingDir, ?string $downloadPath = null): array
    {
        $appRoot = $this->detectAppRoot($stagingDir);
        $copied = 0;
        $skipped = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $source = $item->getPathname();
            $rel = $this->normalizeRelative(substr($source, strlen($appRoot) + 1));

            if ($rel === '' || $this->shouldSkipApplyPath($rel)) {
                $skipped++;
                continue;
            }

            $target = $this->path($rel);

            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true)) {
                    throw new RuntimeException('Could not create directory while applying update: ' . $rel);
                }
                continue;
            }

            if (!$item->isFile()) {
                $skipped++;
                continue;
            }

            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
                throw new RuntimeException('Could not create directory while applying update: ' . dirname($rel));
            }
            if (is_file($target) && !is_writable($target)) {
                throw new RuntimeException('Target file is not writable: ' . $rel);
            }
            if (!is_writable($targetDir)) {
                throw new RuntimeException('Target directory is not writable: ' . dirname($rel));
            }
            if (!copy($source, $target)) {
                throw new RuntimeException('Could not copy update file: ' . $rel);
            }

            $copied++;
        }

        $this->discard($stagingDir, $downloadPath);

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /**
     * Removes the staging directory and downloaded ZIP and releases the update lock.
     * Call to cancel a prepared update or clean up after apply().
     */
    public function discard(?string $stagingDir, ?string $downloadPath): void
    {
        if ($stagingDir !== null && $stagingDir !== '' && is_dir($stagingDir)) {
            $this->removeDirectory($stagingDir);
        }
        if ($downloadPath !== null && $downloadPath !== '' && is_file($downloadPath)) {
            @unlink($downloadPath);
        }
        $this->releaseLock();
    }

    /**
     * Deletes a file, but only when it is inside the storage/cache directory.
     * Silently does nothing when the path resolves outside that boundary.
     */
    public function deleteFile(string $path): void
    {
        $real = realpath($path);
        $cache = realpath($this->cacheDir());
        if ($real === false || $cache === false || !$this->isInside($real, $cache)) {
            return;
        }
        if (is_file($real)) {
            @unlink($real);
        }
    }

    /**
     * Returns true when $stagingDir still exists and contains a recognisable Luster
     * package structure. Used to verify that a previously prepared update is still
     * intact before the admin clicks "Apply".
     */
    public function stagingIsValid(string $stagingDir): bool
    {
        try {
            $this->detectAppRoot($stagingDir);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Returns true when an update lock file is present, indicating a prepare() is in progress. */
    public function lockExists(): bool
    {
        return is_file($this->lockPath());
    }

    /**
     * Extracts the SHA256 hash for $assetName from a SHA256SUMS file.
     *
     * Handles files with or without filename columns (single-hash files return
     * the lone hash regardless of name). Returns null when no matching entry is found
     * or when the file is ambiguous (multiple hashes, none with a matching filename).
     */
    public static function parseChecksumForAsset(string $contents, string $assetName): ?string
    {
        $assetBase = basename(str_replace('\\', '/', $assetName));
        $fallback = null;
        $matches = 0;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!preg_match('/\b([a-fA-F0-9]{64})\b(?:\s+\*?(.+))?/', $line, $m)) {
                continue;
            }

            $matches++;
            $hash = strtolower($m[1]);
            $name = isset($m[2]) ? trim((string) $m[2], " \t\r\n\"'") : '';

            if ($name === '') {
                $fallback = $hash;
                continue;
            }

            $nameBase = basename(str_replace('\\', '/', $name));
            if (hash_equals($assetBase, $nameBase)) {
                return $hash;
            }
        }

        return $matches === 1 ? $fallback : null;
    }

    /**
     * Returns true when an archive entry name is safe to extract (no absolute paths,
     * no drive letters, no empty or dot-only segments, and no path traversal sequences).
     */
    public static function isSafeArchivePath(string $name): bool
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:\//', $name)) {
            return false;
        }

        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{version:string,tag:string,html_url:?string,name:?string,assets_url:?string,assets:array<int,array<string,mixed>>}
     */
    private function fetchLatestRelease(): array
    {
        $body = $this->fetch(self::GITHUB_API);
        if ($body === false || $body === '') {
            throw new RuntimeException('Could not reach GitHub. Check outbound internet access on the server.');
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('GitHub returned an unexpected release response.');
        }
        if (isset($data['message'])) {
            throw new RuntimeException('GitHub: ' . (string) $data['message']);
        }

        $tag = isset($data['tag_name']) ? (string) $data['tag_name'] : '';
        $version = ltrim($tag, 'v');
        if ($version === '' || !preg_match('/^\d+\.\d+/', $version)) {
            throw new RuntimeException('GitHub returned an unrecognised version tag.');
        }

        return [
            'version' => $version,
            'tag' => $tag,
            'html_url' => isset($data['html_url']) ? (string) $data['html_url'] : null,
            'name' => isset($data['name']) ? (string) $data['name'] : null,
            'published_at' => isset($data['published_at']) ? (string) $data['published_at'] : null,
            'assets_url' => isset($data['assets_url']) ? (string) $data['assets_url'] : null,
            'assets' => isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : [],
        ];
    }

    /**
     * @param array<string,mixed> $release
     * @return array<string,mixed>
     */
    private function resolvePackage(array $release): array
    {
        $assets = $release['assets'];
        if ($assets === [] && !empty($release['assets_url'])) {
            $body = $this->fetch((string) $release['assets_url']);
            $decoded = $body !== false ? json_decode((string) $body, true) : null;
            $assets = is_array($decoded) ? $decoded : [];
        }

        $zipAsset = $this->findZipAsset($assets);
        if ($zipAsset === null) {
            return [
                'manual_required' => true,
                'manual_reason' => 'The release does not include a packaged Luster ZIP asset.',
            ];
        }

        $checksumAsset = $this->findChecksumAsset($assets);
        if ($checksumAsset === null) {
            return [
                'manual_required' => true,
                'manual_reason' => 'The release does not include a SHA256SUMS asset.',
            ];
        }

        $checksumUrl = (string) $checksumAsset['browser_download_url'];
        if (!$this->isAllowedUpdateUrl($checksumUrl)) {
            return [
                'manual_required' => true,
                'manual_reason' => 'SHA256SUMS download URL is not on an expected GitHub host.',
            ];
        }

        $checksumBody = $this->fetch($checksumUrl);
        if ($checksumBody === false || $checksumBody === '') {
            throw new RuntimeException('Could not download SHA256SUMS from the release.');
        }

        $zipName = (string) $zipAsset['name'];
        $sha256 = self::parseChecksumForAsset((string) $checksumBody, $zipName);
        if ($sha256 === null) {
            return [
                'manual_required' => true,
                'manual_reason' => 'SHA256SUMS does not contain a checksum for ' . $zipName . '.',
            ];
        }

        $zipUrl = (string) $zipAsset['browser_download_url'];
        if (!$this->isAllowedUpdateUrl($zipUrl)) {
            return [
                'manual_required' => true,
                'manual_reason' => 'Release ZIP download URL is not on an expected GitHub host.',
            ];
        }

        return [
            'manual_required' => false,
            'zip_url' => $zipUrl,
            'zip_name' => $zipName,
            'sha256' => $sha256,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $assets
     * @return array<string,mixed>|null
     */
    private function findZipAsset(array $assets): ?array
    {
        $candidates = [];
        foreach ($assets as $asset) {
            $name = isset($asset['name']) ? (string) $asset['name'] : '';
            $url = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';
            if ($name === '' || $url === '' || !str_ends_with(strtolower($name), '.zip')) {
                continue;
            }
            if (str_contains(strtolower($name), 'sha256')) {
                continue;
            }
            $candidates[] = $asset;
        }

        usort($candidates, static function (array $a, array $b): int {
            $aName = strtolower((string) ($a['name'] ?? ''));
            $bName = strtolower((string) ($b['name'] ?? ''));
            $aScore = str_contains($aName, 'luster') ? 0 : 1;
            $bScore = str_contains($bName, 'luster') ? 0 : 1;
            return $aScore <=> $bScore ?: strcmp($aName, $bName);
        });

        return $candidates[0] ?? null;
    }

    /**
     * @param array<int,array<string,mixed>> $assets
     * @return array<string,mixed>|null
     */
    private function findChecksumAsset(array $assets): ?array
    {
        foreach ($assets as $asset) {
            $name = strtolower((string) ($asset['name'] ?? ''));
            $url = (string) ($asset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            if ($name === 'sha256sums' || $name === 'sha256sums.txt' || str_ends_with($name, '.sha256')) {
                return $asset;
            }
        }

        return null;
    }

    /** Downloads $url to a local file path. Throws RuntimeException on network or write failure. */
    private function downloadToFile(string $url, string $path): void
    {
        $body = $this->fetch($url);
        if ($body === false || $body === '') {
            throw new RuntimeException('Could not download update ZIP.');
        }
        if (file_put_contents($path, $body, LOCK_EX) === false) {
            throw new RuntimeException('Could not write downloaded update ZIP.');
        }
    }

    /**
     * Extracts a release ZIP to $stagingDir, checking each entry for path traversal,
     * absolute paths, and symlinks before writing. Throws RuntimeException on any
     * unsafe entry or I/O failure.
     */
    private function extractZipSafely(string $zipPath, string $stagingDir): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open downloaded update ZIP.');
        }

        $stagingReal = realpath($stagingDir);
        if ($stagingReal === false) {
            $zip->close();
            throw new RuntimeException('Update staging directory does not exist.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!self::isSafeArchivePath(rtrim($name, '/'))) {
                $zip->close();
                throw new RuntimeException('Unsafe path found in update ZIP: ' . $name);
            }
            if ($this->zipEntryIsSymlink($zip, $i)) {
                $zip->close();
                throw new RuntimeException('Symlinks are not allowed in update ZIPs: ' . $name);
            }

            $normalized = rtrim(str_replace('\\', '/', $name), '/');
            $target = $stagingDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);

            if (str_ends_with($name, '/')) {
                if (!is_dir($target) && !mkdir($target, 0755, true)) {
                    $zip->close();
                    throw new RuntimeException('Could not create directory from update ZIP: ' . $name);
                }
                continue;
            }

            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
                $zip->close();
                throw new RuntimeException('Could not create directory from update ZIP: ' . dirname($name));
            }

            $dirReal = realpath($targetDir);
            if ($dirReal === false || !$this->isInside($dirReal, $stagingReal)) {
                $zip->close();
                throw new RuntimeException('Update ZIP entry resolves outside staging: ' . $name);
            }

            $in = $zip->getStream($name);
            if ($in === false) {
                $zip->close();
                throw new RuntimeException('Could not read update ZIP entry: ' . $name);
            }
            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($in);
                $zip->close();
                throw new RuntimeException('Could not write update ZIP entry: ' . $name);
            }
            stream_copy_to_stream($in, $out);
            fclose($out);
            fclose($in);
        }

        $zip->close();
    }

    /** Returns true when the ZIP entry at $index has Unix external attributes indicating a symlink. */
    private function zipEntryIsSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        $mode = ($attr >> 16) & 0170000;
        return $mode === 0120000;
    }

    /**
     * Locates the Luster app root inside $stagingDir.
     *
     * Checks the directory itself first, then looks one level deeper (releases
     * often zip as 'luster-1.x.y/src/…'). Throws when no recognisable root is found.
     */
    private function detectAppRoot(string $stagingDir): string
    {
        $real = realpath($stagingDir);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('Update staging directory is missing.');
        }

        if ($this->looksLikeAppRoot($real)) {
            return $real;
        }

        $candidates = [];
        foreach (new \DirectoryIterator($real) as $entry) {
            if ($entry->isDir() && !$entry->isDot() && $this->looksLikeAppRoot($entry->getPathname())) {
                $candidates[] = $entry->getPathname();
            }
        }

        if ($candidates === []) {
            throw new RuntimeException('Update ZIP does not look like a Luster release package.');
        }

        sort($candidates);
        return $candidates[0];
    }

    /** Returns true when $path contains the expected top-level Luster directories and composer.json. */
    private function looksLikeAppRoot(string $path): bool
    {
        return is_dir($path . DIRECTORY_SEPARATOR . 'src')
            && is_dir($path . DIRECTORY_SEPARATOR . 'templates')
            && is_dir($path . DIRECTORY_SEPARATOR . 'public')
            && is_file($path . DIRECTORY_SEPARATOR . 'composer.json');
    }

    /**
     * Returns true when a staged file should not be copied to the live install.
     *
     * Skipped paths: config/app.php (user credentials), public/.htaccess
     * (user-customised routing), storage/, public/uploads/, vendor/, and .git/.
     */
    private function shouldSkipApplyPath(string $rel): bool
    {
        $rel = $this->normalizeRelative($rel);
        return $rel === 'config/app.php'
            || $rel === 'public/.htaccess'
            || str_starts_with($rel, 'storage/')
            || str_starts_with($rel, 'public/uploads/')
            || str_starts_with($rel, 'vendor/')
            || str_starts_with($rel, '.git/');
    }

    /** Creates the update lock file. Throws RuntimeException when a lock already exists. */
    private function acquireLock(): void
    {
        $lock = $this->lockPath();
        $handle = @fopen($lock, 'x');
        if ($handle === false) {
            throw new RuntimeException('Another update is already prepared or in progress.');
        }
        fwrite($handle, date('c') . PHP_EOL);
        fclose($handle);
    }

    /** Removes the update lock file if it exists. */
    private function releaseLock(): void
    {
        $lock = $this->lockPath();
        if (is_file($lock)) {
            @unlink($lock);
        }
    }

    /**
     * Recursively removes $dir and all its contents.
     *
     * Refuses to operate on any path outside the storage/cache directory as a
     * safeguard against accidental deletion of the live application.
     */
    private function removeDirectory(string $dir): void
    {
        $real = realpath($dir);
        $cache = realpath($this->cacheDir());
        if ($real === false || $cache === false || !$this->isInside($real, $cache)) {
            throw new RuntimeException('Refusing to remove a directory outside the update cache.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($real);
    }

    /** Throws RuntimeException when the PHP zip extension is not loaded. */
    private function ensureZipAvailable(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required before Luster can prepare updates.');
        }
    }

    /** Returns the absolute path to storage/cache, creating it if necessary. */
    private function cacheDir(): string
    {
        $dir = $this->path('storage/cache');
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Could not create storage/cache.');
        }

        return $dir;
    }

    /** Returns the absolute path to the update lock file inside storage/cache. */
    private function lockPath(): string
    {
        return $this->cacheDir() . DIRECTORY_SEPARATOR . '.update_lock';
    }

    /** Resolves a relative app path (e.g. 'src/Core') to an absolute filesystem path. */
    private function path(string $rel): string
    {
        return rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($rel, '/\\'));
    }

    /** Returns $path relative to basePath for display, or $path when it is not inside basePath. */
    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base)
            ? ltrim(substr($path, strlen($base)), '/')
            : $path;
    }

    /** Normalises backslashes to forward slashes and trims leading/trailing slashes. */
    private function normalizeRelative(string $rel): string
    {
        return trim(str_replace('\\', '/', $rel), '/');
    }

    /** Returns the normalised forward-slash path for a ZIP archive entry. */
    private function zipPath(string $rel): string
    {
        return $this->normalizeRelative($rel);
    }

    /** Returns true when $url is on an expected GitHub host (https only). */
    private function isAllowedUpdateUrl(string $url): bool
    {
        return (bool) preg_match(
            '#^https://(?:api\.github\.com|github\.com|objects\.githubusercontent\.com|codeload\.github\.com)/#',
            $url,
        );
    }

    /** Returns true when $path is $base or strictly inside $base (using forward slashes). */
    private function isInside(string $path, string $base): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $base = rtrim(str_replace('\\', '/', $base), '/');

        return $path === $base || str_starts_with($path, $base . '/');
    }

    /** Formats a byte count as a human-readable string (B, KB, MB, GB). */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return number_format($value, $unit === 0 ? 0 : 1) . ' ' . $units[$unit];
    }

    /** Fetches $url via the injected httpFetcher, returning false on failure. */
    private function fetch(string $url): string|false
    {
        try {
            return ($this->httpFetcher)($url);
        } catch (\Throwable) {
            return false;
        }
    }

    private function defaultFetcher(): \Closure
    {
        return function (string $url): string|false {
            $ua     = 'Luster/' . Version::CURRENT . ' (auto-update)';
            $accept = 'application/vnd.github+json, application/octet-stream';

            if (ini_get('allow_url_fopen')) {
                $context = stream_context_create([
                    'http' => [
                        'timeout'       => self::FETCH_TIMEOUT,
                        'user_agent'    => $ua,
                        'ignore_errors' => true,
                        'header'        => "Accept: {$accept}\r\n",
                    ],
                    'ssl' => [
                        'verify_peer'      => true,
                        'verify_peer_name' => true,
                    ],
                ]);
                $result = @file_get_contents($url, false, $context);
                if ($result !== false) {
                    return $result;
                }
            }

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 5,
                    CURLOPT_TIMEOUT        => self::FETCH_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => self::FETCH_TIMEOUT,
                    CURLOPT_USERAGENT      => $ua,
                    CURLOPT_HTTPHEADER     => ["Accept: {$accept}"],
                ]);
                $result = curl_exec($ch);
                $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if (is_string($result) && $code > 0) {
                    return $result;
                }
            }

            return false;
        };
    }
}
