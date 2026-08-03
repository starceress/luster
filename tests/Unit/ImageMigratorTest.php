<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ImageMigrator;
use PHPUnit\Framework\TestCase;

final class ImageMigratorTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'luster_img_test_' . uniqid('', true);
        mkdir($this->publicDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->deleteDir($this->publicDir);
    }

    public function testFetchFromUrlRejectsUnsupportedSchemes(): void
    {
        $migrator = new ImageMigrator($this->publicDir);

        self::assertNull($migrator->fetchFromUrl('file:///etc/passwd', 'codes', 'cs_'));
        self::assertNull($migrator->fetchFromUrl('ftp://example.com/test.png', 'codes', 'cs_'));
    }

    public function testFetchFromUrlRejectsPrivateAndLoopbackHosts(): void
    {
        $migrator = new ImageMigrator($this->publicDir);

        self::assertNull($migrator->fetchFromUrl('http://127.0.0.1/test.png', 'codes', 'cs_'));
        self::assertNull($migrator->fetchFromUrl('https://localhost/test.png', 'codes', 'cs_'));
        self::assertNull($migrator->fetchFromUrl('http://192.168.1.20/test.png', 'codes', 'cs_'));
    }

    /**
     * ensureDir() used to write its own, separate, weaker .htaccess (bare
     * "\.php$", Apache 2.2-only Deny-from-all syntax) instead of delegating
     * to UploadDirectoryGuard like the admin upload controllers do — since
     * neither ever overwrites an existing file, an import that ran first
     * could permanently shadow the stronger version a later admin upload
     * would otherwise have written to the same directory. Confirms it now
     * produces byte-identical content to the top-level public/uploads/.htaccess.
     */
    public function testEnsureDirWritesTheSharedStrongHtaccessNotAWeakerCopy(): void
    {
        $migrator = new ImageMigrator($this->publicDir);
        $m = new \ReflectionMethod($migrator, 'ensureDir');
        $m->setAccessible(true);

        $dir = $m->invoke($migrator, 'codes');

        self::assertDirectoryExists($dir);
        self::assertFileExists($dir . '/.htaccess');

        $generated = str_replace("\r\n", "\n", (string) file_get_contents($dir . '/.htaccess'));
        $topLevel  = str_replace("\r\n", "\n", (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/uploads/.htaccess'
        ));

        self::assertSame($topLevel, $generated);
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->deleteDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
