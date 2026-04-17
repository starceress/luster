<?php

declare(strict_types=1);

use Luster\Addons\Updates\UpdateRepo;

/**
 * Luster embed — collective updates feed.
 *
 * Drop this into any PHP page to embed the site-wide updates feed.
 * Requires the Updates add-on to be installed.
 *
 * Usage:
 *   $luster_per_page  = 5;    // optional; default from config per_page
 *   $luster_show_cats = true; // optional bool; show category tags per entry
 *   $luster_return    = true; // optional bool; true = return string, false = echo
 *   echo require '/absolute/path/to/luster/public/embed/collective-updates.php';
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
// 2. Resolve optional parameters
// ---------------------------------------------------------------------------

$_lusterConfig   = $_lusterApp->getConfig();
$_lusterPerPage  = isset($luster_per_page)  ? max(1, (int) $luster_per_page)  : max(1, (int) $_lusterConfig->get('per_page', 20));
$_lusterShowCats = isset($luster_show_cats) ? (bool) $luster_show_cats : true;
$_lusterPage     = max(1, (int) ($_GET['page'] ?? 1));
$_lusterCat      = trim((string) ($_GET['cat'] ?? ''));

// ---------------------------------------------------------------------------
// 3. Load updates
// ---------------------------------------------------------------------------

$_lusterDb = $_lusterApp->getDatabase();

try {
    $_lusterUpdateRepo = new UpdateRepo($_lusterDb);
    if ($_lusterCat !== '') {
        $_lusterUpdates = $_lusterUpdateRepo->getCollectiveByCategory($_lusterCat, $_lusterPage, $_lusterPerPage);
        $_lusterTotal   = $_lusterUpdateRepo->countCollectiveByCategory($_lusterCat);
    } else {
        $_lusterUpdates = $_lusterUpdateRepo->getCollective($_lusterPage, $_lusterPerPage);
        $_lusterTotal   = $_lusterUpdateRepo->countCollective();
    }
    $_lusterPages = max(1, (int) ceil($_lusterTotal / $_lusterPerPage));
} catch (\Throwable $_lusterEx) {
    // Updates add-on not installed or table missing — return empty output silently.
    if ($luster_return ?? false) {
        return '';
    }
    return;
}

// ---------------------------------------------------------------------------
// 4. Build feed URL for pagination links
// ---------------------------------------------------------------------------

$_lusterFeedUrl = htmlspecialchars(
    strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('default/addons/updates/feed.php', [
    'updates'       => $_lusterUpdates,
    'total'         => $_lusterTotal,
    'page'          => $_lusterPage,
    'pages'         => $_lusterPages,
    'is_collective' => true,
    'show_cats'     => $_lusterShowCats,
    'owner_name'    => (string) ($_lusterConfig->get('owner_name', '')),
    'active_cat'    => $_lusterCat,
    'feed_url'      => $_lusterFeedUrl,
    'base_url'      => $_lusterApp->getBaseUrl(),
    'theme_path'    => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
