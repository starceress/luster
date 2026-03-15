<?php

declare(strict_types=1);

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Captcha;
use Luster\Repositories\ListingRepo;

/**
 * Luster embed — KIM List sign-up form fragment.
 *
 * Drop this into any PHP page to embed the KIM sign-up form without Luster's layout.
 * The surrounding HTML (header, footer, nav) comes from your own page.
 *
 * Usage:
 *   $luster_return = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/kim-signup.php';
 *
 * The form POSTs to Luster's /kim URL.
 */

// ---------------------------------------------------------------------------
// 1. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2); // public/embed/ → public/ → project root

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
$_lusterError   = (string) $_lusterSession->get('lstr_flash_error', '');
$_lusterOld     = (array)  $_lusterSession->get('lstr_flash_old',   []);
$_lusterSession->remove('lstr_flash_error');
$_lusterSession->remove('lstr_flash_old');

// ---------------------------------------------------------------------------
// 5. Render fragment (no layout wrapper)
// ---------------------------------------------------------------------------

$_lusterBaseUrl = $_lusterApp->getBaseUrl();

$_lusterHtml = $_lusterApp->getView()->render('addons/kim/signup.php', [
    'form_action'      => $_lusterBaseUrl . '/kim',
    'csrf_token'       => $_lusterApp->getCSRF()->token(),
    'errors'           => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'fanlistings'      => $_lusterFanlistings,
    'old'              => $_lusterOld,
    'captcha_question' => Captcha::getQuestion('kim_signup'),
    'captcha_error'    => '',
    'base_url'         => $_lusterBaseUrl,
    'theme_path'       => $_lusterBaseUrl . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
