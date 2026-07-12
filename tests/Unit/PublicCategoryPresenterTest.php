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
        // Overview (no category selected): list mode marks the "All" link as current
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(5, 'Anime', 'anime')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
        );

        self::assertStringContainsString('aria-current="page"', $result['html']);
        self::assertTrue($result['show_listings']);
    }

    public function testPerCategoryShowsDropdownSwitcher(): void
    {
        // Per-category page with default showCatSwitcher=true: shows a dropdown
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(5, 'Anime', 'anime'), $this->cat(6, 'Books', 'books')],
            5, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
        );

        self::assertStringContainsString('<select', $result['html']);
        self::assertTrue($result['show_listings']);
    }

    public function testPerCategoryHidesSwitcherWhenOff(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(5, 'Anime', 'anime')],
            5, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false, showCatSwitcher: false,
        );

        self::assertStringNotContainsString('<select', $result['html']);
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
        // Overview (no category selected): no item carries aria-current because
        // "All Listings" only activates when currentCategory==='all', not ''
        $result = PublicCategoryPresenter::renderJoined(
            ['Anime', 'Books'],
            [], 0, '', '/joined', showList: true, browseMode: false,
        );

        // In overview with no selection, no item is active
        self::assertStringNotContainsString('aria-current="page"', $result['html']);
        self::assertStringContainsString('Anime', $result['html']);
        self::assertStringContainsString('fl-category-list', $result['html']);
    }

    public function testJoinedPerCategoryShowsDropdownSwitcher(): void
    {
        // Per-category page with default showCatSwitcher=true: shows a dropdown
        $result = PublicCategoryPresenter::renderJoined(
            ['Anime', 'Books'],
            [], 0, 'Anime', '/joined', showList: true, browseMode: false,
        );

        self::assertStringContainsString('<select', $result['html']);
        self::assertStringContainsString('selected', $result['html']);
        self::assertTrue($result['show_listings']);
    }

    public function testJoinedPerCategoryHidesSwitcherWhenOff(): void
    {
        $result = PublicCategoryPresenter::renderJoined(
            ['Anime', 'Books'],
            [], 0, 'Anime', '/joined', showList: false, browseMode: false, showCatSwitcher: false,
        );

        self::assertSame('', $result['html']);
        self::assertTrue($result['show_listings']);
    }

    public function testRenderJoinedEmptyCategoriesReturnsEmptyHtml(): void
    {
        $result = PublicCategoryPresenter::renderJoined(
            [], [], 0, '', '/joined', showList: false, browseMode: false,
        );

        self::assertSame('', $result['html']);
        self::assertTrue($result['show_listings']);
    }

    // -----------------------------------------------------------------------
    // renderOwned — pseudo-entries (pending / upcoming)
    // -----------------------------------------------------------------------

    public function testListModeShowsPendingPseudoEntryWhenCountNonZero(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, 'Anime', 'anime')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
            totalCount: 5, showCatSwitcher: true,
            view: '', pendingCount: 3, showPending: true, upcomingCount: 0, showUpcoming: true,
        );

        self::assertStringContainsString('All Pending', $result['html']);
        self::assertStringContainsString('__pending__', $result['html']);
        self::assertStringContainsString('(3)', $result['html']);
    }

    public function testListModeOmitsPendingWhenToggleOff(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, 'Anime', 'anime')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
            totalCount: 5, showCatSwitcher: true,
            view: '', pendingCount: 3, showPending: false, upcomingCount: 0, showUpcoming: true,
        );

        self::assertStringNotContainsString('All Pending', $result['html']);
    }

    public function testPendingViewActivatesAriaCurrent(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, 'Anime', 'anime')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
            totalCount: 5, showCatSwitcher: true,
            view: 'pending', pendingCount: 3, showPending: true, upcomingCount: 0, showUpcoming: true,
        );

        // "All Pending" gets aria-current when view === 'pending'
        self::assertStringContainsString('aria-current="page"', $result['html']);
        // "All" link must NOT carry aria-current when a pseudo-view is active
        self::assertMatchesRegularExpression('/<a [^>]*href="\/listings"[^>]*>All<\/a>/', $result['html']);
        self::assertStringNotContainsString('<a aria-current="page" href="/listings">All</a>', $result['html']);
    }

    public function testListModeShowsPastPseudoEntryWhenCountNonZero(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, 'Anime', 'anime')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
            totalCount: 5, showCatSwitcher: true,
            view: '', pendingCount: 0, showPending: true, upcomingCount: 0, showUpcoming: true,
            closedCount: 0, showClosed: true, adoptedOutCount: 0, showAdoptedOut: true,
            pastCount: 4, showPast: true,
        );

        self::assertStringContainsString('Past Fanlistings', $result['html']);
        self::assertStringContainsString('__past__', $result['html']);
        self::assertStringContainsString('(4)', $result['html']);
    }

    public function testListModeOmitsPastWhenToggleOff(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, 'Anime', 'anime')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
            totalCount: 5, showCatSwitcher: true,
            view: '', pendingCount: 0, showPending: true, upcomingCount: 0, showUpcoming: true,
            closedCount: 0, showClosed: true, adoptedOutCount: 0, showAdoptedOut: true,
            pastCount: 4, showPast: false,
        );

        self::assertStringNotContainsString('Past Fanlistings', $result['html']);
    }

    public function testPastViewActivatesAriaCurrent(): void
    {
        $result = PublicCategoryPresenter::renderOwned(
            [$this->cat(1, 'Anime', 'anime')],
            0, '/listings', hideDropdown: false, showList: true,
            browseMode: false, browseShowAll: false,
            totalCount: 5, showCatSwitcher: true,
            view: 'past', pendingCount: 0, showPending: true, upcomingCount: 0, showUpcoming: true,
            closedCount: 0, showClosed: true, adoptedOutCount: 0, showAdoptedOut: true,
            pastCount: 4, showPast: true,
        );

        self::assertStringContainsString('aria-current="page"', $result['html']);
        self::assertStringNotContainsString('<a aria-current="page" href="/listings">All</a>', $result['html']);
    }

    // -----------------------------------------------------------------------
    // renderJoined — "All Listings" entry and pseudo-entries
    // -----------------------------------------------------------------------

    public function testJoinedListModeShowsAllListingsEntry(): void
    {
        $result = PublicCategoryPresenter::renderJoined(
            ['Anime'], ['Anime' => 2], 5, '', '/joined', showList: true, browseMode: false,
        );

        self::assertStringContainsString('All Listings', $result['html']);
        self::assertStringContainsString('?cat=all', $result['html']);
        self::assertStringContainsString('(5)', $result['html']);
    }

    public function testJoinedListModeShowsPendingPseudoEntry(): void
    {
        $result = PublicCategoryPresenter::renderJoined(
            ['Anime'], ['Anime' => 2], 5, '', '/joined',
            showList: true, browseMode: false, showCatSwitcher: true,
            view: '', pendingCount: 2, showPending: true,
        );

        self::assertStringContainsString('All Pending', $result['html']);
        self::assertStringContainsString('__pending__', $result['html']);
        self::assertStringContainsString('(2)', $result['html']);
    }

    // -----------------------------------------------------------------------
    // renderJoined — dropdown placeholder (fix: hidden-row / dead-zone bug)
    // -----------------------------------------------------------------------

    public function testJoinedDropdownOverviewHasDisabledPlaceholder(): void
    {
        // When no category is selected, "All Listings" must NOT be pre-selected;
        // instead a disabled placeholder allows the change event to fire when
        // the visitor picks "All Listings" for the first time.
        $result = PublicCategoryPresenter::renderJoined(
            ['Anime', 'Books'],
            [], 5, '', '/joined', showList: false, browseMode: false,
        );

        self::assertStringContainsString('disabled selected', $result['html']);
        self::assertStringContainsString('value="all"', $result['html']);
        // "All Listings" must NOT carry the selected attribute in overview state
        self::assertStringNotContainsString('<option value="all" selected', $result['html']);
    }

    public function testJoinedDropdownAllCategoryMarksAllListingsSelected(): void
    {
        // When cat=all is active, "All Listings" should carry selected.
        $result = PublicCategoryPresenter::renderJoined(
            ['Anime', 'Books'],
            [], 5, 'all', '/joined', showList: false, browseMode: false,
        );

        self::assertStringContainsString('<option value="all" selected', $result['html']);
        self::assertStringNotContainsString('disabled selected', $result['html']);
    }
}
