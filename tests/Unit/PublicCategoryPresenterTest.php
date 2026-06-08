<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\PublicCategoryPresenter;
use PHPUnit\Framework\TestCase;

final class PublicCategoryPresenterTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function cat(int $id, string $name, string $slug = '', ?int $parentId = null): array
    {
        return ['id' => $id, 'name' => $name, 'slug' => $slug, 'source' => 'custom',
                'parent_id' => $parentId, 'parent_name' => null, 'listing_count' => 1];
    }

    // -----------------------------------------------------------------------
    // renderOwned — hide_dropdown
    // -----------------------------------------------------------------------

    public function testHideDropdownReturnsEmptyHtml(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, 'Anime', 'anime')],
            0, '/listings', hideDropdown: true, showList: false,
            browseMode: false, browseShowAll: false,
        );

        self::assertSame('', $result['html']);
        self::assertTrue($result['show_listings']);
    }

    // -----------------------------------------------------------------------
    // renderOwned — list mode
    // -----------------------------------------------------------------------

    public function testListModeContainsAriaCurrent(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(5, 'Anime', 'anime')],
            5, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
        );

        self::assertStringContainsString('aria-current="page"', $result['html']);
        self::assertTrue($result['show_listings']);
    }

    public function testListModeUsesSlugInUrl(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(5, 'Anime', 'anime')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
        );

        self::assertStringContainsString('?cat=anime', $result['html']);
    }

    public function testListModeFallsBackToIdWhenNoSlug(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(7, 'Books', '')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
        );

        self::assertStringContainsString('?cat=7', $result['html']);
    }

    public function testListModeRendersChildrenNested(): void
    {
        $parent = $this->cat(1, 'Anime', 'anime');
        $child  = ['id' => 2, 'name' => 'Movies', 'slug' => 'anime-movies', 'source' => 'custom',
                   'parent_id' => 1, 'parent_name' => 'Anime', 'listing_count' => 1];

        $result = PublicCategoryPresenter::renderOwned(
            [$parent, $child],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
        );

        self::assertStringContainsString('fl-category-list-children', $result['html']);
    }

    // -----------------------------------------------------------------------
    // renderOwned — dropdown mode
    // -----------------------------------------------------------------------

    public function testDropdownModeContainsSelect(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(3, 'Movies', 'movies')],
            0, '/listings', hideDropdown: false, showList: false,
            browseMode: false, browseShowAll: false,
        );

        self::assertStringContainsString('<select', $result['html']);
        self::assertStringContainsString('fl-category-dropdown', $result['html']);
    }

    // -----------------------------------------------------------------------
    // renderOwned — browse mode
    // -----------------------------------------------------------------------

    public function testBrowseModeIndexShowsListingsFlag(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, 'Anime', 'anime')],
            0, '/listings', hideDropdown: false, showList: false,
            browseMode: true, browseShowAll: false,
        );

        self::assertFalse($result['show_listings']);
        self::assertStringContainsString('fl-category-list', $result['html']);
    }

    public function testBrowseModeWithCategoryShowsBackLink(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, 'Anime', 'anime')],
            1, '/listings', hideDropdown: false, showList: false,
            browseMode: true, browseShowAll: false,
        );

        self::assertTrue($result['show_listings']);
        self::assertStringContainsString('fl-category-back', $result['html']);
    }

    // -----------------------------------------------------------------------
    // renderOwned — fl-category-nav wrapper
    // -----------------------------------------------------------------------

    public function testOutputWrappedInFLCategoryNav(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, 'Anime', 'anime')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
        );

        self::assertStringContainsString('<div class="fl-category-nav"', $result['html']);
        self::assertStringContainsString('fl-category-nav', $result['html']);
        self::assertStringNotContainsString('<nav', $result['html']);
    }

    // -----------------------------------------------------------------------
    // renderOwned — XSS
    // -----------------------------------------------------------------------

    public function testCategoryNameEscapedInOutput(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, '<script>xss</script>', 'safe-slug')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
        );

        self::assertStringNotContainsString('<script>', $result['html']);
        self::assertStringContainsString('&lt;script&gt;', $result['html']);
    }

    // -----------------------------------------------------------------------
    // renderJoined
    // -----------------------------------------------------------------------

    public function testRenderJoinedBrowseIndexShowsListingsFlag(): void
    {
        $result = PublicCategoryPresenter::renderJoined(
            ['Anime', 'Books'],
            ['Anime' => 3, 'Books' => 1],
            4, '', '/joined', showList: false, browseMode: true,
        );

        self::assertFalse($result['show_listings']);
        self::assertStringContainsString('fl-category-list', $result['html']);
    }

    public function testRenderJoinedDropdownContainsOptions(): void
    {
        $result = PublicCategoryPresenter::renderJoined(
            ['Anime', 'Books'],
            [], 0, '', '/joined', showList: false, browseMode: false,
        );

        self::assertStringContainsString('<option', $result['html']);
        self::assertStringContainsString('Anime', $result['html']);
    }

    public function testRenderJoinedListAriaCurrentOnActive(): void
    {
        $result = PublicCategoryPresenter::renderJoined(
            ['Anime', 'Books'],
            [], 0, 'Anime', '/joined', showList: true, browseMode: false,
        );

        self::assertStringContainsString('aria-current="page"', $result['html']);
    }

    public function testRenderJoinedEmptyCategoriesReturnsEmptyHtml(): void
    {
        $result = PublicCategoryPresenter::renderJoined(
            [], [], 0, '', '/joined', showList: false, browseMode: false,
        );

        self::assertSame('', $result['html']);
        self::assertTrue($result['show_listings']);
    }
}
