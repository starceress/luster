<?php

declare(strict_types=1);

/**
 * show_members.php — Embeddable Luster members-list widget.
 *
 * Outputs an approved member list fragment for a fanlisting. No HTML
 * wrapper — drop this include anywhere inside your page's body.
 *
 * Usage
 * -----
 *   <?php
 *   $luster_listing = 3;
 *   include '/path/to/luster/public/show_members.php';
 *
 * The output applies members_template (stored in lstr_fanlisting_settings)
 * once per approved member, concatenates the rows, and echoes the result.
 * Falls back to a built-in default if no template has been saved.
 *
 * Outputs nothing (silently) for upcoming or trouble fanlistings.
 *
 * Tokens available in members_template ($$token$$ style):
 *   $$fan_name$$    — member name (HTML-escaped)
 *   $$fan_email$$   — member email (HTML-escaped)
 *   $$fan_url$$     — member URL (empty when showurl is false; HTML-escaped)
 *   $$fan_country$$ — member country (HTML-escaped)
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────

require_once __DIR__ . '/luster_bootstrap.php';

// ── Guard: suppress output for inactive fanlistings ──────────────────────────

if ($lstr_fl->upcoming || $lstr_fl->trouble) {
    return;
}

// ── Load per-row template ─────────────────────────────────────────────────────

$_lstr_tpls = $lstr_settingsRepo->getTemplates($luster_listing);

$_lstr_rowTpl = ($_lstr_tpls['members_template'] !== null
    && trim($_lstr_tpls['members_template']) !== '')
    ? $_lstr_tpls['members_template']
    : '<p><a href="$$fan_url$$">$$fan_name$$</a> ($$fan_country$$)</p>';

// ── Load approved members ─────────────────────────────────────────────────────

$_lstr_members = $lstr_memberRepo->getApproved($lstr_fl->dbprefix);

// ── Apply template per member and echo ───────────────────────────────────────

$_lstr_e = static fn(string $v): string =>
    htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$_lstr_out = '';

foreach ($_lstr_members as $_lstr_member) {
    $_lstr_out .= str_replace(
        ['$$fan_name$$', '$$fan_email$$', '$$fan_url$$', '$$fan_country$$'],
        [
            $_lstr_e($_lstr_member->name),
            $_lstr_e($_lstr_member->email),
            $_lstr_e($_lstr_member->showurl ? $_lstr_member->url : ''),
            $_lstr_e($_lstr_member->country),
        ],
        $_lstr_rowTpl,
    );
}

echo $_lstr_out;
