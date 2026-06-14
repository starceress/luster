<?php

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormFieldBuilder;
use Luster\Support\SuccessRenderer;

/**
 * Public KIM update form.
 *
 * GET  /kim/update  â€” update form
 * POST /kim/update  â€” authenticates and updates the KIM entry, redirects to host page
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
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $fallbackUrl   = $baseUrl . '/kim/update';

        // Rate limit check
        if ($rateLimit->isBlocked($ip, 'kim_update')) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot â€” silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-updated'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlistings = $listingRepo->getAll();

        // Captcha â€” redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'kim_update')) {
            $rateLimit->hitDefault($ip, 'kim_update');
            $session->set('lstr_flash_error', 'Incorrect answer â€” please try again.');
            $session->set('lstr_flash_old', [
                'name'          => trim((string) $request->post('name',           '')),
                'current_email' => trim((string) $request->post('current_email',  '')),
                'fanlisting_id' => (string) $request->post('fanlisting_id',        ''),
                'new_email'     => trim((string) $request->post('new_email',       '')),
                'new_url'       => trim((string) $request->post('new_url',         '')),
                'send_copy'     => (string) $request->post('send_copy',            ''),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $name         = trim((string) $request->post('name',           ''));
        $currentEmail = trim((string) $request->post('current_email',  ''));
        $currentPw    = (string) $request->post('current_password',    '');
        $fanlistingId = (int) $request->post('fanlisting_id',           0);

        $fanlistingIds = array_map(static fn($fl) => $fl->id, $fanlistings);

        $v = new Validator([
            'name'             => $name,
            'current_email'    => $currentEmail,
            'current_password' => $currentPw,
            'fanlisting_id'    => (string) $fanlistingId,
        ]);
        $v->field('name')->required()->maxLength(100);
        $v->field('current_email')->required()->email()->maxLength(255);
        $v->field('current_password')->required();
        $v->field('fanlisting_id')->required();

        $validationFailed = $v->fails();
        if (!$validationFailed && ($fanlistingId <= 0 || !in_array($fanlistingId, $fanlistingIds, true))) {
            $validationFailed = true;
        }

        $oldFields = [
            'name'          => $name,
            'current_email' => $currentEmail,
            'fanlisting_id' => (string) $fanlistingId,
            'new_email'     => trim((string) $request->post('new_email', '')),
            'new_url'       => trim((string) $request->post('new_url',   '')),
            'send_copy'     => (string) $request->post('send_copy',      ''),
        ];

        if ($validationFailed) {
            $rateLimit->hit($ip, 'kim_update', 5, 3600);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()) ?: 'Please select a valid fanlisting.');
            $session->set('lstr_flash_old', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $kimRepo = new KimMemberRepo($this->app->getDatabase());
        $member  = $kimRepo->findByEmailAndFanlisting($currentEmail, $fanlistingId);

        if ($member === null) {
            $rateLimit->hit($ip, 'kim_update', 5, 3600);
            $session->set('lstr_flash_error', 'No matching entry found.');
            $session->set('lstr_flash_old', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if (!password_verify($currentPw, $member->password)) {
            $rateLimit->hit($ip, 'kim_update', 5, 3600);
            $session->set('lstr_flash_error', 'Incorrect password.');
            $session->set('lstr_flash_old', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Build update fields
        $updateFields  = ['name' => $name];
        $updatedFields = ['name'];

        $newEmail = trim((string) $request->post('new_email', ''));
        if ($newEmail !== '' && $newEmail !== $currentEmail) {
            $vEmail = new Validator(['new_email' => $newEmail]);
            $vEmail->field('new_email')->email()->maxLength(255);
            if ($vEmail->fails()) {
                $rateLimit->hit($ip, 'kim_update', 5, 3600);
                $session->set('lstr_flash_error', implode(' ', $vEmail->allErrors()));
                $session->set('lstr_flash_old', array_merge($oldFields, ['new_email' => $newEmail]));
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
            }
            if ($kimRepo->findByEmailAndFanlisting($newEmail, $fanlistingId) !== null) {
                $session->set('lstr_flash_error', 'That email is already registered for this fanlisting.');
                $session->set('lstr_flash_old', array_merge($oldFields, ['new_email' => $newEmail]));
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
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
            $passwordValidator = new Validator(['new_password' => $newPassword]);
            PasswordPolicy::apply($passwordValidator->field('new_password'));
            if ($passwordValidator->fails()) {
                $session->set('lstr_flash_error', implode(' ', $passwordValidator->allErrors()));
                $session->set('lstr_flash_old', $oldFields);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
            }
            if ($newPassword !== $newPasswordConfirm) {
                $session->set('lstr_flash_error', 'New passwords do not match.');
                $session->set('lstr_flash_old', $oldFields);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
            }
            $updateFields['password'] = password_hash($newPassword, PASSWORD_ARGON2ID);
            $updatedFields[] = 'password';
        }

        $kimRepo->update($member->id, $updateFields);
        $rateLimit->clear($ip, 'kim_update');

        $sendCopy = ((string) $request->post('send_copy', '') === '1');
        if ($sendCopy) {
            try {
                $mailer   = new Mailer($this->app->getConfig());
                $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');
                $subject  = "KIM List Updated â€” {$siteName}";
                $body     = "Hi {$name},\n\n"
                          . "Your KIM List entry has been updated.\n"
                          . "Updated fields: " . implode(', ', $updatedFields) . "\n\n"
                          . "-- {$siteName}";
                $mailer->send($newEmail !== '' ? $newEmail : $currentEmail, $name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[KIM] Update confirmation email failed: ' . $e->getMessage());
            }
        }

        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-updated'));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
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
        $baseUrl    = $this->app->getBaseUrl();
        $wrapperTpl = (new SiteSettingsRepo($this->app->getDatabase()))->get('collective_field_wrapper_template', '') ?? '';

        $flSelectHtml = '<select class="fl-select" id="kim-fanlisting" name="fanlisting_id" required>'
                      . '<option value="">&#8212; Select the fanlisting your entry is for &#8212;</option>';
        foreach ($fanlistings as $_fl) {
            $_flId       = htmlspecialchars((string) $_fl->id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $_flTitle    = htmlspecialchars((string) $_fl->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $_flSelected = ((string) ($old['fanlisting_id'] ?? '')) === (string) $_fl->id ? ' selected' : '';
            $flSelectHtml .= "<option value=\"{$_flId}\"{$_flSelected}>{$_flTitle}</option>";
        }
        $flSelectHtml .= '</select>';

        $captchaQ = Captcha::getQuestion('kim_update');
        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'text',     'name' => 'name',             'id' => 'kim-name',             'label' => 'Name',                                                                         'required' => true,  'value' => (string) ($old['name']          ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'email',    'name' => 'current_email',    'id' => 'kim-current-email',    'label' => 'Current Email',                                                                'required' => true,  'value' => (string) ($old['current_email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'password', 'name' => 'current_password', 'id' => 'kim-current-password', 'label' => 'Current Password',                                                             'required' => true,  'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
            ['type' => 'select',   'name' => 'fanlisting_id',    'id' => 'kim-fanlisting',       'label' => 'Fanlisting',                                                                   'required' => true,  'raw_input' => $flSelectHtml],
            ['type' => 'email',    'name' => 'new_email',        'id' => 'kim-new-email',        'label' => 'New Email',                                                                    'required' => false, 'value' => (string) ($old['new_email']     ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'off'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'url',      'name' => 'new_url',          'id' => 'kim-new-url',          'label' => 'New Website URL',                                                 'required' => false, 'value' => (string) ($old['new_url']       ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'password', 'name' => 'new_password',     'id' => 'kim-new-password',     'label' => 'New Password',                                                                 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Min 8 characters; leave blank to keep current'],
            ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'kim-new-password-confirm', 'label' => 'Confirm New Password',                                               'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
            ['type' => 'text',     'name' => 'captcha_answer',   'id' => 'kim-captcha',          'label' => 'Captcha: ' . $captchaQ,                                                       'required' => true,  'error' => $captchaError, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ], $wrapperTpl);

        return $this->renderKimPage('update.php', [
            'page_title'  => 'KIM List â€” Update Entry',
            'form_action' => $baseUrl . '/kim/update',
            'csrf_token'  => $this->app->getCSRF()->token(),
            'errors'      => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'old'         => $old,
            'f'           => $f,
            'base_url'    => $baseUrl,
            'theme_path'  => $baseUrl . '/assets/css/default/',
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
            'theme_path'   => $baseUrl . '/assets/css/default/',
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
