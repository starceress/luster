<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class ListingRepoLatestOpenedTest extends TestCase
{
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

    // -----------------------------------------------------------------------
    // limit guard
    // -----------------------------------------------------------------------

    public function testZeroLimitReturnsEmpty(): void
    {
        $repo = $this->makeRepo(self::DDL_WITH_OPENED);

        self::assertSame([], $repo->getLatestOpened(0));
        self::assertSame([], $repo->getLatestOpened(-5));
    }

    // -----------------------------------------------------------------------
    // No listings
    // -----------------------------------------------------------------------

    public function testEmptyTableReturnsEmpty(): void
    {
        $repo = $this->makeRepo(self::DDL_WITH_OPENED);

        self::assertSame([], $repo->getLatestOpened(1));
    }

    // -----------------------------------------------------------------------
    // Ordering by opened date (normal path)
    // -----------------------------------------------------------------------

    public function testReturnsListingWithLatestOpenedDate(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $repo = new ListingRepo($db, $this->makeConfig([]));
        $pdo  = $db->pdo();

        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Older', '2022-03-01', 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Newest', '2024-06-15', 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Middle', '2023-01-10', 2)");

        $results = $repo->getLatestOpened(1);

        self::assertCount(1, $results);
        self::assertSame('Newest', $results[0]->name);
    }

    public function testLimitRespectedWhenMultipleRequested(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $repo = new ListingRepo($db, $this->makeConfig([]));
        $pdo  = $db->pdo();

        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('A', '2024-06-01', 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('B', '2023-05-01', 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('C', '2022-04-01', 2)");

        $results = $repo->getLatestOpened(2);

        self::assertCount(2, $results);
        self::assertSame('A', $results[0]->name);
        self::assertSame('B', $results[1]->name);
    }

    public function testListingsWithNullOpenedAreExcluded(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $repo = new ListingRepo($db, $this->makeConfig([]));
        $pdo  = $db->pdo();

        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('No Date', NULL, 2)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Has Date', '2024-01-01', 2)");

        $results = $repo->getLatestOpened(5);

        self::assertCount(1, $results);
        self::assertSame('Has Date', $results[0]->name);
    }

    // -----------------------------------------------------------------------
    // Active-only filtering
    // -----------------------------------------------------------------------

    public function testInactiveListingsExcluded(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $repo = new ListingRepo($db, $this->makeConfig([]));
        $pdo  = $db->pdo();

        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Inactive', '2024-01-01', 0)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Active',   '2023-01-01', 2)");

        $results = $repo->getLatestOpened(5);

        self::assertCount(1, $results);
        self::assertSame('Active', $results[0]->name);
    }

    public function testUpcomingListingsExcluded(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $repo = new ListingRepo($db, $this->makeConfig([]));
        $pdo  = $db->pdo();

        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Upcoming', '2024-01-01', 1)");
        $pdo->exec("INSERT INTO owned (title, opened, status) VALUES ('Active',   '2023-01-01', 2)");

        $results = $repo->getLatestOpened(5);

        self::assertCount(1, $results);
        self::assertSame('Active', $results[0]->name);
    }

    // -----------------------------------------------------------------------
    // Fallback when opened column is absent
    // -----------------------------------------------------------------------

    public function testFallsBackToPkOrderWhenOpenedColumnAbsent(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITHOUT_OPENED);
        $repo = new ListingRepo($db, $this->makeConfig([]));
        $pdo  = $db->pdo();

        // Insert in reverse ID order to confirm descending PK sort
        $pdo->exec("INSERT INTO owned (title, status) VALUES ('First Inserted', 2)");
        $pdo->exec("INSERT INTO owned (title, status) VALUES ('Second Inserted', 2)");
        $pdo->exec("INSERT INTO owned (title, status) VALUES ('Third Inserted', 2)");

        $results = $repo->getLatestOpened(1);

        self::assertCount(1, $results);
        self::assertSame('Third Inserted', $results[0]->name);
    }

    public function testFallbackExcludesInactiveListings(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITHOUT_OPENED);
        $repo = new ListingRepo($db, $this->makeConfig([]));
        $pdo  = $db->pdo();

        $pdo->exec("INSERT INTO owned (title, status) VALUES ('Active',   2)");
        $pdo->exec("INSERT INTO owned (title, status) VALUES ('Inactive', 0)");
        $pdo->exec("INSERT INTO owned (title, status) VALUES ('Upcoming', 1)");

        $results = $repo->getLatestOpened(5);

        self::assertCount(1, $results);
        self::assertSame('Active', $results[0]->name);
    }

    public function testFallbackReturnsEmpty(): void
    {
        $repo = $this->makeRepo(self::DDL_WITHOUT_OPENED);

        self::assertSame([], $repo->getLatestOpened(1));
    }

    // -----------------------------------------------------------------------
    // Hydration
    // -----------------------------------------------------------------------

    public function testReturnedListingHasCorrectName(): void
    {
        $db   = SqliteDatabase::make(self::DDL_WITH_OPENED);
        $repo = new ListingRepo($db, $this->makeConfig([]));
        $pdo  = $db->pdo();

        $pdo->exec("INSERT INTO owned (title, opened, url, email, subject, status, dbtable)
                    VALUES ('My Fanlisting', '2024-03-01', 'https://fl.example.com', 'owner@example.com', 'The Subject', 2, 'myfl_')");

        $results = $repo->getLatestOpened(1);

        self::assertCount(1, $results);
        $fl = $results[0];
        self::assertSame('My Fanlisting', $fl->name);
        self::assertSame('https://fl.example.com', $fl->url);
        self::assertSame('2024-03-01', $fl->opened);
        self::assertFalse($fl->upcoming);
        self::assertFalse($fl->trouble);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeRepo(string $ddl): ListingRepo
    {
        $db = SqliteDatabase::make($ddl);
        return new ListingRepo($db, $this->makeConfig([]));
    }

    private function makeConfig(array $values): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export($values, true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }
}
