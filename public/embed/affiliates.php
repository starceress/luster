<?php

declare(strict_types=1);

use Luster\Core\Pagination;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\AffiliateRowRenderer;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\ListingTemplateResolver;

/**
 * Luster embed — affiliates fragment.
 *
 * Outputs the active affiliates list for a listing (or site-wide collective)
 * using the same HTML structure as GET /affiliates/{id}.
 *
 * Usage — per-fanlisting:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/affiliates.php';
 *
 * Usage — collective / site-wide (fanlisting_id IS NULL):
 *   $luster_fanlisting_id = 0;     // 0 = collective mode
 *   $luster_return        = false;
 *   require '/absolute/path/to/luster/public/embed/affiliates.php';
 *
 * Optional variables:
 *   $luster_per_page   int   affiliates per page (default 50)
 *   $luster_page       int   current page number (default: reads $_GET['page'])
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
$luster_attribution = \Luster\Core\View::attribution();

// ---------------------------------------------------------------------------
// 2. Resolve parameters
// ---------------------------------------------------------------------------

$_lusterListingId = (int) ($luster_fanlisting_id ?? 0);
$_lusterPerPage      = max(1, (int) ($luster_per_page ?? 50));
$_lusterPage         = max(1, (int) ($luster_page ?? ($_GET['page'] ?? 1)));

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
$_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads/affiliates';

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

    $_lusterResult      = $_lusterAffiliateRepo->getActiveByListing($_lusterListingId, $_lusterPerPage, $_lusterPage);
    $_lusterIsCollective = false;
    $_lusterJoinUrl      = $_lusterBaseUrl . '/join/' . $_lusterListingId;
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
    $_lusterSettingsRepo = new ListingSettingsRepo($_lusterApp->getDatabase());
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

// Apply page-level section template when set
$_lusterSectionHtml = null;
if ($_lusterSectionTemplate !== null) {
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    if ($_lusterListingId > 0) {
        $_lusterAffApplyUrl = $_lusterBaseUrl . '/affiliate-apply/' . $_lusterListingId;
    } else {
        $_lusterSiteUrl  = (string) ($_lusterApp->getConfig()->get('site_url', '') ?: $_lusterBaseUrl);
        $_lusterAffOverride = (new SiteSettingsRepo($_lusterApp->getDatabase()))->get('collective_form_url_affiliate_apply');
        $_lusterAffApplyUrl = CollectiveFormUrlResolver::resolve($_lusterAffOverride, $_lusterSiteUrl, $_lusterBaseUrl . '/affiliate-apply/collective');
    }

    // {affiliate_apply_toggle} — collapsible inline affiliate application form
    $_lusterToggleHtml = '';
    if (str_contains($_lusterSectionHtml, '{affiliate_apply_toggle}')) {
        $_lusterApplyEnabled = false;
        $_lusterApplyStyle   = 'link';

        if ($_lusterListingId > 0) {
            $_lusterAffApplySettings = $_lusterSettingsRepo->getAffiliateApplySettings($_lusterListingId);
            $_lusterApplyEnabled     = (bool) ($_lusterAffApplySettings['enabled'] ?? false);
            $_lusterApplyStyle       = $_lusterSettingsRepo->getAffiliateApplyStyle($_lusterListingId);
        } else {
            $_lusterSiteRepoToggle  = $_lusterSiteRepo ?? new SiteSettingsRepo($_lusterApp->getDatabase());
            $_lusterApplyEnabled    = ($_lusterSiteRepoToggle->get('collective_affiliate_apply_enabled') ?? '1') === '1';
            $_lusterApplyStyle      = (string) ($_lusterSiteRepoToggle->get('collective_affiliate_apply_style') ?? 'link');
            if (!in_array($_lusterApplyStyle, ['link', 'button'], true)) {
                $_lusterApplyStyle = 'link';
            }
        }

        if ($_lusterApplyEnabled) {
            $_lusterReturnOrig = $luster_return ?? false;
            $luster_return     = true;
            $_lusterApplyFormHtml = (string) require __DIR__ . '/affiliate-apply.php';
            $luster_return     = $_lusterReturnOrig;

            $_lusterToggleHtml = '<details class="fl-affiliate-apply-collapsible">'
                . '<summary class="fl-affiliate-apply-toggle fl-affiliate-apply-toggle--' . $e($_lusterApplyStyle) . '">'
                . 'Apply to become an affiliate?'
                . '</summary>'
                . '<div class="fl-affiliate-apply-form-wrap">'
                . $_lusterApplyFormHtml
                . '</div>'
                . '</details>';
        }

        $_lusterSectionHtml = str_replace('{affiliate_apply_toggle}', $_lusterToggleHtml, $_lusterSectionHtml);
    }

    $_lusterSectionHtml = str_replace(
        ['{site_name}', '{affiliates_count}', '{affiliate_apply_url}'],
        [htmlspecialchars($_lusterTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), (string) $total, htmlspecialchars($_lusterAffApplyUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')],
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
]);


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
