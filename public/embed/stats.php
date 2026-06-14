<?php

declare(strict_types=1);

use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingTemplateResolver;

/**
 * Luster embed — stats fragment.
 *
 * Outputs the listing stats snippet using the template stored in
 * lstr_fanlisting_settings.stats_template, with {token} placeholders
 * replaced with live data. Identical output to GET /stats/{listingId}
 * but available as a server-side PHP include.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/stats.php';
 *
 * Available {token} placeholders (same as the /stats/{id} endpoint):
 *   {stat_opened}       — date listing opened ("March 1st 2026")
 *   {stat_updated}      — date of most recent approved member (same format)
 *   {stat_last_updated} — admin-controlled "last updated" date from
 *                         lstr_fanlisting_settings; falls back to the
 *                         last_updated_fallback config value (default "Never")
 *   {stat_approved}     — approved member count
 *   {stat_pending}      — pending member count
 *   {stat_countries}    — unique country count for approved members
 *   {stat_newmembers}        — comma-separated newest 5 approved member names
 *   {stat_newmembers_oxford} — newest members formatted as a sentence list
 *   {stat_newmembers_label}  — "Member" when count is 1, "Members" otherwise
 *   {stat_growth}       — average members per day since opened, e.g. "1.23 members/day"
 *   {listing_title}      — listing name (HTML-escaped)
 *   {listing_url}       — listing URL (HTML-escaped)
 *   {approved_by}       — linked network name ("The Fanlistings Network" or
 *                         "The Anime Fanlistings Network"), or '' if not approved
 *   {if_stat_pending}...{/if_stat_pending} — rendered only when pending count > 0
 *   {if_approved_by}...{/if_approved_by}  — rendered only when approved_by is set
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
// 4. Gather stats
// ---------------------------------------------------------------------------

$_lusterMemberRepo = new MemberRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterPrefix     = $_lusterListing->dbprefix;

$_lusterApproved    = $_lusterMemberRepo->countApproved($_lusterPrefix);
$_lusterPending     = $_lusterMemberRepo->countPending($_lusterPrefix);
$_lusterCountries   = $_lusterMemberRepo->countUniqueCountries($_lusterPrefix);
$_lusterNewest      = $_lusterMemberRepo->getNewestMembers($_lusterPrefix, 5);
$_lusterLastUpdated = $_lusterMemberRepo->getMostRecentApprovedDate($_lusterPrefix);

// ---------------------------------------------------------------------------
// 5. Load template from DB (falls back to default)
// ---------------------------------------------------------------------------

$_lusterSettingsRepo = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterTemplates    = $_lusterSettingsRepo->getTemplates($_lusterListingId);

// Last Updated — from lstr_fanlisting_settings.last_updated (admin-controlled)
$_lusterAdminLastUpdated = $_lusterSettingsRepo->getLastUpdated($_lusterListingId);
$_lusterLastUpdatedFallback = (string) $_lusterApp->getConfig()->get('last_updated_fallback', 'Never');

$_lusterGlobalTpls   = (new SiteSettingsRepo($_lusterApp->getDatabase()))->getGlobalTemplates();
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

$_lusterFormatDate = static function (string $dateStr): string {
    $ts = strtotime($dateStr);
    if ($ts === false) {
        return $dateStr;
    }
    $day    = (int) date('j', $ts);
    $suffix = match (true) {
        $day >= 11 && $day <= 13 => 'th',
        $day % 10 === 1          => 'st',
        $day % 10 === 2          => 'nd',
        $day % 10 === 3          => 'rd',
        default                  => 'th',
    };
    return date('F', $ts) . ' ' . $day . $suffix . ' ' . date('Y', $ts);
};

$_lusterOpened  = $_lusterListing->joined !== null
    ? $_lusterFormatDate($_lusterListing->joined)
    : 'Unknown';
$_lusterUpdated = $_lusterLastUpdated !== null
    ? $_lusterFormatDate($_lusterLastUpdated)
    : 'Never';

// ---------------------------------------------------------------------------
// 7. Build token values
// ---------------------------------------------------------------------------

$_lusterNewestEscaped = array_map(
    static fn(string $name) => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    $_lusterNewest,
);
$_lusterNewMembersStr = implode(', ', $_lusterNewestEscaped);

$_lusterNewMembersOxford = (static function (array $names): string {
    return match (count($names)) {
        0       => '',
        1       => $names[0],
        2       => $names[0] . ' and ' . $names[1],
        default => implode(', ', array_slice($names, 0, -1)) . ', and ' . end($names),
    };
})($_lusterNewestEscaped);
$_lusterNewMembersLabel = count($_lusterNewest) === 1 ? 'Member' : 'Members';

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

$_lusterAdminUpdatedStr = $_lusterAdminLastUpdated !== null
    ? $_lusterFormatDate($_lusterAdminLastUpdated)
    : $_lusterLastUpdatedFallback;

$_lusterApprovedBy     = $_lusterSettingsRepo->getApprovedBy($_lusterListingId);
$_lusterApprovedByHtml = match ($_lusterApprovedBy) {
    'tfl'   => '<a href="https://thefanlistings.org">The Fanlistings Network</a>',
    'tafl'  => '<a href="https://animefanlistings.org">The Anime Fanlistings Network</a>',
    default => '',
};

// Conditional blocks — must run before flat str_replace
$_lusterTemplate = (string) preg_replace(
    '/\{if_approved_by\}(.*?)\{\/if_approved_by\}/s',
    $_lusterApprovedBy !== '' ? '$1' : '',
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
        '{stat_last_updated}',
        '{stat_approved}',
        '{stat_pending}',
        '{stat_countries}',
        '{stat_newmembers}',
        '{stat_newmembers_oxford}',
        '{stat_newmembers_label}',
        '{stat_growth}',
        '{listing_title}',
        '{listing_url}',
        '{approved_by}',
    ],
    [
        $_lusterOpened,
        $_lusterUpdated,
        $_lusterAdminUpdatedStr,
        (string) $_lusterApproved,
        (string) $_lusterPending,
        (string) $_lusterCountries,
        $_lusterNewMembersStr,
        $_lusterNewMembersOxford,
        $_lusterNewMembersLabel,
        $_lusterGrowth . ' members/day',
        htmlspecialchars($_lusterListing->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($_lusterListing->url,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        $_lusterApprovedByHtml,
    ],
    $_lusterTemplate,
);

$_lusterHtml .= \Luster\Core\View::attribution();

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
