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
 * show_join.php — Embeddable Luster join form widget.
 *
 * Outputs a self-contained join form fragment. Handles both GET (show form)
 * and POST (process submission) from the same include. No HTML wrapper — drop
 * this include anywhere inside your page's body.
 *
 * Usage
 * -----
 *   <?php
 *   $luster_listing = 3;
 *   include '/path/to/luster/public/show_join.php';
 *
 * Security model (matches JoinController):
 *   - Per-embed CSRF token in session (key: lstr_join_csrf)
 *   - Honeypot field (website_confirm) rejects bots silently
 *   - Arithmetic CAPTCHA via Luster\Core\Captcha
 *   - Rate-limited per IP: 10 joins/hour via lstr_rate_limits
 *   - All input validated with Luster\Core\Validator before any DB write
 *   - Duplicate email checked before insert
 *   - Column names from FieldRepo only — never from user input
 *   - Passwords hashed with PASSWORD_ARGON2ID; auto-generated if omitted
 *
 * The form POSTs back to the current page URL. On success, a success fragment
 * is echoed in place of the form. On error, the form is re-rendered inline
 * with error messages (no redirect needed — PRG is for full-page flows).
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

/** Current page path without query string — used as the form action URL. */
$_lstr_pageBase = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';

/** Requester IP address for rate limiting. */
$_lstr_ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

/** HTML-escape helper. */
$_lstr_e = static fn(string $v): string =>
    htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ── Extra fields HTML builder ─────────────────────────────────────────────────

/**
 * Builds the HTML block for all dynamic additional fields.
 * Output is raw, pre-escaped HTML — do not double-escape.
 *
 * @param \Luster\Models\AdditionalField[] $fields
 * @param array<string,string>             $old  POST repopulation values
 */
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
 * Renders and echoes the join form fragment.
 *
 * Generates a fresh CSRF token if one is not already in session.
 * Always calls Captcha::getQuestion() (which auto-generates on first call).
 *
 * @param string               $error        Main error message (empty = none)
 * @param string               $captchaError Captcha-specific error (empty = none)
 * @param array<string,string> $old          POST values to repopulate
 */
$_lstr_renderJoinForm = function (
    string $error = '',
    string $captchaError = '',
    array  $old = [],
) use ($lstr_fl, $_lstr_fields, $_lstr_pageBase, $_lstr_e, $_lstr_buildExtraFields): void {
    // Ensure a CSRF token exists for this embed session
    if (!isset($_SESSION['lstr_join_csrf'])) {
        $_SESSION['lstr_join_csrf'] = bin2hex(random_bytes(32));
    }
    $csrfToken = (string) $_SESSION['lstr_join_csrf'];

    $captchaQuestion = Captcha::getQuestion('join');
    $countrySelect   = Countries::buildSelect((string) ($old['country'] ?? ''));
    $extraFieldsHtml = $_lstr_buildExtraFields($_lstr_fields, $old);

    // "send info" checkbox: default to checked; honour old value on re-render
    $sendInfoChecked = (isset($old['send_info']) && $old['send_info'] === '')
        ? ''
        : 'checked';

    // Build template string (fragment only — no html/head/body)
    $tpl = '<div class="luster-join">' . "\n"
         . '<div class="form-error" role="alert">{error}</div>' . "\n"
         . '<p>Fields marked <span aria-hidden="true">*</span> are required.</p>' . "\n"
         . '<form method="post" action="{form_action}" novalidate>' . "\n"
         . '<input type="hidden" name="_lstr_csrf" value="{csrf_token}">' . "\n"
         . '<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">' . "\n"
         . '<input type="text" name="website_confirm" id="lstr_website_confirm" tabindex="-1" autocomplete="off">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_name">Name <span aria-hidden="true">*</span></label>' . "\n"
         . '<input type="text" id="lstr_name" name="name" value="{old_name}" maxlength="100" required autocomplete="name">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_email">Email Address <span aria-hidden="true">*</span></label>' . "\n"
         . '<input type="email" id="lstr_email" name="email" value="{old_email}" maxlength="255" required autocomplete="email">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_url">Website URL</label>' . "\n"
         . '<input type="url" id="lstr_url" name="url" value="{old_url}" maxlength="255" placeholder="https://" autocomplete="url">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="country">Country <span aria-hidden="true">*</span></label>' . "\n"
         . '{country_select}' . "\n"
         . '</div>' . "\n"
         . '{extra_fields}' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_password">Password</label>' . "\n"
         . '<input type="password" id="lstr_password" name="password" maxlength="255" autocomplete="new-password">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_password_confirm">Confirm Password</label>' . "\n"
         . '<input type="password" id="lstr_password_confirm" name="password_confirm" maxlength="255" autocomplete="new-password">' . "\n"
         . '<small class="form-hint">Leave blank to have a password emailed to you.</small>' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group form-check">' . "\n"
         . '<input type="checkbox" name="send_info" id="lstr_send_info" value="1" {send_info_checked}>' . "\n"
         . '<label for="lstr_send_info">Yes, send me my account information!</label>' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_captcha">Spam check: What is {captcha_question}? <span aria-hidden="true">*</span></label>' . "\n"
         . '<input type="text" name="captcha_answer" id="lstr_captcha" required autocomplete="off" inputmode="numeric">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-error" role="alert">{captcha_error}</div>' . "\n"
         . '<div class="form-actions">' . "\n"
         . '<button type="submit">Join Now</button>' . "\n"
         . '</div>' . "\n"
         . '</form>' . "\n"
         . '</div>';

    // Raw tokens (pre-built HTML — not auto-escaped)
    $rawTokens = [
        'country_select' => $countrySelect,
        'extra_fields'   => $extraFieldsHtml,
    ];

    // Scalar tokens (auto-escaped before substitution)
    $scalarTokens = [
        'form_action'       => $_lstr_pageBase,
        'csrf_token'        => $csrfToken,
        'error'             => $error,
        'captcha_error'     => $captchaError,
        'old_name'          => (string) ($old['name']  ?? ''),
        'old_email'         => (string) ($old['email'] ?? ''),
        'old_url'           => (string) ($old['url']   ?? ''),
        'captcha_question'  => $captchaQuestion,
        'send_info_checked' => $sendInfoChecked,
    ];

    // Apply substitutions
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
    // ── GET: show blank form ─────────────────────────────────────────────────
    $_lstr_renderJoinForm();
    return;
}

// ── POST: process submission ──────────────────────────────────────────────────

$_lstr_rateLimit = new RateLimit($lstr_db);

// 1. Rate limit check
if ($_lstr_rateLimit->isBlocked($_lstr_ip, RateLimit::ACTION_PUBLIC_JOIN)) {
    $_lstr_renderJoinForm('Too many join attempts. Please try again later.');
    return;
}

// 2. Honeypot — silent success so the bot thinks it worked
if ((string) ($_POST['website_confirm'] ?? '') !== '') {
    echo '<div class="luster-join-success">'
        . "\n<p>Thank you for joining!</p>\n"
        . '</div>';
    return;
}

// 3. CSRF validation
$_lstr_submittedCsrf = (string) ($_POST['_lstr_csrf'] ?? '');
$_lstr_storedCsrf    = (string) ($_SESSION['lstr_join_csrf'] ?? '');

if ($_lstr_storedCsrf === '' || !hash_equals($_lstr_storedCsrf, $_lstr_submittedCsrf)) {
    unset($_SESSION['lstr_join_csrf']);
    $_lstr_renderJoinForm('Your session has expired. Please try again.');
    return;
}

// Invalidate token immediately after use (one-time)
unset($_SESSION['lstr_join_csrf']);

// 4. Captcha — validate() always regenerates a fresh question regardless of outcome
$_lstr_captchaOld = [
    'name'      => trim((string) ($_POST['name']    ?? '')),
    'email'     => trim((string) ($_POST['email']   ?? '')),
    'url'       => trim((string) ($_POST['url']     ?? '')),
    'country'   => trim((string) ($_POST['country'] ?? '')),
    'send_info' => (string) ($_POST['send_info'] ?? ''),
];
foreach ($_lstr_fields as $_lstr_f) {
    $k = 'field_' . $_lstr_f->name;
    $_lstr_captchaOld[$k] = trim((string) ($_POST[$k] ?? ''));
}

if (!Captcha::validate((string) ($_POST['captcha_answer'] ?? ''), 'join')) {
    $_lstr_renderJoinForm('', 'Incorrect answer — please try again.', $_lstr_captchaOld);
    return;
}

// 5. Field validation
$_lstr_name    = trim((string) ($_POST['name']    ?? ''));
$_lstr_email   = trim((string) ($_POST['email']   ?? ''));
$_lstr_url     = trim((string) ($_POST['url']     ?? ''));
$_lstr_country = trim((string) ($_POST['country'] ?? ''));

$_lstr_v = new Validator($_POST);
$_lstr_v->field('name')->required()->maxLength(100);
$_lstr_v->field('email')->required()->email()->maxLength(255);
$_lstr_v->field('url')->url()->maxLength(255);
$_lstr_v->field('country')->required()->maxLength(100);

foreach ($_lstr_fields as $_lstr_f) {
    $vf = $_lstr_v->field('field_' . $_lstr_f->name)->maxLength(255);
    if ($_lstr_f->required) {
        $vf->required();
    }
}

if ($_lstr_v->fails()) {
    $_lstr_rateLimit->hitDefault($_lstr_ip, RateLimit::ACTION_PUBLIC_JOIN);
    $_lstr_validationOld = [
        'name'      => $_lstr_name,
        'email'     => $_lstr_email,
        'url'       => $_lstr_url,
        'country'   => $_lstr_country,
        'send_info' => (string) ($_POST['send_info'] ?? ''),
    ];
    foreach ($_lstr_fields as $_lstr_f) {
        $k = 'field_' . $_lstr_f->name;
        $_lstr_validationOld[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $_lstr_renderJoinForm(implode(' ', $_lstr_v->allErrors()), '', $_lstr_validationOld);
    return;
}

// 6. Duplicate email check
if ($lstr_memberRepo->getByEmail($lstr_fl->dbprefix, $_lstr_email) !== null) {
    $_lstr_dupOld = [
        'name'      => $_lstr_name,
        'email'     => $_lstr_email,
        'url'       => $_lstr_url,
        'country'   => $_lstr_country,
        'send_info' => (string) ($_POST['send_info'] ?? ''),
    ];
    $_lstr_renderJoinForm('That email address is already registered for this listing.', '', $_lstr_dupOld);
    return;
}

// 7. Password handling
$_lstr_rawPassword     = (string) ($_POST['password']         ?? '');
$_lstr_passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

if ($_lstr_rawPassword !== '' && $_lstr_rawPassword !== $_lstr_passwordConfirm) {
    $_lstr_pwOld = [
        'name'      => $_lstr_name,
        'email'     => $_lstr_email,
        'url'       => $_lstr_url,
        'country'   => $_lstr_country,
        'send_info' => (string) ($_POST['send_info'] ?? ''),
    ];
    $_lstr_renderJoinForm('Passwords do not match. Please try again.', '', $_lstr_pwOld);
    return;
}

$_lstr_wasGenerated = false;
if ($_lstr_rawPassword === '') {
    $_lstr_rawPassword  = bin2hex(random_bytes(8));
    $_lstr_wasGenerated = true;
}
$_lstr_passwordHash = password_hash($_lstr_rawPassword, PASSWORD_ARGON2ID);

// 8. Build insert data
$_lstr_insertData = [
    'name'     => $_lstr_name,
    'email'    => $_lstr_email,
    'url'      => $_lstr_url,
    'country'  => $_lstr_country,
    'showurl'  => $_lstr_url !== '' ? 1 : 0,
    'password' => $_lstr_passwordHash,
    'added'    => date('Y-m-d H:i:s'),
];

// Schema-aware approval column (mirrors JoinController)
$_lstr_enthPrefix = (string) $lstr_config->get('enth_table_prefix', '');

if ($_lstr_enthPrefix === '') {
    // Unprefixed Enthusiast 3: pending=0 means approved
    $_lstr_insertData['pending'] = 0;
} else {
    // Standard Enthusiast: approved=1 means approved
    $_lstr_insertData['approved'] = 1;
    $_lstr_insertData['updated']  = date('Y-m-d H:i:s');
}

// Additional fields — column names from FieldRepo only, never from user input
foreach ($_lstr_fields as $_lstr_f) {
    $colName = ($_lstr_enthPrefix === '') ? $_lstr_f->name : 'additional_' . $_lstr_f->name;
    $_lstr_insertData[$colName] = trim((string) ($_POST['field_' . $_lstr_f->name] ?? ''));
}

// 9. Insert and clear rate limit
$lstr_memberRepo->insert($lstr_fl->dbprefix, $_lstr_insertData);
$_lstr_rateLimit->clear($_lstr_ip, RateLimit::ACTION_PUBLIC_JOIN);

// 10. Confirmation email (best-effort — do not abort on failure)
$_lstr_sendInfo = (($_POST['send_info'] ?? '') === '1');

if ($_lstr_sendInfo || $_lstr_wasGenerated) {
    try {
        $_lstr_mailer  = new Mailer($lstr_config);
        $_lstr_appName = (string) $lstr_config->get('app_name', 'Luster');

        $_lstr_subject = "Welcome to the {$lstr_fl->name} listing!";
        $_lstr_body    = "Hi {$_lstr_name},\n\n"
                       . "Thank you for joining the {$lstr_fl->name} listing.\n\n"
                       . "Here are your membership details:\n"
                       . "  Name    : {$_lstr_name}\n"
                       . "  Email   : {$_lstr_email}\n"
                       . "  Website : " . ($_lstr_url !== '' ? $_lstr_url : '(none)') . "\n"
                       . "  Country : {$_lstr_country}\n"
                       . "  Password: {$_lstr_rawPassword}\n\n";

        if ($_lstr_wasGenerated) {
            $_lstr_body .= "Your password was automatically generated since you did not provide one.\n\n";
        }

        $_lstr_body .= "Please keep this email for your records. "
                     . "You will need your password to update or remove your membership.\n\n"
                     . "-- {$_lstr_appName}";

        $_lstr_mailer->send($_lstr_email, $_lstr_name, $_lstr_subject, $_lstr_body);
    } catch (\Throwable $e) {
        error_log('[Luster] Join email failed: ' . $e->getMessage());
    }
}

// 11. Success fragment
$_lstr_flName = $_lstr_e($lstr_fl->name);

echo '<div class="luster-join-success">' . "\n"
    . '<p>Thank you for joining the <strong>' . $_lstr_flName . '</strong> listing! '
    . 'Your membership may need to be approved before it appears in the list.</p>' . "\n"
    . '</div>';
