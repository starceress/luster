<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\CodeCategoriesRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class CodeCategoriesRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_code_categories (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id  INTEGER NOT NULL,
    name           TEXT    NOT NULL,
    parent_id      INTEGER NULL,
    sort_order     INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE lstr_codes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id  INTEGER NOT NULL
);
CREATE TABLE lstr_code_category_map (
    code_id     INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    PRIMARY KEY (code_id, category_id)
)
SQL;

    private CodeCategoriesRepo $repo;

    protected function setUp(): void
    {
        $db = SqliteDatabase::make(self::DDL);
        $this->repo = new CodeCategoriesRepo($db);
    }

    public function testInsertAndGetById(): void
    {
        $id = $this->repo->insert(1, 'Animated', null, 1);
        $cat = $this->repo->getById($id);

        self::assertNotNull($cat);
        self::assertSame(1, $cat->listingId);
        self::assertSame('Animated', $cat->name);
        self::assertNull($cat->parentId);
    }

    public function testGetByListing(): void
    {
        $this->repo->insert(1, 'A', null, 2);
        $this->repo->insert(1, 'B', null, 1);
        $this->repo->insert(2, 'C', null, 1);

        $cats = $this->repo->getByListing(1);
        self::assertCount(2, $cats);
        // Should be ordered by sort_order ascending
        self::assertSame('B', $cats[0]->name);
    }

    public function testUpdate(): void
    {
        $id = $this->repo->insert(1, 'Old', null, 1);
        $this->repo->update($id, 'New', null);
        $cat = $this->repo->getById($id);
        self::assertSame('New', $cat->name);
    }

    public function testDelete(): void
    {
        $id = $this->repo->insert(1, 'ToDelete', null, 1);
        $this->repo->delete($id);
        self::assertNull($this->repo->getById($id));
    }

    public function testGetCategoriesForCodeReturnsEmptyWhenNoAssignments(): void
    {
        $result = $this->repo->getCategoriesForCode(999);
        self::assertSame([], $result);
    }

    public function testGetMaxSortOrder(): void
    {
        self::assertSame(0, $this->repo->getMaxSortOrder(1));
        $this->repo->insert(1, 'A', null, 5);
        $this->repo->insert(1, 'B', null, 3);
        self::assertSame(5, $this->repo->getMaxSortOrder(1));
    }

    public function testGetByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repo->getById(99999));
    }
}
