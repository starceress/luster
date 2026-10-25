<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable KIM (Keep In Mind) List display. Has two modes: overview mode
 shows all fanlistings that have KIM sign-ups, with links to drill into each
 one; subject mode shows the approved KIM members for a single fanlisting.
 Requires the KIM add-on to be installed. Supports configurable URLs for the
 sign-up form and list base, and pagination in subject mode.
*/

declare(strict_types=1);

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Pagination;
use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

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
$_lusterSessionError = View::startEmbedSession($_lusterApp->getSession());
if ($_lusterSessionError !== null) {
    if ($luster_return ?? false) {
        return $_lusterSessionError;
    }
    echo $_lusterSessionError;
    return;
}

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
$_lusterListBaseOverride = isset($luster_kim_list_base) && (string) $luster_kim_list_base !== ''
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
$_lusterListBase   = $_lusterListBaseOverride ?? $_toKimLink($_lusterKimUrls['kim_url_list'] ?? null) ?? $_lusterKimSiteRoot . '/kim-list.php';

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
    if ($_lusterSubjectTpl !== '') {
        $_lusterSubjectTpl = str_replace('{site_name}', htmlspecialchars((string) $_lusterApp->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $_lusterSubjectTpl);
    }
    $_lusterSubjectTemplateHtml = null;
    if ($_lusterSubjectTpl !== '') {
        $_lusterHasMembers     = count($_lusterMembers) > 0;
        $_lusterKimOverviewUrl = $_lusterListBase ?? '';
        $_lusterKimSignupUrl   = $_lusterLinkSignup ?? '';

        // All four conditions are evaluated in a single pass, before the
        // loop splices in member names - member names come from the public
        // KIM sign-up form and could contain literal {if_x}/{/if_x} text
        // that would hijack a conditionals() pass run afterward.
        $_lusterTplOut = TemplateEngine::conditionals($_lusterSubjectTpl, [
            'has_kim_members'  => $_lusterHasMembers,
            'no_kim_members'   => !$_lusterHasMembers,
            'kim_overview_url' => $_lusterKimOverviewUrl !== '',
            'kim_signup_url'   => $_lusterKimSignupUrl !== '',
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
    if ($_lusterOverviewTpl !== '') {
        $_lusterOverviewTpl = str_replace('{site_name}', htmlspecialchars((string) $_lusterApp->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $_lusterOverviewTpl);
    }
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
            static function (string $inner, mixed $entry) use ($_lusterListBase): string {
                $fl    = $entry['listing'];
                $count = (int) $entry['count'];
                $url   = $_lusterListBase !== null
                    ? $_lusterListBase . (str_contains($_lusterListBase, '?') ? '&' : '?') . 'id=' . (int) $fl->id
                    : '#';
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


$_lusterHtml .= $luster_attribution;

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
