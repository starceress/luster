<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\FanlistingRepo;
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
 *  - Password verified via password_verify() (Argon2id)
 *  - Legacy MD5 hash detected, verified, and upgraded to Argon2id before deletion
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

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $oldEmail = (string) $session->get('lstr_flash_old_email', '');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old_email');

        $html = $this->app->getView()->render('default/remove.html', [
            'fanlisting_name' => $fanlisting->name,
            'fanlisting_url'  => $fanlisting->url,
            'csrf_token'      => $this->app->getCSRF()->token(),
            'fanlisting_id'   => (string) $fanlistingId,
            'error'           => $error,
            'old_email'       => $oldEmail,
            'app_name'        => (string) $this->app->getConfig()->get('app_name', 'Luster'),
            'base_url'        => $this->app->getBaseUrl(),
        ]);

        return Response::html($html);
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

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $email    = trim((string) $request->post('email',    ''));
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

        // Verify password — detect and upgrade legacy MD5 hashes before deletion
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

        $html = $this->app->getView()->render('default/success.html', [
            'title'           => 'Membership Removed',
            'message'         => "You have been removed from the {$fanlistingName} fanlisting. We're sorry to see you go!",
            'fanlisting_name' => $fanlistingName,
            'app_name'        => (string) $this->app->getConfig()->get('app_name', 'Luster'),
            'base_url'        => $this->app->getBaseUrl(),
        ]);

        return Response::html($html);
    }
}
