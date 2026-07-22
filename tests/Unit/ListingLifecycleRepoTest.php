<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingLifecycleRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class ListingLifecycleRepoTest extends TestCase
{
    private const DDL = <<<SQL
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

    private ListingLifecycleRepo $repo;

    protected function setUp(): void
    {
        $db         = SqliteDatabase::make(self::DDL);
        $this->repo = new ListingLifecycleRepo($db);
    }

    public function testFreshArchiveWithBlankDateDefaultsToToday(): void
    {
        $this->repo->upsert(1, 'closed', false, true, null, null, null, null);

        $row = $this->repo->getByListingId(1);
        self::assertNotNull($row);
        self::assertSame(date('Y-m-d'), $row['archived_at']);
    }

    public function testExplicitDateIsStoredAsGiven(): void
    {
        $this->repo->upsert(1, 'closed', false, true, null, null, null, '2020-06-15');

        $row = $this->repo->getByListingId(1);
        self::assertSame('2020-06-15', $row['archived_at']);
    }

    public function testResaveWithBlankDatePreservesPreviouslyStoredDate(): void
    {
        $this->repo->upsert(1, 'closed', false, true, null, null, null, '2020-06-15');
        // Re-save without touching the date field (blank submitted value) —
        // must NOT reset to today, unlike the old NOW()-on-every-save behavior.
        $this->repo->upsert(1, 'closed', false, true, null, null, 'updated note', null);

        $row = $this->repo->getByListingId(1);
        self::assertSame('2020-06-15', $row['archived_at']);
        self::assertSame('updated note', $row['archive_note']);
    }

    public function testSwitchingClosedToAdoptedOutWithoutTouchingDateKeepsSameDate(): void
    {
        $this->repo->upsert(1, 'closed', false, true, null, null, null, '2020-06-15');
        $this->repo->upsert(1, 'adopted_out', false, true, 'New Owner', 'https://example.com', null, null);

        $row = $this->repo->getByListingId(1);
        self::assertSame('adopted_out', $row['lifecycle_status']);
        self::assertSame('2020-06-15', $row['archived_at']);
        self::assertSame('New Owner', $row['adopted_to_name']);
    }

    public function testExplicitDateOverridesPreviouslyStoredDate(): void
    {
        $this->repo->upsert(1, 'closed', false, true, null, null, null, '2020-06-15');
        $this->repo->upsert(1, 'closed', false, true, null, null, null, '2021-01-01');

        $row = $this->repo->getByListingId(1);
        self::assertSame('2021-01-01', $row['archived_at']);
    }

    // --- Missing-table degradation (pre-Migration-93 install) ---

    public function testReadMethodsDoNotThrowWhenTableDoesNotExist(): void
    {
        $db   = SqliteDatabase::make('');
        $repo = new ListingLifecycleRepo($db);

        self::assertNull($repo->getByListingId(1));
        self::assertSame([], $repo->getMapForListings([1, 2, 3]));
        // Reaching this line without an exception is the assertion.
        $repo->delete(1);
        self::assertTrue(true);
    }

    /**
     * upsert() is deliberately NOT guarded — a silently-swallowed archive
     * write would leave a listing flagged inactive in the registry with no
     * lifecycle row and no archive metadata, which is worse than a visible
     * failure. This is a negative test: it locks in that behavior so a future
     * "helpful" catch added here gets caught by CI instead of silently
     * reintroducing the corrupted-state bug.
     */
    public function testUpsertStillThrowsWhenTableDoesNotExist(): void
    {
        $db   = SqliteDatabase::make('');
        $repo = new ListingLifecycleRepo($db);

        $this->expectException(\Throwable::class);
        $repo->upsert(1, 'closed', false, true);
    }
}
