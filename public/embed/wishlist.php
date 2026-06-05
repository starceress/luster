<?php

declare(strict_types=1);

use Luster\Addons\Wishlist\WishlistRepo;

/**
 * Luster embed — wishlist.
 *
 * Drop this into any PHP page to embed your fanlisting wishlist.
 * The wishlist is collective-wide (not per-listing).
 *
 * Usage:
 *   $luster_return         = false;    // optional: true = return string, false = echo
 *   $luster_wishlist_type  = 'all';    // optional: filter by type (see below)
 *   require '/absolute/path/to/luster/public/embed/wishlist.php';
 *
 * Optional variables:
 *   $luster_wishlist_type  string  Filter displayed entries by type.
 *                                  Accepted values: 'all', 'wanted', 'top', 'granted'
 *                                  'all' (default) shows every active entry grouped by type.
 *                                  Any unrecognized value silently falls back to 'all'.
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

$_app = require $_lusterRoot . '/bootstrap.php';
$_app->getSession()->start();

// ---------------------------------------------------------------------------
// 2. Resolve parameters
// ---------------------------------------------------------------------------

$_validTypes   = ['wanted', 'top', 'granted'];
$_filterType   = (string) ($luster_wishlist_type ?? 'all');
if ($_filterType !== 'all' && !in_array($_filterType, $_validTypes, true)) {
    $_filterType = 'all';
}

// ---------------------------------------------------------------------------
// 3. Load entries (active only for public embed)
// ---------------------------------------------------------------------------

$_repo    = new WishlistRepo($_app->getDatabase());
$_entries = $_repo->getAll($_filterType !== 'all' ? $_filterType : null, true);

// ---------------------------------------------------------------------------
// 4. Render
// ---------------------------------------------------------------------------

$_lusterHtml = $_app->getView()->renderFragment('wishlist.php', [
    'entries'     => $_entries,
    'filter_type' => $_filterType,
    'base_url'    => $_app->getBaseUrl(),
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
