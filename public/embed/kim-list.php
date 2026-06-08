<?php

declare(strict_types=1);

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\TemplateEngine;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Luster embed — KIM List overview or per-fanlisting subject fragment.
 *
 * Overview mode (default):
 *   $luster_kim_list_base  = 'https://example.com/kim-list.php'; // optional — base for per-fanlisting links
 *   $luster_kim_signup_url = 'https://example.com/kim.php';      // optional — sign-up link override
 *   $luster_return         = false;
 *   require '/absolute/path/to/luster/public/embed/kim-list.php';
 *
 * Subject mode (per-fanlisting approved members):
 *   $luster_kim_subject_id = (int) ($_GET['id'] ?? 0);           // listing ID to show members for
 *   $luster_kim_list_base  = 'https://example.com/kim-list.php'; // optional — "Back to KIM List" URL
 *   $luster_kim_signup_url = 'https://example.com/kim.php';      // optional — sign-up link override
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

$_lusterSubjectId = (int) ($luster_kim_subject_id ?? 0);
$_lusterListBase  = isset($luster_kim_list_base) && (string) $luster_kim_list_base !== ''
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

$_lusterKimParsed   = parse_url((string) $_lusterApp->getConfig()->get('app_url', ''));
$_lusterKimSiteRoot = ($_lusterKimParsed['scheme'] ?? 'https') . '://' . ($_lusterKimParsed['host'] ?? '');
if (!empty($_lusterKimParsed['port'])) {
    $_lusterKimSiteRoot .= ':' . $_lusterKimParsed['port'];
}
$_toKimLink = static fn(?string $v): ?string =>
    ($v !== null && $v !== '') ? $_lusterKimSiteRoot . '/' . ltrim($v, '/') : null;

$_lusterLinkSignup = $luster_kim_signup_url ?? $_toKimLink($_lusterKimUrls['kim_url_signup'] ?: null);

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

    $_lusterMembers = $_lusterKimRepo->getApprovedByFanlisting($_lusterSubjectId);

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

    $_lusterHtml = $_lusterApp->getView()->render('default/addons/kim/subject.php', [
        'listing'                => $_lusterListing,
        'members'                => $_lusterMembers,
        'base_url'               => $_lusterBaseUrl,
        'theme_path'             => $_lusterBaseUrl . '/assets/default/',
        'link_list'              => $_lusterListBase,
        'link_signup'            => $_lusterLinkSignup,
        'kim_member_template_html' => $_lusterKimMemberHtml,
    ]);
} else {
    // -----------------------------------------------------------------------
    // Overview mode — all fanlistings with KIM interest counts
    // -----------------------------------------------------------------------
    $_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());

    $_lusterFanlistings = array_filter(
        $_lusterListingRepo->getAll(),
        static fn($fl) => !$fl->trouble,
    );

    $_lusterFanlistingsWithCounts = [];
    foreach ($_lusterFanlistings as $_fl) {
        $_lusterFanlistingsWithCounts[] = [
            'listing' => $_fl,
            'count'   => $_lusterKimRepo->countApprovedByFanlisting($_fl->id),
        ];
    }

    $_lusterHtml = $_lusterApp->getView()->render('default/addons/kim/list.php', [
        'fanlistings_with_counts' => $_lusterFanlistingsWithCounts,
        'base_url'                => $_lusterBaseUrl,
        'theme_path'              => $_lusterBaseUrl . '/assets/default/',
        'link_signup'             => $_lusterLinkSignup,
        'link_list_base'          => $_lusterListBase,
    ]);
}

$_lusterHtml .= \Luster\Core\View::attribution();

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
