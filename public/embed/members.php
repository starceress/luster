<?php

declare(strict_types=1);

use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;

/**
 * Luster embed — members list fragment.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: fanlisting ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/members.php';
 *
 * Optional variables:
 *   $luster_per_page    int   Members per page. Overrides the config per_page value.
 *                             Default: reads config per_page (typically 50).
 *   $luster_sort_by     string  Force a default sort: 'name' or 'country'.
 *                             When set, $_GET['sort'] is ignored unless you also
 *                             want visitors to be able to switch — omit this variable
 *                             if you want the user-facing sort links to work normally.
 *                             Default: reads $_GET['sort'] (falls back to 'country').
 *   $luster_show_search bool  Show or hide the sort links and filter controls.
 *                             Default: true (controls visible).
 *
 * Reads country/page from $_GET the same way MembersController does.
 * Renders the member list or country summary as a content fragment with no layout wrapper.
 */

// ---------------------------------------------------------------------------
// 1. Validate the required variable
// ---------------------------------------------------------------------------

$_lusterFanlistingId = (int) ($luster_fanlisting_id ?? 0);

if ($_lusterFanlistingId === 0) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: <code>$luster_fanlisting_id</code> is not set.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

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
// 3. Load fanlisting data
// ---------------------------------------------------------------------------

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

$_lusterFieldRepo = new FieldRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterFields    = $_lusterFieldRepo->getFieldsForFanlisting($_lusterFanlistingId);

// ---------------------------------------------------------------------------
// 4. Read query params
// ---------------------------------------------------------------------------

$_lusterBaseUrl     = $_lusterApp->getBaseUrl();
$_lusterMembersBase = $_lusterBaseUrl . '/members/' . $_lusterFanlistingId;

// $luster_per_page — override config value when set
$_lusterPerPage = (isset($luster_per_page) && (int) $luster_per_page > 0)
    ? (int) $luster_per_page
    : max(1, (int) $_lusterApp->getConfig()->get('per_page', 50));

$_lusterPage    = max(1, (int) ($_GET['page'] ?? 1));

// $luster_sort_by — force a default sort, ignoring $_GET['sort'] when set
$_lusterRawSort = (isset($luster_sort_by) && in_array($luster_sort_by, ['name', 'country'], true))
    ? (string) $luster_sort_by
    : (string) ($_GET['sort'] ?? 'country');
$_lusterSort    = in_array($_lusterRawSort, ['name', 'country'], true) ? $_lusterRawSort : 'country';
$_lusterCountry = trim((string) ($_GET['country'] ?? ''));

// $luster_show_search — whether to render sort/filter controls in the template
$_lusterShowSearch = (bool) ($luster_show_search ?? true);

$_lusterMemberRepo = new MemberRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());

// Shared data for all rendering paths
$_lusterCommonData = [
    'fanlisting'      => $_lusterFanlisting,
    'fanlisting_name' => $_lusterFanlisting->name,
    'fanlisting_url'  => $_lusterFanlisting->url,
    'page_title'      => 'Members',
    'join_url'        => $_lusterBaseUrl . '/join/' . $_lusterFanlistingId,
    'members_base'    => $_lusterMembersBase,
    'sort'            => $_lusterSort,
    'show_search'     => $_lusterShowSearch,
    'fields'          => $_lusterFields,
    'base_url'        => $_lusterBaseUrl,
    'theme_path'      => $_lusterBaseUrl . '/assets/default/',
];

// ---------------------------------------------------------------------------
// 5. Build pagination helper (mirrors MembersController::buildPagination)
// ---------------------------------------------------------------------------

$_lusterBuildPagination = static function (
    int $currentPage,
    int $totalPages,
    string $baseUrl,
    array $extraParams = [],
): string {
    if ($totalPages <= 1) {
        return '';
    }

    $makeUrl = static function (int $p) use ($baseUrl, $extraParams): string {
        return htmlspecialchars(
            $baseUrl . '?' . http_build_query(array_merge($extraParams, ['page' => $p])),
            ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8',
        );
    };

    $html  = '<nav class="fl-pagination" aria-label="Members pagination"><ul>';
    if ($currentPage > 1) {
        $html .= '<li><a class="fl-page-link" href="' . $makeUrl($currentPage - 1) . '">&laquo; Previous</a></li>';
    }
    $start = max(1, $currentPage - 2);
    $end   = min($totalPages, $currentPage + 2);
    if ($start > 1) {
        $html .= '<li><a class="fl-page-link" href="' . $makeUrl(1) . '">1</a></li>';
        if ($start > 2) {
            $html .= '<li><span>&hellip;</span></li>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        if ($i === $currentPage) {
            $html .= '<li><span class="fl-page-current" aria-current="page">' . $i . '</span></li>';
        } else {
            $html .= '<li><a class="fl-page-link" href="' . $makeUrl($i) . '">' . $i . '</a></li>';
        }
    }
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<li><span>&hellip;</span></li>';
        }
        $html .= '<li><a class="fl-page-link" href="' . $makeUrl($totalPages) . '">' . $totalPages . '</a></li>';
    }
    if ($currentPage < $totalPages) {
        $html .= '<li><a class="fl-page-link" href="' . $makeUrl($currentPage + 1) . '">Next &raquo;</a></li>';
    }
    $html .= '</ul></nav>';

    return $html;
};

// ---------------------------------------------------------------------------
// 6. Render — three paths identical to MembersController::index()
// ---------------------------------------------------------------------------

if ($_lusterCountry !== '') {
    // Path A: filtered by a specific country
    $_lusterResult     = $_lusterMemberRepo->paginateByCountry(
        $_lusterFanlisting->dbprefix, $_lusterCountry, $_lusterPage, $_lusterPerPage,
    );
    $_lusterStatsLine  = $_lusterCountry . ': ' . $_lusterResult['total'] . ' member(s)'
        . ($_lusterResult['pages'] > 1
            ? ' — page ' . $_lusterPage . ' of ' . $_lusterResult['pages']
            : '');
    $_lusterPagination = $_lusterBuildPagination(
        $_lusterPage, $_lusterResult['pages'], $_lusterMembersBase,
        ['sort' => 'country', 'country' => $_lusterCountry],
    );

    $_lusterHtml = $_lusterApp->getView()->renderFragment('members.php', array_merge($_lusterCommonData, [
        'country'         => $_lusterCountry,
        'members'         => $_lusterResult['members'],
        'country_summary' => [],
        'stats_line'      => $_lusterStatsLine,
        'pagination'      => $_lusterPagination,
    ]));
} elseif ($_lusterSort === 'country') {
    // Path B: country summary
    $_lusterCountries      = $_lusterMemberRepo->getCountrySummary($_lusterFanlisting->dbprefix);
    $_lusterCountrySummary = [];
    foreach ($_lusterCountries as $_lusterRow) {
        $_lusterCountrySummary[] = [
            'country'   => $_lusterRow['country'],
            'fan_count' => (int) $_lusterRow['fan_count'],
            'url'       => $_lusterMembersBase . '?sort=country&country=' . urlencode($_lusterRow['country']),
        ];
    }
    $_lusterTotal     = count($_lusterCountries);
    $_lusterStatsLine = $_lusterTotal . ' ' . ($_lusterTotal === 1 ? 'country' : 'countries') . ' represented';

    $_lusterHtml = $_lusterApp->getView()->renderFragment('members.php', array_merge($_lusterCommonData, [
        'country'         => '',
        'members'         => [],
        'country_summary' => $_lusterCountrySummary,
        'stats_line'      => $_lusterStatsLine,
        'pagination'      => '',
    ]));
} else {
    // Path C: alphabetical by name
    $_lusterResult     = $_lusterMemberRepo->paginate(
        $_lusterFanlisting->dbprefix, $_lusterPage, $_lusterPerPage, true, 'name',
    );
    $_lusterStatsLine  = $_lusterResult['total'] . ' member(s) — page '
        . $_lusterPage . ' of ' . $_lusterResult['pages'];
    $_lusterPagination = $_lusterBuildPagination(
        $_lusterPage, $_lusterResult['pages'], $_lusterMembersBase, ['sort' => 'name'],
    );

    $_lusterHtml = $_lusterApp->getView()->renderFragment('members.php', array_merge($_lusterCommonData, [
        'country'         => '',
        'members'         => $_lusterResult['members'],
        'country_summary' => [],
        'stats_line'      => $_lusterStatsLine,
        'pagination'      => $_lusterPagination,
    ]));
}

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
