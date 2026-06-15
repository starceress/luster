<?php

declare(strict_types=1);

use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingRowRenderer;

/**
 * Luster embed — random single owned listing.
 *
 * Picks one active listing at random from the selected pool and renders it
 * using the Random Listing template from Admin → Collective → Templates.
 * Outputs an HTML comment when the pool is empty so the host page never breaks.
 *
 * Usage:
 *   require '/absolute/path/to/luster/public/embed/random-listing.php';
 *
 * Optional variables:
 *   $luster_status   string  Pool to draw from: 'current' | 'upcoming' | 'all'
 *                            Default: 'current'
 *   $luster_return   bool    true = return HTML string, false = echo
 *                            Default: false
 */

// ---------------------------------------------------------------------------
// 1. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2);

if (!is_file($_lusterRoot . '/vendor/autoload.php') || !is_file($_lusterRoot . '/config/app.php')) {
    $_lusterHtml = '<!-- Luster embed error: Luster is not installed correctly. -->';
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

$_lusterValidStatuses = ['current', 'upcoming', 'all'];
$_lusterStatus        = in_array($luster_status ?? 'current', $_lusterValidStatuses, true)
                      ? (string) ($luster_status ?? 'current')
                      : 'current';

// ---------------------------------------------------------------------------
// 3. Pick one listing at random
// ---------------------------------------------------------------------------

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterPool        = $_lusterListingRepo->getFiltered($_lusterStatus, null, true);

if (empty($_lusterPool)) {
    $_lusterHtml = '<!-- Luster random-listing: no active listings in the pool. -->';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterListing = $_lusterPool[array_rand($_lusterPool)];

// ---------------------------------------------------------------------------
// 4. Load random listing template from DB
// ---------------------------------------------------------------------------

$_lusterSiteRepo  = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterTemplate  = $_lusterSiteRepo->get('random_listing_template')
    ?? SiteSettingsRepo::collectiveDefaults()['random_listing_template'];
if (trim($_lusterTemplate) === '') {
    $_lusterTemplate = SiteSettingsRepo::collectiveDefaults()['random_listing_template'];
}

$_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/listings';

// Fetch per-listing settings (image, last_updated)
$_lusterSettings = (new ListingSettingsRepo($_lusterApp->getDatabase()))->getRenderSettings($_lusterListing->id);

// Fetch all assigned category names via junction table (falls back to primary categoryId)
$_lusterCategoryName = '';
try {
    $_st = $_lusterApp->getDatabase()->prepare(
        "SELECT c.name
         FROM lstr_listing_categories lc
         JOIN lstr_categories c ON c.id = lc.category_id
         WHERE lc.fanlisting_id = :id
         ORDER BY c.name"
    );
    $_st->execute(['id' => $_lusterListing->id]);
    $_lcNames = $_st->fetchAll(\PDO::FETCH_COLUMN);
    $_lusterCategoryName = implode(', ', $_lcNames);
} catch (\Exception) {
    // Junction table absent (pre-migration 51): fall back to primary categoryId
    if ($_lusterListing->categoryId !== null && $_lusterListing->categoryId > 0) {
        try {
            $_st = $_lusterApp->getDatabase()->prepare(
                'SELECT name FROM lstr_categories WHERE id = :id'
            );
            $_st->execute(['id' => $_lusterListing->categoryId]);
            $_lusterCategoryName = (string) ($_st->fetchColumn() ?: '');
        } catch (\Exception) {}
    }
}

// Optional member stats (only fetched when template uses them)
$_flStats = ['approved' => 0, 'pending' => 0, 'country_count' => 0];
$_flNeedsApproved = str_contains($_lusterTemplate, '{member_count}')
                 || str_contains($_lusterTemplate, '{country_count}')
                 || str_contains($_lusterTemplate, '{if_countries}');
$_flNeedsPending  = str_contains($_lusterTemplate, '{member_pending_count}')
                 || str_contains($_lusterTemplate, '{pending_count}')
                 || str_contains($_lusterTemplate, '{if_pending}');

if ($_flNeedsApproved || $_flNeedsPending) {
    try {
        $_flMemberRepo = new MemberRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());

        if ($_flNeedsApproved) {
            $_flStats = array_merge($_flStats, $_flMemberRepo->getMemberStats($_lusterListing->dbprefix));
        }
        if ($_flNeedsPending) {
            $_flStats['pending'] = $_flMemberRepo->countPending($_lusterListing->dbprefix);
        }
    } catch (\Exception) {}
}

// renderTemplate() auto-detects flat ({each_listing}) or sectioned
// ({each_current}/{each_upcoming}/{each_pending}) mode and renders this
// single listing through whichever shape the template declares.
$_lusterHtml = ListingRowRenderer::renderTemplate(
    $_lusterTemplate,
    [$_lusterListing],
    $_lusterUploadsUrl,
    [$_lusterListing->id => $_lusterSettings],
    [$_lusterListing->id => $_flStats],
    $_lusterCategoryName !== '' ? [$_lusterListing->id => $_lusterCategoryName] : [],
);

$_lusterHtml .= \Luster\Core\View::attribution();

// ---------------------------------------------------------------------------
// 5. Return or echo
// ---------------------------------------------------------------------------

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
