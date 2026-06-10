<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\UpdateApplier;
use PHPUnit\Framework\TestCase;

final class UpdateApplierTest extends TestCase
{
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
