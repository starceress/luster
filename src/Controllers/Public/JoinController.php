<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Core\View;
use Luster\Models\Listing;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormFieldBuilder;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\ListingTemplateResolver;
use Luster\Support\SuccessRenderer;

/**
 * Public join form.
 *
 * GET  /join/{id} â€” renders the join form for a listing.
 * POST /join/{id} â€” validates input, checks for duplicates, inserts member,
 *                   sends confirmation email, redirects to host page.
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
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $fieldRepo     = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields        = $fieldRepo->getFieldsForListing($listingId);
        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($listingId);

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        // Redirect to rules gate if the fan hasn't agreed yet
        if ($this->app->isAddonInstalled('Rules') && !$this->rulesAgreed($listingId)) {
            $baseUrl = $this->app->getBaseUrl();
            $session->set('lstr_embed_return_to', $baseUrl . '/join/' . $listingId);
            return Response::redirect($baseUrl . '/rules/' . $listingId);
        }

        return $this->renderJoinForm($listingId, $listing, $fields, $embedSettings, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /join/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');

        // Rate limiting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_JOIN)) {
            $session->set('lstr_flash_error', 'Too many join attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/join/' . $listingId);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'joined'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/join/' . $listingId);
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        // Rules gate enforcement — catches direct POSTs that bypass the embed gate
        if ($this->app->isAddonInstalled('Rules') && !$this->rulesAgreed($listingId)) {
            $session->set('lstr_embed_return_to', $embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/join/' . $listingId);
            return Response::redirect($baseUrl . '/rules/' . $listingId);
        }

        $fieldRepo     = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields        = $fieldRepo->getFieldsForListing($listingId);
        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($listingId);

        $formPageName   = (string) $embedSettings['form_url_join'];
        $formUrl        = $formPageName !== ''
                        ? rtrim((string) $listing->url, '/') . '/' . ltrim($formPageName, '/')
                        : $baseUrl . '/join/' . $listingId;

        // Captcha â€” redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'join')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_JOIN);
            $session->set('lstr_flash_error', 'Incorrect answer â€” please try again.');
            $session->set('lstr_flash_old', [
                'name'      => trim((string) $request->post('name',    '')),
                'email'     => trim((string) $request->post('email',   '')),
                'url'       => trim((string) $request->post('url',     '')),
                'country'   => trim((string) $request->post('country', '')),
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
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
        $v->field('url')->httpUrl()->maxLength(500);
        $countryFieldEnabled = (bool) $embedSettings['country_field_enabled'];
        $countryField = $v->field('country')->maxLength(100);
        if ($countryFieldEnabled) {
            $countryField->required();
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
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());

        // Blacklist check
        if ((new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $session->set('lstr_flash_error', 'That email address cannot be used to join.');
            $session->set('lstr_flash_old', [
                'name'      => $name,
                'email'     => $email,
                'url'       => $url,
                'country'   => $country,
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Duplicate email check
        if ($memberRepo->getByEmail($listing->dbprefix, $email) !== null) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_JOIN);
            $session->set('lstr_flash_error', 'That email address is already registered for this listing.');
            $session->set('lstr_flash_old', [
                'name'      => $name,
                'email'     => $email,
                'url'       => $url,
                'country'   => $country,
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Password â€” use the supplied value or auto-generate a secure one
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
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        if ($rawPassword !== '') {
            $passwordValidator = new Validator(['password' => $rawPassword]);
            PasswordPolicy::apply($passwordValidator->field('password'));
            if ($passwordValidator->fails()) {
                $session->set('lstr_flash_error', implode(' ', $passwordValidator->allErrors()));
                $session->set('lstr_flash_old', [
                    'name'      => $name,
                    'email'     => $email,
                    'url'       => $url,
                    'country'   => $country,
                    'send_info' => (string) $request->post('send_info', ''),
                ]);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
            }
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

        // Per-listing join settings (auto-approve + email override).
        $joinSettings = $settingsRepo->getJoinSettings($listingId);
        $autoApprove  = $joinSettings['auto_approve'];

        // Determine approved/pending column based on schema variant.
        // Default: new members are pending (manual approval required).
        // Per-listing auto_approve opts this listing into immediate approval.
        $prefix = (string) $this->app->getConfig()->get('enth_table_prefix', '');

        if ($prefix === '') {
            // Unprefixed schema: pending=0 = approved, pending=1 = queued
            $insertData['pending'] = $autoApprove ? 0 : 1;
        } else {
            // Standard schema: approved=1 = approved, approved=0 = pending
            $insertData['approved'] = $autoApprove ? 1 : 0;
            $insertData['updated']  = date('Y-m-d H:i:s');
        }

        // Additional fields â€” names from FieldRepo only, never raw user input.
        // Checkbox fields post as arrays; implode to a comma-separated string.
        foreach ($fields as $field) {
            $colName   = ($prefix === '') ? $field->name : 'additional_' . $field->name;
            $raw       = $postData['field_' . $field->name] ?? '';
            $insertData[$colName] = is_array($raw)
                ? implode(', ', array_map('trim', $raw))
                : trim((string) $raw);
        }

        $memberRepo->insert($listing->dbprefix, $insertData);
        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_JOIN);
        // Only reset the stale clock when the join is immediately approved.
        // Pending joins wait for admin approval, which will touch it via MemberController.
        if ($autoApprove) {
            $settingsRepo->touchLastUpdated($listingId);
        }

        // Confirmation email always sent (best-effort â€” do not abort on failure)
        {
            try {
                $cfg       = $this->app->getConfig();
                $mailer    = new Mailer($cfg);
                $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
                $resetUrl  = $wasGenerated ? $this->issuePasswordSetupUrl($listingId, $email) : '';
                $tokens    = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $cfg->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $cfg->get('owner_name', ''),
                    ownerEmail:     (string) $cfg->get('owner_email', ''),
                    fanName:        $name,
                    fanEmail:       $email,
                    fanUrl:         $url,
                    fanCountry:     $country,
                    resetUrl:       $resetUrl,
                );
                [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_JOIN, $tokens);
                $mailer->send($email, $name, $subject, $body);

                if ($wasGenerated && $resetUrl !== '') {
                    [$resetSubject, $resetBody] = $emailRepo->resolveForListing(
                        $listingId,
                        EmailTemplateRepo::TYPE_RESET,
                        $tokens,
                    );
                    $mailer->send($email, $name, $resetSubject, $resetBody);
                }
            } catch (\Throwable $e) {
                error_log('[Luster] Join email failed: ' . $e->getMessage());
            }
        }

        // Admin notification email (best-effort â€” only when listing has an email address)
        if ($listing->email !== '') {
            try {
                $cfg          = $this->app->getConfig();
                $notifyMailer = new Mailer($cfg);
                $notifyRepo   = new EmailTemplateRepo($this->app->getDatabase());
                $notifyTokens = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $cfg->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $cfg->get('owner_name', ''),
                    ownerEmail:     (string) $cfg->get('owner_email', ''),
                    fanName:        $name,
                    fanEmail:       $email,
                    fanUrl:         $url,
                    fanCountry:     $country,
                );
                [$notifySubject, $notifyBody] = $notifyRepo->resolveForListing(
                    $listingId, EmailTemplateRepo::TYPE_NOTIFY_JOIN, $notifyTokens,
                );
                $notifyMailer->send($listing->email, $listing->title, $notifySubject, $notifyBody);
            } catch (\Throwable $e) {
                error_log('[Luster] Join notification email failed: ' . $e->getMessage());
            }
        }

        $session->remove('lstr_embed_return_to');
        $successTpl = (new ListingSettingsRepo($this->app->getDatabase()))->getSuccessTemplate($listingId) ?? '';
        $action     = $autoApprove ? 'joined' : 'joined-pending';
        $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, $action, [
            'fan_name'      => $name,
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
        ]));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
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
        int $listingId,
        Listing $listing,
        array $fields,
        array $embedSettings,
        array $old = [],
        string $error = '',
        string $captchaError = '',
    ): Response {
        $baseUrl         = $this->app->getBaseUrl();
        $captchaQuestion = Captcha::getQuestion('join');
        $countrySelect   = Countries::buildSelect((string) ($old['country'] ?? ''));
        $settingsRepoRjf = new ListingSettingsRepo($this->app->getDatabase());
        $wrapperTpl      = $settingsRepoRjf->getWrapperTemplate($listingId) ?? '';

        $countryFieldEnabled = (bool) $embedSettings['country_field_enabled'];

        $fb          = $this->app->getFormFieldBuilder();
        $descriptors = [
            ['type' => 'text',     'name' => 'name',             'id' => 'join-name',             'label' => 'Name',             'required' => true,  'value' => (string) ($old['name']  ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'email',    'name' => 'email',            'id' => 'join-email',            'label' => 'Email',            'required' => true,  'value' => (string) ($old['email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'url',      'name' => 'url',              'id' => 'join-url',              'label' => 'Website URL',      'required' => false, 'value' => (string) ($old['url']   ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Optional'],
            ['type' => 'text',     'name' => 'country',          'id' => '',                      'label' => 'Country',          'required' => $countryFieldEnabled, 'raw_input' => $countrySelect],
            ...$fb->descriptorsFromAdditionalFields($fields, $old),
            ['type' => 'password', 'name' => 'password',         'id' => 'join-password',         'label' => 'Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Optional - If left blank, a password will automatically generated and emailed to you.'],
            ['type' => 'password', 'name' => 'password_confirm', 'id' => 'join-password-confirm', 'label' => 'Confirm Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
            ['type' => 'text',     'name' => 'captcha_answer',   'id' => 'join-captcha',          'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'error' => $captchaError, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, $wrapperTpl);

        $globalTemplates = (new SiteSettingsRepo($this->app->getDatabase()))->getGlobalTemplates();
        $joinResolved    = ListingTemplateResolver::resolve(
            $settingsRepoRjf->getJoinFormTemplate($listingId),
            $globalTemplates['listing_default_join_form_template'] ?? null,
            '',
        );
        $formHtml    = null;
        $joinFormTpl = $joinResolved['should_render_template'] ? $joinResolved['value'] : '';
        if ($joinFormTpl !== '') {
            $additionalFieldsHtml      = implode("\n", array_map(
                fn($field) => $f['field_' . $field->name] ?? '', $fields,
            ));
            $additionalFieldsInputHtml = implode("\n", array_map(
                fn($field) => $f['field_' . $field->name . '_input'] ?? '', $fields,
            ));

            $tokens = [
                '{listing_title}'                => View::e($listing->title),
                '{listing_url}'                  => View::e($listing->url),
                '{field_name}'                    => $f['name'],
                '{field_name_input}'              => $f['name_input'],
                '{field_email}'                   => $f['email'],
                '{field_email_input}'             => $f['email_input'],
                '{field_url}'                     => $f['url'],
                '{field_url_input}'               => $f['url_input'],
                '{field_country}'                 => $countryFieldEnabled ? $f['country'] : '',
                '{field_country_input}'           => $countryFieldEnabled ? $f['country_input'] : '',
                '{additional_fields}'             => $additionalFieldsHtml,
                '{additional_fields_input}'       => $additionalFieldsInputHtml,
                '{field_password}'                => $f['password'],
                '{field_password_input}'          => $f['password_input'],
                '{field_password_confirm}'        => $f['password_confirm'],
                '{field_password_confirm_input}'  => $f['password_confirm_input'],
                '{captcha_block}'                 => $f['captcha_answer'],
                '{captcha_block_input}'           => $f['captcha_answer_input'],
                '{captcha_question}'              => View::e($captchaQuestion),
                '{submit_button}'                 => '<button type="submit" class="fl-submit">Join Fanlisting</button>',
                '{clear_button}'                  => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            ];

            $innerHtml = FormTemplateRenderer::expandTokens($joinFormTpl, $tokens);
            $formHtml  = FormTemplateRenderer::wrapForm(
                $innerHtml,
                $baseUrl . '/join/' . $listingId,
                $this->app->getCSRF()->token(),
                false,
            );
        }

        $html = $this->app->getView()->renderPublic('join.php', [
            'listing'               => $listing,
            'listing_name'          => $listing->title,
            'listing_url'           => $listing->url,
            'page_title'            => 'Join',
            'form_action'           => $baseUrl . '/join/' . $listingId,
            'csrf_token'            => $this->app->getCSRF()->token(),
            'errors'                => $error !== '' ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '',
            'fields'                => $fields,
            'f'                     => $f,
            'base_url'              => $baseUrl,
            'theme_path'            => $baseUrl . '/assets/css/default/',
            'country_field_enabled' => $countryFieldEnabled,
            'form_html'             => $formHtml,
        ]);

        return Response::html($html);
    }

    private function issuePasswordSetupUrl(int $listingId, string $email): string
    {
        $resetRepo = new FanPasswordResetRepo($this->app->getDatabase());
        $resetRepo->deleteByEmail($listingId, $email);

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new \DateTimeImmutable())
            ->modify('+3600 seconds')
            ->format('Y-m-d H:i:s');

        $resetRepo->create($listingId, $email, $tokenHash, $expiresAt);

        return $this->app->getBaseUrl() . '/reset/' . $listingId . '/confirm?token=' . urlencode($rawToken);
    }

    /**
     * Returns true if the fan has agreed to the current rules version for this
     * listing, or if no active rules exist.  Wrapped in try/catch so a missing
     * lstr_rules table (addon uninstalled mid-session) never blocks joining.
     */
    private function rulesAgreed(int $listingId): bool
    {
        try {
            $repo    = new \Luster\Addons\Rules\RulesRepo($this->app->getDatabase());
            $ruleSet = $repo->getForListing($listingId);
            if ($ruleSet === null || !$ruleSet->enabled || trim($ruleSet->body) === '') {
                return true;
            }
            $stored = (string) $this->app->getSession()->get('lstr_rules_agreed_' . $listingId, '');
            return $stored === $ruleSet->fingerprint();
        } catch (\Throwable) {
            return true;
        }
    }
}
