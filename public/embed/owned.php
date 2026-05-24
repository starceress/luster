<?php

declare(strict_types=1);

use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Repositories\ListingSettingsRepo;

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
 *                                     inactive listings are silently omitted so they
 *                                     never appear in a general listing. Has no effect
 *                                     when $luster_status is any other value.
 *                                     Default: true
 *   $luster_category_id       int     Luster category ID to pre-filter to (0 = all).
 *                                     When 0, reads $_GET['cat'] so the built-in
 *                                     category selector works without extra PHP.
 *                                     Default: 0 (reads $_GET['cat'])
 *   $luster_hide_dropdown       bool    Set true to suppress the category selector entirely.
 *                                       Default: false
 *   $luster_show_list           bool    Set true to display categories as a bulleted link
 *                                       list instead of a <select> dropdown.
 *                                       Default: false
 *   $luster_browse_by_category  bool    Set true to enable browse-by-category mode.
 *                                       When no category is selected, a list of category
 *                                       names (with listing counts) is shown as clickable
 *                                       links. Selecting a category then shows that
 *                                       category's listings formatted with the HTML template.
 *                                       Default: false
 *
 * Category selector behaviour:
 *   The selector submits to the current page (PHP_SELF) so it works without any
 *   additional routing. The selected category ID is passed as $_GET['cat'].
 *   Set $luster_hide_dropdown = true if you want to filter a specific category
 *   without showing the selector at all.
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
$_lusterHideDropdown    = (bool) ($luster_hide_dropdown   ?? false);
$_lusterShowList        = (bool) ($luster_show_list        ?? false);
$_lusterBrowseByCat     = (bool) ($luster_browse_by_category ?? false);

// Category ID: prefer explicit $luster_category_id; fall back to $_GET['cat'].
// In browse-by-category mode, '?cat=all' means "show all listings" rather than
// returning to the category index (which is the normal cat=0 / no-param behaviour).
$_lusterBrowseShowAll = false;
if (isset($luster_category_id) && (int) $luster_category_id > 0) {
    $_lusterCatId = (int) $luster_category_id;
} else {
    $_lusterRawCat = (string) ($_GET['cat'] ?? '');
    if ($_lusterRawCat === 'all') {
        $_lusterCatId         = 0;   // no category filter — load all listings
        $_lusterBrowseShowAll = true;
    } else {
        $_lusterCatId = max(0, (int) $_lusterRawCat);
    }
}

// ---------------------------------------------------------------------------
// 3. Load listings and categories
// ---------------------------------------------------------------------------

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());

$_lusterListings = $_lusterListingRepo->getFiltered(
    $_lusterStatus,
    $_lusterCatId > 0 ? $_lusterCatId : null,
    $_lusterExcludePending,
);

$_lusterCategories = $_lusterListingRepo->getCategoryList();

// ---------------------------------------------------------------------------
// 3b. Load owned listing row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSiteRepo      = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterOwnedTemplate = $_lusterSiteRepo->get('owned_template')
    ?? SiteSettingsRepo::collectiveDefaults()['owned_template'];
if (trim($_lusterOwnedTemplate) === '') {
    $_lusterOwnedTemplate = SiteSettingsRepo::collectiveDefaults()['owned_template'];
}

// Build per-listing HTML when a custom template is set
$_lusterListingHtml = null;
$_lusterSectionsMode = false;
if ($_lusterOwnedTemplate !== null) {
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/listings';
    $statusLabels = ['current' => 'Current', 'upcoming' => 'Upcoming', 'pending' => 'Pending'];

    // Batch-fetch settings (image, last_updated) and category names for all listings
    $_lusterListingIds = array_map(static fn($fl) => $fl->id, $_lusterListings);

    $_lusterSettingsMap       = [];   // listingId → ['image' => ..., 'last_updated' => ...]
    $_lusterListingCategoryMap = [];  // listingId → comma-separated category names

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

        // Batch-fetch all category names via junction table (returns all assigned categories)
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
                    $_lusterListingCategoryMap[$_fl->id] = '';  // resolved below
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

    // Fetch member stats (one query per listing) only when the template uses them
    $_lusterMemberStatsMap = [];
    $_lusterNeedsMemberStats = str_contains($_lusterOwnedTemplate, '{member_count}')
                            || str_contains($_lusterOwnedTemplate, '{country_count}');
    if ($_lusterNeedsMemberStats) {
        $_lusterMemberRepo = new MemberRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
        foreach ($_lusterListings as $_fl) {
            $_lusterMemberStatsMap[$_fl->id] = $_lusterMemberRepo->getMemberStats($_fl->dbprefix);
        }
    }

    $renderOwnedRow = static function (object $_fl) use (
        $e, $_lusterOwnedTemplate, $_lusterUploadsUrl,
        $statusLabels, $_lusterSettingsMap, $_lusterListingCategoryMap, $_lusterMemberStatsMap
    ): string {
        $statusLabel  = $statusLabels[$_fl->trouble ? 'pending' : ($_fl->upcoming ? 'upcoming' : 'current')] ?? 'Current';
        $_flSettings  = $_lusterSettingsMap[$_fl->id] ?? [];
        $_flImage     = (string) ($_flSettings['image'] ?? '');
        $_flImageTag  = $_flImage !== ''
            ? '<img src="' . $e($_lusterUploadsUrl . '/' . $_flImage) . '" alt="' . $e($_fl->name) . '" title="' . $e($_fl->subject) . '">'
            : '';
        $_flStats = $_lusterMemberStatsMap[$_fl->id] ?? ['approved' => 0, 'country_count' => 0];
        return str_replace(
            [
                '{listing_name}', '{listing_url}', '{listing_subject}', '{listing_status}',
                '{listing_email}', '{listing_type}', '{listing_category}',
                '{listing_image}', '{date_opened}', '{date_updated}',
                '{member_count}', '{country_count}',
            ],
            [
                $e($_fl->name), $e($_fl->url), $e($_fl->subject ?? ''), $e($statusLabel),
                $e($_fl->email), $e($_fl->listingtype), $e($_lusterListingCategoryMap[$_fl->id] ?? ''),
                $_flImageTag, $e($_fl->opened ?? ''), $e((string) ($_flSettings['last_updated'] ?? '')),
                (string) $_flStats['approved'], (string) $_flStats['country_count'],
            ],
            $_lusterOwnedTemplate,
        );
    };

    $_lusterListingHtml = implode('', array_map($renderOwnedRow, $_lusterListings));

    // Sections mode: null (never saved) = use built-in default; '' (explicitly cleared) = off.
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
        $_lcCurrentHtml  = implode('', array_map($renderOwnedRow, $_lcCurrent));
        $_lcUpcomingHtml = implode('', array_map($renderOwnedRow, $_lcUpcoming));
        $_lcPendingHtml  = implode('', array_map($renderOwnedRow, $_lcPending));

        $_lusterSectionsOut = (string) preg_replace(
            '/\{if_current_listings\}(.*?)\{\/if_current_listings\}/s',
            $_lcCurrentHtml !== '' ? '$1' : '',
            $_lusterSectionsTpl,
        );
        $_lusterSectionsOut = (string) preg_replace(
            '/\{if_upcoming_listings\}(.*?)\{\/if_upcoming_listings\}/s',
            $_lcUpcomingHtml !== '' ? '$1' : '',
            $_lusterSectionsOut,
        );
        $_lusterSectionsOut = (string) preg_replace(
            '/\{if_pending_listings\}(.*?)\{\/if_pending_listings\}/s',
            $_lcPendingHtml !== '' ? '$1' : '',
            $_lusterSectionsOut,
        );
        $_lusterSectionsOut = str_replace(
            ['{current_content}', '{upcoming_content}', '{pending_content}',
             '{current_count}', '{upcoming_count}', '{pending_count}', '{total_count}'],
            [$_lcCurrentHtml, $_lcUpcomingHtml, $_lcPendingHtml,
             (string) count($_lcCurrent), (string) count($_lcUpcoming), (string) count($_lcPending),
             (string) count($_lusterListings)],
            $_lusterSectionsOut,
        );
        // Empty string when all conditional blocks were stripped — lets the template
        // fall through to the "No listings to display" path correctly.
        $_lusterListingHtml = trim($_lusterSectionsOut) !== '' ? $_lusterSectionsOut : '';
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

$_lusterHtml = $_lusterApp->getView()->renderFragment('owned.php', [
    'listings'            => $_lusterListings,
    'listing_html'        => $_lusterListingHtml,
    'sections_mode'       => $_lusterSectionsMode,
    'categories'          => $_lusterCategories,
    'current_category'    => $_lusterCatId,
    'status'              => $_lusterStatus,
    'hide_dropdown'       => $_lusterHideDropdown,
    'show_list'           => $_lusterShowList,
    'browse_by_category'  => $_lusterBrowseByCat,
    'browse_show_all'     => $_lusterBrowseShowAll,
    'uploads_url'         => $_lusterApp->getBaseUrl() . '/uploads',
    'base_url'            => $_lusterApp->getBaseUrl(),
    'form_action'         => $_lusterFormAction,
    'theme_path'          => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
