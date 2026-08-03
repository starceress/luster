<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\BackupService;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Verifies BackupService path-exclusion logic for both backup profiles without
 * running an actual ZIP build (which requires a real DB connection). The private
 * shouldSkipPath() method is exercised via ReflectionMethod — the whole point of
 * this test is that extracting the method into BackupService didn't change its
 * behaviour compared to the old UpdateApplier::shouldSkipBackupPath().
 */
final class BackupServiceTest extends TestCase
{
    private function shouldSkip(string $rel, bool $includeUploads, bool $includeVendor = false): bool
    {
        // Database is final — use SqliteDatabase helper to get a real instance.
        // shouldSkipPath() never touches the database, so an empty schema is fine.
        $db      = SqliteDatabase::make('');
        $service = new BackupService($db, sys_get_temp_dir());
        $method  = new \ReflectionMethod($service, 'shouldSkipPath');
        $method->setAccessible(true);
        return (bool) $method->invoke($service, $rel, $includeUploads, $includeVendor);
    }

    // --- rollback profile ($includeUploads = false, $includeVendor = true) --

    public function testRollbackExcludesUploads(): void
    {
        self::assertTrue($this->shouldSkip('files/public/uploads/foo.jpg', false, true));
        self::assertTrue($this->shouldSkip('files/public/uploads/subdir/bar.png', false, true));
    }

    public function testRollbackIncludesVendor(): void
    {
        // Rollback backups now include vendor/ - UpdateApplier deletes/rewrites
        // it while applying an update, so a rollback snapshot has to be able to
        // restore it without requiring Composer on the server.
        self::assertFalse($this->shouldSkip('files/vendor/autoload.php', false, true));
        self::assertFalse($this->shouldSkip('files/vendor/phpmailer/phpmailer/src/PHPMailer.php', false, true));
    }

    public function testRollbackExcludesStorage(): void
    {
        self::assertTrue($this->shouldSkip('files/storage/logs/error.log', false, true));
        self::assertTrue($this->shouldSkip('files/storage/cache/luster_backup_20260101.zip', false, true));
    }

    public function testRollbackIncludesSrcAndTemplates(): void
    {
        self::assertFalse($this->shouldSkip('files/src/Core/Application.php', false, true));
        self::assertFalse($this->shouldSkip('files/templates/admin/layout.php', false, true));
    }

    public function testRollbackIncludesPublicExceptUploads(): void
    {
        self::assertFalse($this->shouldSkip('files/public/assets/css/admin.css', false, true));
        self::assertFalse($this->shouldSkip('files/public/embed/join.php', false, true));
        self::assertFalse($this->shouldSkip('files/public/.htaccess', false, true));
    }

    // --- complete profile ($includeUploads = true, $includeVendor = false) --

    public function testCompleteIncludesUploads(): void
    {
        self::assertFalse($this->shouldSkip('files/public/uploads/listing1/badge.png', true));
        self::assertFalse($this->shouldSkip('files/public/uploads/affiliates/logo.gif', true));
    }

    public function testCompleteStillExcludesVendor(): void
    {
        self::assertTrue($this->shouldSkip('files/vendor/autoload.php', true));
    }

    public function testCompleteStillExcludesStorage(): void
    {
        self::assertTrue($this->shouldSkip('files/storage/logs/error.log', true));
    }

    public function testCompleteIncludesSrcAndTemplates(): void
    {
        self::assertFalse($this->shouldSkip('files/src/Core/BackupService.php', true));
        self::assertFalse($this->shouldSkip('files/templates/email/join.php', true));
    }

    // --- paths without the 'files/' prefix (e.g. top-level entries) ---------

    public function testStripsFilesPrefixBeforeChecking(): void
    {
        // Should behave the same whether the 'files/' prefix is present or not
        self::assertTrue($this->shouldSkip('vendor/autoload.php', false));
        self::assertFalse($this->shouldSkip('vendor/autoload.php', false, true));
        self::assertTrue($this->shouldSkip('storage/cache/tmp.zip', false));
        self::assertTrue($this->shouldSkip('public/uploads/foo.png', false));
        self::assertFalse($this->shouldSkip('public/uploads/foo.png', true));
    }

    // --- cleanupStale() -------------------------------------------------------

    public function testCleanupStaleRemovesOldZipsButKeepsFreshOnes(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_backup_cleanup_test_' . uniqid('', true);
        $cacheDir = $base . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        mkdir($cacheDir, 0777, true);

        $stale = $cacheDir . DIRECTORY_SEPARATOR . 'luster_backup_20260101_000000_deadbeef.zip';
        $fresh = $cacheDir . DIRECTORY_SEPARATOR . 'luster_backup_20260102_000000_cafebabe.zip';
        $unrelated = $cacheDir . DIRECTORY_SEPARATOR . 'luster_dl_deadbeef.zip';
        file_put_contents($stale, 'x');
        file_put_contents($fresh, 'x');
        file_put_contents($unrelated, 'x');
        touch($stale, time() - 90000); // > 24h old
        touch($fresh, time() - 60);    // fresh

        try {
            (new BackupService(SqliteDatabase::make(''), $base))->cleanupStale();

            self::assertFileDoesNotExist($stale);
            self::assertFileExists($fresh);
            self::assertFileExists($unrelated, 'cleanupStale() only targets luster_backup_*.zip');
        } finally {
            @unlink($fresh);
            @unlink($unrelated);
            @rmdir($cacheDir);
            @rmdir($base . DIRECTORY_SEPARATOR . 'storage');
            @rmdir($base);
        }
    }

    public function testCleanupStaleIsNoOpWhenCacheDirMissing(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_backup_cleanup_test_nodir_' . uniqid('', true);

        // Must not throw / must not create storage/cache just by sweeping.
        (new BackupService(SqliteDatabase::make(''), $base))->cleanupStale();

        self::assertDirectoryDoesNotExist($base . DIRECTORY_SEPARATOR . 'storage');
    }
}
