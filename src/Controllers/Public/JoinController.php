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
 * Public join form.
 *
 * GET  /join/{id} — renders the join form for a fanlisting.
 * POST /join/{id} — validates input, checks for duplicates, inserts member,
 *                   sends confirmation email, redirects to success page.
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware before this action runs
 *  - Honeypot field silently rejects bot submissions
 *  - Arithmetic CAPTCHA prevents automated submissions
 *  - Rate-limited per IP (10 submissions per hour) via lstr_rate_limits
 *  - All input validated before any DB write
 *  - Duplicate email checked before insert
 *  - Additional field names resolved only from FieldRepo (never user-supplied)
 *  - Member password auto-generated if not supplied; only the hash is stored in DB
 */
final class JoinController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /join/{id}
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

        return $this->renderJoinForm($fanlistingId, $fanlisting, $fields, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /join/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();

        // Rate limiting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_JOIN)) {
            $session->set('lstr_flash_error', 'Too many join attempts. Please try again later.');
            return Response::redirect($baseUrl . '/join/' . $fanlistingId);
        }

        // Honeypot — silent reject; redirect to the success page as if it worked
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/join/' . $fanlistingId . '/success');
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
                'name'      => trim((string) $request->post('name',    '')),
                'email'     => trim((string) $request->post('email',   '')),
                'url'       => trim((string) $request->post('url',     '')),
                'country'   => trim((string) $request->post('country', '')),
                'send_info' => (string) $request->post('send_info', ''),
            ];
            return $this->renderJoinForm(
                $fanlistingId, $fanlisting, $fields, $old,
                '', 'Incorrect answer — please try again.',
            );
        }

        // Collect and validate posted data
        $name    = trim((string) $request->post('name',    ''));
        $email   = trim((string) $request->post('email',   ''));
        $url     = trim((string) $request->post('url',     ''));
        $country = trim((string) $request->post('country', ''));

        $postData = $request->postAll();

        $v = new Validator($postData);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->url()->maxLength(255);
        $v->field('country')->required()->maxLength(100);

        foreach ($fields as $field) {
            $vf = $v->field('field_' . $field->name)->maxLength(255);
            if ($field->required) {
                $vf->required();
            }
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_JOIN);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', [
                'name'      => $name,
                'email'     => $email,
                'url'       => $url,
                'country'   => $country,
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($baseUrl . '/join/' . $fanlistingId);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());

        // Duplicate email check
        if ($memberRepo->getByEmail($fanlisting->dbprefix, $email) !== null) {
            $session->set('lstr_flash_error', 'That email address is already registered for this fanlisting.');
            $session->set('lstr_flash_old', [
                'name'      => $name,
                'email'     => $email,
                'url'       => $url,
                'country'   => $country,
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($baseUrl . '/join/' . $fanlistingId);
        }

        // Password — use the supplied value or auto-generate a secure one
        $rawPassword     = (string) $request->post('password',         '');
        $passwordConfirm = (string) $request->post('password_confirm', '');

        if ($rawPassword !== '' && $rawPassword !== $passwordConfirm) {
            $session->set('lstr_flash_error', 'Passwords do not match. Please try again.');
            $session->set('lstr_flash_old', [
                'name'      => $name,
                'email'     => $email,
                'url'       => $url,
                'country'   => $country,
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($baseUrl . '/join/' . $fanlistingId);
        }

        if ($rawPassword === '') {
            $rawPassword  = bin2hex(random_bytes(8));
            $wasGenerated = true;
        } else {
            $wasGenerated = false;
        }
        $passwordHash = password_hash($rawPassword, PASSWORD_ARGON2ID);

        // Build insert data
        $insertData = [
            'name'     => $name,
            'email'    => $email,
            'url'      => $url,
            'country'  => $country,
            'showurl'  => $url !== '' ? 1 : 0,
            'password' => $passwordHash,
            'added'    => date('Y-m-d H:i:s'),
        ];

        // Determine approved/pending column based on schema variant
        $prefix = (string) $this->app->getConfig()->get('enth_table_prefix', '');

        if ($prefix === '') {
            // Unprefixed schema: pending=0 means approved, pending=1 means queued
            $insertData['pending'] = 0;
        } else {
            // Standard schema: approved=1 means approved
            $insertData['approved'] = 1;
            $insertData['updated']  = date('Y-m-d H:i:s');
        }

        // Additional fields — names from FieldRepo only, never raw user input
        foreach ($fields as $field) {
            $colName              = ($prefix === '') ? $field->name : 'additional_' . $field->name;
            $insertData[$colName] = trim((string) ($postData['field_' . $field->name] ?? ''));
        }

        $memberRepo->insert($fanlisting->dbprefix, $insertData);
        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_JOIN);

        $sendInfo = ($request->post('send_info') === '1');

        // Confirmation email (best-effort — do not abort on failure)
        // Send when the member requested it OR when a password was auto-generated
        // (they need the password regardless of their preference).
        if ($sendInfo || $wasGenerated) {
            try {
                $mailer  = new Mailer($this->app->getConfig());
                $appName = (string) $this->app->getConfig()->get('app_name', 'Luster');

                $subject = "Welcome to the {$fanlisting->name} fanlisting!";
                $body    = "Hi {$name},\n\n"
                         . "Thank you for joining the {$fanlisting->name} fanlisting.\n\n"
                         . "Here are your membership details:\n"
                         . "  Name    : {$name}\n"
                         . "  Email   : {$email}\n"
                         . "  Website : " . ($url !== '' ? $url : '(none)') . "\n"
                         . "  Country : {$country}\n"
                         . "  Password: {$rawPassword}\n\n";

                if ($wasGenerated) {
                    $body .= "Your password was automatically generated since you did not provide one.\n\n";
                }

                $body .= "Please keep this email for your records. "
                       . "You will need your password to update or remove your membership.\n\n"
                       . "-- {$appName}";

                $mailer->send($email, $name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[Luster] Join email failed: ' . $e->getMessage());
            }
        }

        return Response::redirect($baseUrl . '/join/' . $fanlistingId . '/success');
    }

    // -----------------------------------------------------------------------
    // GET /join/{id}/success
    // -----------------------------------------------------------------------

    public function success(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        $fanlistingName = $fanlisting?->name ?? 'this fanlisting';

        $html = $this->app->getView()->render('default/success.html', [
            'title'           => 'Thank You!',
            'message'         => "You have successfully joined the {$fanlistingName} fanlisting. "
                               . 'Your membership may need to be approved before it appears in the list.',
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
     * Renders the join form template with all required tokens.
     *
     * Used by both showForm() (on GET) and the captcha-failure path in submit(),
     * so the form can be re-rendered in-place without a redirect.
     *
     * @param \Luster\Models\AdditionalField[] $fields
     * @param array<string,string>             $old
     */
    private function renderJoinForm(
        int $fanlistingId,
        Fanlisting $fanlisting,
        array $fields,
        array $old = [],
        string $error = '',
        string $captchaError = '',
    ): Response {
        $html = $this->app->getView()->render('default/join.html', [
            'fanlisting_name'  => $fanlisting->name,
            'fanlisting_url'   => $fanlisting->url,
            'csrf_token'       => $this->app->getCSRF()->token(),
            'fanlisting_id'    => (string) $fanlistingId,
            'error'            => $error,
            'old_name'         => (string) ($old['name']  ?? ''),
            'old_email'        => (string) ($old['email'] ?? ''),
            'old_url'          => (string) ($old['url']   ?? ''),
            'country_select'      => new ViewRaw(Countries::buildSelect((string) ($old['country'] ?? ''))),
            'extra_fields'        => new ViewRaw($this->buildExtraFieldsHtml($fields, $old)),
            'send_info_checked'   => isset($old['send_info']) ? ($old['send_info'] ? 'checked' : '') : 'checked',
            'captcha_question' => Captcha::getQuestion(),
            'captcha_error'    => $captchaError,
            'app_name'         => (string) $this->app->getConfig()->get('app_name', 'Luster'),
            'base_url'         => $this->app->getBaseUrl(),
        ]);

        return Response::html($html);
    }

    /**
     * Builds the HTML for dynamic additional fields from FieldRepo data.
     *
     * @param \Luster\Models\AdditionalField[] $fields
     * @param array<string,string>             $oldValues Repopulation values on error.
     */
    private function buildExtraFieldsHtml(array $fields, array $oldValues): string
    {
        if (empty($fields)) {
            return '';
        }

        $html = '';

        foreach ($fields as $field) {
            $inputName = 'field_' . $field->name;
            $labelText = htmlspecialchars($field->label,    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $nameAttr  = htmlspecialchars($inputName,       ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
