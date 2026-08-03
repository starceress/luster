<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\UploadDirectoryGuard;
use PHPUnit\Framework\TestCase;

/**
 * Tests UploadDirectoryGuard::ensure() — the single shared source of truth
 * for per-directory upload .htaccess guards. Previously four separate,
 * narrower copies: ListingController, JoinedController, and
 * WishlistAdminController shared one weaker denylist pattern (missing
 * .phar/.pht, Apache-2.2-only syntax in one case); ImageMigrator (used by
 * the import wizard) had an even weaker one of its own. Since none of them
 * ever overwrote an existing file, whichever code path ran first for a
 * given upload subdirectory permanently determined which guard that
 * directory got — an import running before any admin upload could leave a
 * weaker .htaccess in place indefinitely.
 */
final class UploadDirectoryGuardTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_upload_guard_test_' . uniqid('', true);
        mkdir($this->base, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->base . '/sub/.htaccess');
        @rmdir($this->base . '/sub');
        @rmdir($this->base);
    }

    public function testCreatesDirectoryAndWritesHtaccessMatchingTopLevelFile(): void
    {
        $dir = UploadDirectoryGuard::ensure($this->base . '/sub');

        self::assertDirectoryExists($dir);
        self::assertFileExists($dir . '/.htaccess');

        $generated = str_replace("\r\n", "\n", (string) file_get_contents($dir . '/.htaccess'));
        $topLevel  = str_replace("\r\n", "\n", (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/uploads/.htaccess'
        ));

        self::assertSame($topLevel, $generated);
    }

    public function testIsIdempotentAndDoesNotOverwriteAnExistingCustomizedFile(): void
    {
        $dir = UploadDirectoryGuard::ensure($this->base . '/sub');

        // Simulate a site owner having hand-customized the file, or an
        // earlier caller (e.g. ImageMigrator) having already created one -
        // a second call must not clobber it.
        file_put_contents($dir . '/.htaccess', 'CUSTOMIZED CONTENT');

        $dir2 = UploadDirectoryGuard::ensure($this->base . '/sub');

        self::assertSame($dir, $dir2);
        self::assertSame('CUSTOMIZED CONTENT', file_get_contents($dir . '/.htaccess'));
    }

    public function testWorksWhenDirectoryAlreadyExistsWithoutAnHtaccess(): void
    {
        mkdir($this->base . '/sub', 0777, true);

        $dir = UploadDirectoryGuard::ensure($this->base . '/sub');

        self::assertFileExists($dir . '/.htaccess');
    }
}
