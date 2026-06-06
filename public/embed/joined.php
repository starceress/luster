<?php

declare(strict_types=1);

use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\PublicCategoryPresenter;

/**
 * Luster embed — joined listings list.
 *
 * Drop this into any PHP page to embed the list of listings you have joined.
 * Supports category filtering and two selector display modes.
 *
 * Usage:
 *   $luster_return = false;                  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/joined.php';
 *
 * Optional variables:
 *   $luster_category    string  Controls what is displayed:
 *                               ''    — show the category selector; visitor picks a category
 *                               'all' — show ALL active listings grouped by category
 *                               other — exact category name; show only that category's listings
 *                               Default: '' (reads $_GET['cat'])
 *   $luster_show_list           bool    Show category selector as bulleted list vs <select>.
 *                                       Default: false
 *   $luster_browse_by_category  bool    Browse-by-category mode: index view when no category selected.
 *                                       Default: false
 *   $luster_show_all_by_default bool    Show grouped listings AND the selector initially.
 *                                       Default: false
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

$_lusterShowList        = (bool) ($luster_show_list           ?? false);
$_lusterShowAllDefault  = (bool) ($luster_show_all_by_default  ?? false);
$_lusterBrowseByCat     = (bool) ($luster_browse_by_category   ?? false);

// Category: prefer explicit $luster_category; fall back to $_GET['cat']
$_lusterCategory = isset($luster_category) && (string) $luster_category !== ''
                 ? (string) $luster_category
                 : (string) ($_GET['cat'] ?? '');

// ---------------------------------------------------------------------------
// 3. Load listings and categories
// ---------------------------------------------------------------------------

$_lusterRepo = new JoinedListingRepo($_lusterApp->getDatabase());

$_lusterCategories       = $_lusterRepo->getCategories();
$_lusterCategoryCountMap = $_lusterRepo->getCategoryCountMap();
$_lusterTotalCount       = $_lusterRepo->countActive();

/**
 * Groups a flat result set of JoinedListings into [category => [...]] sorted by key.
 */
$_lusterGroupByCategory = static function (array $items): array {
    $grouped = [];
    foreach ($items as $fl) {
        if (!$fl->active) {
            continue;
        }
        $cat = $fl->category !== '' ? $fl->category : 'Uncategorized';
        $grouped[$cat][] = $fl;
    }
    ksort($grouped);
    return $grouped;
};

if ($_lusterCategory === 'all') {
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1);
    $_lusterGrouped  = $_lusterGroupByCategory($result['items']);
    $_lusterListings = [];
} elseif ($_lusterCategory !== '') {
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1, filterCat: $_lusterCategory);
    $_lusterListings = array_values(array_filter($result['items'], static fn($fl) => $fl->active));
    $_lusterGrouped  = [];
} elseif ($_lusterShowAllDefault) {
    $result          = $_lusterRepo->getAll(perPage: 9999, page: 1);
    $_lusterGrouped  = $_lusterGroupByCategory($result['items']);
    $_lusterListings = [];
} else {
    $_lusterListings = [];
    $_lusterGrouped  = [];
}

// ---------------------------------------------------------------------------
// 3b. Build the category navigator HTML via PublicCategoryPresenter
// ---------------------------------------------------------------------------

$_lusterFormAction = htmlspecialchars(
    strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/',
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

// Render the navigator in selector mode, show-all-default mode, or browse-by-category mode
// (browse mode needs the presenter even when a category is selected — it generates the back link)
$_lusterShowCategoryNav = ($_lusterCategory === ''
                        || ($_lusterShowAllDefault && $_lusterCategory === '')
                        || ($_lusterBrowseByCat && $_lusterCategory !== ''));
$_lusterShowListings    = true;
$_lusterCategoryHtml    = '';

if ($_lusterShowCategoryNav) {
    $_ljPresenter = PublicCategoryPresenter::renderJoined(
        $_lusterCategories,
        $_lusterCategoryCountMap,
        $_lusterTotalCount,
        $_lusterCategory,
        $_lusterFormAction,
        $_lusterShowList,
        $_lusterBrowseByCat,
    );
    $_lusterCategoryHtml = $_ljPresenter['html'];
    $_lusterShowListings = $_ljPresenter['show_listings'];
}

// ---------------------------------------------------------------------------
// 3c. Load joined listing row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSiteRepo       = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterJoinedTemplate = $_lusterSiteRepo->get('joined_template')
    ?? SiteSettingsRepo::collectiveDefaults()['joined_template'];
if (trim($_lusterJoinedTemplate) === '') {
    $_lusterJoinedTemplate = SiteSettingsRepo::collectiveDefaults()['joined_template'];
}

$_lusterListingHtml = null;
$_lusterSectionsMode = false;
if ($_lusterJoinedTemplate !== null) {
    $e                 = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/joined';
    $renderRow         = static function (object $fl) use ($_lusterJoinedTemplate, $e, $_lusterUploadsUrl): string {
        $imageTag = ($fl->image !== null && $fl->image !== '')
            ? '<img src="' . $e($_lusterUploadsUrl . '/' . $fl->image) . '" alt="' . $e($fl->name) . '" title="' . $e($fl->name) . '">'
            : '';
        return str_replace(
            ['{listing_title}', '{listing_url}', '{listing_category}', '{listing_image}', '{image}', '{date_joined}', '{listing_status}'],
            [$e($fl->name), $e($fl->url), $e($fl->category ?? ''), $imageTag, $imageTag, $e($fl->dateJoined ?? ''), $e($fl->status)],
            $_lusterJoinedTemplate,
        );
    };

    $_ljSectionsTplRaw = $_lusterSiteRepo->get('joined_sections_template');
    $_ljSectionsTpl    = $_ljSectionsTplRaw === null
        ? SiteSettingsRepo::collectiveDefaults()['joined_sections_template']
        : $_ljSectionsTplRaw;

    if ($_lusterCategory === 'all' && trim($_ljSectionsTpl) !== '' && isset($result)) {
        $_lusterSectionsMode = true;
        $_ljActive = array_values(array_filter($result['items'], static fn($fl) => $fl->active));

        $_ljActiveHtml = implode('', array_map($renderRow, $_ljActive));

        $_ljOut = (string) preg_replace(
            '/\{if_current_listings\}(.*?)\{\/if_current_listings\}/s',
            $_ljActiveHtml !== '' ? '$1' : '',
            $_ljSectionsTpl,
        );
        $_ljOut = str_replace(
            ['{current_content}', '{pending_content}',
             '{current_count}', '{pending_count}', '{total_count}'],
            [$_ljActiveHtml, '',
             (string) count($_ljActive), '0',
             (string) count($_ljActive)],
            $_ljOut,
        );
        $_lusterListingHtml = trim($_ljOut) !== '' ? $_ljOut : '';
    } elseif (!empty($_lusterGrouped)) {
        $rows = '';
        foreach ($_lusterGrouped as $catName => $items) {
            foreach ($items as $fl) {
                $rows .= $renderRow($fl);
            }
        }
        $_lusterListingHtml = $rows;
    } elseif (!empty($_lusterListings)) {
        $_lusterListingHtml = implode('', array_map($renderRow, $_lusterListings));
    }
}

// ---------------------------------------------------------------------------
// 4. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('joined.php', [
    'listings'           => $_lusterListings,
    'grouped'            => $_lusterGrouped,
    'listing_html'       => $_lusterListingHtml,
    'sections_mode'      => $_lusterSectionsMode,
    'category_html'      => $_lusterCategoryHtml,
    'show_listings'      => $_lusterShowListings,
    'categories'         => $_lusterCategories,
    'category_counts'    => $_lusterCategoryCountMap,
    'total_count'        => $_lusterTotalCount,
    'current_category'   => $_lusterCategory,
    'show_list'          => $_lusterShowList,
    'browse_by_category' => $_lusterBrowseByCat,
    'uploads_url'        => $_lusterApp->getBaseUrl() . '/uploads/joined',
    'base_url'           => $_lusterApp->getBaseUrl(),
    'form_action'        => $_lusterFormAction,
    'theme_path'         => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
