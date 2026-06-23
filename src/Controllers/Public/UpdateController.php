<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles the two-step fan update form. Step 1 asks the fan to sign in with
 their email and password. Step 2 shows the pre-filled update form where they
 can change their name, email, URL, country, additional fields, or password.
 Members can also permanently delete their membership from this form. Updates
 can be applied immediately or queued for admin review depending on per-listing
 settings. Legacy MD5 passwords are detected and upgraded to Argon2id on login.
*/

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Countries;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\TemplateEngine;
use Luster\Core\Validator;
use Luster\Models\Listing;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormFieldBuilder;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\ListingTemplateResolver;
use Luster\Support\SuccessRenderer;

/**
 * Fan update form — two-step flow.
 *
 * Step 1 (sign-in): GET /update/{id} with no session → email + password only.
 * Step 2 (update):  POST /update/{id}/sign-in → sets session → GET /update/{id}
 *                   → pre-filled update fields.
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware
 *  - Honeypot field silently rejects bot submissions
 *  - Arithmetic CAPTCHA on the update step (step 2)
 *  - Rate-limited per IP via lstr_rate_limits
 *  - Password verified via password_verify() (Argon2id)
 *  - Legacy MD5 hash detected, verified, and upgraded to Argon2id on success
 *  - Session key lstr_update_authed_{id} stores the authenticated member's email
 */
final class UpdateController
{
    /** Session key prefix for the authenticated member email. */
    private const AUTH_KEY = 'lstr_update_authed_';

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /update/{id}
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($listingId);

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        $authedEmail = (string) $session->get(self::AUTH_KEY . $listingId, '');

        if ($authedEmail === '') {
            return $this->renderSignIn($listingId, $listing, $embedSettings, $old, $error);
        }

        // Validate the session email still exists
        $fieldRepo  = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields     = $fieldRepo->getFieldsForListing($listingId);
        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($listing->dbprefix, $authedEmail);

        if ($member === null) {
            $session->remove(self::AUTH_KEY . $listingId);
            return $this->renderSignIn($listingId, $listing, $embedSettings, [], '');
        }

        return $this->renderUpdateForm($listingId, $listing, $fields, $embedSettings, $member, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /update/{id}/sign-in
    // -----------------------------------------------------------------------

    public function signIn(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/update/' . $listingId);
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/update/' . $listingId);
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($listingId);
        $formUrl       = $this->buildFormUrl($listing, $embedSettings, $baseUrl, $listingId);

        $email    = trim((string) $request->post('email',    ''));
        $password = (string) $request->post('password', '');

        $v = new Validator(['email' => $email, 'password' => $password]);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('password')->required();

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', ['email' => $email]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($listing->dbprefix, $email);

        if ($member === null) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', 'No membership found for that email address.');
            $session->set('lstr_flash_old', ['email' => $email]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Verify password — detect and upgrade legacy MD5 hashes
        $storedHash = $member->password;
        $verified   = false;

        if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
            if (hash_equals($storedHash, md5($password))) {
                $verified = true;
                $memberRepo->update($listing->dbprefix, $email, [
                    'password' => password_hash($password, PASSWORD_ARGON2ID),
                ]);
            }
        } else {
            $verified = password_verify($password, $storedHash);
        }

        if (!$verified) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', 'Incorrect password. Please try again.');
            $session->set('lstr_flash_old', ['email' => $email]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_UPDATE);
        $session->set(self::AUTH_KEY . $listingId, $email);

        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
    }

    // -----------------------------------------------------------------------
    // GET /update/{id}/sign-out
    // -----------------------------------------------------------------------

    public function signOut(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl   = $this->app->getBaseUrl();

        $this->app->getSession()->remove(self::AUTH_KEY . $listingId);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);
        $embedSettings = [];
        if ($listing !== null) {
            $embedSettings = (new ListingSettingsRepo($this->app->getDatabase()))->getEmbedSettings($listingId);
        }

        $formUrl = $listing !== null
            ? $this->buildFormUrl($listing, $embedSettings, $baseUrl, $listingId)
            : $baseUrl . '/update/' . $listingId;

        return Response::redirect($formUrl);
    }

    // -----------------------------------------------------------------------
    // POST /update/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $ip        = $request->ip();

        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/update/' . $listingId);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'updated'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/update/' . $listingId);
        }

        // Must be authenticated before reaching the update form
        $email = (string) $session->get(self::AUTH_KEY . $listingId, '');
        if ($email === '') {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/update/' . $listingId);
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $fieldRepo     = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields        = $fieldRepo->getFieldsForListing($listingId);
        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($listingId);
        $formUrl       = $this->buildFormUrl($listing, $embedSettings, $baseUrl, $listingId);

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($listing->dbprefix, $email);

        if ($member === null) {
            $session->remove(self::AUTH_KEY . $listingId);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/update/' . $listingId);
        }

        // Update fields (all optional; blank = keep existing)
        $newName            = trim((string) $request->post('new_name',            ''));
        $newEmail           = trim((string) $request->post('new_email',           ''));
        $newPassword        = (string) $request->post('new_password',        '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');
        $url                = trim((string) $request->post('url',                 ''));
        $country            = trim((string) $request->post('country', ''));
        $deleteUrl          = ((string) $request->post('delete_url', '') === '1');

        $postData = $request->postAll();


        // Permanently delete membership instead of updating it
        if ((string) $request->post('delete_membership', '') === '1') {
            $memberName = $member->name;
            $memberRepo->deleteByEmail($listing->dbprefix, $email);

            $pendingRepo = new PendingUpdateRepo($this->app->getDatabase());
            $pendingRepo->deleteByListingAndEmail($listingId, $email);

            // Farewell email (best-effort)
            try {
                $cfg       = $this->app->getConfig();
                $mailer    = new Mailer($cfg);
                $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
                $tokens    = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $cfg->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $cfg->get('owner_name', ''),
                    ownerEmail:     (string) $cfg->get('owner_email', ''),
                    fanName:        $memberName,
                    fanEmail:       $email,
                );
                [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_REMOVE, $tokens);
                $mailer->send($email, $memberName, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[Luster] Remove email failed: ' . $e->getMessage());
            }

            $session->remove(self::AUTH_KEY . $listingId);
            $session->remove('lstr_embed_return_to');
            $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'removed', [
                'listing_title' => $listing->title,
                'listing_url'   => $listing->url,
            ]));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Validate update fields only when non-empty
        $v = new Validator($postData);
        if ($newName !== '') {
            $v->field('new_name')->maxLength(100);
        }
        if ($newEmail !== '') {
            $v->field('new_email')->email()->maxLength(255);
        }
        if ($url !== '') {
            $v->field('url')->httpUrl()->maxLength(500);
        }
        if ($country !== '') {
            $v->field('country')->maxLength(100);
        }
        if ($newPassword !== '') {
            PasswordPolicy::applyOptional($v->field('new_password'), $newPassword);
        }

        foreach ($fields as $field) {
            $vf = $v->field('field_' . $field->name)->maxLength(255);
            if ($field->required) {
                $vf->required();
            }
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $old = [
                'new_name'  => $newName,
                'new_email' => $newEmail,
                'url'       => $url,
                'country'   => $country,
            ];
            foreach ($fields as $field) {
                $old['field_' . $field->name] = $postData['field_' . $field->name] ?? '';
            }
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', $old);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // New password confirmation
        if ($newPassword !== '' && $newPassword !== $newPasswordConfirm) {
            $old = ['new_name' => $newName, 'new_email' => $newEmail, 'url' => $url, 'country' => $country];
            foreach ($fields as $field) {
                $old['field_' . $field->name] = $postData['field_' . $field->name] ?? '';
            }
            $session->set('lstr_flash_error', 'New passwords do not match. Please try again.');
            $session->set('lstr_flash_old', $old);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // New email blacklist + uniqueness check
        if ($newEmail !== '' && $newEmail !== $email) {
            $old = ['new_name' => $newName, 'new_email' => $newEmail, 'url' => $url, 'country' => $country];
            foreach ($fields as $field) {
                $old['field_' . $field->name] = $postData['field_' . $field->name] ?? '';
            }
            if ((new BlacklistRepo($this->app->getDatabase()))->isBlocked($newEmail)) {
                $session->set('lstr_flash_error', 'That email address cannot be used.');
                $session->set('lstr_flash_old', $old);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
            }
            if ($memberRepo->getByEmail($listing->dbprefix, $newEmail) !== null) {
                $session->set('lstr_flash_error', 'That email address is already registered for this listing.');
                $session->set('lstr_flash_old', $old);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
            }
        }

        // Resolve final values
        $finalName    = $newName  !== '' ? $newName  : $member->name;
        $finalEmail   = $newEmail !== '' ? $newEmail : $email;
        $finalCountry = $country  !== '' ? $country  : $member->country;

        if ($deleteUrl) {
            $finalUrl = '';
        } elseif ($url !== '') {
            $finalUrl = $url;
        } else {
            $finalUrl = $member->url;
        }

        $updateData = [
            'name'    => $finalName,
            'url'     => $finalUrl,
            'country' => $finalCountry,
            'showurl' => $finalUrl !== '' ? 1 : 0,
        ];

        if ($newEmail !== '' && $newEmail !== $email) {
            $updateData['email'] = $finalEmail;
        }
        if ($newPassword !== '') {
            $updateData['password'] = password_hash($newPassword, PASSWORD_ARGON2ID);
        }

        $prefix = (string) $this->app->getConfig()->get('enth_table_prefix', '');
        foreach ($fields as $field) {
            $colName = ($prefix === '') ? $field->name : 'additional_' . $field->name;
            $raw     = $postData['field_' . $field->name] ?? '';
            $updateData[$colName] = is_array($raw)
                ? implode(', ', array_map('trim', $raw))
                : trim((string) $raw);
        }

        // Password changes always apply immediately (never queued)
        if (isset($updateData['password'])) {
            $memberRepo->update($listing->dbprefix, $email, ['password' => $updateData['password']]);
            unset($updateData['password']);
        }

        // Strip unchanged fields so password-only submissions don't create no-op queue entries
        $currentRow = $memberRepo->getRawRow($listing->dbprefix, $email);
        if ($currentRow !== null) {
            foreach ($updateData as $k => $v) {
                if (array_key_exists($k, $currentRow) && (string) $currentRow[$k] === (string) $v) {
                    unset($updateData[$k]);
                }
            }
        }

        $shouldQueue = $settingsRepo->getQueueUpdates($listingId);

        if ($shouldQueue && $updateData !== []) {
            $pendingRepo = new PendingUpdateRepo($this->app->getDatabase());
            $pendingRepo->insert($listingId, $email, $updateData);

            $session->remove(self::AUTH_KEY . $listingId);
            $session->remove('lstr_embed_return_to');
            $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'updated-pending', [
                'listing_title' => $listing->title,
                'listing_url'   => $listing->url,
            ]));
        } else {
            if ($updateData !== []) {
                $memberRepo->update($listing->dbprefix, $email, $updateData);
            }

            $session->remove(self::AUTH_KEY . $listingId);
            $session->remove('lstr_embed_return_to');
            $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'updated', [
                'listing_title' => $listing->title,
                'listing_url'   => $listing->url,
            ]));
        }

        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Renders the sign-in step (step 1 — email + password only).
     */
    private function renderSignIn(
        int $listingId,
        Listing $listing,
        array $embedSettings,
        array $old = [],
        string $error = '',
    ): Response {
        $baseUrl       = $this->app->getBaseUrl();
        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $wrapperTpl    = $settingsRepo->getWrapperTemplate($listingId) ?? '';
        $globalTemplates = (new SiteSettingsRepo($this->app->getDatabase()))->getGlobalTemplates();
        $updResolved   = ListingTemplateResolver::resolve(
            $settingsRepo->getUpdateFormTemplate($listingId),
            $globalTemplates['listing_default_update_form_template'] ?? null,
            ListingSettingsRepo::defaultUpdateFormTemplate(),
        );
        $updateFormTpl = $updResolved['should_render_template'] ? $updResolved['value'] : '';

        $formAction      = $baseUrl . '/update/' . $listingId . '/sign-in';
        $lostPasswordUrl = $this->buildResetUrl($listing, $embedSettings, $baseUrl, $listingId);

        $fb          = $this->app->getFormFieldBuilder();
        $descriptors = [
            ['type' => 'email',    'name' => 'email',    'id' => 'upd-email',    'label' => 'Email Address', 'required' => true, 'value' => (string) ($old['email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'password', 'name' => 'password', 'id' => 'upd-password', 'label' => 'Password',       'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
        ];
        $f = $fb->build($descriptors, $wrapperTpl);

        $formHtml = null;
        if ($updateFormTpl !== '') {
            $processed = TemplateEngine::conditionals($updateFormTpl, [
                'not_signed_in' => true,
                'signed_in'     => false,
            ]);
            $tokens = [
                '{listing_title}'          => \Luster\Core\View::e((string) $listing->title),
                '{listing_subject}'        => \Luster\Core\View::e((string) $listing->subject),
                '{listing_url}'            => \Luster\Core\View::e((string) $listing->url),
                '{field_signin_email}'     => $f['email'],
                '{field_signin_password}'  => $f['password'],
                '{field_new_name}'         => '',
                '{field_new_email}'        => '',
                '{field_new_url}'          => '',
                '{field_new_country}'      => '',
                '{additional_fields}'      => '',
                '{field_password}'         => '',
                '{field_password_confirm}' => '',
                '{captcha_block}'          => '',
                '{captcha_block_input}'    => '',
                '{captcha_question}'       => '',
                '{field_remove}'           => '',
                '{submit_button}'          => '<button type="submit" class="fl-submit">Sign In</button>',
                '{clear_button}'           => '<button type="reset" class="fl-btn-secondary">Clear</button>',
                '{sign_out_url}'           => '#',
                '{field_email}'            => '',
                '{lost_password_url}'      => \Luster\Core\View::e($lostPasswordUrl),
            ];
            $inner    = FormTemplateRenderer::expandTokens($processed, $tokens);
            $formHtml = FormTemplateRenderer::wrapForm($inner, $formAction, $this->app->getCSRF()->token(), false, formMarker: 'update:' . $listingId);
        }

        $html = $this->app->getView()->renderPublic('update.php', [
            'listing'           => $listing,
            'listing_title'      => $listing->title,
            'listing_url'       => $listing->url,
            'page_title'        => 'Update Membership',
            'signed_in'         => false,
            'sign_in_action'    => $formAction,
            'csrf_token'        => $this->app->getCSRF()->token(),
            'errors'            => $error !== '' ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '',
            'lost_password_url' => $lostPasswordUrl,
            'fields'            => [],
            'f'                 => $f,
            'form_html'         => $formHtml,
            'base_url'          => $baseUrl,
            'theme_path'        => $baseUrl . '/assets/css/default/',
        ]);

        return Response::html($html);
    }

    /**
     * Renders the update form (step 2 — authenticated, pre-filled fields).
     *
     * @param \Luster\Models\AdditionalField[] $fields
     * @param array<string,mixed>              $embedSettings
     * @param array<string,string>             $old
     */
    private function renderUpdateForm(
        int $listingId,
        Listing $listing,
        array $fields,
        array $embedSettings,
        \Luster\Models\Member $member,
        array $old = [],
        string $error = '',
        string $updateError = '',
    ): Response {
        $baseUrl         = $this->app->getBaseUrl();
        $countrySelect   = Countries::buildSelect((string) ($old['country'] ?? ''));
        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $wrapperTpl      = $settingsRepo->getWrapperTemplate($listingId) ?? '';
        $globalTemplates = (new SiteSettingsRepo($this->app->getDatabase()))->getGlobalTemplates();
        $updResolved     = ListingTemplateResolver::resolve(
            $settingsRepo->getUpdateFormTemplate($listingId),
            $globalTemplates['listing_default_update_form_template'] ?? null,
            ListingSettingsRepo::defaultUpdateFormTemplate(),
        );
        $updateFormTpl = $updResolved['should_render_template'] ? $updResolved['value'] : '';

        $formAction = $baseUrl . '/update/' . $listingId;
        $signOutUrl = $baseUrl . '/update/' . $listingId . '/sign-out';

        // URL + "Remove my website URL" checkbox bundled as a single raw_input
        $urlVal = htmlspecialchars(
            (string) (($old['url'] ?? '') !== '' ? $old['url'] : $member->url),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $urlRaw = '<input class="fl-input" type="url" id="upd-url" name="url" value="' . $urlVal . '" maxlength="500" placeholder="https://">'
                . '<div class="fl-field-hint"><label><input type="checkbox" name="delete_url" value="1"> Remove my website URL</label></div>';

        // Merge old/current values for additional fields so pre-fill comes from the member record
        $updOld = [];
        foreach ($fields as $field) {
            $oldVal = $old['field_' . $field->name] ?? null;
            $updOld['field_' . $field->name] = $oldVal !== null
                ? (is_array($oldVal) ? implode(',', array_map('strval', $oldVal)) : (string) $oldVal)
                : (string) ($member->additionalFields[$field->name] ?? '');
        }

        $fb          = $this->app->getFormFieldBuilder();
        $descriptors = [
            ['type' => 'text',     'name' => 'new_name',            'id' => 'upd-name',         'label' => 'New Name',             'required' => false, 'value' => (string) ($old['new_name']  ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name',         'placeholder' => $member->name]],
            ['type' => 'email',    'name' => 'new_email',           'id' => 'upd-new-email',    'label' => 'New Email',            'required' => false, 'value' => (string) ($old['new_email'] ?? ''), 'attrs' => ['maxlength' => '255',                                    'placeholder' => $member->email]],
            ['type' => 'url',      'name' => 'url',                 'id' => 'upd-url',          'label' => 'New Website URL',      'required' => false, 'raw_input' => $urlRaw],
            ['type' => 'text',     'name' => 'country',             'id' => '',                 'label' => 'New Country',          'required' => false, 'raw_input' => $countrySelect],
            ...$fb->descriptorsFromAdditionalFields($fields, $updOld),
            ['type' => 'password', 'name' => 'new_password',        'id' => 'upd-new-pass',     'label' => 'New Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters. Leave blank to keep current.'],
            ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'upd-confirm-pass', 'label' => 'Confirm New Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password', 'placeholder' => 'Repeat new password']],
        ];
        $f = $fb->build($descriptors, $wrapperTpl);

        $formHtml = null;
        if ($updateFormTpl !== '') {
            $additionalFieldsHtml = implode("\n", array_map(
                fn(\Luster\Models\AdditionalField $field) => $f['field_' . $field->name] ?? '',
                $fields,
            ));
            $removeMembershipHtml = '<div class="fl-field fl-field--danger"><label>'
                . '<input type="checkbox" id="upd-delete-membership" name="delete_membership" value="1">'
                . ' Permanently delete my membership instead of updating it</label>'
                . '<p class="fl-field-hint">This immediately and permanently removes your membership from this listing. Cannot be undone.</p></div>';

            $processed = TemplateEngine::conditionals($updateFormTpl, [
                'not_signed_in' => false,
                'signed_in'     => true,
            ]);
            $tokens = [
                '{listing_title}'          => \Luster\Core\View::e((string) $listing->title),
                '{listing_subject}'        => \Luster\Core\View::e((string) $listing->subject),
                '{listing_url}'            => \Luster\Core\View::e((string) $listing->url),
                '{field_signin_email}'     => '',
                '{field_signin_password}'  => '',
                '{field_new_name}'         => $f['new_name'],
                '{field_new_email}'        => $f['new_email'],
                '{field_new_url}'          => $f['url'],
                '{field_new_country}'      => $f['country'],
                '{additional_fields}'      => $additionalFieldsHtml,
                '{field_password}'         => $f['new_password'],
                '{field_password_confirm}' => $f['new_password_confirm'],
                '{captcha_block}'          => '',
                '{captcha_block_input}'    => '',
                '{captcha_question}'       => '',
                '{field_remove}'           => $removeMembershipHtml,
                '{submit_button}'          => '<button type="submit" class="fl-submit">Update Information</button>',
                '{clear_button}'           => '<button type="reset" class="fl-btn-secondary">Clear</button>',
                '{sign_out_url}'           => \Luster\Core\View::e($signOutUrl),
                '{field_email}'            => \Luster\Core\View::e($member->email),
                '{lost_password_url}'      => '',
            ];
            $inner    = FormTemplateRenderer::expandTokens($processed, $tokens);
            $formHtml = FormTemplateRenderer::wrapForm($inner, $formAction, $this->app->getCSRF()->token(), false, formMarker: 'update:' . $listingId);
        }

        $html = $this->app->getView()->renderPublic('update.php', [
            'listing'       => $listing,
            'listing_title'  => $listing->title,
            'listing_url'   => $listing->url,
            'page_title'    => 'Update Membership',
            'signed_in'     => true,
            'sign_out_url'  => $signOutUrl,
            'form_action'   => $formAction,
            'csrf_token'    => $this->app->getCSRF()->token(),
            'errors'        => $error !== '' ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '',
            'field_email'   => $member->email,
            'fields'        => $fields,
            'f'             => $f,
            'form_html'     => $formHtml,
            'base_url'      => $baseUrl,
            'theme_path'    => $baseUrl . '/assets/css/default/',
        ]);

        return Response::html($html);
    }

    private function buildFormUrl(Listing $listing, array $embedSettings, string $baseUrl, int $listingId): string
    {
        $formPageName = (string) ($embedSettings['form_url_update'] ?? '');
        return $formPageName !== ''
            ? rtrim((string) $listing->url, '/') . '/' . ltrim($formPageName, '/')
            : $baseUrl . '/update/' . $listingId;
    }

    private function buildResetUrl(Listing $listing, array $embedSettings, string $baseUrl, int $listingId): string
    {
        $resetPage = (string) ($embedSettings['form_url_reset'] ?? '');
        if ($resetPage !== '') {
            if (preg_match('#^https?://#i', $resetPage)) {
                return $resetPage;
            }
            return rtrim((string) $listing->url, '/') . '/' . ltrim($resetPage, '/');
        }
        return '';
    }
}
