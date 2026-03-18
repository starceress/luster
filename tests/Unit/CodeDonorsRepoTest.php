<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\CodeDonorsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class CodeDonorsRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_code_donors (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id  INTEGER NOT NULL,
    name           TEXT    NOT NULL,
    email          TEXT    NULL,
    url            TEXT    NULL
);
CREATE TABLE lstr_codes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id  INTEGER NOT NULL,
    donor_id       INTEGER NULL
)
SQL;

    private CodeDonorsRepo $repo;

    protected function setUp(): void
    {
        $db = SqliteDatabase::make(self::DDL);
        $this->repo = new CodeDonorsRepo($db);
    }

    public function testInsertAndGetById(): void
    {
        $id = $this->repo->insert(1, 'Alice', 'alice@example.com', 'https://alice.example.com');
        $donor = $this->repo->getById($id);

        self::assertNotNull($donor);
        self::assertSame(1, $donor->listingId);
        self::assertSame('Alice', $donor->name);
        self::assertSame('alice@example.com', $donor->email);
        self::assertSame('https://alice.example.com', $donor->url);
    }

    public function testInsertWithNullOptionalFields(): void
    {
        $id = $this->repo->insert(1, 'Bob', null, null);
        $donor = $this->repo->getById($id);

        self::assertNotNull($donor);
        self::assertNull($donor->email);
        self::assertNull($donor->url);
    }

    public function testGetByListing(): void
    {
        $this->repo->insert(1, 'Charlie', null, null);
        $this->repo->insert(1, 'Alice', null, null);
        $this->repo->insert(2, 'Dave', null, null);

        $donors = $this->repo->getByListing(1);
        self::assertCount(2, $donors);
        // Should be ordered by name ascending
        self::assertSame('Alice', $donors[0]->name);
        self::assertSame('Charlie', $donors[1]->name);
    }

    public function testUpdate(): void
    {
        $id = $this->repo->insert(1, 'Old', 'old@example.com', null);
        $this->repo->update($id, 'New', 'new@example.com', 'https://new.example.com');
        $donor = $this->repo->getById($id);

        self::assertSame('New', $donor->name);
        self::assertSame('new@example.com', $donor->email);
        self::assertSame('https://new.example.com', $donor->url);
    }

    public function testDelete(): void
    {
        $id = $this->repo->insert(1, 'ToDelete', null, null);
        $this->repo->delete($id);
        self::assertNull($this->repo->getById($id));
    }

    public function testGetByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repo->getById(99999));
    }

    public function testCountCodesForDonorReturnsZeroWhenNone(): void
    {
        $id = $this->repo->insert(1, 'NoCodes', null, null);
        self::assertSame(0, $this->repo->countCodesForDonor($id));
    }
}
