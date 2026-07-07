<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable collective-level membership update form. Lets a visitor enter
 their email address to receive a verification link, then update their
 name, URL, and country across all fanlistings they're a member of in one
 go. Does not require a $luster_fanlisting_id — operates at the
 collective/site-wide level.

 Usage:
   $luster_return = true;   // optional — return HTML instead of echoing
   echo require '/path/to/luster/public/embed/collective-update.php';
*/

declare(strict_types=1);

use Luster\Controllers\Public\CollectiveUpdateController;
use Luster\Core\View;
use Luster\Repositories\CollectiveUpdateTokenRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;

// ---------------------------------------------------------------------------
// 1. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2);

if (!is_file($_lusterRoot . '/vendor/autoload.php') || !is_file($_lusterRoot . '/config/app.php')) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: Luster is not installed correctly.</p>';
    if ($luster_return ?? false) { return $_lusterHtml; }
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
// Strip the token param so the return URL is the clean host page
$_lusterRedirectTo = preg_replace('/([?&])_luster_cu_token=[^&]*(&|$)/', '$1', $_lusterRedirectTo);
$_lusterRedirectTo = rtrim($_lusterRedirectTo, '?&');
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);

// ---------------------------------------------------------------------------
// 2. POST handler
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['_luster_embed_form'] ?? '') !== 'collective_update') {
        if ($luster_return ?? false) { return ''; }
        return;
    }

    if (!$_lusterApp->getCSRF()->validate((string) ($_POST['_csrf'] ?? ''))) {
        $_lusterApp->getSession()->set('lstr_flash_error', 'Security check failed. Please reload the page and try again.');
        if (!headers_sent()) { header('Location: ' . $_lusterRedirectTo, true, 302); exit; }
        echo '<script>window.location.replace(' . json_encode($_lusterRedirectTo) . ');</script>';
        return;
    }

    $_lusterController = new CollectiveUpdateController($_lusterApp);

    // Step B: token + form data → apply updates
    if ((string) ($_POST['_cu_token'] ?? '') !== '') {
        $response = $_lusterController->submit(new \Luster\Core\Request());
    } else {
        // Step A: email → send verification link
        $response = $_lusterController->request(new \Luster\Core\Request());
    }

    $_lusterUrl = $response->getRedirectUrl() ?? $_lusterRedirectTo;
    if (!headers_sent()) { header('Location: ' . $_lusterUrl, true, 302); exit; }
    echo '<script>window.location.replace(' . json_encode($_lusterUrl) . ');</script>';
    return;
}

// ---------------------------------------------------------------------------
// 3. Consume flash data
// ---------------------------------------------------------------------------

$_lusterSession = $_lusterApp->getSession();
$_lusterSuccess = (string) $_lusterSession->get('lstr_flash_success_html', '');
$_lusterError   = (string) $_lusterSession->get('lstr_flash_error', '');
$_lusterOldEmail = (string) $_lusterSession->get('lstr_flash_old_email', '');

$_lusterSession->remove('lstr_flash_success_html');
$_lusterSession->remove('lstr_flash_error');
$_lusterSession->remove('lstr_flash_old_email');

$_lusterCsrfToken = $_lusterApp->getCSRF()->token();

// ---------------------------------------------------------------------------
// 4. Determine which step to render
// ---------------------------------------------------------------------------

// Step B: verification token in URL — validate and load membership data
$_lusterRawToken   = (string) ($_GET['_luster_cu_token'] ?? '');
$_lusterTokenRow   = null;
$_lusterMemberships = [];
$_lusterTokenEmail = '';

if ($_lusterRawToken !== '') {
    $_lusterTokenRepo = new CollectiveUpdateTokenRepo($_lusterApp->getDatabase());
    $_lusterTokenRow  = $_lusterTokenRepo->findValid($_lusterRawToken);

    if ($_lusterTokenRow !== null) {
        $_lusterTokenEmail  = (string) $_lusterTokenRow['email'];
        $_lusterMemberships = CollectiveUpdateController::findMembershipsForEmail($_lusterTokenEmail, $_lusterApp);

        if ($_lusterMemberships === []) {
            $_lusterError   = 'No active memberships were found for your email address.';
            $_lusterTokenRow = null;
        }
    } else {
        $_lusterError   = 'That update link is invalid or has expired. Please request a new one below.';
    }
}

// ---------------------------------------------------------------------------
// 5. Build the Step-1 form HTML from the admin-editable template
// ---------------------------------------------------------------------------

$_lusterDb          = $_lusterApp->getDatabase();
$_lusterUpdateTpl   = (new SiteSettingsRepo($_lusterDb))->get('collective_update_form_template')
    ?: SiteSettingsRepo::defaultCollectiveUpdateFormTemplate();

$_lusterFb          = $_lusterApp->getFormFieldBuilder();
$_lusterEmailFields = $_lusterFb->build([
    ['type' => 'email', 'name' => 'email', 'id' => 'cu-email', 'label' => 'Your email address',
     'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email',
     'value' => $_lusterOldEmail]],
], '');

$_lusterRequestTokens = [
    '{site_name}'         => htmlspecialchars((string) $_lusterApp->getConfig()->get('site_name', ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    '{field_email}'       => $_lusterEmailFields['email'],
    '{field_email_input}' => $_lusterEmailFields['email_input'],
    '{submit_button}'     => '<button type="submit" class="fl-submit">Send Verification Link</button>',
    '{clear_button}'      => '<button type="reset" class="fl-btn-secondary">Clear</button>',
];

$_lusterRequestInner   = FormTemplateRenderer::expandTokens($_lusterUpdateTpl, $_lusterRequestTokens);
$_lusterRequestFormHtml = FormTemplateRenderer::wrapForm(
    $_lusterRequestInner,
    $_lusterRedirectTo,
    $_lusterCsrfToken,
    false,
    false,
    'collective_update'
);

// ---------------------------------------------------------------------------
// 6. Render
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('collective-update.php', [
    'base_url'          => $_lusterApp->getBaseUrl(),
    'form_action'       => $_lusterRedirectTo,
    'csrf_token'        => $_lusterCsrfToken,
    'success_html'      => $_lusterSuccess,
    'error'             => $_lusterError !== '' ? htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '',
    // Pre-rendered Step 1 (email-request form)
    'request_form_html' => $_lusterRequestFormHtml,
    // Token step (Step 2)
    'token_valid'       => $_lusterTokenRow !== null,
    'raw_token'         => $_lusterRawToken,
    'token_email'       => htmlspecialchars($_lusterTokenEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'memberships'       => $_lusterMemberships,
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}
echo $_lusterHtml;
