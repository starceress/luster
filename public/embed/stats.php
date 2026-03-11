<?php

declare(strict_types=1);

use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FanlistingSettingsRepo;
use Luster\Repositories\MemberRepo;

/**
 * Luster embed — stats fragment.
 *
 * Outputs the fanlisting stats snippet using the template stored in
 * lstr_fanlisting_settings.stats_template, with $token$ placeholders
 * replaced with live data. Identical output to GET /stats/{fanlistingId}
 * but available as a server-side PHP include.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: fanlisting ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/stats.php';
 *
 * Available $token$ placeholders (same as the /stats/{id} endpoint):
 *   $stat_opened$      — date fanlisting opened ("March 1st 2026")
 *   $stat_updated$     — date of most recent approved member (same format)
 *   $stat_approved$    — approved member count
 *   $stat_pending$     — pending member count
 *   $stat_newmembers$  — comma-separated newest 5 approved member names
 *   $stat_growth$      — members per day since opened (2 decimal places)
 *   $fanlisting_name$  — fanlisting name (HTML-escaped)
 *   $fanlisting_url$   — fanlisting URL (HTML-escaped)
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

// ---------------------------------------------------------------------------
// 3. Load fanlisting
// ---------------------------------------------------------------------------

$_lusterFanlistingRepo = new FanlistingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterFanlisting     = $_lusterFanlistingRepo->getById($_lusterFanlistingId);

if ($_lusterFanlisting === null || $_lusterFanlisting->upcoming || $_lusterFanlisting->trouble) {
    $_lusterHtml = '<!-- Luster: stats not available for fanlisting #' . $_lusterFanlistingId . ' -->';
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
$_lusterPrefix     = $_lusterFanlisting->dbprefix;

$_lusterApproved    = $_lusterMemberRepo->countApproved($_lusterPrefix);
$_lusterPending     = $_lusterMemberRepo->countPending($_lusterPrefix);
$_lusterNewest      = $_lusterMemberRepo->getNewestMembers($_lusterPrefix, 5);
$_lusterLastUpdated = $_lusterMemberRepo->getMostRecentApprovedDate($_lusterPrefix);

// ---------------------------------------------------------------------------
// 5. Load template from DB (falls back to default)
// ---------------------------------------------------------------------------

$_lusterSettingsRepo = new FanlistingSettingsRepo($_lusterApp->getDatabase());
$_lusterTemplates    = $_lusterSettingsRepo->getTemplates($_lusterFanlistingId);
$_lusterTemplate     = ($_lusterTemplates['stats_template'] !== null
                     && trim($_lusterTemplates['stats_template']) !== '')
    ? $_lusterTemplates['stats_template']
    : '<p>Opened: $stat_opened$<br>Last updated: $stat_updated$<br>'
    . 'Members: $stat_approved$ ($stat_pending$ pending)<br>'
    . 'Newest: $stat_newmembers$</p>';

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

$_lusterOpened  = $_lusterFanlisting->joined !== null
    ? $_lusterFormatDate($_lusterFanlisting->joined)
    : 'Unknown';
$_lusterUpdated = $_lusterLastUpdated !== null
    ? $_lusterFormatDate($_lusterLastUpdated)
    : 'Never';

// ---------------------------------------------------------------------------
// 7. Build token values
// ---------------------------------------------------------------------------

$_lusterNewMembersStr = implode(', ', array_map(
    static fn(string $name) => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    $_lusterNewest,
));

$_lusterGrowth = '0.00';
if ($_lusterFanlisting->joined !== null) {
    $_lusterOpenedTs = strtotime($_lusterFanlisting->joined);
    if ($_lusterOpenedTs !== false) {
        $_lusterDays   = max(1, (int) round((time() - $_lusterOpenedTs) / 86400));
        $_lusterGrowth = number_format($_lusterApproved / $_lusterDays, 2);
    }
}

// ---------------------------------------------------------------------------
// 8. Replace tokens and output
// ---------------------------------------------------------------------------

$_lusterHtml = str_replace(
    [
        '$stat_opened$',
        '$stat_updated$',
        '$stat_approved$',
        '$stat_pending$',
        '$stat_newmembers$',
        '$stat_growth$',
        '$fanlisting_name$',
        '$fanlisting_url$',
    ],
    [
        $_lusterOpened,
        $_lusterUpdated,
        (string) $_lusterApproved,
        (string) $_lusterPending,
        $_lusterNewMembersStr,
        $_lusterGrowth,
        htmlspecialchars($_lusterFanlisting->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($_lusterFanlisting->url,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ],
    $_lusterTemplate,
);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
