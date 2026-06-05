<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Addons\Adoption\AdoptionRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class AdoptionRepoTest extends TestCase
{
    // SQLite doesn't support ENUM; TEXT substituted for status.
    private const DDL = <<<SQL
CREATE TABLE lstr_adoption_applications (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id INTEGER NOT NULL,
    name          TEXT    NOT NULL DEFAULT '',
    email         TEXT    NOT NULL DEFAULT '',
    url           TEXT    NOT NULL DEFAULT '',
    message       TEXT    NULL,
    admin_notes   TEXT    NULL,
    status        TEXT    NOT NULL DEFAULT 'pending',
    applied_at    TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL;

    private AdoptionRepo $repo;

    protected function setUp(): void
    {
        $this->repo = new AdoptionRepo(SqliteDatabase::make(self::DDL));
    }

    // -----------------------------------------------------------------------
    // insert + getById
    // -----------------------------------------------------------------------

    public function testInsertAndGetById(): void
    {
        $id  = $this->repo->insert(1, ['name' => 'Alice', 'email' => 'alice@example.com', 'url' => 'https://alice.test']);
        $row = $this->repo->getById($id);

        self::assertNotNull($row);
        self::assertSame($id, (int) $row['id']);
        self::assertSame(1,   (int) $row['fanlisting_id']);
        self::assertSame('Alice', $row['name']);
        self::assertSame('alice@example.com', $row['email']);
        self::assertSame('https://alice.test', $row['url']);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['message']);
    }

    public function testInsertNormalizesEmail(): void
    {
        $id  = $this->repo->insert(1, ['name' => 'Bob', 'email' => '  BOB@EXAMPLE.COM  ']);
        $row = $this->repo->getById($id);

        self::assertNotNull($row);
        self::assertSame('bob@example.com', $row['email']);
    }

    public function testInsertStoresMessage(): void
    {
        $id  = $this->repo->insert(1, ['name' => 'X', 'email' => 'x@x.com', 'message' => 'Please let me adopt!']);
        $row = $this->repo->getById($id);

        self::assertNotNull($row);
        self::assertSame('Please let me adopt!', $row['message']);
    }

    public function testGetByIdReturnsNullForMissingId(): void
    {
        self::assertNull($this->repo->getById(9999));
    }

    // -----------------------------------------------------------------------
    // hasPendingApplication
    // -----------------------------------------------------------------------

    public function testHasPendingApplicationReturnsFalseWhenNone(): void
    {
        self::assertFalse($this->repo->hasPendingApplication(1, 'nobody@example.com'));
    }

    public function testHasPendingApplicationReturnsTrueForNewStatus(): void
    {
        $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);

        self::assertTrue($this->repo->hasPendingApplication(1, 'a@example.com'));
    }

    public function testHasPendingApplicationReturnsTrueForGrantedStatus(): void
    {
        $id = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        $this->repo->setStatus($id, 'granted');

        self::assertTrue($this->repo->hasPendingApplication(1, 'a@example.com'));
    }

    public function testHasPendingApplicationReturnsTrueForApprovedStatus(): void
    {
        $id = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        $this->repo->setStatus($id, 'approved');

        self::assertTrue($this->repo->hasPendingApplication(1, 'a@example.com'));
    }

    public function testHasPendingApplicationReturnsFalseWhenOnlyRejected(): void
    {
        $id = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        $this->repo->setStatus($id, 'rejected');

        self::assertFalse($this->repo->hasPendingApplication(1, 'a@example.com'));
    }

    public function testHasPendingApplicationIsCaseInsensitive(): void
    {
        $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);

        self::assertTrue($this->repo->hasPendingApplication(1, 'A@EXAMPLE.COM'));
        self::assertTrue($this->repo->hasPendingApplication(1, '  a@Example.com  '));
    }

    public function testHasPendingApplicationIsScopedToListing(): void
    {
        $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);

        self::assertFalse($this->repo->hasPendingApplication(2, 'a@example.com'));
    }

    // -----------------------------------------------------------------------
    // getForListing
    // -----------------------------------------------------------------------

    public function testGetForListingReturnsAllStatusesWhenAll(): void
    {
        $id1 = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        $id2 = $this->repo->insert(1, ['name' => 'B', 'email' => 'b@example.com']);
        $this->repo->setStatus($id2, 'approved');

        $rows = $this->repo->getForListing(1, 'all');
        self::assertCount(2, $rows);
    }

    public function testGetForListingFiltersToRequestedStatus(): void
    {
        $id1 = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        $id2 = $this->repo->insert(1, ['name' => 'B', 'email' => 'b@example.com']);
        $this->repo->setStatus($id2, 'approved');

        $newRows = $this->repo->getForListing(1, 'pending');
        self::assertCount(1, $newRows);
        self::assertSame((string) $id1, (string) $newRows[0]['id']);

        $approvedRows = $this->repo->getForListing(1, 'approved');
        self::assertCount(1, $approvedRows);
        self::assertSame((string) $id2, (string) $approvedRows[0]['id']);
    }

    public function testGetForListingIsScopedToListing(): void
    {
        $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        $this->repo->insert(2, ['name' => 'B', 'email' => 'b@example.com']);

        self::assertCount(1, $this->repo->getForListing(1, 'all'));
        self::assertCount(1, $this->repo->getForListing(2, 'all'));
    }

    // -----------------------------------------------------------------------
    // countForListing
    // -----------------------------------------------------------------------

    public function testCountForListingAll(): void
    {
        $id1 = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        $id2 = $this->repo->insert(1, ['name' => 'B', 'email' => 'b@example.com']);
        $this->repo->setStatus($id2, 'approved');

        self::assertSame(2, $this->repo->countForListing(1, 'all'));
        self::assertSame(1, $this->repo->countForListing(1, 'pending'));
        self::assertSame(1, $this->repo->countForListing(1, 'approved'));
        self::assertSame(0, $this->repo->countForListing(1, 'rejected'));
    }

    // -----------------------------------------------------------------------
    // countNew (global)
    // -----------------------------------------------------------------------

    public function testCountNewAcrossAllListings(): void
    {
        $id1 = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        $id2 = $this->repo->insert(2, ['name' => 'B', 'email' => 'b@example.com']);
        $id3 = $this->repo->insert(1, ['name' => 'C', 'email' => 'c@example.com']);
        $this->repo->setStatus($id3, 'approved');

        // id1=pending, id2=pending, id3=approved — countNew should return 2
        self::assertSame(2, $this->repo->countNew());
    }

    // -----------------------------------------------------------------------
    // setStatus
    // -----------------------------------------------------------------------

    public function testSetStatusUpdatesStatus(): void
    {
        $id = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);

        foreach (['approved', 'rejected', 'granted', 'pending'] as $status) {
            $this->repo->setStatus($id, $status);
            self::assertSame($status, $this->repo->getById($id)['status']);
        }
    }

    public function testSetStatusIgnoresInvalidStatus(): void
    {
        $id = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        $this->repo->setStatus($id, 'INVALID_STATUS');

        // Row must be unchanged
        self::assertSame('pending', $this->repo->getById($id)['status']);
    }

    // -----------------------------------------------------------------------
    // delete
    // -----------------------------------------------------------------------

    public function testDeleteRemovesApplication(): void
    {
        $id = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        self::assertNotNull($this->repo->getById($id));

        $this->repo->delete($id);
        self::assertNull($this->repo->getById($id));
    }

    // -----------------------------------------------------------------------
    // getForAllListings
    // -----------------------------------------------------------------------

    public function testGetForAllListingsReturnsAllWhenStatusAll(): void
    {
        $id1 = $this->repo->insert(1, ['name' => 'A', 'email' => 'a@example.com']);
        $id2 = $this->repo->insert(2, ['name' => 'B', 'email' => 'b@example.com']);
        $this->repo->setStatus($id2, 'approved');

        self::assertCount(2, $this->repo->getForAllListings('all'));
        self::assertCount(1, $this->repo->getForAllListings('pending'));
        self::assertCount(1, $this->repo->getForAllListings('approved'));
    }
}
