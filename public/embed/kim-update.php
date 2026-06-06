<?php

declare(strict_types=1);

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Captcha;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Luster embed — KIM List update form fragment.
 *
 * Usage:
 *   $luster_return = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/kim-update.php';
 *
 * The form POSTs to Luster's /kim/update URL.
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
$_lusterRedirectTo = (string) ($luster_redirect_url ?? '');
if ($_lusterRedirectTo === '') {
    $_lusterProto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_lusterRedirectTo = $_lusterProto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);

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
// 3. Load fanlistings
// ---------------------------------------------------------------------------

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterFanlistings = $_lusterListingRepo->getAll();

// ---------------------------------------------------------------------------
// 4. Consume flash data
// ---------------------------------------------------------------------------

$_lusterSession = $_lusterApp->getSession();
$_lusterSuccess = (string) $_lusterSession->get('lstr_flash_success', '');
$_lusterError   = (string) $_lusterSession->get('lstr_flash_error',   '');
$_lusterOld     = (array)  $_lusterSession->get('lstr_flash_old',     []);
$_lusterSession->remove('lstr_flash_success');
$_lusterSession->remove('lstr_flash_error');
$_lusterSession->remove('lstr_flash_old');

if ($_lusterSuccess !== '') {
    $_lusterHtml = '<div class="fl-success"><p>'
        . htmlspecialchars($_lusterSuccess, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</p></div>';
    if ($luster_return ?? false) { return $_lusterHtml; }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// Load KIM page URLs from settings (allow host-page override)
// ---------------------------------------------------------------------------

$_lusterSiteSettings = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterKimUrls = $_lusterSiteSettings->getMany([
    "kim_url_signup", "kim_url_update", "kim_url_reset", "kim_url_list"
]);

// Stored values are page filenames; combine with the site origin to build full URLs.
// Host-page $luster_kim_*_url overrides are used as-is (they are already full URLs).
$_lusterKimParsed   = parse_url((string) $_lusterApp->getConfig()->get('app_url', ''));
$_lusterKimSiteRoot = ($_lusterKimParsed['scheme'] ?? 'https') . '://' . ($_lusterKimParsed['host'] ?? '');
if (!empty($_lusterKimParsed['port'])) {
    $_lusterKimSiteRoot .= ':' . $_lusterKimParsed['port'];
}
$_toKimLink = static fn(?string $v): ?string =>
    ($v !== null && $v !== '') ? $_lusterKimSiteRoot . '/' . ltrim($v, '/') : null;

$_lusterLinkSignup = $luster_kim_signup_url ?? $_toKimLink($_lusterKimUrls["kim_url_signup"] ?: null);
$_lusterLinkUpdate = $luster_kim_update_url ?? $_toKimLink($_lusterKimUrls["kim_url_update"] ?: null);
$_lusterLinkReset  = $luster_kim_reset_url  ?? $_toKimLink($_lusterKimUrls["kim_url_reset"]  ?: null);
$_lusterLinkList   = $luster_kim_list_url   ?? $_toKimLink($_lusterKimUrls["kim_url_list"]   ?: null);

// ---------------------------------------------------------------------------
// 5. Render fragment (no layout wrapper)
// ---------------------------------------------------------------------------

$_lusterBaseUrl = $_lusterApp->getBaseUrl();

$_lusterHtml = $_lusterApp->getView()->render('default/addons/kim/update.php', [
    'form_action'      => $_lusterBaseUrl . '/kim/update',
    'csrf_token'       => $_lusterApp->getCSRF()->token(),
    'errors'           => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'fanlistings'      => $_lusterFanlistings,
    'old'              => $_lusterOld,
    'captcha_question' => Captcha::getQuestion('kim_update'),
    'captcha_error'    => '',
    'base_url'         => $_lusterBaseUrl,
    'theme_path'       => $_lusterBaseUrl . '/assets/default/',
    'link_reset'       => $_lusterLinkReset,
    'link_list'        => $_lusterLinkList,
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
