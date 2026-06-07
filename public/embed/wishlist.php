<?php

declare(strict_types=1);

use Luster\Addons\Wishlist\WishlistRepo;
use Luster\Core\TemplateEngine;
use Luster\Repositories\SiteSettingsRepo;

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
// 4. Apply wishlist_template (if set) via TemplateEngine
// ---------------------------------------------------------------------------

$_wishlistTemplateHtml = null;
$_wishlistTemplate     = (new SiteSettingsRepo($_app->getDatabase()))->get('wishlist_template');

if ($_wishlistTemplate !== null && trim($_wishlistTemplate) !== '') {
    $_uploadsUrl = rtrim($_app->getBaseUrl(), '/') . '/uploads/wishlist';

    $_wishlistTemplateHtml = TemplateEngine::loop(
        $_wishlistTemplate,
        'wishlist_item',
        $_entries,
        static function (string $inner, object $entry) use ($_uploadsUrl): string {
            $imageUrl = ($entry->image !== null && $entry->image !== '')
                ? $_uploadsUrl . '/' . $entry->image
                : '';

            return TemplateEngine::render(
                $inner,
                [
                    'item_subject'     => $entry->subject,
                    'item_url'         => $entry->url,
                    'item_type'        => $entry->type,
                    'item_type_label'  => $entry->typeLabel(),
                    'item_category'    => $entry->category ?? '',
                    'item_description' => $entry->description ?? '',
                    'item_image_url'   => $imageUrl,
                ],
                [],
                [
                    'item_url'          => $entry->url !== '',
                    'no_item_url'       => $entry->url === '',
                    'item_category'     => ($entry->category ?? '') !== '',
                    'item_description'  => $entry->description !== null && $entry->description !== '',
                    'item_image'        => $imageUrl !== '',
                    'type_top'          => $entry->type === 'top',
                    'type_wanted'       => $entry->type === 'wanted',
                    'type_granted'      => $entry->type === 'granted',
                ],
            );
        },
    );
}

// ---------------------------------------------------------------------------
// 5. Render
// ---------------------------------------------------------------------------

$_lusterHtml = $_app->getView()->renderFragment('wishlist.php', [
    'entries'               => $_entries,
    'filter_type'           => $_filterType,
    'base_url'              => $_app->getBaseUrl(),
    'wishlist_template_html'=> $_wishlistTemplateHtml,
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
