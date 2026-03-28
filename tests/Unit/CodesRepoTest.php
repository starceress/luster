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
    fanlisting_id  INTEGER NOT NULL,
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
