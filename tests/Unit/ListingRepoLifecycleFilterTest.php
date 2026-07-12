<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests ListingRepo::getFiltered()'s 'closed'/'adopted_out'/'pending'/'all'
 * status handling now that lstr_listing_lifecycle exists. Confirms archived
 * listings are excluded from plain 'pending' results (so the two concepts
 * don't overlap), included correctly under their own status strings, and
 * that returned Listing objects carry the right lifecycle property.
 */
final class ListingRepoLifecycleFilterTest extends TestCase
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

    private function insertListing(string $title, int $status = 2): int
    {
        $pdo = $this->pdo();
        $pdo->prepare('INSERT INTO owned (title, subject, status) VALUES (:t, :t, :s)')
            ->execute(['t' => $title, 's' => $status]);
        return (int) $pdo->lastInsertId();
    }

    private function archive(int $id, string $status): void
    {
        $this->pdo()->prepare(
            "INSERT INTO lstr_listing_lifecycle (fanlisting_id, lifecycle_status, archived_at)
             VALUES (:id, :status, '2026-01-01 00:00:00')"
        )->execute(['id' => $id, 'status' => $status]);
    }

    public function testClosedStatusReturnsOnlyClosedListings(): void
    {
        $closedId  = $this->insertListing('Closed FL', status: 0);
        $pendingId = $this->insertListing('Pending FL', status: 0);
        $this->archive($closedId, 'closed');

        $results = $this->repo->getFiltered('closed');

        self::assertCount(1, $results);
        self::assertSame($closedId, $results[0]->id);
        self::assertSame('closed', $results[0]->lifecycle);
    }

    public function testAdoptedOutStatusReturnsOnlyAdoptedOutListings(): void
    {
        $adoptedId = $this->insertListing('Adopted FL', status: 0);
        $closedId  = $this->insertListing('Closed FL', status: 0);
        $this->archive($adoptedId, 'adopted_out');
        $this->archive($closedId, 'closed');

        $results = $this->repo->getFiltered('adopted_out');

        self::assertCount(1, $results);
        self::assertSame($adoptedId, $results[0]->id);
        self::assertSame('adopted_out', $results[0]->lifecycle);
    }

    public function testPastStatusReturnsClosedAndAdoptedOutTogether(): void
    {
        $closedId  = $this->insertListing('Closed FL', status: 0);
        $adoptedId = $this->insertListing('Adopted FL', status: 0);
        $pendingId = $this->insertListing('Pending FL', status: 0);
        $this->archive($closedId, 'closed');
        $this->archive($adoptedId, 'adopted_out');

        $results = $this->repo->getFiltered('past');
        $byId    = [];
        foreach ($results as $r) {
            $byId[$r->id] = $r;
        }

        // One merged, sorted query — not two separate fetches concatenated together.
        self::assertCount(2, $results);
        self::assertArrayNotHasKey($pendingId, $byId);
        self::assertSame('closed', $byId[$closedId]->lifecycle);
        self::assertSame('adopted_out', $byId[$adoptedId]->lifecycle);
    }

    public function testPastStatusExcludesCurrentAndPendingListings(): void
    {
        $this->insertListing('Current FL', status: 2);
        $this->insertListing('Pending FL', status: 0);
        $closedId = $this->insertListing('Closed FL', status: 0);
        $this->archive($closedId, 'closed');

        $results = $this->repo->getFiltered('past');

        self::assertCount(1, $results);
        self::assertSame($closedId, $results[0]->id);
    }

    public function testPendingExcludesArchivedListings(): void
    {
        $truePending = $this->insertListing('Truly Pending', status: 0);
        $closedId    = $this->insertListing('Closed FL', status: 0);
        $this->archive($closedId, 'closed');

        $results = $this->repo->getFiltered('pending');

        self::assertCount(1, $results);
        self::assertSame($truePending, $results[0]->id);
        self::assertNull($results[0]->lifecycle);
    }

    public function testCurrentAndUpcomingAreUnaffectedByLifecycleTable(): void
    {
        $this->insertListing('Current FL', status: 2);
        $this->insertListing('Upcoming FL', status: 1);

        self::assertCount(1, $this->repo->getFiltered('current'));
        self::assertCount(1, $this->repo->getFiltered('upcoming'));
    }

    public function testAllStatusIncludesArchivedListingsWithLifecyclePopulated(): void
    {
        $currentId = $this->insertListing('Current FL', status: 2);
        $closedId  = $this->insertListing('Closed FL', status: 0);
        $this->archive($closedId, 'closed');

        $results = $this->repo->getFiltered('all');
        $byId     = [];
        foreach ($results as $r) {
            $byId[$r->id] = $r;
        }

        self::assertCount(2, $results);
        self::assertNull($byId[$currentId]->lifecycle);
        self::assertSame('closed', $byId[$closedId]->lifecycle);
    }

    public function testAllWithExcludePendingAlsoExcludesArchivedListings(): void
    {
        $currentId = $this->insertListing('Current FL', status: 2);
        $closedId  = $this->insertListing('Closed FL', status: 0);
        $this->archive($closedId, 'closed');

        $results = $this->repo->getFiltered('all', null, excludePending: true);

        self::assertCount(1, $results);
        self::assertSame($currentId, $results[0]->id);
    }

    public function testGetLifecycleCountsReturnsCorrectBreakdown(): void
    {
        $closed1 = $this->insertListing('Closed 1', status: 0);
        $closed2 = $this->insertListing('Closed 2', status: 0);
        $adopted = $this->insertListing('Adopted', status: 0);
        $this->archive($closed1, 'closed');
        $this->archive($closed2, 'closed');
        $this->archive($adopted, 'adopted_out');

        $counts = $this->repo->getLifecycleCounts();

        self::assertSame(2, $counts['closed']);
        self::assertSame(1, $counts['adopted_out']);
    }

    public function testGetLifecycleCountsReturnsZeroesWhenNothingArchived(): void
    {
        $this->insertListing('Current FL', status: 2);

        $counts = $this->repo->getLifecycleCounts();

        self::assertSame(0, $counts['closed']);
        self::assertSame(0, $counts['adopted_out']);
    }
}
