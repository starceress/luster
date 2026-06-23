<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable random joined listing widget. Picks one active, approved joined
 listing at random and renders it using the admin-editable Random Joined
 Listing template from Collective Templates. No $luster_fanlisting_id is
 required. Outputs a harmless HTML comment when the pool is empty so the host
 page never breaks.
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
// 2. Pick one joined listing at random
// ---------------------------------------------------------------------------

$_lusterRepo = new JoinedListingRepo($_lusterApp->getDatabase());
$_lusterPool = $_lusterRepo->getActiveApproved();

if (empty($_lusterPool)) {
    $_lusterHtml = '<!-- Luster random-joined-listing: no active joined listings in the pool. -->';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterListing = $_lusterPool[array_rand($_lusterPool)];

// ---------------------------------------------------------------------------
// 3. Load random joined listing template from DB
// ---------------------------------------------------------------------------

$_lusterSiteRepo = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterTemplate = $_lusterSiteRepo->get('random_joined_listing_template')
    ?? SiteSettingsRepo::collectiveDefaults()['random_joined_listing_template'];
if (trim($_lusterTemplate) === '') {
    $_lusterTemplate = SiteSettingsRepo::collectiveDefaults()['random_joined_listing_template'];
}

$_lusterTemplate = str_replace('{site_name}', htmlspecialchars((string) $_lusterApp->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $_lusterTemplate);

$_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/joined';

$_lusterHtml = JoinedListingRowRenderer::renderTemplate($_lusterTemplate, [$_lusterListing], $_lusterUploadsUrl, (string) ($luster_date_format ?? ''));

// ---------------------------------------------------------------------------
// 4. Return or echo
// ---------------------------------------------------------------------------

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
