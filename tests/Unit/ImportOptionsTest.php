<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ImportOptions;
use PHPUnit\Framework\TestCase;

/**
 * ImportOptions::fromPost() image-mode auto-detection. Enthusiast, Listing
 * Admin, Fanatically, and CodeSort all read image folder/URL settings from
 * the source database itself, so the wizard hides the manual "import
 * images?" checkbox for them and expects imageMode to come out 'auto'
 * without import_images ever being posted.
 */
final class ImportOptionsTest extends TestCase
{
    /** @dataProvider alwaysAutoSources */
    public function testSourcesWithOwnImageSettingsDefaultToAutoModeWithoutOptIn(string $source): void
    {
        $opts = ImportOptions::fromPost(['source' => $source]);

        self::assertSame('auto', $opts->imageMode);
    }

    public static function alwaysAutoSources(): array
    {
        return [
            'enthusiast'    => ['enthusiast'],
            'listing-admin' => ['listing-admin'],
            'fanatically'   => ['fanatically'],
            'codesort'      => ['codesort'],
        ];
    }

    public function testSourceWithoutOwnImageSettingsStaysBlankWithoutOptIn(): void
    {
        $opts = ImportOptions::fromPost(['source' => 'fanupdate']);

        self::assertSame('', $opts->imageMode);
    }

    public function testSourceWithoutOwnImageSettingsHonorsExplicitOptIn(): void
    {
        $opts = ImportOptions::fromPost(['source' => 'fanupdate', 'import_images' => '1']);

        self::assertSame('auto', $opts->imageMode);
    }

    public function testZipPathTakesPrecedenceOverAutoSources(): void
    {
        $opts = ImportOptions::fromPost(['source' => 'codesort', '_zip_path' => '/tmp/upload.zip']);

        self::assertSame('zip', $opts->imageMode);
    }
}
