<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\View;

/**
 * Renders the public category navigator for owned and joined listing embeds.
 *
 * Produces semantic HTML with fl-category-* CSS classes and aria-current="page"
 * on the active link. URLs use the category slug when available, falling back to
 * the numeric ID so old bookmarks never break.
 *
 * All return values are either an HTML string or a result array:
 *   ['html' => string, 'show_listings' => bool]
 * 'show_listings' is false only in browse-by-category index mode — the HTML
 * already contains the full index; the caller must NOT render listings below it.
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
    ): array {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Browse-by-category: index view when no category is selected
        if ($browseMode && $currentId === 0 && !$browseShowAll) {
            $html = self::renderOwnedBrowseIndex($categories, $formAction, $e);
            return ['html' => $html, 'show_listings' => false];
        }

        // Browse mode with a category selected — render back link, then fall through to show listings
        if ($browseMode && ($currentId !== 0 || $browseShowAll)) {
            $html  = '<nav class="fl-category-nav">';
            $html .= '<a class="fl-category-back" href="' . $e($formAction) . '">&larr; All categories</a>';
            $html .= '</nav>';
            return ['html' => $html, 'show_listings' => true];
        }

        // Normal mode: no selector wanted
        if ($hideDropdown || empty($categories)) {
            return ['html' => '', 'show_listings' => true];
        }

        // Normal mode: list or dropdown
        $html = $showList
            ? self::renderOwnedList($categories, $currentId, $formAction, $e)
            : self::renderOwnedDropdown($categories, $currentId, $formAction, $e);

        return ['html' => $html, 'show_listings' => true];
    }

    private static function categoryUrl(array $cat, string $formAction): string
    {
        $key = ($cat['slug'] ?? '') !== '' ? $cat['slug'] : (string) $cat['id'];
        return $formAction . '?cat=' . $key;
    }

    /**
     * @param callable(string):string $e
     */
    private static function renderOwnedBrowseIndex(array $categories, string $formAction, callable $e): string
    {
        if (empty($categories)) {
            return '<p>No categories to display.</p>';
        }

        $html  = '<nav class="fl-category-nav">';
        $html .= '<ul class="fl-category-list">';
        $html .= '<li><a href="' . $e($formAction . '?cat=all') . '">';
        $html .= 'All Categories</a></li>';

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
        $html .= '</nav>';
        return $html;
    }

    /**
     * @param callable(string):string $e
     */
    private static function renderOwnedList(array $categories, int $currentId, string $formAction, callable $e): string
    {
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

        $html  = '<nav class="fl-category-nav">';
        $html .= '<ul class="fl-category-list">';
        $allActive = $currentId === 0 ? ' aria-current="page"' : '';
        $html .= '<li><a' . $allActive . ' href="' . $e($formAction) . '">All</a></li>';

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
        $html .= '</nav>';
        return $html;
    }

    /**
     * @param callable(string):string $e
     */
    private static function renderOwnedDropdown(array $categories, int $currentId, string $formAction, callable $e): string
    {
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

        $html  = '<nav class="fl-category-nav">';
        $html .= '<form method="GET" action="' . $e($formAction) . '" class="fl-category-dropdown">';
        $html .= '<label for="fl-owned-cat">Category:</label> ';
        $html .= '<select id="fl-owned-cat" name="cat" onchange="this.form.submit()">';
        $html .= '<option value="0"' . ($currentId === 0 ? ' selected' : '') . '>All</option>';

        foreach ($topLevel as $cat) {
            $kids = $children[(int) $cat['id']] ?? [];
            if (!empty($kids)) {
                $html .= '<optgroup label="' . $e($cat['name']) . '">';
                // Make the parent itself selectable as the first option inside the group
                $selParent = $currentId === (int) $cat['id'] ? ' selected' : '';
                $html .= '<option value="' . $e(self::categoryUrl($cat, '') === '' ? (string) $cat['id'] : (($cat['slug'] ?? '') ?: (string) $cat['id'])) . '"' . $selParent . '>' . $e($cat['name']) . ' (all)</option>';
                foreach ($kids as $child) {
                    $sel   = $currentId === (int) $child['id'] ? ' selected' : '';
                    $val   = ($child['slug'] ?? '') !== '' ? $child['slug'] : (string) $child['id'];
                    $html .= '<option value="' . $e($val) . '"' . $sel . '>' . $e($child['name']) . '</option>';
                }
                $html .= '</optgroup>';
            } else {
                $sel  = $currentId === (int) $cat['id'] ? ' selected' : '';
                $val  = ($cat['slug'] ?? '') !== '' ? $cat['slug'] : (string) $cat['id'];
                $html .= '<option value="' . $e($val) . '"' . $sel . '>' . $e($cat['name']) . '</option>';
            }
        }

        // Orphan children
        foreach ($children as $parentId => $kids) {
            if (!isset(array_column($topLevel, 'id', 'id')[$parentId])) {
                foreach ($kids as $child) {
                    $sel  = $currentId === (int) $child['id'] ? ' selected' : '';
                    $val  = ($child['slug'] ?? '') !== '' ? $child['slug'] : (string) $child['id'];
                    $html .= '<option value="' . $e($val) . '"' . $sel . '>' . $e($child['name']) . '</option>';
                }
            }
        }

        $html .= '</select>';
        $html .= '<noscript><button type="submit">Go</button></noscript>';
        $html .= '</form>';
        $html .= '</nav>';
        return $html;
    }

    // -----------------------------------------------------------------------
    // Joined listings (string-based category names)
    // -----------------------------------------------------------------------

    /**
     * Renders the category navigator for the joined listings embed.
     *
     * @param string[] $categories        Available category names
     * @param array<string, int> $counts  Category name → active listing count
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
    ): array {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Browse-by-category: index view when no category is selected
        if ($browseMode && $currentCategory === '') {
            $html = self::renderJoinedBrowseIndex($categories, $counts, $totalCount, $formAction, $e);
            return ['html' => $html, 'show_listings' => false];
        }

        // Browse mode with a category selected — render back link
        if ($browseMode && $currentCategory !== '') {
            $html  = '<nav class="fl-category-nav">';
            $html .= '<a class="fl-category-back" href="' . $e($formAction) . '">&larr; All categories</a>';
            $html .= '</nav>';
            return ['html' => $html, 'show_listings' => true];
        }

        // No selector needed
        if (empty($categories)) {
            return ['html' => '', 'show_listings' => true];
        }

        $html = $showList
            ? self::renderJoinedList($categories, $currentCategory, $formAction, $e)
            : self::renderJoinedDropdown($categories, $currentCategory, $formAction, $e);

        return ['html' => $html, 'show_listings' => true];
    }

    /**
     * @param callable(string):string $e
     */
    private static function renderJoinedBrowseIndex(array $categories, array $counts, int $totalCount, string $formAction, callable $e): string
    {
        if (empty($categories)) {
            return '<p>No categories to display.</p>';
        }

        $html  = '<nav class="fl-category-nav">';
        $html .= '<ul class="fl-category-list">';
        $html .= '<li><a href="' . $e($formAction . '?cat=all') . '">All Categories</a>';
        $html .= ' <span class="fl-category-count">(' . $totalCount . ')</span></li>';

        foreach ($categories as $catName) {
            $count = (int) ($counts[$catName] ?? 0);
            $html .= '<li>';
            $html .= '<a href="' . $e($formAction . '?cat=' . urlencode($catName)) . '">' . $e($catName) . '</a>';
            $html .= ' <span class="fl-category-count">(' . $count . ')</span>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</nav>';
        return $html;
    }

    /**
     * @param callable(string):string $e
     */
    private static function renderJoinedList(array $categories, string $currentCategory, string $formAction, callable $e): string
    {
        $html  = '<nav class="fl-category-nav">';
        $html .= '<ul class="fl-category-list">';

        foreach ($categories as $catName) {
            $active = $currentCategory === $catName ? ' aria-current="page"' : '';
            $html  .= '<li>';
            $html  .= '<a' . $active . ' href="' . $e($formAction . '?cat=' . urlencode($catName)) . '">' . $e($catName) . '</a>';
            $html  .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</nav>';
        return $html;
    }

    /**
     * @param callable(string):string $e
     */
    private static function renderJoinedDropdown(array $categories, string $currentCategory, string $formAction, callable $e): string
    {
        $html  = '<nav class="fl-category-nav">';
        $html .= '<form method="GET" action="' . $e($formAction) . '" class="fl-category-dropdown">';
        $html .= '<label for="fl-joined-cat">Category:</label> ';
        $html .= '<select id="fl-joined-cat" name="cat" onchange="this.form.submit()">';
        $html .= '<option value="">All categories&hellip;</option>';

        foreach ($categories as $catName) {
            $sel   = $currentCategory === $catName ? ' selected' : '';
            $html .= '<option value="' . $e($catName) . '"' . $sel . '>' . $e($catName) . '</option>';
        }

        $html .= '</select>';
        $html .= '<noscript><button type="submit">Go</button></noscript>';
        $html .= '</form>';
        $html .= '</nav>';
        return $html;
    }
}
