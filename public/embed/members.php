<?php

declare(strict_types=1);

use Luster\Core\Pagination;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingTemplateResolver;

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
 *   $luster_sort_by     string  Force a default sort: 'name', 'country', or 'added'.
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
$luster_attribution = \Luster\Core\View::attribution();
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

// Build pre-rendered sort link tokens for the members template
$_lusterEscape          = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$_lusterSortLinkAll     = $_lusterSort === 'added'   ? '<strong aria-current="page" class="fl-member-sort-link">View All</strong>'  : '<a class="fl-member-sort-link" href="' . $_lusterEscape($_lusterMembersBase . '?sort=added')   . '">View All</a>';
$_lusterSortLinkName    = $_lusterSort === 'name'    ? '<strong aria-current="page" class="fl-member-sort-link">Name</strong>'      : '<a class="fl-member-sort-link" href="' . $_lusterEscape($_lusterMembersBase . '?sort=name')    . '">Name</a>';
$_lusterSortLinkCountry = $_lusterSort === 'country' ? '<strong aria-current="page" class="fl-member-sort-link">Country</strong>'   : '<a class="fl-member-sort-link" href="' . $_lusterEscape($_lusterMembersBase . '?sort=country') . '">Country</a>';

// $luster_show_search — whether to render sort/filter controls in the template
$_lusterShowSearch = (bool) ($luster_show_search ?? true);

$_lusterMemberRepo = new MemberRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());

// ---------------------------------------------------------------------------
// 4b. Load member row template from DB (if set)
// ---------------------------------------------------------------------------

$_lusterSettingsRepo     = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterTemplates        = $_lusterSettingsRepo->getTemplates($_lusterListingId);
$_lusterGlobalTpls       = (new SiteSettingsRepo($_lusterApp->getDatabase()))->getGlobalTemplates();
$_lusterMembResolved     = ListingTemplateResolver::resolve(
    $_lusterTemplates['members_template'] ?? null,
    $_lusterGlobalTpls['listing_default_members_template'] ?? null,
    ListingSettingsRepo::defaultMembersTemplate(),
);
$_lusterMemberTemplate   = $_lusterMembResolved['should_render_template'] ? $_lusterMembResolved['value'] : null;
$_lusterPaginationTemplate = $_lusterSettingsRepo->getPaginationTemplate($_lusterListingId) ?? '';

/**
 * Applies a members list template — supports {each_member} loop, per-member
 * conditionals ({if_fan_url}, {if_no_fan_url}, {if_fan_country}, {if_no_fan_country}),
 * page-level conditionals ({if_members}, {if_no_members}), and page-level
 * tokens ({join_url}, {member_count}).
 * Legacy templates (no {each_member} block) are handled as per-row templates.
 */
$_lusterApplyMembersTemplate = static function (
    string $tpl,
    array  $members,
    int    $total,
    string $joinUrl,
    array  $fields,
    string $statsLine = '',
    string $sortLinkAll = '',
    string $sortLinkName = '',
    string $sortLinkCountry = '',
) use ($_lusterListingId): string {
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $applyRowConditionals = static function (string $row, object $m): string {
        $hasUrl     = ($m->url     ?? '') !== '';
        $hasCountry = ($m->country ?? '') !== '';

        if ($hasUrl) {
            $row = preg_replace('/\{if_no_fan_url\}.*?\{\/if_no_fan_url\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_fan_url}', '{/if_fan_url}'], '', $row);
        } else {
            $row = preg_replace('/\{if_fan_url\}.*?\{\/if_fan_url\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_no_fan_url}', '{/if_no_fan_url}'], '', $row);
        }
        if ($hasCountry) {
            $row = preg_replace('/\{if_no_fan_country\}.*?\{\/if_no_fan_country\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_fan_country}', '{/if_fan_country}'], '', $row);
        } else {
            $row = preg_replace('/\{if_fan_country\}.*?\{\/if_fan_country\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_no_fan_country}', '{/if_no_fan_country}'], '', $row);
        }
        return $row;
    };

    $buildTokens = static function (object $m) use ($e, $fields): array {
        $tokens = [
            '{fan_name}'    => $e($m->name),
            '{fan_url}'     => $e($m->url     ?? ''),
            '{fan_email}'   => $e($m->email   ?? ''),
            '{fan_country}' => $e($m->country ?? ''),
        ];
        foreach ($fields as $field) {
            $val = (string) ($m->additionalFields[$field->name] ?? '');
            $tokens['{fan_' . $field->name . '}'] = $e($val);
        }
        return $tokens;
    };

    if (preg_match('/\{each_member\}(.*?)\{\/each_member\}/s', $tpl, $eachMatch)) {
        $rowTpl   = $eachMatch[1];
        $rendered = '';
        foreach ($members as $member) {
            $row     = $applyRowConditionals($rowTpl, $member);
            $tokens  = $buildTokens($member);
            $rendered .= str_replace(array_keys($tokens), array_values($tokens), $row);
        }
        $tpl = (string) preg_replace('/\{each_member\}.*?\{\/each_member\}/s', $rendered, $tpl, 1);

        if ($total > 0) {
            $tpl = preg_replace('/\{if_no_members\}.*?\{\/if_no_members\}/s', '', $tpl) ?? $tpl;
            $tpl = str_replace(['{if_members}', '{/if_members}'], '', $tpl);
        } else {
            $tpl = preg_replace('/\{if_members\}.*?\{\/if_members\}/s', '', $tpl) ?? $tpl;
            $tpl = str_replace(['{if_no_members}', '{/if_no_members}'], '', $tpl);
        }

        return str_replace(
            ['{join_url}', '{member_count}', '{stats_line}', '{sort_link_all}', '{sort_link_name}', '{sort_link_country}'],
            [$e($joinUrl), (string) $total, $e($statsLine), $sortLinkAll, $sortLinkName, $sortLinkCountry],
            $tpl,
        );
    }

    // Legacy: entire template is per-row
    $rows = [];
    foreach ($members as $member) {
        $row    = $applyRowConditionals($tpl, $member);
        $tokens = $buildTokens($member);
        $rows[] = str_replace(array_keys($tokens), array_values($tokens), $row);
    }
    return implode("\n", $rows);
};

// Shared data for all rendering paths
$_lusterCommonData = [
    'listing'      => $_lusterListing,
    'listing_title' => $_lusterListing->title,
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
            $_lusterBaseUrl . '/join/' . $_lusterListingId, $_lusterFields,
            $_lusterStatsLine, $_lusterSortLinkAll, $_lusterSortLinkName, $_lusterSortLinkCountry,
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
            $_lusterBaseUrl . '/join/' . $_lusterListingId, $_lusterFields,
            $_lusterStatsLine, $_lusterSortLinkAll, $_lusterSortLinkName, $_lusterSortLinkCountry,
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
