<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\Mailer;
use Luster\Core\RateLimit;
use Luster\Core\Session;
use Luster\Core\Validator;

/**
 * show_remove.php — Embeddable Luster membership-removal widget.
 *
 * Outputs a self-contained removal form fragment. Handles both GET (show form)
 * and POST (authenticate then delete) from the same include. No HTML wrapper —
 * drop this include anywhere inside your page's body.
 *
 * Usage
 * -----
 *   <?php
 *   $luster_listing = 3;
 *   include '/path/to/luster/public/show_remove.php';
 *
 * Security model (matches RemoveController):
 *   - Per-embed CSRF token in session (key: lstr_remove_csrf)
 *   - Honeypot field (website_confirm) rejects bots silently
 *   - Arithmetic CAPTCHA via Luster\Core\Captcha
 *   - Rate-limited per IP: 10 attempts/hour via lstr_rate_limits
 *   - Email + password authenticated before deletion
 *   - Legacy MD5 password detected and verified before deletion
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

$_lstr_pageBase = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
$_lstr_ip       = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

$_lstr_e = static fn(string $v): string =>
    htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ── Form renderer ─────────────────────────────────────────────────────────────

/**
 * @param string $error        Main credential/rate-limit error
 * @param string $captchaError Captcha-specific error
 * @param string $oldEmail     Email to repopulate on re-render
 */
$_lstr_renderRemoveForm = function (
    string $error = '',
    string $captchaError = '',
    string $oldEmail = '',
) use ($lstr_fl, $_lstr_pageBase, $_lstr_e): void {
    if (!isset($_SESSION['lstr_remove_csrf'])) {
        $_SESSION['lstr_remove_csrf'] = bin2hex(random_bytes(32));
    }
    $csrfToken = (string) $_SESSION['lstr_remove_csrf'];

    $captchaQuestion = Captcha::getQuestion('remove');

    $tpl = '<div class="luster-remove">' . "\n"
         . '<div class="form-error" role="alert">{error}</div>' . "\n"
         . '<p>Enter your email address and password to remove yourself from this listing.</p>' . "\n"
         . '<p><strong>This action cannot be undone.</strong></p>' . "\n"
         . '<form method="post" action="{form_action}" novalidate>' . "\n"
         . '<input type="hidden" name="_lstr_csrf" value="{csrf_token}">' . "\n"
         . '<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">' . "\n"
         . '<input type="text" name="website_confirm" id="lstr_wc_r" tabindex="-1" autocomplete="off">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_r_email">Email Address <span aria-hidden="true">*</span></label>' . "\n"
         . '<input type="email" id="lstr_r_email" name="email" value="{old_email}" maxlength="255" required autocomplete="email">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_r_password">Password <span aria-hidden="true">*</span></label>' . "\n"
         . '<input type="password" id="lstr_r_password" name="password" maxlength="255" required autocomplete="current-password">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-group">' . "\n"
         . '<label for="lstr_r_captcha">Spam check: What is {captcha_question}? <span aria-hidden="true">*</span></label>' . "\n"
         . '<input type="text" name="captcha_answer" id="lstr_r_captcha" required autocomplete="off" inputmode="numeric">' . "\n"
         . '</div>' . "\n"
         . '<div class="form-error" role="alert">{captcha_error}</div>' . "\n"
         . '<div class="form-actions">' . "\n"
         . '<button type="submit" class="btn-danger">Remove Membership</button>' . "\n"
         . '</div>' . "\n"
         . '</form>' . "\n"
         . '</div>';

    $scalarTokens = [
        'form_action'      => $_lstr_pageBase,
        'csrf_token'       => $csrfToken,
        'error'            => $error,
        'captcha_error'    => $captchaError,
        'old_email'        => $oldEmail,
        'captcha_question' => $captchaQuestion,
    ];

    $result = $tpl;
    foreach ($scalarTokens as $k => $v) {
        $result = str_replace('{' . $k . '}', $_lstr_e($v), $result);
    }

    echo $result;
};

// ── Dispatch ──────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_lstr_renderRemoveForm();
    return;
}

// ── POST: process submission ──────────────────────────────────────────────────

$_lstr_rateLimit = new RateLimit($lstr_db);

// 1. Rate limit
if ($_lstr_rateLimit->isBlocked($_lstr_ip, RateLimit::ACTION_PUBLIC_REMOVE)) {
    $_lstr_renderRemoveForm('Too many attempts. Please try again later.');
    return;
}

// 2. Honeypot — silent success
if ((string) ($_POST['website_confirm'] ?? '') !== '') {
    echo '<div class="luster-remove-success">'
        . "\n<p>Your membership has been removed.</p>\n"
        . '</div>';
    return;
}

// 3. CSRF
$_lstr_submittedCsrf = (string) ($_POST['_lstr_csrf'] ?? '');
$_lstr_storedCsrf    = (string) ($_SESSION['lstr_remove_csrf'] ?? '');

if ($_lstr_storedCsrf === '' || !hash_equals($_lstr_storedCsrf, $_lstr_submittedCsrf)) {
    unset($_SESSION['lstr_remove_csrf']);
    $_lstr_renderRemoveForm('Your session has expired. Please try again.');
    return;
}
unset($_SESSION['lstr_remove_csrf']);

// 4. Collect POST values (email needed before captcha for re-render repopulation)
$_lstr_email    = trim((string) ($_POST['email']    ?? ''));
$_lstr_password = (string) ($_POST['password'] ?? '');

// 5. Captcha
if (!Captcha::validate((string) ($_POST['captcha_answer'] ?? ''), 'remove')) {
    $_lstr_renderRemoveForm('', 'Incorrect answer — please try again.', $_lstr_email);
    return;
}

// 6. Field validation
$_lstr_v = new Validator(['email' => $_lstr_email, 'password' => $_lstr_password]);
$_lstr_v->field('email')->required()->email()->maxLength(255);
$_lstr_v->field('password')->required();

if ($_lstr_v->fails()) {
    $_lstr_rateLimit->hitDefault($_lstr_ip, RateLimit::ACTION_PUBLIC_REMOVE);
    $_lstr_renderRemoveForm(implode(' ', $_lstr_v->allErrors()), '', $_lstr_email);
    return;
}

// 7. Member lookup
$_lstr_member = $lstr_memberRepo->getByEmail($lstr_fl->dbprefix, $_lstr_email);

if ($_lstr_member === null) {
    $_lstr_rateLimit->hitDefault($_lstr_ip, RateLimit::ACTION_PUBLIC_REMOVE);
    $_lstr_renderRemoveForm('No membership found for that email address.', '', $_lstr_email);
    return;
}

// 8. Password verify — detect legacy MD5 (no upgrade needed before deletion)
$_lstr_storedHash = $_lstr_member->password;
$_lstr_verified   = false;

if (strlen($_lstr_storedHash) === 32 && ctype_xdigit($_lstr_storedHash)) {
    $_lstr_verified = hash_equals($_lstr_storedHash, md5($_lstr_password));
} else {
    $_lstr_verified = password_verify($_lstr_password, $_lstr_storedHash);
}

if (!$_lstr_verified) {
    $_lstr_rateLimit->hitDefault($_lstr_ip, RateLimit::ACTION_PUBLIC_REMOVE);
    $_lstr_renderRemoveForm('Incorrect password. Please try again.', '', $_lstr_email);
    return;
}

// 9. Delete member
$_lstr_memberName = $_lstr_member->name;
$lstr_memberRepo->deleteByEmail($lstr_fl->dbprefix, $_lstr_email);

// 10. Farewell email (best-effort)
try {
    $_lstr_mailer  = new Mailer($lstr_config);
    $_lstr_appName = (string) $lstr_config->get('app_name', 'Luster');

    $_lstr_mailer->send(
        $_lstr_email,
        $_lstr_memberName,
        "You have been removed from the {$lstr_fl->name} fanlisting",
        "Hi {$_lstr_memberName},\n\n"
        . "Your membership in the {$lstr_fl->name} listing has been removed as requested.\n\n"
        . "-- {$_lstr_appName}",
    );
} catch (\Throwable $e) {
    error_log('[Luster] Remove email failed: ' . $e->getMessage());
}

// 11. Success fragment
$_lstr_flName = $_lstr_e($lstr_fl->name);
echo '<div class="luster-remove-success">' . "\n"
    . '<p>Your membership in the <strong>' . $_lstr_flName . '</strong> listing has been removed. We\'re sorry to see you go!</p>' . "\n"
    . '</div>';
