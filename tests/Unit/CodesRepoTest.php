<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\CodesRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class CodesRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_codes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id  INTEGER NULL,
    name           TEXT    NULL,
    filename       TEXT    NOT NULL,
    width          INTEGER NOT NULL DEFAULT 0,
    height         INTEGER NOT NULL DEFAULT 0,
    size_id        INTEGER NULL,
    donor_id       INTEGER NULL,
    status         TEXT    NOT NULL DEFAULT 'approved',
    submitter_name  TEXT   NULL,
    submitter_email TEXT   NULL,
    submitter_url   TEXT   NULL,
    sort_order     INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL;

    private CodesRepo $repo;

    protected function setUp(): void
    {
        $db = SqliteDatabase::make(self::DDL);
        $this->repo = new CodesRepo($db);
    }

    public function testInsertAndGetById(): void
    {
        $id = $this->repo->insert(1, 'test.jpg', 100, 50, 1);
        $code = $this->repo->getById($id);

        self::assertNotNull($code);
        self::assertSame(1, $code->listingId);
        self::assertSame('test.jpg', $code->filename);
        self::assertSame(100, $code->width);
        self::assertSame(50, $code->height);
        self::assertSame('approved', $code->status);
        self::assertNull($code->name);
        self::assertNull($code->submitterName);
        self::assertNull($code->submitterEmail);
    }

    public function testInsertWithSubmitterInfo(): void
    {
        $id = $this->repo->insert(1, 'fan.png', 50, 50, 1, null, null, 'pending', null, 'Alice', 'alice@example.com');
        $code = $this->repo->getById($id);

        self::assertNotNull($code);
        self::assertSame('pending', $code->status);
        self::assertSame('Alice', $code->submitterName);
        self::assertSame('alice@example.com', $code->submitterEmail);
    }

    /**
     * getById()'s result is read interchangeably with getPendingAllPaginated()'s
     * items (a raw (object) cast of the DB row) by GlobalCodesController and
     * maybeSendDonorEmail() — both read snake_case fanlisting_id/submitter_*,
     * not just the camelCase listingId/submitterName/etc. Without these aliases
     * present, ->fanlisting_id and ->submitter_email silently resolve to null
     * on a getById() result (undefined property on stdClass warns, not
     * fatals) instead of throwing, so this only surfaces as wrong behavior
     * (collective/per-listing checks always taking the "collective" branch,
     * donor emails missing name/url), never a visible error.
     */
    public function testGetByIdIncludesSnakeCaseAliasesForListingAndSubmitterFields(): void
    {
        $id   = $this->repo->insert(7, 'fan.png', 50, 50, 1, null, null, 'pending', null, 'Alice', 'alice@example.com', 'https://example.com/alice');
        $code = $this->repo->getById($id);

        self::assertSame(7, $code->listingId);
        self::assertSame(7, $code->fanlisting_id);
        self::assertSame('Alice', $code->submitterName);
        self::assertSame('Alice', $code->submitter_name);
        self::assertSame('alice@example.com', $code->submitterEmail);
        self::assertSame('alice@example.com', $code->submitter_email);
        self::assertSame('https://example.com/alice', $code->submitterUrl);
        self::assertSame('https://example.com/alice', $code->submitter_url);
    }

    /** A NULL fanlisting_id (collective/site-wide code) must round-trip as true null, not 0. */
    public function testGetByIdFanlistingIdIsNullForCollectiveCode(): void
    {
        $id   = $this->repo->insert(null, 'collective.png', 50, 50, 1);
        $code = $this->repo->getById($id);

        self::assertSame(0, $code->listingId, 'listingId int-coerces null to 0 (pre-existing, per-listing-scoped callers rely on this)');
        self::assertNull($code->fanlisting_id, 'fanlisting_id must preserve true nullability so collective-vs-per-listing checks work');
    }

    public function testGetByListingFiltersStatus(): void
    {
        $this->repo->insert(1, 'a.jpg', 50, 50, 1, null, null, 'approved');
        $this->repo->insert(1, 'b.jpg', 50, 50, 2, null, null, 'pending');
        $this->repo->insert(1, 'c.jpg', 50, 50, 3, null, null, 'rejected');

        $approved = $this->repo->getByListing(1, 'approved');
        self::assertCount(1, $approved);
        self::assertSame('a.jpg', $approved[0]->filename);

        $all = $this->repo->getByListing(1);
        self::assertCount(3, $all);
    }

    public function testUpdateStatus(): void
    {
        $id = $this->repo->insert(1, 'x.jpg', 50, 50, 1, null, null, 'pending');
        $this->repo->updateStatus($id, 'approved');
        $code = $this->repo->getById($id);
        self::assertSame('approved', $code->status);
    }

    /**
     * bulkStatus() must scope by listing — a crafted/stale ID list spanning
     * multiple listings should only ever affect the ones matching $listingId,
     * the same way a single-code approve/reject already scopes by listing.
     */
    public function testBulkStatusOnlyAffectsCodesInTheGivenListing(): void
    {
        $inScope    = $this->repo->insert(1, 'a.jpg', 50, 50, 1, null, null, 'pending');
        $outOfScope = $this->repo->insert(2, 'b.jpg', 50, 50, 1, null, null, 'pending');

        $count = $this->repo->bulkStatus([$inScope, $outOfScope], 'approved', 1);

        self::assertSame(1, $count);
        self::assertSame('approved', $this->repo->getById($inScope)->status);
        self::assertSame('pending', $this->repo->getById($outOfScope)->status, 'a code from a different listing must not be touched');
    }

    /** Passing $listingId = null scopes to collective-only codes (fanlisting_id IS NULL). */
    public function testBulkStatusWithNullListingIdOnlyAffectsCollectiveCodes(): void
    {
        $collective  = $this->repo->insert(null, 'c.jpg', 50, 50, 1, null, null, 'pending');
        $perListing  = $this->repo->insert(1, 'd.jpg', 50, 50, 1, null, null, 'pending');

        $count = $this->repo->bulkStatus([$collective, $perListing], 'approved', null);

        self::assertSame(1, $count);
        self::assertSame('approved', $this->repo->getById($collective)->status);
        self::assertSame('pending', $this->repo->getById($perListing)->status, 'a per-listing code must not be touched by a collective-scoped bulk action');
    }

    /**
     * approveAllPending() has three distinct scopes, not two: a specific
     * listing, collective-only, or truly everything. $listingId=null alone
     * can't tell "collective" apart from "no scope at all" — both real admin
     * UI buttons (a per-group "Approve All" on the collective bucket, and a
     * page-level "Approve All Pending") need to be distinguishable.
     */
    public function testApproveAllPendingScopedToOneListingLeavesOthersUntouched(): void
    {
        $inListing1 = $this->repo->insert(1, 'a.jpg', 50, 50, 1, null, null, 'pending');
        $inListing2 = $this->repo->insert(2, 'b.jpg', 50, 50, 1, null, null, 'pending');
        $collective = $this->repo->insert(null, 'c.jpg', 50, 50, 1, null, null, 'pending');

        $count = $this->repo->approveAllPending(1);

        self::assertSame(1, $count);
        self::assertSame('approved', $this->repo->getById($inListing1)->status);
        self::assertSame('pending', $this->repo->getById($inListing2)->status);
        self::assertSame('pending', $this->repo->getById($collective)->status);
    }

    public function testApproveAllPendingCollectiveOnlyLeavesPerListingCodesUntouched(): void
    {
        $collective1 = $this->repo->insert(null, 'a.jpg', 50, 50, 1, null, null, 'pending');
        $collective2 = $this->repo->insert(null, 'b.jpg', 50, 50, 2, null, null, 'pending');
        $perListing  = $this->repo->insert(1, 'c.jpg', 50, 50, 1, null, null, 'pending');

        $count = $this->repo->approveAllPending(null, true);

        self::assertSame(2, $count);
        self::assertSame('approved', $this->repo->getById($collective1)->status);
        self::assertSame('approved', $this->repo->getById($collective2)->status);
        self::assertSame('pending', $this->repo->getById($perListing)->status, 'a per-listing code must not be approved by the collective-only "Approve All"');
    }

    public function testApproveAllPendingWithNoScopeApprovesEverything(): void
    {
        $perListing = $this->repo->insert(1, 'a.jpg', 50, 50, 1, null, null, 'pending');
        $collective = $this->repo->insert(null, 'b.jpg', 50, 50, 1, null, null, 'pending');

        $count = $this->repo->approveAllPending(null, false);

        self::assertSame(2, $count);
        self::assertSame('approved', $this->repo->getById($perListing)->status);
        self::assertSame('approved', $this->repo->getById($collective)->status);
    }

    /**
     * getByListing()'s results are passed straight into
     * GlobalCodesController::maybeSendDonorEmail(), which reads snake_case
     * fanlisting_id/submitter_* — without these aliases (same gap getById()
     * had before it was fixed), donor notification emails were silently
     * skipped for every "Approve All" scoped to one listing or collective.
     */
    public function testGetByListingIncludesSnakeCaseAliasesForListingAndSubmitterFields(): void
    {
        $this->repo->insert(7, 'fan.png', 50, 50, 1, null, null, 'pending', null, 'Alice', 'alice@example.com', 'https://example.com/alice');
        $codes = $this->repo->getByListing(7);

        self::assertCount(1, $codes);
        $code = $codes[0];
        self::assertSame(7, $code->listingId);
        self::assertSame(7, $code->fanlisting_id);
        self::assertSame('Alice', $code->submitterName);
        self::assertSame('Alice', $code->submitter_name);
        self::assertSame('alice@example.com', $code->submitterEmail);
        self::assertSame('alice@example.com', $code->submitter_email);
        self::assertSame('https://example.com/alice', $code->submitterUrl);
        self::assertSame('https://example.com/alice', $code->submitter_url);
    }

    /** getByListing(null) (the collective bucket) must preserve true null, not 0, for fanlisting_id. */
    public function testGetByListingWithNullListingIdFanlistingIdIsNull(): void
    {
        $this->repo->insert(null, 'collective.png', 50, 50, 1);
        $codes = $this->repo->getByListing(null);

        self::assertCount(1, $codes);
        self::assertNull($codes[0]->fanlisting_id);
    }

    public function testUpdateName(): void
    {
        $id = $this->repo->insert(1, 'y.jpg', 50, 50, 1);
        $this->repo->updateName($id, 'My Code');
        $code = $this->repo->getById($id);
        self::assertSame('My Code', $code->name);

        $this->repo->updateName($id, null);
        $code = $this->repo->getById($id);
        self::assertNull($code->name);
    }

    public function testReorder(): void
    {
        $id1 = $this->repo->insert(1, '1.jpg', 50, 50, 10);
        $id2 = $this->repo->insert(1, '2.jpg', 50, 50, 20);
        $id3 = $this->repo->insert(1, '3.jpg', 50, 50, 30);

        // Reverse the order
        $this->repo->reorder(1, [$id3, $id2, $id1]);

        $codes = $this->repo->getByListing(1);
        self::assertSame($id3, $codes[0]->id);
        self::assertSame($id2, $codes[1]->id);
        self::assertSame($id1, $codes[2]->id);
    }

    public function testReorderIgnoresWrongListing(): void
    {
        $id1 = $this->repo->insert(1, '1.jpg', 50, 50, 1);
        $this->repo->insert(2, '2.jpg', 50, 50, 1);

        // Try to reorder listing 2 using an ID that belongs to listing 1 only
        $this->repo->reorder(2, [$id1]);

        // id1 should remain at sort_order=1 (unchanged — ownership guard blocks it)
        $code1 = $this->repo->getById($id1);
        self::assertSame(1, $code1->sortOrder);
    }

    public function testCountByListing(): void
    {
        $this->repo->insert(1, 'a.jpg', 50, 50, 1);
        $this->repo->insert(1, 'b.jpg', 50, 50, 2);
        $this->repo->insert(2, 'c.jpg', 50, 50, 1);

        self::assertSame(2, $this->repo->countByListing(1));
        self::assertSame(1, $this->repo->countByListing(2));
    }

    public function testCountPendingByListing(): void
    {
        $this->repo->insert(1, 'a.jpg', 50, 50, 1, null, null, 'approved');
        $this->repo->insert(1, 'b.jpg', 50, 50, 2, null, null, 'pending');
        $this->repo->insert(1, 'c.jpg', 50, 50, 3, null, null, 'pending');

        self::assertSame(2, $this->repo->countPendingByListing(1));
    }

    public function testDelete(): void
    {
        $id = $this->repo->insert(1, 'del.jpg', 50, 50, 1);
        $this->repo->delete($id);
        self::assertNull($this->repo->getById($id));
    }

    public function testGetByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repo->getById(99999));
    }

    public function testGetMaxSortOrder(): void
    {
        self::assertSame(0, $this->repo->getMaxSortOrder(1));
        $this->repo->insert(1, 'a.jpg', 50, 50, 5);
        $this->repo->insert(1, 'b.jpg', 50, 50, 12);
        self::assertSame(12, $this->repo->getMaxSortOrder(1));
    }
}
