<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Mailer;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Models\Fanlisting;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FanlistingSettingsRepo;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\MemberRepo;

/**
 * Fan password reset — allows fans to recover access when they forget their password.
 *
 * GET  /reset/{id}           — email entry form
 * POST /reset/{id}           — sends reset email (always shows /sent, prevents enumeration)
 * GET  /reset/{id}/sent      — "check your email" confirmation page
 * GET  /reset/{id}/confirm   — new password form (validates token from ?token= query param)
 * POST /reset/{id}/confirm   — applies new password, consumes token
 * GET  /reset/{id}/done      — success page shown after password is updated
 *
 * Security properties:
 *  - CSRF token on all POST forms (validated by CsrfMiddleware)
 *  - Honeypot on the email entry form
 *  - Arithmetic CAPTCHA on the email entry form
 *  - Rate-limited per IP (5/hr) on both POST actions
 *  - Token: bin2hex(random_bytes(32)) — 256 bits of entropy
 *  - Only the SHA-256 hash is stored in the DB; the raw token travels only in email
 *  - Token expiry: 1 hour
 *  - Email enumeration prevented: always redirects to /sent regardless of outcome
 *  - Only approved (non-pending) members can request a reset
 *  - New password validated for presence and maxLength(255) only
 */
final class ResetController
{
    private const TOKEN_TTL_SECONDS = 3600; // 1 hour

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /reset/{id}
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $session  = $this->app->getSession();
        $error    = (string) $session->get('lstr_flash_error', '');
        $oldEmail = (string) $session->get('lstr_flash_old_email', '');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old_email');

        return $this->renderResetForm($fanlistingId, $fanlisting, $oldEmail, $error);
    }

    // -----------------------------------------------------------------------
    // POST /reset/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_RESET)) {
            // Still redirect to /sent — don't reveal that the IP is blocked
            return Response::redirect($baseUrl . '/reset/' . $fanlistingId . '/sent');
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/reset/' . $fanlistingId . '/sent');
        }

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $email = trim((string) $request->post('email', ''));

        // Captcha — re-render the form (not a redirect) so CAPTCHA question refreshes
        if (!Captcha::validate((string) $request->post('captcha_answer', ''))) {
            return $this->renderResetForm(
                $fanlistingId, $fanlisting, $email,
                '', 'Incorrect answer — please try again.',
            );
        }

        $settingsRepo  = new FanlistingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($fanlistingId);
        $formUrl       = (string) $embedSettings['form_url_reset'] !== ''
                       ? (string) $embedSettings['form_url_reset']
                       : $baseUrl . '/reset/' . $fanlistingId;

        $v = new Validator(['email' => $email]);
        $v->field('email')->required()->email()->maxLength(255);

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_RESET);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($formUrl);
        }

        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_RESET);

        // Look up the member — only send email if they exist AND are approved
        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($fanlisting->dbprefix, $email);

        if ($member !== null && $member->approved) {
            $resetRepo = new FanPasswordResetRepo($this->app->getDatabase());

            // One token at a time — delete any existing token for this email+fanlisting
            $resetRepo->deleteByEmail($fanlistingId, $email);

            // Generate token
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = (new \DateTimeImmutable())
                ->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds')
                ->format('Y-m-d H:i:s');

            $resetRepo->create($fanlistingId, $email, $tokenHash, $expiresAt);

            $resetUrl = $baseUrl . '/reset/' . $fanlistingId . '/confirm?token=' . urlencode($rawToken);
            $appName  = (string) $this->app->getConfig()->get('app_name', 'Luster');

            try {
                $mailer = new Mailer($this->app->getConfig());
                $mailer->send(
                    $email,
                    $member->name,
                    "Reset your {$fanlisting->name} password",
                    "Hi {$member->name},\n\n"
                    . "We received a request to reset the password for your membership "
                    . "in the {$fanlisting->name} fanlisting.\n\n"
                    . "Click the link below to set a new password. "
                    . "This link expires in 1 hour.\n\n"
                    . $resetUrl . "\n\n"
                    . "If you did not request this, you can safely ignore this email. "
                    . "Your password will not change.\n\n"
                    . "-- {$appName}",
                );
            } catch (\Throwable $e) {
                error_log('[Luster] Fan password reset email failed: ' . $e->getMessage());
            }
        }

        // Always redirect to /sent — never reveal whether the email was found
        return Response::redirect($baseUrl . '/reset/' . $fanlistingId . '/sent');
    }

    // -----------------------------------------------------------------------
    // GET /reset/{id}/sent
    // -----------------------------------------------------------------------

    public function sent(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        $fanlistingName = $fanlisting?->name ?? 'this fanlisting';
        $baseUrl        = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('success.php', [
            'fanlisting'      => $fanlisting,
            'fanlisting_name' => $fanlistingName,
            'fanlisting_url'  => $fanlisting?->url ?? '',
            'page_title'      => 'Check Your Email',
            'success_heading' => 'Check Your Email',
            'success_message' => 'If that email address is registered for this fanlisting, '
                               . 'you will receive a password reset link shortly. '
                               . 'The link expires in 1 hour.',
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // GET /reset/{id}/confirm
    // -----------------------------------------------------------------------

    public function showConfirm(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $rawToken = trim((string) $request->query('token', ''));

        if ($rawToken === '') {
            return $this->renderConfirmForm($fanlistingId, $fanlisting, '', 'Missing or invalid reset link.');
        }

        $resetRepo = new FanPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken(hash('sha256', $rawToken));

        if ($tokenRow === null || (int) $tokenRow['fanlisting_id'] !== $fanlistingId) {
            return $this->renderConfirmForm(
                $fanlistingId, $fanlisting, '',
                'This reset link has expired or is invalid. Please request a new one.',
            );
        }

        return $this->renderConfirmForm($fanlistingId, $fanlisting, $rawToken);
    }

    // -----------------------------------------------------------------------
    // POST /reset/{id}/confirm
    // -----------------------------------------------------------------------

    public function submitConfirm(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_RESET)) {
            return Response::redirect($baseUrl . '/reset/' . $fanlistingId . '/sent');
        }

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $rawToken = trim((string) $request->post('token', ''));

        if ($rawToken === '') {
            return $this->renderConfirmForm(
                $fanlistingId, $fanlisting, '',
                'Missing token. Please use the link from your email.',
            );
        }

        $resetRepo = new FanPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken(hash('sha256', $rawToken));

        if ($tokenRow === null || (int) $tokenRow['fanlisting_id'] !== $fanlistingId) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_RESET);
            return $this->renderConfirmForm(
                $fanlistingId, $fanlisting, '',
                'This reset link has expired or is invalid. Please request a new one.',
            );
        }

        $newPassword        = (string) $request->post('new_password',        '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');

        $v = new Validator(['new_password' => $newPassword]);
        $v->field('new_password')->required()->maxLength(255);

        if ($v->fails()) {
            return $this->renderConfirmForm(
                $fanlistingId, $fanlisting, $rawToken,
                implode(' ', $v->allErrors()),
            );
        }

        if ($newPassword !== $newPasswordConfirm) {
            return $this->renderConfirmForm(
                $fanlistingId, $fanlisting, $rawToken,
                'Passwords do not match. Please try again.',
            );
        }

        // Update the member's password
        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $memberRepo->update(
            $fanlisting->dbprefix,
            (string) $tokenRow['email'],
            ['password' => password_hash($newPassword, PASSWORD_ARGON2ID)],
        );

        // Consume the token — one-time use
        $resetRepo->deleteByToken(hash('sha256', $rawToken));
        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_RESET);

        // Redirect to configured URL or default done page
        $settingsRepo  = new FanlistingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($fanlistingId);
        $redirectUrl   = (string) $embedSettings['redirect_url_reset'];
        if ($redirectUrl !== '') {
            return Response::redirect($redirectUrl);
        }

        return Response::redirect($baseUrl . '/reset/' . $fanlistingId . '/done');
    }

    // -----------------------------------------------------------------------
    // GET /reset/{id}/done
    // -----------------------------------------------------------------------

    public function done(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        $fanlistingName = $fanlisting?->name ?? 'this fanlisting';
        $baseUrl        = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('success.php', [
            'fanlisting'      => $fanlisting,
            'fanlisting_name' => $fanlistingName,
            'fanlisting_url'  => $fanlisting?->url ?? '',
            'page_title'      => 'Password Updated',
            'success_heading' => 'Password Updated',
            'success_message' => "Your password for the {$fanlistingName} fanlisting has been updated. "
                               . 'You can now use it to update or remove your membership.',
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function renderResetForm(
        int $fanlistingId,
        Fanlisting $fanlisting,
        string $oldEmail = '',
        string $error = '',
        string $captchaError = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('reset.php', [
            'fanlisting'      => $fanlisting,
            'fanlisting_name' => $fanlisting->name,
            'fanlisting_url'  => $fanlisting->url,
            'page_title'      => 'Reset Password',
            'form_action'     => $baseUrl . '/reset/' . $fanlistingId,
            'csrf_token'      => $this->app->getCSRF()->token(),
            'errors'          => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'field_email'     => $oldEmail,
            'captcha_question' => Captcha::getQuestion(),
            'captcha_error'   => $captchaError,
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    private function renderConfirmForm(
        int $fanlistingId,
        Fanlisting $fanlisting,
        string $rawToken,
        string $error = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('reset_confirm.php', [
            'fanlisting'      => $fanlisting,
            'fanlisting_name' => $fanlisting->name,
            'fanlisting_url'  => $fanlisting->url,
            'page_title'      => 'Set New Password',
            'form_action'     => $baseUrl . '/reset/' . $fanlistingId . '/confirm',
            'reset_url'       => $baseUrl . '/reset/' . $fanlistingId,
            'csrf_token'      => $this->app->getCSRF()->token(),
            'errors'          => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'token'           => $rawToken,
            'token_valid'     => $rawToken !== '',
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }
}
