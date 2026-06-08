<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Models\Listing;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\MemberRepo;
use Luster\Support\FormFieldBuilder;
use Luster\Support\SuccessRenderer;

/**
 * Fan password reset — allows fans to recover access when they forget their password.
 *
 * GET  /reset/{id}           — email entry form
 * POST /reset/{id}           — sends reset email, redirects to host page (prevents enumeration)
 * GET  /reset/{id}/confirm   — new password form (validates token from ?token= query param)
 * POST /reset/{id}/confirm   — applies new password, consumes token, redirects to host page
 *
 * Security properties:
 *  - CSRF token on all POST forms (validated by CsrfMiddleware)
 *  - Honeypot on the email entry form
 *  - Arithmetic CAPTCHA on the email entry form
 *  - Rate-limited per IP (5/hr) on both POST actions
 *  - Token: bin2hex(random_bytes(32)) — 256 bits of entropy
 *  - Only the SHA-256 hash is stored in the DB; the raw token travels only in email
 *  - Token expiry: 1 hour
 *  - Email enumeration prevented: always flashes the same neutral message regardless of outcome
 *  - Only approved (non-pending) members can request a reset
 *  - New password validated against the shared password policy
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
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $session  = $this->app->getSession();
        $error    = (string) $session->get('lstr_flash_error', '');
        $oldEmail = (string) $session->get('lstr_flash_old_email', '');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old_email');

        return $this->renderResetForm($listingId, $listing, $oldEmail, $error);
    }

    // -----------------------------------------------------------------------
    // POST /reset/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_RESET)) {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'reset-sent'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/reset/' . $listingId);
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'reset-sent'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/reset/' . $listingId);
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $email = trim((string) $request->post('email', ''));

        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($listingId);
        $formPageName  = (string) $embedSettings['form_url_reset'];
        $formUrl       = $formPageName !== ''
                       ? rtrim((string) $listing->url, '/') . '/' . ltrim($formPageName, '/')
                       : $baseUrl . '/reset/' . $listingId;

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'reset')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_RESET);
            $session->set('lstr_flash_error', 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $v = new Validator(['email' => $email]);
        $v->field('email')->required()->email()->maxLength(255);

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_RESET);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($formUrl);
        }

        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_RESET);

        // Blacklist check — silently skip sending if this address is blocked.
        // Always redirect with the same neutral message so the block is not revealed.
        if ((new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $session->remove('lstr_embed_return_to');
            $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'reset-sent'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Look up the member — only send email if they exist AND are approved
        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($listing->dbprefix, $email);

        if ($member !== null && $member->approved) {
            $resetRepo = new FanPasswordResetRepo($this->app->getDatabase());

            // One token at a time — delete any existing token for this email+fanlisting
            $resetRepo->deleteByEmail($listingId, $email);

            // Generate token
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = (new \DateTimeImmutable())
                ->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds')
                ->format('Y-m-d H:i:s');

            $resetRepo->create($listingId, $email, $tokenHash, $expiresAt);

            $resetUrl = $baseUrl . '/reset/' . $listingId . '/confirm?token=' . urlencode($rawToken);

            try {
                $cfg       = $this->app->getConfig();
                $mailer    = new Mailer($cfg);
                $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
                $tokens    = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $cfg->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $cfg->get('owner_name', ''),
                    ownerEmail:     (string) $cfg->get('owner_email', ''),
                    fanName:        $member->name,
                    fanEmail:       $email,
                    resetUrl:       $resetUrl,
                );
                [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_RESET, $tokens);
                $mailer->send($email, $member->name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[Luster] Fan password reset email failed: ' . $e->getMessage());
            }
        }

        // Always flash the same neutral message and redirect to host page — never reveal whether the email was found
        $session->remove('lstr_embed_return_to');
        $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
        $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'reset-sent'));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
    }

    // -----------------------------------------------------------------------
    // GET /reset/{id}/confirm
    // -----------------------------------------------------------------------

    public function showConfirm(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $rawToken = trim((string) $request->query('token', ''));

        if ($rawToken === '') {
            return $this->renderConfirmForm($listingId, $listing, '', 'Missing or invalid reset link.');
        }

        $resetRepo = new FanPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken(hash('sha256', $rawToken));

        if ($tokenRow === null || (int) $tokenRow['fanlisting_id'] !== $listingId) {
            return $this->renderConfirmForm(
                $listingId, $listing, '',
                'This reset link has expired or is invalid. Please request a new one.',
            );
        }

        return $this->renderConfirmForm($listingId, $listing, $rawToken);
    }

    // -----------------------------------------------------------------------
    // POST /reset/{id}/confirm
    // -----------------------------------------------------------------------

    public function submitConfirm(Request $request, array $params): Response
    {
        $listingId    = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_RESET)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/reset/' . $listingId);
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $rawToken = trim((string) $request->post('token', ''));

        if ($rawToken === '') {
            return $this->renderConfirmForm(
                $listingId, $listing, '',
                'Missing token. Please use the link from your email.',
            );
        }

        $resetRepo = new FanPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken(hash('sha256', $rawToken));

        if ($tokenRow === null || (int) $tokenRow['fanlisting_id'] !== $listingId) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_RESET);
            return $this->renderConfirmForm(
                $listingId, $listing, '',
                'This reset link has expired or is invalid. Please request a new one.',
            );
        }

        $newPassword        = (string) $request->post('new_password',        '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');

        $v = new Validator(['new_password' => $newPassword]);
        PasswordPolicy::apply($v->field('new_password'));

        if ($v->fails()) {
            return $this->renderConfirmForm(
                $listingId, $listing, $rawToken,
                implode(' ', $v->allErrors()),
            );
        }

        if ($newPassword !== $newPasswordConfirm) {
            return $this->renderConfirmForm(
                $listingId, $listing, $rawToken,
                'Passwords do not match. Please try again.',
            );
        }

        // Update the member's password
        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $memberRepo->update(
            $listing->dbprefix,
            (string) $tokenRow['email'],
            ['password' => password_hash($newPassword, PASSWORD_ARGON2ID)],
        );

        // Consume the token — one-time use
        $resetRepo->deleteByToken(hash('sha256', $rawToken));
        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_RESET);

        $session->remove('lstr_embed_return_to');
        $successTpl = (new ListingSettingsRepo($this->app->getDatabase()))->getSuccessTemplate($listingId) ?? '';
        $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'reset-done', [
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
        ]));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/reset/' . $listingId);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function renderResetForm(
        int $listingId,
        Listing $listing,
        string $oldEmail = '',
        string $error = '',
        string $captchaError = '',
    ): Response {
        $baseUrl         = $this->app->getBaseUrl();
        $captchaQuestion = Captcha::getQuestion('reset');
        $wrapperTpl      = (new ListingSettingsRepo($this->app->getDatabase()))->getWrapperTemplate($listingId) ?? '';

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'email', 'name' => 'email',          'id' => 'rst-email',   'label' => 'Email',    'required' => true, 'value' => $oldEmail,       'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'text',  'name' => 'captcha_answer', 'id' => 'rst-captcha', 'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'error' => $captchaError, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ], $wrapperTpl);

        $html = $this->app->getView()->renderPublic('reset.php', [
            'listing'      => $listing,
            'listing_name' => $listing->title,
            'listing_url'  => $listing->url,
            'page_title'   => 'Reset Password',
            'form_action'  => $baseUrl . '/reset/' . $listingId,
            'csrf_token'   => $this->app->getCSRF()->token(),
            'errors'       => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'f'            => $f,
            'base_url'     => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    private function renderConfirmForm(
        int $listingId,
        Listing $listing,
        string $rawToken,
        string $error = '',
    ): Response {
        $baseUrl    = $this->app->getBaseUrl();
        $wrapperTpl = (new ListingSettingsRepo($this->app->getDatabase()))->getWrapperTemplate($listingId) ?? '';

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'password', 'name' => 'new_password',         'id' => 'rc-password', 'label' => 'New Password',         'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
            ['type' => 'password', 'name' => 'new_password_confirm',  'id' => 'rc-confirm',  'label' => 'Confirm New Password', 'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
        ], $wrapperTpl);

        $html = $this->app->getView()->renderPublic('reset_confirm.php', [
            'listing'      => $listing,
            'listing_name' => $listing->title,
            'listing_url'  => $listing->url,
            'page_title'   => 'Set New Password',
            'form_action'  => $baseUrl . '/reset/' . $listingId . '/confirm',
            'reset_url'    => $baseUrl . '/reset/' . $listingId,
            'csrf_token'   => $this->app->getCSRF()->token(),
            'errors'       => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'token'        => $rawToken,
            'token_valid'  => $rawToken !== '',
            'f'            => $f,
            'base_url'     => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }
}
