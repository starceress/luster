<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Repositories\CollectiveStatsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CollectiveStatsRepo::getLastOwnedOpenedDate(), which backs the
 * {collective_last_updated} embed token — the collective-wide analogue of
 * a per-listing's {stat_updated}.
 */
final class CollectiveStatsLastUpdatedTest extends TestCase
{
    private Config $config;

    /** DDL with an `opened` column — normal Luster-migrated owned table. */
    private const DDL_WITH_OPENED = <<<SQL
CREATE TABLE owned (
    listingid   INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL DEFAULT '',
    url         TEXT    NOT NULL DEFAULT 'https://example.com',
    email       TEXT    NOT NULL DEFAULT '',
    subject     TEXT    NOT NULL DEFAULT '',
    opened      TEXT    NULL,
    status      INTEGER NOT NULL DEFAULT 2,
    dbtable     TEXT    NOT NULL DEFAULT '',
    additional  TEXT    NOT NULL DEFAULT ''
)
SQL;

    /** DDL without `opened` column — raw Enthusiast install, pre-migration. */
    private const DDL_WITHOUT_OPENED = <<<SQL
CREATE TABLE owned (
    listingid   INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL DEFAULT '',
    url         TEXT    NOT NULL DEFAULT 'https://example.com',
    email       TEXT    NOT NULL DEFAULT '',
    subject     TEXT    NOT NULL DEFAULT '',
    status      INTEGER NOT NULL DEFAULT 2,
    dbtable     TEXT    NOT NULL DEFAULT '',
    additional  TEXT    NOT NULL DEFAULT ''
)
SQL;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lstr_test_');
        file_put_contents($tmp, "<?php return ['enth_table_prefix' => ''];");
        $this->config = new Config($tmp);
        unlink($tmp);
    }

    public function testReturnsMostRecentOpenedDateRegardlessOfStatus(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Older', '2022-03-01', 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Newest Pending', '2026-05-10', 0)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Middle', '2023-01-10', 2)");

        self::assertSame('2026-05-10', $repo->getLastOwnedOpenedDate());
    }

    public function testReturnsNullWhenNoListingsHaveOpenedDates(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('No Date', NULL, 2)");

        self::assertNull($repo->getLastOwnedOpenedDate());
    }

    public function testReturnsNullOnEmptyTable(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $repo = new CollectiveStatsRepo($db, $this->config);

        self::assertNull($repo->getLastOwnedOpenedDate());
    }

    public function testReturnsNullWhenOpenedColumnAbsent(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITHOUT_OPENED);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $pdo->exec("INSERT INTO owned (title, status) VALUES ('First', 2)");

        self::assertNull($repo->getLastOwnedOpenedDate());
    }

    private function extractPdo(\Luster\Core\Database $db): \PDO
    {
        $prop = new \ReflectionProperty($db, 'pdo');
        $prop->setAccessible(true);
        return $prop->getValue($db);
    }
}
