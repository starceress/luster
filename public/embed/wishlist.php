<?php

declare(strict_types=1);

use Luster\Addons\Wishlist\WishlistRepo;
use Luster\Core\Pagination;
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
 *   $luster_per_page       = 25;       // optional: entries per page
 *   require '/absolute/path/to/luster/public/embed/wishlist.php';
 *
 * Optional variables:
 *   $luster_wishlist_type  string  Filter displayed entries by type.
 *                                  Accepted values: 'all', 'wanted', 'top', 'granted'
 *                                  'all' (default) shows every active entry grouped by type.
 *                                  Any unrecognized value silently falls back to 'all'.
 *   $luster_per_page       int     Entries per page, counted across all categories
 *                                  combined even when showing 'all'. Default 25.
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
// 3. Load entries (active only for public embed), paginated
// ---------------------------------------------------------------------------

$_perPage = isset($luster_per_page) ? max(1, (int) $luster_per_page) : 25;
$_page    = max(1, (int) ($_GET['page'] ?? 1));

$_repo   = new WishlistRepo($_app->getDatabase());
$_result = $_repo->paginate(
    $_filterType !== 'all' ? $_filterType : null,
    true,
    '', '',
    'sort_order', 'asc',
    $_perPage, $_page,
);
$_entries = $_result['items'];
$_total   = $_result['total'];
$_pages   = max(1, (int) ceil($_total / $_perPage));

$_baseUrl            = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/';
$_siteSettings       = new SiteSettingsRepo($_app->getDatabase());
$_paginationTemplate = (string) ($_siteSettings->get('collective_pagination_template') ?? '');
$_pagination         = Pagination::render($_page, $_pages, $_baseUrl, $_paginationTemplate);

// ---------------------------------------------------------------------------
// 4. Apply wishlist_template (if set) via TemplateEngine
// ---------------------------------------------------------------------------

$_wishlistTemplateHtml = null;
$_wishlistTemplate     = $_siteSettings->get('wishlist_template');

if ($_wishlistTemplate !== null && trim($_wishlistTemplate) !== '') {
    $_uploadsUrl = rtrim($_app->getBaseUrl(), '/') . '/uploads/wishlist';

    $_wishlistRowFn = static function (string $inner, object $entry) use ($_uploadsUrl): string {
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
    };

    // Type-scoped groups, drawn from this page's entries only — same
    // pagination caveat as the built-in grouped layout in wishlist.php.
    $_topEntries     = array_values(array_filter($_entries, static fn(object $e): bool => $e->type === 'top'));
    $_wantedEntries  = array_values(array_filter($_entries, static fn(object $e): bool => $e->type === 'wanted'));
    $_grantedEntries = array_values(array_filter($_entries, static fn(object $e): bool => $e->type === 'granted'));

    // Resolve {if_has_*} on the shell before any row HTML is substituted in
    // (loops are processed afterward), mirroring
    // ListingRowRenderer::renderTemplate()'s shell-first ordering.
    $_wishlistTemplateHtml = TemplateEngine::conditionals($_wishlistTemplate, [
        'has_top'     => $_topEntries     !== [],
        'has_wanted'  => $_wantedEntries  !== [],
        'has_granted' => $_grantedEntries !== [],
    ]);

    $_wishlistTemplateHtml = TemplateEngine::loop($_wishlistTemplateHtml, 'wishlist_item',         $_entries,        $_wishlistRowFn);
    $_wishlistTemplateHtml = TemplateEngine::loop($_wishlistTemplateHtml, 'wishlist_item_top',     $_topEntries,     $_wishlistRowFn);
    $_wishlistTemplateHtml = TemplateEngine::loop($_wishlistTemplateHtml, 'wishlist_item_wanted',  $_wantedEntries,  $_wishlistRowFn);
    $_wishlistTemplateHtml = TemplateEngine::loop($_wishlistTemplateHtml, 'wishlist_item_granted', $_grantedEntries, $_wishlistRowFn);
}

// ---------------------------------------------------------------------------
// 5. Render
// ---------------------------------------------------------------------------

$_lusterHtml = $_app->getView()->renderFragment('wishlist.php', [
    'entries'               => $_entries,
    'filter_type'           => $_filterType,
    'base_url'              => $_app->getBaseUrl(),
    'wishlist_template_html'=> $_wishlistTemplateHtml,
    'pagination'            => $_pagination,
    'page'                  => $_page,
    'pages'                 => $_pages,
    'total'                 => $_total,
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
