<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ListingSettingsRepo::getRenderSettingsBatch() and getRenderSettings().
 *
 * Covers:
 *   - Normal fetch when lstr_fanlisting_settings.description exists (Migration 74+).
 *   - Pre-Migration-74 fallback: schema WITHOUT description column still returns
 *     image/last_updated/approved_by without throwing or silently losing them.
 *   - Empty input returns empty array.
 *   - Single-listing convenience wrapper delegates to batch method.
 */
final class ListingSettingsRepoRenderSettingsTest extends TestCase
{
    private const DDL_WITH_DESC = <<<SQL
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id INTEGER PRIMARY KEY,
    image         TEXT NULL,
    last_updated  TEXT NULL,
    approved_by   TEXT NOT NULL DEFAULT '',
    description   TEXT NULL
)
SQL;

    private const DDL_WITHOUT_DESC = <<<SQL
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id INTEGER PRIMARY KEY,
    image         TEXT NULL,
    last_updated  TEXT NULL,
    approved_by   TEXT NOT NULL DEFAULT ''
)
SQL;

    public function testGetRenderSettingsBatchReturnsAllColumnsWhenDescriptionExists(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_DESC);
        $repo = new ListingSettingsRepo($db);

        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, image, last_updated, approved_by, description)
             VALUES (1, 'fl_img.png', '2025-01-15', 'tfl', 'About this listing.')"
        );

        $map = $repo->getRenderSettingsBatch([1]);

        $this->assertArrayHasKey(1, $map);
        $this->assertSame('fl_img.png',           (string) $map[1]['image']);
        $this->assertSame('2025-01-15',            (string) $map[1]['last_updated']);
        $this->assertSame('tfl',                   (string) $map[1]['approved_by']);
        $this->assertSame('About this listing.',   (string) $map[1]['description']);
    }

    public function testGetRenderSettingsBatchFallsBackWhenDescriptionColumnMissing(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITHOUT_DESC);
        $repo = new ListingSettingsRepo($db);

        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, image, last_updated, approved_by)
             VALUES (2, 'old_img.png', '2024-06-01', 'tafl')"
        );

        // Must NOT throw — should fall back to query without description
        $map = $repo->getRenderSettingsBatch([2]);

        $this->assertArrayHasKey(2, $map, 'Fallback path must still return the row');
        $this->assertSame('old_img.png', (string) $map[2]['image'],        'image must survive fallback');
        $this->assertSame('2024-06-01',  (string) $map[2]['last_updated'], 'last_updated must survive fallback');
        $this->assertSame('tafl',        (string) $map[2]['approved_by'],  'approved_by must survive fallback');
    }

    public function testGetRenderSettingsBatchBatchFetchesMultipleRows(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_DESC);
        $repo = new ListingSettingsRepo($db);

        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, image, approved_by) VALUES
             (10, 'a.png', 'tfl'),
             (20, 'b.png', ''),
             (30, 'c.png', 'tafl')"
        );

        $map = $repo->getRenderSettingsBatch([10, 20, 30]);

        $this->assertCount(3, $map);
        $this->assertSame('a.png', (string) $map[10]['image']);
        $this->assertSame('b.png', (string) $map[20]['image']);
        $this->assertSame('c.png', (string) $map[30]['image']);
    }

    public function testGetRenderSettingsBatchReturnsEmptyForEmptyInput(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_DESC);
        $repo = new ListingSettingsRepo($db);

        $this->assertSame([], $repo->getRenderSettingsBatch([]));
    }

    public function testGetRenderSettingsBatchReturnsEmptyForMissingId(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_DESC);
        $repo = new ListingSettingsRepo($db);

        $map = $repo->getRenderSettingsBatch([999]);

        $this->assertSame([], $map, 'Non-existent listing id returns empty map');
    }

    public function testGetRenderSettingsDelegatesToBatch(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_DESC);
        $repo = new ListingSettingsRepo($db);

        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, image, description)
             VALUES (5, 'single.png', 'Single listing.')"
        );

        $row = $repo->getRenderSettings(5);

        $this->assertSame('single.png',      (string) $row['image']);
        $this->assertSame('Single listing.', (string) $row['description']);
    }

    public function testGetRenderSettingsReturnsEmptyArrayForMissingId(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_DESC);
        $repo = new ListingSettingsRepo($db);

        $this->assertSame([], $repo->getRenderSettings(999));
    }
}
