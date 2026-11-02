<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Core\ListingLifecycleService;
use Luster\Repositories\ListingLifecycleRepo;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests ListingLifecycleService::archive()/reopen() against both Enthusiast
 * schema variants (unprefixed 'owned' and standard 'enth_listings').
 * Confirms: archiving snapshots the pre-archive upcoming/trouble state and
 * writes the legacy inactive representation (never widening its meaning),
 * reopen restores the exact snapshot, round-trips are no-ops on the legacy
 * columns, and the lifecycle row only exists while archived.
 */
final class ListingLifecycleServiceTest extends TestCase
{
    private const OWNED_DDL = <<<SQL
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

    private const ENTH_DDL = <<<SQL
CREATE TABLE enth_listings (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT    NOT NULL DEFAULT '',
    url             TEXT    NOT NULL DEFAULT 'https://example.com',
    email           TEXT    NOT NULL DEFAULT '',
    subject         TEXT    NOT NULL DEFAULT '',
    dbprefix        TEXT    NOT NULL DEFAULT '',
    additional_info TEXT    NOT NULL DEFAULT '',
    category        INTEGER NOT NULL DEFAULT 0,
    upcoming        INTEGER NOT NULL DEFAULT 0,
    trouble         INTEGER NOT NULL DEFAULT 0
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

    private function makeConfig(array $values): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export($values, true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }

    /** @return array{ListingLifecycleService, ListingRepo, ListingLifecycleRepo, Database} */
    private function makeOwned(): array
    {
        $db            = SqliteDatabase::make(self::OWNED_DDL);
        $config        = $this->makeConfig(['enth_table_prefix' => '']);
        $listingRepo   = new ListingRepo($db, $config);
        $lifecycleRepo = new ListingLifecycleRepo($db);
        return [new ListingLifecycleService($listingRepo, $lifecycleRepo), $listingRepo, $lifecycleRepo, $db];
    }

    /** @return array{ListingLifecycleService, ListingRepo, ListingLifecycleRepo, Database} */
    private function makeEnth(): array
    {
        $db            = SqliteDatabase::make(self::ENTH_DDL);
        $config        = $this->makeConfig(['enth_table_prefix' => 'enth_']);
        $listingRepo   = new ListingRepo($db, $config);
        $lifecycleRepo = new ListingLifecycleRepo($db);
        return [new ListingLifecycleService($listingRepo, $lifecycleRepo), $listingRepo, $lifecycleRepo, $db];
    }

    private function insertOwned(Database $db, string $title = 'Test', int $status = 2): int
    {
        $pdo = $this->pdo($db);
        $pdo->prepare('INSERT INTO owned (title, subject, status) VALUES (:t, :t, :s)')
            ->execute(['t' => $title, 's' => $status]);
        return (int) $pdo->lastInsertId();
    }

    private function insertEnth(Database $db, string $name = 'Test', bool $upcoming = false, bool $trouble = false): int
    {
        $pdo = $this->pdo($db);
        $pdo->prepare('INSERT INTO enth_listings (name, subject, upcoming, trouble) VALUES (:n, :n, :u, :tr)')
            ->execute(['n' => $name, 'u' => (int) $upcoming, 'tr' => (int) $trouble]);
        return (int) $pdo->lastInsertId();
    }

    private function pdo(Database $db): \PDO
    {
        $prop = new \ReflectionProperty($db, 'pdo');
        $prop->setAccessible(true);
        return $prop->getValue($db);
    }

    // -----------------------------------------------------------------------
    // archive() — owned schema
    // -----------------------------------------------------------------------

    public function testArchiveSetsLegacyInactiveRepresentationOwnedSchema(): void
    {
        [$service, $listingRepo, , $db] = $this->makeOwned();
        $id = $this->insertOwned($db, status: 2); // current

        self::assertTrue($service->archive($id, 'closed'));

        $listing = $listingRepo->getById($id);
        self::assertFalse($listing->upcoming);
        self::assertTrue($listing->trouble);
    }

    public function testArchiveSnapshotsPreArchiveStateOwnedSchema(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db, status: 1); // upcoming

        $service->archive($id, 'closed');

        $row = $lifecycleRepo->getByListingId($id);
        self::assertNotNull($row);
        self::assertTrue($row['legacy_upcoming']);
        self::assertFalse($row['legacy_trouble']);
        self::assertSame('closed', $row['lifecycle_status']);
    }

    public function testReopenRestoresExactSnapshotOwnedSchema(): void
    {
        [$service, $listingRepo, $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db, status: 1); // upcoming

        $service->archive($id, 'closed');
        self::assertTrue($service->reopen($id));

        $listing = $listingRepo->getById($id);
        self::assertTrue($listing->upcoming);
        self::assertFalse($listing->trouble);
        self::assertNull($lifecycleRepo->getByListingId($id), 'Lifecycle row must be removed on reopen');
    }

    public function testArchiveReopenRoundTripIsNoOpOnLegacyColumnsOwnedSchema(): void
    {
        [$service, $listingRepo, , $db] = $this->makeOwned();
        $id = $this->insertOwned($db, status: 2); // current

        $before = $listingRepo->getById($id);
        $service->archive($id, 'adopted_out');
        $service->reopen($id);
        $after = $listingRepo->getById($id);

        self::assertSame($before->upcoming, $after->upcoming);
        self::assertSame($before->trouble, $after->trouble);
    }

    // -----------------------------------------------------------------------
    // archive() — enth_ schema
    // -----------------------------------------------------------------------

    public function testArchiveSetsLegacyInactiveRepresentationEnthSchema(): void
    {
        [$service, $listingRepo, , $db] = $this->makeEnth();
        $id = $this->insertEnth($db, upcoming: false, trouble: false); // current

        self::assertTrue($service->archive($id, 'closed'));

        $listing = $listingRepo->getById($id);
        self::assertFalse($listing->upcoming);
        self::assertTrue($listing->trouble);
    }

    public function testArchiveSnapshotsPreArchiveStateEnthSchema(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeEnth();
        $id = $this->insertEnth($db, upcoming: true, trouble: false); // upcoming

        $service->archive($id, 'adopted_out');

        $row = $lifecycleRepo->getByListingId($id);
        self::assertNotNull($row);
        self::assertTrue($row['legacy_upcoming']);
        self::assertFalse($row['legacy_trouble']);
        self::assertSame('adopted_out', $row['lifecycle_status']);
    }

    public function testReopenRestoresExactSnapshotEnthSchema(): void
    {
        [$service, $listingRepo, $lifecycleRepo, $db] = $this->makeEnth();
        $id = $this->insertEnth($db, upcoming: true, trouble: false); // upcoming

        $service->archive($id, 'closed');
        self::assertTrue($service->reopen($id));

        $listing = $listingRepo->getById($id);
        self::assertTrue($listing->upcoming);
        self::assertFalse($listing->trouble);
        self::assertNull($lifecycleRepo->getByListingId($id));
    }

    public function testArchiveReopenRoundTripIsNoOpOnLegacyColumnsEnthSchema(): void
    {
        [$service, $listingRepo, , $db] = $this->makeEnth();
        $id = $this->insertEnth($db, upcoming: false, trouble: true); // pending

        $before = $listingRepo->getById($id);
        $service->archive($id, 'closed');
        $service->reopen($id);
        $after = $listingRepo->getById($id);

        self::assertSame($before->upcoming, $after->upcoming);
        self::assertSame($before->trouble, $after->trouble);
    }

    // -----------------------------------------------------------------------
    // Adopted Out successor metadata
    // -----------------------------------------------------------------------

    public function testArchiveAdoptedOutStoresSuccessorFields(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db);

        $service->archive($id, 'adopted_out', 'New Owner', 'https://newowner.example.com', 'Handed off amicably.');

        $row = $lifecycleRepo->getByListingId($id);
        self::assertSame('New Owner', $row['adopted_to_name']);
        self::assertSame('https://newowner.example.com', $row['adopted_to_url']);
        self::assertSame('Handed off amicably.', $row['archive_note']);
    }

    public function testArchiveClosedDoesNotStoreSuccessorFieldsEvenIfPassed(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db);

        // archive() only persists adopted_to_* for 'adopted_out' — passing them for
        // 'closed' should be silently ignored, not stored.
        $service->archive($id, 'closed', 'Should Not Be Stored', 'https://example.com/nope', 'A note.');

        $row = $lifecycleRepo->getByListingId($id);
        self::assertNull($row['adopted_to_name']);
        self::assertNull($row['adopted_to_url']);
        self::assertSame('A note.', $row['archive_note'], 'archive_note applies to both closed and adopted_out');
    }

    // -----------------------------------------------------------------------
    // Failure cases
    // -----------------------------------------------------------------------

    public function testArchiveRejectsUnknownStatus(): void
    {
        [$service, , , $db] = $this->makeOwned();
        $id = $this->insertOwned($db);

        self::assertFalse($service->archive($id, 'pending'));
        self::assertFalse($service->archive($id, 'bogus'));
    }

    public function testArchiveReturnsFalseForNonExistentListing(): void
    {
        [$service] = $this->makeOwned();

        self::assertFalse($service->archive(999, 'closed'));
    }

    public function testReopenReturnsFalseWhenNotArchived(): void
    {
        [$service, , , $db] = $this->makeOwned();
        $id = $this->insertOwned($db);

        self::assertFalse($service->reopen($id));
    }

    public function testReopenReturnsFalseForNonExistentListing(): void
    {
        [$service] = $this->makeOwned();

        self::assertFalse($service->reopen(999));
    }

    // -----------------------------------------------------------------------
    // Re-archiving (Closed → Adopted Out) snapshot preservation
    // -----------------------------------------------------------------------

    public function testRearchivingPreservesOriginalSnapshot(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db, status: 1); // upcoming

        // First archive: snapshots upcoming=true, trouble=false
        $service->archive($id, 'closed');
        $rowAfterFirst = $lifecycleRepo->getByListingId($id);
        self::assertNotNull($rowAfterFirst);
        self::assertTrue($rowAfterFirst['legacy_upcoming']);
        self::assertFalse($rowAfterFirst['legacy_trouble']);

        // Re-archive to a different lifecycle status: original snapshot must survive
        $service->archive($id, 'adopted_out');
        $rowAfterSecond = $lifecycleRepo->getByListingId($id);
        self::assertSame('adopted_out', $rowAfterSecond['lifecycle_status']);
        self::assertTrue($rowAfterSecond['legacy_upcoming'], 'Original upcoming snapshot must be preserved on re-archive');
        self::assertFalse($rowAfterSecond['legacy_trouble'], 'Original trouble snapshot must be preserved on re-archive');
    }

    public function testReopenAfterRearchivingRestoresOriginalState(): void
    {
        [$service, $listingRepo, , $db] = $this->makeOwned();
        $id = $this->insertOwned($db, status: 1); // upcoming

        $service->archive($id, 'closed');
        $service->archive($id, 'adopted_out');
        $service->reopen($id);

        $listing = $listingRepo->getById($id);
        self::assertTrue($listing->upcoming, 'Reopen must restore original upcoming state, not the archived inactive state');
        self::assertFalse($listing->trouble);
    }

    // -----------------------------------------------------------------------
    // Re-archiving keeps the note, successor and date (bulk "Archive as...")
    // -----------------------------------------------------------------------

    /**
     * Archives through the edit-form path (upsert with metadata and a date),
     * the way a listing gets its note and successor in the first place.
     */
    private function archiveWithDetails(ListingLifecycleRepo $lifecycleRepo, int $id, string $status): void
    {
        $isAdopted = $status === 'adopted_out';
        $lifecycleRepo->upsert(
            $id, $status, true, false,
            $isAdopted ? 'Jane' : null,
            $isAdopted ? 'https://jane.example' : null,
            'Handed off.',
            '2020-05-01',
        );
    }

    public function testRearchiveClosedToClosedKeepsNoteAndDate(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db);
        $this->archiveWithDetails($lifecycleRepo, $id, 'closed');

        $service->archive($id, 'closed');

        $row = $lifecycleRepo->getByListingId($id);
        self::assertSame('closed', $row['lifecycle_status']);
        self::assertSame('Handed off.', $row['archive_note']);
        self::assertSame('2020-05-01', $row['archived_at']);
    }

    public function testRearchiveAdoptedToAdoptedKeepsSuccessorNoteAndDate(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db);
        $this->archiveWithDetails($lifecycleRepo, $id, 'adopted_out');

        $service->archive($id, 'adopted_out');

        $row = $lifecycleRepo->getByListingId($id);
        self::assertSame('adopted_out', $row['lifecycle_status']);
        self::assertSame('Jane', $row['adopted_to_name']);
        self::assertSame('https://jane.example', $row['adopted_to_url']);
        self::assertSame('Handed off.', $row['archive_note']);
        self::assertSame('2020-05-01', $row['archived_at']);
    }

    public function testRearchiveClosedToAdoptedKeepsNoteAndDate(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db);
        $this->archiveWithDetails($lifecycleRepo, $id, 'closed');

        $service->archive($id, 'adopted_out');

        $row = $lifecycleRepo->getByListingId($id);
        self::assertSame('adopted_out', $row['lifecycle_status']);
        self::assertNull($row['adopted_to_name'], 'A Closed listing had no successor to carry over');
        self::assertSame('Handed off.', $row['archive_note']);
        self::assertSame('2020-05-01', $row['archived_at']);
    }

    public function testRearchiveAdoptedToClosedDropsSuccessorButKeepsNoteAndDate(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db);
        $this->archiveWithDetails($lifecycleRepo, $id, 'adopted_out');

        $service->archive($id, 'closed');

        $row = $lifecycleRepo->getByListingId($id);
        self::assertSame('closed', $row['lifecycle_status']);
        self::assertNull($row['adopted_to_name'], 'Closed listings have no successor');
        self::assertNull($row['adopted_to_url']);
        self::assertSame('Handed off.', $row['archive_note']);
        self::assertSame('2020-05-01', $row['archived_at']);
    }

    public function testRearchiveWithExplicitEmptyStringsStillClears(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db);
        $this->archiveWithDetails($lifecycleRepo, $id, 'adopted_out');

        $service->archive($id, 'adopted_out', '', '', '');

        $row = $lifecycleRepo->getByListingId($id);
        self::assertSame('', $row['adopted_to_name']);
        self::assertSame('', $row['adopted_to_url']);
        self::assertSame('', $row['archive_note']);
    }

    public function testRearchiveKeepsOriginalReopenSnapshot(): void
    {
        [$service, $listingRepo, $lifecycleRepo, $db] = $this->makeOwned();
        $id = $this->insertOwned($db, status: 1); // upcoming
        $service->archive($id, 'adopted_out', 'Jane', 'https://jane.example', 'Handed off.');

        $service->archive($id, 'adopted_out');
        $service->archive($id, 'closed');
        $service->reopen($id);

        $listing = $listingRepo->getById($id);
        self::assertTrue($listing->upcoming, 'Reopen must restore the state from before the first archive');
        self::assertFalse($listing->trouble);
        self::assertNull($lifecycleRepo->getByListingId($id));
    }

    // -----------------------------------------------------------------------
    // ListingLifecycleService::isLifecycleStatus()
    // -----------------------------------------------------------------------

    public function testIsLifecycleStatus(): void
    {
        self::assertTrue(ListingLifecycleService::isLifecycleStatus('closed'));
        self::assertTrue(ListingLifecycleService::isLifecycleStatus('adopted_out'));
        self::assertFalse(ListingLifecycleService::isLifecycleStatus('current'));
        self::assertFalse(ListingLifecycleService::isLifecycleStatus('pending'));
        self::assertFalse(ListingLifecycleService::isLifecycleStatus(''));
    }

    // -----------------------------------------------------------------------
    // ListingLifecycleRepo::getMapForListings()
    // -----------------------------------------------------------------------

    public function testGetMapForListingsReturnsOnlyArchivedListings(): void
    {
        [$service, , $lifecycleRepo, $db] = $this->makeOwned();
        $id1 = $this->insertOwned($db);
        $id2 = $this->insertOwned($db);
        $id3 = $this->insertOwned($db);

        $service->archive($id1, 'closed');
        $service->archive($id2, 'adopted_out');
        // $id3 left unarchived

        $map = $lifecycleRepo->getMapForListings([$id1, $id2, $id3]);

        self::assertSame(['closed', 'adopted_out'], [$map[$id1] ?? null, $map[$id2] ?? null]);
        self::assertArrayNotHasKey($id3, $map);
    }

    public function testGetMapForListingsReturnsEmptyArrayForEmptyInput(): void
    {
        [, , $lifecycleRepo] = $this->makeOwned();

        self::assertSame([], $lifecycleRepo->getMapForListings([]));
    }
}
