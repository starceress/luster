<?php

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ListingRepo;

/**
 * Public KIM sign-up form.
 *
 * GET  /kim         — renders the sign-up form
 * POST /kim         — validates, inserts, sends confirmation, redirects
 * GET  /kim/success — confirmation page
 */
final class KimSignupController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /kim
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlistings = $listingRepo->getAll();

        return $this->renderForm($fanlistings, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /kim
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $ip        = $request->ip();

        // Rate limit check
        if ($rateLimit->isBlocked($ip, 'kim_signup')) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($baseUrl . '/kim');
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/kim/success');
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlistings = $listingRepo->getAll();

        // Captcha validate — re-render on failure (no redirect, fresh question shown)
        $captchaOk = Captcha::validate((string) $request->post('captcha_answer', ''), 'kim_signup');
        if (!$captchaOk) {
            $old = [
                'name'         => trim((string) $request->post('name',         '')),
                'email'        => trim((string) $request->post('email',        '')),
                'url'          => trim((string) $request->post('url',          '')),
                'fanlisting_id' => (string) $request->post('fanlisting_id',    ''),
                'send_copy'    => (string) $request->post('send_copy',         ''),
            ];
            return $this->renderForm($fanlistings, $old, '', 'Incorrect answer — please try again.');
        }

        $name        = trim((string) $request->post('name',         ''));
        $email       = trim((string) $request->post('email',        ''));
        $url         = trim((string) $request->post('url',          ''));
        $fanlistingId = (int) $request->post('fanlisting_id',       0);

        // Validate fanlisting exists in our list
        $fanlistingIds = array_map(static fn($fl) => $fl->id, $fanlistings);

        $v = new Validator([
            'name'         => $name,
            'email'        => $email,
            'url'          => $url,
            'fanlisting_id' => (string) $fanlistingId,
        ]);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->required()->url()->maxLength(500);
        $v->field('fanlisting_id')->required();

        $validationFailed = $v->fails();
        if (!$validationFailed && ($fanlistingId <= 0 || !in_array($fanlistingId, $fanlistingIds, true))) {
            $validationFailed = true;
        }

        if ($validationFailed) {
            $rateLimit->hit($ip, 'kim_signup', 5, 3600);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()) ?: 'Please select a valid fanlisting.');
            $session->set('lstr_flash_old', [
                'name'         => $name,
                'email'        => $email,
                'url'          => $url,
                'fanlisting_id' => (string) $fanlistingId,
                'send_copy'    => (string) $request->post('send_copy', ''),
            ]);
            return Response::redirect($baseUrl . '/kim');
        }

        $kimRepo = new KimMemberRepo($this->app->getDatabase());

        // Duplicate check
        if ($kimRepo->findByEmailAndFanlisting($email, $fanlistingId) !== null) {
            $session->set('lstr_flash_error', 'That email is already signed up for this fanlisting.');
            $session->set('lstr_flash_old', [
                'name'         => $name,
                'email'        => $email,
                'url'          => $url,
                'fanlisting_id' => (string) $fanlistingId,
                'send_copy'    => (string) $request->post('send_copy', ''),
            ]);
            return Response::redirect($baseUrl . '/kim');
        }

        // Password — auto-generate if not supplied
        $rawPassword     = (string) $request->post('password',         '');
        $passwordConfirm = (string) $request->post('password_confirm', '');

        if ($rawPassword !== '' && $rawPassword !== $passwordConfirm) {
            $session->set('lstr_flash_error', 'Passwords do not match. Please try again.');
            $session->set('lstr_flash_old', [
                'name'         => $name,
                'email'        => $email,
                'url'          => $url,
                'fanlisting_id' => (string) $fanlistingId,
                'send_copy'    => (string) $request->post('send_copy', ''),
            ]);
            return Response::redirect($baseUrl . '/kim');
        }

        if ($rawPassword === '') {
            $rawPassword  = bin2hex(random_bytes(8));
            $wasGenerated = true;
        } else {
            $wasGenerated = false;
        }

        $passwordHash = password_hash($rawPassword, PASSWORD_ARGON2ID);

        $kimRepo->create($fanlistingId, $name, $email, $url, $passwordHash);
        $rateLimit->clear($ip, 'kim_signup');

        // Find the fanlisting subject for the email
        $selectedListing = null;
        foreach ($fanlistings as $fl) {
            if ($fl->id === $fanlistingId) {
                $selectedListing = $fl;
                break;
            }
        }

        $sendCopy = ((string) $request->post('send_copy', '') === '1');

        if ($sendCopy || $wasGenerated) {
            try {
                $mailer  = new Mailer($this->app->getConfig());
                $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');
                $subject = "KIM List Sign-Up — {$siteName}";
                $body    = "Hi {$name},\n\n"
                         . "You have signed up for the KIM List for:\n"
                         . "  Fanlisting: " . ($selectedListing?->subject ?? "ID {$fanlistingId}") . "\n"
                         . "  Your name:  {$name}\n"
                         . "  Your email: {$email}\n"
                         . "  Your URL:   {$url}\n"
                         . "  Password:   {$rawPassword}\n\n"
                         . "Your entry is currently pending approval.\n\n"
                         . "-- {$siteName}";
                $mailer->send($email, $name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[KIM] Sign-up confirmation email failed: ' . $e->getMessage());
            }
        }

        return Response::redirect($baseUrl . '/kim/success');
    }

    // -----------------------------------------------------------------------
    // GET /kim/success
    // -----------------------------------------------------------------------

    public function success(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();

        return $this->renderKimPage('success.php', [
            'heading'   => 'Sign-Up Received',
            'message'   => 'Thank you for signing up! Your entry is pending approval.',
            'page_title' => 'KIM — Sign-Up Received',
            'base_url'  => $baseUrl,
            'theme_path' => $baseUrl . '/assets/default/',
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function renderForm(
        array  $fanlistings,
        array  $old = [],
        string $error = '',
        string $captchaError = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        return $this->renderKimPage('signup.php', [
            'page_title'      => 'KIM List — Sign Up',
            'form_action'     => $baseUrl . '/kim',
            'csrf_token'      => $this->app->getCSRF()->token(),
            'errors'          => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'fanlistings'     => $fanlistings,
            'old'             => $old,
            'captcha_question' => Captcha::getQuestion('kim_signup'),
            'captcha_error'   => $captchaError,
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);
    }

    private function renderKimPage(string $template, array $data): Response
    {
        $view     = $this->app->getView();
        $baseUrl  = $this->app->getBaseUrl();
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
