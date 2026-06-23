<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable joined listings page. Renders the list of fanlistings you have
 joined as a member, with category filtering via dropdown or browse-by-
 category index. Supports showing all listings grouped by category, filtering
 to a single category, and an admin-editable row template with token
 substitution for each joined listing entry.
*/

declare(strict_types=1);

use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\JoinedListingRowRenderer;
use Luster\Support\PublicCategoryPresenter;

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
$luster_attribution = \Luster\Core\View::attribution();

// ---------------------------------------------------------------------------
// 2. Resolve parameters
// ---------------------------------------------------------------------------

$_lusterShowList          = false;
$_lusterShowCatSwitcher   = true;
$_lusterShowAllDefault    = (bool) ($luster_show_all_by_default   ?? false);
$_lusterBrowseByCat       = (bool) ($luster_browse_by_category    ?? false);
$_lusterShowPendingPseudo = (bool) ($luster_show_pending          ?? true);

// Category: prefer explicit $luster_category; fall back to $_GET['cat']
// Sentinel value __pending__ activates the pending pseudo-view.
$_lusterPseudoView   = '';
$_lusterCategoryRaw  = isset($luster_category) && (string) $luster_category !== ''
                     ? (string) $luster_category
                     : (string) ($_GET['cat'] ?? '');

if ($_lusterCategoryRaw === '__pending__') {
    $_lusterPseudoView = 'pending';
    $_lusterCategory   = '';
} else {
    $_lusterCategory = $_lusterCategoryRaw;
}

// ---------------------------------------------------------------------------
// 3. Load listings and categories
// ---------------------------------------------------------------------------

$_lusterRepo = new JoinedListingRepo($_lusterApp->getDatabase());

$_lusterCategories       = $_lusterRepo->getCategories(true);     // activeOnly: hides categories that only contain hidden rows
$_lusterCategoryCountMap = $_lusterRepo->getCategoryCountMap();   // activeOnly=true: excludes hidden (active=0)
$_lusterStatusTabs       = $_lusterRepo->countAllByStatusTab();
$_lusterPendingCount     = (int) ($_lusterStatusTabs['visible_pending'] ?? 0); // non-hidden pending only
// Total for the nav = non-hidden rows only (all rows minus active=0)
$_lusterTotalCount       = (int) ($_lusterStatusTabs['all'] ?? 0) - (int) ($_lusterStatusTabs['hidden'] ?? 0);

/**
 * Groups a flat result set of JoinedListings into [category => [...]] sorted by key.
 * Includes both active and pending listings (active-only filter removed per spec).
 */
$_lusterGroupByCategory = static function (array $items): array {
    $grouped = [];
    foreach ($items as $fl) {
        $cat = $fl->category !== '' ? $fl->category : 'Uncategorized';
        $grouped[$cat][] = $fl;
    }
    ksort($grouped);
    return $grouped;
};

if ($_lusterPseudoView === 'pending') {
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1, statusTab: 'visible_pending');
    $_lusterListings = $result['items'];
    $_lusterGrouped  = [];
} elseif ($_lusterCategory === 'all') {
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1, statusTab: 'visible');
    $_lusterGrouped  = $_lusterGroupByCategory($result['items']);
    $_lusterListings = [];
} elseif ($_lusterCategory !== '') {
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1, filterCat: $_lusterCategory, statusTab: 'visible');
    $_lusterListings = $result['items'];
    $_lusterGrouped  = [];
} elseif ($_lusterShowAllDefault) {
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1, statusTab: 'visible');
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

// Always render the navigator — the presenter handles all cases (overview list/dropdown,
// per-category switcher, browse-by-category back link, or empty string when nothing applies)
$_ljPresenter = PublicCategoryPresenter::renderJoined(
    $_lusterCategories,
    $_lusterCategoryCountMap,
    $_lusterTotalCount,
    $_lusterCategory,
    $_lusterFormAction,
    $_lusterShowList,
    $_lusterBrowseByCat,
    $_lusterShowCatSwitcher,
    $_lusterPseudoView,
    $_lusterPendingCount,
    $_lusterShowPendingPseudo,
);
$_lusterCategoryHtml = $_ljPresenter['html'];
$_lusterShowListings = $_ljPresenter['show_listings'];

// ---------------------------------------------------------------------------
// 3c. Load joined listing row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSiteRepo       = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterJoinedTemplate = $_lusterSiteRepo->get('joined_template')
    ?? SiteSettingsRepo::collectiveDefaults()['joined_template'];
if (trim($_lusterJoinedTemplate) === '') {
    $_lusterJoinedTemplate = SiteSettingsRepo::collectiveDefaults()['joined_template'];
}

$_lusterJoinedTemplate = str_replace('{site_name}', htmlspecialchars((string) $_lusterApp->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $_lusterJoinedTemplate);

$_lusterListingHtml = null;
if ($_lusterJoinedTemplate !== null) {
    $_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/joined';

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

    // Sectioned ({each_current_joined}) templates always render, even with zero
    // items (the shell still shows {if_current_listings}-gated empty state).
    // Flat ({each_joined}) templates only render when there is at least one
    // item — otherwise $_lusterListingHtml stays null and templates/default/
    // joined.php falls through to its own "No listings to display" branches.
    if (str_contains($_lusterJoinedTemplate, '{each_current_joined}') || !empty($_lusterJoinedItems)) {
        $_lusterListingHtml = JoinedListingRowRenderer::renderTemplate($_lusterJoinedTemplate, $_lusterJoinedItems, $_lusterUploadsUrl, (string) ($luster_date_format ?? ''));
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
    'browse_by_category' => $_lusterBrowseByCat,
    'view'               => $_lusterPseudoView,
    'uploads_url'        => $_lusterApp->getBaseUrl() . '/uploads/joined',
    'base_url'           => $_lusterApp->getBaseUrl(),
    'form_action'        => $_lusterFormAction,
    'theme_path'         => $_lusterApp->getBaseUrl() . '/assets/default/',
]);


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
