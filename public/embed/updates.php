<?php

declare(strict_types=1);

use Luster\Addons\Updates\UpdateRepo;
use Luster\Core\Pagination;
use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingTemplateResolver;

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
$_lusterCat      = trim((string) ($_GET['cat'] ?? ''));

// ---------------------------------------------------------------------------
// 5. Load updates
// ---------------------------------------------------------------------------

try {
    $_lusterUpdateRepo = new UpdateRepo($_lusterDb);
    if ($_lusterCat !== '') {
        $_lusterUpdates = $_lusterUpdateRepo->getForFanlistingByCategory($_lusterFid, $_lusterCat, $_lusterPage, $_lusterPerPage);
        $_lusterTotal   = $_lusterUpdateRepo->countForFanlistingByCategory($_lusterFid, $_lusterCat);
    } else {
        $_lusterUpdates = $_lusterUpdateRepo->getForFanlisting($_lusterFid, $_lusterPage, $_lusterPerPage);
        $_lusterTotal   = $_lusterUpdateRepo->countForFanlisting($_lusterFid);
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
// 6. Build feed URL and pagination
// ---------------------------------------------------------------------------

$_lusterFeedUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/';

$_lusterSettingsRepo       = new ListingSettingsRepo($_lusterDb);
$_lusterPaginationTemplate = $_lusterSettingsRepo->getPaginationTemplate($_lusterFid) ?? '';
$_lusterPagination         = Pagination::render(
    $_lusterPage, $_lusterPages, $_lusterFeedUrl, $_lusterPaginationTemplate,
    $_lusterCat !== '' ? ['cat' => $_lusterCat] : [],
);

// ---------------------------------------------------------------------------
// 6b. Apply updates row template (if set) via TemplateEngine
// ---------------------------------------------------------------------------

$_lusterUpdatesTemplateHtml = null;
$_lusterGlobalTpls          = (new SiteSettingsRepo($_lusterDb))->getGlobalTemplates();
$_lusterUpdResolved         = ListingTemplateResolver::resolve(
    $_lusterSettingsRepo->getUpdatesTemplate($_lusterFid),
    $_lusterGlobalTpls['listing_default_updates_template'] ?? null,
    ListingSettingsRepo::defaultUpdatesTemplate(),
);
$_lusterUpdatesTemplate = $_lusterUpdResolved['should_render_template'] ? $_lusterUpdResolved['value'] : null;

if ($_lusterUpdatesTemplate !== null) {
    // Apply listing context tokens to the template SHELL before loop rendering
    // so that user-authored update body text cannot accidentally match {listing_subject}.
    $tplForLoop = $_lusterFid !== null
        ? str_replace(
            ['{listing_title}', '{listing_subject}'],
            [
                htmlspecialchars((string) $_lusterListing->title,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars((string) $_lusterListing->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ],
            $_lusterUpdatesTemplate,
        )
        : $_lusterUpdatesTemplate;

    // Process page-level conditionals before the per-update loop.
    $tplForLoop = TemplateEngine::conditionals($tplForLoop, [
        'updates'    => count($_lusterUpdates) > 0,
        'no_updates' => count($_lusterUpdates) === 0,
    ]);

    $_lusterUpdatesTemplateHtml = TemplateEngine::loop(
        $tplForLoop,
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
// 7. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('addons/updates/feed.php', [
    'updates'               => $_lusterUpdates,
    'total'                 => $_lusterTotal,
    'page'                  => $_lusterPage,
    'pages'                 => $_lusterPages,
    'is_collective'         => false,
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
