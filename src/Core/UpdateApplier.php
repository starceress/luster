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

use Luster\Repositories\SiteSettingsRepo;
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
        'vendor',
    ];

    /**
     * Paths that are never candidates for obsolete-file deletion, regardless
     * of whether they exist in the staged release. These are live-only,
     * user-owned content the release never ships (or ships empty) - deleting
     * them would destroy real data, not stale application files.
     * public/.htaccess is excluded here too; it has its own dedicated
     * reconciliation (reconcileHtaccess()) instead of being silently
     * deleted or blindly overwritten.
     *
     * install/ is deliberately NOT here - see the $installExisted handling
     * in apply() and shouldSkipApplyPath(). It's conditionally excluded
     * (only when absent live before the run starts), not unconditionally.
     */
    private const DELETE_HARD_EXCLUDE = [
        'public/uploads',
        'public/assets/css/skins',
        'storage',
        'config/app.php',
        'public/.htaccess',
        '.git',
    ];

    /**
     * Top-level paths a past release used to ship but no longer does. The
     * top-level scoping rule in planObsoleteFiles() only ever recurses into
     * entries the new release still ships, so a path fully retired from the
     * package (not just emptied) needs a manual entry here to be cleaned up
     * on existing installs. Expected to stay empty almost always - append to
     * it only on the rare occasion a whole shipped top-level path is retired.
     */
    private const KNOWN_REMOVED_TOP_LEVEL_PATHS = [];

/** @var callable(string): string|false */
    private $httpFetcher;

    private readonly SiteSettingsRepo $settingsRepo;

    public function __construct(
        private readonly string $basePath,
        private readonly Database $db,
        ?callable $httpFetcher = null,
        private readonly string $enthTablePrefix = '',
        ?SiteSettingsRepo $settingsRepo = null,
    ) {
        $this->httpFetcher = $httpFetcher ?? $this->defaultFetcher();
        $this->settingsRepo = $settingsRepo ?? new SiteSettingsRepo($db);
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
        // Only apply the date fallback for a genuine version-reset (remote strictly less
        // than installed, e.g. 1.0.18 -> 1.0.0 with a newer build). Equal versions are
        // never "newer" - see the same guard in UpdateChecker::getStatus().
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

            $preflight = $this->runPreflight((int) filesize($downloadPath), $stagingDir);
            $preview = $this->buildPreview($stagingDir);

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
                'preview' => $preview,
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
    public function runPreflight(int $zipSize, string $stagingDir): array
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

        $appRoot = null;
        try {
            $appRoot = $this->detectAppRoot($stagingDir);
        } catch (\Throwable) {
            $appRoot = null;
        }

        $packageComplete = $appRoot !== null && $this->packageIsComplete($appRoot);
        $results[] = [
            'key' => 'package_complete',
            'label' => 'Package completeness',
            'ok' => $packageComplete,
            'detail' => $packageComplete
                ? 'The staged package includes vendor/autoload.php and public/.htaccess.'
                : 'The staged package is missing vendor/autoload.php or public/.htaccess and cannot be safely applied.',
        ];

        $deletableOk = true;
        $undeletable = [];
        if ($appRoot !== null) {
            foreach ($this->planObsoleteFiles($stagingDir) as $rel) {
                if (!$this->pathIsDeletable($rel)) {
                    $deletableOk = false;
                    $undeletable[] = $rel;
                }
            }
        }
        $results[] = [
            'key' => 'deletable',
            'label' => 'Obsolete files removable',
            'ok' => $deletableOk,
            'detail' => $deletableOk
                ? 'Files no longer shipped by this release can be removed.'
                : 'Not writable: ' . implode(', ', array_slice($undeletable, 0, 5))
                    . (count($undeletable) > 5 ? ', and more.' : '.'),
        ];

        $free = disk_free_space($this->basePath);
        // Rollback backups now include vendor/ (see BackupService), so the
        // transient space needed to build that backup ZIP server-side scales
        // with vendor/'s own on-disk size, not just the downloaded update ZIP.
        $needed = $zipSize * 2 + $this->directorySize($this->path('vendor'));
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
     * Order matters: obsolete live files are deleted first (based on a diff
     * against the staged package), then the copy loop overlays everything
     * the new release ships, then public/.htaccess is reconciled separately
     * since it's excluded from both the delete pass and the copy loop.
     * install/'s live-existence is snapshotted once before any of this
     * starts - see the class docblock on DELETE_HARD_EXCLUDE and
     * shouldSkipApplyPath() for why a live re-check partway through would be
     * unsafe.
     *
     * @return array{copied:int,skipped:int,deleted:int,htaccess:string}
     */
    public function apply(string $stagingDir, ?string $downloadPath = null): array
    {
        $appRoot = $this->detectAppRoot($stagingDir);
        $this->assertPackageComplete($appRoot);

        $installExisted = is_dir($this->path('install'));

        $deleted = $this->deleteObsoleteFiles($this->planObsoleteFiles($stagingDir));

        $copied = 0;
        $skipped = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $source = $item->getPathname();
            $rel = $this->normalizeRelative(substr($source, strlen($appRoot) + 1));

            if ($rel === '' || $this->shouldSkipApplyPath($rel, $installExisted)) {
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

        $htaccess = $this->reconcileHtaccess($stagingDir);

        $this->discard($stagingDir, $downloadPath);

        return ['copied' => $copied, 'skipped' => $skipped, 'deleted' => $deleted, 'htaccess' => $htaccess];
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

    // -----------------------------------------------------------------------
    // Obsolete-file deletion
    // -----------------------------------------------------------------------

    /**
     * Computes which live files are no longer part of the staged release and
     * would be deleted by apply(). Returns paths relative to basePath. Never
     * writes anything - safe to call for a prepare-time / index-page preview.
     *
     * Only recurses into top-level entries that exist in BOTH the live
     * install and the staged package - a top-level name with no staging
     * counterpart (e.g. .git/, tests/, docs/ in a dev checkout) is left
     * completely alone. This is what makes the sweep safe to run against a
     * git checkout of this very project as well as a real production
     * install: nothing a release never ships is ever a deletion candidate.
     *
     * @return array<int,string>
     */
    public function planObsoleteFiles(string $stagingDir): array
    {
        $appRoot = $this->detectAppRoot($stagingDir);
        $obsolete = [];

        $liveEntries = @scandir($this->basePath);
        if ($liveEntries !== false) {
            foreach ($liveEntries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $rel = $name;
                if ($this->isHardExcludedFromDeletion($rel)) {
                    continue;
                }

                $stagedPeer = $appRoot . DIRECTORY_SEPARATOR . $name;
                if (!file_exists($stagedPeer)) {
                    // Not part of this release package at all - leave alone.
                    continue;
                }

                $this->diffObsolete($this->path($rel), $stagedPeer, $rel, $obsolete);
            }
        }

        foreach (self::KNOWN_REMOVED_TOP_LEVEL_PATHS as $rel) {
            if ($this->isHardExcludedFromDeletion($rel)) {
                continue;
            }
            if (file_exists($this->path($rel))) {
                $obsolete[] = $rel;
            }
        }

        return $obsolete;
    }

    /** Returns true when $rel is, or is inside, a DELETE_HARD_EXCLUDE entry. */
    private function isHardExcludedFromDeletion(string $rel): bool
    {
        $rel = $this->normalizeRelative($rel);
        foreach (self::DELETE_HARD_EXCLUDE as $excluded) {
            if ($rel === $excluded || str_starts_with($rel, $excluded . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively compares a live path against its staged counterpart,
     * appending obsolete relative paths to $obsolete by reference.
     *
     * A live file is obsolete when staging has no file at that exact path
     * (missing entirely, or a directory sits there instead). A live
     * directory is obsolete as a whole only when staging has a plain file at
     * that exact path instead (type-aware deletion - see the class-level
     * notes). Otherwise a live directory is always recursed into, even when
     * staging has nothing at all at that path - a wholly missing staged
     * directory is treated the same as an empty one, so every live file
     * underneath is still evaluated individually against
     * isHardExcludedFromDeletion(). This deliberately never collapses an
     * entirely-removed directory into a single obsolete entry: doing so
     * would risk sweeping up hard-excluded content (e.g. a community skin
     * file) nested arbitrarily deep inside it, without ever reaching the
     * exact-path check that protects it.
     *
     * When both sides agree on type and both exist, content differences are
     * left for the normal copy loop in apply() to overwrite - this method
     * only ever decides what to delete, never what to copy.
     *
     * @param array<int,string> $obsolete
     */
    private function diffObsolete(string $livePath, string $stagedPath, string $rel, array &$obsolete): void
    {
        if ($this->isHardExcludedFromDeletion($rel)) {
            return;
        }

        if (!is_dir($livePath)) {
            if (!is_file($stagedPath)) {
                $obsolete[] = $rel;
            }
            return;
        }

        if (file_exists($stagedPath) && !is_dir($stagedPath)) {
            $obsolete[] = $rel;
            return;
        }

        $entries = @scandir($livePath);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $childRel = $rel . '/' . $name;
            if ($this->isHardExcludedFromDeletion($childRel)) {
                continue;
            }

            $this->diffObsolete(
                $livePath . DIRECTORY_SEPARATOR . $name,
                $stagedPath . DIRECTORY_SEPARATOR . $name,
                $childRel,
                $obsolete,
            );
        }
    }

    /**
     * Deletes the given basePath-relative paths (files or whole directories),
     * then prunes any parent directories left empty as a result. Refuses to
     * touch anything that doesn't resolve inside basePath - a fresh guard
     * distinct from removeDirectory(), which stays hardcoded to
     * storage/cache and must never be reused for live-tree pruning.
     *
     * @param array<int,string> $relativePaths
     */
    private function deleteObsoleteFiles(array $relativePaths): int
    {
        $base = realpath($this->basePath);
        if ($base === false) {
            return 0;
        }

        $deleted = 0;
        $touchedDirs = [];

        foreach ($relativePaths as $rel) {
            $target = $this->path($rel);
            $real = realpath($target);
            if ($real === false || !$this->isInside($real, $base) || $real === $base) {
                continue;
            }

            if (is_dir($real) && !is_link($real)) {
                $deleted += $this->deleteLiveDirectory($real, $base);
            } elseif (is_file($real) || is_link($real)) {
                if (@unlink($real)) {
                    $deleted++;
                }
            }

            $touchedDirs[] = dirname($real);
        }

        foreach (array_unique($touchedDirs) as $dir) {
            $this->pruneEmptyAncestors($dir, $base);
        }

        return $deleted;
    }

    /** Recursively removes a live directory (guarded to inside $base). Returns the number of files deleted. */
    private function deleteLiveDirectory(string $dir, string $base): int
    {
        $real = realpath($dir);
        if ($real === false || !$this->isInside($real, $base) || $real === $base) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } elseif (@unlink($item->getPathname())) {
                $count++;
            }
        }
        @rmdir($real);

        return $count;
    }

    /** Walks upward from $dir removing each ancestor that is now empty, stopping at $base or the first non-empty directory. */
    private function pruneEmptyAncestors(string $dir, string $base): void
    {
        $real = realpath($dir);
        while ($real !== false && $this->isInside($real, $base) && $real !== $base) {
            $entries = @scandir($real);
            if ($entries === false || count($entries) > 2) {
                break;
            }
            if (!@rmdir($real)) {
                break;
            }
            $real = realpath(dirname($real));
        }
    }

    /** Returns true when $rel (or, if a live directory, everything inside it) can be deleted. Used by the 'deletable' preflight check. */
    private function pathIsDeletable(string $rel): bool
    {
        $target = $this->path($rel);

        if (is_dir($target) && !is_link($target)) {
            if (!is_writable(dirname($target))) {
                return false;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($iterator as $item) {
                if (!is_writable(dirname($item->getPathname()))) {
                    return false;
                }
            }

            return is_writable($target);
        }

        return is_writable(dirname($target));
    }

    /** Returns the total size in bytes of every file inside $dir, or 0 if it doesn't exist. */
    private function directorySize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $size += $item->getSize();
            }
        }

        return $size;
    }

    /**
     * @return array{obsolete_count:int,obsolete_paths:array<int,string>,htaccess_status:string}
     */
    private function buildPreview(string $stagingDir): array
    {
        $obsolete = $this->planObsoleteFiles($stagingDir);

        return [
            'obsolete_count' => count($obsolete),
            'obsolete_paths' => array_slice($obsolete, 0, 300),
            'htaccess_status' => $this->htaccessStatus($stagingDir),
        ];
    }

    /** Returns true when the staged package includes vendor/autoload.php and public/.htaccess. */
    private function packageIsComplete(string $appRoot): bool
    {
        return is_file($appRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')
            && is_file($appRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.htaccess');
    }

    /**
     * Throws when the staged package is missing vendor/autoload.php or
     * public/.htaccess. apply() is public and destructive - it must not
     * rely solely on the controller having already run runPreflight()
     * against the same staging dir, since vendor/ and .htaccess are now
     * update-critical. This is the real gate; the 'package_complete'
     * preflight check is the advisory version shown to the admin beforehand.
     */
    private function assertPackageComplete(string $appRoot): void
    {
        if (!$this->packageIsComplete($appRoot)) {
            throw new RuntimeException(
                'Update package is missing vendor/autoload.php or public/.htaccess and cannot be applied.'
            );
        }
    }

    // -----------------------------------------------------------------------
    // public/.htaccess reconciliation
    // -----------------------------------------------------------------------

    /**
     * Computes (without writing anything) what reconcileHtaccess() would do:
     *
     *   unchanged                - live already matches the staged content
     *   updated                  - live matches the last content Luster
     *                               itself wrote (update_htaccess_baseline_hash);
     *                               safe to auto-overwrite
     *   kept_customized          - live differs from both staged and the
     *                               known baseline; never auto-overwritten
     *   kept_customized_reviewed - same as above, but the admin has already
     *                               explicitly accepted this EXACT pairing -
     *                               this live content reviewed against this
     *                               staged default (update_htaccess_reviewed_live_hash
     *                               + update_htaccess_reviewed_staged_hash
     *                               both matching) - same "never touch it"
     *                               behaviour, quieter UI
     *   missing_in_package       - the staged release has no public/.htaccess
     *
     * update_htaccess_baseline_hash is the ONLY signal that ever authorises
     * an automatic overwrite. The two update_htaccess_reviewed_*_hash keys
     * only silence the "needs review" nag - they must never feed into the
     * overwrite decision. Keeping these separate from the baseline hash is
     * deliberate: it makes it structurally impossible for accepting a
     * customization to make that same file eligible for a silent overwrite
     * on a later update.
     *
     * Both reviewed hashes must match (live content AND staged default)
     * before the quiet status applies - matching only the live hash would
     * mean accepting release A's diff silently stays quiet about release B's
     * completely different diff later, even though the admin never saw it.
     */
    public function htaccessStatus(string $stagingDir): string
    {
        try {
            $appRoot = $this->detectAppRoot($stagingDir);
        } catch (\Throwable) {
            return 'missing_in_package';
        }

        $liveFile = $this->path('public/.htaccess');
        $stagedFile = $appRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.htaccess';

        if (!is_file($stagedFile)) {
            return 'missing_in_package';
        }

        $staged = hash_file('sha256', $stagedFile);
        $live = is_file($liveFile) ? hash_file('sha256', $liveFile) : false;

        if ($staged === false || $live === false) {
            return 'missing_in_package';
        }

        if ($staged === $live) {
            return 'unchanged';
        }

        $baseline = $this->settingsRepo->get('update_htaccess_baseline_hash');
        if ($baseline !== null && $baseline === $live) {
            return 'updated';
        }

        $reviewedLive = $this->settingsRepo->get('update_htaccess_reviewed_live_hash');
        $reviewedStaged = $this->settingsRepo->get('update_htaccess_reviewed_staged_hash');
        if ($reviewedLive !== null && $reviewedStaged !== null && $reviewedLive === $live && $reviewedStaged === $staged) {
            return 'kept_customized_reviewed';
        }

        return 'kept_customized';
    }

    /**
     * Applies the htaccessStatus() decision: overwrites public/.htaccess
     * only in the 'updated' case, and only ever writes
     * update_htaccess_baseline_hash there or in the 'unchanged' case (never
     * from live content that hasn't been proven to be Luster's own).
     * Otherwise leaves the live file untouched and (re)writes a reviewable
     * copy to storage/cache/public.htaccess.new - never into public/, where
     * a '.htaccess.new' name is not actually caught by the dotfile-block
     * FilesMatch rule and could be web-readable.
     */
    public function reconcileHtaccess(string $stagingDir): string
    {
        $status = $this->htaccessStatus($stagingDir);
        if ($status === 'missing_in_package') {
            return $status;
        }

        $appRoot = $this->detectAppRoot($stagingDir);
        $liveFile = $this->path('public/.htaccess');
        $stagedFile = $appRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.htaccess';
        $reviewPath = $this->htaccessReviewCopyPath();

        switch ($status) {
            case 'unchanged':
                $hash = hash_file('sha256', $liveFile);
                if ($hash !== false) {
                    $this->settingsRepo->set('update_htaccess_baseline_hash', $hash);
                }
                $this->deleteFileAt($reviewPath);
                break;

            case 'updated':
                $contents = file_get_contents($stagedFile);
                if ($contents === false || file_put_contents($liveFile, $contents, LOCK_EX) === false) {
                    throw new RuntimeException('Could not write public/.htaccess while applying update.');
                }
                $newHash = hash_file('sha256', $liveFile);
                if ($newHash !== false) {
                    $this->settingsRepo->set('update_htaccess_baseline_hash', $newHash);
                }
                // The old customization (if any) no longer describes what's
                // live - clear it so a stale reviewed pairing can't coincide
                // with some future customization by chance.
                $this->settingsRepo->set('update_htaccess_reviewed_live_hash', null);
                $this->settingsRepo->set('update_htaccess_reviewed_staged_hash', null);
                $this->deleteFileAt($reviewPath);
                break;

            case 'kept_customized':
            case 'kept_customized_reviewed':
                $contents = file_get_contents($stagedFile);
                if ($contents !== false) {
                    $dir = dirname($reviewPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($reviewPath, $contents, LOCK_EX);
                }
                break;
        }

        return $status;
    }

    /**
     * Returns the content an admin should review for public/.htaccess.
     *
     * Prefers reading directly from $stagingDir's staged file whenever a
     * valid staged update is currently prepared - that's always the release
     * actually being reviewed right now. Only falls back to the review copy
     * a previous reconcileHtaccess() run wrote to
     * storage/cache/public.htaccess.new when there is no active staging dir
     * (the normal post-apply case, once discard() has removed it).
     *
     * Getting this priority backwards is a real bug, not just a cosmetic
     * one: acceptHtaccessCustomization() hashes whatever this method
     * returns as the "staged" side of the reviewed pairing. If a stale
     * leftover .new from an earlier applied release were preferred while a
     * newer release is staged but not yet applied, the admin could be shown
     * - and "accept" - the wrong release's diff entirely, silently failing
     * to quiet the banner for the release actually in front of them.
     *
     * Returns null when neither source is available.
     */
    public function htaccessReviewContent(?string $stagingDir): ?string
    {
        if ($stagingDir !== null && $stagingDir !== '' && $this->stagingIsValid($stagingDir)) {
            try {
                $appRoot = $this->detectAppRoot($stagingDir);
                $stagedFile = $appRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.htaccess';
                if (is_file($stagedFile)) {
                    $contents = file_get_contents($stagedFile);
                    if ($contents !== false) {
                        return $contents;
                    }
                }
            } catch (\Throwable) {
                // Fall through to the cached review copy below.
            }
        }

        $reviewPath = $this->htaccessReviewCopyPath();
        if (is_file($reviewPath)) {
            $contents = file_get_contents($reviewPath);
            return $contents !== false ? $contents : null;
        }

        return null;
    }

    /**
     * Records that the admin has reviewed and manually merged the current
     * live public/.htaccess against the staged default they were just shown
     * (via htaccessReviewContent() - the same review copy / staged-file
     * fallback the download link and inline preview use, so "accept" always
     * pairs with whatever the admin actually looked at). Sets ONLY the two
     * update_htaccess_reviewed_*_hash keys - never update_htaccess_baseline_hash
     * - so accepting a customization can never make that file eligible for a
     * future automatic overwrite; it only silences the "needs review" banner,
     * and only for this exact (live content, staged default) pairing. A
     * later release shipping a different default is a different pairing and
     * will surface the banner again, even though the live file hasn't changed.
     */
    public function acceptHtaccessCustomization(?string $stagingDir): void
    {
        $liveFile = $this->path('public/.htaccess');
        if (!is_file($liveFile)) {
            return;
        }

        $stagedContent = $this->htaccessReviewContent($stagingDir);
        if ($stagedContent === null) {
            return;
        }

        $liveHash = hash_file('sha256', $liveFile);
        if ($liveHash === false) {
            return;
        }

        $this->settingsRepo->set('update_htaccess_reviewed_live_hash', $liveHash);
        $this->settingsRepo->set('update_htaccess_reviewed_staged_hash', hash('sha256', $stagedContent));
    }

    /** Absolute path to the public/.htaccess review copy inside storage/cache. */
    private function htaccessReviewCopyPath(): string
    {
        return $this->cacheDir() . DIRECTORY_SEPARATOR . 'public.htaccess.new';
    }

    /** Deletes $path if it exists as a file. Silent no-op otherwise. */
    private function deleteFileAt(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
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
     * (has its own reconciliation - see reconcileHtaccess()), storage/,
     * public/uploads/, and .git/ (defensive - staging never actually
     * contains .git/, since release packaging excludes it).
     *
     * vendor/ is deliberately NOT skipped here any more - it's now a fully
     * managed release-owned directory, same as src/ or templates/.
     *
     * install/...  (and the bare top-level 'install' entry) is skipped only
     * when $installExisted is false - i.e. the admin had already deleted it
     * before this apply() run started. $installExisted must be a single
     * snapshot taken once at the top of apply(), not a live is_dir() check
     * repeated on every call - the copy loop itself creates install/ via
     * mkdir() partway through, which would otherwise flip the answer
     * mid-run.
     */
    private function shouldSkipApplyPath(string $rel, bool $installExisted): bool
    {
        $rel = $this->normalizeRelative($rel);

        if (!$installExisted && ($rel === 'install' || str_starts_with($rel, 'install/'))) {
            return true;
        }

        return $rel === 'config/app.php'
            || $rel === 'public/.htaccess'
            || str_starts_with($rel, 'storage/')
            || str_starts_with($rel, 'public/uploads/')
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
