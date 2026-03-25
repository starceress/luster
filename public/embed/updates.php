<?php

declare(strict_types=1);

use Luster\Addons\Updates\UpdateRepo;
use Luster\Repositories\ListingRepo;

/**
 * Luster embed — per-fanlisting updates feed.
 *
 * Drop this into any PHP page to embed the updates feed for one fanlisting.
 * Requires the Updates add-on to be installed.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;    // required (int) — fanlisting ID
 *   $luster_per_page      = 5;    // optional; default from config per_page
 *   $luster_show_cats     = true; // optional bool; show category tags per entry
 *   $luster_return        = true; // optional bool; true = return string, false = echo
 *   echo require '/absolute/path/to/luster/public/embed/updates.php';
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
// 2. Validate required parameter
// ---------------------------------------------------------------------------

$_lusterFid = isset($luster_fanlisting_id) ? (int) $luster_fanlisting_id : 0;

if ($_lusterFid <= 0) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: $luster_fanlisting_id is required.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 3. Load listing
// ---------------------------------------------------------------------------

$_lusterDb          = $_lusterApp->getDatabase();
$_lusterConfig      = $_lusterApp->getConfig();
$_lusterListingRepo = new ListingRepo($_lusterDb, $_lusterConfig);
$_lusterListing     = $_lusterListingRepo->getById($_lusterFid);

if ($_lusterListing === null) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: Listing not found.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 4. Resolve optional parameters
// ---------------------------------------------------------------------------

$_lusterPerPage  = isset($luster_per_page)  ? max(1, (int) $luster_per_page)  : max(1, (int) $_lusterConfig->get('per_page', 20));
$_lusterShowCats = isset($luster_show_cats) ? (bool) $luster_show_cats : true;
$_lusterPage     = max(1, (int) ($_GET['page'] ?? 1));

// ---------------------------------------------------------------------------
// 5. Load updates
// ---------------------------------------------------------------------------

try {
    $_lusterUpdateRepo = new UpdateRepo($_lusterDb);
    $_lusterUpdates    = $_lusterUpdateRepo->getForFanlisting($_lusterFid, $_lusterPage, $_lusterPerPage);
    $_lusterTotal      = $_lusterUpdateRepo->countForFanlisting($_lusterFid);
    $_lusterPages      = max(1, (int) ceil($_lusterTotal / $_lusterPerPage));
} catch (\Throwable $_lusterEx) {
    // Updates add-on not installed or table missing — return empty output silently.
    if ($luster_return ?? false) {
        return '';
    }
    return;
}

// ---------------------------------------------------------------------------
// 6. Build feed URL for pagination links
// ---------------------------------------------------------------------------

$_lusterFeedUrl = htmlspecialchars(
    strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);

// ---------------------------------------------------------------------------
// 7. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('addons/updates/feed.php', [
    'updates'       => $_lusterUpdates,
    'total'         => $_lusterTotal,
    'page'          => $_lusterPage,
    'pages'         => $_lusterPages,
    'is_collective' => false,
    'show_cats'     => $_lusterShowCats,
    'owner_name'    => (string) ($_lusterConfig->get('owner_name', '')),
    'feed_url'      => $_lusterFeedUrl,
    'base_url'      => $_lusterApp->getBaseUrl(),
    'theme_path'    => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
