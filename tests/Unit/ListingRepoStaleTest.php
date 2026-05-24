<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class ListingRepoStaleTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE owned (
    listingid   INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL DEFAULT '',
    url         TEXT    NOT NULL DEFAULT 'https://example.com',
    email       TEXT    NOT NULL DEFAULT '',
    subject     TEXT    NOT NULL DEFAULT '',
    status      INTEGER NOT NULL DEFAULT 2,
    dbtable     TEXT    NOT NULL DEFAULT '',
    additional  TEXT    NOT NULL DEFAULT ''
);
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id INTEGER PRIMARY KEY,
    last_updated  TEXT    NULL
)
SQL;

    private Database $db;
    private ListingRepo $repo;

    protected function setUp(): void
    {
        $this->db   = SqliteDatabase::make(self::DDL);
        $this->repo = new ListingRepo($this->db, $this->makeConfig([]));
    }

    // -----------------------------------------------------------------------

    public function testZeroDaysReturnsEmpty(): void
    {
        $this->insertListing('My Fanlisting');

        self::assertSame([], $this->repo->getStaleListings(0));
        self::assertSame([], $this->repo->getStaleListings(-1));
    }

    public function testActiveListingWithNoSettingsRowIsStale(): void
    {
        // No lstr_fanlisting_settings row — LEFT JOIN gives NULL last_updated.
        $this->insertListing('Stale One');
        $this->insertListing('Stale Two');

        $results = $this->repo->getStaleListings(55);

        self::assertCount(2, $results);
        $names = array_map(static fn($l) => $l->name, $results);
        self::assertContains('Stale One', $names);
        self::assertContains('Stale Two', $names);
    }

    public function testActiveListingWithExplicitNullLastUpdatedIsStale(): void
    {
        $id = $this->insertListing('Explicit Null');
        $this->db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated) VALUES ({$id}, NULL)"
        );

        $results = $this->repo->getStaleListings(55);

        self::assertCount(1, $results);
        self::assertSame('Explicit Null', $results[0]->name);
    }

    public function testInactiveListingExcluded(): void
    {
        // status = 0 → trouble/inactive in owned schema
        $this->insertListing('Inactive', status: 0);

        self::assertSame([], $this->repo->getStaleListings(55));
    }

    public function testUpcomingListingExcluded(): void
    {
        // status = 1 → upcoming in owned schema
        $this->insertListing('Upcoming', status: 1);

        self::assertSame([], $this->repo->getStaleListings(55));
    }

    public function testRecentListingNotStale(): void
    {
        $id = $this->insertListing('Fresh');
        // last_updated = today → not older than the threshold
        $this->db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated) VALUES ({$id}, date('now'))"
        );

        self::assertSame([], $this->repo->getStaleListings(55));
    }

    public function testOldLastUpdatedIsStale(): void
    {
        $id = $this->insertListing('Old');
        $this->db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated) VALUES ({$id}, '2020-01-01')"
        );

        $results = $this->repo->getStaleListings(55);

        self::assertCount(1, $results);
        self::assertSame('Old', $results[0]->name);
    }

    public function testMixedActiveAndInactiveListings(): void
    {
        $this->insertListing('Active Stale', status: 2);
        $this->insertListing('Inactive',     status: 0);
        $this->insertListing('Upcoming',     status: 1);

        $results = $this->repo->getStaleListings(55);

        self::assertCount(1, $results);
        self::assertSame('Active Stale', $results[0]->name);
    }

    // -----------------------------------------------------------------------
    // Standard Enthusiast schema (enth_listings with upcoming/trouble cols)
    // -----------------------------------------------------------------------

    public function testStandardSchemaActiveListingNoSettingsRowIsStale(): void
    {
        [$repo] = $this->makeEnthRepo();
        $pdo = $this->enthDb->pdo();
        $pdo->exec("INSERT INTO enth_listings (name, upcoming, trouble) VALUES ('Stale Enth', 0, 0)");

        $results = $repo->getStaleListings(55);

        self::assertCount(1, $results);
        self::assertSame('Stale Enth', $results[0]->name);
    }

    public function testStandardSchemaUpcomingListingExcluded(): void
    {
        [$repo] = $this->makeEnthRepo();
        $this->enthDb->pdo()->exec(
            "INSERT INTO enth_listings (name, upcoming, trouble) VALUES ('Not Open Yet', 1, 0)"
        );

        self::assertSame([], $repo->getStaleListings(55));
    }

    public function testStandardSchemaTroubleListingExcluded(): void
    {
        [$repo] = $this->makeEnthRepo();
        $this->enthDb->pdo()->exec(
            "INSERT INTO enth_listings (name, upcoming, trouble) VALUES ('Broken', 0, 1)"
        );

        self::assertSame([], $repo->getStaleListings(55));
    }

    public function testStandardSchemaRecentListingNotStale(): void
    {
        [$repo, $db] = $this->makeEnthRepo();
        $pdo = $db->pdo();
        $pdo->exec("INSERT INTO enth_listings (name, upcoming, trouble) VALUES ('Fresh Enth', 0, 0)");
        $id = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated) VALUES ({$id}, date('now'))"
        );

        self::assertSame([], $repo->getStaleListings(55));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private Database $enthDb;

    /**
     * Build a repo wired to the standard enth_listings schema.
     *
     * @return array{0: ListingRepo, 1: Database}
     */
    private function makeEnthRepo(): array
    {
        $ddl = <<<SQL
CREATE TABLE enth_listings (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    name     TEXT    NOT NULL DEFAULT '',
    upcoming INTEGER NOT NULL DEFAULT 0,
    trouble  INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id INTEGER PRIMARY KEY,
    last_updated  TEXT    NULL
)
SQL;
        $this->enthDb = SqliteDatabase::make($ddl);
        $repo = new ListingRepo($this->enthDb, $this->makeConfig(['enth_table_prefix' => 'enth_']));
        return [$repo, $this->enthDb];
    }

    private function insertListing(string $title, int $status = 2): int
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            "INSERT INTO owned (title, status) VALUES ('{$title}', {$status})"
        );
        return (int) $pdo->lastInsertId();
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
