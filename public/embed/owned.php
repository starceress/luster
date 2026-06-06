<?php

declare(strict_types=1);

use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingRowRenderer;
use Luster\Support\MemberStatsService;
use Luster\Support\PublicCategoryPresenter;

/**
 * Luster embed — owned listings list.
 *
 * Drop this into any PHP page to embed the list of listings you own.
 * Supports status filtering, category filtering, and two selector display modes.
 *
 * Usage:
 *   $luster_return = false;                  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/owned.php';
 *
 * Optional variables:
 *   $luster_status            string  'current' | 'upcoming' | 'pending' | 'all'
 *                                     Default: 'current'
 *   $luster_exclude_pending   bool    When true and $luster_status is 'all', pending /
 *                                     inactive listings are silently omitted.
 *                                     Default: true
 *   $luster_category_id       int     Luster category ID to pre-filter to (0 = all).
 *                                     When 0, reads $_GET['cat'] — accepts both numeric
 *                                     IDs and slugs (e.g. ?cat=anime-manga).
 *                                     Default: 0 (reads $_GET['cat'])
 *   $luster_hide_dropdown       bool    Set true to suppress the category selector entirely.
 *                                       Default: false
 *   $luster_show_list           bool    Set true to display categories as a bulleted link
 *                                       list instead of a <select> dropdown.
 *                                       Default: false
 *   $luster_browse_by_category  bool    Set true to enable browse-by-category mode.
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

$_lusterValidStatuses = ['current', 'upcoming', 'pending', 'all'];
$_lusterStatus        = in_array($luster_status ?? 'current', $_lusterValidStatuses, true)
                      ? (string) ($luster_status ?? 'current')
                      : 'current';

$_lusterExcludePending  = (bool) ($luster_exclude_pending  ?? true);
$_lusterHideDropdown    = (bool) ($luster_hide_dropdown    ?? false);
$_lusterShowList        = (bool) ($luster_show_list        ?? false);
$_lusterBrowseByCat     = (bool) ($luster_browse_by_category ?? false);

// Category ID resolution order:
//   1. $luster_category_id (explicit integer) — bypasses URL params entirely
//   2. $_GET['category'] (slug) — new dedicated slug param, avoids numeric ambiguity
//   3. $_GET['cat'] with 'slug:' prefix — dropdown-posted slug value
//   4. $_GET['cat'] with 'all' value — browse-show-all mode
//   5. $_GET['cat'] numeric — legacy ID-based link
$_lusterBrowseShowAll = false;
if (isset($luster_category_id) && (int) $luster_category_id > 0) {
    $_lusterCatId = (int) $luster_category_id;
} else {
    $_lusterRawCat = (string) ($_GET['cat'] ?? '');
    if ($_lusterRawCat === 'all') {
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

$_lusterListings = $_lusterListingRepo->getFiltered(
    $_lusterStatus,
    $_lusterCatId > 0 ? $_lusterCatId : null,
    $_lusterExcludePending,
);

// ---------------------------------------------------------------------------
// 3b. Build the category navigator HTML via PublicCategoryPresenter
// ---------------------------------------------------------------------------

$_lusterPresenterResult = PublicCategoryPresenter::renderOwned(
    $_lusterCategories,
    $_lusterCatId,
    htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    $_lusterHideDropdown,
    $_lusterShowList,
    $_lusterBrowseByCat,
    $_lusterBrowseShowAll,
    count($_lusterListings),
);

$_lusterCategoryHtml  = $_lusterPresenterResult['html'];
$_lusterShowListings  = $_lusterPresenterResult['show_listings'];

// ---------------------------------------------------------------------------
// 3c. Load owned listing row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSiteRepo      = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterOwnedTemplate = $_lusterSiteRepo->get('owned_template')
    ?? SiteSettingsRepo::collectiveDefaults()['owned_template'];
if (trim($_lusterOwnedTemplate) === '') {
    $_lusterOwnedTemplate = SiteSettingsRepo::collectiveDefaults()['owned_template'];
}

$_lusterListingHtml = null;
$_lusterSectionsMode = false;
if ($_lusterOwnedTemplate !== null && $_lusterShowListings) {
    $_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/listings';

    // Batch-fetch settings (image, last_updated) for all listings
    $_lusterListingIds = array_map(static fn($fl) => $fl->id, $_lusterListings);

    $_lusterSettingsMap       = [];
    $_lusterListingCategoryMap = [];

    if (!empty($_lusterListingIds)) {
        try {
            $_db  = $_lusterApp->getDatabase();
            $_phs = implode(',', array_fill(0, count($_lusterListingIds), '?'));
            $_st  = $_db->prepare("SELECT fanlisting_id, image, last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id IN ({$_phs})");
            $_st->execute(array_values($_lusterListingIds));
            foreach ($_st->fetchAll() as $_row) {
                $_lusterSettingsMap[(int) $_row['fanlisting_id']] = $_row;
            }
        } catch (\Exception) {}

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
                            || str_contains($_lusterOwnedTemplate, '{pending_count}')
                            || str_contains($_lusterOwnedTemplate, '{country_count}')
                            || str_contains($_lusterOwnedTemplate, '{countries_count}')
                            || str_contains($_lusterOwnedTemplate, '{if_countries}')
                            || str_contains($_lusterOwnedTemplate, '{if_pending}');
    if ($_lusterNeedsMemberStats && !empty($_lusterListings)) {
        $_lusterMemberService  = new MemberStatsService($_lusterApp->getDatabase(), $_lusterApp->getConfig());
        $_lusterMemberStatsMap = $_lusterMemberService->forListings($_lusterListings);
    }

    // Render each listing row using ListingRowRenderer
    $_lusterListingHtml = implode('', array_map(
        static fn($fl) => ListingRowRenderer::render(
            $fl,
            $_lusterOwnedTemplate,
            $_lusterSettingsMap[$fl->id] ?? [],
            $_lusterApp->getBaseUrl() . '/uploads/listings',
            $_lusterListingCategoryMap[$fl->id] ?? '',
            $_lusterMemberStatsMap[$fl->id] ?? [],
        ),
        $_lusterListings,
    ));

    // Sections mode
    $_lusterSectionsTplRaw = $_lusterSiteRepo->get('owned_sections_template');
    $_lusterSectionsTpl    = $_lusterSectionsTplRaw === null
        ? SiteSettingsRepo::collectiveDefaults()['owned_sections_template']
        : $_lusterSectionsTplRaw;

    if ($_lusterStatus === 'all' && trim($_lusterSectionsTpl) !== '') {
        $_lusterSectionsMode = true;
        $_lcCurrent  = [];
        $_lcUpcoming = [];
        $_lcPending  = [];
        foreach ($_lusterListings as $_fl) {
            if ($_fl->trouble) {
                $_lcPending[] = $_fl;
            } elseif ($_fl->upcoming) {
                $_lcUpcoming[] = $_fl;
            } else {
                $_lcCurrent[] = $_fl;
            }
        }

        $_lcRender = static fn($fl) => ListingRowRenderer::render(
            $fl,
            $_lusterOwnedTemplate,
            $_lusterSettingsMap[$fl->id] ?? [],
            $_lusterApp->getBaseUrl() . '/uploads/listings',
            $_lusterListingCategoryMap[$fl->id] ?? '',
            $_lusterMemberStatsMap[$fl->id] ?? [],
        );

        $_lcCurrentHtml  = implode('', array_map($_lcRender, $_lcCurrent));
        $_lcUpcomingHtml = implode('', array_map($_lcRender, $_lcUpcoming));
        $_lcPendingHtml  = implode('', array_map($_lcRender, $_lcPending));

        $_lusterSectionsOut = (string) preg_replace('/\{if_current_listings\}(.*?)\{\/if_current_listings\}/s', $_lcCurrentHtml  !== '' ? '$1' : '', $_lusterSectionsTpl);
        $_lusterSectionsOut = (string) preg_replace('/\{if_upcoming_listings\}(.*?)\{\/if_upcoming_listings\}/s', $_lcUpcomingHtml !== '' ? '$1' : '', $_lusterSectionsOut);
        $_lusterSectionsOut = (string) preg_replace('/\{if_pending_listings\}(.*?)\{\/if_pending_listings\}/s',  $_lcPendingHtml  !== '' ? '$1' : '', $_lusterSectionsOut);
        $_lusterSectionsOut = str_replace(
            ['{current_content}', '{upcoming_content}', '{pending_content}',
             '{current_count}', '{upcoming_count}', '{pending_count}', '{total_count}'],
            [$_lcCurrentHtml, $_lcUpcomingHtml, $_lcPendingHtml,
             (string) count($_lcCurrent), (string) count($_lcUpcoming), (string) count($_lcPending),
             (string) count($_lusterListings)],
            $_lusterSectionsOut,
        );
        $_lusterListingHtml = trim($_lusterSectionsOut) !== '' ? $_lusterSectionsOut : '';
    }
}

// ---------------------------------------------------------------------------
// 4. Build form action URL
// ---------------------------------------------------------------------------

$_lusterFormAction = htmlspecialchars(
    strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('owned.php', [
    'listings'            => $_lusterListings,
    'listing_html'        => $_lusterListingHtml,
    'sections_mode'       => $_lusterSectionsMode,
    'category_html'       => $_lusterCategoryHtml,
    'show_listings'       => $_lusterShowListings,
    'categories'          => $_lusterCategories,
    'current_category'    => $_lusterCatId,
    'browse_show_all'     => $_lusterBrowseShowAll,
    'total_count'         => count($_lusterListings),
    'status'              => $_lusterStatus,
    'uploads_url'         => $_lusterApp->getBaseUrl() . '/uploads',
    'base_url'            => $_lusterApp->getBaseUrl(),
    'form_action'         => $_lusterFormAction,
    'theme_path'          => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
