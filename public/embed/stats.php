<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable statistics block for a single fanlisting. Renders the stats
 snippet using the admin-editable template stored in the database, with
 {token} placeholders replaced by live data (member counts, newest members,
 growth rate, network approval status, etc.). The output is identical to the
 built-in GET /stats route but available as a server-side PHP include.
*/

declare(strict_types=1);

use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\DateFormatter;
use Luster\Support\ListingTemplateResolver;

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

// ---------------------------------------------------------------------------
// 3. Load fanlisting
// ---------------------------------------------------------------------------

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterListing     = $_lusterListingRepo->getById($_lusterListingId);

if ($_lusterListing === null || $_lusterListing->upcoming || $_lusterListing->trouble) {
    $_lusterHtml = '<!-- Luster: stats not available for listing #' . $_lusterListingId . ' -->';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 4. Load settings + gather stats
// ---------------------------------------------------------------------------

$_lusterSettingsRepo    = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterSiteSettingsRepo = new SiteSettingsRepo($_lusterApp->getDatabase());

$_lusterNewestLimit = (static function (?int $pl, ?string $gl): int {
    if ($pl !== null) return $pl;
    if ($gl !== null && $gl !== '' && ctype_digit($gl)) return (int) $gl;
    return 5;
})(
    $_lusterSettingsRepo->getStatNewmembersLimit($_lusterListingId),
    $_lusterSiteSettingsRepo->get('stat_newmembers_limit'),
);

$_lusterMemberRepo = new MemberRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterPrefix     = $_lusterListing->dbprefix;

$_lusterApproved   = $_lusterMemberRepo->countApproved($_lusterPrefix);
$_lusterPending    = $_lusterMemberRepo->countPending($_lusterPrefix);
$_lusterCountries  = $_lusterMemberRepo->countUniqueCountries($_lusterPrefix);
$_lusterNewest     = $_lusterMemberRepo->getNewestMembers($_lusterPrefix, $_lusterNewestLimit);
$_lusterMemberDate = $_lusterMemberRepo->getMostRecentApprovedDate($_lusterPrefix);

// Expose output variables for host pages — all pre-escaped with htmlspecialchars.
// These are available after the embed runs, for use in the host page's <head> or body.
$luster_description     = htmlspecialchars(
    (string) ($_lusterSettingsRepo->getDescription($_lusterListingId) ?? ''),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8',
);
$luster_page_title      = htmlspecialchars($_lusterListing->title,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$luster_listing_title   = $luster_page_title;
$luster_listing_subject = htmlspecialchars($_lusterListing->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$luster_listing_url     = htmlspecialchars($_lusterListing->url,     ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
// $luster_meta_description: description if set, else constructed from subject
$luster_meta_description = $luster_description !== ''
    ? $luster_description
    : htmlspecialchars('A fanlisting for ' . $_lusterListing->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ---------------------------------------------------------------------------
// 5. Load template from DB (falls back to default)
// ---------------------------------------------------------------------------

$_lusterTemplates    = $_lusterSettingsRepo->getTemplates($_lusterListingId);

// Last Updated — from lstr_fanlisting_settings.last_updated (admin-controlled)
$_lusterAdminDate = $_lusterSettingsRepo->getLastUpdated($_lusterListingId);

$_lusterGlobalTpls   = $_lusterSiteSettingsRepo->getGlobalTemplates();
$_lusterStatResolved = ListingTemplateResolver::resolve(
    $_lusterTemplates['stats_template'] ?? null,
    $_lusterGlobalTpls['listing_default_stats_template'] ?? null,
    ListingSettingsRepo::defaultStatsTemplate(),
);
$_lusterTemplate     = $_lusterStatResolved['value'];

// Allow an inline override set before require (e.g. from the customizer-generated code).
if (isset($luster_stats_template) && trim((string) $luster_stats_template) !== '') {
    $_lusterTemplate = (string) $luster_stats_template;
}

// ---------------------------------------------------------------------------
// 6. Format dates
// ---------------------------------------------------------------------------

$_lusterDateFmt     = (string) ($luster_date_format ?? '');
$_lusterOpened = $_lusterListing->joined !== null
    ? ($_lusterDateFmt !== '' ? DateFormatter::formatWith($_lusterListing->joined, $_lusterDateFmt) : DateFormatter::format($_lusterListing->joined))
    : 'Unknown';

$_lusterUpdatedDate = DateFormatter::mostRecent($_lusterMemberDate, $_lusterAdminDate);
$_lusterUpdated     = $_lusterUpdatedDate !== null
    ? ($_lusterDateFmt !== '' ? DateFormatter::formatWith($_lusterUpdatedDate, $_lusterDateFmt) : DateFormatter::format($_lusterUpdatedDate))
    : 'N/A';

// ---------------------------------------------------------------------------
// 7. Build token values
// ---------------------------------------------------------------------------

$_lusterNewMembers = (static function (array $members, int $totalApproved): string {
    if ($members === []) {
        return '';
    }

    $formatted = array_map(static function (array $m): string {
        $name = htmlspecialchars($m['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($m['url'] !== '') {
            $url = htmlspecialchars($m['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<a href="' . $url . '">' . $name . '</a>';
        }
        return $name;
    }, $members);

    $hasMore = $totalApproved > count($members);

    if ($hasMore) {
        return implode(', ', $formatted) . ', and more&hellip;';
    }

    return match (count($formatted)) {
        1       => $formatted[0],
        2       => $formatted[0] . ' and ' . $formatted[1],
        default => implode(', ', array_slice($formatted, 0, -1)) . ', and ' . end($formatted),
    };
})($_lusterNewest, $_lusterApproved);

$_lusterGrowth = '0.00';
if ($_lusterListing->joined !== null) {
    $_lusterOpenedTs = strtotime($_lusterListing->joined);
    if ($_lusterOpenedTs !== false) {
        $_lusterDays   = max(1, (int) round((time() - $_lusterOpenedTs) / 86400));
        $_lusterGrowth = number_format($_lusterApproved / $_lusterDays, 2);
    }
}

// ---------------------------------------------------------------------------
// 8. Replace tokens and output
// ---------------------------------------------------------------------------

$_lusterApprovedBy   = $_lusterSettingsRepo->getApprovedBy($_lusterListingId);
$_lusterApprovalUrl  = $_lusterSettingsRepo->getApprovalUrl($_lusterListingId) ?? '';
$_lusterNetwork      = strtoupper($_lusterApprovedBy);
$_lusterNetworkName  = match ($_lusterApprovedBy) {
    'tfl'   => 'The Fanlistings Network',
    'tafl'  => 'The Anime Fanlistings Network',
    default => '',
};
// {tfl_url} / {tafl_url}: use the per-listing approval URL when set,
// otherwise fall back to the network's home page.
$_lusterTflUrl  = ($_lusterApprovedBy === 'tfl'  && $_lusterApprovalUrl !== '') ? $_lusterApprovalUrl : 'https://thefanlistings.org/';
$_lusterTaflUrl = ($_lusterApprovedBy === 'tafl' && $_lusterApprovalUrl !== '') ? $_lusterApprovalUrl : 'https://www.animefanlistings.com/';

// Conditional blocks — must run before flat str_replace
$_lusterTemplate = (string) preg_replace(
    '/\{if_approved_by\}(.*?)\{\/if_approved_by\}/s',
    $_lusterApprovedBy !== '' ? '$1' : '',
    $_lusterTemplate,
);
$_lusterTemplate = (string) preg_replace(
    '/\{if_tfl\}(.*?)\{\/if_tfl\}/s',
    $_lusterApprovedBy === 'tfl' ? '$1' : '',
    $_lusterTemplate,
);
$_lusterTemplate = (string) preg_replace(
    '/\{if_tafl\}(.*?)\{\/if_tafl\}/s',
    $_lusterApprovedBy === 'tafl' ? '$1' : '',
    $_lusterTemplate,
);
$_lusterTemplate = (string) preg_replace(
    '/\{if_stat_pending\}(.*?)\{\/if_stat_pending\}/s',
    $_lusterPending > 0 ? '$1' : '',
    $_lusterTemplate,
);

$_lusterHtml = str_replace(
    [
        '{stat_opened}',
        '{stat_updated}',
        '{stat_approved}',
        '{stat_pending}',
        '{stat_countries}',
        '{stat_newmembers}',
        '{stat_growth}',
        '{stat_growth_rate}',
        '{listing_title}',
        '{listing_subject}',
        '{listing_url}',
        '{approved_by}',
        '{network_name}',
        '{tfl_url}',
        '{tafl_url}',
        '{approval_url}',
    ],
    [
        $_lusterOpened,
        $_lusterUpdated,
        (string) $_lusterApproved,
        (string) $_lusterPending,
        (string) $_lusterCountries,
        $_lusterNewMembers,
        $_lusterGrowth . ' members/day',
        $_lusterGrowth,
        htmlspecialchars($_lusterListing->title,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($_lusterListing->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($_lusterListing->url,     ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        $_lusterNetwork,
        $_lusterNetworkName,
        htmlspecialchars($_lusterTflUrl,      ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($_lusterTaflUrl,     ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($_lusterApprovalUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ],
    $_lusterTemplate,
);


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
