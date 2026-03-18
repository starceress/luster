<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\CodeSizesRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class CodeSizesRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_code_sizes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id  INTEGER NOT NULL,
    label          TEXT    NOT NULL,
    width          INTEGER NULL,
    height         INTEGER NULL,
    sort_order     INTEGER NOT NULL DEFAULT 0
)
SQL;

    private CodeSizesRepo $repo;

    protected function setUp(): void
    {
        $db = SqliteDatabase::make(self::DDL);
        $this->repo = new CodeSizesRepo($db);
    }

    public function testInsertAndGetById(): void
    {
        $id = $this->repo->insert(1, '50×50', 50, 50, 1);
        $size = $this->repo->getById($id);

        self::assertNotNull($size);
        self::assertSame(1, $size->listingId);
        self::assertSame('50×50', $size->label);
        self::assertSame(50, $size->width);
        self::assertSame(50, $size->height);
    }

    public function testInsertWithNullDimensions(): void
    {
        $id = $this->repo->insert(1, 'Custom', null, null, 1);
        $size = $this->repo->getById($id);

        self::assertNotNull($size);
        self::assertNull($size->width);
        self::assertNull($size->height);
    }

    public function testGetByListing(): void
    {
        $this->repo->insert(1, 'Small', 50, 50, 2);
        $this->repo->insert(1, 'Large', 100, 100, 1);
        $this->repo->insert(2, 'Other', 75, 75, 1);

        $sizes = $this->repo->getByListing(1);
        self::assertCount(2, $sizes);
        // Should be ordered by sort_order ascending
        self::assertSame('Large', $sizes[0]->label);
        self::assertSame('Small', $sizes[1]->label);
    }

    public function testUpdate(): void
    {
        $id = $this->repo->insert(1, 'Old', 50, 50, 1);
        $this->repo->update($id, 'New', 75, 80);
        $size = $this->repo->getById($id);

        self::assertSame('New', $size->label);
        self::assertSame(75, $size->width);
        self::assertSame(80, $size->height);
    }

    public function testDelete(): void
    {
        $id = $this->repo->insert(1, 'ToDelete', 50, 50, 1);
        $this->repo->delete($id);
        self::assertNull($this->repo->getById($id));
    }

    public function testGetMaxSortOrder(): void
    {
        self::assertSame(0, $this->repo->getMaxSortOrder(1));
        $this->repo->insert(1, 'A', null, null, 5);
        $this->repo->insert(1, 'B', null, null, 3);
        self::assertSame(5, $this->repo->getMaxSortOrder(1));
    }

    public function testGetByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repo->getById(99999));
    }
}
