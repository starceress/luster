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
 * Public KIM update form.
 *
 * GET  /kim/update         — update form
 * POST /kim/update         — authenticates and updates the KIM entry
 * GET  /kim/update/success — confirmation page
 */
final class KimUpdateController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /kim/update
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
    // POST /kim/update
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $ip        = $request->ip();

        // Rate limit check
        if ($rateLimit->isBlocked($ip, 'kim_update')) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($baseUrl . '/kim/update');
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/kim/update/success');
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlistings = $listingRepo->getAll();

        // Captcha validate — re-render on failure
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'kim_update')) {
            $old = [
                'name'          => trim((string) $request->post('name',           '')),
                'current_email' => trim((string) $request->post('current_email',  '')),
                'fanlisting_id' => (string) $request->post('fanlisting_id',        ''),
                'new_email'     => trim((string) $request->post('new_email',       '')),
                'new_url'       => trim((string) $request->post('new_url',         '')),
                'send_copy'     => (string) $request->post('send_copy',            ''),
            ];
            return $this->renderForm($fanlistings, $old, '', 'Incorrect answer — please try again.');
        }

        $name        = trim((string) $request->post('name',           ''));
        $currentEmail = trim((string) $request->post('current_email', ''));
        $currentPw    = (string) $request->post('current_password',   '');
        $fanlistingId = (int) $request->post('fanlisting_id',          0);

        $fanlistingIds = array_map(static fn($fl) => $fl->id, $fanlistings);

        $v = new Validator([
            'name'          => $name,
            'current_email' => $currentEmail,
            'current_password' => $currentPw,
            'fanlisting_id' => (string) $fanlistingId,
        ]);
        $v->field('name')->required()->maxLength(100);
        $v->field('current_email')->required()->email()->maxLength(255);
        $v->field('current_password')->required();
        $v->field('fanlisting_id')->required();

        $validationFailed = $v->fails();
        if (!$validationFailed && ($fanlistingId <= 0 || !in_array($fanlistingId, $fanlistingIds, true))) {
            $validationFailed = true;
        }

        if ($validationFailed) {
            $rateLimit->hit($ip, 'kim_update', 5, 3600);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()) ?: 'Please select a valid fanlisting.');
            $session->set('lstr_flash_old', [
                'name'          => $name,
                'current_email' => $currentEmail,
                'fanlisting_id' => (string) $fanlistingId,
                'new_email'     => trim((string) $request->post('new_email', '')),
                'new_url'       => trim((string) $request->post('new_url',   '')),
                'send_copy'     => (string) $request->post('send_copy',      ''),
            ]);
            return Response::redirect($baseUrl . '/kim/update');
        }

        $kimRepo = new KimMemberRepo($this->app->getDatabase());
        $member  = $kimRepo->findByEmailAndFanlisting($currentEmail, $fanlistingId);

        // No enumeration — always same generic error
        if ($member === null) {
            $rateLimit->hit($ip, 'kim_update', 5, 3600);
            $session->set('lstr_flash_error', 'No matching entry found.');
            $session->set('lstr_flash_old', [
                'name'          => $name,
                'current_email' => $currentEmail,
                'fanlisting_id' => (string) $fanlistingId,
                'new_email'     => trim((string) $request->post('new_email', '')),
                'new_url'       => trim((string) $request->post('new_url',   '')),
                'send_copy'     => (string) $request->post('send_copy',      ''),
            ]);
            return Response::redirect($baseUrl . '/kim/update');
        }

        // Password verify
        if (!password_verify($currentPw, $member->password)) {
            $rateLimit->hit($ip, 'kim_update', 5, 3600);
            $session->set('lstr_flash_error', 'Incorrect password.');
            $session->set('lstr_flash_old', [
                'name'          => $name,
                'current_email' => $currentEmail,
                'fanlisting_id' => (string) $fanlistingId,
                'new_email'     => trim((string) $request->post('new_email', '')),
                'new_url'       => trim((string) $request->post('new_url',   '')),
                'send_copy'     => (string) $request->post('send_copy',      ''),
            ]);
            return Response::redirect($baseUrl . '/kim/update');
        }

        // Build update fields
        $updateFields = ['name' => $name];
        $updatedFields = ['name'];

        $newEmail = trim((string) $request->post('new_email', ''));
        if ($newEmail !== '' && $newEmail !== $currentEmail) {
            $vEmail = new Validator(['new_email' => $newEmail]);
            $vEmail->field('new_email')->email()->maxLength(255);
            if ($vEmail->fails()) {
                $rateLimit->hit($ip, 'kim_update', 5, 3600);
                $session->set('lstr_flash_error', implode(' ', $vEmail->allErrors()));
                $session->set('lstr_flash_old', [
                    'name'          => $name,
                    'current_email' => $currentEmail,
                    'fanlisting_id' => (string) $fanlistingId,
                    'new_email'     => $newEmail,
                    'new_url'       => trim((string) $request->post('new_url', '')),
                    'send_copy'     => (string) $request->post('send_copy',    ''),
                ]);
                return Response::redirect($baseUrl . '/kim/update');
            }
            // Check not taken by another entry for same fanlisting
            if ($kimRepo->findByEmailAndFanlisting($newEmail, $fanlistingId) !== null) {
                $session->set('lstr_flash_error', 'That email is already registered for this fanlisting.');
                $session->set('lstr_flash_old', [
                    'name'          => $name,
                    'current_email' => $currentEmail,
                    'fanlisting_id' => (string) $fanlistingId,
                    'new_email'     => $newEmail,
                    'new_url'       => trim((string) $request->post('new_url', '')),
                    'send_copy'     => (string) $request->post('send_copy',    ''),
                ]);
                return Response::redirect($baseUrl . '/kim/update');
            }
            $updateFields['email'] = $newEmail;
            $updatedFields[] = 'email';
        }

        $newUrl = trim((string) $request->post('new_url', ''));
        if ($newUrl !== '') {
            $updateFields['url'] = $newUrl;
            $updatedFields[] = 'url';
        }

        $newPassword        = (string) $request->post('new_password',         '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');

        if ($newPassword !== '') {
            if ($newPassword !== $newPasswordConfirm) {
                $session->set('lstr_flash_error', 'New passwords do not match.');
                $session->set('lstr_flash_old', [
                    'name'          => $name,
                    'current_email' => $currentEmail,
                    'fanlisting_id' => (string) $fanlistingId,
                    'new_email'     => $newEmail,
                    'new_url'       => $newUrl,
                    'send_copy'     => (string) $request->post('send_copy', ''),
                ]);
                return Response::redirect($baseUrl . '/kim/update');
            }
$updateFields['password'] = password_hash($newPassword, PASSWORD_ARGON2ID);
            $updatedFields[] = 'password';
        }

        $kimRepo->update($member->id, $updateFields);
        $rateLimit->clear($ip, 'kim_update');

        $sendCopy = ((string) $request->post('send_copy', '') === '1');
        if ($sendCopy) {
            try {
                $mailer  = new Mailer($this->app->getConfig());
                $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');
                $subject = "KIM List Updated — {$siteName}";
                $body    = "Hi {$name},\n\n"
                         . "Your KIM List entry has been updated.\n"
                         . "Updated fields: " . implode(', ', $updatedFields) . "\n\n"
                         . "-- {$siteName}";
                $mailer->send($newEmail !== '' ? $newEmail : $currentEmail, $name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[KIM] Update confirmation email failed: ' . $e->getMessage());
            }
        }

        return Response::redirect($baseUrl . '/kim/update/success');
    }

    // -----------------------------------------------------------------------
    // GET /kim/update/success
    // -----------------------------------------------------------------------

    public function success(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();

        return $this->renderKimPage('success.php', [
            'heading'    => 'Entry Updated',
            'message'    => 'Your KIM List entry has been updated successfully.',
            'page_title' => 'KIM — Entry Updated',
            'base_url'   => $baseUrl,
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

        return $this->renderKimPage('update.php', [
            'page_title'      => 'KIM List — Update Entry',
            'form_action'     => $baseUrl . '/kim/update',
            'csrf_token'      => $this->app->getCSRF()->token(),
            'errors'          => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'fanlistings'     => $fanlistings,
            'old'             => $old,
            'captcha_question' => Captcha::getQuestion('kim_update'),
            'captcha_error'   => $captchaError,
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
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
