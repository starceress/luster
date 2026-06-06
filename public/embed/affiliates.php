<?php

declare(strict_types=1);

use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

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

$_lusterAffiliateTemplate = null;
$_lusterSectionTemplate   = null;
if ($_lusterListingId > 0) {
    $_lusterSettingsRepo = new ListingSettingsRepo($_lusterApp->getDatabase());
    $_lusterTpls         = $_lusterSettingsRepo->getTemplates($_lusterListingId);
    if ($_lusterTpls['affiliates_template'] !== null
        && trim($_lusterTpls['affiliates_template']) !== '') {
        $_lusterAffiliateTemplate = $_lusterTpls['affiliates_template'];
    }
    if (($_lusterTpls['affiliates_section_template'] ?? null) !== null
        && trim($_lusterTpls['affiliates_section_template']) !== '') {
        $_lusterSectionTemplate = $_lusterTpls['affiliates_section_template'];
    }
} else {
    // Collective mode — load section template from lstr_site_settings
    $_lusterSiteRepo    = new \Luster\Repositories\SiteSettingsRepo($_lusterApp->getDatabase());
    $_lusterSectTplRaw  = (string) ($_lusterSiteRepo->getMany(['collective_affiliates_section_template'])['collective_affiliates_section_template'] ?? '');
    if (trim($_lusterSectTplRaw) !== '') {
        $_lusterSectionTemplate = $_lusterSectTplRaw;
    }
}

// ---------------------------------------------------------------------------
// 4. Build pagination
// ---------------------------------------------------------------------------

$_lusterBuildPagination = static function (int $current, int $total, string $baseHref): string {
    if ($total <= 1) {
        return '';
    }
    $parts = [];
    for ($i = 1; $i <= $total; $i++) {
        $href = htmlspecialchars($baseHref . '?page=' . $i, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($i === $current) {
            $parts[] = '<span class="fl-page-current">' . $i . '</span>';
        } else {
            $parts[] = '<a class="fl-page-link" href="' . $href . '">' . $i . '</a>';
        }
    }
    return implode(' ', $parts);
};

// Allow the embedding page to override pagination base URL.
$_lusterPaginationBase = (isset($luster_affiliates_base) && (string) $luster_affiliates_base !== '')
    ? (string) $luster_affiliates_base
    : ($_lusterListingId > 0
        ? $_lusterBaseUrl . '/affiliates/' . $_lusterListingId
        : $_lusterBaseUrl . '/affiliates');

$_lusterPagination = $_lusterBuildPagination($_lusterPage, $_lusterResult['pages'], $_lusterPaginationBase);

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

// Build per-row HTML when a custom row template is set
$_lusterAffiliatesHtml = null;
if ($_lusterAffiliateTemplate !== null) {
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rows = '';
    foreach ($_lusterResult['items'] as $_aff) {
        $imageFilename = $_aff->image ?? '';
        $imageUrl      = ($imageFilename !== '') ? $_lusterUploadsUrl . '/' . $imageFilename : '';
        $imageTag      = ($imageUrl !== '')
            ? '<img src="' . $e($imageUrl) . '" alt="' . $e($_aff->name) . '">'
            : '';
        $rows .= str_replace(
            ['{aff_name}', '{aff_url}', '{aff_image}', '{aff_image_url}', '{aff_image_tag}'],
            [$e($_aff->name), $e($_aff->url), $e($imageFilename), $e($imageUrl), $imageTag],
            $_lusterAffiliateTemplate,
        );
    }
    $_lusterAffiliatesHtml = $rows;
}

// Apply page-level section template when set
$_lusterSectionHtml = null;
if ($_lusterSectionTemplate !== null) {
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Build {affiliates} content from row template or simple fallback
    if ($_lusterAffiliatesHtml !== null) {
        $_lusterRowsHtml = $_lusterAffiliatesHtml;
    } else {
        $rowParts = [];
        foreach ($_lusterResult['items'] as $_aff) {
            $imageUrl = ($_aff->image !== null && $_aff->image !== '') ? $_lusterUploadsUrl . '/' . $_aff->image : '';
            if ($imageUrl !== '') {
                $rowParts[] = '<a href="' . $e($_aff->url) . '" target="_blank" rel="noopener noreferrer">'
                    . '<img src="' . $e($imageUrl) . '" alt="' . $e($_aff->name) . '"></a>';
            } else {
                $rowParts[] = '<a href="' . $e($_aff->url) . '" target="_blank" rel="noopener noreferrer">'
                    . $e($_aff->name) . '</a>';
            }
        }
        $_lusterRowsHtml = implode("\n", $rowParts);
    }

    // Build {affiliates_list}
    $listParts = [];
    foreach ($_lusterResult['items'] as $_aff) {
        $listParts[] = '<a href="' . $e($_aff->url) . '">' . $e($_aff->name) . '</a>';
    }
    $plainList = implode(', ', $listParts);

    // Process conditionals
    $total = $_lusterResult['total'];
    if ($total > 0) {
        $_lusterSectionHtml = preg_replace('/\{if_no_affiliates\}.*?\{\/if_no_affiliates\}/s', '', $_lusterSectionTemplate) ?? $_lusterSectionTemplate;
        $_lusterSectionHtml = str_replace(['{if_affiliates}', '{/if_affiliates}'], '', $_lusterSectionHtml);
    } else {
        $_lusterSectionHtml = preg_replace('/\{if_affiliates\}.*?\{\/if_affiliates\}/s', '', $_lusterSectionTemplate) ?? $_lusterSectionTemplate;
        $_lusterSectionHtml = str_replace(['{if_no_affiliates}', '{/if_no_affiliates}'], '', $_lusterSectionHtml);
    }

    $_lusterSectionHtml = str_replace(
        ['{affiliates}', '{affiliates_count}', '{affiliates_list}'],
        [$_lusterRowsHtml, (string) $total, $plainList],
        $_lusterSectionHtml,
    );
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('affiliates.php', [
    'listing'      => $_lusterListing,
    'listing_name' => $_lusterTitle,
    'listing_url'  => $_lusterListing?->url ?? $_lusterApp->getBaseUrl(),
    'page_title'      => $_lusterIsCollective ? 'Affiliates' : 'Affiliates — ' . $_lusterTitle,
    'affiliates'      => $_lusterResult['items'],
    'affiliates_html' => $_lusterAffiliatesHtml,
    'section_html'    => $_lusterSectionHtml,
    'total'           => $_lusterResult['total'],
    'pagination'      => $_lusterPagination,
    'is_collective'   => $_lusterIsCollective,
    'join_url'        => $_lusterJoinUrl,
    'uploads_url'     => $_lusterUploadsUrl,
    'base_url'        => $_lusterApp->getBaseUrl(),
    'theme_path'      => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

// Conditionally append the affiliate application form if enabled for this listing.
$_lusterAffApplySettings = (new \Luster\Repositories\ListingSettingsRepo($_lusterApp->getDatabase()))
    ->getAffiliateApplySettings($_lusterListingId);
if ($_lusterAffApplySettings['enabled'] ?? false) {
    $luster_return = true;
    $_lusterHtml .= (string) require __DIR__ . '/affiliate-apply.php';
}

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
