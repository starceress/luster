<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public controller for KIM password resets. A two-step flow: the fan enters
 their email to receive a time-limited reset link (1 hour TTL), then follows
 the link to set a new password. The response is always the same regardless
 of whether the email exists, to prevent account enumeration.
*/

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Addons\Kim\KimPasswordResetRepo;
use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\SuccessRenderer;

/**
 * KIM password reset flow.
 *
 * POST /kim/reset          — sends reset email, redirects to host page (no enumeration)
 * POST /kim/reset/confirm  — applies new password, consumes token, redirects to host page
 */
final class KimResetController
{
    private const TOKEN_TTL_SECONDS = 3600; // 1 hour

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // POST /kim/reset
    // -----------------------------------------------------------------------

    /**
     * Processes the email-entry step. Issues time-limited reset tokens for each
     * approved KIM entry found for the submitted email, then sends a single email
     * containing all reset links. Always redirects with the same neutral flash
     * message to prevent account enumeration.
     */
    public function submit(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $siteUrl       = (string) $this->app->getConfig()->get('site_url', '');
        $siteUrlOrBase = $siteUrl !== '' ? $siteUrl : $baseUrl;
        $fallbackUrl   = CollectiveFormUrlResolver::resolve(
            (new SiteSettingsRepo($this->app->getDatabase()))->get('kim_url_reset') ?: 'kim-reset.php',
            $siteUrlOrBase,
            $siteUrlOrBase,
        );

        // Never reveal blocked status
        if ($rateLimit->isBlocked($ip, 'kim_reset')) {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html:kim_reset', SuccessRenderer::render('', 'reset-sent'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; same neutral message so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html:kim_reset', SuccessRenderer::render('', 'reset-sent'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $email = trim((string) $request->post('email', ''));

        $v = new Validator(['email' => $email]);
        $v->field('email')->required()->email()->maxLength(255);

        $rateLimit->hit($ip, 'kim_reset', 5, 3600);

        if (!$v->fails()) {
            $db        = $this->app->getDatabase();
            $config    = $this->app->getConfig();
            $kimRepo   = new KimMemberRepo($db);
            $resetRepo = new KimPasswordResetRepo($db);
            $members   = $kimRepo->findAllByEmail($email);
            $siteName  = (string) $config->get('site_name', '');

            $listingRepo = new \Luster\Repositories\ListingRepo($db, $config);
            $siteUrl     = (string) $config->get('site_url', '');
            $kimResetPage = $embedReturnTo !== ''
                ? $embedReturnTo
                : CollectiveFormUrlResolver::resolve(
                    (new SiteSettingsRepo($db))->get('kim_url_reset') ?: 'kim-reset.php',
                    $siteUrlOrBase,
                    $siteUrlOrBase,
                );

            $resetLinks  = [];
            $recipientName = '';

            foreach ($members as $member) {
                if ($member->pending) {
                    continue;
                }

                $resetRepo->deleteByMember($member->id);

                $rawToken  = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = (new \DateTimeImmutable())
                    ->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds')
                    ->format('Y-m-d H:i:s');

                $resetRepo->create($member->id, $tokenHash, $expiresAt);

                $sep      = str_contains($kimResetPage, '?') ? '&' : '?';
                $resetUrl = $kimResetPage . $sep . 'token=' . urlencode($rawToken);

                $listing     = $listingRepo->getById($member->fanlistingId);
                $listingName = $listing !== null ? ($listing->subject ?: $listing->title) : 'Listing #' . $member->fanlistingId;

                $resetLinks[] = ['listing' => $listingName, 'url' => $resetUrl];
                if ($recipientName === '') {
                    $recipientName = $member->name;
                }
            }

            if ($resetLinks !== []) {
                try {
                    $ownerName  = (string) $config->get('owner_name',  '');
                    $ownerEmail = (string) $config->get('owner_email', '');

                    // Single entry: bare reset URL. Multiple entries: numbered list.
                    if (count($resetLinks) === 1) {
                        $resetUrlText = $resetLinks[0]['url'];
                    } else {
                        $lines = [];
                        foreach ($resetLinks as $i => $link) {
                            $lines[] = ($i + 1) . '. ' . $link['listing'] . "\n   " . $link['url'];
                        }
                        $resetUrlText = implode("\n\n", $lines);
                    }

                    $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
                    $tokens    = EmailTemplateRepo::buildKimTokens(
                        listingName:  $siteName,
                        listingUrl:   (string) $this->app->getConfig()->get('site_url', ''),
                        siteName:     $siteName,
                        ownerName:    $ownerName,
                        ownerEmail:   $ownerEmail,
                        kimName:      $recipientName,
                        kimEmail:     $email,
                        resetUrl:     $resetUrlText,
                        kimUpdateUrl: \Luster\Support\CollectiveFormUrlResolver::resolve(
                            (new \Luster\Repositories\SiteSettingsRepo($this->app->getDatabase()))->get('kim_url_update') ?: 'kim-update.php',
                            ((string) $this->app->getConfig()->get('site_url', '')) ?: (string) $this->app->getConfig()->get('app_url', ''),
                            ((string) $this->app->getConfig()->get('site_url', '')) ?: (string) $this->app->getConfig()->get('app_url', ''),
                        ),
                    );
                    [$subject, $body] = $emailRepo->resolveForListing(null, EmailTemplateRepo::TYPE_KIM_RESET, $tokens);

                    if ($subject !== '') {
                        $mailer = new Mailer($config);
                        $mailer->send($email, $recipientName, $subject, $body);
                    }
                } catch (\Throwable $e) {
                    error_log('[KIM] Password reset email failed: ' . $e->getMessage());
                }
            }
        }

        // Always flash the same neutral message — never enumerate
        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success_html:kim_reset', SuccessRenderer::render('', 'reset-sent'));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // POST /kim/reset/confirm
    // -----------------------------------------------------------------------

    /**
     * Validates the token and new password, then applies the password change
     * and consumes the reset token so it cannot be reused.
     */
    public function submitConfirm(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');

        $siteUrl       = (string) $this->app->getConfig()->get('site_url', '');
        $siteUrlOrBase = $siteUrl !== '' ? $siteUrl : $baseUrl;
        $resetPageUrl  = CollectiveFormUrlResolver::resolve(
            (new SiteSettingsRepo($this->app->getDatabase()))->get('kim_url_reset') ?: 'kim-reset.php',
            $siteUrlOrBase,
            $siteUrlOrBase,
        );
        $fallbackUrl   = $embedReturnTo !== '' ? $embedReturnTo : $resetPageUrl;

        $rawToken = trim((string) $request->post('token', ''));

        $confirmReturnUrl = $fallbackUrl;
        if ($rawToken !== '') {
            $confirmReturnUrl .= (str_contains($confirmReturnUrl, '?') ? '&' : '?') . 'token=' . urlencode($rawToken);
        }

        if ($rateLimit->isBlocked($ip, 'kim_reset')) {
            $session->set('lstr_flash_error:kim_reset', 'Too many attempts. Please try again later.');
            return Response::redirect($confirmReturnUrl);
        }

        if ($rawToken === '') {
            $session->set('lstr_flash_error:kim_reset', 'Missing token. Please use the link from your email.');
            return Response::redirect($fallbackUrl);
        }

        $resetRepo = new KimPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken(hash('sha256', $rawToken));

        if ($tokenRow === null) {
            $rateLimit->hit($ip, 'kim_reset', 5, 3600);
            $session->set('lstr_flash_error:kim_reset', 'This reset link has expired or is invalid. Please request a new one.');
            return Response::redirect($fallbackUrl);
        }

        $newPassword        = (string) $request->post('new_password',         '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');

        $v = new Validator(['new_password' => $newPassword]);
        PasswordPolicy::apply($v->field('new_password'));

        if ($v->fails()) {
            $session->set('lstr_flash_error:kim_reset', implode(' ', $v->allErrors()));
            return Response::redirect($confirmReturnUrl);
        }

        if ($newPassword !== $newPasswordConfirm) {
            $session->set('lstr_flash_error:kim_reset', 'Passwords do not match. Please try again.');
            return Response::redirect($confirmReturnUrl);
        }

        // Atomically claim the token right before applying the password
        // change - closes the race where two concurrent requests with the
        // same raw token both pass the findValidByToken() check above before
        // either has consumed it.
        $tokenRow = $resetRepo->verifyAndConsume(hash('sha256', $rawToken));
        if ($tokenRow === null) {
            $session->set('lstr_flash_error:kim_reset', 'This reset link has expired or is invalid. Please request a new one.');
            return Response::redirect($fallbackUrl);
        }

        $kimMemberId = (int) $tokenRow['kim_member_id'];
        $kimRepo     = new KimMemberRepo($this->app->getDatabase());

        $kimRepo->update($kimMemberId, [
            'password' => password_hash($newPassword, PASSWORD_ARGON2ID),
        ]);

        $rateLimit->clear($ip, 'kim_reset');

        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success_html:kim_reset', SuccessRenderer::render('', 'kim-reset-done'));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

}
