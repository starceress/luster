<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Core\Mailer;
use Luster\Core\RateLimit;
use Luster\Core\Session;
use Luster\Core\Validator;
use Luster\Repositories\FieldRepo;

/**
 * show_update.php — Embeddable Luster membership-update widget.
 *
 * Outputs a self-contained update form fragment. Handles both GET (show form)
 * and POST (authenticate then apply changes) from the same include. No HTML
 * wrapper — drop this include anywhere inside your page's body.
 *
 * Usage
 * -----
 *   <?php
 *   $luster_listing = 3;
 *   include '/path/to/luster/public/show_update.php';
 *
 * Security model (matches UpdateController):
 *   - Per-embed CSRF token in session (key: lstr_update_csrf)
 *   - Honeypot field (website_confirm) rejects bots silently
 *   - Arithmetic CAPTCHA via Luster\Core\Captcha
 *   - Rate-limited per IP: 10 updates/hour via lstr_rate_limits
 *   - Email + password authenticated before any change is applied
 *   - Legacy MD5 password detected, verified, and upgraded to Argon2id
 *   - "Leave blank to keep unchanged" semantics for all Section 2 fields
 *   - New email uniqueness checked before update
 *   - Column names from FieldRepo only — never from user input
 *
 * The form POSTs back to the current page URL. On success, a success fragment
 * is echoed in place of the form. All error paths re-render inline (no redirect).
 *
 * Outputs nothing (silently) for upcoming or trouble listings.
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────

require_once __DIR__ . '/luster_bootstrap.php';

// ── Guard ─────────────────────────────────────────────────────────────────────

if ($lstr_fl->upcoming || $lstr_fl->trouble) {
    return;
}

// ── Session ───────────────────────────────────────────────────────────────────

if (session_status() !== PHP_SESSION_ACTIVE) {
    $_lstr_session = new Session();
    $_lstr_session->start();
}

// ── Shared setup ──────────────────────────────────────────────────────────────

$_lstr_fieldRepo = new FieldRepo($lstr_db, $lstr_config);
$_lstr_fields    = $_lstr_fieldRepo->getFieldsForListing($luster_listing);

$_lstr_pageBase  = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
$_lstr_ip        = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$_lstr_e = static fn(string $v): string =>
    htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// "Lost it?" link points to Luster's own /remove/{id} page
$_lstr_removeUrl = rtrim((string) $lstr_config->get('site_url', ''), '/') . '/remove/' . $luster_listing;

// ── Extra fields HTML builder ─────────────────────────────────────────────────

$_lstr_buildExtraFields = static function (array $fields, array $old) use ($_lstr_e): string {
    $html = '';

    foreach ($fields as $field) {
        $inputName = 'field_' . $field->name;
        $labelText = $_lstr_e($field->label);
        $nameAttr  = $_lstr_e($inputName);
        $oldVal    = $_lstr_e((string) ($old[$inputName] ?? ''));
        $required  = $field->required ? ' required' : '';
        $reqMark   = $field->required ? ' <span aria-hidden="true">*</span>' : '';

        if ($field->type === 'textarea') {
            $html .= "<div class=\"form-group\">\n"
                   . "  <label for=\"{$nameAttr}\">{$labelText}{$reqMark}</label>\n"
                   . "  <textarea id=\"{$nameAttr}\" name=\"{$nameAttr}\" maxlength=\"255\"{$required}>{$oldVal}</textarea>\n"
                   . "</div>\n";
        } else {
            $html .= "<div class=\"form-group\">\n"
                   . "  <label for=\"{$nameAttr}\">{$labelText}{$reqMark}</label>\n"
                   . "  <input type=\"text\" id=\"{$nameAttr}\" name=\"{$nameAttr}\" value=\"{$oldVal}\" maxlength=\"255\"{$required}>\n"
                   . "</div>\n";
        }
    }

    return $html;
};

// ── Form renderer ─────────────────────────────────────────────────────────────

/**
 * @param string               $error        Auth/rate-limit error (Section 1)
 * @param string               $captchaError Captcha-specific error
 * @param string               $updateError  Post-auth error (Section 2: pw mismatch, dup email)
 * @param array<string,string> $old          POST values to repopulate
 */
$_lstr_renderUpdateForm = function (
    string $error = '',
    string $captchaError = '',
    string $updateError = '',
    array  $old = [],
) use ($lstr_fl, $_lstr_fields, $_lstr_pageBase, $_lstr_removeUrl, $_lstr_e, $_lstr_buildExtraFields): void {
    if (!isset($_SESSION['lstr_update_csrf'])) {
        $_SESSION['lstr_update_csrf'] = bin2hex(random_bytes(32));
    }
    $csrfToken = (string) $_SESSION['lstr_update_csrf'];

    $captchaQuestion = Captcha::getQuestion('update');
    $countrySelect   = Countries::buildSelect((string) ($old['country'] ?? ''));
    $extraFieldsHtml = $_lstr_buildExtraFields($_lstr_fields, $old);

    $tpl = '<div class="luster-update">' . "\n"
         . '<div class="form-error" role="alert">{error}</div>' . "\n"
         . '<p>Fields marked <span aria-hidden="true">*</span> are required.</p>' . "\n"
         . '<form method="post" action="{form_action}" novalidate>' . "\n"
         . '<input type="hidden" name="_lstr_csrf" value="{csrf_token}">' . "\n"
         . '<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">' . "\n"
         . '<input type="text" name="website_confirm" id="lstr_wc_u" tabindex="-1" autocomplete="off">' . "\n"
         . '</div>' . "\n"
         . '<fieldset class="form-section">' . "\n"
         . '<legend>To verify your identity:</legend>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_u_email">Current Email Address <span aria-hidden="true">*</span></label>' . "\n"
         . '<input type="email" id="lstr_u_email" name="email" value="{old_email}" maxlength="255" required autocomplete="email">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_u_password">Current Password <span aria-hidden="true">*</span>'
         . ' <small><a href="{lost_password_url}">Lost it?</a></small></label>' . "\n"
         . '<input type="password" id="lstr_u_password" name="password" maxlength="255" required autocomplete="current-password">' . "\n"
         . '</div>' . "\n"
         . '</fieldset>' . "\n"
         . '<fieldset class="form-section">' . "\n"
         . '<legend>Leave blank to keep unchanged:</legend>' . "\n"
         . '<div class="form-error" role="alert">{update_error}</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_u_new_name">Updated Name</label>' . "\n"
         . '<input type="text" id="lstr_u_new_name" name="new_name" value="{old_name}" maxlength="100" autocomplete="name">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_u_new_email">Updated Email Address</label>' . "\n"
         . '<input type="email" id="lstr_u_new_email" name="new_email" value="{old_new_email}" maxlength="255" autocomplete="email">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_u_new_password">New Password</label>' . "\n"
         . '<input type="password" id="lstr_u_new_password" name="new_password" maxlength="255" autocomplete="new-password">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_u_new_password_confirm">Confirm New Password</label>' . "\n"
         . '<input type="password" id="lstr_u_new_password_confirm" name="new_password_confirm" maxlength="255" autocomplete="new-password">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_u_country">Country</label>' . "\n"
         . '{country_select}' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_u_url">Updated Website URL</label>' . "\n"
         . '<input type="url" id="lstr_u_url" name="url" value="{old_url}" maxlength="255" placeholder="https://" autocomplete="url">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group form-check">' . "\n"
         . '<input type="checkbox" name="delete_url" id="lstr_u_delete_url" value="1">' . "\n"
         . '<label for="lstr_u_delete_url">Remove my website from my record</label>' . "\n"
         . '</div>' . "\n"
         . '{extra_fields}' . "\n"
         . '</fieldset>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_u_captcha">Spam check: What is {captcha_question}? <span aria-hidden="true">*</span></label>' . "\n"
         . '<input type="text" name="captcha_answer" id="lstr_u_captcha" required autocomplete="off" inputmode="numeric">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-error" role="alert">{captcha_error}</div>' . "\n"
         . '<div class="form-actions">' . "\n"
         . '<button type="submit">Update Membership</button>' . "\n"
         . '</div>' . "\n"
         . '</form>' . "\n"
         . '</div>';

    $rawTokens = [
        'country_select' => $countrySelect,
        'extra_fields'   => $extraFieldsHtml,
    ];

    $scalarTokens = [
        'form_action'      => $_lstr_pageBase,
        'csrf_token'       => $csrfToken,
        'error'            => $error,
        'captcha_error'    => $captchaError,
        'update_error'     => $updateError,
        'old_email'        => (string) ($old['email']     ?? ''),
        'old_name'         => (string) ($old['new_name']  ?? ''),
        'old_new_email'    => (string) ($old['new_email'] ?? ''),
        'old_url'          => (string) ($old['url']       ?? ''),
        'captcha_question' => $captchaQuestion,
        'lost_password_url' => $_lstr_removeUrl,
    ];

    $result = $tpl;
    foreach ($rawTokens as $k => $html) {
        $result = str_replace('{' . $k . '}', $html, $result);
    }
    foreach ($scalarTokens as $k => $v) {
        $result = str_replace('{' . $k . '}', $_lstr_e($v), $result);
    }

    echo $result;
};

// ── Dispatch ──────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_lstr_renderUpdateForm();
    return;
}

// ── POST: process submission ──────────────────────────────────────────────────

$_lstr_rateLimit = new RateLimit($lstr_db);

// 1. Rate limit
if ($_lstr_rateLimit->isBlocked($_lstr_ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
    $_lstr_renderUpdateForm('Too many attempts. Please try again later.');
    return;
}

// 2. Honeypot — silent success
if ((string) ($_POST['website_confirm'] ?? '') !== '') {
    echo '<div class="luster-update-success">'
        . "\n<p>Your membership has been updated.</p>\n"
        . '</div>';
    return;
}

// 3. CSRF
$_lstr_submittedCsrf = (string) ($_POST['_lstr_csrf'] ?? '');
$_lstr_storedCsrf    = (string) ($_SESSION['lstr_update_csrf'] ?? '');

if ($_lstr_storedCsrf === '' || !hash_equals($_lstr_storedCsrf, $_lstr_submittedCsrf)) {
    unset($_SESSION['lstr_update_csrf']);
    $_lstr_renderUpdateForm('Your session has expired. Please try again.');
    return;
}
unset($_SESSION['lstr_update_csrf']);

// 4. Collect POST values
$_lstr_email              = trim((string) ($_POST['email']               ?? ''));
$_lstr_password           = (string) ($_POST['password']                 ?? '');
$_lstr_newName            = trim((string) ($_POST['new_name']            ?? ''));
$_lstr_newEmail           = trim((string) ($_POST['new_email']           ?? ''));
$_lstr_newPassword        = (string) ($_POST['new_password']             ?? '');
$_lstr_newPasswordConfirm = (string) ($_POST['new_password_confirm']     ?? '');
$_lstr_url                = trim((string) ($_POST['url']                 ?? ''));
$_lstr_country            = trim((string) ($_POST['country']             ?? ''));
$_lstr_deleteUrl          = ((string) ($_POST['delete_url'] ?? '') === '1');

$_lstr_repopOld = [
    'email'     => $_lstr_email,
    'new_name'  => $_lstr_newName,
    'new_email' => $_lstr_newEmail,
    'url'       => $_lstr_url,
    'country'   => $_lstr_country,
];
foreach ($_lstr_fields as $_lstr_f) {
    $k = 'field_' . $_lstr_f->name;
    $_lstr_repopOld[$k] = trim((string) ($_POST[$k] ?? ''));
}

// 5. Captcha — always regenerates on validate(), so re-render gets a fresh question
if (!Captcha::validate((string) ($_POST['captcha_answer'] ?? ''), 'update')) {
    $_lstr_renderUpdateForm('', 'Incorrect answer — please try again.', '', $_lstr_repopOld);
    return;
}

// 6. Field validation
$_lstr_v = new Validator($_POST);
$_lstr_v->field('email')->required()->email()->maxLength(255);
$_lstr_v->field('password')->required();

if ($_lstr_newName !== '')  $_lstr_v->field('new_name')->maxLength(100);
if ($_lstr_newEmail !== '') $_lstr_v->field('new_email')->email()->maxLength(255);
if ($_lstr_url !== '')      $_lstr_v->field('url')->url()->maxLength(255);
if ($_lstr_country !== '')  $_lstr_v->field('country')->maxLength(100);

foreach ($_lstr_fields as $_lstr_f) {
    $vf = $_lstr_v->field('field_' . $_lstr_f->name)->maxLength(255);
    if ($_lstr_f->required) {
        $vf->required();
    }
}

if ($_lstr_v->fails()) {
    $_lstr_rateLimit->hitDefault($_lstr_ip, RateLimit::ACTION_PUBLIC_UPDATE);
    $_lstr_renderUpdateForm(implode(' ', $_lstr_v->allErrors()), '', '', $_lstr_repopOld);
    return;
}

// 7. Member lookup
$_lstr_member = $lstr_memberRepo->getByEmail($lstr_fl->dbprefix, $_lstr_email);

if ($_lstr_member === null) {
    $_lstr_rateLimit->hitDefault($_lstr_ip, RateLimit::ACTION_PUBLIC_UPDATE);
    $_lstr_renderUpdateForm('No membership found for that email address.', '', '', ['email' => $_lstr_email]);
    return;
}

// 8. Password verify — detect and upgrade legacy MD5 hashes
$_lstr_storedHash = $_lstr_member->password;
$_lstr_verified   = false;

if (strlen($_lstr_storedHash) === 32 && ctype_xdigit($_lstr_storedHash)) {
    // Legacy MD5: verify then upgrade to Argon2id
    if (hash_equals($_lstr_storedHash, md5($_lstr_password))) {
        $_lstr_verified = true;
        $lstr_memberRepo->update($lstr_fl->dbprefix, $_lstr_email, [
            'password' => password_hash($_lstr_password, PASSWORD_ARGON2ID),
        ]);
    }
} else {
    $_lstr_verified = password_verify($_lstr_password, $_lstr_storedHash);
}

if (!$_lstr_verified) {
    $_lstr_rateLimit->hitDefault($_lstr_ip, RateLimit::ACTION_PUBLIC_UPDATE);
    $_lstr_renderUpdateForm('Incorrect password. Please try again.', '', '', ['email' => $_lstr_email]);
    return;
}

// Auth passed — clear rate limit
$_lstr_rateLimit->clear($_lstr_ip, RateLimit::ACTION_PUBLIC_UPDATE);

// 9. New password confirmation (post-auth error → update_error, not main error)
$_lstr_postAuthOld = [
    'email'     => $_lstr_email,
    'new_name'  => $_lstr_newName,
    'new_email' => $_lstr_newEmail,
    'url'       => $_lstr_url,
    'country'   => $_lstr_country,
];

if ($_lstr_newPassword !== '' && $_lstr_newPassword !== $_lstr_newPasswordConfirm) {
    $_lstr_renderUpdateForm('', '', 'New passwords do not match. Please try again.', $_lstr_postAuthOld);
    return;
}

// 10. New email uniqueness (only when the address is actually changing)
if ($_lstr_newEmail !== '' && $_lstr_newEmail !== $_lstr_email) {
    if ($lstr_memberRepo->getByEmail($lstr_fl->dbprefix, $_lstr_newEmail) !== null) {
        $_lstr_renderUpdateForm('', '', 'That email address is already registered for this listing.', $_lstr_postAuthOld);
        return;
    }
}

// 11. Resolve final values — blank = keep existing
$_lstr_finalName    = $_lstr_newName  !== '' ? $_lstr_newName  : $_lstr_member->name;
$_lstr_finalEmail   = $_lstr_newEmail !== '' ? $_lstr_newEmail : $_lstr_email;
$_lstr_finalCountry = $_lstr_country  !== '' ? $_lstr_country  : $_lstr_member->country;

if ($_lstr_deleteUrl) {
    $_lstr_finalUrl = '';
} elseif ($_lstr_url !== '') {
    $_lstr_finalUrl = $_lstr_url;
} else {
    $_lstr_finalUrl = $_lstr_member->url;
}

$_lstr_updateData = [
    'name'    => $_lstr_finalName,
    'url'     => $_lstr_finalUrl,
    'country' => $_lstr_finalCountry,
    'showurl' => $_lstr_finalUrl !== '' ? 1 : 0,
];

if ($_lstr_newEmail !== '' && $_lstr_newEmail !== $_lstr_email) {
    $_lstr_updateData['email'] = $_lstr_finalEmail;
}
if ($_lstr_newPassword !== '') {
    $_lstr_updateData['password'] = password_hash($_lstr_newPassword, PASSWORD_ARGON2ID);
}

// Schema-aware additional field column names (mirrors UpdateController)
$_lstr_enthPrefix = (string) $lstr_config->get('enth_table_prefix', '');
foreach ($_lstr_fields as $_lstr_f) {
    $colName = ($_lstr_enthPrefix === '') ? $_lstr_f->name : 'additional_' . $_lstr_f->name;
    $_lstr_updateData[$colName] = trim((string) ($_POST['field_' . $_lstr_f->name] ?? ''));
}

// 12. Persist
$lstr_memberRepo->update($lstr_fl->dbprefix, $_lstr_email, $_lstr_updateData);

// 13. Confirmation email (best-effort)
try {
    $_lstr_mailer  = new Mailer($lstr_config);
    $_lstr_siteName = (string) $lstr_config->get('site_name', 'Luster');

    $_lstr_mailer->send(
        $_lstr_finalEmail,
        $_lstr_finalName,
        "Your {$lstr_fl->name} membership has been updated",
        "Hi {$_lstr_finalName},\n\n"
        . "Your membership in the {$lstr_fl->name} listing has been updated.\n\n"
        . "-- {$_lstr_siteName}",
    );
} catch (\Throwable $e) {
    error_log('[Luster] Update email failed: ' . $e->getMessage());
}

// 14. Success fragment
$_lstr_flName = $_lstr_e($lstr_fl->name);
echo '<div class="luster-update-success">' . "\n"
    . '<p>Your membership in the <strong>' . $_lstr_flName . '</strong> listing has been updated successfully.</p>' . "\n"
    . '</div>';
