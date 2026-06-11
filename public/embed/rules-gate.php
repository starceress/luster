<?php

declare(strict_types=1);

use Luster\Addons\Rules\RulesRepo;

/**
 * Luster embed — rules gate fragment.
 *
 * Returns the rules gate HTML if the fan has not yet agreed to the current
 * rule version, or an empty string if no action is needed (no rules, rules
 * disabled, or already agreed this session).
 *
 * Usage on your join page, placed BEFORE the join embed:
 *
 *   $luster_fanlisting_id = 1;
 *   $luster_return        = true;   // optional; default false (echo)
 *   // optional: $luster_redirect_url = 'https://yoursite.com/join';
 *
 *   $gate = require '/absolute/path/to/luster/public/embed/rules-gate.php';
 *   if ($gate !== '') {
 *       echo $gate;
 *       return; // Stop rendering — fan must agree before seeing the join form
 *   }
 *
 *   // Fan has agreed — show the join form
 *   $luster_return = false;
 *   require '/absolute/path/to/luster/public/embed/join.php';
 *
 * After agreement, the fan is redirected back to the host page (determined
 * from $luster_redirect_url or the current REQUEST_URI automatically).
 * The embed then re-renders as empty string and the join form is shown.
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
    $_lusterHtml = '<p class="fl-errors">Luster embed error: Luster is not installed correctly at <code>'
        . htmlspecialchars($_lusterRoot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</code>.</p>';
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
// 3. Determine and store the return URL
//    This is where the fan will be redirected after clicking "Agree".
// ---------------------------------------------------------------------------

$_lusterRedirectTo = (string) ($luster_redirect_url ?? '');
if ($_lusterRedirectTo === '') {
    $_lusterProto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_lusterRedirectTo = $_lusterProto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);

// ---------------------------------------------------------------------------
// 4. Load the rule set — silently returns '' when not needed.
//    Wrapped in try/catch so a missing table after uninstall never breaks the
//    host page; failing open is safe (fan proceeds directly to join form).
// ---------------------------------------------------------------------------

try {
    $_lusterRulesRepo = new RulesRepo($_lusterApp->getDatabase());
    $_lusterRuleSet   = $_lusterRulesRepo->getForListing($_lusterListingId);
} catch (\Throwable) {
    $_lusterRuleSet = null;
}

if ($_lusterRuleSet === null || !$_lusterRuleSet->enabled || trim($_lusterRuleSet->body) === '') {
    // No active rules for this listing — proceed to join form
    if ($luster_return ?? false) {
        return '';
    }
    return; // echo nothing
}

// ---------------------------------------------------------------------------
// 5. Check session agreement
// ---------------------------------------------------------------------------

$_lusterSession  = $_lusterApp->getSession();
$_lusterStoredFp = (string) $_lusterSession->get('lstr_rules_agreed_' . $_lusterListingId, '');

if ($_lusterStoredFp === $_lusterRuleSet->fingerprint()) {
    // Already agreed to the current version — let join form through
    if ($luster_return ?? false) {
        return '';
    }
    return;
}

// ---------------------------------------------------------------------------
// 6. Render the gate fragment
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
$_lusterFormAction = $_lusterBaseUrl . '/rules/' . $_lusterListingId . '/agree';

// Load the listing once — used by both the intro template and the rules body tokens.
try {
    $_lusterListing = (new \Luster\Repositories\ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig()))
        ->getById($_lusterListingId);
} catch (\Throwable) {
    $_lusterListing = null;
}

// Load rules intro template (gracefully absent before Migration 68)
$_lusterRulesIntroHtml = '';
try {
    $_lusterRawIntro = (new \Luster\Repositories\ListingSettingsRepo($_lusterApp->getDatabase()))
        ->getTemplates($_lusterListingId)['rules_intro_template'] ?? null;
    if ($_lusterRawIntro !== null && trim($_lusterRawIntro) !== '') {
        $_lusterRulesIntroHtml = \Luster\Core\TemplateEngine::render($_lusterRawIntro, escaped: [
            'listing_title'   => $_lusterListing?->title ?? '',
            'listing_url'     => $_lusterListing?->url ?? '',
            'listing_subject' => $_lusterListing?->subject ?? '',
        ]);
    }
} catch (\Throwable) {
    // intro template unavailable — proceed without it
}

$_lusterRulesBodyHtml = \Luster\Support\RulesBodyRenderer::render(
    $_lusterRuleSet->body,
    [
        'subject'       => $_lusterListing?->subject ?? '',
        'listing_title' => $_lusterListing?->title ?? '',
        'listing_url'   => $_lusterListing?->url ?? '',
        'join_url'      => $_lusterBaseUrl . '/join/' . $_lusterListingId,
        'update_url'    => $_lusterBaseUrl . '/update/' . $_lusterListingId,
    ],
    $_lusterRuleSet->listStyle
);

$_lusterHtml = $_lusterApp->getView()->renderFragment('addons/rules/gate.php', [
    'rules'            => $_lusterRuleSet,
    'already_agreed'   => false,
    'form_action'      => $_lusterFormAction,
    'return_to'        => $_lusterRedirectTo,
    'csrf_token'       => $_lusterApp->getCSRF()->token(),
    'rules_intro_html' => $_lusterRulesIntroHtml,
    'rules_body_html'  => $_lusterRulesBodyHtml,
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}
echo $_lusterHtml;
