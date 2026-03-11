<?php

declare(strict_types=1);

use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\FanlistingRepo;

/**
 * Luster embed — affiliates fragment.
 *
 * Outputs the active affiliates list for a fanlisting (or site-wide collective)
 * using the same HTML structure as GET /affiliates/{id}.
 *
 * Usage — per-fanlisting:
 *   $luster_fanlisting_id = 1;     // required: fanlisting ID (int)
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

$_lusterFanlistingId = (int) ($luster_fanlisting_id ?? 0);
$_lusterPerPage      = max(1, (int) ($luster_per_page ?? 50));
$_lusterPage         = max(1, (int) ($luster_page ?? ($_GET['page'] ?? 1)));

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
$_lusterUploadsUrl = $_lusterApp->getBaseUrl() . '/uploads';

// ---------------------------------------------------------------------------
// 3. Load affiliates
// ---------------------------------------------------------------------------

$_lusterAffiliateRepo = new AffiliateRepo($_lusterApp->getDatabase());

if ($_lusterFanlistingId > 0) {
    // Per-fanlisting mode
    $_lusterFanlistingRepo = new FanlistingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
    $_lusterFanlisting     = $_lusterFanlistingRepo->getById($_lusterFanlistingId);

    if ($_lusterFanlisting === null) {
        $_lusterHtml = '<p class="fl-errors">Luster embed error: fanlisting #' . $_lusterFanlistingId . ' not found.</p>';
        if ($luster_return ?? false) {
            return $_lusterHtml;
        }
        echo $_lusterHtml;
        return;
    }

    $_lusterResult      = $_lusterAffiliateRepo->getActiveByFanlisting($_lusterFanlistingId, $_lusterPerPage, $_lusterPage);
    $_lusterIsCollective = false;
    $_lusterJoinUrl      = $_lusterBaseUrl . '/join/' . $_lusterFanlistingId;
    $_lusterTitle        = $_lusterFanlisting->name;
} else {
    // Collective mode
    $_lusterFanlisting   = null;
    $_lusterResult       = $_lusterAffiliateRepo->getActiveCollective($_lusterPerPage, $_lusterPage);
    $_lusterIsCollective = true;
    $_lusterJoinUrl      = '';
    $_lusterTitle        = (string) $_lusterApp->getConfig()->get('app_name', 'Luster');
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

$_lusterPaginationBase = $_lusterFanlistingId > 0
    ? $_lusterBaseUrl . '/affiliates/' . $_lusterFanlistingId
    : $_lusterBaseUrl . '/affiliates';

$_lusterPagination = $_lusterBuildPagination($_lusterPage, $_lusterResult['pages'], $_lusterPaginationBase);

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('affiliates.php', [
    'fanlisting'      => $_lusterFanlisting,
    'fanlisting_name' => $_lusterTitle,
    'fanlisting_url'  => $_lusterFanlisting?->url ?? $_lusterApp->getBaseUrl(),
    'page_title'      => $_lusterIsCollective ? 'Affiliates' : 'Affiliates — ' . $_lusterTitle,
    'affiliates'      => $_lusterResult['items'],
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
