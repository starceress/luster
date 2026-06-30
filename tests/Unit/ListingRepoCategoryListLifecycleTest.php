<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests getCategoryList() lifecycle awareness: pending excludes archived
 * listings, and closed/adopted_out status strings return only the relevant
 * archived listings' categories.
 */
final class ListingRepoCategoryListLifecycleTest extends TestCase
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
    additional  TEXT    NOT NULL DEFAULT '',
    catid       TEXT    NOT NULL DEFAULT '',
    category_id INTEGER NULL
);
CREATE TABLE lstr_categories (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      TEXT    NOT NULL DEFAULT '',
    slug      TEXT    NOT NULL DEFAULT '',
    source    TEXT    NOT NULL DEFAULT 'tfl',
    parent_id INTEGER NULL
);
CREATE TABLE lstr_listing_categories (
    fanlisting_id INTEGER NOT NULL,
    category_id   INTEGER NOT NULL,
    PRIMARY KEY (fanlisting_id, category_id)
);
CREATE TABLE lstr_listing_lifecycle (
    fanlisting_id    INTEGER NOT NULL PRIMARY KEY,
    lifecycle_status TEXT    NOT NULL,
    archived_at      TEXT    NOT NULL,
    legacy_upcoming  INTEGER NOT NULL DEFAULT 0,
    legacy_trouble   INTEGER NOT NULL DEFAULT 0,
    adopted_to_name  TEXT    NULL,
    adopted_to_url   TEXT    NULL,
    archive_note     TEXT    NULL
)
SQL;

    private Database $db;
    private ListingRepo $repo;

    protected function setUp(): void
    {
        $this->db   = SqliteDatabase::make(self::DDL);
        $this->repo = new ListingRepo($this->db, $this->makeConfig([]));
    }

    private function makeConfig(array $values): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export($values, true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }

    private function pdo(): \PDO
    {
        $prop = new \ReflectionProperty($this->db, 'pdo');
        $prop->setAccessible(true);
        return $prop->getValue($this->db);
    }

    private function insertListing(int $status = 0): int
    {
        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO owned (title, subject, status) VALUES (:t, :t, :s)')
            ->execute(['t' => 'Test', 's' => $status]);
        return (int) $pdo->lastInsertId();
    }

    private function insertCategory(string $name): int
    {
        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO lstr_categories (name, slug, source) VALUES (:n, :n, :s)')
            ->execute(['n' => $name, 's' => 'tfl']);
        return (int) $pdo->lastInsertId();
    }

    private function linkCategory(int $listingId, int $categoryId): void
    {
        $this->pdo()->prepare(
            'INSERT INTO lstr_listing_categories (fanlisting_id, category_id) VALUES (:f, :c)'
        )->execute(['f' => $listingId, 'c' => $categoryId]);
    }

    private function archive(int $id, string $status): void
    {
        $this->pdo()->prepare(
            "INSERT INTO lstr_listing_lifecycle (fanlisting_id, lifecycle_status, archived_at)
             VALUES (:id, :status, '2026-01-01 00:00:00')"
        )->execute(['id' => $id, 'status' => $status]);
    }

    public function testPendingExcludesArchivedListings(): void
    {
        $catId   = $this->insertCategory('Music');
        $pendId  = $this->insertListing(status: 0); // truly pending (trouble)
        $closedId = $this->insertListing(status: 0); // archived
        $this->linkCategory($pendId, $catId);
        $this->linkCategory($closedId, $catId);
        $this->archive($closedId, 'closed');

        $list = $this->repo->getCategoryList('pending');

        self::assertCount(1, $list);
        self::assertSame(1, $list[0]['listing_count'], 'Archived listing must not count toward pending');
    }

    public function testClosedStatusReturnsOnlyClosedListingsCategories(): void
    {
        $catId      = $this->insertCategory('Music');
        $closedId   = $this->insertListing(status: 0);
        $adoptedId  = $this->insertListing(status: 0);
        $this->linkCategory($closedId, $catId);
        $this->linkCategory($adoptedId, $catId);
        $this->archive($closedId, 'closed');
        $this->archive($adoptedId, 'adopted_out');

        $list = $this->repo->getCategoryList('closed');

        self::assertCount(1, $list);
        self::assertSame(1, $list[0]['listing_count']);
    }

    public function testAdoptedOutStatusReturnsOnlyAdoptedOutListingsCategories(): void
    {
        $catId      = $this->insertCategory('Music');
        $closedId   = $this->insertListing(status: 0);
        $adoptedId  = $this->insertListing(status: 0);
        $this->linkCategory($closedId, $catId);
        $this->linkCategory($adoptedId, $catId);
        $this->archive($closedId, 'closed');
        $this->archive($adoptedId, 'adopted_out');

        $list = $this->repo->getCategoryList('adopted_out');

        self::assertCount(1, $list);
        self::assertSame(1, $list[0]['listing_count']);
    }

    public function testCurrentStatusUnaffectedByArchivedListings(): void
    {
        $catId    = $this->insertCategory('Music');
        $curId    = $this->insertListing(status: 2);
        $closedId = $this->insertListing(status: 0);
        $this->linkCategory($curId, $catId);
        $this->linkCategory($closedId, $catId);
        $this->archive($closedId, 'closed');

        $list = $this->repo->getCategoryList('current');

        self::assertCount(1, $list);
        self::assertSame(1, $list[0]['listing_count'], 'Current count must not include archived listing');
    }
}
