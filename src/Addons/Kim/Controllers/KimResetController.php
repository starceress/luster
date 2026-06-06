<?php

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Addons\Kim\KimPasswordResetRepo;
use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ListingRepo;

/**
 * KIM password reset flow.
 *
 * GET  /kim/reset          — email entry form
 * POST /kim/reset          — sends reset email, redirects to host page (no enumeration)
 * GET  /kim/reset/confirm  — new password form (validates token from ?token=)
 * POST /kim/reset/confirm  — applies new password, consumes token, redirects to host page
 */
final class KimResetController
{
    private const TOKEN_TTL_SECONDS = 3600; // 1 hour

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /kim/reset
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $session  = $this->app->getSession();
        $error    = (string) $session->get('lstr_flash_error',     '');
        $oldEmail = (string) $session->get('lstr_flash_old_email', '');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old_email');

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlistings = $listingRepo->getAll();

        return $this->renderResetForm($fanlistings, $oldEmail, $error);
    }

    // -----------------------------------------------------------------------
    // POST /kim/reset
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $fallbackUrl   = $baseUrl . '/kim/reset';

        $sentMessage = 'If that email address is registered for the selected fanlisting, you will receive a password reset link shortly. The link expires in 1 hour.';

        // Never reveal blocked status
        if ($rateLimit->isBlocked($ip, 'kim_reset')) {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success', $sentMessage);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; same neutral message so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success', $sentMessage);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlistings = $listingRepo->getAll();

        $email        = trim((string) $request->post('email',        ''));
        $fanlistingId = (int) $request->post('fanlisting_id',          0);

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'kim_reset')) {
            $rateLimit->hitDefault($ip, 'kim_reset');
            $session->set('lstr_flash_error', 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $fanlistingIds = array_map(static fn($fl) => $fl->id, $fanlistings);

        $v = new Validator(['email' => $email, 'fanlisting_id' => (string) $fanlistingId]);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('fanlisting_id')->required();

        $validationFailed = $v->fails();
        if (!$validationFailed && ($fanlistingId <= 0 || !in_array($fanlistingId, $fanlistingIds, true))) {
            $validationFailed = true;
        }

        $rateLimit->hit($ip, 'kim_reset', 5, 3600);

        if (!$validationFailed) {
            $kimRepo = new KimMemberRepo($this->app->getDatabase());
            $member  = $kimRepo->findByEmailAndFanlisting($email, $fanlistingId);

            if ($member !== null && !$member->pending) {
                $resetRepo = new KimPasswordResetRepo($this->app->getDatabase());
                $resetRepo->deleteByMember($member->id);

                $rawToken  = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = (new \DateTimeImmutable())
                    ->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds')
                    ->format('Y-m-d H:i:s');

                $resetRepo->create($member->id, $tokenHash, $expiresAt);

                $resetUrl = $baseUrl . '/kim/reset/confirm?token=' . urlencode($rawToken);
                $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');

                try {
                    $mailer  = new Mailer($this->app->getConfig());
                    $subject = "KIM List Password Reset — {$siteName}";
                    $body    = "Hi {$member->name},\n\n"
                             . "A password reset was requested for your KIM List account.\n\n"
                             . "Click the link below to set a new password:\n"
                             . "{$resetUrl}\n\n"
                             . "This link expires in 1 hour.\n\n"
                             . "If you did not request this, you can ignore this email.\n\n"
                             . "-- {$siteName}";
                    $mailer->send($email, $member->name, $subject, $body);
                } catch (\Throwable $e) {
                    error_log('[KIM] Password reset email failed: ' . $e->getMessage());
                }
            }
        }

        // Always flash the same neutral message — never enumerate
        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success', $sentMessage);
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // GET /kim/reset/confirm
    // -----------------------------------------------------------------------

    public function showConfirm(Request $request, array $params): Response
    {
        $rawToken = trim((string) $request->query('token', ''));

        if ($rawToken === '') {
            return $this->renderConfirmForm('', false, 'Missing or invalid reset link.');
        }

        $resetRepo = new KimPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken(hash('sha256', $rawToken));

        if ($tokenRow === null) {
            return $this->renderConfirmForm(
                '',
                false,
                'This reset link has expired or is invalid. Please request a new one.',
            );
        }

        return $this->renderConfirmForm($rawToken, true);
    }

    // -----------------------------------------------------------------------
    // POST /kim/reset/confirm
    // -----------------------------------------------------------------------

    public function submitConfirm(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $fallbackUrl   = $baseUrl . '/kim/reset';

        if ($rateLimit->isBlocked($ip, 'kim_reset')) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $rawToken = trim((string) $request->post('token', ''));

        if ($rawToken === '') {
            return $this->renderConfirmForm(
                '',
                false,
                'Missing token. Please use the link from your email.',
            );
        }

        $resetRepo = new KimPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken(hash('sha256', $rawToken));

        if ($tokenRow === null) {
            $rateLimit->hit($ip, 'kim_reset', 5, 3600);
            return $this->renderConfirmForm(
                '',
                false,
                'This reset link has expired or is invalid. Please request a new one.',
            );
        }

        $newPassword        = (string) $request->post('new_password',         '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');

        $v = new Validator(['new_password' => $newPassword]);
        PasswordPolicy::apply($v->field('new_password'));

        if ($v->fails()) {
            return $this->renderConfirmForm($rawToken, true, implode(' ', $v->allErrors()));
        }

        if ($newPassword !== $newPasswordConfirm) {
            return $this->renderConfirmForm($rawToken, true, 'Passwords do not match. Please try again.');
        }

        $kimMemberId = (int) $tokenRow['kim_member_id'];
        $kimRepo     = new KimMemberRepo($this->app->getDatabase());

        $kimRepo->update($kimMemberId, [
            'password' => password_hash($newPassword, PASSWORD_ARGON2ID),
        ]);

        $resetRepo->deleteByToken(hash('sha256', $rawToken));
        $rateLimit->clear($ip, 'kim_reset');

        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success', 'Your KIM List password has been updated successfully.');
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function renderResetForm(
        array  $fanlistings,
        string $oldEmail = '',
        string $error = '',
        string $captchaError = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        return $this->renderKimPage('reset.php', [
            'page_title'      => 'KIM List — Reset Password',
            'form_action'     => $baseUrl . '/kim/reset',
            'csrf_token'      => $this->app->getCSRF()->token(),
            'errors'          => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'fanlistings'     => $fanlistings,
            'old_email'       => $oldEmail,
            'captcha_question' => Captcha::getQuestion('kim_reset'),
            'captcha_error'   => $captchaError,
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);
    }

    private function renderConfirmForm(
        string $rawToken,
        bool   $tokenValid,
        string $error = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        return $this->renderKimPage('reset-confirm.php', [
            'page_title'  => 'KIM — Set New Password',
            'form_action' => $baseUrl . '/kim/reset/confirm',
            'csrf_token'  => $this->app->getCSRF()->token(),
            'errors'      => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'token'       => $rawToken,
            'token_valid' => $tokenValid,
            'base_url'    => $baseUrl,
            'theme_path'  => $baseUrl . '/assets/default/',
        ]);
    }

    private function renderKimPage(string $template, array $data): Response
    {
        $view    = $this->app->getView();
        $baseUrl = $this->app->getBaseUrl();
        $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');

        $content = $view->render('default/addons/kim/' . $template, $data);

        $layoutData = array_merge($data, [
            'content'      => $content,
            'listing'      => null,
            'listing_name' => $siteName,
            'listing_url'  => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/default/',
        ]);

        $layoutPath = $this->app->getBasePath() . '/templates/default/layout.php';
        if (is_file($layoutPath)) {
            $html = $view->render('default/layout.php', $layoutData);
        } else {
            $html = $content;
        }

        return Response::html($html);
    }
}
