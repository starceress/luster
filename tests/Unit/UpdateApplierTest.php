<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\UpdateApplier;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class UpdateApplierTest extends TestCase
{
    private string $stagingDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagingDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_update_test_' . uniqid('', true);
        mkdir($this->stagingDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->deleteDir($this->stagingDir);
    }

    /**
     * Mirrors the package shape produced by .github/workflows/release.yml:
     * src/, templates/, public/, and composer.json at the package root.
     */
    public function testStagingIsValidAcceptsReleasePackageShape(): void
    {
        mkdir($this->stagingDir . '/src', 0777, true);
        mkdir($this->stagingDir . '/templates', 0777, true);
        mkdir($this->stagingDir . '/public', 0777, true);
        file_put_contents($this->stagingDir . '/composer.json', '{}');

        $applier = new UpdateApplier($this->stagingDir, SqliteDatabase::make(''));

        self::assertTrue($applier->stagingIsValid($this->stagingDir));
    }

    public function testStagingIsValidRejectsPackageMissingComposerJson(): void
    {
        mkdir($this->stagingDir . '/src', 0777, true);
        mkdir($this->stagingDir . '/templates', 0777, true);
        mkdir($this->stagingDir . '/public', 0777, true);

        $applier = new UpdateApplier($this->stagingDir, SqliteDatabase::make(''));

        self::assertFalse($applier->stagingIsValid($this->stagingDir));
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

    public function testChecksumParserMatchesNamedZipAsset(): void
    {
        $hash = str_repeat('a', 64);
        $contents = "ignored\n{$hash}  luster-v1.2.3.zip\n";

        self::assertSame($hash, UpdateApplier::parseChecksumForAsset($contents, 'luster-v1.2.3.zip'));
    }

    public function testChecksumParserAllowsSingleBareHash(): void
    {
        $hash = str_repeat('b', 64);

        self::assertSame($hash, UpdateApplier::parseChecksumForAsset($hash, 'luster-v1.2.3.zip'));
    }

    public function testChecksumParserRejectsWrongAssetNameWhenAmbiguous(): void
    {
        $contents = str_repeat('c', 64) . "  other.zip\n"
            . str_repeat('d', 64) . "  another.zip\n";

        self::assertNull(UpdateApplier::parseChecksumForAsset($contents, 'luster-v1.2.3.zip'));
    }

    public function testArchivePathValidationAllowsNormalRelativePaths(): void
    {
        self::assertTrue(UpdateApplier::isSafeArchivePath('luster/src/Core/View.php'));
        self::assertTrue(UpdateApplier::isSafeArchivePath('public/assets/css/admin.css'));
    }

    public function testArchivePathValidationRejectsTraversalAndAbsolutePaths(): void
    {
        self::assertFalse(UpdateApplier::isSafeArchivePath('../config/app.php'));
        self::assertFalse(UpdateApplier::isSafeArchivePath('luster/../config/app.php'));
        self::assertFalse(UpdateApplier::isSafeArchivePath('/tmp/luster.zip'));
        self::assertFalse(UpdateApplier::isSafeArchivePath('C:/tmp/luster.zip'));
        self::assertFalse(UpdateApplier::isSafeArchivePath('luster//src/View.php'));
    }
}
