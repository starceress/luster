<?php

declare(strict_types=1);

use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Luster embed — joined listings list.
 *
 * Drop this into any PHP page to embed the list of listings you have joined.
 * Supports category filtering and two selector display modes.
 *
 * Usage:
 *   $luster_return = false;                  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/joined.php';
 *
 * Optional variables:
 *   $luster_category    string  Controls what is displayed:
 *                               ''    — show the category selector; visitor picks a category
 *                                       (reads $_GET['cat'] automatically, no PHP needed)
 *                               'all' — show ALL active listings grouped by category
 *                                       heading, no selector rendered (best for small lists)
 *                               other — exact category name; show only that category's
 *                                       listings, selector suppressed
 *                               Default: '' (reads $_GET['cat'])
 *   $luster_show_list           bool    When the selector is visible, show category links as a
 *                                       bulleted list instead of a <select> dropdown.
 *                                       Has no effect when $luster_category is 'all' or a fixed name.
 *                                       Default: false
 *   $luster_browse_by_category  bool    Browse-by-category mode: when no category is selected,
 *                                       shows a list of all category names as clickable links.
 *                                       Clicking a link filters to that category and renders
 *                                       listings via the admin-defined HTML template.
 *                                       Default: false
 */

// ---------------------------------------------------------------------------
// 1. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2);

if (!is_file($_lusterRoot . '/vendor/autoload.php') || !is_file($_lusterRoot . '/config/app.php')) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: Luster is not installed correctly.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);

/** @var \Luster\Core\Application $_lusterApp */
$_lusterApp = require $_lusterRoot . '/bootstrap.php';

// ---------------------------------------------------------------------------
// 2. Resolve parameters
// ---------------------------------------------------------------------------

$_lusterShowList        = (bool) ($luster_show_list           ?? false);
$_lusterShowAllDefault  = (bool) ($luster_show_all_by_default  ?? false);
$_lusterBrowseByCat     = (bool) ($luster_browse_by_category   ?? false);

// Category: prefer explicit $luster_category; fall back to $_GET['cat']
$_lusterCategory = isset($luster_category) && (string) $luster_category !== ''
                 ? (string) $luster_category
                 : (string) ($_GET['cat'] ?? '');

// ---------------------------------------------------------------------------
// 3. Load listings and categories
// ---------------------------------------------------------------------------

$_lusterRepo = new JoinedListingRepo($_lusterApp->getDatabase());

$_lusterCategories       = $_lusterRepo->getCategories();
$_lusterCategoryCountMap = $_lusterRepo->getCategoryCountMap();
$_lusterTotalCount       = $_lusterRepo->countActive();

/**
 * Groups a flat result set of JoinedListings into [category => [...]] sorted by key.
 */
$_lusterGroupByCategory = static function (array $items): array {
    $grouped = [];
    foreach ($items as $fl) {
        if (!$fl->active) {
            continue;
        }
        $cat = $fl->category !== '' ? $fl->category : 'Uncategorized';
        $grouped[$cat][] = $fl;
    }
    ksort($grouped);
    return $grouped;
};

if ($_lusterCategory === 'all') {
    // Show-all mode: load every active listing and group by category (no selector shown)
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1);
    $_lusterGrouped  = $_lusterGroupByCategory($result['items']);
    $_lusterListings = [];
} elseif ($_lusterCategory !== '') {
    // Single-category mode: filter by the chosen category
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1, filterCat: $_lusterCategory);
    $_lusterListings = array_values(array_filter($result['items'], static fn($fl) => $fl->active));
    $_lusterGrouped  = [];
} elseif ($_lusterShowAllDefault) {
    // Selector mode with show-all-by-default: show grouped listings AND the selector
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1);
    $_lusterGrouped  = $_lusterGroupByCategory($result['items']);
    $_lusterListings = [];
} else {
    // Selector mode: no category chosen yet — show only the selector
    $_lusterListings = [];
    $_lusterGrouped  = [];
}

// ---------------------------------------------------------------------------
// 3b. Load joined listing row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSiteRepo       = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterJoinedTemplate = $_lusterSiteRepo->get('joined_template')
    ?? SiteSettingsRepo::collectiveDefaults()['joined_template'];
if (trim($_lusterJoinedTemplate) === '') {
    $_lusterJoinedTemplate = SiteSettingsRepo::collectiveDefaults()['joined_template'];
}

// Build per-listing HTML when a custom template is set.
// Applied to both flat ($listings) and grouped ($grouped) modes.
$_lusterListingHtml = null;
if ($_lusterJoinedTemplate !== null) {
    $e          = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/joined';
    $renderRow  = static function (object $fl) use ($_lusterJoinedTemplate, $e, $_lusterUploadsUrl): string {
        $imageTag = ($fl->image !== null && $fl->image !== '')
            ? '<img src="' . $e($_lusterUploadsUrl . '/' . $fl->image) . '" alt="' . $e($fl->name) . '" title="' . $e($fl->name) . '">'
            : '';
        return str_replace(
            ['{listing_name}', '{listing_url}', '{listing_category}', '{listing_image}', '{image}', '{date_joined}', '{listing_status}'],
            [$e($fl->name), $e($fl->url), $e($fl->category ?? ''), $imageTag, $imageTag, $e($fl->dateJoined ?? ''), $e($fl->status)],
            $_lusterJoinedTemplate,
        );
    };

    if (!empty($_lusterGrouped)) {
        $rows = '';
        foreach ($_lusterGrouped as $catName => $items) {
            foreach ($items as $fl) {
                $rows .= $renderRow($fl);
            }
        }
        $_lusterListingHtml = $rows;
    } elseif (!empty($_lusterListings)) {
        $_lusterListingHtml = implode('', array_map($renderRow, $_lusterListings));
    }
}

// ---------------------------------------------------------------------------
// 4. Build form action URL (current page so the selector posts back here)
// ---------------------------------------------------------------------------

$_lusterFormAction = htmlspecialchars(
    strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('joined.php', [
    'listings'           => $_lusterListings,
    'grouped'            => $_lusterGrouped,
    'listing_html'       => $_lusterListingHtml,
    'categories'         => $_lusterCategories,
    'category_counts'    => $_lusterCategoryCountMap,
    'total_count'        => $_lusterTotalCount,
    'current_category'   => $_lusterCategory,
    'show_list'          => $_lusterShowList,
    'browse_by_category' => $_lusterBrowseByCat,
    'uploads_url'        => $_lusterApp->getBaseUrl() . '/uploads/joined',
    'base_url'           => $_lusterApp->getBaseUrl(),
    'form_action'        => $_lusterFormAction,
    'theme_path'         => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
