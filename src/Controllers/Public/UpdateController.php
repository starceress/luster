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
use Luster\Core\ViewRaw;
use Luster\Models\Fanlisting;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;

/**
 * Fan update form — allows a fan to edit their own membership record.
 *
 * GET  /update/{id} — shows the update form; requires email + password to load.
 * POST /update/{id} — verifies credentials, updates the record, sends email.
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware
 *  - Honeypot field silently rejects bot submissions
 *  - Arithmetic CAPTCHA prevents automated submissions
 *  - Password verified via password_verify() (Argon2id)
 *  - Legacy MD5 hash detected, verified, and upgraded to Argon2id on success
 *  - All input validated before any DB write
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

        $fieldRepo = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields    = $fieldRepo->getFieldsForFanlisting($fanlistingId);

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        return $this->renderUpdateForm($fanlistingId, $fanlisting, $fields, $old, $error);
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

        // Honeypot — silent reject; redirect to the success page as if it worked
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/update/' . $fanlistingId . '/success');
        }

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $fieldRepo = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields    = $fieldRepo->getFieldsForFanlisting($fanlistingId);

        // Captcha — re-render the form with an error on failure
        if (!Captcha::validate((string) $request->post('captcha_answer', ''))) {
            $old = [
                'email'   => trim((string) $request->post('email',   '')),
                'name'    => trim((string) $request->post('name',    '')),
                'url'     => trim((string) $request->post('url',     '')),
                'country' => trim((string) $request->post('country', '')),
            ];
            return $this->renderUpdateForm(
                $fanlistingId, $fanlisting, $fields, $old,
                '', 'Incorrect answer — please try again.',
            );
        }

        $email    = trim((string) $request->post('email',    ''));
        $password = (string) $request->post('password', '');
        $name     = trim((string) $request->post('name',    ''));
        $url      = trim((string) $request->post('url',     ''));
        $country  = trim((string) $request->post('country', ''));

        $postData = $request->postAll();

        // Basic validation
        $v = new Validator($postData);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('password')->required();
        $v->field('name')->required()->maxLength(100);
        $v->field('url')->url()->maxLength(255);
        $v->field('country')->required()->maxLength(100);

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
                'email'   => $email,
                'name'    => $name,
                'url'     => $url,
                'country' => $country,
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

        // Build update payload
        $updateData = [
            'name'    => $name,
            'url'     => $url,
            'country' => $country,
            'showurl' => $url !== '' ? 1 : 0,
        ];

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
                $email,
                $name,
                "Your {$fanlisting->name} membership has been updated",
                "Hi {$name},\n\n"
                . "Your membership in the {$fanlisting->name} fanlisting has been updated.\n\n"
                . "-- {$appName}",
            );
        } catch (\Throwable $e) {
            error_log('[Luster] Update email failed: ' . $e->getMessage());
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

        $html = $this->app->getView()->render('default/success.html', [
            'title'           => 'Membership Updated',
            'message'         => "Your membership in the {$fanlistingName} fanlisting has been updated successfully.",
            'fanlisting_name' => $fanlistingName,
            'app_name'        => (string) $this->app->getConfig()->get('app_name', 'Luster'),
            'base_url'        => $this->app->getBaseUrl(),
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Renders the update form template with all required tokens.
     *
     * Used by both showForm() (on GET) and the captcha-failure path in submit(),
     * so the form can be re-rendered in-place without a redirect.
     *
     * @param \Luster\Models\AdditionalField[] $fields
     * @param array<string,string>             $old
     */
    private function renderUpdateForm(
        int $fanlistingId,
        Fanlisting $fanlisting,
        array $fields,
        array $old = [],
        string $error = '',
        string $captchaError = '',
    ): Response {
        $html = $this->app->getView()->render('default/update.html', [
            'fanlisting_name'  => $fanlisting->name,
            'fanlisting_url'   => $fanlisting->url,
            'csrf_token'       => $this->app->getCSRF()->token(),
            'fanlisting_id'    => (string) $fanlistingId,
            'error'            => $error,
            'old_name'         => (string) ($old['name']  ?? ''),
            'old_email'        => (string) ($old['email'] ?? ''),
            'old_url'          => (string) ($old['url']   ?? ''),
            'country_select'   => new ViewRaw(Countries::buildSelect((string) ($old['country'] ?? ''))),
            'extra_fields'     => new ViewRaw($this->buildExtraFieldsHtml($fields, $old)),
            'captcha_question' => Captcha::getQuestion(),
            'captcha_error'    => $captchaError,
            'app_name'         => (string) $this->app->getConfig()->get('app_name', 'Luster'),
            'base_url'         => $this->app->getBaseUrl(),
        ]);

        return Response::html($html);
    }

    /**
     * @param \Luster\Models\AdditionalField[] $fields
     * @param array<string,string>             $oldValues
     */
    private function buildExtraFieldsHtml(array $fields, array $oldValues): string
    {
        if (empty($fields)) {
            return '';
        }

        $html = '';

        foreach ($fields as $field) {
            $inputName = 'field_' . $field->name;
            $labelText = htmlspecialchars($field->label,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $nameAttr  = htmlspecialchars($inputName,      ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $oldVal    = htmlspecialchars((string) ($oldValues[$inputName] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $required  = $field->required ? ' required' : '';
            $reqMark   = $field->required ? ' <span aria-hidden="true">*</span>' : '';

            if ($field->type === 'textarea') {
                $html .= "<div class=\"form-group\">\n"
                       . "  <label for=\"{$nameAttr}\">{$labelText}{$reqMark}</label>\n"
                       . "  <textarea id=\"{$nameAttr}\" name=\"{$nameAttr}\" maxlength=\"255\"{$required}>{$oldVal}</textarea>\n"
                       . "</div>\n";
            } else {
                $html .= "<div class=\"form-group\">\n"
                       . "  <label for=\"{$nameAttr}\">{$labelText}{$reqMark}</label>\n"
                       . "  <input type=\"text\" id=\"{$nameAttr}\" name=\"{$nameAttr}\" value=\"{$oldVal}\" maxlength=\"255\"{$required}>\n"
                       . "</div>\n";
            }
        }

        return $html;
    }
}
