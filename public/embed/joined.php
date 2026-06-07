<?php

declare(strict_types=1);

use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Models\JoinedListing;
use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\PublicCategoryPresenter;

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
 *                               'all' — show ALL active listings grouped by category
 *                               other — exact category name; show only that category's listings
 *                               Default: '' (reads $_GET['cat'])
 *   $luster_show_list           bool    Show category selector as bulleted list vs <select>.
 *                                       Default: false
 *   $luster_browse_by_category  bool    Browse-by-category mode: index view when no category selected.
 *                                       Default: false
 *   $luster_show_all_by_default bool    Show grouped listings AND the selector initially.
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
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1);
    $_lusterGrouped  = $_lusterGroupByCategory($result['items']);
    $_lusterListings = [];
} elseif ($_lusterCategory !== '') {
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1, filterCat: $_lusterCategory);
    $_lusterListings = array_values(array_filter($result['items'], static fn($fl) => $fl->active));
    $_lusterGrouped  = [];
} elseif ($_lusterShowAllDefault) {
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1);
    $_lusterGrouped  = $_lusterGroupByCategory($result['items']);
    $_lusterListings = [];
} else {
    $_lusterListings = [];
    $_lusterGrouped  = [];
}

// ---------------------------------------------------------------------------
// 3b. Build the category navigator HTML via PublicCategoryPresenter
// ---------------------------------------------------------------------------

$_lusterFormAction = htmlspecialchars(
    strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

// Render the navigator in selector mode, show-all-default mode, or browse-by-category mode
// (browse mode needs the presenter even when a category is selected — it generates the back link)
$_lusterShowCategoryNav = ($_lusterCategory === ''
                        || ($_lusterShowAllDefault && $_lusterCategory === '')
                        || ($_lusterBrowseByCat && $_lusterCategory !== ''));
$_lusterShowListings    = true;
$_lusterCategoryHtml    = '';

if ($_lusterShowCategoryNav) {
    $_ljPresenter = PublicCategoryPresenter::renderJoined(
        $_lusterCategories,
        $_lusterCategoryCountMap,
        $_lusterTotalCount,
        $_lusterCategory,
        $_lusterFormAction,
        $_lusterShowList,
        $_lusterBrowseByCat,
    );
    $_lusterCategoryHtml = $_ljPresenter['html'];
    $_lusterShowListings = $_ljPresenter['show_listings'];
}

// ---------------------------------------------------------------------------
// 3c. Load joined listing row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSiteRepo       = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterJoinedTemplate = $_lusterSiteRepo->get('joined_template')
    ?? SiteSettingsRepo::collectiveDefaults()['joined_template'];
if (trim($_lusterJoinedTemplate) === '') {
    $_lusterJoinedTemplate = SiteSettingsRepo::collectiveDefaults()['joined_template'];
}

$_lusterListingHtml = null;
if ($_lusterJoinedTemplate !== null) {
    $_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/joined';

    // Renders one joined-listing row via TemplateEngine — replaces the old inline
    // str_replace() closure. {listing_image_url} (escaped bare URL) + {listing_image_html}
    // (raw <img>) + {if_listing_image} replace {listing_image}/{image} outright; there
    // is no legacy alias (see docs/PUBLIC-TEMPLATES-PLAN.md "No {listing_image} legacy alias").
    $_lusterRenderRow = static function (string $inner, JoinedListing $fl) use ($_lusterUploadsUrl): string {
        $imageUrl  = ($fl->image !== null && $fl->image !== '') ? $_lusterUploadsUrl . '/' . $fl->image : '';
        $imageHtml = $imageUrl !== ''
            ? '<img src="' . View::e($imageUrl) . '" alt="' . View::e($fl->name) . '" title="' . View::e($fl->name) . '">'
            : '';

        return TemplateEngine::render(
            $inner,
            escaped: [
                'listing_title'     => $fl->name,
                'listing_url'       => $fl->url,
                'listing_category'  => $fl->category ?? '',
                'listing_image_url' => $imageUrl,
                'date_joined'       => $fl->dateJoined ?? '',
                'listing_status'    => $fl->status,
            ],
            raw: [
                'listing_image_html' => $imageHtml,
            ],
            conditions: [
                'listing_image' => $imageUrl !== '',
            ],
        );
    };

    // Flatten $_lusterGrouped (already category-sorted via ksort in $_lusterGroupByCategory)
    // into a single ordered list — the data ordering IS the "grouped by category" contract
    // for {each_current_joined}; the row template itself still prints {listing_category}
    // per item (no template-level group-header support in {each_*} loops).
    $_lusterFlatGrouped = [];
    foreach ($_lusterGrouped as $_items) {
        foreach ($_items as $_fl) {
            $_lusterFlatGrouped[] = $_fl;
        }
    }

    // The item set to feed the loop is whichever one the current view is actually
    // showing: single-category/selector mode populates $_lusterListings while
    // $_lusterGrouped (and thus $_lusterFlatGrouped) stays empty; show-all /
    // show-all-by-default modes populate $_lusterGrouped while $_lusterListings
    // stays empty. Falling back to $_lusterFlatGrouped is what makes
    // {each_current_joined} render correctly for a single selected category too —
    // not just for cat=all — since {each_current_joined} has no separate "flat"
    // counterpart of its own to fall back to.
    $_lusterJoinedItems = !empty($_lusterListings) ? $_lusterListings : $_lusterFlatGrouped;

    if (str_contains($_lusterJoinedTemplate, '{each_current_joined}')) {
        // Outer section-count/conditional tokens are applied to the template SHELL
        // first — before any row HTML is substituted in. TemplateEngine::tokens()
        // performs one simultaneous strtr() scan per call; running it again over a
        // string that already contains rendered row HTML would re-scan that HTML's
        // literal escaped text too (e.g. a listing title of literally
        // "{current_count}" would be overwritten on the second pass). Resolving the
        // shell first means the loop body is the last thing substituted in, so its
        // literal content can never be re-matched — preserving the "escaped values
        // stay plain text" guarantee end-to-end.
        $_lusterListingHtml = TemplateEngine::render(
            $_lusterJoinedTemplate,
            escaped: [
                'current_count' => count($_lusterJoinedItems),
                'total_count'   => count($_lusterJoinedItems),
            ],
            conditions: [
                'current_listings' => $_lusterJoinedItems !== [],
            ],
        );
        $_lusterListingHtml = TemplateEngine::loop($_lusterListingHtml, 'current_joined', $_lusterJoinedItems, $_lusterRenderRow);
    } elseif (!empty($_lusterJoinedItems)) {
        // Flat {each_joined} mode.
        $_lusterListingHtml = TemplateEngine::loop($_lusterJoinedTemplate, 'joined', $_lusterJoinedItems, $_lusterRenderRow);
    }
}

// ---------------------------------------------------------------------------
// 4. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('joined.php', [
    'listings'           => $_lusterListings,
    'grouped'            => $_lusterGrouped,
    'listing_html'       => $_lusterListingHtml,
    'category_html'      => $_lusterCategoryHtml,
    'show_listings'      => $_lusterShowListings,
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
