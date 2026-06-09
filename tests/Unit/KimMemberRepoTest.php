<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class KimMemberRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_kim_members (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id INTEGER NOT NULL,
    name          TEXT    NOT NULL DEFAULT '',
    email         TEXT    NOT NULL DEFAULT '',
    url           TEXT    NOT NULL DEFAULT '',
    password      TEXT    NOT NULL DEFAULT '',
    pending       INTEGER NOT NULL DEFAULT 1,
    granted       INTEGER NOT NULL DEFAULT 0,
    added_at      TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL;

    private KimMemberRepo $repo;

    protected function setUp(): void
    {
        $this->repo = new KimMemberRepo(SqliteDatabase::make(self::DDL));
    }

    // -----------------------------------------------------------------------
    // getApprovedCountsAll
    // -----------------------------------------------------------------------

    public function testGetApprovedCountsAllReturnsEmptyArrayWhenNoMembers(): void
    {
        self::assertSame([], $this->repo->getApprovedCountsAll());
    }

    public function testGetApprovedCountsAllOnlyCountsApprovedMembers(): void
    {
        $this->repo->create(1, 'Alice', 'alice@example.com', '', '');
        $this->repo->create(1, 'Bob',   'bob@example.com',   '', '');

        // Both still pending — counts should be empty
        self::assertSame([], $this->repo->getApprovedCountsAll());
    }

    public function testGetApprovedCountsAllCountsCorrectlyAfterApproval(): void
    {
        $id1 = $this->repo->create(1, 'Alice',   'alice@example.com',   '', '');
        $id2 = $this->repo->create(1, 'Bob',     'bob@example.com',     '', '');
        $this->repo->create(1,          'Charlie', 'charlie@example.com', '', '');

        $this->repo->approve($id1);
        $this->repo->approve($id2);
        // Charlie remains pending

        $counts = $this->repo->getApprovedCountsAll();

        self::assertSame([1 => 2], $counts);
    }

    public function testGetApprovedCountsAllBucketsMultipleListings(): void
    {
        $id1 = $this->repo->create(1, 'Alice', 'alice@example.com', '', '');
        $id2 = $this->repo->create(2, 'Bob',   'bob@example.com',   '', '');
        $id3 = $this->repo->create(2, 'Carol', 'carol@example.com', '', '');
        $id4 = $this->repo->create(3, 'Dave',  'dave@example.com',  '', '');

        $this->repo->approve($id1);
        $this->repo->approve($id2);
        $this->repo->approve($id3);
        // Dave (listing 3) stays pending

        $counts = $this->repo->getApprovedCountsAll();

        self::assertSame(1, $counts[1]);
        self::assertSame(2, $counts[2]);
        self::assertArrayNotHasKey(3, $counts);
    }

    public function testGetApprovedCountsAllExcludesPendingFromCount(): void
    {
        $id1 = $this->repo->create(1, 'Alice', 'alice@example.com', '', '');
        $id2 = $this->repo->create(1, 'Bob',   'bob@example.com',   '', '');

        $this->repo->approve($id1);
        // Bob stays pending

        $counts = $this->repo->getApprovedCountsAll();

        self::assertSame([1 => 1], $counts);
    }
}
