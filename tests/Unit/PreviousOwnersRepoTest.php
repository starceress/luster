<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\PreviousOwnersRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class PreviousOwnersRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_listing_previous_owners (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id  INTEGER NOT NULL,
    owner_name     TEXT    NOT NULL DEFAULT '',
    owner_url      TEXT    NOT NULL DEFAULT '',
    sort_order     INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT    NOT NULL DEFAULT '',
    updated_at     TEXT    NOT NULL DEFAULT ''
)
SQL;

    private PreviousOwnersRepo $repo;

    protected function setUp(): void
    {
        $db = SqliteDatabase::make(self::DDL);
        $this->repo = new PreviousOwnersRepo($db);
    }

    public function testGetForListingReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repo->getForListing(1));
    }

    public function testReplaceForListingInsertsRowsInOrder(): void
    {
        $this->repo->replaceForListing(1, [
            ['owner_name' => 'Alice', 'owner_url' => 'https://alice.example.com'],
            ['owner_name' => 'Bob', 'owner_url' => ''],
        ]);

        $rows = $this->repo->getForListing(1);
        self::assertCount(2, $rows);
        self::assertSame('Alice', $rows[0]['owner_name']);
        self::assertSame('https://alice.example.com', $rows[0]['owner_url']);
        self::assertSame('Bob', $rows[1]['owner_name']);
        self::assertSame('', $rows[1]['owner_url']);
    }

    public function testReplaceForListingDropsRowsWhereBothFieldsAreBlank(): void
    {
        $this->repo->replaceForListing(1, [
            ['owner_name' => '', 'owner_url' => ''],
            ['owner_name' => '  ', 'owner_url' => '  '],
            ['owner_name' => 'Kept', 'owner_url' => ''],
        ]);

        $rows = $this->repo->getForListing(1);
        self::assertCount(1, $rows);
        self::assertSame('Kept', $rows[0]['owner_name']);
    }

    public function testReplaceForListingKeepsUrlOnlyRow(): void
    {
        $this->repo->replaceForListing(1, [
            ['owner_name' => '', 'owner_url' => 'https://example.com'],
        ]);

        $rows = $this->repo->getForListing(1);
        self::assertCount(1, $rows);
        self::assertSame('', $rows[0]['owner_name']);
        self::assertSame('https://example.com', $rows[0]['owner_url']);
    }

    public function testReplaceForListingReplacesEntirelyNotAppends(): void
    {
        $this->repo->replaceForListing(1, [
            ['owner_name' => 'First', 'owner_url' => ''],
        ]);
        $this->repo->replaceForListing(1, [
            ['owner_name' => 'Second', 'owner_url' => ''],
        ]);

        $rows = $this->repo->getForListing(1);
        self::assertCount(1, $rows);
        self::assertSame('Second', $rows[0]['owner_name']);
    }

    public function testReplaceForListingIsScopedPerListing(): void
    {
        $this->repo->replaceForListing(1, [['owner_name' => 'Listing1Owner', 'owner_url' => '']]);
        $this->repo->replaceForListing(2, [['owner_name' => 'Listing2Owner', 'owner_url' => '']]);

        self::assertCount(1, $this->repo->getForListing(1));
        self::assertCount(1, $this->repo->getForListing(2));
        self::assertSame('Listing1Owner', $this->repo->getForListing(1)[0]['owner_name']);
    }

    public function testDeleteForListingRemovesAllRows(): void
    {
        $this->repo->replaceForListing(1, [
            ['owner_name' => 'A', 'owner_url' => ''],
            ['owner_name' => 'B', 'owner_url' => ''],
        ]);
        $this->repo->deleteForListing(1);

        self::assertSame([], $this->repo->getForListing(1));
    }

    public function testDeleteForListingDoesNotAffectOtherListings(): void
    {
        $this->repo->replaceForListing(1, [['owner_name' => 'A', 'owner_url' => '']]);
        $this->repo->replaceForListing(2, [['owner_name' => 'B', 'owner_url' => '']]);
        $this->repo->deleteForListing(1);

        self::assertSame([], $this->repo->getForListing(1));
        self::assertCount(1, $this->repo->getForListing(2));
    }

    /**
     * An install that has the new application code but hasn't run
     * public/migrate/ (Migration 102) yet must not crash — every method
     * should no-op instead of letting a "table doesn't exist" PDOException
     * escape. Uses a database with no tables at all to simulate that state.
     */
    public function testMethodsDoNotThrowWhenTableDoesNotExist(): void
    {
        $db   = SqliteDatabase::make('');
        $repo = new PreviousOwnersRepo($db);

        self::assertSame([], $repo->getForListing(1));

        $repo->replaceForListing(1, [['owner_name' => 'Alice', 'owner_url' => '']]);
        $repo->deleteForListing(1);

        // Reaching this line without an exception is the assertion.
        self::assertTrue(true);
    }
}
