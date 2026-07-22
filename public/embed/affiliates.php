<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable affiliates list. Renders the approved affiliates for a single
 fanlisting (when $luster_fanlisting_id is set) or the collective/site-wide
 affiliates (when set to 0). Supports pagination, an admin-editable section
 template with per-affiliate row rendering, and an optional link to the
 affiliate application form.
*/

declare(strict_types=1);

use Luster\Core\Pagination;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\AffiliateRowRenderer;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\ListingTemplateResolver;

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
// Started here (not just implicitly via the nested affiliate-apply.php require
// below) so the pending-feedback peek below reads a populated $_SESSION rather
// than an empty one from before session_start() has run.
$_lusterApp->getSession()->start();

// ---------------------------------------------------------------------------
// 2. Resolve parameters
// ---------------------------------------------------------------------------

$_lusterListingId = (int) ($luster_fanlisting_id ?? 0);
$_lusterPerPage      = max(1, (int) ($luster_per_page ?? 50));
// $luster_page sets which page shows first on a fresh visit (no ?page= yet).
// $_GET['page'] always takes priority once present, so the pagination links
// this file renders (via Pagination::render()) keep working after a click
// instead of being permanently overridden back to $luster_page.
$_lusterPage         = max(1, (int) ($_GET['page'] ?? ($luster_page ?? 1)));

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
// "?luster_img=affiliates" instead of a direct uploads URL — keeps the real
// Luster install path out of every <img src>. Requires the host page to have
// the serveUploadedImage() guard next to the existing serveCss() one (see
// Luster\Core\View::serveUploadedImage() docblock).
$_lusterUploadsUrl = '?luster_img=affiliates';

// ---------------------------------------------------------------------------
// 3. Load affiliates
// ---------------------------------------------------------------------------

$_lusterAffiliateRepo = new AffiliateRepo($_lusterApp->getDatabase());

if ($_lusterListingId > 0) {
    // Per-fanlisting mode
    $_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
    $_lusterListing     = $_lusterListingRepo->getById($_lusterListingId);

    if ($_lusterListing === null) {
        $_lusterHtml = '<p class="fl-errors">Luster embed error: listing #' . $_lusterListingId . ' not found.</p>';
        if ($luster_return ?? false) {
            return $_lusterHtml;
        }
        echo $_lusterHtml;
        return;
    }

    $_lusterResult       = $_lusterAffiliateRepo->getActiveByListing($_lusterListingId, $_lusterPerPage, $_lusterPage);
    $_lusterIsCollective = false;
    $_lusterSettingsRepo  = new ListingSettingsRepo($_lusterApp->getDatabase());
    $_lusterEmbedSettings = $_lusterSettingsRepo->getResolvedEmbedSettings($_lusterListingId);
    $_lusterGlobalDefaults = $_lusterSettingsRepo->getGlobalFormUrlDefaults();
    $_lusterJoinUrl      = \Luster\Support\ListingFormUrlResolver::resolve(
        (string) ($_lusterEmbedSettings['form_url_join'] ?? ''),
        $_lusterListing->url,
        $_lusterGlobalDefaults['form_url_join'],
    );
    $_lusterTitle        = $_lusterListing->title;
} else {
    // Collective mode
    $_lusterListing   = null;
    $_lusterResult       = $_lusterAffiliateRepo->getActiveCollective($_lusterPerPage, $_lusterPage);
    $_lusterIsCollective = true;
    $_lusterJoinUrl      = '';
    $_lusterTitle        = (string) $_lusterApp->getConfig()->get('site_name', 'Luster');
}

// ---------------------------------------------------------------------------
// 3b. Load affiliate row template + section template from DB
// ---------------------------------------------------------------------------

$_lusterSectionTemplate   = null;
if ($_lusterListingId > 0) {
    $_lusterTpls         = $_lusterSettingsRepo->getTemplates($_lusterListingId);
    $_lusterGlobalTpls   = (new SiteSettingsRepo($_lusterApp->getDatabase()))->getGlobalTemplates();
    $_lusterAffResolved  = ListingTemplateResolver::resolve(
        $_lusterTpls['affiliates_section_template'] ?? null,
        $_lusterGlobalTpls['listing_default_affiliates_section_template'] ?? null,
        ListingSettingsRepo::defaultAffiliatesSectionTemplate(),
    );
    if ($_lusterAffResolved['should_render_template']) {
        $_lusterSectionTemplate = $_lusterAffResolved['value'];
    }
    $_lusterPaginationTemplate = $_lusterSettingsRepo->getPaginationTemplate($_lusterListingId) ?? '';
} else {
    // Collective mode — load section template from lstr_site_settings
    $_lusterSiteRepo           = new \Luster\Repositories\SiteSettingsRepo($_lusterApp->getDatabase());
    $_lusterSectTplRaw         = (string) ($_lusterSiteRepo->getMany(['collective_affiliates_section_template'])['collective_affiliates_section_template'] ?? '');
    $_lusterPaginationTemplate = (string) ($_lusterSiteRepo->get('collective_pagination_template') ?? '');
    if (trim($_lusterSectTplRaw) !== '') {
        $_lusterSectionTemplate = $_lusterSectTplRaw;
    }
}

// ---------------------------------------------------------------------------
// 4. Build pagination
// ---------------------------------------------------------------------------

// Pagination links always point back to the host page that includes this
// embed, so visitors never leave it when paging through affiliates.
$_lusterPaginationBase = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/';

$_lusterPagination = Pagination::render(
    $_lusterPage, $_lusterResult['pages'], $_lusterPaginationBase, $_lusterPaginationTemplate,
);

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

// {affiliate_apply_toggle} / {affiliate_apply_form} — trigger button + a
// separately-placed form, shown/hidden via fl-embed.js. Built unconditionally
// (not just when a section template references the tokens) so the hardcoded
// PHP fallback fragment (templates/default/affiliates.php, used when nothing
// is customized at any tier) can show the same apply CTA as a templated one.
$e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$_lusterToggleHtml    = '';
$_lusterFormTokenHtml = '';
$_lusterApplyEnabled = false;
$_lusterApplyStyle   = 'button';
$_lusterApplyLabel   = 'Apply to become an affiliate?';
if ($_lusterListingId > 0) {
    $_lusterAffApplySettings = $_lusterSettingsRepo->getAffiliateApplySettings($_lusterListingId);
    $_lusterApplyEnabled     = (bool) ($_lusterAffApplySettings['enabled'] ?? false);
    $_lusterSiteRepoFallback = $_lusterSiteRepo ?? new SiteSettingsRepo($_lusterApp->getDatabase());
    $_lusterApplyStyle       = $_lusterSettingsRepo->getAffiliateApplyStyle($_lusterListingId)
        ?? $_lusterSiteRepoFallback->get('global_affiliate_apply_style')
        ?? 'button';
    $_lusterApplyLabel       = $_lusterSettingsRepo->getAffiliateApplyToggleLabel($_lusterListingId)
        ?? $_lusterSiteRepoFallback->get('global_affiliate_apply_toggle_label')
        ?? 'Apply to become an affiliate?';
} else {
    $_lusterSiteRepoToggle  = $_lusterSiteRepo ?? new SiteSettingsRepo($_lusterApp->getDatabase());
    $_lusterApplyEnabled    = ($_lusterSiteRepoToggle->get('collective_affiliate_apply_enabled') ?? '1') === '1';
    $_lusterApplyStyle      = (string) ($_lusterSiteRepoToggle->get('collective_affiliate_apply_style') ?? 'button');
    if (!in_array($_lusterApplyStyle, ['link', 'button'], true)) {
        $_lusterApplyStyle = 'button';
    }
    $_lusterApplyLabel = (string) ($_lusterSiteRepoToggle->get('collective_affiliate_apply_toggle_label') ?? 'Apply to become an affiliate?');
}

// Apply page-level section template when set — resolve the {each_affiliate}
// loop and {if_affiliates}/{if_no_affiliates} conditionals FIRST, so the
// apply-form decision below reflects the actually-active branch rather than
// just whether the raw template mentions the tokens anywhere (which could be
// inside a branch that isn't even active, e.g. {if_no_affiliates} while the
// listing currently has affiliates).
$_lusterSectionHtml = null;
if ($_lusterSectionTemplate !== null) {
    $_lusterTpl = $_lusterSectionTemplate;

    // {each_affiliate}...{/each_affiliate} — inline per-row loop block.
    // Process before conditionals so the block is substituted while still in context.
    if (preg_match('/\{each_affiliate\}(.*?)\{\/each_affiliate\}/s', $_lusterTpl, $_lusterEachMatch)) {
        $_lusterEachRows = AffiliateRowRenderer::renderRows($_lusterEachMatch[1], $_lusterResult['items'], $_lusterUploadsUrl);
        $_lusterTpl = (string) preg_replace('/\{each_affiliate\}.*?\{\/each_affiliate\}/s', $_lusterEachRows, $_lusterTpl, 1);
    }

    // Process conditionals
    $total = $_lusterResult['total'];
    if ($total > 0) {
        $_lusterSectionHtml = preg_replace('/\{if_no_affiliates\}.*?\{\/if_no_affiliates\}/s', '', $_lusterTpl) ?? $_lusterTpl;
        $_lusterSectionHtml = str_replace(['{if_affiliates}', '{/if_affiliates}'], '', $_lusterSectionHtml);
    } else {
        $_lusterSectionHtml = preg_replace('/\{if_affiliates\}.*?\{\/if_affiliates\}/s', '', $_lusterTpl) ?? $_lusterTpl;
        $_lusterSectionHtml = str_replace(['{if_no_affiliates}', '{/if_no_affiliates}'], '', $_lusterSectionHtml);
    }
}

// Only build the nested apply form when it will actually be used: either
// there's no custom/global section template at all (the PHP fallback
// fragment always renders the CTA), or the resolved (post-conditional)
// output still references the tokens. Otherwise a custom display template
// that only puts the tokens inside a branch that isn't currently active
// would still pull in affiliate-apply.php, which consumes/clears its own
// scoped flash success/error keys — silently swallowing the feedback meant
// for a genuinely separate affiliate-apply.php embed placed elsewhere on
// the same page.
$_lusterWillUseInlineApplyForm = $_lusterSectionTemplate === null
    || ($_lusterSectionHtml !== null
        && (str_contains($_lusterSectionHtml, '{affiliate_apply_toggle}') || str_contains($_lusterSectionHtml, '{affiliate_apply_form}')));

if ($_lusterApplyEnabled && $_lusterWillUseInlineApplyForm) {
    $_lusterAffApplyMarker = 'affiliate_apply:' . $_lusterListingId;
    $_lusterAffHasFeedback = $_lusterApp->getSession()->get('lstr_flash_success_html:' . $_lusterAffApplyMarker) !== null
                          || $_lusterApp->getSession()->get('lstr_flash_error:' . $_lusterAffApplyMarker) !== null;

    $_lusterReturnOrig = $luster_return ?? false;
    $luster_return     = true;
    $_lusterApplyFormHtml = (string) require __DIR__ . '/affiliate-apply.php';
    $luster_return     = $_lusterReturnOrig;

    $_lusterAffFormId = 'fl-affiliate-apply-form-' . $_lusterListingId;
    $_lusterAffTag    = $_lusterApplyStyle === 'link' ? 'a' : 'button';
    $_lusterAffAttrs  = $_lusterApplyStyle === 'link' ? ' role="button" tabindex="0"' : ' type="button"';

    $_lusterToggleHtml = '<' . $_lusterAffTag . ' class="fl-affiliate-apply-toggle fl-affiliate-apply-toggle--' . $e($_lusterApplyStyle) . '"'
        . $_lusterAffAttrs
        . ' data-fl-toggle="' . $e($_lusterAffFormId) . '" aria-expanded="' . ($_lusterAffHasFeedback ? 'true' : 'false') . '" aria-controls="' . $e($_lusterAffFormId) . '">'
        . $e($_lusterApplyLabel)
        . '</' . $_lusterAffTag . '>';

    $_lusterFormTokenHtml = '<div class="fl-affiliate-apply-form-wrap" id="' . $e($_lusterAffFormId) . '"' . ($_lusterAffHasFeedback ? '' : ' hidden') . '>'
        . $_lusterApplyFormHtml
        . '</div>';
}

// Substitute the remaining page-level tokens now that the toggle/form html
// (if any) has been built.
if ($_lusterSectionTemplate !== null) {
    if ($_lusterListingId > 0) {
        $_lusterAffApplySettings = $_lusterSettingsRepo->getAffiliateApplySettings($_lusterListingId);
        $_lusterAffApplyUrl      = \Luster\Support\ListingFormUrlResolver::resolve(
            (string) $_lusterAffApplySettings['form_url'],
            $_lusterListing->url,
            $_lusterGlobalDefaults['form_url_affiliate_apply'] ?? '',
        );
    } else {
        $_lusterSiteUrl  = (string) ($_lusterApp->getConfig()->get('site_url', '') ?: $_lusterBaseUrl);
        $_lusterAffOverride = (new SiteSettingsRepo($_lusterApp->getDatabase()))->get('collective_form_url_affiliate_apply');
        $_lusterAffApplyUrl = CollectiveFormUrlResolver::resolve(
            $_lusterAffOverride ?: 'affiliates.php',
            $_lusterSiteUrl,
            $_lusterSiteUrl,
        );
    }

    $_lusterSectionHtml = str_replace(
        ['{affiliate_apply_toggle}', '{affiliate_apply_form}'],
        [$_lusterToggleHtml, $_lusterFormTokenHtml],
        $_lusterSectionHtml,
    );

    $_lusterSectionHtml = str_replace(
        ['{site_name}', '{affiliates_count}', '{affiliate_apply_url}'],
        [htmlspecialchars($_lusterTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), (string) $_lusterResult['total'], htmlspecialchars($_lusterAffApplyUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')],
        $_lusterSectionHtml,
    );
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('affiliates.php', [
    'listing'      => $_lusterListing,
    'listing_title' => $_lusterTitle,
    'listing_url'  => $_lusterListing?->url ?? $_lusterApp->getBaseUrl(),
    'page_title'      => $_lusterIsCollective ? 'Affiliates' : 'Affiliates — ' . $_lusterTitle,
    'affiliates'      => $_lusterResult['items'],
    'section_html'    => $_lusterSectionHtml,
    'total'           => $_lusterResult['total'],
    'pagination'      => $_lusterPagination,
    'is_collective'   => $_lusterIsCollective,
    'join_url'        => $_lusterJoinUrl,
    'uploads_url'     => $_lusterUploadsUrl,
    'base_url'        => $_lusterApp->getBaseUrl(),
    'theme_path'      => $_lusterApp->getBaseUrl() . '/assets/default/',
    'affiliate_apply_toggle' => $_lusterToggleHtml,
    'affiliate_apply_form'   => $_lusterFormTokenHtml,
]);


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
