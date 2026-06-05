<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Luster embed — affiliate application form fragment.
 *
 * Renders the affiliate application form for a specific listing.
 * Applications are reviewed in Admin → Affiliates → Pending.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/affiliate-apply.php';
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
$_lusterApp = $_lusterApp ?? require $_lusterRoot . '/bootstrap.php';
$_lusterApp->getSession()->start();

// ---------------------------------------------------------------------------
// 2. Load listing + settings
// ---------------------------------------------------------------------------

if (!isset($luster_fanlisting_id)) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: $luster_fanlisting_id must be set to a listing ID or 0 for collective.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterListingId = (int) $luster_fanlisting_id;

if ($_lusterListingId < 0) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: $luster_fanlisting_id must be a valid listing ID or 0 for collective.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 3. Collective mode (fanlisting_id = 0)
// ---------------------------------------------------------------------------

if ($_lusterListingId === 0) {
    $_lusterSiteRepo = new SiteSettingsRepo($_lusterApp->getDatabase());

    if ($_lusterSiteRepo->get('collective_affiliate_apply_enabled') !== '1') {
        $_lusterHtml = '<p class="fl-errors">Collective affiliate applications are not open.</p>';
        if ($luster_return ?? false) {
            return $_lusterHtml;
        }
        echo $_lusterHtml;
        return;
    }

    $_lusterConfig  = $_lusterApp->getConfig();
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

    $_lusterBaseUrl    = rtrim((string) ($_lusterConfig->get('embed_base_url', '') ?: $_lusterApp->getBaseUrl()), '/');
    $_lusterFormAction = $_lusterBaseUrl . '/affiliate-apply/collective';
    $_lusterSiteName   = (string) $_lusterConfig->get('site_name', 'Collective');
    $_lusterSiteUrl    = (string) ($_lusterConfig->get('site_url', '') ?: $_lusterApp->getBaseUrl());

    $_lusterHtml = $_lusterApp->getView()->renderFragment('affiliate-apply.php', [
        'listing'          => null,
        'listing_name'     => $_lusterSiteName,
        'listing_url'      => $_lusterSiteUrl,
        'page_title'       => 'Affiliate Application',
        'base_url'         => $_lusterApp->getBaseUrl(),
        'theme_path'       => $_lusterApp->getBaseUrl() . '/assets/default/',
        'form_action'      => $_lusterFormAction,
        'csrf_token'       => $_lusterApp->getCsrf()->getToken(),
        'captcha_question' => Captcha::getQuestion('affiliate_apply'),
        'captcha_error'    => '',
        'errors'           => $_lusterError,
        'old'              => $_lusterOld,
    ]);

    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 3b. Listing mode (fanlisting_id > 0)
// ---------------------------------------------------------------------------

$_lusterSettingsRepo  = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterApplySettings = $_lusterSettingsRepo->getAffiliateApplySettings($_lusterListingId);

if (!$_lusterApplySettings['enabled']) {
    $_lusterHtml = '<p class="fl-errors">Affiliate applications are not open for this listing.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterListing     = $_lusterListingRepo->getById($_lusterListingId);

if ($_lusterListing === null) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: listing #' . $_lusterListingId . ' not found.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 4. Consume flash data + build form state
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

$_lusterBaseUrl  = rtrim((string) ($_lusterApp->getConfig()->get('embed_base_url', '') ?: $_lusterApp->getBaseUrl()), '/');
$_lusterEmbedSettings = $_lusterSettingsRepo->getEmbedSettings($_lusterListingId);

$_lusterFormAction = $_lusterApplySettings['form_url'] !== ''
    ? rtrim((string) $_lusterListing->url, '/') . '/' . ltrim($_lusterApplySettings['form_url'], '/')
    : $_lusterBaseUrl . '/affiliate-apply/' . $_lusterListingId;

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('affiliate-apply.php', [
    'listing'          => $_lusterListing,
    'listing_name'     => (string) $_lusterListing->name,
    'listing_url'      => (string) $_lusterListing->url,
    'page_title'       => 'Affiliate Application — ' . $_lusterListing->name,
    'base_url'         => $_lusterApp->getBaseUrl(),
    'theme_path'       => rtrim((string) $_lusterListing->url, '/') . '/' . ltrim((string) ($_lusterEmbedSettings['theme_path'] ?? 'theme'), '/'),
    'form_action'      => $_lusterFormAction,
    'csrf_token'       => $_lusterApp->getCsrf()->getToken(),
    'captcha_question' => Captcha::getQuestion('affiliate_apply'),
    'captcha_error'    => '',
    'errors'           => $_lusterError,
    'old'              => $_lusterOld,
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
