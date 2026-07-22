<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Confirms ListingRepo::getFiltered()/getCategoryList()/getLifecycleCounts()
 * degrade gracefully — instead of throwing — on an install that has the new
 * application code but hasn't run public/migrate/ (Migration 93) yet, so
 * lstr_listing_lifecycle doesn't exist. Deliberately uses a database WITHOUT
 * that table (unlike ListingRepoLifecycleFilterTest, which always creates it).
 *
 * 'closed'/'adopted_out'/'past' are entirely defined by the missing table, so
 * an empty result is the *correct* answer, not just a safe fallback. 'pending'
 * is real legacy data independent of the lifecycle feature, so it must still
 * return real rows via a fallback query — not silently hide them.
 */
final class ListingRepoLifecycleMissingTableTest extends TestCase
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
    name      TEXT NOT NULL,
    slug      TEXT NOT NULL DEFAULT '',
    source    TEXT NOT NULL DEFAULT '',
    parent_id INTEGER NULL
);
CREATE TABLE lstr_listing_categories (
    fanlisting_id INTEGER NOT NULL,
    category_id   INTEGER NOT NULL
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

    private function insertListing(string $title, int $status = 0): int
    {
        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO owned (title, subject, status) VALUES (:t, :t, :s)')
            ->execute(['t' => $title, 's' => $status]);
        return (int) $pdo->lastInsertId();
    }

    public function testClosedAdoptedOutPastReturnEmptyArraysNotExceptions(): void
    {
        $this->insertListing('Some Pending FL', status: 0);

        self::assertSame([], $this->repo->getFiltered('closed'));
        self::assertSame([], $this->repo->getFiltered('adopted_out'));
        self::assertSame([], $this->repo->getFiltered('past'));
    }

    public function testPendingStillReturnsRealListingsViaFallback(): void
    {
        $pendingId = $this->insertListing('Truly Pending', status: 0);

        $results = $this->repo->getFiltered('pending');

        self::assertCount(1, $results);
        self::assertSame($pendingId, $results[0]->id);
    }

    public function testCurrentAndUpcomingAreUnaffected(): void
    {
        $this->insertListing('Current FL', status: 2);
        $this->insertListing('Upcoming FL', status: 1);

        self::assertCount(1, $this->repo->getFiltered('current'));
        self::assertCount(1, $this->repo->getFiltered('upcoming'));
    }

    public function testGetCategoryListClosedAdoptedOutPastReturnEmptyArrays(): void
    {
        self::assertSame([], $this->repo->getCategoryList('closed'));
        self::assertSame([], $this->repo->getCategoryList('adopted_out'));
        self::assertSame([], $this->repo->getCategoryList('past'));
    }

    public function testGetCategoryListPendingStillReturnsRealCounts(): void
    {
        $pendingId = $this->insertListing('Truly Pending', status: 0);
        $pdo       = $this->pdo();
        $pdo->prepare("INSERT INTO lstr_categories (name, slug, source) VALUES ('Anime', 'anime', 'tfl')")->execute();
        $catId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO lstr_listing_categories (fanlisting_id, category_id) VALUES (:fid, :cid)')
            ->execute(['fid' => $pendingId, 'cid' => $catId]);

        $results = $this->repo->getCategoryList('pending');

        self::assertCount(1, $results);
        self::assertSame(1, $results[0]['listing_count']);
    }

    public function testGetLifecycleCountsReturnsZeroesNotException(): void
    {
        $counts = $this->repo->getLifecycleCounts();

        self::assertSame(['closed' => 0, 'adopted_out' => 0], $counts);
    }

    public function testAllWithExcludePendingStillReturnsRealListingsViaFallback(): void
    {
        // A missing lifecycle table means nothing could be archived yet, so 'all'
        // with pending excluded should fall back to the old plain-legacy exclusion
        // (same shape 'pending' itself falls back to) instead of throwing or
        // silently returning an empty result.
        $currentId = $this->insertListing('Current FL', status: 2);
        $this->insertListing('Truly Pending', status: 0);

        $results = $this->repo->getFiltered('all', null, excludePending: true);

        self::assertCount(1, $results);
        self::assertSame($currentId, $results[0]->id);
    }

    public function testGetCategoryListAllWithExcludePendingStillReturnsRealCounts(): void
    {
        $currentId = $this->insertListing('Current FL', status: 2);
        $pdo       = $this->pdo();
        $pdo->prepare("INSERT INTO lstr_categories (name, slug, source) VALUES ('Anime', 'anime', 'tfl')")->execute();
        $catId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO lstr_listing_categories (fanlisting_id, category_id) VALUES (:fid, :cid)')
            ->execute(['fid' => $currentId, 'cid' => $catId]);

        $results = $this->repo->getCategoryList('all', excludePending: true);

        self::assertCount(1, $results);
        self::assertSame(1, $results[0]['listing_count']);
    }
}
