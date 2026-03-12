<?php

declare(strict_types=1);

use Luster\Repositories\JoinedFanlistingRepo;

/**
 * Luster embed — joined fanlistings list.
 *
 * Drop this into any PHP page to embed the list of fanlistings you have joined.
 * Supports category filtering and two selector display modes.
 *
 * Usage:
 *   $luster_return = false;                  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/joined.php';
 *
 * Optional variables:
 *   $luster_category    string  Controls what is displayed:
 *                               ''    — show the category selector; visitor picks a category
 *                                       (reads $_GET['cat'] automatically, no PHP needed)
 *                               'all' — show ALL active fanlistings grouped by category
 *                                       heading, no selector rendered (best for small lists)
 *                               other — exact category name; show only that category's
 *                                       fanlistings, selector suppressed
 *                               Default: '' (reads $_GET['cat'])
 *   $luster_show_list   bool    When the selector is visible, show category links as a
 *                               bulleted list instead of a <select> dropdown.
 *                               Has no effect when $luster_category is 'all' or a fixed name.
 *                               Default: false
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

$_lusterShowList = (bool) ($luster_show_list ?? false);

// Category: prefer explicit $luster_category; fall back to $_GET['cat']
$_lusterCategory = isset($luster_category) && (string) $luster_category !== ''
                 ? (string) $luster_category
                 : (string) ($_GET['cat'] ?? '');

// ---------------------------------------------------------------------------
// 3. Load fanlistings and categories
// ---------------------------------------------------------------------------

$_lusterRepo = new JoinedFanlistingRepo($_lusterApp->getDatabase());

$_lusterCategories = $_lusterRepo->getCategories();

if ($_lusterCategory === 'all') {
    // Show-all mode: load every active fanlisting and group by category
    $result        = $_lusterRepo->getAll(perPage: 9999, page: 1);
    $_lusterGrouped = [];
    foreach ($result['items'] as $fl) {
        if (!$fl->active) {
            continue;
        }
        $cat = $fl->category !== '' ? $fl->category : 'Uncategorized';
        $_lusterGrouped[$cat][] = $fl;
    }
    ksort($_lusterGrouped);
    $_lusterFanlistings = [];  // not used in grouped mode
} elseif ($_lusterCategory !== '') {
    // Single-category mode
    $result             = $_lusterRepo->getAll(perPage: 9999, page: 1, filterCat: $_lusterCategory);
    $_lusterFanlistings = array_values(array_filter(
        $result['items'],
        static fn($fl) => $fl->active,
    ));
    $_lusterGrouped = [];
} else {
    // Selector mode: no category chosen yet
    $_lusterFanlistings = [];
    $_lusterGrouped     = [];
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

$_lusterHtml = $_lusterApp->getView()->renderFragment('joined.php', [
    'fanlistings'      => $_lusterFanlistings,
    'grouped'          => $_lusterGrouped,
    'categories'       => $_lusterCategories,
    'current_category' => $_lusterCategory,
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
