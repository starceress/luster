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

// ---------------------------------------------------------------------------
// 2. Resolve parameters
// ---------------------------------------------------------------------------

$_lusterValidStatuses = ['current', 'upcoming', 'pending', 'closed', 'adopted_out', 'past', 'all'];
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
$_lusterShowClosedPseudo    = (bool) ($luster_show_closed         ?? false);
$_lusterShowAdoptedOutPseudo = (bool) ($luster_show_adopted_out   ?? false);
$_lusterShowPastPseudo      = (bool) ($luster_show_past           ?? true);

// Category ID resolution order:
//   1. $_GET['cat'], once present at all, always wins — the category/browse
//      links this file renders (via PublicCategoryPresenter) must keep
//      working after a click, not be permanently overridden back to
//      $luster_category_id.
//   2. $luster_category_id (explicit integer) — only used as the starting
//      category on a fresh visit, before any ?cat= link has been clicked.
//   3. $_GET['cat'] sentinel values __pending__ / __upcoming__ — pseudo-views (current only)
//   4. $_GET['cat'] with 'slug:' prefix — dropdown-posted slug value
//   5. $_GET['cat'] with 'all' value — browse-show-all mode
//   6. $_GET['cat'] numeric — legacy ID-based link
$_lusterBrowseShowAll = false;
$_lusterPseudoView    = '';   // '' | 'pending' | 'upcoming' | 'closed' | 'adopted_out'

$_lusterPseudoSentinels = [
    '__pending__'     => 'pending',
    '__upcoming__'    => 'upcoming',
    '__closed__'      => 'closed',
    '__adopted_out__' => 'adopted_out',
    '__past__'        => 'past',
];

if (!isset($_GET['cat']) && isset($luster_category_id) && (int) $luster_category_id > 0) {
    $_lusterCatId = (int) $luster_category_id;
} else {
    $_lusterRawCat = (string) ($_GET['cat'] ?? '');
    if ($_lusterStatus === 'current' && isset($_lusterPseudoSentinels[$_lusterRawCat])) {
        $_lusterPseudoView = $_lusterPseudoSentinels[$_lusterRawCat];
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

// Grand total across all statuses — used for the "All Listings" dropdown option
$_lusterStats    = $_lusterListingRepo->getStats();
$_lusterAllCount = (int) $_lusterStats['total'];

// Pseudo-view counts (only meaningful for status='current')
$_lusterPendingCount    = 0;
$_lusterUpcomingCount   = 0;
$_lusterClosedCount     = 0;
$_lusterAdoptedOutCount = 0;
$_lusterPastCount       = 0;
if ($_lusterStatus === 'current') {
    $_lusterLifecycleCounts = $_lusterListingRepo->getLifecycleCounts();
    $_lusterClosedCount     = (int) ($_lusterLifecycleCounts['closed']      ?? 0);
    $_lusterAdoptedOutCount = (int) ($_lusterLifecycleCounts['adopted_out'] ?? 0);
    $_lusterPastCount       = $_lusterClosedCount + $_lusterAdoptedOutCount;
    // getStats()'s 'trouble' count already includes archived listings (legacy inactive
    // representation) — subtract them so "All Pending" reflects truly-pending listings only.
    $_lusterPendingCount    = max(0, (int) ($_lusterStats['trouble'] ?? 0) - $_lusterClosedCount - $_lusterAdoptedOutCount);
    $_lusterUpcomingCount   = (int) ($_lusterStats['upcoming'] ?? 0);
}

// When a pseudo-view is active, fetch that status group's listings instead.
// When browsing "All Listings" (the ?cat=all browse-index link), show every
// status instead of just $luster_status's subset — the "All Listings (N)"
// count next to that link already reflects the true site-wide total
// (getStats()'s COUNT(*)), so the listings actually shown there should match,
// including Closed/Adopted Out (with their status badge), not silently
// exclude them the way a plain $luster_status='current' page would.
$_lusterContentStatus = match (true) {
    $_lusterPseudoView !== ''  => $_lusterPseudoView,
    $_lusterBrowseShowAll      => 'all',
    default                    => $_lusterStatus,
};

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
    $_lusterAllCount,
    $_lusterShowCatSwitcher,
    $_lusterPseudoView,
    $_lusterPendingCount,
    $_lusterShowPendingPseudo,
    $_lusterUpcomingCount,
    $_lusterShowUpcomingPseudo,
    $_lusterClosedCount,
    $_lusterShowClosedPseudo,
    $_lusterAdoptedOutCount,
    $_lusterShowAdoptedOutPseudo,
    $_lusterPastCount,
    $_lusterShowPastPseudo,
);

$_lusterCategoryHtml  = $_lusterPresenterResult['html'];
$_lusterShowListings  = $_lusterPresenterResult['show_listings'];

// ---------------------------------------------------------------------------
// 3d. Load owned listing row template from DB (if set)
// ---------------------------------------------------------------------------

// The Past view gets its own dedicated template (past_owned_template), independent
// from the shared owned_template every other status uses — set on the Collective
// Templates page's "Past Fanlistings" section.
$_lusterOwnedTemplateKey = $_lusterContentStatus === 'past' ? 'past_owned_template' : 'owned_template';

$_lusterSiteRepo      = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterOwnedTemplate = $_lusterSiteRepo->get($_lusterOwnedTemplateKey)
    ?? SiteSettingsRepo::collectiveDefaults()[$_lusterOwnedTemplateKey];
if (trim($_lusterOwnedTemplate) === '') {
    $_lusterOwnedTemplate = SiteSettingsRepo::collectiveDefaults()[$_lusterOwnedTemplateKey];
}

$_lusterOwnedTemplate = str_replace('{site_name}', htmlspecialchars((string) $_lusterApp->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $_lusterOwnedTemplate);

$_lusterListingHtml = null;
if ($_lusterOwnedTemplate !== null && $_lusterShowListings) {
    // "?luster_img=listings" instead of a direct uploads URL — keeps the real
    // Luster install path out of every <img src>. Requires the host page to
    // have the serveUploadedImage() guard next to the existing serveCss() one
    // (see Luster\Core\View::serveUploadedImage() docblock).
    $_lusterUploadsUrl = '?luster_img=listings';

    // Batch-fetch settings (image, last_updated) for all listings
    $_lusterListingIds = array_map(static fn($fl) => $fl->id, $_lusterListings);

    $_lusterSettingsMap       = [];
    $_lusterListingCategoryMap = [];

    if (!empty($_lusterListingIds)) {
        $_db = $_lusterApp->getDatabase();
        $_lusterSettingsMap = (new ListingSettingsRepo($_db))->getRenderSettingsBatch($_lusterListingIds);

        // Merge lifecycle metadata (adopted_to_name/url, archive_note, archived_at) for
        // any archived listing so the row template's {adopted_to_*}/{archive_note} tokens
        // resolve — only relevant when this view actually includes closed/adopted_out rows.
        if ($_lusterContentStatus === 'closed' || $_lusterContentStatus === 'adopted_out' || $_lusterContentStatus === 'past' || $_lusterContentStatus === 'all') {
            $_lusterLifecycleRepo = new \Luster\Repositories\ListingLifecycleRepo($_db);
            foreach ($_lusterListingIds as $_lcListingId) {
                $_lcRow = $_lusterLifecycleRepo->getByListingId($_lcListingId);
                if ($_lcRow !== null) {
                    $_lusterSettingsMap[$_lcListingId] = array_merge(
                        $_lusterSettingsMap[$_lcListingId] ?? [],
                        \Luster\Repositories\ListingLifecycleRepo::renderFields($_lcRow),
                    );
                }
            }
        }

        // Batch-fetch category names (+ network source, for {if_tfl}/{if_tafl}/
        // {network_name} — independent of the "Approved By" field) via junction table
        try {
            $_db  = $_db ?? $_lusterApp->getDatabase();
            $_phs = implode(',', array_fill(0, count($_lusterListingIds), '?'));
            $_st  = $_db->prepare(
                "SELECT lc.fanlisting_id, c.name, c.source
                 FROM lstr_listing_categories lc
                 JOIN lstr_categories c ON c.id = lc.category_id
                 WHERE lc.fanlisting_id IN ({$_phs})
                 ORDER BY lc.fanlisting_id, c.name"
            );
            $_st->execute(array_values($_lusterListingIds));
            $_lcRaw = [];
            foreach ($_st->fetchAll() as $_row) {
                $_lcListingId = (int) $_row['fanlisting_id'];
                $_lcRaw[$_lcListingId][] = (string) $_row['name'];
                if ($_row['source'] === 'tfl') {
                    $_lusterSettingsMap[$_lcListingId]['has_tfl_category'] = true;
                }
                if ($_row['source'] === 'tafl') {
                    $_lusterSettingsMap[$_lcListingId]['has_tafl_category'] = true;
                }
            }
            foreach ($_lcRaw as $_lcId => $_lcNames) {
                $_lusterListingCategoryMap[$_lcId] = implode(', ', $_lcNames);
            }
        } catch (\Exception) {
            // Junction table absent: fall back to primary category_id
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
    'total_all_count'     => $_lusterAllCount,
    'status'              => $_lusterStatus,
    'view'                => $_lusterPseudoView,
    'uploads_url'         => '?luster_img=listings',
    'base_url'            => $_lusterApp->getBaseUrl(),
    'form_action'         => $_lusterFormAction,
    'theme_path'          => $_lusterApp->getBaseUrl() . '/assets/default/',
]);


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
