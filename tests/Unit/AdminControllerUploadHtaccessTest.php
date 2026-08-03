<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\AdminController;
use PHPUnit\Framework\TestCase;

/**
 * Tests AdminController::ensureUploadSubdirectory(), a thin wrapper around
 * UploadDirectoryGuard::ensure() (see UploadDirectoryGuardTest.php for
 * direct coverage of that shared class, and ImageMigratorTest.php for the
 * import wizard's own caller). This file's job is specifically the wrapper's
 * own path-building (LUSTER_BASE . '/public/uploads/' . $subdir), not
 * re-verifying content UploadDirectoryGuardTest.php already covers in depth.
 *
 * Previously four separate, narrower copies existed: ListingController,
 * JoinedController, and WishlistAdminController shared one weaker denylist
 * pattern; ImageMigrator had an even weaker one of its own.
 *
 * Runs in a separate process because it defines the global LUSTER_BASE
 * constant, which — once defined — can't be changed for the rest of the
 * PHP process and must not leak into other test files.
 */
final class AdminControllerUploadHtaccessTest extends TestCase
{
    private function makeController(): AdminController
    {
        return new class () extends AdminController {
            public function __construct() {}

            public function ensureUploadSubdirectoryPublic(string $subdir): string
            {
                return $this->ensureUploadSubdirectory($subdir);
            }
        };
    }

    /** @runInSeparateProcess */
    public function testCreatesDirectoryAndWritesHtaccessMatchingTopLevelFile(): void
    {
        $fakeBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_upload_htaccess_test_' . uniqid('', true);
        mkdir($fakeBase . '/public/uploads', 0777, true);
        define('LUSTER_BASE', $fakeBase);

        $controller = $this->makeController();
        $dir = $controller->ensureUploadSubdirectoryPublic('testsubdir');

        self::assertDirectoryExists($dir);
        self::assertFileExists($dir . '/.htaccess');

        $generated = str_replace("\r\n", "\n", (string) file_get_contents($dir . '/.htaccess'));
        $topLevel  = str_replace("\r\n", "\n", (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/uploads/.htaccess'
        ));

        self::assertSame(
            $topLevel,
            $generated,
            'Auto-generated per-directory .htaccess must exactly match public/uploads/.htaccess, not a narrower subset.'
        );

        $this->cleanupDir($fakeBase);
    }

    /** @runInSeparateProcess */
    public function testIsIdempotentAndDoesNotOverwriteAnExistingCustomizedFile(): void
    {
        $fakeBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_upload_htaccess_test_' . uniqid('', true);
        mkdir($fakeBase . '/public/uploads', 0777, true);
        define('LUSTER_BASE', $fakeBase);

        $controller = $this->makeController();
        $dir = $controller->ensureUploadSubdirectoryPublic('testsubdir');

        // Simulate a site owner having hand-customized the file - a second
        // call (e.g. a later image upload to the same listing) must not
        // clobber it, matching the existing is_file() guard's intent.
        file_put_contents($dir . '/.htaccess', 'CUSTOMIZED CONTENT');

        $dir2 = $controller->ensureUploadSubdirectoryPublic('testsubdir');

        self::assertSame($dir, $dir2);
        self::assertSame('CUSTOMIZED CONTENT', file_get_contents($dir . '/.htaccess'));

        $this->cleanupDir($fakeBase);
    }

    private function cleanupDir(string $fakeBase): void
    {
        $dir = $fakeBase . '/public/uploads/testsubdir';
        @unlink($dir . '/.htaccess');
        @rmdir($dir);
        @rmdir($fakeBase . '/public/uploads');
        @rmdir($fakeBase . '/public');
        @rmdir($fakeBase);
    }
}
