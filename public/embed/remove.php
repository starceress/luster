<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Repositories\FanlistingRepo;

/**
 * Luster embed — remove membership form fragment.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: fanlisting ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/remove.php';
 *
 * The form POSTs to Luster's remove URL. On success the user is sent to the
 * post-remove redirect URL from fanlisting settings, or Luster's success page.
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
$_lusterApp->getSession()->start();

// ---------------------------------------------------------------------------
// 3. Load fanlisting data
// ---------------------------------------------------------------------------

$_lusterFanlistingRepo = new FanlistingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterFanlisting     = $_lusterFanlistingRepo->getById($_lusterFanlistingId);

if ($_lusterFanlisting === null) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: fanlisting #' . $_lusterFanlistingId . ' not found.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 4. Consume flash data
// ---------------------------------------------------------------------------

$_lusterSession  = $_lusterApp->getSession();
$_lusterError    = (string) $_lusterSession->get('lstr_flash_error',    '');
$_lusterOldEmail = (string) $_lusterSession->get('lstr_flash_old_email', '');
$_lusterSession->remove('lstr_flash_error');
$_lusterSession->remove('lstr_flash_old_email');

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
$_lusterFormAction = $_lusterBaseUrl . '/remove/' . $_lusterFanlistingId;

$_lusterHtml = $_lusterApp->getView()->renderFragment('remove.php', [
    'fanlisting'       => $_lusterFanlisting,
    'fanlisting_name'  => $_lusterFanlisting->name,
    'fanlisting_url'   => $_lusterFanlisting->url,
    'page_title'       => 'Remove Membership',
    'form_action'      => $_lusterFormAction,
    'csrf_token'       => $_lusterApp->getCSRF()->token(),
    'errors'           => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'field_email'      => $_lusterOldEmail,
    'captcha_question' => Captcha::getQuestion(),
    'captcha_error'    => '',
    'base_url'         => $_lusterApp->getBaseUrl(),
    'theme_path'       => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
