<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Core\Mailer;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Models\Fanlisting;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FanlistingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;

/**
 * Fan update form — allows a fan to edit their own membership record.
 *
 * GET  /update/{id} — shows the update form.
 * POST /update/{id} — verifies credentials, applies changes, sends confirmation email.
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware
 *  - Honeypot field silently rejects bot submissions
 *  - Arithmetic CAPTCHA prevents automated submissions
 *  - Rate-limited per IP via lstr_rate_limits
 *  - Password verified via password_verify() (Argon2id)
 *  - Legacy MD5 hash detected, verified, and upgraded to Argon2id on success
 *  - All input validated before any DB write
 *  - "Leave blank to keep unchanged" semantics: only provided fields are overwritten
 */
final class UpdateController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /update/{id}
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $fieldRepo     = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields        = $fieldRepo->getFieldsForFanlisting($fanlistingId);
        $settingsRepo  = new FanlistingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($fanlistingId);

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        return $this->renderUpdateForm($fanlistingId, $fanlisting, $fields, $embedSettings, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /update/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();

        // Rate limiting — prevents credential stuffing and account harvesting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($baseUrl . '/update/' . $fanlistingId);
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/update/' . $fanlistingId . '/success');
        }

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $fieldRepo     = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields        = $fieldRepo->getFieldsForFanlisting($fanlistingId);
        $settingsRepo  = new FanlistingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($fanlistingId);

        $countryEnabled = (bool) $embedSettings['country_field_enabled'];

        // Section 1 — authentication credentials
        $email    = trim((string) $request->post('email',    ''));
        $password = (string) $request->post('password', '');

        // Section 2 — update fields (all optional; blank = keep existing)
        $newName            = trim((string) $request->post('new_name',            ''));
        $newEmail           = trim((string) $request->post('new_email',           ''));
        $newPassword        = (string) $request->post('new_password',        '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');
        $url                = trim((string) $request->post('url',                 ''));
        $country            = $countryEnabled ? trim((string) $request->post('country', '')) : '';
        $deleteUrl          = ((string) $request->post('delete_url', '') === '1');

        $postData = $request->postAll();

        // Captcha — re-render the form with an error on failure
        if (!Captcha::validate((string) $request->post('captcha_answer', ''))) {
            $old = [
                'email'     => $email,
                'new_name'  => $newName,
                'new_email' => $newEmail,
                'url'       => $url,
                'country'   => $country,
            ];
            return $this->renderUpdateForm(
                $fanlistingId, $fanlisting, $fields, $embedSettings, $old,
                '', 'Incorrect answer — please try again.',
            );
        }

        // Validate auth credentials (format only; existence checked after lookup)
        $v = new Validator($postData);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('password')->required();

        // Validate update fields only when non-empty
        if ($newName !== '') {
            $v->field('new_name')->maxLength(100);
        }
        if ($newEmail !== '') {
            $v->field('new_email')->email()->maxLength(255);
        }
        if ($url !== '') {
            $v->field('url')->url()->maxLength(500);
        }
        if ($countryEnabled && $country !== '') {
            $v->field('country')->maxLength(100);
        }

        foreach ($fields as $field) {
            $vf = $v->field('field_' . $field->name)->maxLength(255);
            if ($field->required) {
                $vf->required();
            }
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', [
                'email'     => $email,
                'new_name'  => $newName,
                'new_email' => $newEmail,
                'url'       => $url,
                'country'   => $country,
            ]);
            return Response::redirect($baseUrl . '/update/' . $fanlistingId);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($fanlisting->dbprefix, $email);

        if ($member === null) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', 'No membership found for that email address.');
            $session->set('lstr_flash_old', ['email' => $email]);
            return Response::redirect($baseUrl . '/update/' . $fanlistingId);
        }

        // Verify password — detect and upgrade legacy MD5 hashes
        $storedHash = $member->password;
        $verified   = false;

        if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
            // Legacy MD5 — verify then re-hash with Argon2id
            if (hash_equals($storedHash, md5($password))) {
                $verified = true;
                $newHash  = password_hash($password, PASSWORD_ARGON2ID);
                $memberRepo->update($fanlisting->dbprefix, $email, ['password' => $newHash]);
            }
        } else {
            $verified = password_verify($password, $storedHash);
        }

        if (!$verified) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', 'Incorrect password. Please try again.');
            $session->set('lstr_flash_old', ['email' => $email]);
            return Response::redirect($baseUrl . '/update/' . $fanlistingId);
        }

        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_UPDATE);

        // New password confirmation — re-render with update_error so the rest of the
        // form stays filled in rather than forcing the member to re-enter everything.
        if ($newPassword !== '' && $newPassword !== $newPasswordConfirm) {
            $old = [
                'email'     => $email,
                'new_name'  => $newName,
                'new_email' => $newEmail,
                'url'       => $url,
                'country'   => $country,
            ];
            return $this->renderUpdateForm(
                $fanlistingId, $fanlisting, $fields, $embedSettings, $old,
                '', '', 'New passwords do not match. Please try again.',
            );
        }

        // New email uniqueness check — only when the address is actually changing
        if ($newEmail !== '' && $newEmail !== $email) {
            if ($memberRepo->getByEmail($fanlisting->dbprefix, $newEmail) !== null) {
                $old = [
                    'email'     => $email,
                    'new_name'  => $newName,
                    'new_email' => $newEmail,
                    'url'       => $url,
                    'country'   => $country,
                ];
                return $this->renderUpdateForm(
                    $fanlistingId, $fanlisting, $fields, $embedSettings, $old,
                    '', '', 'That email address is already registered for this fanlisting.',
                );
            }
        }

        // Resolve final values — blank submitted field means keep existing value
        $finalName    = $newName  !== '' ? $newName  : $member->name;
        $finalEmail   = $newEmail !== '' ? $newEmail : $email;
        $finalCountry = ($countryEnabled && $country !== '') ? $country : $member->country;

        // URL: delete_url clears it; non-empty submission overwrites; blank keeps existing
        if ($deleteUrl) {
            $finalUrl = '';
        } elseif ($url !== '') {
            $finalUrl = $url;
        } else {
            $finalUrl = $member->url;
        }

        // Build update payload
        $updateData = [
            'name'    => $finalName,
            'url'     => $finalUrl,
            'country' => $finalCountry,
            'showurl' => $finalUrl !== '' ? 1 : 0,
        ];

        // Only write new email / password columns when they were intentionally changed
        if ($newEmail !== '' && $newEmail !== $email) {
            $updateData['email'] = $finalEmail;
        }
        if ($newPassword !== '') {
            $updateData['password'] = password_hash($newPassword, PASSWORD_ARGON2ID);
        }

        $prefix = (string) $this->app->getConfig()->get('enth_table_prefix', '');

        foreach ($fields as $field) {
            $colName              = ($prefix === '') ? $field->name : 'additional_' . $field->name;
            $updateData[$colName] = trim((string) ($postData['field_' . $field->name] ?? ''));
        }

        $memberRepo->update($fanlisting->dbprefix, $email, $updateData);

        // Confirmation email (best-effort)
        try {
            $mailer  = new Mailer($this->app->getConfig());
            $appName = (string) $this->app->getConfig()->get('app_name', 'Luster');

            $mailer->send(
                $finalEmail,
                $finalName,
                "Your {$fanlisting->name} membership has been updated",
                "Hi {$finalName},\n\n"
                . "Your membership in the {$fanlisting->name} fanlisting has been updated.\n\n"
                . "-- {$appName}",
            );
        } catch (\Throwable $e) {
            error_log('[Luster] Update email failed: ' . $e->getMessage());
        }

        // Redirect to configured URL or default success page
        $redirectUrl = (string) $embedSettings['redirect_url_update'];
        if ($redirectUrl !== '') {
            return Response::redirect($redirectUrl);
        }

        return Response::redirect($baseUrl . '/update/' . $fanlistingId . '/success');
    }

    // -----------------------------------------------------------------------
    // GET /update/{id}/success
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
            'page_title'      => 'Membership Updated',
            'success_heading' => 'Membership Updated',
            'success_message' => "Your membership in the {$fanlistingName} fanlisting has been updated successfully.",
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Renders the update form template with all required variables.
     *
     * Used by showForm() (GET), captcha-failure, password-mismatch, and
     * new-email-taken re-renders so the form stays filled in without a redirect.
     *
     * @param \Luster\Models\AdditionalField[] $fields
     * @param array<string,mixed>              $embedSettings
     * @param array<string,string>             $old
     */
    private function renderUpdateForm(
        int $fanlistingId,
        Fanlisting $fanlisting,
        array $fields,
        array $embedSettings,
        array $old = [],
        string $error = '',
        string $captchaError = '',
        string $updateError = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('update.php', [
            'fanlisting'           => $fanlisting,
            'fanlisting_name'      => $fanlisting->name,
            'fanlisting_url'       => $fanlisting->url,
            'page_title'           => 'Update Membership',
            'form_action'          => $baseUrl . '/update/' . $fanlistingId,
            'csrf_token'           => $this->app->getCSRF()->token(),
            'errors'               => $error !== '' ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '',
            'update_error'         => $updateError,
            'field_email'          => (string) ($old['email']     ?? ''),
            'field_name'           => (string) ($old['new_name']  ?? ''),
            'field_new_email'      => (string) ($old['new_email'] ?? ''),
            'field_url'            => (string) ($old['url']       ?? ''),
            'country_select'       => Countries::buildSelect((string) ($old['country'] ?? '')),
            'country_field_enabled' => (bool) $embedSettings['country_field_enabled'],
            'fields'               => $fields,
            'old'                  => $old,
            'captcha_question'     => Captcha::getQuestion(),
            'captcha_error'        => $captchaError,
            'lost_password_url'    => $baseUrl . '/reset/' . $fanlistingId,
            'base_url'             => $baseUrl,
            'theme_path'           => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }
}
