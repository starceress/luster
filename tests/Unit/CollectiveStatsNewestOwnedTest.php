<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Repositories\CollectiveStatsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for CollectiveStatsRepo::getNewestOwned() ordering.
 *
 * "Newest" must mean most-recently-opened (opened DESC), not insertion order,
 * with a fallback to primary-key order when the `opened` column is absent
 * (raw pre-migration Enthusiast install).
 */
final class CollectiveStatsNewestOwnedTest extends TestCase
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

    public function testReturnsListingWithMostRecentOpenedDateFirst(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Older', '2022-03-01', 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Newest', '2024-06-15', 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Middle', '2023-01-10', 2)");

        $results = $repo->getNewestOwned(1);

        self::assertCount(1, $results);
        self::assertSame('Newest', $results[0]->title);
    }

    public function testOrderingNotInsertionOrder(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        // Inserted in chronological order, but the last-inserted row has the
        // oldest opened date — a pure "ORDER BY pk DESC" would return it first.
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('First', '2024-06-01', 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Second', '2023-05-01', 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Third', '2022-04-01', 2)");

        $results = $repo->getNewestOwned(3);

        self::assertCount(3, $results);
        self::assertSame('First',  $results[0]->title);
        self::assertSame('Second', $results[1]->title);
        self::assertSame('Third',  $results[2]->title);
    }

    public function testNullOpenedSortsLastBehindDatedListings(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('No Date', NULL, 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Has Date', '2024-01-01', 2)");

        $results = $repo->getNewestOwned(2);

        self::assertCount(2, $results);
        self::assertSame('Has Date', $results[0]->title);
        self::assertSame('No Date',  $results[1]->title);
    }

    public function testInactiveListingsExcluded(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Inactive', '2024-01-01', 0)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Upcoming', '2024-02-01', 1)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Active',   '2023-01-01', 2)");

        $results = $repo->getNewestOwned(5);

        self::assertCount(1, $results);
        self::assertSame('Active', $results[0]->title);
    }

    public function testFallsBackToPkOrderWhenOpenedColumnAbsent(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITHOUT_OPENED);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $pdo->exec("INSERT INTO owned (title, status) VALUES ('First Inserted', 2)");
        $pdo->exec("INSERT INTO owned (title, status) VALUES ('Second Inserted', 2)");
        $pdo->exec("INSERT INTO owned (title, status) VALUES ('Third Inserted', 2)");

        $results = $repo->getNewestOwned(1);

        self::assertCount(1, $results);
        self::assertSame('Third Inserted', $results[0]->title);
    }

    public function testZeroOrNegativeLimitReturnsEmpty(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $repo = new CollectiveStatsRepo($db, $this->config);

        self::assertSame([], $repo->getNewestOwned(0));
        self::assertSame([], $repo->getNewestOwned(-1));
    }

    private function extractPdo(\Luster\Core\Database $db): \PDO
    {
        $prop = new \ReflectionProperty($db, 'pdo');
        $prop->setAccessible(true);
        return $prop->getValue($db);
    }
}
