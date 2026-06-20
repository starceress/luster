<?php

declare(strict_types=1);

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Pagination;
use Luster\Core\TemplateEngine;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Luster embed — KIM List overview or per-fanlisting subject fragment.
 *
 * Overview mode (default):
 *   $luster_kim_list_base        = 'https://example.com/kim-list.php'; // optional — base for per-fanlisting links
 *   $luster_kim_signup_url       = 'https://example.com/kim.php';      // optional — sign-up link override
 *   $luster_kim_only_with_signups = true;                              // optional — false = show all listings even with no sign-ups
 *   $luster_return               = false;
 *   require '/absolute/path/to/luster/public/embed/kim-list.php';
 *
 * Subject mode (per-fanlisting approved members):
 *   $luster_kim_subject_id = (int) ($_GET['id'] ?? 0);           // listing ID to show members for
 *   $luster_kim_list_base  = 'https://example.com/kim-list.php'; // optional — "Back to KIM List" URL
 *   $luster_kim_signup_url = 'https://example.com/kim.php';      // optional — sign-up link override
 *   $luster_per_page       = 25;                                 // optional — approved members per page
 *   $luster_return         = false;
 *   require '/absolute/path/to/luster/public/embed/kim-list.php';
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
$_lusterApp->getSession()->start();

// ---------------------------------------------------------------------------
// 2. Check KIM addon is installed
// ---------------------------------------------------------------------------

if (!$_lusterApp->isAddonInstalled('Kim')) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: KIM List add-on is not installed.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 3. Resolve mode + override variables
// ---------------------------------------------------------------------------

$_lusterSubjectId       = (int) ($luster_kim_subject_id ?? 0);
$_lusterOnlyWithSignups = (bool) ($luster_kim_only_with_signups ?? true);
$_lusterPerPage         = isset($luster_per_page) ? max(1, (int) $luster_per_page) : 25;
$_lusterPage            = max(1, (int) ($_GET['page'] ?? 1));
$_lusterListBase        = isset($luster_kim_list_base) && (string) $luster_kim_list_base !== ''
    ? (string) $luster_kim_list_base
    : null;

// ---------------------------------------------------------------------------
// 4. Resolve KIM page URLs (signup link; fallback to site-settings values)
// ---------------------------------------------------------------------------

$_lusterSiteSettings = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterKimMemberTemplate = $_lusterSiteSettings->get('kim_member_template');
$_lusterKimUrls      = $_lusterSiteSettings->getMany([
    'kim_url_signup', 'kim_url_update', 'kim_url_reset', 'kim_url_list',
]);

$_lusterKimParsed   = parse_url((string) ($_lusterApp->getConfig()->get('site_url', '')));
$_lusterKimSiteRoot = ($_lusterKimParsed['scheme'] ?? 'https') . '://' . ($_lusterKimParsed['host'] ?? '');
if (!empty($_lusterKimParsed['port'])) {
    $_lusterKimSiteRoot .= ':' . $_lusterKimParsed['port'];
}
$_toKimLink = static fn(?string $v): ?string =>
    ($v !== null && $v !== '') ? $_lusterKimSiteRoot . '/' . ltrim($v, '/') : null;

$_lusterLinkSignup = $luster_kim_signup_url ?? $_toKimLink($_lusterKimUrls['kim_url_signup'] ?? null) ?? $_lusterKimSiteRoot . '/kim.php';

// ---------------------------------------------------------------------------
// 5. Render the appropriate fragment
// ---------------------------------------------------------------------------

$_lusterKimRepo = new KimMemberRepo($_lusterApp->getDatabase());
$_lusterBaseUrl = $_lusterApp->getBaseUrl();

if ($_lusterSubjectId > 0) {
    // -----------------------------------------------------------------------
    // Subject mode — approved KIM members for one fanlisting
    // -----------------------------------------------------------------------
    $_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
    $_lusterListing     = $_lusterListingRepo->getById($_lusterSubjectId);

    if ($_lusterListing === null) {
        $_lusterHtml = '<p class="fl-errors">Luster embed error: that fanlisting does not exist.</p>';
        if ($luster_return ?? false) {
            return $_lusterHtml;
        }
        echo $_lusterHtml;
        return;
    }

    $_lusterTotal   = $_lusterKimRepo->countApprovedByFanlisting($_lusterSubjectId);
    $_lusterPages   = max(1, (int) ceil($_lusterTotal / $_lusterPerPage));
    $_lusterMembers = $_lusterKimRepo->getApprovedByFanlistingPaged($_lusterSubjectId, $_lusterPage, $_lusterPerPage);

    $_lusterSubjectBaseUrl     = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '/';
    $_lusterPaginationTemplate = (string) ($_lusterSiteSettings->get('collective_pagination_template') ?? '');
    $_lusterPagination         = Pagination::render(
        $_lusterPage,
        $_lusterPages,
        $_lusterSubjectBaseUrl,
        $_lusterPaginationTemplate,
        ['id' => $_lusterSubjectId],
    );

    // Apply kim_member_template if set
    $_lusterKimMemberHtml = null;
    if ($_lusterKimMemberTemplate !== null && trim($_lusterKimMemberTemplate) !== '') {
        $_lusterKimMemberHtml = TemplateEngine::loop(
            $_lusterKimMemberTemplate,
            'kim_member',
            $_lusterMembers,
            static function (string $inner, object $member) use ($_lusterListing): string {
                return TemplateEngine::render(
                    $inner,
                    [
                        'kim_name'        => $member->name,
                        'kim_url'         => $member->url,
                        'listing_title'   => $_lusterListing->title,
                        'listing_subject' => $_lusterListing->subject,
                    ],
                    [],
                    [
                        'kim_url'    => $member->url !== '',
                        'no_kim_url' => $member->url === '',
                    ],
                );
            },
        );
    }

    $_lusterSubjectTpl = $_lusterSiteSettings->get('collective_kim_list_subject_template') ?? '';
    $_lusterSubjectTemplateHtml = null;
    if ($_lusterSubjectTpl !== '') {
        $_lusterHasMembers = count($_lusterMembers) > 0;
        $_lusterTplOut = TemplateEngine::conditionals($_lusterSubjectTpl, [
            'has_kim_members' => $_lusterHasMembers,
            'no_kim_members'  => !$_lusterHasMembers,
        ]);
        $_lusterTplOut = TemplateEngine::loop(
            $_lusterTplOut,
            'kim_member',
            $_lusterMembers,
            static function (string $inner, object $member): string {
                return TemplateEngine::render(
                    $inner,
                    ['member_name' => $member->name, 'member_url' => $member->url],
                    [],
                    ['member_url' => $member->url !== '', 'no_member_url' => $member->url === ''],
                );
            },
        );
        $_lusterKimOverviewUrl = $_lusterListBase ?? $_lusterBaseUrl . '/kim/list';
        $_lusterKimSignupUrl  = $_lusterLinkSignup ?? $_lusterBaseUrl . '/kim';
        $_lusterTplOut = TemplateEngine::conditionals($_lusterTplOut, [
            'kim_overview_url' => $_lusterKimOverviewUrl !== '',
            'kim_signup_url'   => $_lusterKimSignupUrl !== '',
        ]);
        $_lusterSubjectTemplateHtml = TemplateEngine::tokens(
            $_lusterTplOut,
            [
                'listing_title'    => $_lusterListing->title,
                'listing_subject'  => $_lusterListing->subject,
                'listing_url'      => $_lusterListing->url,
                'kim_overview_url' => $_lusterKimOverviewUrl,
                'kim_signup_url'   => $_lusterKimSignupUrl,
            ],
            ['pagination' => $_lusterPagination],
        );
    }

    $_lusterHtml = $_lusterApp->getView()->render('default/addons/kim/subject.php', [
        'listing'                  => $_lusterListing,
        'members'                  => $_lusterMembers,
        'base_url'                 => $_lusterBaseUrl,
        'theme_path'               => $_lusterBaseUrl . '/assets/default/',
        'link_list'                => $_lusterListBase,
        'link_signup'              => $_lusterLinkSignup,
        'kim_member_template_html' => $_lusterKimMemberHtml,
        'subject_template_html'    => $_lusterSubjectTemplateHtml,
        'pagination'               => $_lusterPagination,
        'page'                     => $_lusterPage,
        'pages'                    => $_lusterPages,
        'total'                    => $_lusterTotal,
    ]);
} else {
    // -----------------------------------------------------------------------
    // Overview mode — all (or only active) fanlistings with KIM interest counts
    // -----------------------------------------------------------------------
    $_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());

    $_lusterFanlistings   = array_filter(
        $_lusterListingRepo->getAll(),
        static fn($fl) => !$fl->trouble,
    );
    $_lusterApprovedCounts = $_lusterKimRepo->getApprovedCountsAll();

    $_lusterFanlistingsWithCounts = [];
    foreach ($_lusterFanlistings as $_fl) {
        $_lusterCount = $_lusterApprovedCounts[$_fl->id] ?? 0;
        if ($_lusterOnlyWithSignups && $_lusterCount === 0) {
            continue;
        }
        $_lusterFanlistingsWithCounts[] = [
            'listing' => $_fl,
            'count'   => $_lusterCount,
        ];
    }

    $_lusterOverviewTpl = $_lusterSiteSettings->get('collective_kim_list_overview_template') ?? '';
    $_lusterOverviewTemplateHtml = null;
    if ($_lusterOverviewTpl !== '') {
        $_lusterHasListings = count($_lusterFanlistingsWithCounts) > 0;
        $_lusterTplOut = TemplateEngine::conditionals($_lusterOverviewTpl, [
            'has_listings' => $_lusterHasListings,
            'no_listings'  => !$_lusterHasListings,
        ]);
        $_lusterOverviewTemplateHtml = TemplateEngine::loop(
            $_lusterTplOut,
            'kim_listing',
            array_values($_lusterFanlistingsWithCounts),
            static function (string $inner, mixed $entry) use ($_lusterListBase, $_lusterBaseUrl): string {
                $fl    = $entry['listing'];
                $count = (int) $entry['count'];
                $url   = $_lusterListBase !== null
                    ? $_lusterListBase . '?id=' . (int) $fl->id
                    : $_lusterBaseUrl . '/kim/list/' . (int) $fl->id;
                return TemplateEngine::render($inner, [
                    'listing_title'     => $fl->title,
                    'listing_subject'   => $fl->subject,
                    'listing_url'       => $fl->url,
                    'listing_kim_url'   => $url,
                    'listing_kim_count' => (string) $count,
                ]);
            },
        );
    }

    $_lusterHtml = $_lusterApp->getView()->render('default/addons/kim/list.php', [
        'fanlistings_with_counts' => $_lusterFanlistingsWithCounts,
        'base_url'                => $_lusterBaseUrl,
        'theme_path'              => $_lusterBaseUrl . '/assets/default/',
        'link_signup'             => $_lusterLinkSignup,
        'link_list_base'          => $_lusterListBase,
        'overview_template_html'  => $_lusterOverviewTemplateHtml,
    ]);
}


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
