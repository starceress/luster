<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles the fan password reset flow. The first step collects the fan's email
 and sends a reset link containing a one-time token (256 bits of entropy,
 SHA-256 hashed in the database, 1-hour expiry). The second step validates
 the token, accepts a new password, applies the password policy, and hashes
 with Argon2id. The response is always a neutral message regardless of whether
 the email was found, preventing email enumeration.
*/

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\MemberRepo;
use Luster\Support\ListingFormUrlResolver;
use Luster\Support\MemberAddressBusy;
use Luster\Support\SuccessRenderer;

/**
 * Fan password reset — allows fans to recover access when they forget their password.
 *
 * POST /reset/{id}           — sends reset email, redirects to host page (prevents enumeration)
 * POST /reset/{id}/confirm   — applies new password, consumes token, redirects to host page
 *
 * Security properties:
 *  - CSRF token on all POST forms (validated by CsrfMiddleware)
 *  - Honeypot on the email entry form
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
    // POST /reset/{id}
    // -----------------------------------------------------------------------

    /**
     * Processes the email-entry form: issues a one-time reset token and sends the
     * reset email. Always redirects with the same neutral success message whether
     * the address exists or not, preventing email enumeration.
     *
     * Blacklisted addresses silently receive no email but see the same message.
     * Only approved (non-pending) members can receive a reset link.
     */
    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $marker        = (string) $request->post('_luster_embed_form', '');

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings   = $settingsRepo->getResolvedEmbedSettings($listingId);
        $globalDefaults  = $settingsRepo->getGlobalFormUrlDefaults();
        $formUrl         = ListingFormUrlResolver::resolve(
            (string) $embedSettings['form_url_reset'],
            $listing->url,
            $globalDefaults['form_url_reset'],
        );

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_RESET)) {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render('', 'reset-sent'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render('', 'reset-sent'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $email = trim((string) $request->post('email', ''));

        $v = new Validator(['email' => $email]);
        $v->field('email')->required()->email()->maxLength(255);

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_RESET);
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old_email:' . $marker, $email);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_RESET);

        // Blacklist check — silently skip sending if this address is blocked.
        // Always redirect with the same neutral message so the block is not revealed.
        if ((new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $session->remove('lstr_embed_return_to');
            $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, 'reset-sent'));
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

            $sep      = str_contains($formUrl, '?') ? '&' : '?';
            $resetUrl = $formUrl . $sep . 'token=' . urlencode($rawToken);

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
                    listingEmail:   $listing->email,
                    formUrlJoin:    (string) ($embedSettings['form_url_join']   ?: $globalDefaults['form_url_join']),
                    formUrlUpdate:  (string) ($embedSettings['form_url_update'] ?: $globalDefaults['form_url_update']),
                    formUrlReset:   (string) ($embedSettings['form_url_reset']  ?: $globalDefaults['form_url_reset']),
                    listingType:    $settingsRepo->getListingType($listingId),
                );
                [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_RESET, $tokens);
                if ($subject !== '') {
                    $mailer->send($email, $member->name, $subject, $body);
                }
            } catch (\Throwable $e) {
                error_log('[Luster] Fan password reset email failed: ' . $e->getMessage());
            }
        }

        // Always flash the same neutral message and redirect to host page — never reveal whether the email was found
        $session->remove('lstr_embed_return_to');
        $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
        $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, 'reset-sent'));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
    }

    // -----------------------------------------------------------------------
    // POST /reset/{id}/confirm
    // -----------------------------------------------------------------------

    /**
     * Processes the new-password form: validates the token, enforces the password
     * policy, updates the member's password hash (Argon2id), and consumes the
     * one-time token so it cannot be reused.
     */
    public function submitConfirm(Request $request, array $params): Response
    {
        $listingId    = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $marker        = (string) $request->post('_luster_embed_form', '');

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings   = $settingsRepo->getResolvedEmbedSettings($listingId);
        $globalDefaults  = $settingsRepo->getGlobalFormUrlDefaults();
        $resetPageUrl    = ListingFormUrlResolver::resolve(
            (string) $embedSettings['form_url_reset'],
            $listing->url,
            $globalDefaults['form_url_reset'],
        );

        $rawToken = trim((string) $request->post('token', ''));

        $confirmReturnUrl = $embedReturnTo !== '' ? $embedReturnTo : $resetPageUrl;
        if ($rawToken !== '') {
            $confirmReturnUrl .= (str_contains($confirmReturnUrl, '?') ? '&' : '?') . 'token=' . urlencode($rawToken);
        }

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_RESET)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many attempts. Please try again later.');
            return Response::redirect($confirmReturnUrl);
        }

        if ($rawToken === '') {
            $session->set('lstr_flash_error:' . $marker, 'Missing reset token. Please use the link from your email.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $resetPageUrl);
        }

        $resetRepo = new FanPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken(hash('sha256', $rawToken));

        if ($tokenRow === null || (int) $tokenRow['fanlisting_id'] !== $listingId) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_RESET);
            $session->set('lstr_flash_error:' . $marker, 'This reset link has expired or is invalid. Please request a new one.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $resetPageUrl);
        }

        $newPassword        = (string) $request->post('new_password',        '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');

        $v = new Validator(['new_password' => $newPassword]);
        PasswordPolicy::apply($v->field('new_password'));

        if ($v->fails()) {
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            return Response::redirect($confirmReturnUrl);
        }

        if ($newPassword !== $newPasswordConfirm) {
            $session->set('lstr_flash_error:' . $marker, 'Passwords do not match. Please try again.');
            return Response::redirect($confirmReturnUrl);
        }

        // Under the address's lock: a fresh look at the member, then the token is used
        // and the password written. If the address is busy, the token and the password
        // stay as they were.
        $tokenHash = hash('sha256', $rawToken);
        $email     = (string) $tokenRow['email'];
        try {
            $applied = $this->app->getMemberAddressLock()->run(
                [[$listingId, $email]],
                function () use ($resetRepo, $tokenHash, $listing, $email, $newPassword): bool {
                    // Confirm the member the token was issued to still exists under that
                    // email before touching anything — PDO's execute() returns true even
                    // when an UPDATE matches zero rows, so without this check a token
                    // pointing at a since-renamed or deleted member would silently do
                    // nothing yet still report success and consume the token.
                    $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
                    if ($memberRepo->getByEmail($listing->dbprefix, $email) === null) {
                        $resetRepo->deleteByToken($tokenHash);
                        return false;
                    }

                    // Atomically claim the token right before applying the password
                    // change - closes the race where two concurrent requests with the
                    // same raw token both pass the findValidByToken() check above before
                    // either has consumed it.
                    if ($resetRepo->verifyAndConsume($tokenHash) === null) {
                        return false;
                    }

                    // Update the member's password
                    $memberRepo->update(
                        $listing->dbprefix,
                        $email,
                        ['password' => password_hash($newPassword, PASSWORD_ARGON2ID)],
                    );

                    return true;
                },
            );
        } catch (MemberAddressBusy) {
            $session->set('lstr_flash_error:' . $marker, MemberAddressBusy::MESSAGE);
            return Response::redirect($confirmReturnUrl);
        }

        if (!$applied) {
            $session->set('lstr_flash_error:' . $marker, 'This reset link has expired or is invalid. Please request a new one.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $resetPageUrl);
        }

        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_RESET);

        $session->remove('lstr_embed_return_to');
        $successTpl = (new ListingSettingsRepo($this->app->getDatabase()))->getSuccessTemplate($listingId) ?? '';
        $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, 'reset-done', [
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
        ]));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $resetPageUrl);
    }

}
