<?php

declare(strict_types=1);

use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\CodeCategoriesRepo;
use Luster\Repositories\CodeDonorsRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

/**
 * Luster embed — codes fragment.
 *
 * Usage in your own page:
 *   $luster_fanlisting_id = 1;     // listing ID; omit or set to 0 for collective codes
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/codes.php';
 *
 * Renders the codes as a content fragment grouped by detected dimensions,
 * with no layout wrapper. Set $luster_fanlisting_id = 0 (or leave unset) for
 * collective-wide codes where fanlisting_id IS NULL in lstr_codes.
 */

// ---------------------------------------------------------------------------
// 1. Resolve listing ID / collective mode
// ---------------------------------------------------------------------------

$_lusterRawListingId = isset($luster_fanlisting_id) ? (int) $luster_fanlisting_id : 0;
$_lusterIsCollective = ($_lusterRawListingId === 0);
$_lusterListingId    = $_lusterIsCollective ? null : $_lusterRawListingId;

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
// 3. Load listing (or collective identity)
// ---------------------------------------------------------------------------

$_lusterDb = $_lusterApp->getDatabase();

if ($_lusterIsCollective) {
    $_lusterListing     = null;
    $_lusterListingName = (string) ($_lusterApp->getConfig()->get('app_name', '') ?: 'Collective');
    $_lusterListingUrl  = '';
} else {
    $_lusterListingRepo = new ListingRepo($_lusterDb, $_lusterApp->getConfig());
    $_lusterListing     = $_lusterListingRepo->getById($_lusterListingId);

    if ($_lusterListing === null) {
        $_lusterHtml = '<p class="fl-errors">Luster embed error: listing #' . $_lusterListingId . ' not found.</p>';
        if ($luster_return ?? false) {
            return $_lusterHtml;
        }
        echo $_lusterHtml;
        return;
    }

    $_lusterListingName = $_lusterListing->title;
    $_lusterListingUrl  = $_lusterListing->url;
}

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

// Group all codes by detected dimensions (donated codes sorted in with the rest)
$_lusterGrouped = [];
foreach ($_lusterCodes as $_lc) {
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

// ---------------------------------------------------------------------------
// 4a. Apply codes row template (if set) via TemplateEngine
// ---------------------------------------------------------------------------

$_lusterCodesTemplateHtml = null;

if (!$_lusterIsCollective && $_lusterListingId !== null) {
    $_lusterCodesTemplate = (new ListingSettingsRepo($_lusterDb))->getCodesTemplate($_lusterListingId);

    if ($_lusterCodesTemplate !== null && trim($_lusterCodesTemplate) !== '') {
        // Shared per-code renderer — resolves donor info, size label, categories,
        // and builds the escaped + conditions maps for TemplateEngine::render().
        $_lusterRenderCode = static function (
            string $inner,
            object $code,
            bool   $sizeChanged,
            array  $catMap,
            array  $categoryMap,
            array  $donorMap,
            string $uploadsUrl,
            string $listingName,
        ): string {
            $dnrObj    = ($code->donorId !== null) ? ($donorMap[$code->donorId] ?? null) : null;
            $donorName = $code->submitterName ?? ($dnrObj !== null ? $dnrObj->name : null);
            $donorUrl  = '';
            if (($code->submitterUrl ?? '') !== '') {
                $donorUrl = (string) $code->submitterUrl;
            } elseif ($dnrObj !== null && ($dnrObj->url ?? '') !== '') {
                $donorUrl = (string) $dnrObj->url;
            }

            $hasSize   = $code->width > 0 && $code->height > 0;
            $sizeLabel = $hasSize ? ((int) $code->width . '×' . (int) $code->height) : '';
            $altText   = $donorName !== null
                ? 'Donated by ' . $donorName
                : ($sizeLabel !== '' ? $sizeLabel : $listingName . ' code');

            $catIds   = $catMap[$code->id] ?? [];
            $catNames = [];
            foreach ($catIds as $catId) {
                if (isset($categoryMap[$catId])) {
                    $catNames[] = $categoryMap[$catId]->name;
                }
            }

            return TemplateEngine::render(
                $inner,
                [
                    'code_url'        => $uploadsUrl . '/' . $code->filename,
                    'code_alt'        => $altText,
                    'code_width'      => $hasSize ? (string) (int) $code->width : '',
                    'code_height'     => $hasSize ? (string) (int) $code->height : '',
                    'code_size'       => $sizeLabel,
                    'code_name'       => (string) ($code->name ?? ''),
                    'code_categories' => implode(', ', $catNames),
                    'code_donor_name' => (string) ($donorName ?? ''),
                    'code_donor_url'  => $donorUrl,
                ],
                [],
                [
                    'size'         => $hasSize,
                    'size_changed' => $sizeChanged,
                    'donor'        => $donorName !== null,
                    'no_donor'     => $donorName === null,
                    'donor_url'    => $donorUrl !== '',
                    'no_donor_url' => $donorUrl === '',
                    'categories'   => count($catNames) > 0,
                    'code_name'    => ($code->name ?? '') !== '',
                ],
            );
        };

        if (str_contains($_lusterCodesTemplate, '{each_size_group}')) {
            // Grouped mode: outer {each_size_group} with inner {each_code}
            $_lusterCodesTemplateHtml = TemplateEngine::loop(
                $_lusterCodesTemplate,
                'size_group',
                array_values($_lusterGrouped),
                static function (string $innerGroupTpl, array $group) use (
                    $_lusterRenderCode,
                    $_lusterCatMap,
                    $_lusterCategoryMap,
                    $_lusterDonorMap,
                    $_lusterUploadsUrl,
                    $_lusterListingName,
                ): string {
                    $processed = TemplateEngine::tokens($innerGroupTpl, [
                        'group_size'       => $group['label'],
                        'group_code_count' => (string) count($group['codes']),
                    ]);
                    return TemplateEngine::loop(
                        $processed,
                        'code',
                        $group['codes'],
                        static fn(string $inner, object $code): string => $_lusterRenderCode(
                            $inner, $code, false,
                            $_lusterCatMap, $_lusterCategoryMap, $_lusterDonorMap,
                            $_lusterUploadsUrl, $_lusterListingName,
                        ),
                    );
                },
            );
        } elseif (str_contains($_lusterCodesTemplate, '{each_code}')) {
            // Flat mode: single {each_code} loop with {if_size_changed} tracking
            $_lusterPrevSizeKey = null;
            $_lusterCodesTemplateHtml = TemplateEngine::loop(
                $_lusterCodesTemplate,
                'code',
                $_lusterCodes,
                static function (string $inner, object $code) use (
                    &$_lusterPrevSizeKey,
                    $_lusterRenderCode,
                    $_lusterCatMap,
                    $_lusterCategoryMap,
                    $_lusterDonorMap,
                    $_lusterUploadsUrl,
                    $_lusterListingName,
                ): string {
                    $sizeKey            = ($code->width > 0 && $code->height > 0)
                        ? ((int) $code->width . 'x' . (int) $code->height)
                        : 'unknown';
                    $sizeChanged        = $sizeKey !== $_lusterPrevSizeKey;
                    $_lusterPrevSizeKey = $sizeKey;
                    return $_lusterRenderCode(
                        $inner, $code, $sizeChanged,
                        $_lusterCatMap, $_lusterCategoryMap, $_lusterDonorMap,
                        $_lusterUploadsUrl, $_lusterListingName,
                    );
                },
            );
        }
    }
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('codes.php', [
    'listing'             => $_lusterListing,
    'listing_name'        => $_lusterListingName,
    'listing_url'         => $_lusterListingUrl,
    'page_title'          => 'Codes',
    'codes'               => $_lusterCodes,
    'grouped'             => $_lusterGrouped,
    'categories'          => $_lusterCategories,
    'cat_map'             => $_lusterCatMap,
    'category_map'        => $_lusterCategoryMap,
    'donors'              => $_lusterDonors,
    'donor_map'           => $_lusterDonorMap,
    'uploads_url'         => $_lusterUploadsUrl,
    'join_url'            => $_lusterIsCollective ? '' : $_lusterBaseUrl . '/join/' . $_lusterListingId,
    'base_url'            => $_lusterBaseUrl,
    'theme_path'          => $_lusterBaseUrl . '/assets/default/',
    'codes_template_html' => $_lusterCodesTemplateHtml,
]);

// Conditionally append the code donation form if enabled.
// Set $luster_append_submit = false before require to suppress this (e.g. when you
// have a dedicated codes-submit page and don't want the form duplicated here).
// NOTE: codes-submit.php is required in the same scope and reassigns $_lusterHtml,
// so we save the gallery output and restore $luster_return after the sub-require.
$_lusterEmbedSettings = $_lusterIsCollective
    ? []
    : (new \Luster\Repositories\ListingSettingsRepo($_lusterApp->getDatabase()))
        ->getEmbedSettings($_lusterListingId);
if (($luster_append_submit ?? true) && ($_lusterEmbedSettings['codes_submit_enabled'] ?? true)) {
    $_lusterGalleryHtml  = $_lusterHtml;
    $_lusterReturnOrig   = $luster_return ?? false;
    $luster_return       = true;   // make codes-submit.php return, not echo
    $_lusterSubmitHtml   = (string) require __DIR__ . '/codes-submit.php';
    $_lusterHtml         = $_lusterGalleryHtml . $_lusterSubmitHtml;
    $luster_return       = $_lusterReturnOrig; // restore so the outer embed behaves correctly
}

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
