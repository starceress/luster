<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable collective newsfeed. Renders the site-wide newsfeed with
 pagination, using the admin-editable collective news template. Requires
 the Newsfeed add-on to be installed. Each entry can optionally show category
 tags. The feed only includes entries where fanlisting_id IS NULL (collective
 scope).
*/

declare(strict_types=1);

use Luster\Addons\Newsfeed\NewsfeedRepo;
use Luster\Core\Pagination;
use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\SiteSettingsRepo;

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
$luster_attribution = \Luster\Core\View::attribution();

// ---------------------------------------------------------------------------
// 2. Resolve optional parameters
// ---------------------------------------------------------------------------

$_lusterConfig   = $_lusterApp->getConfig();
// Fixed default (20), not the admin panel's own `per_page` config value —
// that key controls admin list-view pagination and is unrelated to the
// public site (see public/embed/members.php for the same reasoning).
$_lusterPerPage  = isset($luster_per_page)  ? max(1, (int) $luster_per_page)  : 20;
$_lusterShowCats = isset($luster_show_cats) ? (bool) $luster_show_cats : true;
$_lusterPage     = max(1, (int) ($_GET['page'] ?? 1));
$_lusterCat      = trim((string) ($_GET['cat'] ?? ''));

// ---------------------------------------------------------------------------
// 3. Load newsfeed entries
// ---------------------------------------------------------------------------

$_lusterDb = $_lusterApp->getDatabase();

try {
    $_lusterNewsfeedRepo = new NewsfeedRepo($_lusterDb);
    if ($_lusterCat !== '') {
        $_lusterUpdates = $_lusterNewsfeedRepo->getCollectiveByCategory($_lusterCat, $_lusterPage, $_lusterPerPage);
        $_lusterTotal   = $_lusterNewsfeedRepo->countCollectiveByCategory($_lusterCat);
    } else {
        $_lusterUpdates = $_lusterNewsfeedRepo->getCollective($_lusterPage, $_lusterPerPage);
        $_lusterTotal   = $_lusterNewsfeedRepo->countCollective();
    }
    $_lusterPages = max(1, (int) ceil($_lusterTotal / $_lusterPerPage));
} catch (\Throwable $_lusterEx) {
    // Newsfeed add-on not installed or table missing — return empty output silently.
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
// 4b. Apply collective_newsfeed_template (if set) via TemplateEngine
// ---------------------------------------------------------------------------

$_lusterNewsfeedTemplateHtml = null;
$_lusterNewsfeedTemplate     = $_lusterSiteRepo->get('collective_newsfeed_template');

if ($_lusterNewsfeedTemplate !== null && trim($_lusterNewsfeedTemplate) !== '') {
    $_lusterNewsfeedTemplate = str_replace('{site_name}', htmlspecialchars((string) $_lusterApp->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $_lusterNewsfeedTemplate);
    $tplForLoop = TemplateEngine::conditionals($_lusterNewsfeedTemplate, [
        'newsfeed'    => count($_lusterUpdates) > 0,
        'no_newsfeed' => count($_lusterUpdates) === 0,
    ]);

    $_lusterNewsfeedTemplateHtml = TemplateEngine::loop(
        $tplForLoop,
        'newsfeed_entry',
        $_lusterUpdates,
        static function (string $inner, object $entry): string {
            return TemplateEngine::render(
                $inner,
                [
                    'newsfeed_title'      => $entry->title,
                    'newsfeed_date'       => date('F j, Y', strtotime($entry->postedAt)),
                    'newsfeed_author'     => $entry->author ?? '',
                    'newsfeed_categories' => implode(', ', $entry->categories()),
                ],
                [
                    'newsfeed_body_html' => nl2br(View::e($entry->body)),
                ],
                [
                    'author'     => ($entry->author ?? '') !== '',
                    'categories' => count($entry->categories()) > 0,
                ],
            );
        },
    );
}

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('addons/newsfeed/feed.php', [
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
    'newsfeed_template_html' => $_lusterNewsfeedTemplateHtml,
    'base_url'              => $_lusterApp->getBaseUrl(),
    'theme_path'            => $_lusterApp->getBaseUrl() . '/assets/default/',
]);


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
