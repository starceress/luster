<?php

declare(strict_types=1);

/**
 * show_stats.php — Embeddable Luster stats widget.
 *
 * Outputs the stats template fragment for a listing. No HTML wrapper —
 * drop this include anywhere inside your page's body.
 *
 * Usage
 * -----
 *   <?php
 *   $luster_listing = 3;
 *   include '/path/to/luster/public/show_stats.php';
 *
 * The output is the rendered stats_template stored in lstr_fanlisting_settings,
 * with $$token$$ placeholders replaced by live data. Falls back to a sensible
 * default if no custom template has been saved.
 *
 * Outputs nothing (silently) for upcoming or trouble listings.
 *
 * Tokens replaced:
 *   $$stat_opened$$     — date listing opened, formatted "Month Dth Year"
 *   $$stat_updated$$    — date of most recently added approved member (same format)
 *   $$stat_approved$$   — approved member count
 *   $$stat_pending$$    — pending member count
 *   $$stat_newmembers$$ — comma-separated list of newest 5 approved member names
 *   $$stat_growth$$     — members per day since opened, to 2 decimal places
 *   $$listing_name$$ — listing name (HTML-escaped)
 *   $$listing_url$$  — listing URL (HTML-escaped)
 */

// ── Bootstrap (loads $lstr_fl, $lstr_memberRepo, $lstr_settingsRepo, etc.) ──

require_once __DIR__ . '/luster_bootstrap.php';

// ── Guard: suppress output for inactive listings ──────────────────────────

if ($lstr_fl->upcoming || $lstr_fl->trouble) {
    return;
}

// ── Load stats ───────────────────────────────────────────────────────────────

$prefix      = $lstr_fl->dbprefix;
$approved    = $lstr_memberRepo->countApproved($prefix);
$pending     = $lstr_memberRepo->countPending($prefix);
$newest      = $lstr_memberRepo->getNewestMembers($prefix, 5);
$lastUpdated = $lstr_memberRepo->getMostRecentApprovedDate($prefix);

// ── Load template ─────────────────────────────────────────────────────────────

$_lstr_templates = $lstr_settingsRepo->getTemplates($luster_listing);
$_lstr_template  = ($_lstr_templates['stats_template'] !== null && trim($_lstr_templates['stats_template']) !== '')
    ? $_lstr_templates['stats_template']
    : '<p>Opened: $$stat_opened$$<br>Last updated: $$stat_updated$$<br>'
      . 'Members: $$stat_approved$$ ($$stat_pending$$ pending)<br>'
      . 'Newest: $$stat_newmembers$$</p>';

// ── Build token values ────────────────────────────────────────────────────────

/**
 * Formats a MySQL date/datetime string as "Month Dth Year" (e.g. "March 1st 2026").
 * Returns the original string unchanged if it cannot be parsed.
 */
$_lstr_formatDate = static function (string $dateStr): string {
    $ts = strtotime($dateStr);

    if ($ts === false) {
        return $dateStr;
    }

    $day    = (int) date('j', $ts);
    $suffix = match (true) {
        $day >= 11 && $day <= 13 => 'th',   // 11th, 12th, 13th
        $day % 10 === 1          => 'st',
        $day % 10 === 2          => 'nd',
        $day % 10 === 3          => 'rd',
        default                  => 'th',
    };

    return date('F', $ts) . ' ' . $day . $suffix . ' ' . date('Y', $ts);
};

$_lstr_opened  = $lstr_fl->joined !== null ? $_lstr_formatDate($lstr_fl->joined)  : 'Unknown';
$_lstr_updated = $lastUpdated      !== null ? $_lstr_formatDate($lastUpdated)      : 'Never';

$_lstr_newMembersStr = implode(', ', array_map(
    static fn(string $name) => htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    $newest,
));

// Growth: members per day since opened; guard against zero days
$_lstr_growth = '0.00';
if ($lstr_fl->joined !== null) {
    $_lstr_openedTs = strtotime($lstr_fl->joined);
    if ($_lstr_openedTs !== false) {
        $_lstr_days   = max(1, (int) round((time() - $_lstr_openedTs) / 86400));
        $_lstr_growth = number_format($approved / $_lstr_days, 2);
    }
}

// ── Replace tokens ────────────────────────────────────────────────────────────

echo str_replace(
    [
        '$$stat_opened$$',
        '$$stat_updated$$',
        '$$stat_approved$$',
        '$$stat_pending$$',
        '$$stat_newmembers$$',
        '$$stat_growth$$',
        '$$listing_name$$',
        '$$listing_url$$',
    ],
    [
        $_lstr_opened,
        $_lstr_updated,
        (string) $approved,
        (string) $pending,
        $_lstr_newMembersStr,
        $_lstr_growth,
        htmlspecialchars($lstr_fl->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($lstr_fl->url,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    ],
    $_lstr_template,
);
