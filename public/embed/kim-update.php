<?php

declare(strict_types=1);

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Request;
use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;

/**
 * Luster embed — KIM List update / management fragment.
 *
 * Usage:
 *   $luster_return = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/kim-update.php';
 *
 * Two-step flow:
 *   Step 1 — sign-in form (email + password)
 *   Step 2 — dashboard: update or remove KIM entries across all fanlistings
 *
 * Sign-out: the sign-out link appends ?kim_signout=1 to the host page URL.
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
$luster_attribution = \Luster\Core\View::attribution();
$_lusterApp->getSession()->start();
$_lusterRedirectTo = (string) ($luster_redirect_url ?? '');
if ($_lusterRedirectTo === '') {
    $_lusterProto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_lusterRedirectTo = $_lusterProto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}
// Strip ?kim_signout from the canonical redirect URL so it doesn't persist
$_lusterRedirectTo = preg_replace('/([?&])kim_signout=1(&|$)/', '$1', $_lusterRedirectTo);
$_lusterRedirectTo = rtrim(rtrim($_lusterRedirectTo, '?'), '&');
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);

// ---------------------------------------------------------------------------
// Sign-out — handled as a GET param to avoid needing a separate URL
// ---------------------------------------------------------------------------

if (isset($_GET['kim_signout'])) {
    $_lusterApp->getSession()->remove('lstr_kim_update_authed');
    if (!headers_sent()) {
        header('Location: ' . $_lusterRedirectTo, true, 302);
        exit;
    }
    echo '<script>window.location.replace(' . json_encode($_lusterRedirectTo) . ');</script>';
    return;
}

// ---------------------------------------------------------------------------
// POST handler — route to controller based on embed form marker
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_lusterMarker = (string) ($_POST['_luster_embed_form'] ?? '');

    if (!in_array($_lusterMarker, ['kim_update_auth', 'kim_update_save'], true)) {
        if ($luster_return ?? false) { return ''; }
        return;
    }

    if (!$_lusterApp->getCSRF()->validate((string) ($_POST['_csrf'] ?? ''))) {
        $_lusterApp->getSession()->set('lstr_flash_error', 'Security check failed. Please reload the page and try again.');
        if (!headers_sent()) { header('Location: ' . $_lusterRedirectTo, true, 302); exit; }
        echo '<script>window.location.replace(' . json_encode($_lusterRedirectTo) . ');</script>';
        return;
    }

    $_lusterController = new \Luster\Addons\Kim\Controllers\KimUpdateController($_lusterApp);
    $_lusterRequest    = new Request();

    $_lusterResponse = match ($_lusterMarker) {
        'kim_update_auth' => $_lusterController->submitSignIn($_lusterRequest, []),
        'kim_update_save' => $_lusterController->submitSave($_lusterRequest,   []),
    };

    $_lusterUrl = $_lusterResponse->getRedirectUrl() ?? $_lusterRedirectTo;
    if (!headers_sent()) { header('Location: ' . $_lusterUrl, true, 302); exit; }
    echo '<script>window.location.replace(' . json_encode($_lusterUrl) . ');</script>';
    return;
}

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
// 3. Consume flash data
// ---------------------------------------------------------------------------

$_lusterSession = $_lusterApp->getSession();
$_lusterSuccess = (string) $_lusterSession->get('lstr_flash_success_html', '');
$_lusterError   = (string) $_lusterSession->get('lstr_flash_error',        '');
$_lusterSession->remove('lstr_flash_success_html');
$_lusterSession->remove('lstr_flash_error');
$_lusterSession->remove('lstr_flash_old');

// ---------------------------------------------------------------------------
// 4. Load common settings
// ---------------------------------------------------------------------------

$_lusterSiteSettings = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterKimUrls = $_lusterSiteSettings->getMany([
    'kim_url_signup', 'kim_url_update', 'kim_url_reset', 'kim_url_list',
]);

$_lusterKimParsed   = parse_url((string) ($_lusterApp->getConfig()->get('site_url', '')));
$_lusterKimSiteRoot = ($_lusterKimParsed['scheme'] ?? 'https') . '://' . ($_lusterKimParsed['host'] ?? '');
if (!empty($_lusterKimParsed['port'])) {
    $_lusterKimSiteRoot .= ':' . $_lusterKimParsed['port'];
}
$_toKimLink = static fn(?string $v): ?string =>
    ($v !== null && $v !== '') ? $_lusterKimSiteRoot . '/' . ltrim($v, '/') : null;

$_lusterLinkReset = $luster_kim_reset_url ?? $_toKimLink($_lusterKimUrls['kim_url_reset'] ?? null) ?? $_lusterKimSiteRoot . '/kim-reset.php';
$_lusterLinkList  = $luster_kim_list_url  ?? $_toKimLink($_lusterKimUrls['kim_url_list']  ?: null);

$_lusterBaseUrl = $_lusterApp->getBaseUrl();
$_lusterAuthedEmail = (string) $_lusterSession->get('lstr_kim_update_authed', '');

// ---------------------------------------------------------------------------
// 5a. Dashboard — authenticated user
// ---------------------------------------------------------------------------

if ($_lusterAuthedEmail !== '') {
    $_lusterKimRepo     = new KimMemberRepo($_lusterApp->getDatabase());
    $_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
    $_lusterMembers     = $_lusterKimRepo->findAllByEmail($_lusterAuthedEmail);

    // If no entries remain (all removed, or email changed), auto-sign-out
    if ($_lusterMembers === []) {
        $_lusterSession->remove('lstr_kim_update_authed');
        $_lusterAuthedEmail = '';
    } else {
        $_lusterEntries = [];
        foreach ($_lusterMembers as $_lusterMember) {
            $_lusterListing = $_lusterListingRepo->getById($_lusterMember->fanlistingId);
            $_lusterEntries[] = [
                'id'           => $_lusterMember->id,
                'fanlisting_id'=> $_lusterMember->fanlistingId,
                'listing_title' => $_lusterListing !== null
                    ? ($_lusterListing->subject ?: $_lusterListing->title)
                    : 'Listing #' . $_lusterMember->fanlistingId,
                'name'         => $_lusterMember->name,
                'url'          => $_lusterMember->url ?? '',
                'email'        => $_lusterMember->email,
                'pending'      => $_lusterMember->pending,
            ];
        }

        $_lusterF = $_lusterApp->getFormFieldBuilder()->build([
            ['type' => 'text',     'name' => 'new_name',             'id' => 'kim-new-name',             'label' => 'New Name',             'required' => false, 'attrs' => ['maxlength' => '100'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'email',    'name' => 'new_email',            'id' => 'kim-new-email',            'label' => 'New Email',            'required' => false, 'attrs' => ['maxlength' => '255'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'url',      'name' => 'new_url',              'id' => 'kim-new-url',              'label' => 'New Website URL',      'required' => false, 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'password', 'name' => 'new_password',         'id' => 'kim-new-password',         'label' => 'New Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters. Leave blank to keep current.'],
            ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'kim-new-password-confirm', 'label' => 'Confirm New Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
        ], '');

        $_lusterSignOutSep  = str_contains($_lusterRedirectTo, '?') ? '&' : '?';
        $_lusterSignOutUrl  = $_lusterRedirectTo . $_lusterSignOutSep . 'kim_signout=1';

        $_lusterHtml = $_lusterApp->getView()->render('default/addons/kim/update-dashboard.php', [
            'auth_email'         => $_lusterAuthedEmail,
            'entries'            => $_lusterEntries,
            'save_action'        => $_lusterRedirectTo,
            'sign_out_url'       => $_lusterSignOutUrl,
            'csrf_token'         => $_lusterApp->getCSRF()->token(),
            'errors'             => $_lusterError !== ''
                ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'success_html'       => $_lusterSuccess,
            'f'                  => $_lusterF,
            'save_form_marker'   => 'kim_update_save',
            'link_reset'         => $_lusterLinkReset,
            'link_list'          => $_lusterLinkList,
            'base_url'           => $_lusterBaseUrl,
            'theme_path'         => $_lusterBaseUrl . '/assets/css/default/',
        ]);

        if ($luster_return ?? false) { return $_lusterHtml; }
        echo $_lusterHtml;
        return;
    }
}

// ---------------------------------------------------------------------------
// 5b. Sign-in form — not yet authenticated
// ---------------------------------------------------------------------------

$_lusterF = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'email',    'name' => 'email',    'id' => 'kim-email',    'label' => 'Email',    'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'password', 'name' => 'password', 'id' => 'kim-password', 'label' => 'Password', 'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
], '');

$_lusterFormHtml = null;
$_lusterUpdateTpl = $_lusterSiteSettings->get('kim_update_form_template') ?? '';
if ($_lusterUpdateTpl !== '') {
    $_lusterLostPwUrl = $_lusterLinkReset ?? $_lusterBaseUrl . '/kim/reset';
    $_lusterTplOut    = TemplateEngine::conditionals($_lusterUpdateTpl, ['lost_password_url' => true]);
    $_lusterTokens    = [
        '{site_name}'                        => View::e((string) $_lusterApp->getConfig()->get('site_name', 'Collective')),
        '{field_email}'                      => $_lusterF['email'],
        '{field_email_input}'                => $_lusterF['email_input'],
        '{field_password}'                   => $_lusterF['password'],
        '{field_password_input}'             => $_lusterF['password_input'],
        '{submit_button}'                    => '<button type="submit" class="fl-submit">Sign In</button>',
        '{clear_button}'                     => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        '{lost_password_url}'                => View::e($_lusterLostPwUrl),
    ];
    $_lusterInner    = FormTemplateRenderer::expandTokens($_lusterTplOut, $_lusterTokens);
    $_lusterFormHtml = FormTemplateRenderer::wrapForm($_lusterInner, $_lusterRedirectTo, $_lusterApp->getCSRF()->token(), false, false, 'kim_update_auth');
}

$_lusterHtml = $_lusterApp->getView()->render('default/addons/kim/update.php', [
    'form_action' => $_lusterRedirectTo,
    'csrf_token'  => $_lusterApp->getCSRF()->token(),
    'errors'      => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'success_html'=> $_lusterSuccess,
    'f'           => $_lusterF,
    'form_html'   => $_lusterFormHtml,
    'form_marker' => 'kim_update_auth',
    'link_reset'  => $_lusterLinkReset,
    'link_list'   => $_lusterLinkList,
    'base_url'    => $_lusterBaseUrl,
    'theme_path'  => $_lusterBaseUrl . '/assets/css/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
