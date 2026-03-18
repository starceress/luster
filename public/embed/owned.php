<?php

declare(strict_types=1);

use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Luster embed — owned listings list.
 *
 * Drop this into any PHP page to embed the list of listings you own.
 * Supports status filtering, category filtering, and two selector display modes.
 *
 * Usage:
 *   $luster_return = false;                  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/owned.php';
 *
 * Optional variables:
 *   $luster_status            string  'current' | 'upcoming' | 'trouble' | 'all'
 *                                     Default: 'current'
 *   $luster_exclude_trouble   bool    When true and $luster_status is 'all', trouble /
 *                                     inactive listings are silently omitted so they
 *                                     never appear in a general listing. Has no effect
 *                                     when $luster_status is any other value.
 *                                     Default: true
 *   $luster_category_id       int     Luster category ID to pre-filter to (0 = all).
 *                                     When 0, reads $_GET['cat'] so the built-in
 *                                     category selector works without extra PHP.
 *                                     Default: 0 (reads $_GET['cat'])
 *   $luster_hide_dropdown     bool    Set true to suppress the category selector entirely.
 *                                     Default: false
 *   $luster_show_list         bool    Set true to display categories as a bulleted link
 *                                     list instead of a <select> dropdown.
 *                                     Default: false
 *
 * Category selector behaviour:
 *   The selector submits to the current page (PHP_SELF) so it works without any
 *   additional routing. The selected category ID is passed as $_GET['cat'].
 *   Set $luster_hide_dropdown = true if you want to filter a specific category
 *   without showing the selector at all.
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
// 2. Resolve parameters
// ---------------------------------------------------------------------------

$_lusterValidStatuses = ['current', 'upcoming', 'trouble', 'all'];
$_lusterStatus        = in_array($luster_status ?? 'current', $_lusterValidStatuses, true)
                      ? (string) ($luster_status ?? 'current')
                      : 'current';

$_lusterExcludeTrouble = (bool) ($luster_exclude_trouble ?? true);
$_lusterHideDropdown   = (bool) ($luster_hide_dropdown ?? false);
$_lusterShowList       = (bool) ($luster_show_list ?? false);

// Category ID: prefer explicit $luster_category_id; fall back to $_GET['cat']
$_lusterCatId = isset($luster_category_id) && (int) $luster_category_id > 0
              ? (int) $luster_category_id
              : max(0, (int) ($_GET['cat'] ?? 0));

// ---------------------------------------------------------------------------
// 3. Load listings and categories
// ---------------------------------------------------------------------------

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());

$_lusterListings = $_lusterListingRepo->getFiltered(
    $_lusterStatus,
    $_lusterCatId > 0 ? $_lusterCatId : null,
    $_lusterExcludeTrouble,
);

$_lusterCategories = $_lusterListingRepo->getCategoryList();

// ---------------------------------------------------------------------------
// 3b. Load owned listing row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSiteRepo      = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterOwnedTemplate = $_lusterSiteRepo->get('owned_template');
if ($_lusterOwnedTemplate !== null && trim($_lusterOwnedTemplate) === '') {
    $_lusterOwnedTemplate = null;
}

// Build per-listing HTML when a custom template is set
$_lusterListingHtml = null;
if ($_lusterOwnedTemplate !== null) {
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $statusLabels = ['current' => 'Current', 'upcoming' => 'Upcoming', 'trouble' => 'Trouble'];
    $rows = '';
    foreach ($_lusterListings as $_fl) {
        $statusLabel = $statusLabels[$_fl->status ?? 'current'] ?? 'Current';
        $rows .= str_replace(
            ['{listing_name}', '{listing_url}', '{listing_subject}', '{listing_status}'],
            [$e($_fl->name), $e($_fl->url), $e($_fl->subject ?? ''), $e($statusLabel)],
            $_lusterOwnedTemplate,
        );
    }
    $_lusterListingHtml = $rows;
}

// ---------------------------------------------------------------------------
// 4. Build form action URL (current page so the selector posts back here)
// ---------------------------------------------------------------------------

$_lusterFormAction = htmlspecialchars(
    strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('owned.php', [
    'listings'      => $_lusterListings,
    'listing_html'  => $_lusterListingHtml,
    'categories'       => $_lusterCategories,
    'current_category' => $_lusterCatId,
    'status'           => $_lusterStatus,
    'hide_dropdown'    => $_lusterHideDropdown,
    'show_list'        => $_lusterShowList,
    'uploads_url'      => $_lusterApp->getBaseUrl() . '/uploads',
    'base_url'         => $_lusterApp->getBaseUrl(),
    'form_action'      => $_lusterFormAction,
    'theme_path'       => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
