<?php

declare(strict_types=1);

use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingRowRenderer;

/**
 * Luster embed — random single owned listing.
 *
 * Picks one active listing at random from the selected pool and renders it
 * using the owned-listings row template from Admin → Collective.
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
// 4. Load owned template from DB
// ---------------------------------------------------------------------------

$_lusterSiteRepo      = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterOwnedTemplate = $_lusterSiteRepo->get('owned_template')
    ?? SiteSettingsRepo::collectiveDefaults()['owned_template'];
if (trim($_lusterOwnedTemplate) === '') {
    $_lusterOwnedTemplate = SiteSettingsRepo::collectiveDefaults()['owned_template'];
}

$_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/listings';

// Fetch per-listing settings (image, last_updated)
$_lusterSettings = [];
try {
    $_st = $_lusterApp->getDatabase()->prepare(
        'SELECT fanlisting_id, image, last_updated, approved_by FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
    );
    $_st->execute(['id' => $_lusterListing->id]);
    $_lusterSettings = $_st->fetch() ?: [];
} catch (\Exception) {}

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
$_flNeedsApproved = str_contains($_lusterOwnedTemplate, '{member_count}')
                 || str_contains($_lusterOwnedTemplate, '{country_count}')
                 || str_contains($_lusterOwnedTemplate, '{countries_count}')
                 || str_contains($_lusterOwnedTemplate, '{if_countries}');
$_flNeedsPending  = str_contains($_lusterOwnedTemplate, '{member_pending_count}')
                 || str_contains($_lusterOwnedTemplate, '{pending_count}')
                 || str_contains($_lusterOwnedTemplate, '{if_pending}');

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

// Routed through renderTemplate() — not render() — because owned_template is
// now a combined block template that may declare {each_listing} (flat) or
// {each_current}/{each_upcoming}/{each_pending} (sectioned) loops of its own.
// render() has no loop-processing step, so calling it directly here would leak
// literal {each_listing}/{/each_listing} tags into the page for the new
// defaults. renderTemplate() auto-detects the template's shape and renders
// this single listing through whichever mode it declares — placing it into
// its matching section when the template is sectioned — exactly as
// embed/owned.php does for a full listing pool (docs/PUBLIC-TEMPLATES-PLAN.md).
$_lusterHtml = ListingRowRenderer::renderTemplate(
    $_lusterOwnedTemplate,
    [$_lusterListing],
    $_lusterUploadsUrl,
    [$_lusterListing->id => $_lusterSettings],
    [$_lusterListing->id => $_flStats],
    $_lusterCategoryName !== '' ? [$_lusterListing->id => $_lusterCategoryName] : [],
);

// ---------------------------------------------------------------------------
// 5. Return or echo
// ---------------------------------------------------------------------------

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
