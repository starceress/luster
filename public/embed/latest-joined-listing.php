<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable latest joined listing widget. Renders the most recently joined
 listing (by date_joined) using the admin-editable Latest Joined Listing
 template from Collective Templates. No $luster_fanlisting_id is required.
 Outputs a harmless HTML comment when no active joined listings exist.
*/

declare(strict_types=1);

use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\JoinedListingRowRenderer;

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
// 2. Fetch the most recently joined active, approved listing
// ---------------------------------------------------------------------------

$_lusterRepo   = new JoinedListingRepo($_lusterApp->getDatabase());
$_lusterLatest = $_lusterRepo->getLatestJoined(1);

if (empty($_lusterLatest)) {
    $_lusterHtml = '<!-- Luster latest-joined-listing: no active joined listings found. -->';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterListing = $_lusterLatest[0];

// ---------------------------------------------------------------------------
// 3. Load latest joined listing template from DB
// ---------------------------------------------------------------------------

$_lusterSiteRepo = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterTemplate = $_lusterSiteRepo->get('latest_joined_listing_template')
    ?? SiteSettingsRepo::collectiveDefaults()['latest_joined_listing_template'];
if (trim($_lusterTemplate) === '') {
    $_lusterTemplate = SiteSettingsRepo::collectiveDefaults()['latest_joined_listing_template'];
}

$_lusterTemplate = str_replace('{site_name}', htmlspecialchars((string) $_lusterApp->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $_lusterTemplate);

$_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/joined';

$_lusterHtml = JoinedListingRowRenderer::renderTemplate($_lusterTemplate, [$_lusterListing], $_lusterUploadsUrl, (string) ($luster_date_format ?? ''));

// ---------------------------------------------------------------------------
// 4. Return or echo
// ---------------------------------------------------------------------------

$_lusterHtml .= $luster_attribution;

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
