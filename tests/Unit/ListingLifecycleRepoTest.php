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
    archived_at      TEXT    NULL,
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
        $this->repo->upsert(1, 'closed', false, true, null, null, null, '');

        $row = $this->repo->getByListingId(1);
        self::assertNotNull($row);
        self::assertSame(date('Y-m-d'), $row['archived_at']);
    }

    public function testFreshArchiveWithNoDateGivenDefaultsToToday(): void
    {
        // Bulk "Archive as..." leaves the date out altogether.
        $this->repo->upsert(1, 'closed', false, true);

        self::assertSame(date('Y-m-d'), $this->repo->getByListingId(1)['archived_at']);
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
        $this->repo->upsert(1, 'closed', false, true, null, null, 'updated note', '');

        $row = $this->repo->getByListingId(1);
        self::assertSame('2020-06-15', $row['archived_at']);
        self::assertSame('updated note', $row['archive_note']);
    }

    public function testSwitchingClosedToAdoptedOutWithoutTouchingDateKeepsSameDate(): void
    {
        $this->repo->upsert(1, 'closed', false, true, null, null, null, '2020-06-15');
        $this->repo->upsert(1, 'adopted_out', false, true, 'New Owner', 'https://example.com', null, '');

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

    // --- An unknown date (NULL, Migration 108) ---

    public function testExplicitNullStoresAnUnknownDate(): void
    {
        $this->repo->upsert(1, 'closed', false, true, null, null, null, null);

        $row = $this->repo->getByListingId(1);
        self::assertNotNull($row);
        self::assertNull($row['archived_at'], 'Unknown stays NULL; it only becomes blank for display');
    }

    public function testBlankResaveOfAnUnknownDateKeepsItUnknown(): void
    {
        $this->repo->upsert(1, 'closed', false, true, null, null, null, null);
        $this->repo->upsert(1, 'closed', false, true, null, null, 'note', '');
        $this->repo->upsert(1, 'closed', false, true, null, null, 'note again');

        self::assertNull($this->repo->getByListingId(1)['archived_at'], 'A re-save never swaps "unknown" for today');
    }

    public function testSwitchingClosedToAdoptedOutKeepsAnUnknownDate(): void
    {
        $this->repo->upsert(1, 'closed', false, true, null, null, null, null);
        $this->repo->upsert(1, 'adopted_out', false, true, 'New Owner', 'https://example.com', null, '');

        $row = $this->repo->getByListingId(1);
        self::assertSame('adopted_out', $row['lifecycle_status']);
        self::assertNull($row['archived_at']);
    }

    public function testAKnownDateCanReplaceAnUnknownOneAndBack(): void
    {
        $this->repo->upsert(1, 'closed', false, true, null, null, null, null);
        $this->repo->upsert(1, 'closed', false, true, null, null, null, '2019-03-02');
        self::assertSame('2019-03-02', $this->repo->getByListingId(1)['archived_at']);

        $this->repo->upsert(1, 'closed', false, true, null, null, null, null);
        self::assertNull($this->repo->getByListingId(1)['archived_at']);
    }

    public function testSupportsUnknownDateOnlyWhenTheColumnIsNullable(): void
    {
        self::assertTrue($this->repo->supportsUnknownDate());

        $before = new ListingLifecycleRepo(SqliteDatabase::make(str_replace('archived_at      TEXT    NULL', 'archived_at      TEXT    NOT NULL', self::DDL)));
        self::assertFalse($before->supportsUnknownDate(), 'Before Migration 108 the column is NOT NULL');

        self::assertFalse((new ListingLifecycleRepo(SqliteDatabase::make('')))->supportsUnknownDate(), 'No table at all');
    }

    public function testRenderFieldsShowsAnUnknownDateAsBlank(): void
    {
        $this->repo->upsert(1, 'adopted_out', false, true, 'New Owner', null, null, null);

        self::assertSame(
            ['adopted_to_name' => 'New Owner', 'adopted_to_url' => '', 'archive_note' => '', 'archived_at' => ''],
            ListingLifecycleRepo::renderFields($this->repo->getByListingId(1)),
        );
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
