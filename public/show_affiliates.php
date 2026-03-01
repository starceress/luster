<?php

declare(strict_types=1);

use Luster\Repositories\AffiliateRepo;

/**
 * show_affiliates.php — Embeddable Luster affiliates widget.
 *
 * Outputs an affiliates fragment for a fanlisting. No HTML wrapper —
 * drop this include anywhere inside your page's body.
 *
 * Usage
 * -----
 *   <?php
 *   $luster_listing = 3;
 *   include '/path/to/luster/public/show_affiliates.php';
 *
 * The output applies affiliates_template (stored in lstr_fanlisting_settings)
 * once per active affiliate, concatenates the rows, and echoes the result.
 * Falls back to a built-in default if no template has been saved.
 *
 * Outputs nothing (silently) for upcoming or trouble fanlistings,
 * or when the fanlisting has no active affiliates.
 *
 * Tokens available in affiliates_template ($$token$$ style):
 *   $$aff_url$$   — affiliate URL (HTML-escaped)
 *   $$aff_image$$ — affiliate image URL (HTML-escaped; empty when not set)
 *   $$aff_name$$  — affiliate name (HTML-escaped)
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────

require_once __DIR__ . '/luster_bootstrap.php';

// ── Guard: suppress output for inactive fanlistings ──────────────────────────

if ($lstr_fl->upcoming || $lstr_fl->trouble) {
    return;
}

// ── Load affiliates ───────────────────────────────────────────────────────────

$_lstr_affiliateRepo = new AffiliateRepo($lstr_db);
$_lstr_affiliates    = $_lstr_affiliateRepo->getByFanlisting($lstr_fl->id);

if (empty($_lstr_affiliates)) {
    return;
}

// ── Load per-row template ─────────────────────────────────────────────────────

$_lstr_tpls = $lstr_settingsRepo->getTemplates($luster_listing);

$_lstr_rowTpl = ($_lstr_tpls['affiliates_template'] !== null
    && trim($_lstr_tpls['affiliates_template']) !== '')
    ? $_lstr_tpls['affiliates_template']
    : '<a href="$$aff_url$$"><img src="$$aff_image$$" alt="$$aff_name$$" /></a>';

// ── Apply template per affiliate and echo ─────────────────────────────────────

$_lstr_e = static fn(string $v): string =>
    htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$_lstr_out = '';

foreach ($_lstr_affiliates as $_lstr_aff) {
    if (!$_lstr_aff->active) {
        continue;
    }

    $_lstr_out .= str_replace(
        ['$$aff_url$$', '$$aff_image$$', '$$aff_name$$'],
        [
            $_lstr_e($_lstr_aff->url),
            $_lstr_e($_lstr_aff->image ?? ''),
            $_lstr_e($_lstr_aff->name),
        ],
        $_lstr_rowTpl,
    );
}

echo $_lstr_out;
