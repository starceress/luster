<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders the public-facing category navigation UI for the owned and joined
 listing embeds. Supports multiple presentation modes: bulleted link lists,
 dropdown selects, and browse-by-category index pages. All output uses
 fl-category-* CSS classes and avoids the HTML nav element so it embeds
 cleanly into any host page without style collisions.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\View;

/**
 * Renders the public category navigator for owned and joined listing embeds.
 *
 * Produces embed-safe HTML with fl-category-* CSS classes and aria-current="page"
 * on the active link. URLs use the category slug when available, falling back to
 * the numeric ID so old bookmarks never break. The wrappers intentionally avoid
 * the HTML nav tag so host-page navigation CSS does not leak into embeds.
 *
 * All return values are either an HTML string or a result array:
 *   ['html' => string, 'show_listings' => bool]
 * 'show_listings' is false only in browse-by-category index mode — the HTML
 * already contains the full index; the caller must NOT render listings below it.
 *
 * Pseudo-entries ("All Pending", "All Upcoming") are injected after the top "All"
 * link and before real categories when their count > 0 and their toggle is true.
 * They use sentinel ?cat= values (__pending__, __upcoming__) so they work with
 * both link-based and <select>-based navigation without a separate GET param.
 */
final class PublicCategoryPresenter
{
    // -----------------------------------------------------------------------
    // Owned listings (ID-based categories with optional slug URLs)
    // -----------------------------------------------------------------------

    /**
     * Renders the category navigator for the owned listings embed.
     *
     * @param array<int, array{id: int, name: string, slug: string, source: string, parent_id: int|null, parent_name: string|null, listing_count: int}> $categories
     * @return array{html: string, show_listings: bool}
     */
    public static function renderOwned(
        array  $categories,
        int    $currentId,
        string $formAction,
        bool   $hideDropdown,
        bool   $showList,
        bool   $browseMode,
        bool   $browseShowAll,
        int    $totalCount = 0,
        bool   $showCatSwitcher = true,
        string $view          = '',
        int    $pendingCount  = 0,
        bool   $showPending   = true,
        int    $upcomingCount = 0,
        bool   $showUpcoming  = true,
        int    $closedCount     = 0,
        bool   $showClosed      = true,
        int    $adoptedOutCount = 0,
        bool   $showAdoptedOut  = true,
    ): array {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $pseudoEntries = self::ownedPseudoEntries(
            $view, $pendingCount, $showPending, $upcomingCount, $showUpcoming,
            $closedCount, $showClosed, $adoptedOutCount, $showAdoptedOut,
        );

        // Browse-by-category: index view only when no category AND no pseudo-view selected
        if ($browseMode && $currentId === 0 && !$browseShowAll && $view === '') {
            $html = self::renderOwnedBrowseIndex($categories, $formAction, $e, $totalCount, $pseudoEntries);
            return ['html' => $html, 'show_listings' => false];
        }

        // Browse mode with a category, show-all, or pseudo-view — back link
        if ($browseMode && ($currentId !== 0 || $browseShowAll || $view !== '')) {
            $html  = '<div class="fl-category-nav">';
            $html .= '<a class="fl-category-back" href="' . $e($formAction) . '">&larr; Back</a>';
            $html .= '</div>';
            return ['html' => $html, 'show_listings' => true];
        }

        // Normal mode: no selector wanted
        if ($hideDropdown || (empty($categories) && empty($pseudoEntries))) {
            return ['html' => '', 'show_listings' => true];
        }

        // Per-category page: compact switcher (only when viewing a real category, not a pseudo-view)
        if ($currentId > 0) {
            $html = $showCatSwitcher
                ? self::renderOwnedDropdown($categories, $currentId, $formAction, $e, $view, $pseudoEntries)
                : '';
            return ['html' => $html, 'show_listings' => true];
        }

        // Overview (no category selected): list or dropdown
        $html = $showList
            ? self::renderOwnedList($categories, $currentId, $formAction, $e, $view, $pseudoEntries)
            : self::renderOwnedDropdown($categories, $currentId, $formAction, $e, $view, $pseudoEntries);

        return ['html' => $html, 'show_listings' => true];
    }

    /**
     * @return array<int, array{label: string, value: string, count: int, active: bool}>
     */
    private static function ownedPseudoEntries(
        string $view,
        int    $pendingCount,
        bool   $showPending,
        int    $upcomingCount,
        bool   $showUpcoming,
        int    $closedCount     = 0,
        bool   $showClosed      = true,
        int    $adoptedOutCount = 0,
        bool   $showAdoptedOut  = true,
    ): array {
        $entries = [];
        if ($showPending && $pendingCount > 0) {
            $entries[] = [
                'label'  => 'All Pending',
                'value'  => '__pending__',
                'count'  => $pendingCount,
                'active' => $view === 'pending',
            ];
        }
        if ($showUpcoming && $upcomingCount > 0) {
            $entries[] = [
                'label'  => 'All Upcoming',
                'value'  => '__upcoming__',
                'count'  => $upcomingCount,
                'active' => $view === 'upcoming',
            ];
        }
        if ($showClosed && $closedCount > 0) {
            $entries[] = [
                'label'  => 'Closed',
                'value'  => '__closed__',
                'count'  => $closedCount,
                'active' => $view === 'closed',
            ];
        }
        if ($showAdoptedOut && $adoptedOutCount > 0) {
            $entries[] = [
                'label'  => 'Adopted Out',
                'value'  => '__adopted_out__',
                'count'  => $adoptedOutCount,
                'active' => $view === 'adopted_out',
            ];
        }
        return $entries;
    }

    /**
     * @return array<int, array{label: string, value: string, count: int, active: bool}>
     */
    private static function joinedPseudoEntries(string $view, int $pendingCount, bool $showPending): array
    {
        if ($showPending && $pendingCount > 0) {
            return [[
                'label'  => 'All Pending',
                'value'  => '__pending__',
                'count'  => $pendingCount,
                'active' => $view === 'pending',
            ]];
        }
        return [];
    }

    private static function categoryUrl(array $cat, string $formAction): string
    {
        if (($cat['slug'] ?? '') !== '') {
            return $formAction . '?cat=' . rawurlencode($cat['slug']);
        }
        return $formAction . '?cat=' . $cat['id'];
    }

    /**
     * @param callable(string):string $e
     * @param array<int, array{label: string, value: string, count: int, active: bool}> $pseudoEntries
     */
    private static function renderOwnedBrowseIndex(
        array    $categories,
        string   $formAction,
        callable $e,
        int      $totalCount = 0,
        array    $pseudoEntries = [],
    ): string {
        if (empty($categories) && empty($pseudoEntries)) {
            return '<p class="fl-empty">No categories to display.</p>';
        }

        $countHtml = $totalCount > 0 ? ' <span class="fl-category-count">(' . $totalCount . ')</span>' : '';

        $html  = '<div class="fl-category-nav">';
        $html .= '<ul class="fl-category-list">';
        $html .= '<li><a href="' . $e($formAction . '?cat=all') . '">All Listings</a>' . $countHtml . '</li>';

        foreach ($pseudoEntries as $entry) {
            $active = $entry['active'] ? ' aria-current="page"' : '';
            $html  .= '<li>';
            $html  .= '<a' . $active . ' href="' . $e($formAction . '?cat=' . $entry['value']) . '">' . $e($entry['label']) . '</a>';
            $html  .= ' <span class="fl-category-count">(' . $entry['count'] . ')</span>';
            $html  .= '</li>';
        }

        foreach ($categories as $cat) {
            $count = (int) $cat['listing_count'];
            $url   = self::categoryUrl($cat, $formAction);
            $label = ($cat['parent_name'] !== null ? $e($cat['parent_name']) . ' &rsaquo; ' : '') . $e($cat['name']);
            $html .= '<li>';
            $html .= '<a href="' . $e($url) . '">' . $label . '</a>';
            $html .= ' <span class="fl-category-count">(' . $count . ')</span>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';
        return $html;
    }

    /**
     * @param callable(string):string $e
     * @param array<int, array{label: string, value: string, count: int, active: bool}> $pseudoEntries
     */
    private static function renderOwnedList(
        array    $categories,
        int      $currentId,
        string   $formAction,
        callable $e,
        string   $view = '',
        array    $pseudoEntries = [],
    ): string {
        // Separate top-level and children
        $topLevel = [];
        $children = [];
        foreach ($categories as $cat) {
            if ($cat['parent_id'] === null) {
                $topLevel[] = $cat;
            } else {
                $children[(int) $cat['parent_id']][] = $cat;
            }
        }

        $html  = '<div class="fl-category-nav">';
        $html .= '<ul class="fl-category-list">';
        $allActive = ($currentId === 0 && $view === '') ? ' aria-current="page"' : '';
        $html .= '<li><a' . $allActive . ' href="' . $e($formAction) . '">All</a></li>';

        foreach ($pseudoEntries as $entry) {
            $active = $entry['active'] ? ' aria-current="page"' : '';
            $html  .= '<li>';
            $html  .= '<a' . $active . ' href="' . $e($formAction . '?cat=' . $entry['value']) . '">' . $e($entry['label']) . '</a>';
            $html  .= ' <span class="fl-category-count">(' . $entry['count'] . ')</span>';
            $html  .= '</li>';
        }

        foreach ($topLevel as $cat) {
            $active = $currentId === (int) $cat['id'] ? ' aria-current="page"' : '';
            $url    = self::categoryUrl($cat, $formAction);
            $html  .= '<li>';
            $html  .= '<a' . $active . ' href="' . $e($url) . '">' . $e($cat['name']) . '</a>';

            $kids = $children[(int) $cat['id']] ?? [];
            if (!empty($kids)) {
                $html .= '<ul class="fl-category-list-children">';
                foreach ($kids as $child) {
                    $childActive = $currentId === (int) $child['id'] ? ' aria-current="page"' : '';
                    $childUrl    = self::categoryUrl($child, $formAction);
                    $html .= '<li><a' . $childActive . ' href="' . $e($childUrl) . '">' . $e($child['name']) . '</a></li>';
                }
                $html .= '</ul>';
            }

            $html .= '</li>';
        }

        // Orphan children (parent not in category list) rendered flat
        foreach ($children as $parentId => $kids) {
            if (!isset(array_column($topLevel, 'id', 'id')[$parentId])) {
                foreach ($kids as $child) {
                    $childActive = $currentId === (int) $child['id'] ? ' aria-current="page"' : '';
                    $childUrl    = self::categoryUrl($child, $formAction);
                    $html .= '<li><a' . $childActive . ' href="' . $e($childUrl) . '">' . $e($child['name']) . '</a></li>';
                }
            }
        }

        $html .= '</ul>';
        $html .= '</div>';
        return $html;
    }

    /**
     * @param callable(string):string $e
     * @param array<int, array{label: string, value: string, count: int, active: bool}> $pseudoEntries
     */
    private static function renderOwnedDropdown(
        array    $categories,
        int      $currentId,
        string   $formAction,
        callable $e,
        string   $view = '',
        array    $pseudoEntries = [],
    ): string {
        // Separate top-level and children for optgroup rendering
        $topLevel = [];
        $children = [];
        foreach ($categories as $cat) {
            if ($cat['parent_id'] === null) {
                $topLevel[] = $cat;
            } else {
                $children[(int) $cat['parent_id']][] = $cat;
            }
        }

        $html  = '<div class="fl-category-nav">';
        $html .= '<form method="GET" action="' . $e($formAction) . '" class="fl-category-dropdown">';
        $html .= '<label class="fl-label" for="fl-owned-cat">Category:</label> ';
        $html .= '<select class="fl-select" id="fl-owned-cat" name="cat">';
        $html .= '<option value="0"' . ($currentId === 0 && $view === '' ? ' selected' : '') . '>All</option>';

        foreach ($pseudoEntries as $entry) {
            $html .= '<option value="' . $e($entry['value']) . '"' . ($entry['active'] ? ' selected' : '') . '>';
            $html .= $e($entry['label']) . ' (' . $entry['count'] . ')';
            $html .= '</option>';
        }

        $optVal = static function (array $c): string {
            return ($c['slug'] ?? '') !== '' ? $c['slug'] : (string) $c['id'];
        };
        $isSel = static function (array $c, int $currentId): string {
            return $currentId === (int) $c['id'] ? ' selected' : '';
        };

        foreach ($topLevel as $cat) {
            $kids = $children[(int) $cat['id']] ?? [];
            if (!empty($kids)) {
                $html .= '<optgroup label="' . $e($cat['name']) . '">';
                $html .= '<option value="' . $e($optVal($cat)) . '"' . $isSel($cat, $currentId) . '>' . $e($cat['name']) . ' (all)</option>';
                foreach ($kids as $child) {
                    $html .= '<option value="' . $e($optVal($child)) . '"' . $isSel($child, $currentId) . '>' . $e($child['name']) . '</option>';
                }
                $html .= '</optgroup>';
            } else {
                $html .= '<option value="' . $e($optVal($cat)) . '"' . $isSel($cat, $currentId) . '>' . $e($cat['name']) . '</option>';
            }
        }

        // Orphan children (parent not in the category list returned for this status)
        foreach ($children as $parentId => $kids) {
            if (!isset(array_column($topLevel, 'id', 'id')[$parentId])) {
                foreach ($kids as $child) {
                    $html .= '<option value="' . $e($optVal($child)) . '"' . $isSel($child, $currentId) . '>' . $e($child['name']) . '</option>';
                }
            }
        }

        $html .= '</select>';
        $html .= '<noscript><button type="submit" class="fl-btn">Go</button></noscript>';
        $html .= '</form>';
        $html .= '</div>';
        return $html;
    }

    // -----------------------------------------------------------------------
    // Joined listings (string-based category names)
    // -----------------------------------------------------------------------

    /**
     * Renders the category navigator for the joined listings embed.
     *
     * @param string[] $categories        Available category names
     * @param array<string, int> $counts  Category name → listing count (active + pending)
     * @return array{html: string, show_listings: bool}
     */
    public static function renderJoined(
        array  $categories,
        array  $counts,
        int    $totalCount,
        string $currentCategory,
        string $formAction,
        bool   $showList,
        bool   $browseMode,
        bool   $showCatSwitcher = true,
        string $view         = '',
        int    $pendingCount = 0,
        bool   $showPending  = true,
    ): array {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $pseudoEntries = self::joinedPseudoEntries($view, $pendingCount, $showPending);

        // Browse-by-category: index view when no category AND no pseudo-view selected
        if ($browseMode && $currentCategory === '' && $view === '') {
            $html = self::renderJoinedBrowseIndex($categories, $counts, $totalCount, $formAction, $e, $pseudoEntries);
            return ['html' => $html, 'show_listings' => false];
        }

        // Browse mode with a category or pseudo-view selected — back link
        if ($browseMode && ($currentCategory !== '' || $view !== '')) {
            $html  = '<div class="fl-category-nav">';
            $html .= '<a class="fl-category-back" href="' . $e($formAction) . '">&larr; Back</a>';
            $html .= '</div>';
            return ['html' => $html, 'show_listings' => true];
        }

        // No selector needed
        if (empty($categories) && empty($pseudoEntries)) {
            return ['html' => '', 'show_listings' => true];
        }

        // Per-category page: compact switcher (only when not in a pseudo-view)
        if ($currentCategory !== '' && $view === '') {
            $html = $showCatSwitcher
                ? self::renderJoinedDropdown($categories, $currentCategory, $formAction, $e, $totalCount, $pseudoEntries, $view)
                : '';
            return ['html' => $html, 'show_listings' => true];
        }

        // Overview (no category selected) or pseudo-view active
        $html = $showList
            ? self::renderJoinedList($categories, $currentCategory, $formAction, $e, $totalCount, $pseudoEntries, $view)
            : self::renderJoinedDropdown($categories, $currentCategory, $formAction, $e, $totalCount, $pseudoEntries, $view);

        return ['html' => $html, 'show_listings' => true];
    }

    /**
     * @param callable(string):string $e
     * @param array<int, array{label: string, value: string, count: int, active: bool}> $pseudoEntries
     */
    private static function renderJoinedBrowseIndex(
        array    $categories,
        array    $counts,
        int      $totalCount,
        string   $formAction,
        callable $e,
        array    $pseudoEntries = [],
    ): string {
        if (empty($categories) && empty($pseudoEntries)) {
            return '<p class="fl-empty">No categories to display.</p>';
        }

        $html  = '<div class="fl-category-nav">';
        $html .= '<ul class="fl-category-list">';
        $html .= '<li><a href="' . $e($formAction . '?cat=all') . '">All Listings</a>';
        $html .= ' <span class="fl-category-count">(' . $totalCount . ')</span></li>';

        foreach ($pseudoEntries as $entry) {
            $active = $entry['active'] ? ' aria-current="page"' : '';
            $html  .= '<li>';
            $html  .= '<a' . $active . ' href="' . $e($formAction . '?cat=' . $entry['value']) . '">' . $e($entry['label']) . '</a>';
            $html  .= ' <span class="fl-category-count">(' . $entry['count'] . ')</span>';
            $html  .= '</li>';
        }

        foreach ($categories as $catName) {
            $count = (int) ($counts[$catName] ?? 0);
            $html .= '<li>';
            $html .= '<a href="' . $e($formAction . '?cat=' . urlencode($catName)) . '">' . $e($catName) . '</a>';
            $html .= ' <span class="fl-category-count">(' . $count . ')</span>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';
        return $html;
    }

    /**
     * @param callable(string):string $e
     * @param array<int, array{label: string, value: string, count: int, active: bool}> $pseudoEntries
     */
    private static function renderJoinedList(
        array    $categories,
        string   $currentCategory,
        string   $formAction,
        callable $e,
        int      $totalCount = 0,
        array    $pseudoEntries = [],
        string   $view = '',
    ): string {
        $html  = '<div class="fl-category-nav">';
        $html .= '<ul class="fl-category-list">';

        // "All Listings (N)" — first entry, links to ?cat=all
        $allActive = ($currentCategory === 'all' && $view === '') ? ' aria-current="page"' : '';
        $html .= '<li><a' . $allActive . ' href="' . $e($formAction . '?cat=all') . '">All Listings</a>';
        $html .= ' <span class="fl-category-count">(' . $totalCount . ')</span></li>';

        foreach ($pseudoEntries as $entry) {
            $active = $entry['active'] ? ' aria-current="page"' : '';
            $html  .= '<li>';
            $html  .= '<a' . $active . ' href="' . $e($formAction . '?cat=' . $entry['value']) . '">' . $e($entry['label']) . '</a>';
            $html  .= ' <span class="fl-category-count">(' . $entry['count'] . ')</span>';
            $html  .= '</li>';
        }

        foreach ($categories as $catName) {
            $active = $currentCategory === $catName ? ' aria-current="page"' : '';
            $html  .= '<li>';
            $html  .= '<a' . $active . ' href="' . $e($formAction . '?cat=' . urlencode($catName)) . '">' . $e($catName) . '</a>';
            $html  .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';
        return $html;
    }

    /**
     * @param callable(string):string $e
     * @param array<int, array{label: string, value: string, count: int, active: bool}> $pseudoEntries
     */
    private static function renderJoinedDropdown(
        array    $categories,
        string   $currentCategory,
        string   $formAction,
        callable $e,
        int      $totalCount = 0,
        array    $pseudoEntries = [],
        string   $view = '',
    ): string {
        $html  = '<div class="fl-category-nav">';
        $html .= '<form method="GET" action="' . $e($formAction) . '" class="fl-category-dropdown">';
        $html .= '<label class="fl-label" for="fl-joined-cat">Category:</label> ';
        $html .= '<select class="fl-select" id="fl-joined-cat" name="cat">';

        // When no category is chosen yet, add a disabled placeholder so "All Listings"
        // can be selected via the change event even on first visit (already-selected
        // options never fire change, so without this the visitor would be stuck).
        if ($currentCategory === '' && $view === '') {
            $html .= '<option value="" disabled selected>&mdash; Choose a category &mdash;</option>';
        }
        // "All Listings (N)" — first real option, value="all"
        $allSel = $currentCategory === 'all' && $view === '' ? ' selected' : '';
        $html  .= '<option value="all"' . $allSel . '>All Listings (' . $totalCount . ')</option>';

        foreach ($pseudoEntries as $entry) {
            $html .= '<option value="' . $e($entry['value']) . '"' . ($entry['active'] ? ' selected' : '') . '>';
            $html .= $e($entry['label']) . ' (' . $entry['count'] . ')';
            $html .= '</option>';
        }

        foreach ($categories as $catName) {
            $sel   = $currentCategory === $catName ? ' selected' : '';
            $html .= '<option value="' . $e($catName) . '"' . $sel . '>' . $e($catName) . '</option>';
        }

        $html .= '</select>';
        $html .= '<noscript><button type="submit" class="fl-btn">Go</button></noscript>';
        $html .= '</form>';
        $html .= '</div>';
        return $html;
    }
}
