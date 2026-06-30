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
    private function shouldSkip(string $rel, bool $includeUploads): bool
    {
        // Database is final — use SqliteDatabase helper to get a real instance.
        // shouldSkipPath() never touches the database, so an empty schema is fine.
        $db      = SqliteDatabase::make('');
        $service = new BackupService($db, sys_get_temp_dir());
        $method  = new \ReflectionMethod($service, 'shouldSkipPath');
        $method->setAccessible(true);
        return (bool) $method->invoke($service, $rel, $includeUploads);
    }

    // --- rollback profile ($includeUploads = false) -------------------------

    public function testRollbackExcludesUploads(): void
    {
        self::assertTrue($this->shouldSkip('files/public/uploads/foo.jpg', false));
        self::assertTrue($this->shouldSkip('files/public/uploads/subdir/bar.png', false));
    }

    public function testRollbackExcludesVendor(): void
    {
        self::assertTrue($this->shouldSkip('files/vendor/autoload.php', false));
        self::assertTrue($this->shouldSkip('files/vendor/phpmailer/phpmailer/src/PHPMailer.php', false));
    }

    public function testRollbackExcludesStorage(): void
    {
        self::assertTrue($this->shouldSkip('files/storage/logs/error.log', false));
        self::assertTrue($this->shouldSkip('files/storage/cache/luster_backup_20260101.zip', false));
    }

    public function testRollbackIncludesSrcAndTemplates(): void
    {
        self::assertFalse($this->shouldSkip('files/src/Core/Application.php', false));
        self::assertFalse($this->shouldSkip('files/templates/admin/layout.php', false));
    }

    public function testRollbackIncludesPublicExceptUploads(): void
    {
        self::assertFalse($this->shouldSkip('files/public/assets/css/admin.css', false));
        self::assertFalse($this->shouldSkip('files/public/embed/join.php', false));
        self::assertFalse($this->shouldSkip('files/public/.htaccess', false));
    }

    // --- complete profile ($includeUploads = true) --------------------------

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
        self::assertTrue($this->shouldSkip('storage/cache/tmp.zip', false));
        self::assertTrue($this->shouldSkip('public/uploads/foo.png', false));
        self::assertFalse($this->shouldSkip('public/uploads/foo.png', true));
    }
}
