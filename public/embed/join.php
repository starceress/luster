<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FanlistingSettingsRepo;
use Luster\Repositories\FieldRepo;

/**
 * Luster embed — join form fragment.
 *
 * Drop this into any PHP page to embed the join form without Luster's layout.
 * The surrounding HTML (header, footer, nav) comes from your own page.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: fanlisting ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/join.php';
 *
 * The form POSTs to Luster's join URL. On success the user is sent to the
 * post-join redirect URL configured in the fanlisting's embed settings, or to
 * Luster's own success page if none is set.
 *
 * On validation error the controller redirects back to Luster's /join/{id} URL
 * (not your embed page). To keep users on your site after errors, configure
 * embed_base_url in Settings and ensure your site handles those URLs too.
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

$_lusterFieldRepo     = new FieldRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterFields        = $_lusterFieldRepo->getFieldsForFanlisting($_lusterFanlistingId);
$_lusterSettingsRepo  = new FanlistingSettingsRepo($_lusterApp->getDatabase());
$_lusterEmbedSettings = $_lusterSettingsRepo->getEmbedSettings($_lusterFanlistingId);

// ---------------------------------------------------------------------------
// 4. Consume flash data (set by the controller after a failed POST)
// ---------------------------------------------------------------------------

$_lusterSession = $_lusterApp->getSession();
$_lusterError   = (string) $_lusterSession->get('lstr_flash_error', '');
$_lusterOld     = (array)  $_lusterSession->get('lstr_flash_old',   []);
$_lusterSession->remove('lstr_flash_error');
$_lusterSession->remove('lstr_flash_old');

// ---------------------------------------------------------------------------
// 5. Render fragment (no layout wrapper)
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
$_lusterFormAction = $_lusterBaseUrl . '/join/' . $_lusterFanlistingId;

$_lusterHtml = $_lusterApp->getView()->renderFragment('join.php', [
    'fanlisting'            => $_lusterFanlisting,
    'fanlisting_name'       => $_lusterFanlisting->name,
    'fanlisting_url'        => $_lusterFanlisting->url,
    'page_title'            => 'Join',
    'form_action'           => $_lusterFormAction,
    'csrf_token'            => $_lusterApp->getCSRF()->token(),
    'errors'                => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'field_name'            => (string) ($_lusterOld['name']    ?? ''),
    'field_email'           => (string) ($_lusterOld['email']   ?? ''),
    'field_url'             => (string) ($_lusterOld['url']     ?? ''),
    'country_select'        => Countries::buildSelect((string) ($_lusterOld['country'] ?? '')),
    'country_field_enabled' => (bool) $_lusterEmbedSettings['country_field_enabled'],
    'fields'                => $_lusterFields,
    'old'                   => $_lusterOld,
    'captcha_question'      => Captcha::getQuestion(),
    'captcha_error'         => '',
    'send_info_checked'     => 'checked',
    'base_url'              => $_lusterApp->getBaseUrl(),
    'theme_path'            => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
