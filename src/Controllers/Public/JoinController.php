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
use Luster\Models\Listing;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\MemberRepo;

/**
 * Public join form.
 *
 * GET  /join/{id} — renders the join form for a listing.
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
            $session->set('lstr_flash_success', 'Thank you for joining!');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/join/' . $listingId);
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

        $formPageName   = (string) $embedSettings['form_url_join'];
        $formUrl        = $formPageName !== ''
                        ? rtrim((string) $listing->url, '/') . '/' . ltrim($formPageName, '/')
                        : $baseUrl . '/join/' . $listingId;

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'join')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_JOIN);
            $session->set('lstr_flash_error', 'Incorrect answer — please try again.');
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
            return Response::redirect($formUrl);
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
            return Response::redirect($formUrl);
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
                return Response::redirect($formUrl);
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

        // Additional fields — names from FieldRepo only, never raw user input.
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

        // Confirmation email always sent (best-effort — do not abort on failure)
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

        // Admin notification email (best-effort — only when listing has an email address)
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
        $session->set('lstr_flash_success', 'Thank you for joining! Your membership may need to be approved before it appears in the member list.');
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
        $baseUrl = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('join.php', [
            'listing'           => $listing,
            'listing_name'      => $listing->title,
            'listing_url'       => $listing->url,
            'page_title'           => 'Join',
            'form_action'          => $baseUrl . '/join/' . $listingId,
            'csrf_token'           => $this->app->getCSRF()->token(),
            'errors'               => $error !== '' ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '',
            'field_name'           => (string) ($old['name']  ?? ''),
            'field_email'          => (string) ($old['email'] ?? ''),
            'field_url'            => (string) ($old['url']   ?? ''),
            'country_select'       => Countries::buildSelect((string) ($old['country'] ?? '')),
            'fields'               => $fields,
            'old'                  => $old,
            'captcha_question'     => Captcha::getQuestion('join'),
            'captcha_error'        => $captchaError,
            'send_info_checked'    => !isset($old['send_info']) || $old['send_info'] === '1' ? 'checked' : '',
            'base_url'             => $baseUrl,
            'theme_path'           => $baseUrl . '/assets/default/',
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
}
