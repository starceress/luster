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
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FanlistingSettingsRepo;
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

        $fieldRepo     = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields        = $fieldRepo->getFieldsForFanlisting($fanlistingId);
        $settingsRepo  = new FanlistingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($fanlistingId);

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        return $this->renderJoinForm($fanlistingId, $fanlisting, $fields, $embedSettings, $old, $error);
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

        $fieldRepo     = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields        = $fieldRepo->getFieldsForFanlisting($fanlistingId);
        $settingsRepo  = new FanlistingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($fanlistingId);

        $countryEnabled = (bool) $embedSettings['country_field_enabled'];
        $formUrl        = (string) $embedSettings['form_url_join'] !== ''
                        ? (string) $embedSettings['form_url_join']
                        : $baseUrl . '/join/' . $fanlistingId;

        // Captcha — re-render the form with an error on failure
        if (!Captcha::validate((string) $request->post('captcha_answer', ''))) {
            $old = [
                'name'      => trim((string) $request->post('name',    '')),
                'email'     => trim((string) $request->post('email',   '')),
                'url'       => trim((string) $request->post('url',     '')),
                'country'   => $countryEnabled ? trim((string) $request->post('country', '')) : '',
                'send_info' => (string) $request->post('send_info', ''),
            ];
            return $this->renderJoinForm(
                $fanlistingId, $fanlisting, $fields, $embedSettings, $old,
                '', 'Incorrect answer — please try again.',
            );
        }

        // Collect and validate posted data
        $name    = trim((string) $request->post('name',    ''));
        $email   = trim((string) $request->post('email',   ''));
        $url     = trim((string) $request->post('url',     ''));
        $country = $countryEnabled ? trim((string) $request->post('country', '')) : '';

        $postData = $request->postAll();

        $v = new Validator($postData);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->url()->maxLength(500);
        if ($countryEnabled) {
            $v->field('country')->required()->maxLength(100);
        }

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
            return Response::redirect($formUrl);
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
            return Response::redirect($formUrl);
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
            return Response::redirect($formUrl);
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
        $settingsRepo->touchLastUpdated($fanlistingId);

        $sendInfo = ($request->post('send_info') === '1');

        // Confirmation email (best-effort — do not abort on failure)
        // Send when the member requested it OR when a password was auto-generated
        // (they need the password regardless of their preference).
        if ($sendInfo || $wasGenerated) {
            try {
                $mailer    = new Mailer($this->app->getConfig());
                $appName   = (string) $this->app->getConfig()->get('app_name', 'Luster');
                $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
                $tpl       = $emailRepo->get($fanlistingId, EmailTemplateRepo::TYPE_JOIN);
                $tokens    = EmailTemplateRepo::buildTokens(
                    fanlistingName: $fanlisting->name,
                    fanlistingUrl:  $fanlisting->url,
                    appName:        $appName,
                    fanName:        $name,
                    fanEmail:       $email,
                    fanUrl:         $url,
                    fanCountry:     $country,
                    fanPassword:    $rawPassword,
                );
                [$subject, $body] = EmailTemplateRepo::resolve($tpl, EmailTemplateRepo::TYPE_JOIN, $tokens);
                $mailer->send($email, $name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[Luster] Join email failed: ' . $e->getMessage());
            }
        }

        // Redirect to configured URL or default success page
        $redirectUrl = (string) $embedSettings['redirect_url_join'];
        if ($redirectUrl !== '') {
            return Response::redirect($redirectUrl);
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
        $baseUrl        = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('success.php', [
            'fanlisting'       => $fanlisting,
            'fanlisting_name'  => $fanlistingName,
            'fanlisting_url'   => $fanlisting?->url ?? '',
            'page_title'       => 'Thank You!',
            'success_heading'  => 'Thank You!',
            'success_message'  => "You have successfully joined the {$fanlistingName} fanlisting. "
                                . 'Your membership may need to be approved before it appears in the list.',
            'base_url'         => $baseUrl,
            'theme_path'       => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Renders the join form template with all required variables.
     *
     * Used by both showForm() (on GET) and the captcha-failure path in submit(),
     * so the form can be re-rendered in-place without a redirect.
     *
     * @param \Luster\Models\AdditionalField[] $fields
     * @param array<string,mixed>              $embedSettings
     * @param array<string,string>             $old
     */
    private function renderJoinForm(
        int $fanlistingId,
        Fanlisting $fanlisting,
        array $fields,
        array $embedSettings,
        array $old = [],
        string $error = '',
        string $captchaError = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        $errorsHtml = $error !== ''
            ? '<ul>' . implode('', array_map(
                fn(string $e) => '<li>' . htmlspecialchars($e, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>',
                explode(' ', $error),
              )) . '</ul>'
            : '';

        $html = $this->app->getView()->renderPublic('join.php', [
            'fanlisting'           => $fanlisting,
            'fanlisting_name'      => $fanlisting->name,
            'fanlisting_url'       => $fanlisting->url,
            'page_title'           => 'Join',
            'form_action'          => $baseUrl . '/join/' . $fanlistingId,
            'csrf_token'           => $this->app->getCSRF()->token(),
            'errors'               => $error !== '' ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '',
            'field_name'           => (string) ($old['name']  ?? ''),
            'field_email'          => (string) ($old['email'] ?? ''),
            'field_url'            => (string) ($old['url']   ?? ''),
            'country_select'       => Countries::buildSelect((string) ($old['country'] ?? '')),
            'country_field_enabled' => (bool) $embedSettings['country_field_enabled'],
            'fields'               => $fields,
            'old'                  => $old,
            'captcha_question'     => Captcha::getQuestion(),
            'captcha_error'        => $captchaError,
            'send_info_checked'    => isset($old['send_info']) ? ($old['send_info'] ? 'checked' : '') : 'checked',
            'base_url'             => $baseUrl,
            'theme_path'           => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }
}
