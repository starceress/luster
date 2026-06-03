<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\CategoryRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CategoryRepo slug generation and hierarchy enforcement.
 */
final class CategoryRepoSlugTest extends TestCase
{
    private const DDL = "
        CREATE TABLE lstr_categories (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            slug       TEXT    NOT NULL DEFAULT '',
            parent_id  INTEGER NULL,
            source     TEXT    NOT NULL DEFAULT 'custom',
            created_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE lstr_joined (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT NOT NULL,
            category_id INTEGER NULL
        )
    ";

    private function repo(): CategoryRepo
    {
        $db = SqliteDatabase::make(self::DDL);
        return new CategoryRepo($db);
    }

    // -----------------------------------------------------------------------
    // Slug generation
    // -----------------------------------------------------------------------

    public function testAddGeneratesSlugFromName(): void
    {
        $repo = $this->repo();
        $id   = $repo->add('Anime & Manga', 'custom');
        $cat  = $repo->getById($id);

        self::assertSame('anime-manga', (string) $cat->slug);
    }

    public function testAddSlugIsLowercaseKebab(): void
    {
        $repo = $this->repo();
        $id   = $repo->add('Movies & TV Shows', 'custom');
        $cat  = $repo->getById($id);

        self::assertSame('movies-tv-shows', (string) $cat->slug);
    }

    public function testAddGeneratesUniqueSlugOnCollision(): void
    {
        $repo = $this->repo();
        $id1  = $repo->add('Anime', 'custom');
        // Force a collision by inserting a row whose slug would also be 'anime'
        // We do that by adding a second category with the same slug base.
        $id2  = $repo->add('Anime', 'custom');  // findOrCreate blocks this via UNIQUE but let's check directly

        $cat1 = $repo->getById($id1);
        $cat2 = $repo->getById($id2);

        // One gets 'anime', the other gets 'anime-2'
        $slugs = [(string) $cat1->slug, (string) $cat2->slug];
        sort($slugs);
        self::assertSame(['anime', 'anime-2'], $slugs);
    }

    public function testFindOrCreateGeneratesSlug(): void
    {
        $repo = $this->repo();
        $id   = $repo->findOrCreate('tfl', 'Actors & Actresses');
        $cat  = $repo->getById($id);

        self::assertSame('actors-actresses', (string) $cat->slug);
    }

    public function testFindOrCreateReturnsExistingId(): void
    {
        $repo = $this->repo();
        $id1  = $repo->findOrCreate('tfl', 'Books');
        $id2  = $repo->findOrCreate('tfl', 'Books');

        self::assertSame($id1, $id2);
    }

    // -----------------------------------------------------------------------
    // update() slug handling
    // -----------------------------------------------------------------------

    public function testUpdateWithExplicitSlugChangesIt(): void
    {
        $repo = $this->repo();
        $id   = $repo->add('Anime', 'custom');
        $repo->update($id, 'Anime', 'custom', 'my-custom-slug');
        $cat  = $repo->getById($id);

        self::assertSame('my-custom-slug', (string) $cat->slug);
    }

    public function testUpdateWithNullSlugDoesNotChangeSlug(): void
    {
        $repo  = $this->repo();
        $id    = $repo->add('Anime', 'custom');
        $cat   = $repo->getById($id);
        $origSlug = (string) $cat->slug;

        $repo->update($id, 'Anime Renamed', 'custom', null);
        $cat  = $repo->getById($id);

        self::assertSame($origSlug, (string) $cat->slug);
    }

    // -----------------------------------------------------------------------
    // Parent / depth enforcement
    // -----------------------------------------------------------------------

    public function testAddWithValidParentSucceeds(): void
    {
        $repo   = $this->repo();
        $parent = $repo->add('Parent', 'custom');
        $child  = $repo->add('Child', 'custom', $parent);

        $cat = $repo->getById($child);
        self::assertSame((string) $parent, (string) $cat->parent_id);
    }

    public function testAddWithGrandparentThrows(): void
    {
        $repo   = $this->repo();
        $parent = $repo->add('Parent', 'custom');
        $child  = $repo->add('Child', 'custom', $parent);

        $this->expectException(\InvalidArgumentException::class);
        $repo->add('Grandchild', 'custom', $child);
    }

    public function testUpdateWithGrandparentThrows(): void
    {
        $repo   = $this->repo();
        $parent = $repo->add('Parent', 'custom');
        $child  = $repo->add('Child', 'custom', $parent);
        $other  = $repo->add('Other', 'custom');

        $this->expectException(\InvalidArgumentException::class);
        $repo->update($other, 'Other', 'custom', null, $child);
    }

    // -----------------------------------------------------------------------
    // buildIndentedList
    // -----------------------------------------------------------------------

    public function testBuildIndentedListOrganizesHierarchy(): void
    {
        $repo   = $this->repo();
        $parent = $repo->add('Anime', 'custom');
        $child  = $repo->add('Anime Movies', 'custom', $parent);
        $top    = $repo->add('Books', 'custom');

        $list = $repo->buildIndentedList();
        $ids  = array_column($list, 'id');

        // Parent must come before its child
        self::assertLessThan(array_search($child, $ids), array_search($parent, $ids));
    }

    public function testBuildIndentedListDepthAndIndentName(): void
    {
        $repo   = $this->repo();
        $parent = $repo->add('Anime', 'custom');
        $child  = $repo->add('Movies', 'custom', $parent);

        $list = $repo->buildIndentedList();
        $map  = [];
        foreach ($list as $item) {
            $map[$item['id']] = $item;
        }

        self::assertSame(0, $map[$parent]['depth']);
        self::assertSame('Anime', $map[$parent]['indent_name']);
        self::assertSame(1, $map[$child]['depth']);
        self::assertSame('— Movies', $map[$child]['indent_name']);
    }
}
