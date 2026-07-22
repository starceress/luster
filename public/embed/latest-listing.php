<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable latest owned listing widget. Renders the most recently opened
 listing using the admin-editable Latest Listing template from Collective
 Templates. No $luster_fanlisting_id is required. Falls back to the most
 recently inserted listing when the schema has no opened-date column. Outputs
 a harmless HTML comment when no listings exist.
*/

declare(strict_types=1);

use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingRowRenderer;

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
$luster_attribution = \Luster\Core\View::attribution();

// ---------------------------------------------------------------------------
// 2. Fetch the most recently opened active listing
// ---------------------------------------------------------------------------

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterLatest      = $_lusterListingRepo->getLatestOpened(1);

if (empty($_lusterLatest)) {
    $_lusterHtml = '<!-- Luster latest-listing: no active listings found. -->';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterListing = $_lusterLatest[0];

// ---------------------------------------------------------------------------
// 3. Load latest listing template from DB
// ---------------------------------------------------------------------------

$_lusterSiteRepo  = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterTemplate  = $_lusterSiteRepo->get('latest_listing_template')
    ?? SiteSettingsRepo::collectiveDefaults()['latest_listing_template'];
if (trim($_lusterTemplate) === '') {
    $_lusterTemplate = SiteSettingsRepo::collectiveDefaults()['latest_listing_template'];
}

$_lusterTemplate = str_replace('{site_name}', htmlspecialchars((string) $_lusterApp->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $_lusterTemplate);

// "?luster_img=listings" instead of a direct uploads URL — keeps the real
// Luster install path out of every <img src>. Requires the host page to have
// the serveUploadedImage() guard next to the existing serveCss() one (see
// Luster\Core\View::serveUploadedImage() docblock).
$_lusterUploadsUrl = '?luster_img=listings';

// Fetch per-listing settings (image, last_updated)
$_lusterSettings = (new ListingSettingsRepo($_lusterApp->getDatabase()))->getRenderSettings($_lusterListing->id);

// Fetch all assigned category names (+ network source, for {if_tfl}/{if_tafl}/
// {network_name} — independent of "Approved By") via junction table (falls back
// to primary categoryId)
$_lusterCategoryName = '';
try {
    $_st = $_lusterApp->getDatabase()->prepare(
        "SELECT c.name, c.source
         FROM lstr_listing_categories lc
         JOIN lstr_categories c ON c.id = lc.category_id
         WHERE lc.fanlisting_id = :id
         ORDER BY c.name"
    );
    $_st->execute(['id' => $_lusterListing->id]);
    $_lcRows  = $_st->fetchAll();
    $_lcNames = array_map(static fn($r) => (string) $r['name'], $_lcRows);
    $_lusterCategoryName = implode(', ', $_lcNames);
    foreach ($_lcRows as $_lcRow) {
        if ($_lcRow['source'] === 'tfl')  { $_lusterSettings['has_tfl_category']  = true; }
        if ($_lcRow['source'] === 'tafl') { $_lusterSettings['has_tafl_category'] = true; }
    }
} catch (\Exception) {
    // Junction table absent: fall back to primary categoryId
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
                 || str_contains($_lusterTemplate, '{pending_owned_count}')
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
    (string) ($luster_date_format ?? ''),
);


// ---------------------------------------------------------------------------
// 4. Return or echo
// ---------------------------------------------------------------------------

$_lusterHtml .= $luster_attribution;

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
