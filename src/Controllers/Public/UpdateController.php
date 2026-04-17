<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Core\PasswordPolicy;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Models\Listing;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;

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

        return $this->renderUpdateForm($listingId, $listing, $fields, $embedSettings, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /update/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();

        // Rate limiting — prevents credential stuffing and account harvesting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($baseUrl . '/update/' . $listingId);
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/update/' . $listingId . '/success');
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

        $formPageName   = (string) $embedSettings['form_url_update'];
        $formUrl        = $formPageName !== ''
                        ? rtrim((string) $listing->url, '/') . '/' . ltrim($formPageName, '/')
                        : $baseUrl . '/update/' . $listingId;

        // Section 1 — authentication credentials
        $email    = trim((string) $request->post('email',    ''));
        $password = (string) $request->post('password', '');

        // Section 2 — update fields (all optional; blank = keep existing)
        $newName            = trim((string) $request->post('new_name',            ''));
        $newEmail           = trim((string) $request->post('new_email',           ''));
        $newPassword        = (string) $request->post('new_password',        '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');
        $url                = trim((string) $request->post('url',                 ''));
        $country            = trim((string) $request->post('country', ''));
        $deleteUrl          = ((string) $request->post('delete_url', '') === '1');

        $postData = $request->postAll();

        // Captcha — re-render the form with an error on failure
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'update')) {
            $old = [
                'email'     => $email,
                'new_name'  => $newName,
                'new_email' => $newEmail,
                'url'       => $url,
                'country'   => $country,
            ];
            return $this->renderUpdateForm(
                $listingId, $listing, $fields, $embedSettings, $old,
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
            $flashOld = [
                'email'     => $email,
                'new_name'  => $newName,
                'new_email' => $newEmail,
                'url'       => $url,
                'country'   => $country,
            ];
            foreach ($fields as $field) {
                $raw = $postData['field_' . $field->name] ?? '';
                $flashOld['field_' . $field->name] = $raw;
            }
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', $flashOld);
            return Response::redirect($formUrl);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($listing->dbprefix, $email);

        if ($member === null) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', 'No membership found for that email address.');
            $session->set('lstr_flash_old', ['email' => $email]);
            return Response::redirect($formUrl);
        }

        // Verify password — detect and upgrade legacy MD5 hashes
        $storedHash = $member->password;
        $verified   = false;

        if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
            // Legacy MD5 — verify then re-hash with Argon2id
            if (hash_equals($storedHash, md5($password))) {
                $verified = true;
                $newHash  = password_hash($password, PASSWORD_ARGON2ID);
                $memberRepo->update($listing->dbprefix, $email, ['password' => $newHash]);
            }
        } else {
            $verified = password_verify($password, $storedHash);
        }

        if (!$verified) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', 'Incorrect password. Please try again.');
            $session->set('lstr_flash_old', ['email' => $email]);
            return Response::redirect($formUrl);
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
                $listingId, $listing, $fields, $embedSettings, $old,
                '', '', 'New passwords do not match. Please try again.',
            );
        }

        // New email blacklist + uniqueness check — only when the address is actually changing
        if ($newEmail !== '' && $newEmail !== $email) {
            if ((new BlacklistRepo($this->app->getDatabase()))->isBlocked($newEmail)) {
                $old = [
                    'email'     => $email,
                    'new_name'  => $newName,
                    'new_email' => $newEmail,
                    'url'       => $url,
                    'country'   => $country,
                ];
                return $this->renderUpdateForm(
                    $listingId, $listing, $fields, $embedSettings, $old,
                    '', '', 'That email address cannot be used.',
                );
            }
        }

        // New email uniqueness check — only when the address is actually changing
        if ($newEmail !== '' && $newEmail !== $email) {
            if ($memberRepo->getByEmail($listing->dbprefix, $newEmail) !== null) {
                $old = [
                    'email'     => $email,
                    'new_name'  => $newName,
                    'new_email' => $newEmail,
                    'url'       => $url,
                    'country'   => $country,
                ];
                return $this->renderUpdateForm(
                    $listingId, $listing, $fields, $embedSettings, $old,
                    '', '', 'That email address is already registered for this listing.',
                );
            }
        }

        // Resolve final values — blank submitted field means keep existing value
        $finalName    = $newName  !== '' ? $newName  : $member->name;
        $finalEmail   = $newEmail !== '' ? $newEmail : $email;
        $finalCountry = $country !== '' ? $country : $member->country;

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
            $colName   = ($prefix === '') ? $field->name : 'additional_' . $field->name;
            $raw       = $postData['field_' . $field->name] ?? '';
            $updateData[$colName] = is_array($raw)
                ? implode(', ', array_map('trim', $raw))
                : trim((string) $raw);
        }

        // Apply new password immediately (auth-critical; don't queue it)
        if (isset($updateData['password'])) {
            $memberRepo->update($listing->dbprefix, $email, ['password' => $updateData['password']]);
            unset($updateData['password']);
        }

        // Apply email change immediately — email is the member key, so deferring
        // it would leave the pending record unreachable if the email changes.
        if (isset($updateData['email'])) {
            $memberRepo->update($listing->dbprefix, $email, ['email' => $updateData['email']]);
            unset($updateData['email']);
        }

        // Queue the remaining field changes for admin review.
        $pendingRepo = new PendingUpdateRepo($this->app->getDatabase());
        $pendingRepo->insert($listingId, $finalEmail, $updateData);

        $session->set('lstr_flash_pending', '1');

        return Response::redirect($baseUrl . '/update/' . $listingId . '/success');
    }

    // -----------------------------------------------------------------------
    // GET /update/{id}/success
    // -----------------------------------------------------------------------

    public function success(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        $listingName = $listing?->name ?? 'this listing';
        $baseUrl        = $this->app->getBaseUrl();
        $session        = $this->app->getSession();

        $isPending = $session->get('lstr_flash_pending') === '1';
        $session->remove('lstr_flash_pending');

        $html = $this->app->getView()->renderPublic('success.php', [
            'listing'      => $listing,
            'listing_name' => $listingName,
            'listing_url'  => $listing?->url ?? '',
            'page_title'      => $isPending ? 'Update Pending' : 'Membership Updated',
            'success_heading' => $isPending ? 'Update Submitted' : 'Membership Updated',
            'success_message' => $isPending
                ? "Your update for the {$listingName} listing has been submitted and is awaiting admin review. Changes will appear once approved."
                : "Your membership in the {$listingName} listing has been updated successfully.",
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
        int $listingId,
        Listing $listing,
        array $fields,
        array $embedSettings,
        array $old = [],
        string $error = '',
        string $captchaError = '',
        string $updateError = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('update.php', [
            'listing'           => $listing,
            'listing_name'      => $listing->name,
            'listing_url'       => $listing->url,
            'page_title'           => 'Update Membership',
            'form_action'          => $baseUrl . '/update/' . $listingId,
            'csrf_token'           => $this->app->getCSRF()->token(),
            'errors'               => $error !== '' ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '',
            'update_error'         => $updateError,
            'field_email'          => (string) ($old['email']     ?? ''),
            'field_name'           => (string) ($old['new_name']  ?? ''),
            'field_new_email'      => (string) ($old['new_email'] ?? ''),
            'field_url'            => (string) ($old['url']       ?? ''),
            'country_select'       => Countries::buildSelect((string) ($old['country'] ?? '')),
            'fields'               => $fields,
            'old'                  => $old,
            'captcha_question'     => Captcha::getQuestion('update'),
            'captcha_error'        => $captchaError,
            'lost_password_url'    => $this->buildResetUrl($listing, $embedSettings, $baseUrl, $listingId),
            'base_url'             => $baseUrl,
            'theme_path'           => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    /**
     * Builds the URL for the "Forgot your password?" link in the update form.
     *
     * Uses the admin-configured reset page filename (form_url_reset) combined
     * with the listing URL. Returns an empty string if not configured, which
     * causes the template to hide the link entirely.
     */
    private function buildResetUrl(Listing $listing, array $embedSettings, string $baseUrl, int $listingId): string
    {
        $resetPage = (string) ($embedSettings['form_url_reset'] ?? '');

        if ($resetPage !== '') {
            return rtrim((string) $listing->url, '/') . '/' . ltrim($resetPage, '/');
        }

        return '';
    }
}
