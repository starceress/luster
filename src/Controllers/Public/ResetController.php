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

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_RESET)) {
            // Still redirect to /sent — don't reveal that the IP is blocked
            return Response::redirect($baseUrl . '/reset/' . $listingId . '/sent');
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/reset/' . $listingId . '/sent');
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $email = trim((string) $request->post('email', ''));

        // Captcha — re-render the form (not a redirect) so CAPTCHA question refreshes
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'reset')) {
            return $this->renderResetForm(
                $listingId, $listing, $email,
                '', 'Incorrect answer — please try again.',
            );
        }

        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($listingId);
        $formPageName  = (string) $embedSettings['form_url_reset'];
        $formUrl       = $formPageName !== ''
                       ? rtrim((string) $listing->url, '/') . '/' . ltrim($formPageName, '/')
                       : $baseUrl . '/reset/' . $listingId;

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
        // We still redirect to /sent so the block is not revealed.
        if ((new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            return Response::redirect($baseUrl . '/reset/' . $listingId . '/sent');
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
                    listingName:    $listing->name,
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

        // Always redirect to /sent — never reveal whether the email was found
        return Response::redirect($baseUrl . '/reset/' . $listingId . '/sent');
    }

    // -----------------------------------------------------------------------
    // GET /reset/{id}/sent
    // -----------------------------------------------------------------------

    public function sent(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        $listingName = $listing?->name ?? 'this listing';
        $baseUrl        = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('success.php', [
            'listing'      => $listing,
            'listing_name' => $listingName,
            'listing_url'  => $listing?->url ?? '',
            'page_title'      => 'Check Your Email',
            'success_heading' => 'Check Your Email',
            'success_message' => 'If that email address is registered for this listing, '
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
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_RESET)) {
            return Response::redirect($baseUrl . '/reset/' . $listingId . '/sent');
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

        return Response::redirect($baseUrl . '/reset/' . $listingId . '/done');
    }

    // -----------------------------------------------------------------------
    // GET /reset/{id}/done
    // -----------------------------------------------------------------------

    public function done(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        $listingName = $listing?->name ?? 'this listing';
        $baseUrl        = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('success.php', [
            'listing'      => $listing,
            'listing_name' => $listingName,
            'listing_url'  => $listing?->url ?? '',
            'page_title'      => 'Password Updated',
            'success_heading' => 'Password Updated',
            'success_message' => "Your password for the {$listingName} listing has been updated. "
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
        int $listingId,
        Listing $listing,
        string $oldEmail = '',
        string $error = '',
        string $captchaError = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('reset.php', [
            'listing'      => $listing,
            'listing_name' => $listing->name,
            'listing_url'  => $listing->url,
            'page_title'      => 'Reset Password',
            'form_action'     => $baseUrl . '/reset/' . $listingId,
            'csrf_token'      => $this->app->getCSRF()->token(),
            'errors'          => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'field_email'     => $oldEmail,
            'captcha_question' => Captcha::getQuestion('reset'),
            'captcha_error'   => $captchaError,
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    private function renderConfirmForm(
        int $listingId,
        Listing $listing,
        string $rawToken,
        string $error = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('reset_confirm.php', [
            'listing'      => $listing,
            'listing_name' => $listing->name,
            'listing_url'  => $listing->url,
            'page_title'      => 'Set New Password',
            'form_action'     => $baseUrl . '/reset/' . $listingId . '/confirm',
            'reset_url'       => $baseUrl . '/reset/' . $listingId,
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
