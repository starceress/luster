<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ImportOptions;
use Luster\Import\ListingAdminImportSource;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Covers the la_table_prefix validation guard added to
 * ListingAdminImportSource::preview()/run() (SQL injection fix — the field
 * was previously interpolated into raw SQL across dozens of call sites with
 * no character-class validation). Not a full behavioral suite for the
 * adapter itself.
 */
final class ListingAdminImportSourceTest extends TestCase
{
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /** @param array<string,mixed> $overrides */
    private function makeOptions(array $overrides = []): ImportOptions
    {
        return ImportOptions::fromPost(array_merge([
            'source'            => 'listing-admin',
            'conflict_strategy' => 'skip',
        ], $overrides));
    }

    private function adapter(): ListingAdminImportSource
    {
        return new ListingAdminImportSource();
    }

    public function testPreviewRejectsLaTablePrefixWithIllegalCharacters(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid table prefix');

        $this->adapter()->preview($source, $target, $this->makeOptions([
            'la_table_prefix' => "trex` UNION SELECT password_hash FROM lstr_admin_users-- ",
        ]));
    }

    public function testRunRejectsLaTablePrefixWithIllegalCharacters(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid table prefix');

        $this->adapter()->run($source, $target, $this->makeOptions([
            'la_table_prefix' => "trex`; DROP TABLE lstr_admin_users;--",
        ]));
    }

    public function testPreviewAcceptsBlankLaTablePrefix(): void
    {
        // Blank is the documented, valid "normal Listing Admin install" case.
        $source = $this->makePdo();
        $target = $this->makePdo();

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions([
            'la_table_prefix' => '',
        ]));

        $this->assertSame([], $preview->rows);
    }

    public function testPreviewAcceptsValidLaTablePrefix(): void
    {
        $source = $this->makePdo();
        $target = $this->makePdo();

        $preview = $this->adapter()->preview($source, $target, $this->makeOptions([
            'la_table_prefix' => 'trex_',
        ]));

        $this->assertSame([], $preview->rows);
    }
}
