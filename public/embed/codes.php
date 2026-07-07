<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable fan codes (graphics/buttons) display. Renders approved codes for a
 single fanlisting or for the collective (when $luster_fanlisting_id is 0 or
 omitted). Codes are displayed using the admin-editable codes template, which
 supports both grouped-by-size and flat layout modes, with donor attribution
 and category filtering.
*/

declare(strict_types=1);

use Luster\Core\View;
use Luster\Repositories\CodeCategoriesRepo;
use Luster\Repositories\CodeDonorsRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\CodesTemplateRenderer;

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
    $_lusterListingName = (string) ($_lusterApp->getConfig()->get('site_name', '') ?: 'Collective');
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
$_lusterDonorsRepo  = new CodeDonorsRepo($_lusterDb);
$_lusterCodes       = $_lusterCodesRepo->getByListing($_lusterListingId, 'approved');
$_lusterDonors      = $_lusterDonorsRepo->getByListing($_lusterListingId);

// Build donor lookup: id => object
$_lusterDonorMap = [];
foreach ($_lusterDonors as $_d) {
    $_lusterDonorMap[$_d->id] = $_d;
}

// Load code categories
$_lusterCatRepo    = new CodeCategoriesRepo($_lusterDb);
$_lusterCategories = $_lusterCatRepo->getByListing($_lusterListingId);
$_lusterCatMap     = $_lusterCatRepo->getCategoryMapForListing($_lusterListingId);
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
// 4. Pagination (optional)
// ---------------------------------------------------------------------------

$_lusterPerPage    = isset($luster_per_page) ? max(1, (int) $luster_per_page) : 0;
$_lusterTotalCodes = count($_lusterCodes);
$_lusterPagHtml    = '';

if ($_lusterPerPage > 0 && $_lusterTotalCodes > $_lusterPerPage) {
    $_lusterPage      = max(1, (int) ($_GET['page'] ?? 1));
    $_lusterTotalPgs  = (int) ceil($_lusterTotalCodes / $_lusterPerPage);
    $_lusterPage      = min($_lusterPage, $_lusterTotalPgs);
    $_lusterCodes     = array_slice($_lusterCodes, ($_lusterPage - 1) * $_lusterPerPage, $_lusterPerPage);

    // Rebuild grouped from the paginated slice
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

    $_lusterPagBaseUrl = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '';
    $_lusterPagHtml    = \Luster\Core\Pagination::render($_lusterPage, $_lusterTotalPgs, $_lusterPagBaseUrl);
}

// ---------------------------------------------------------------------------
// 5. Render
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim($_lusterApp->getBaseUrl(), '/');
$_lusterUploadsUrl = $_lusterBaseUrl . '/uploads/codes';

// ---------------------------------------------------------------------------
// 4a. Apply codes display template (if set) via CodesTemplateRenderer
// ---------------------------------------------------------------------------

$_lusterCodesTemplateHtml = null;
$_lusterCodesTemplate     = null;

if ($_lusterIsCollective) {
    $_lusterCodesTemplate = (new SiteSettingsRepo($_lusterDb))->get('collective_codes_template');
} elseif ($_lusterListingId !== null) {
    $_lusterCodesResolved = \Luster\Support\ListingTemplateResolver::resolve(
        (new ListingSettingsRepo($_lusterDb))->getCodesTemplate($_lusterListingId),
        (new SiteSettingsRepo($_lusterDb))->get('listing_default_codes_template'),
        ListingSettingsRepo::defaultCodesTemplate(),
    );
    $_lusterCodesTemplate = $_lusterCodesResolved['should_render_template'] ? $_lusterCodesResolved['value'] : null;
}

// Extract {codes_submit_toggle} / {codes_submit_form} from raw template
// BEFORE rendering so user-supplied donor names or code titles can never
// match either token.
$_lusterCsToggleRendered = false;
$_lusterCsTogglePlaceholder = '<!--lstr-cs-toggle-' . bin2hex(random_bytes(8)) . '-->';
$_lusterCsFormPlaceholder   = '<!--lstr-cs-form-' . bin2hex(random_bytes(8)) . '-->';
if ($_lusterCodesTemplate !== null) {
    $_lusterCodesTemplate = str_replace(
        ['{codes_submit_toggle}', '{codes_submit_form}'],
        [$_lusterCsTogglePlaceholder, $_lusterCsFormPlaceholder],
        $_lusterCodesTemplate,
    );
}

if ($_lusterCodesTemplate !== null && trim($_lusterCodesTemplate) !== '') {
    $_lusterCodesTemplate = str_replace('{site_name}', htmlspecialchars($_lusterListingName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $_lusterCodesTemplate);
    $_lusterCodesTemplateHtml = CodesTemplateRenderer::render(
        $_lusterCodesTemplate,
        $_lusterCodes,
        $_lusterGrouped,
        $_lusterDonorMap,
        $_lusterUploadsUrl,
        $_lusterCatMap,
        $_lusterCategoryMap,
        $_lusterListingName,
    );
}

// {codes_submit_toggle} / {codes_submit_form} — trigger button + a
// separately-placed code donation form, shown/hidden via fl-embed.js.
if ($_lusterCodesTemplateHtml !== null
    && (str_contains($_lusterCodesTemplateHtml, $_lusterCsTogglePlaceholder) || str_contains($_lusterCodesTemplateHtml, $_lusterCsFormPlaceholder))
) {
    $_lusterCsToggleHtml = '';
    $_lusterCsFormTokenHtml = '';
    $_lusterCsEnabled    = false;

    $_lusterCsToggleStyle = 'link';
    $_lusterCsToggleLabel = 'Donate a code?';
    $_lusterCsSiteRepo    = $_lusterSiteRepo ?? new SiteSettingsRepo($_lusterDb);
    if (!$_lusterIsCollective && $_lusterListingId !== null) {
        $_lusterCsSettingsRepo = new ListingSettingsRepo($_lusterDb);
        $_lusterCsEmbedCfg     = $_lusterCsSettingsRepo->getResolvedEmbedSettings($_lusterListingId);
        $_lusterCsEnabled      = (bool) ($_lusterCsEmbedCfg['codes_submit_enabled'] ?? true);
        $_lusterCsToggleStyle  = $_lusterCsSettingsRepo->getCodesSubmitToggleStyle($_lusterListingId)
            ?? $_lusterCsSiteRepo->get('global_codes_submit_toggle_style')
            ?? 'link';
        $_lusterCsToggleLabel  = $_lusterCsSettingsRepo->getCodesSubmitToggleLabel($_lusterListingId)
            ?? $_lusterCsSiteRepo->get('global_codes_submit_toggle_label')
            ?? 'Donate a code?';
    } else {
        $_lusterCsEnabled     = ($_lusterCsSiteRepo->get('collective_codes_submit_enabled') ?? '1') === '1';
        $_lusterCsToggleStyle = (string) ($_lusterCsSiteRepo->get('collective_codes_submit_style') ?? 'link');
        if (!in_array($_lusterCsToggleStyle, ['link', 'button'], true)) { $_lusterCsToggleStyle = 'link'; }
        $_lusterCsToggleLabel = (string) ($_lusterCsSiteRepo->get('collective_codes_submit_toggle_label') ?? 'Donate a code?');
    }

    if ($_lusterCsEnabled) {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $_lusterCsHasFeedback = $_lusterApp->getSession()->get('lstr_flash_success_html') !== null
                             || $_lusterApp->getSession()->get('lstr_flash_error') !== null;
        $_lusterReturnOrig = $luster_return ?? false;
        $luster_return     = true;
        $_lusterCsFormHtml = (string) require __DIR__ . '/codes-submit.php';
        $luster_return     = $_lusterReturnOrig;

        if ($_lusterCsFormHtml !== '') {
            $_lusterCsToggleRendered = true;
            $_lusterCsFormId = 'fl-codes-submit-form-' . ($_lusterListingId ?? 0);
            $_lusterCsTag    = $_lusterCsToggleStyle === 'link' ? 'a' : 'button';
            $_lusterCsAttrs  = $_lusterCsToggleStyle === 'link' ? ' role="button" tabindex="0"' : ' type="button"';

            $_lusterCsToggleHtml = '<' . $_lusterCsTag . ' class="fl-codes-submit-toggle fl-codes-submit-toggle--' . $e($_lusterCsToggleStyle) . '"'
                . $_lusterCsAttrs
                . ' data-fl-toggle="' . $e($_lusterCsFormId) . '" aria-expanded="' . ($_lusterCsHasFeedback ? 'true' : 'false') . '" aria-controls="' . $e($_lusterCsFormId) . '">'
                . $e($_lusterCsToggleLabel)
                . '</' . $_lusterCsTag . '>';

            $_lusterCsFormTokenHtml = '<div class="fl-codes-submit-form-wrap" id="' . $e($_lusterCsFormId) . '"' . ($_lusterCsHasFeedback ? '' : ' hidden') . '>'
                . $_lusterCsFormHtml
                . '</div>';
        }
    }

    $_lusterCodesTemplateHtml = str_replace(
        [$_lusterCsTogglePlaceholder, $_lusterCsFormPlaceholder],
        [$_lusterCsToggleHtml, $_lusterCsFormTokenHtml],
        $_lusterCodesTemplateHtml,
    );
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('codes.php', [
    'listing'             => $_lusterListing,
    'listing_title'        => $_lusterListingName,
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
    'pagination'          => $_lusterPagHtml,
]);

// Pagination is now rendered inside the template (before attribution).

// Optionally append the code donation form after the gallery.
// Set $luster_append_submit = true before require to include the form here (useful when
// you want both the gallery and submission on the same page without a separate embed).
// By default (false) codes.php shows only the gallery; use codes-submit.php separately.
// NOTE: codes-submit.php is required in the same scope and reassigns $_lusterHtml,
// so we save the gallery output and restore $luster_return after the sub-require.
$_lusterAppendEnabled = false;
if ($_lusterIsCollective) {
    $_lusterAppendEnabled = (($_lusterCsSiteRepo ?? new SiteSettingsRepo($_lusterDb))->get('collective_codes_submit_enabled') ?? '1') === '1';
} else {
    $_lusterEmbedSettings = (new \Luster\Repositories\ListingSettingsRepo($_lusterApp->getDatabase()))
        ->getResolvedEmbedSettings($_lusterListingId);
    $_lusterAppendEnabled = (bool) ($_lusterEmbedSettings['codes_submit_enabled'] ?? true);
}
if (($luster_append_submit ?? false) && !($_lusterCsToggleRendered ?? false) && $_lusterAppendEnabled) {
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
