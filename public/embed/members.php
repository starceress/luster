<?php

declare(strict_types=1);

use Luster\Core\Pagination;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;

/**
 * Luster embed — members list fragment.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
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

$_lusterListingId = (int) ($luster_fanlisting_id ?? 0);

if ($_lusterListingId === 0) {
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
// 3. Load listing data
// ---------------------------------------------------------------------------

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

$_lusterFieldRepo = new FieldRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterFields    = $_lusterFieldRepo->getFieldsForListing($_lusterListingId);

// ---------------------------------------------------------------------------
// 4. Read query params
// ---------------------------------------------------------------------------

$_lusterBaseUrl     = $_lusterApp->getBaseUrl();

// Allow the embedding page to override the base URL used for sort/pagination
// links so they point back to the external page rather than Luster's own route.
//   $luster_members_base = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
$_lusterMembersBase = (isset($luster_members_base) && (string) $luster_members_base !== '')
    ? (string) $luster_members_base
    : $_lusterBaseUrl . '/members/' . $_lusterListingId;

// $luster_per_page — override config value when set
$_lusterPerPage = (isset($luster_per_page) && (int) $luster_per_page > 0)
    ? (int) $luster_per_page
    : max(1, (int) $_lusterApp->getConfig()->get('per_page', 50));

$_lusterPage    = max(1, (int) ($_GET['page'] ?? 1));

// $luster_sort_by — force a default sort, ignoring $_GET['sort'] when set
$_lusterRawSort = (isset($luster_sort_by) && in_array($luster_sort_by, ['name', 'country', 'added'], true))
    ? (string) $luster_sort_by
    : (string) ($_GET['sort'] ?? 'country');
$_lusterSort    = in_array($_lusterRawSort, ['name', 'country', 'added'], true) ? $_lusterRawSort : 'country';
$_lusterCountry = trim((string) ($_GET['country'] ?? ''));

// $luster_show_search — whether to render sort/filter controls in the template
$_lusterShowSearch = (bool) ($luster_show_search ?? true);

$_lusterMemberRepo = new MemberRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());

// ---------------------------------------------------------------------------
// 4b. Load member row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSettingsRepo     = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterTemplates        = $_lusterSettingsRepo->getTemplates($_lusterListingId);
$_lusterMemberTemplate   = ($_lusterTemplates['members_template'] !== null
                         && trim($_lusterTemplates['members_template']) !== '')
    ? $_lusterTemplates['members_template']
    : null;
$_lusterPaginationTemplate = $_lusterSettingsRepo->getPaginationTemplate($_lusterListingId) ?? '';

/**
 * Applies {token} substitution for a single member row.
 * Always HTML-escapes values before substitution.
 */
$_lusterRenderMember = static function (object $member) use ($_lusterMemberTemplate): string {
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return str_replace(
        ['{fan_name}', '{fan_url}', '{fan_email}', '{fan_country}'],
        [$e($member->name), $e($member->url ?? ''), $e($member->email), $e($member->country)],
        (string) $_lusterMemberTemplate,
    );
};

// Shared data for all rendering paths
$_lusterCommonData = [
    'listing'      => $_lusterListing,
    'listing_name' => $_lusterListing->title,
    'listing_url'  => $_lusterListing->url,
    'page_title'      => 'Members',
    'join_url'        => $_lusterBaseUrl . '/join/' . $_lusterListingId,
    'members_base'    => $_lusterMembersBase,
    'sort'            => $_lusterSort,
    'show_search'     => $_lusterShowSearch,
    'fields'          => $_lusterFields,
    'base_url'        => $_lusterBaseUrl,
    'theme_path'      => $_lusterBaseUrl . '/assets/default/',
];

// ---------------------------------------------------------------------------
// 5. Render — three paths identical to MembersController::index()
// ---------------------------------------------------------------------------

if ($_lusterCountry !== '') {
    // Path A: filtered by a specific country
    $_lusterResult     = $_lusterMemberRepo->paginateByCountry(
        $_lusterListing->dbprefix, $_lusterCountry, $_lusterPage, $_lusterPerPage,
    );
    $_lusterStatsLine  = $_lusterCountry . ': ' . $_lusterResult['total'] . ' member(s)'
        . ($_lusterResult['pages'] > 1
            ? ' — page ' . $_lusterPage . ' of ' . $_lusterResult['pages']
            : '');
    $_lusterPagination = Pagination::render(
        $_lusterPage, $_lusterResult['pages'], $_lusterMembersBase, $_lusterPaginationTemplate,
        ['sort' => 'country', 'country' => $_lusterCountry],
    );

    $_lusterMembersHtml = null;
    if ($_lusterMemberTemplate !== null) {
        $_lusterMembersHtml = implode('', array_map($_lusterRenderMember, $_lusterResult['members']));
    }
    $_lusterHtml = $_lusterApp->getView()->renderFragment('members.php', array_merge($_lusterCommonData, [
        'country'         => $_lusterCountry,
        'members'         => $_lusterResult['members'],
        'members_html'    => $_lusterMembersHtml,
        'country_summary' => [],
        'stats_line'      => $_lusterStatsLine,
        'pagination'      => $_lusterPagination,
    ]));
} elseif ($_lusterSort === 'country' || ($_lusterSort !== 'name' && $_lusterSort !== 'added')) {
    // Path B: country summary
    $_lusterCountries      = $_lusterMemberRepo->getCountrySummary($_lusterListing->dbprefix);
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
        'members_html'    => null,
        'country_summary' => $_lusterCountrySummary,
        'stats_line'      => $_lusterStatsLine,
        'pagination'      => '',
    ]));
} else {
    // Path C: alphabetical by name
    $_lusterResult     = $_lusterMemberRepo->paginate(
        $_lusterListing->dbprefix, $_lusterPage, $_lusterPerPage, true, $_lusterSort,
    );
    $_lusterStatsLine  = $_lusterResult['total'] . ' member(s) — page '
        . $_lusterPage . ' of ' . $_lusterResult['pages'];
    $_lusterPagination = Pagination::render(
        $_lusterPage, $_lusterResult['pages'], $_lusterMembersBase, $_lusterPaginationTemplate,
        ['sort' => $_lusterSort],
    );

    $_lusterMembersHtml = null;
    if ($_lusterMemberTemplate !== null) {
        $_lusterMembersHtml = implode('', array_map($_lusterRenderMember, $_lusterResult['members']));
    }
    $_lusterHtml = $_lusterApp->getView()->renderFragment('members.php', array_merge($_lusterCommonData, [
        'country'         => '',
        'members'         => $_lusterResult['members'],
        'members_html'    => $_lusterMembersHtml,
        'country_summary' => [],
        'stats_line'      => $_lusterStatsLine,
        'pagination'      => $_lusterPagination,
    ]));
}

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
