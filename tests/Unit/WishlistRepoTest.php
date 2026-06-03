<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Addons\Wishlist\WishlistRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class WishlistRepoTest extends TestCase
{
    // SQLite doesn't support ENUM; TEXT is used instead.
    private const DDL = <<<SQL
CREATE TABLE lstr_wishlist (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    subject     TEXT    NOT NULL DEFAULT '',
    url         TEXT    NOT NULL DEFAULT '',
    image       TEXT    NULL,
    category    TEXT    NOT NULL DEFAULT '',
    type        TEXT    NOT NULL DEFAULT 'wanted',
    description TEXT    NULL,
    added_at    TEXT    NULL,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    active      INTEGER NOT NULL DEFAULT 1
)
SQL;

    private WishlistRepo $repo;

    protected function setUp(): void
    {
        $db         = SqliteDatabase::make(self::DDL);
        $this->repo = new WishlistRepo($db);
    }

    // -----------------------------------------------------------------------
    // insert + getById
    // -----------------------------------------------------------------------

    public function testInsertAndGetById(): void
    {
        $id   = $this->repo->insert(['subject' => 'Naruto', 'type' => 'wanted', 'active' => true]);
        $item = $this->repo->getById($id);

        self::assertNotNull($item);
        self::assertSame($id, $item->id);
        self::assertSame('Naruto', $item->subject);
        self::assertSame('wanted', $item->type);
        self::assertTrue($item->active);
        self::assertNull($item->image);
        self::assertNull($item->description);
    }

    public function testInsertSetsAddedAtToTodayWhenOmitted(): void
    {
        $id   = $this->repo->insert(['subject' => 'X', 'type' => 'top']);
        $item = $this->repo->getById($id);

        self::assertNotNull($item);
        self::assertSame(date('Y-m-d'), $item->addedAt);
    }

    public function testInsertWithAllFields(): void
    {
        $id = $this->repo->insert([
            'subject'     => 'Fullmetal',
            'url'         => 'https://example.com',
            'image'       => 'https://example.com/img.jpg',
            'category'    => 'Anime',
            'type'        => 'top',
            'description' => 'Very wanted.',
            'added_at'    => '2025-01-15',
            'sort_order'  => 5,
            'active'      => false,
        ]);
        $item = $this->repo->getById($id);

        self::assertNotNull($item);
        self::assertSame('Fullmetal', $item->subject);
        self::assertSame('https://example.com', $item->url);
        self::assertSame('https://example.com/img.jpg', $item->image);
        self::assertSame('Anime', $item->category);
        self::assertSame('top', $item->type);
        self::assertSame('Very wanted.', $item->description);
        self::assertSame('2025-01-15', $item->addedAt);
        self::assertSame(5, $item->sortOrder);
        self::assertFalse($item->active);
    }

    public function testGetByIdReturnsNullForMissingId(): void
    {
        self::assertNull($this->repo->getById(9999));
    }

    // -----------------------------------------------------------------------
    // getAll + type filter
    // -----------------------------------------------------------------------

    public function testGetAllReturnsAllEntries(): void
    {
        $this->repo->insert(['subject' => 'A', 'type' => 'wanted']);
        $this->repo->insert(['subject' => 'B', 'type' => 'top']);
        $this->repo->insert(['subject' => 'C', 'type' => 'granted']);

        $all = $this->repo->getAll();
        self::assertCount(3, $all);
    }

    public function testGetAllFilterByType(): void
    {
        $this->repo->insert(['subject' => 'A', 'type' => 'wanted']);
        $this->repo->insert(['subject' => 'B', 'type' => 'top']);
        $this->repo->insert(['subject' => 'C', 'type' => 'wanted']);

        $wanted = $this->repo->getAll('wanted');
        self::assertCount(2, $wanted);
        foreach ($wanted as $item) {
            self::assertSame('wanted', $item->type);
        }
    }

    public function testGetAllActiveOnly(): void
    {
        $this->repo->insert(['subject' => 'Visible',  'type' => 'wanted', 'active' => true]);
        $this->repo->insert(['subject' => 'Hidden',   'type' => 'wanted', 'active' => false]);

        $active = $this->repo->getAll(null, true);
        self::assertCount(1, $active);
        self::assertSame('Visible', $active[0]->subject);
    }

    public function testPaginateCanFilterHiddenEntries(): void
    {
        $this->repo->insert(['subject' => 'Visible', 'type' => 'wanted', 'active' => true]);
        $this->repo->insert(['subject' => 'Hidden',  'type' => 'top',    'active' => false]);

        $hidden = $this->repo->paginate(null, false, '', '', 'subject', 'asc', 10, 1, false);

        self::assertSame(1, $hidden['total']);
        self::assertCount(1, $hidden['items']);
        self::assertSame('Hidden', $hidden['items'][0]->subject);
    }

    public function testGetAllOrderedBySortOrderThenId(): void
    {
        $this->repo->insert(['subject' => 'Z', 'type' => 'wanted', 'sort_order' => 10]);
        $this->repo->insert(['subject' => 'A', 'type' => 'wanted', 'sort_order' => 1]);
        $this->repo->insert(['subject' => 'M', 'type' => 'wanted', 'sort_order' => 5]);

        $all = $this->repo->getAll();
        self::assertSame('A', $all[0]->subject);
        self::assertSame('M', $all[1]->subject);
        self::assertSame('Z', $all[2]->subject);
    }

    // -----------------------------------------------------------------------
    // countAll + countByType
    // -----------------------------------------------------------------------

    public function testCountAll(): void
    {
        $this->repo->insert(['subject' => 'A', 'type' => 'wanted']);
        $this->repo->insert(['subject' => 'B', 'type' => 'top']);

        self::assertSame(2, $this->repo->countAll());
        self::assertSame(1, $this->repo->countAll('wanted'));
        self::assertSame(0, $this->repo->countAll('upcoming'));
    }

    public function testCountByType(): void
    {
        $this->repo->insert(['subject' => 'A', 'type' => 'wanted']);
        $this->repo->insert(['subject' => 'B', 'type' => 'wanted']);
        $this->repo->insert(['subject' => 'C', 'type' => 'top']);

        $counts = $this->repo->countByType();
        self::assertSame(2, $counts['wanted']);
        self::assertSame(1, $counts['top']);
        self::assertSame(0, $counts['granted']);
        self::assertSame(0, $counts['upcoming']);
    }

    public function testCountHidden(): void
    {
        $this->repo->insert(['subject' => 'Visible', 'type' => 'wanted', 'active' => true]);
        $this->repo->insert(['subject' => 'Hidden',  'type' => 'wanted', 'active' => false]);

        self::assertSame(1, $this->repo->countHidden());
    }

    // -----------------------------------------------------------------------
    // getCategories
    // -----------------------------------------------------------------------

    public function testGetCategoriesReturnsDistinctSortedValues(): void
    {
        $this->repo->insert(['subject' => 'A', 'type' => 'wanted', 'category' => 'Music']);
        $this->repo->insert(['subject' => 'B', 'type' => 'wanted', 'category' => 'Anime']);
        $this->repo->insert(['subject' => 'C', 'type' => 'wanted', 'category' => 'Music']);
        $this->repo->insert(['subject' => 'D', 'type' => 'wanted', 'category' => '']);

        $cats = $this->repo->getCategories();
        self::assertSame(['Anime', 'Music'], $cats);
    }

    // -----------------------------------------------------------------------
    // update
    // -----------------------------------------------------------------------

    public function testUpdate(): void
    {
        $id = $this->repo->insert(['subject' => 'Old', 'type' => 'wanted']);
        $this->repo->update($id, [
            'subject'    => 'New',
            'type'       => 'top',
            'url'        => 'https://example.com',
            'active'     => true,
            'sort_order' => 3,
        ]);
        $item = $this->repo->getById($id);

        self::assertNotNull($item);
        self::assertSame('New', $item->subject);
        self::assertSame('top', $item->type);
        self::assertSame('https://example.com', $item->url);
        self::assertSame(3, $item->sortOrder);
    }

    // -----------------------------------------------------------------------
    // delete
    // -----------------------------------------------------------------------

    public function testDelete(): void
    {
        $id = $this->repo->insert(['subject' => 'Gone', 'type' => 'wanted']);
        self::assertNotNull($this->repo->getById($id));

        $this->repo->delete($id);
        self::assertNull($this->repo->getById($id));
    }

    // -----------------------------------------------------------------------
    // toggleActive
    // -----------------------------------------------------------------------

    public function testToggleActiveFlipsState(): void
    {
        $id = $this->repo->insert(['subject' => 'X', 'type' => 'wanted', 'active' => true]);

        $newState = $this->repo->toggleActive($id);
        self::assertFalse($newState);
        self::assertFalse($this->repo->getById($id)?->active);

        $newState = $this->repo->toggleActive($id);
        self::assertTrue($newState);
        self::assertTrue($this->repo->getById($id)?->active);
    }

    // -----------------------------------------------------------------------
    // reorder
    // -----------------------------------------------------------------------

    public function testReorder(): void
    {
        $id1 = $this->repo->insert(['subject' => 'First',  'type' => 'wanted', 'sort_order' => 0]);
        $id2 = $this->repo->insert(['subject' => 'Second', 'type' => 'wanted', 'sort_order' => 1]);
        $id3 = $this->repo->insert(['subject' => 'Third',  'type' => 'wanted', 'sort_order' => 2]);

        // Reverse the order
        $this->repo->reorder([$id3, $id2, $id1]);

        $all = $this->repo->getAll();
        self::assertSame('Third',  $all[0]->subject);
        self::assertSame('Second', $all[1]->subject);
        self::assertSame('First',  $all[2]->subject);
    }

    // -----------------------------------------------------------------------
    // typeLabel
    // -----------------------------------------------------------------------

    public function testTypeLabel(): void
    {
        $id = $this->repo->insert(['subject' => 'A', 'type' => 'top']);
        self::assertSame('Top Wanted', $this->repo->getById($id)?->typeLabel());

        $id = $this->repo->insert(['subject' => 'B', 'type' => 'granted']);
        self::assertSame('Granted', $this->repo->getById($id)?->typeLabel());

        $id = $this->repo->insert(['subject' => 'C', 'type' => 'upcoming']);
        self::assertSame('Upcoming', $this->repo->getById($id)?->typeLabel());

        $id = $this->repo->insert(['subject' => 'D', 'type' => 'wanted']);
        self::assertSame('Wanted', $this->repo->getById($id)?->typeLabel());
    }
}
