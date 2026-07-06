<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable member list for a single fanlisting. Renders the approved member
 roster as an HTML fragment with pagination, sort controls, and country
 filtering. Supports a configurable per-page count, forced sort order, and
 toggling the search/filter UI. Sort and pagination links target the host page
 so visitors never leave the embedding site.
*/

declare(strict_types=1);

use Luster\Core\Pagination;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingTemplateResolver;
use Luster\Support\MembersTemplateRenderer;

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

// Sort and pagination links always point back to the host page that
// includes this embed, so visitors never leave it when paging or sorting.
$_lusterMembersBase = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/';

// $luster_per_page — resolved after settings repo is available (see below)
$_lusterPerPageOverride = (isset($luster_per_page) && (int) $luster_per_page > 0)
    ? (int) $luster_per_page
    : 0;

$_lusterPage    = max(1, (int) ($_GET['page'] ?? 1));

// $luster_sort_by — force a default sort, ignoring $_GET['sort'] when set
$_lusterRawSort = (isset($luster_sort_by) && in_array($luster_sort_by, ['name', 'country', 'added'], true))
    ? (string) $luster_sort_by
    : (string) ($_GET['sort'] ?? 'country');
$_lusterSort    = in_array($_lusterRawSort, ['name', 'country', 'added'], true) ? $_lusterRawSort : 'country';
$_lusterCountry = trim((string) ($_GET['country'] ?? ''));

// Build pre-rendered sort link tokens for the members template
$_lusterEscape          = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$_lusterSortLinkDate    = $_lusterSort === 'added'   ? '<strong aria-current="page" class="fl-member-sort-link">Date Joined</strong>'  : '<a class="fl-member-sort-link" href="' . $_lusterEscape($_lusterMembersBase . '?sort=added')   . '">Date Joined</a>';
$_lusterSortLinkName    = $_lusterSort === 'name'    ? '<strong aria-current="page" class="fl-member-sort-link">Name</strong>'      : '<a class="fl-member-sort-link" href="' . $_lusterEscape($_lusterMembersBase . '?sort=name')    . '">Name</a>';
$_lusterSortLinkCountry = $_lusterSort === 'country' ? '<strong aria-current="page" class="fl-member-sort-link">Country</strong>'   : '<a class="fl-member-sort-link" href="' . $_lusterEscape($_lusterMembersBase . '?sort=country') . '">Country</a>';

// $luster_show_search — whether to render sort/filter controls in the template
$_lusterShowSearch = (bool) ($luster_show_search ?? true);

// $luster_date_format — PHP date() format string for {fan_added}; blank ("Automatic")
// falls back to the ordinal style (e.g. "March 1st 2026"), not the raw stored value
$_lusterDateFormat = (string) ($luster_date_format ?? '');

$_lusterMemberRepo = new MemberRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());

// ---------------------------------------------------------------------------
// 4b. Load member row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSettingsRepo     = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterSiteRepo         = new SiteSettingsRepo($_lusterApp->getDatabase());

// Resolve per-page: embed override > per-listing setting > global default > built-in 25.
// Use getMembersPerPageOrNull() so an unset per-listing value (null) falls through to
// the global default instead of being mistaken for an explicit 25.
// The final fallback is a plain constant, not the admin panel's own `per_page`
// config value (config/app.php) — that key controls admin list-view pagination
// (Admin -> Members -> Settings) and has nothing to do with the public site;
// reusing it here would silently change the public embed's default whenever
// an admin adjusts their own admin-panel page size.
if ($_lusterPerPageOverride > 0) {
    $_lusterPerPage = $_lusterPerPageOverride;
} else {
    $_lusterPerPageDb = $_lusterSettingsRepo->getMembersPerPageOrNull($_lusterListingId);
    if ($_lusterPerPageDb !== null && $_lusterPerPageDb > 0) {
        $_lusterPerPage = $_lusterPerPageDb;
    } else {
        $globalMpp = $_lusterSiteRepo->get('listing_default_members_per_page');
        $_lusterPerPage = ($globalMpp !== null && (int) $globalMpp > 0)
            ? (int) $globalMpp
            : 25;
    }
}

$_lusterTemplates        = $_lusterSettingsRepo->getTemplates($_lusterListingId);
$_lusterGlobalTpls       = $_lusterSiteRepo->getGlobalTemplates();
$_lusterMembResolved     = ListingTemplateResolver::resolve(
    $_lusterTemplates['members_template'] ?? null,
    $_lusterGlobalTpls['listing_default_members_template'] ?? null,
    ListingSettingsRepo::defaultMembersTemplate(),
);
$_lusterMemberTemplate   = $_lusterMembResolved['should_render_template'] ? $_lusterMembResolved['value'] : null;
$_lusterPaginationTemplate = $_lusterSettingsRepo->getPaginationTemplate($_lusterListingId) ?? '';

$_lusterApplyMembersTemplate = static function (
    string $tpl,
    array  $members,
    int    $total,
    string $joinUrl,
    array  $fields,
    string $statsLine = '',
    string $sortLinkDate = '',
    string $sortLinkName = '',
    string $sortLinkCountry = '',
) use ($_lusterShowSearch, $_lusterDateFormat, $_lusterSort): string {
    return MembersTemplateRenderer::render(
        $tpl, $members, $fields, $joinUrl, $total,
        $statsLine, $sortLinkDate, $sortLinkName, $sortLinkCountry, $_lusterShowSearch, $_lusterDateFormat,
        $_lusterSort === 'added',
    );
};

// Shared data for all rendering paths
$_lusterGlobalDefaults = $_lusterSettingsRepo->getGlobalFormUrlDefaults();
$_lusterJoinUrl = \Luster\Support\ListingFormUrlResolver::resolve(
    (string) ($_lusterSettingsRepo->getResolvedEmbedSettings($_lusterListingId)['form_url_join'] ?? ''),
    $_lusterListing->url,
    $_lusterGlobalDefaults['form_url_join'],
);
$_lusterCommonData = [
    'listing'      => $_lusterListing,
    'listing_title' => $_lusterListing->title,
    'listing_url'  => $_lusterListing->url,
    'page_title'      => 'Members',
    'join_url'        => $_lusterJoinUrl,
    'members_base'    => $_lusterMembersBase,
    'sort'            => $_lusterSort,
    'show_search'     => $_lusterShowSearch,
    'date_format'     => $_lusterDateFormat,
    'fields'          => $_lusterFields,
    'base_url'        => $_lusterBaseUrl,
    'theme_path'      => $_lusterBaseUrl . '/assets/default/',
];

// ---------------------------------------------------------------------------
// 5. Render — three paths: country filter, country summary, default list
// ---------------------------------------------------------------------------

if ($_lusterCountry !== '') {
    // Path A: filtered by a specific country
    $_lusterResult     = $_lusterMemberRepo->paginateByCountry(
        $_lusterListing->dbprefix, $_lusterCountry, $_lusterPage, $_lusterPerPage,
    );
    if ($_lusterResult['total'] === 0) {
        $_lusterStatsLine = 'No members';
    } else {
        $_lusterStart     = ($_lusterPage - 1) * $_lusterPerPage + 1;
        $_lusterEnd       = min($_lusterPage * $_lusterPerPage, $_lusterResult['total']);
        $_lusterStatsLine = 'Showing ' . $_lusterStart . '–' . $_lusterEnd . ' of ' . $_lusterResult['total'] . ' members';
    }
    $_lusterPagination = Pagination::render(
        $_lusterPage, $_lusterResult['pages'], $_lusterMembersBase, $_lusterPaginationTemplate,
        ['sort' => 'country', 'country' => $_lusterCountry],
    );

    $_lusterMembersHtml = null;
    if ($_lusterMemberTemplate !== null) {
        $_lusterMembersHtml = $_lusterApplyMembersTemplate(
            $_lusterMemberTemplate, $_lusterResult['members'], $_lusterResult['total'],
            $_lusterJoinUrl, $_lusterFields,
            $_lusterStatsLine, $_lusterSortLinkDate, $_lusterSortLinkName, $_lusterSortLinkCountry,
        );
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
    $_lusterStatsLine = $_lusterTotal === 0 ? 'No countries' : 'Showing 1–' . $_lusterTotal . ' of ' . $_lusterTotal . ' ' . ($_lusterTotal === 1 ? 'country' : 'countries');

    $_lusterMembersHtml = null;
    if ($_lusterMemberTemplate !== null) {
        $_lusterE = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $_lusterCountryRows = '';
        foreach ($_lusterCountrySummary as $_lusterCRow) {
            $_lusterCountryRows .= '<li class="fl-member-item"><a class="fl-member-name" href="'
                . $_lusterE($_lusterCRow['url']) . '">' . $_lusterE($_lusterCRow['country'])
                . '</a> <span class="fl-member-country">(' . (int) $_lusterCRow['fan_count'] . ')</span></li>' . "\n";
        }
        $_lusterCountryBody = '<ul class="fl-member-list">' . "\n" . $_lusterCountryRows . '</ul>';
        $_lusterMembersHtml = MembersTemplateRenderer::renderWithBody(
            $_lusterMemberTemplate, $_lusterCountryBody,
            $_lusterJoinUrl, $_lusterTotal,
            $_lusterStatsLine, $_lusterSortLinkDate, $_lusterSortLinkName, $_lusterSortLinkCountry,
            $_lusterFields, $_lusterShowSearch,
        );
    }

    $_lusterHtml = $_lusterApp->getView()->renderFragment('members.php', array_merge($_lusterCommonData, [
        'country'         => '',
        'members'         => [],
        'members_html'    => $_lusterMembersHtml,
        'country_summary' => $_lusterCountrySummary,
        'stats_line'      => $_lusterStatsLine,
        'pagination'      => '',
    ]));
} else {
    // Path C: alphabetical by name
    $_lusterResult     = $_lusterMemberRepo->paginate(
        $_lusterListing->dbprefix, $_lusterPage, $_lusterPerPage, true, $_lusterSort,
    );
    if ($_lusterResult['total'] === 0) {
        $_lusterStatsLine = 'No members';
    } else {
        $_lusterStart     = ($_lusterPage - 1) * $_lusterPerPage + 1;
        $_lusterEnd       = min($_lusterPage * $_lusterPerPage, $_lusterResult['total']);
        $_lusterStatsLine = 'Showing ' . $_lusterStart . '–' . $_lusterEnd . ' of ' . $_lusterResult['total'] . ' members';
    }
    $_lusterPagination = Pagination::render(
        $_lusterPage, $_lusterResult['pages'], $_lusterMembersBase, $_lusterPaginationTemplate,
        ['sort' => $_lusterSort],
    );

    $_lusterMembersHtml = null;
    if ($_lusterMemberTemplate !== null) {
        $_lusterMembersHtml = $_lusterApplyMembersTemplate(
            $_lusterMemberTemplate, $_lusterResult['members'], $_lusterResult['total'],
            $_lusterJoinUrl, $_lusterFields,
            $_lusterStatsLine, $_lusterSortLinkDate, $_lusterSortLinkName, $_lusterSortLinkCountry,
        );
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
