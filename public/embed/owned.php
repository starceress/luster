<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable owned listings page. Renders the list of fanlistings you own,
 with support for status filtering (current/upcoming/pending/all), category
 filtering via dropdown or browse-by-category index, and an admin-editable
 row template with token substitution. Each row can include member counts,
 country counts, and network approval status, which are only queried when
 the template actually uses those tokens.
*/

declare(strict_types=1);

use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingRowRenderer;
use Luster\Support\MemberStatsService;
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

$_lusterValidStatuses = ['current', 'upcoming', 'pending', 'all'];
$_lusterStatus        = in_array($luster_status ?? 'current', $_lusterValidStatuses, true)
                      ? (string) ($luster_status ?? 'current')
                      : 'current';

$_lusterExcludePending      = (bool) ($luster_exclude_pending     ?? true);
$_lusterHideDropdown        = (bool) ($luster_hide_dropdown       ?? false);
$_lusterShowList            = false;
$_lusterShowCatSwitcher     = true;
$_lusterBrowseByCat         = (bool) ($luster_browse_by_category  ?? false);
$_lusterShowPendingPseudo   = (bool) ($luster_show_pending        ?? true);
$_lusterShowUpcomingPseudo  = (bool) ($luster_show_upcoming       ?? true);

// Category ID resolution order:
//   1. $luster_category_id (explicit integer) — bypasses URL params entirely
//   2. $_GET['cat'] sentinel values __pending__ / __upcoming__ — pseudo-views (current only)
//   3. $_GET['cat'] with 'slug:' prefix — dropdown-posted slug value
//   4. $_GET['cat'] with 'all' value — browse-show-all mode
//   5. $_GET['cat'] numeric — legacy ID-based link
$_lusterBrowseShowAll = false;
$_lusterPseudoView    = '';   // '' | 'pending' | 'upcoming'

if (isset($luster_category_id) && (int) $luster_category_id > 0) {
    $_lusterCatId = (int) $luster_category_id;
} else {
    $_lusterRawCat = (string) ($_GET['cat'] ?? '');
    if ($_lusterStatus === 'current'
        && ($_lusterRawCat === '__pending__' || $_lusterRawCat === '__upcoming__')) {
        $_lusterPseudoView = $_lusterRawCat === '__pending__' ? 'pending' : 'upcoming';
        $_lusterCatId      = 0;
    } elseif ($_lusterRawCat === 'all') {
        $_lusterCatId         = 0;
        $_lusterBrowseShowAll = true;
    } elseif (is_numeric($_lusterRawCat) && (int) $_lusterRawCat > 0) {
        $_lusterCatId = (int) $_lusterRawCat;
    } elseif ($_lusterRawCat !== '') {
        // Non-numeric, non-empty → treat as slug (e.g. ?cat=comics)
        $_lusterCatSlug = $_lusterRawCat;
        $_lusterCatId   = 0;
    } else {
        $_lusterCatId = 0;
    }
}

// ---------------------------------------------------------------------------
// 3. Load listings and categories
// ---------------------------------------------------------------------------

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());

// Pass the same status filter so the category nav (nav pool) only shows
// categories that actually have listings in the same pool as the content.
$_lusterCategories = $_lusterListingRepo->getCategoryList($_lusterStatus, $_lusterExcludePending);

// Slug resolution: find the ID for $_lusterCatSlug in the already-loaded list
if (isset($_lusterCatSlug) && $_lusterCatSlug !== '') {
    foreach ($_lusterCategories as $_cat) {
        if (($_cat['slug'] ?? '') === $_lusterCatSlug) {
            $_lusterCatId = $_cat['id'];
            break;
        }
    }
    unset($_lusterCatSlug);
}

// Pseudo-view counts (only meaningful for status='current')
$_lusterPendingCount  = 0;
$_lusterUpcomingCount = 0;
if ($_lusterStatus === 'current') {
    $_lusterStats         = $_lusterListingRepo->getStats();
    $_lusterPendingCount  = (int) ($_lusterStats['trouble']  ?? 0);
    $_lusterUpcomingCount = (int) ($_lusterStats['upcoming'] ?? 0);
}

// When a pseudo-view is active, fetch that status group's listings instead
$_lusterContentStatus = $_lusterPseudoView !== '' ? $_lusterPseudoView : $_lusterStatus;

$_lusterListings = $_lusterListingRepo->getFiltered(
    $_lusterContentStatus,
    // Suppress category filter when in a pseudo-view (all pending/upcoming regardless of category)
    $_lusterPseudoView === '' && $_lusterCatId > 0 ? $_lusterCatId : null,
    $_lusterExcludePending,
);

// ---------------------------------------------------------------------------
// 3b. Build form action URL (needed both for the presenter and renderFragment)
// ---------------------------------------------------------------------------

$_lusterFormAction = htmlspecialchars(
    strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

// ---------------------------------------------------------------------------
// 3c. Build the category navigator HTML via PublicCategoryPresenter
// ---------------------------------------------------------------------------

$_lusterPresenterResult = PublicCategoryPresenter::renderOwned(
    $_lusterCategories,
    $_lusterCatId,
    $_lusterFormAction,
    $_lusterHideDropdown,
    $_lusterShowList,
    $_lusterBrowseByCat,
    $_lusterBrowseShowAll,
    count($_lusterListings),
    $_lusterShowCatSwitcher,
    $_lusterPseudoView,
    $_lusterPendingCount,
    $_lusterShowPendingPseudo,
    $_lusterUpcomingCount,
    $_lusterShowUpcomingPseudo,
);

$_lusterCategoryHtml  = $_lusterPresenterResult['html'];
$_lusterShowListings  = $_lusterPresenterResult['show_listings'];

// ---------------------------------------------------------------------------
// 3d. Load owned listing row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSiteRepo      = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterOwnedTemplate = $_lusterSiteRepo->get('owned_template')
    ?? SiteSettingsRepo::collectiveDefaults()['owned_template'];
if (trim($_lusterOwnedTemplate) === '') {
    $_lusterOwnedTemplate = SiteSettingsRepo::collectiveDefaults()['owned_template'];
}

$_lusterOwnedTemplate = str_replace('{site_name}', htmlspecialchars((string) $_lusterApp->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $_lusterOwnedTemplate);

$_lusterListingHtml = null;
if ($_lusterOwnedTemplate !== null && $_lusterShowListings) {
    $_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/listings';

    // Batch-fetch settings (image, last_updated) for all listings
    $_lusterListingIds = array_map(static fn($fl) => $fl->id, $_lusterListings);

    $_lusterSettingsMap       = [];
    $_lusterListingCategoryMap = [];

    if (!empty($_lusterListingIds)) {
        $_db = $_lusterApp->getDatabase();
        $_lusterSettingsMap = (new ListingSettingsRepo($_db))->getRenderSettingsBatch($_lusterListingIds);

        // Batch-fetch category names via junction table
        try {
            $_db  = $_db ?? $_lusterApp->getDatabase();
            $_phs = implode(',', array_fill(0, count($_lusterListingIds), '?'));
            $_st  = $_db->prepare(
                "SELECT lc.fanlisting_id, c.name
                 FROM lstr_listing_categories lc
                 JOIN lstr_categories c ON c.id = lc.category_id
                 WHERE lc.fanlisting_id IN ({$_phs})
                 ORDER BY lc.fanlisting_id, c.name"
            );
            $_st->execute(array_values($_lusterListingIds));
            $_lcRaw = [];
            foreach ($_st->fetchAll() as $_row) {
                $_lcRaw[(int) $_row['fanlisting_id']][] = (string) $_row['name'];
            }
            foreach ($_lcRaw as $_lcId => $_lcNames) {
                $_lusterListingCategoryMap[$_lcId] = implode(', ', $_lcNames);
            }
        } catch (\Exception) {
            // Junction table absent (pre-migration 51): fall back to primary category_id
            foreach ($_lusterListings as $_fl) {
                if (($_fl->categoryId ?? null) !== null) {
                    $_lusterListingCategoryMap[$_fl->id] = '';
                }
            }
            $_fallbackCatIds = array_filter(array_unique(array_map(static fn($fl) => $fl->categoryId, $_lusterListings)));
            if (!empty($_fallbackCatIds)) {
                try {
                    $_phs = implode(',', array_fill(0, count($_fallbackCatIds), '?'));
                    $_st  = $_db->prepare("SELECT id, name FROM lstr_categories WHERE id IN ({$_phs})");
                    $_st->execute(array_values($_fallbackCatIds));
                    $_fallbackMap = [];
                    foreach ($_st->fetchAll() as $_row) {
                        $_fallbackMap[(int) $_row['id']] = (string) $_row['name'];
                    }
                    foreach ($_lusterListings as $_fl) {
                        if (($_fl->categoryId ?? null) !== null && isset($_fallbackMap[$_fl->categoryId])) {
                            $_lusterListingCategoryMap[$_fl->id] = $_fallbackMap[$_fl->categoryId];
                        }
                    }
                } catch (\Exception) {}
            }
        }
    }

    // Batch-fetch member stats via MemberStatsService (chunked UNION ALL)
    $_lusterMemberStatsMap = [];
    $_lusterNeedsMemberStats = str_contains($_lusterOwnedTemplate, '{member_count}')
                            || str_contains($_lusterOwnedTemplate, '{member_pending_count}')
                            || str_contains($_lusterOwnedTemplate, '{pending_owned_count}')
                            || str_contains($_lusterOwnedTemplate, '{country_count}')
                            || str_contains($_lusterOwnedTemplate, '{if_countries}')
                            || str_contains($_lusterOwnedTemplate, '{if_pending}');
    if ($_lusterNeedsMemberStats && !empty($_lusterListings)) {
        $_lusterMemberService  = new MemberStatsService($_lusterApp->getDatabase(), $_lusterApp->getConfig());
        $_lusterMemberStatsMap = $_lusterMemberService->forListings($_lusterListings);
    }

    // Renders the combined owned_template — auto-detects flat ({each_listing})
    // vs sectioned ({each_current}/{each_upcoming}/{each_pending}) mode from
    // the template string itself.
    $_lusterListingHtml = ListingRowRenderer::renderTemplate(
        $_lusterOwnedTemplate,
        $_lusterListings,
        $_lusterUploadsUrl,
        $_lusterSettingsMap,
        $_lusterMemberStatsMap,
        $_lusterListingCategoryMap,
        (string) ($luster_date_format ?? ''),
    );
}

// ---------------------------------------------------------------------------
// 4. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('owned.php', [
    'listings'            => $_lusterListings,
    'listing_html'        => $_lusterListingHtml,
    'category_html'       => $_lusterCategoryHtml,
    'show_listings'       => $_lusterShowListings,
    'categories'          => $_lusterCategories,
    'current_category'    => $_lusterCatId,
    'browse_by_category'  => $_lusterBrowseByCat,
    'browse_show_all'     => $_lusterBrowseShowAll,
    'total_count'         => count($_lusterListings),
    'status'              => $_lusterStatus,
    'view'                => $_lusterPseudoView,
    'uploads_url'         => $_lusterApp->getBaseUrl() . '/uploads',
    'base_url'            => $_lusterApp->getBaseUrl(),
    'form_action'         => $_lusterFormAction,
    'theme_path'          => $_lusterApp->getBaseUrl() . '/assets/default/',
]);


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
