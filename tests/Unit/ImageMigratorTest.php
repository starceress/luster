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
