<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the lstr_listing_categories junction table methods:
 *  - getCategoryIdsForListing()
 *  - saveCategories()
 * And the dependent query changes:
 *  - getFiltered() uses EXISTS on the junction table
 *  - getCategoryList() joins through the junction table
 */
final class ListingRepoCategoriesTest extends TestCase
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
    // getCategoryIdsForListing
    // -----------------------------------------------------------------------

    public function testGetCategoryIdsReturnsEmptyWhenNoRows(): void
    {
        $id = $this->insertListing('My Listing');

        self::assertSame([], $this->repo->getCategoryIdsForListing($id));
    }

    public function testGetCategoryIdsReturnsSingleAssignment(): void
    {
        $id  = $this->insertListing('My Listing');
        $cat = $this->insertCategory('Actors');
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id}, {$cat})");

        self::assertSame([$cat], $this->repo->getCategoryIdsForListing($id));
    }

    public function testGetCategoryIdsReturnsMultipleAssignments(): void
    {
        $id   = $this->insertListing('Multi');
        $cat1 = $this->insertCategory('Actors');
        $cat2 = $this->insertCategory('Movies');
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id}, {$cat1})");
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id}, {$cat2})");

        $result = $this->repo->getCategoryIdsForListing($id);

        self::assertCount(2, $result);
        self::assertContains($cat1, $result);
        self::assertContains($cat2, $result);
    }

    public function testGetCategoryIdsIsolatedPerListing(): void
    {
        $id1 = $this->insertListing('One');
        $id2 = $this->insertListing('Two');
        $cat = $this->insertCategory('Shared');
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id1}, {$cat})");

        self::assertSame([$cat], $this->repo->getCategoryIdsForListing($id1));
        self::assertSame([], $this->repo->getCategoryIdsForListing($id2));
    }

    // -----------------------------------------------------------------------
    // saveCategories
    // -----------------------------------------------------------------------

    public function testSaveCategoriesInsertsNewRows(): void
    {
        $id   = $this->insertListing('New');
        $cat1 = $this->insertCategory('Anime');
        $cat2 = $this->insertCategory('Music');

        $this->repo->saveCategories($id, [$cat1, $cat2]);

        $ids = $this->repo->getCategoryIdsForListing($id);
        self::assertCount(2, $ids);
        self::assertContains($cat1, $ids);
        self::assertContains($cat2, $ids);
    }

    public function testSaveCategoriesReplacesExistingRows(): void
    {
        $id   = $this->insertListing('Replace');
        $old  = $this->insertCategory('Old');
        $new1 = $this->insertCategory('New1');
        $new2 = $this->insertCategory('New2');
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id}, {$old})");

        $this->repo->saveCategories($id, [$new1, $new2]);

        $ids = $this->repo->getCategoryIdsForListing($id);
        self::assertSame(2, count($ids));
        self::assertNotContains($old, $ids);
        self::assertContains($new1, $ids);
        self::assertContains($new2, $ids);
    }

    public function testSaveCategoriesWithEmptyArrayClearsAll(): void
    {
        $id  = $this->insertListing('Clear');
        $cat = $this->insertCategory('Something');
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id}, {$cat})");

        $this->repo->saveCategories($id, []);

        self::assertSame([], $this->repo->getCategoryIdsForListing($id));
    }

    public function testSaveCategoriesSkipsZeroAndNegativeIds(): void
    {
        $id = $this->insertListing('Sanitise');

        $this->repo->saveCategories($id, [0, -1, -99]);

        self::assertSame([], $this->repo->getCategoryIdsForListing($id));
    }

    public function testSaveCategoriesIsIdempotent(): void
    {
        $id  = $this->insertListing('Idempotent');
        $cat = $this->insertCategory('Idempotent Cat');

        $this->repo->saveCategories($id, [$cat]);
        $this->repo->saveCategories($id, [$cat]);

        self::assertSame([$cat], $this->repo->getCategoryIdsForListing($id));
    }

    // -----------------------------------------------------------------------
    // getFiltered — category filter uses junction table
    // -----------------------------------------------------------------------

    public function testGetFilteredByCategoryUsesJunctionTable(): void
    {
        $id1 = $this->insertListing('Match');
        $id2 = $this->insertListing('No Match');
        $cat = $this->insertCategory('Target');
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id1}, {$cat})");

        $results = $this->repo->getFiltered('current', $cat);

        self::assertCount(1, $results);
        self::assertSame($id1, $results[0]->id);
    }

    public function testGetFilteredWithMultiCategoryListingMatchesOnEither(): void
    {
        $id   = $this->insertListing('Both');
        $cat1 = $this->insertCategory('CatA');
        $cat2 = $this->insertCategory('CatB');
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id}, {$cat1})");
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id}, {$cat2})");

        self::assertCount(1, $this->repo->getFiltered('current', $cat1));
        self::assertCount(1, $this->repo->getFiltered('current', $cat2));
    }

    public function testGetFilteredNoCategoryReturnsAll(): void
    {
        $this->insertListing('Alpha');
        $this->insertListing('Beta');

        self::assertCount(2, $this->repo->getFiltered('current'));
    }

    // -----------------------------------------------------------------------
    // getCategoryList — joins through junction table
    // -----------------------------------------------------------------------

    public function testGetCategoryListReflectsJunctionTable(): void
    {
        $id  = $this->insertListing('Listed');
        $cat = $this->insertCategory('Jazz');
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id}, {$cat})");

        $list = $this->repo->getCategoryList();

        self::assertCount(1, $list);
        self::assertSame('Jazz', $list[0]['name']);
        self::assertSame(1, $list[0]['listing_count']);
    }

    public function testGetCategoryListCountsDistinctListings(): void
    {
        $id1 = $this->insertListing('One');
        $id2 = $this->insertListing('Two');
        $cat = $this->insertCategory('Shared');
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id1}, {$cat})");
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$id2}, {$cat})");

        $list = $this->repo->getCategoryList();

        self::assertCount(1, $list);
        self::assertSame(2, $list[0]['listing_count']);
    }

    public function testGetCategoryListExcludesInactiveListings(): void
    {
        $active   = $this->insertListing('Active', status: 2);
        $inactive = $this->insertListing('Inactive', status: 0);
        $cat      = $this->insertCategory('Something');
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$active}, {$cat})");
        $this->db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES ({$inactive}, {$cat})");

        $list = $this->repo->getCategoryList();

        self::assertCount(1, $list);
        self::assertSame(1, $list[0]['listing_count']);
    }

    public function testGetCategoryListEmptyWhenNoJunctionRows(): void
    {
        $this->insertListing('Uncategorised');
        $this->insertCategory('Unused');

        self::assertSame([], $this->repo->getCategoryList());
    }

    public function testGetCategoryListWorksWhenCategoriesTableHasNoParentColumn(): void
    {
        $ddl = <<<SQL
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
    source    TEXT    NOT NULL DEFAULT 'tfl'
);
CREATE TABLE lstr_listing_categories (
    fanlisting_id INTEGER NOT NULL,
    category_id   INTEGER NOT NULL,
    PRIMARY KEY (fanlisting_id, category_id)
)
SQL;
        $db = SqliteDatabase::make($ddl);
        $repo = new ListingRepo($db, $this->makeConfig([]));

        $db->pdo()->exec("INSERT INTO owned (listingid, title, status) VALUES (1, 'Listed', 2)");
        $db->pdo()->exec("INSERT INTO lstr_categories (id, name, source) VALUES (5, 'Animation', 'tfl')");
        $db->pdo()->exec("INSERT INTO lstr_listing_categories VALUES (1, 5)");

        $list = $repo->getCategoryList();

        self::assertCount(1, $list);
        self::assertSame('Animation', $list[0]['name']);
        self::assertNull($list[0]['parent_id']);
        self::assertNull($list[0]['parent_name']);
        self::assertSame(1, $list[0]['listing_count']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function insertListing(string $title, int $status = 2): int
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO owned (title, status) VALUES ('{$title}', {$status})");
        return (int) $pdo->lastInsertId();
    }

    private function insertCategory(string $name, string $source = 'tfl'): int
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO lstr_categories (name, source) VALUES ('{$name}', '{$source}')");
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
