<?php

declare(strict_types=1);

namespace Luster\Core;

use PDO;
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

    private const BACKUP_PATHS = [
        'src',
        'templates',
        'bootstrap.php',
        'composer.json',
        'composer.lock',
        'config/app.php',
        'config/routes.php',
        'public',
    ];

    /** @var callable(string): string|false */
    private $httpFetcher;

    public function __construct(
        private readonly string $basePath,
        private readonly Database $db,
        ?callable $httpFetcher = null,
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
        if ($isNewer !== true && version_compare($version, ltrim(Version::CURRENT, 'v'), '<') && isset($release['published_at'])) {
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
     * Creates a complete updater backup ZIP in storage/cache.
     *
     * @return array{path:string,filename:string}
     */
    public function createBackupZip(): array
    {
        $this->ensureZipAvailable();

        $cacheDir = $this->cacheDir();
        $filename = 'luster_backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
        $path = $cacheDir . DIRECTORY_SEPARATOR . $filename;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create backup ZIP.');
        }

        $zip->addFromString(
            'BACKUP-NOTES.txt',
            "Luster backup created " . date('c') . "\n\n"
            . "This backup contains Luster application files, lstr_* database tables,\n"
            . "the listing registry (owned/enth_fanlistings), and all per-listing member tables.\n"
        );

        foreach (self::BACKUP_PATHS as $rel) {
            $full = $this->path($rel);
            if (is_file($full)) {
                $zip->addFile($full, 'files/' . $this->zipPath($rel));
            } elseif (is_dir($full)) {
                $this->addDirectoryToZip($zip, $full, 'files/' . $this->zipPath($rel));
            }
        }

        $zip->addFromString('database/luster_database.sql', $this->dumpDatabase());
        $zip->close();

        return ['path' => $path, 'filename' => $filename];
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

    public function stagingIsValid(string $stagingDir): bool
    {
        try {
            $this->detectAppRoot($stagingDir);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function lockExists(): bool
    {
        return is_file($this->lockPath());
    }

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

        $checksumBody = $this->fetch((string) $checksumAsset['browser_download_url']);
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

        return [
            'manual_required' => false,
            'zip_url' => (string) $zipAsset['browser_download_url'],
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

    private function looksLikeAppRoot(string $path): bool
    {
        return is_dir($path . DIRECTORY_SEPARATOR . 'src')
            && is_dir($path . DIRECTORY_SEPARATOR . 'templates')
            && is_dir($path . DIRECTORY_SEPARATOR . 'public')
            && is_file($path . DIRECTORY_SEPARATOR . 'composer.json');
    }

    private function dumpDatabase(): string
    {
        $pdo = $this->db->pdo();
        $tables = $this->lusterTableNames($pdo);

        $out = [];
        $out[] = '-- Luster updater backup';
        $out[] = '-- Created: ' . date('c');
        $out[] = '-- Includes lstr_* tables, listing registry, and per-listing member tables.';
        $out[] = 'SET FOREIGN_KEY_CHECKS=0;';
        $out[] = '';

        foreach ($tables as $table) {
            $quoted = $this->quoteIdentifier($table);
            $row = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC);
            $create = is_array($row) ? (string) ($row['Create Table'] ?? array_values($row)[1] ?? '') : '';
            if ($create !== '') {
                $out[] = 'DROP TABLE IF EXISTS ' . $quoted . ';';
                $out[] = $create . ';';
                $out[] = '';
            }

            $stmt = $pdo->query('SELECT * FROM ' . $quoted);
            while (($record = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns = array_map(fn(string $col): string => $this->quoteIdentifier($col), array_keys($record));
                $values = array_map(fn(mixed $value): string => $this->sqlLiteral($pdo, $value), array_values($record));
                $out[] = 'INSERT INTO ' . $quoted . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
            }
            $out[] = '';
        }

        $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $out[] = '';

        return implode("\n", $out);
    }

    /**
     * @return array<int,string>
     */
    private function lusterTableNames(PDO $pdo): array
    {
        $allTables = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            $allTables[] = (string) ($row[0] ?? '');
        }

        $tables = [];

        // Luster's own tables
        foreach ($allTables as $table) {
            if (str_starts_with($table, 'lstr_') && preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $tables[] = $table;
            }
        }

        // Enthusiast registry tables
        foreach (['owned', 'enth_fanlistings'] as $registry) {
            if (in_array($registry, $allTables, true)) {
                $tables[] = $registry;
            }
        }

        // Per-listing member tables (from owned.dbtable or enth_fanlistings.dbprefix)
        foreach (['owned' => 'dbtable', 'enth_fanlistings' => 'dbprefix'] as $registry => $col) {
            if (!in_array($registry, $allTables, true)) {
                continue;
            }
            try {
                $stmt = $pdo->query("SELECT `{$col}` FROM `{$registry}`");
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $prefix = (string) ($row[0] ?? '');
                    if ($prefix === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
                        continue;
                    }
                    $memberTable = $registry === 'enth_fanlistings' ? $prefix . 'members' : $prefix;
                    if (in_array($memberTable, $allTables, true) && !in_array($memberTable, $tables, true)) {
                        $tables[] = $memberTable;
                    }
                }
            } catch (\Throwable) {}
        }

        sort($tables);
        return $tables;
    }

    private function addDirectoryToZip(ZipArchive $zip, string $dir, string $zipPrefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $full = $item->getPathname();
            $rel = $this->normalizeRelative(substr($full, strlen($dir) + 1));
            if ($this->shouldSkipBackupPath($this->normalizeRelative($zipPrefix . '/' . $rel))) {
                continue;
            }

            $zipPath = $this->zipPath($zipPrefix . '/' . $rel);
            if ($item->isDir()) {
                $zip->addEmptyDir($zipPath);
            } elseif ($item->isFile()) {
                $zip->addFile($full, $zipPath);
            }
        }
    }

    private function shouldSkipBackupPath(string $rel): bool
    {
        $rel = $this->normalizeRelative(preg_replace('#^files/#', '', $rel) ?? $rel);
        return str_starts_with($rel, 'vendor/')
            || str_starts_with($rel, 'storage/')
            || str_starts_with($rel, 'public/uploads/');
    }

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

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new RuntimeException('Unsafe database identifier in backup.');
        }

        return '`' . $identifier . '`';
    }

    private function sqlLiteral(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        return (string) $pdo->quote((string) $value);
    }

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

    private function releaseLock(): void
    {
        $lock = $this->lockPath();
        if (is_file($lock)) {
            @unlink($lock);
        }
    }

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

    private function ensureZipAvailable(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required before Luster can prepare updates.');
        }
    }

    private function cacheDir(): string
    {
        $dir = $this->path('storage/cache');
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Could not create storage/cache.');
        }

        return $dir;
    }

    private function lockPath(): string
    {
        return $this->cacheDir() . DIRECTORY_SEPARATOR . '.update_lock';
    }

    private function path(string $rel): string
    {
        return rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($rel, '/\\'));
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $this->basePath), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base)
            ? ltrim(substr($path, strlen($base)), '/')
            : $path;
    }

    private function normalizeRelative(string $rel): string
    {
        return trim(str_replace('\\', '/', $rel), '/');
    }

    private function zipPath(string $rel): string
    {
        return $this->normalizeRelative($rel);
    }

    private function isInside(string $path, string $base): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $base = rtrim(str_replace('\\', '/', $base), '/');

        return $path === $base || str_starts_with($path, $base . '/');
    }

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
