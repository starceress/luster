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
use Luster\Repositories\MemberRepo;

/**
 * Fan removal form — lets a fan remove themselves from a fanlisting.
 *
 * GET  /remove/{id} — shows the confirmation/credential form.
 * POST /remove/{id} — verifies email + password, deletes the member row,
 *                     sends a farewell email, redirects to success page.
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware
 *  - Honeypot field silently rejects bot submissions
 *  - Arithmetic CAPTCHA prevents automated submissions
 *  - Password verified via password_verify() (Argon2id)
 *  - Legacy MD5 hash detected and verified before deletion
 */
final class RemoveController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /remove/{id}
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
        $error    = (string) $session->get('lstr_flash_error',    '');
        $oldEmail = (string) $session->get('lstr_flash_old_email', '');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old_email');

        return $this->renderRemoveForm($fanlistingId, $fanlisting, $oldEmail, $error);
    }

    // -----------------------------------------------------------------------
    // POST /remove/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();

        // Rate limiting — prevents credential stuffing and email enumeration
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_REMOVE)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($baseUrl . '/remove/' . $fanlistingId);
        }

        // Honeypot — silent reject; redirect to the success page as if it worked
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/remove/' . $fanlistingId . '/success');
        }

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        // Captcha — re-render the form with an error on failure
        $email = trim((string) $request->post('email', ''));
        if (!Captcha::validate((string) $request->post('captcha_answer', ''))) {
            return $this->renderRemoveForm(
                $fanlistingId, $fanlisting, $email,
                '', 'Incorrect answer — please try again.',
            );
        }

        $password = (string) $request->post('password', '');

        $v = new Validator(['email' => $email, 'password' => $password]);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('password')->required();

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_REMOVE);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($baseUrl . '/remove/' . $fanlistingId);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($fanlisting->dbprefix, $email);

        if ($member === null) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_REMOVE);
            $session->set('lstr_flash_error', 'No membership found for that email address.');
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($baseUrl . '/remove/' . $fanlistingId);
        }

        // Verify password — detect and verify legacy MD5 hashes before deletion
        $storedHash = $member->password;
        $verified   = false;

        if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
            if (hash_equals($storedHash, md5($password))) {
                $verified = true;
            }
        } else {
            $verified = password_verify($password, $storedHash);
        }

        if (!$verified) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_REMOVE);
            $session->set('lstr_flash_error', 'Incorrect password. Please try again.');
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($baseUrl . '/remove/' . $fanlistingId);
        }

        $memberName = $member->name;

        $memberRepo->deleteByEmail($fanlisting->dbprefix, $email);

        // Farewell email (best-effort)
        try {
            $mailer  = new Mailer($this->app->getConfig());
            $appName = (string) $this->app->getConfig()->get('app_name', 'Luster');

            $mailer->send(
                $email,
                $memberName,
                "You have been removed from the {$fanlisting->name} fanlisting",
                "Hi {$memberName},\n\n"
                . "Your membership in the {$fanlisting->name} fanlisting has been removed as requested.\n\n"
                . "-- {$appName}",
            );
        } catch (\Throwable $e) {
            error_log('[Luster] Remove email failed: ' . $e->getMessage());
        }

        // Redirect to configured URL or default success page
        $settingsRepo = new FanlistingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($fanlistingId);
        $redirectUrl   = (string) $embedSettings['redirect_url_remove'];
        if ($redirectUrl !== '') {
            return Response::redirect($redirectUrl);
        }

        return Response::redirect($baseUrl . '/remove/' . $fanlistingId . '/success');
    }

    // -----------------------------------------------------------------------
    // GET /remove/{id}/success
    // -----------------------------------------------------------------------

    public function success(Request $request, array $params): Response
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
            'page_title'      => 'Membership Removed',
            'success_heading' => 'Membership Removed',
            'success_message' => "You have been removed from the {$fanlistingName} fanlisting. We're sorry to see you go!",
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Renders the remove form template with all required variables.
     *
     * Used by both showForm() (on GET) and the captcha-failure path in submit(),
     * so the form can be re-rendered in-place without a redirect.
     */
    private function renderRemoveForm(
        int $fanlistingId,
        Fanlisting $fanlisting,
        string $oldEmail = '',
        string $error = '',
        string $captchaError = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('remove.php', [
            'fanlisting'      => $fanlisting,
            'fanlisting_name' => $fanlisting->name,
            'fanlisting_url'  => $fanlisting->url,
            'page_title'      => 'Remove Membership',
            'form_action'     => $baseUrl . '/remove/' . $fanlistingId,
            'csrf_token'      => $this->app->getCSRF()->token(),
            'errors'          => $error !== '' ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '',
            'field_email'     => $oldEmail,
            'captcha_question' => Captcha::getQuestion(),
            'captcha_error'   => $captchaError,
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }
}
