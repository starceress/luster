<?php

declare(strict_types=1);

use Luster\Repositories\CodeCategoriesRepo;
use Luster\Repositories\CodeDonorsRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\ListingRepo;

/**
 * Luster embed — codes fragment.
 *
 * Usage in your own page:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/codes.php';
 *
 * Renders the codes for the given listing as a content fragment grouped by
 * detected dimensions, with no layout wrapper.
 */

// ---------------------------------------------------------------------------
// 1. Validate required variable
// ---------------------------------------------------------------------------

$_lusterListingId = (int) ($luster_fanlisting_id ?? 0);

if ($_lusterListingId === 0) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: <code>$luster_fanlisting_id</code> is not set.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 2. Bootstrap Luster
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
$_lusterApp->getSession()->start();

// ---------------------------------------------------------------------------
// 3. Load data
// ---------------------------------------------------------------------------

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterListing     = $_lusterListingRepo->getById($_lusterListingId);

if ($_lusterListing === null) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: listing #' . $_lusterListingId . ' not found.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterDb          = $_lusterApp->getDatabase();
$_lusterCodesRepo   = new CodesRepo($_lusterDb);
$_lusterCatsRepo    = new CodeCategoriesRepo($_lusterDb);
$_lusterDonorsRepo  = new CodeDonorsRepo($_lusterDb);
$_lusterCodes       = $_lusterCodesRepo->getByListing($_lusterListingId, 'approved');
$_lusterCategories  = $_lusterCatsRepo->getByListing($_lusterListingId);
$_lusterCatMap      = $_lusterCatsRepo->getCategoryMapForListing($_lusterListingId);
$_lusterDonors      = $_lusterDonorsRepo->getByListing($_lusterListingId);

// Build donor lookup: id => object
$_lusterDonorMap = [];
foreach ($_lusterDonors as $_d) {
    $_lusterDonorMap[$_d->id] = $_d;
}
// Build category lookup: id => object
$_lusterCategoryMap = [];
foreach ($_lusterCategories as $_cat) {
    $_lusterCategoryMap[$_cat->id] = $_cat;
}

// Separate donated (fan-submitted) codes from regular codes
$_lusterDonated = [];
$_lusterRegular = [];
foreach ($_lusterCodes as $_lc) {
    if ($_lc->submitterName !== null) {
        $_lusterDonated[] = $_lc;
    } else {
        $_lusterRegular[] = $_lc;
    }
}

// Auto-group regular codes by detected dimensions; unknown dimensions last
$_lusterGrouped = [];
foreach ($_lusterRegular as $_lc) {
    if ($_lc->width > 0 && $_lc->height > 0) {
        $_lKey   = (int) $_lc->width . 'x' . (int) $_lc->height;
        $_lLabel = (int) $_lc->width . '×' . (int) $_lc->height . 'px';
    } else {
        $_lKey   = 'unknown';
        $_lLabel = 'Other';
    }
    if (!isset($_lusterGrouped[$_lKey])) {
        $_lusterGrouped[$_lKey] = ['label' => $_lLabel, 'codes' => []];
    }
    $_lusterGrouped[$_lKey]['codes'][] = $_lc;
}
uksort($_lusterGrouped, function (string $a, string $b): int {
    if ($a === 'unknown') return 1;
    if ($b === 'unknown') return -1;
    [$aw, $ah] = explode('x', $a);
    [$bw, $bh] = explode('x', $b);
    return $aw !== $bw ? (int) $aw - (int) $bw : (int) $ah - (int) $bh;
});

// ---------------------------------------------------------------------------
// 4. Render
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim($_lusterApp->getBaseUrl(), '/');
$_lusterUploadsUrl = $_lusterBaseUrl . '/uploads/codes';

$_lusterHtml = $_lusterApp->getView()->renderFragment('codes.php', [
    'listing'      => $_lusterListing,
    'listing_name' => $_lusterListing->name,
    'listing_url'  => $_lusterListing->url,
    'page_title'   => 'Codes',
    'codes'        => $_lusterCodes,
    'donated'      => $_lusterDonated,
    'grouped'      => $_lusterGrouped,
    'categories'   => $_lusterCategories,
    'cat_map'      => $_lusterCatMap,
    'category_map' => $_lusterCategoryMap,
    'donors'       => $_lusterDonors,
    'donor_map'    => $_lusterDonorMap,
    'uploads_url'  => $_lusterUploadsUrl,
    'join_url'     => $_lusterBaseUrl . '/join/' . $_lusterListingId,
    'base_url'     => $_lusterBaseUrl,
    'theme_path'   => $_lusterBaseUrl . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
