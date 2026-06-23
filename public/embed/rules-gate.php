<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable rules gate fragment. Place this BEFORE the join embed on your
 page to enforce a "read and agree to the rules" step. Returns the rules
 gate HTML (checkbox or button) if the fan has not yet agreed to the current
 rule version, or an empty string if no gate is needed (rules disabled, no
 rules set, or already agreed this session). Requires the Rules add-on to be
 installed.
*/

declare(strict_types=1);

use Luster\Addons\Rules\RuleSet;
use Luster\Addons\Rules\RulesRepo;

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
$luster_attribution = \Luster\Core\View::attribution();
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
$_lusterMarker = 'rules_gate:' . $_lusterListingId;

// ---------------------------------------------------------------------------
// POST handler — process form submissions inline (self-post)
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['_luster_embed_form'] ?? '') !== $_lusterMarker) {
        if ($luster_return ?? false) { return ''; }
        return;
    }
    if (!$_lusterApp->getCSRF()->validate((string) ($_POST['_csrf'] ?? ''))) {
        $_lusterApp->getSession()->set('lstr_flash_error', 'Security check failed. Please reload the page and try again.');
        if (!headers_sent()) { header('Location: ' . $_lusterRedirectTo, true, 302); exit; }
        echo '<script>window.location.replace(' . json_encode($_lusterRedirectTo) . ');</script>';
        return;
    }
    $response   = (new \Luster\Addons\Rules\Controllers\RulesPublicController($_lusterApp))
        ->agree(new \Luster\Core\Request(), ['id' => $_lusterListingId]);
    $_lusterUrl = $response->getRedirectUrl() ?? $_lusterRedirectTo;
    if (!headers_sent()) { header('Location: ' . $_lusterUrl, true, 302); exit; }
    echo '<script>window.location.replace(' . json_encode($_lusterUrl) . ');</script>';
    return;
}

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
    // No active per-listing rules — try global fallback
    try {
        $_lusterSiteRepo    = new \Luster\Repositories\SiteSettingsRepo($_lusterApp->getDatabase());
        $_lusterGlobalBody    = trim((string) ($_lusterSiteRepo->get('rules_global_body') ?? ''));
        $_lusterGlobalEnabled = ($_lusterSiteRepo->get('rules_global_gate_enabled') ?? '0') === '1';
        if ($_lusterGlobalBody !== '' && $_lusterGlobalEnabled) {
            $_lusterGlobalVer = (int) ($_lusterSiteRepo->get('rules_global_version') ?? 1);
            $_lusterRuleSet   = new RuleSet(
                id:           0,
                fanlistingId: $_lusterListingId,
                body:         $_lusterGlobalBody,
                enabled:      true,
                version:      $_lusterGlobalVer,
                updatedAt:    '',
                gateType:     $_lusterSiteRepo->get('rules_global_gate_type')  ?? 'checkbox',
                gateLabel:    $_lusterSiteRepo->get('rules_global_gate_label') ?? '',
            );
        }
    } catch (\Throwable) {
        // Site settings unavailable — proceed without global rules
    }
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
$_lusterFormAction = $_lusterRedirectTo;

try {
    $_lusterListing = (new \Luster\Repositories\ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig()))
        ->getById($_lusterListingId);
} catch (\Throwable) {
    $_lusterListing = null;
}

// Build gate tokens based on gate type
$_lusterEscGateLabel = htmlspecialchars(
    $_lusterRuleSet->effectiveGateLabel(),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
if ($_lusterRuleSet->gateType === 'button') {
    $_lusterGateCheckboxHtml = '';
    $_lusterGateButtonHtml   = '<button type="submit" class="fl-submit fl-rules-gate-btn" name="agree" value="1">'
                             . $_lusterEscGateLabel . '</button>';
} else {
    $_lusterGateCheckboxHtml = '<input type="checkbox" class="fl-rules-agree-check" name="agree" value="1" required>';
    $_lusterGateButtonHtml   = '<button type="submit" class="fl-submit fl-rules-gate-btn">'
                             . $_lusterEscGateLabel . '</button>';
}

$_lusterSettingsRepo = new \Luster\Repositories\ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterEmbedSettings = $_lusterSettingsRepo->getEmbedSettings($_lusterListingId);
$_lusterListingUrl = $_lusterListing?->url ?? '';

$_lusterResolveUrl = static function (string $settingKey, string $fallbackRoute) use ($_lusterEmbedSettings, $_lusterListingUrl, $_lusterBaseUrl, $_lusterListingId): string {
    $page = (string) ($_lusterEmbedSettings[$settingKey] ?? '');
    if ($page !== '' && preg_match('#^https?://#i', $page)) {
        return $page;
    }
    if ($page !== '') {
        return rtrim($_lusterListingUrl, '/') . '/' . ltrim($page, '/');
    }
    return $_lusterBaseUrl . '/' . $fallbackRoute . '/' . $_lusterListingId;
};

$_lusterRulesBodyHtml = \Luster\Support\RulesBodyRenderer::render(
    $_lusterRuleSet->body,
    [
        'subject'       => $_lusterListing?->subject ?? '',
        'listing_title' => $_lusterListing?->title ?? '',
        'listing_url'   => $_lusterListingUrl,
        'join_url'      => $_lusterResolveUrl('form_url_join', 'join'),
        'update_url'    => $_lusterResolveUrl('form_url_update', 'update'),
    ],
    [
        'gate_checkbox' => $_lusterGateCheckboxHtml,
        'gate_button'   => $_lusterGateButtonHtml,
    ]
);

if (!\Luster\Support\RulesBodyRenderer::hasGateToken($_lusterRuleSet->body)) {
    $_lusterRulesBodyHtml .= "\n" . $_lusterGateCheckboxHtml . "\n" . $_lusterGateButtonHtml;
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('addons/rules/gate.php', [
    'rules'           => $_lusterRuleSet,
    'already_agreed'  => false,
    'form_action'     => $_lusterFormAction,
    'return_to'       => $_lusterRedirectTo,
    'csrf_token'      => $_lusterApp->getCSRF()->token(),
    'rules_body_html' => $_lusterRulesBodyHtml,
    'form_marker'     => $_lusterMarker,
]);


if ($luster_return ?? false) {
    return $_lusterHtml;
}
echo $_lusterHtml;
