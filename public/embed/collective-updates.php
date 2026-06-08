<?php

declare(strict_types=1);

use Luster\Addons\Updates\UpdateRepo;
use Luster\Core\Pagination;
use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\SiteSettingsRepo;

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
// 4. Build feed URL and pagination
// ---------------------------------------------------------------------------

$_lusterFeedUrl   = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/';
$_lusterSiteRepo  = new SiteSettingsRepo($_lusterDb);
$_lusterPaginationTemplate = (string) ($_lusterSiteRepo->get('collective_pagination_template') ?? '');
$_lusterPagination = Pagination::render(
    $_lusterPage, $_lusterPages, $_lusterFeedUrl, $_lusterPaginationTemplate,
    $_lusterCat !== '' ? ['cat' => $_lusterCat] : [],
);

// ---------------------------------------------------------------------------
// 4b. Apply collective_updates_template (if set) via TemplateEngine
// ---------------------------------------------------------------------------

$_lusterUpdatesTemplateHtml = null;
$_lusterUpdatesTemplate     = $_lusterSiteRepo->get('collective_updates_template');

if ($_lusterUpdatesTemplate !== null && trim($_lusterUpdatesTemplate) !== '') {
    $_lusterUpdatesTemplateHtml = TemplateEngine::loop(
        $_lusterUpdatesTemplate,
        'update',
        $_lusterUpdates,
        static function (string $inner, object $update): string {
            return TemplateEngine::render(
                $inner,
                [
                    'update_title'      => $update->title,
                    'update_date'       => date('F j, Y', strtotime($update->postedAt)),
                    'update_date_raw'   => substr($update->postedAt, 0, 10),
                    'update_author'     => $update->author ?? '',
                    'update_categories' => implode(', ', $update->categories()),
                ],
                [
                    'update_body_html' => nl2br(View::e($update->body)),
                ],
                [
                    'author'     => ($update->author ?? '') !== '',
                    'categories' => count($update->categories()) > 0,
                ],
            );
        },
    );
}

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('addons/updates/feed.php', [
    'updates'               => $_lusterUpdates,
    'total'                 => $_lusterTotal,
    'page'                  => $_lusterPage,
    'pages'                 => $_lusterPages,
    'is_collective'         => true,
    'show_cats'             => $_lusterShowCats,
    'owner_name'            => (string) ($_lusterConfig->get('owner_name', '')),
    'active_cat'            => $_lusterCat,
    'feed_url'              => $_lusterFeedUrl,
    'pagination'            => $_lusterPagination,
    'updates_template_html' => $_lusterUpdatesTemplateHtml,
    'base_url'              => $_lusterApp->getBaseUrl(),
    'theme_path'            => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
