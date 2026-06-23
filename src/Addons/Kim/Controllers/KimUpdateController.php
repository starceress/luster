<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public controller for the KIM update flow. Fans sign in with their email
 and password to reach a dashboard showing all their KIM entries across
 every fanlisting. From there they can update their details, change their
 password, or remove individual entries. Session-based authentication
 persists across requests until the fan signs out.
*/

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\TemplateEngine;
use Luster\Core\Validator;
use Luster\Core\View;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\SuccessRenderer;

/**
 * Public KIM update flow — two-step: sign in then manage all entries.
 *
 * GET  /kim/update              — step 1: sign-in form (email + password)
 * POST /kim/update/sign-in      — authenticate; store session; redirect to dashboard
 * GET  /kim/update/sign-out     — clear auth session; redirect to sign-in form
 * GET  /kim/update/dashboard    — step 2: show all KIM entries for authed email
 * POST /kim/update/save         — apply bulk changes / removals to selected entries
 */
final class KimUpdateController
{
    private const SESSION_AUTH_KEY = 'lstr_kim_update_authed';

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /kim/update — step 1: sign-in form
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();

        // Already authenticated → go straight to dashboard
        if ((string) $session->get(self::SESSION_AUTH_KEY, '') !== '') {
            return Response::redirect($this->app->getBaseUrl() . '/kim/update/dashboard');
        }

        $error    = (string) $session->get('lstr_flash_error', '');
        $oldEmail = (string) $session->get('lstr_flash_old_email', '');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old_email');

        return $this->renderSignInForm($oldEmail, $error);
    }

    // -----------------------------------------------------------------------
    // POST /kim/update/sign-in
    // -----------------------------------------------------------------------

    public function submitSignIn(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $fallbackUrl   = $baseUrl . '/kim/update';

        if ($rateLimit->isBlocked($ip, 'kim_update')) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-updated'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $email    = trim((string) $request->post('email',    ''));
        $password = (string)      $request->post('password', '');

        $v = new Validator(['email' => $email, 'password' => $password]);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('password')->required();

        if ($v->fails()) {
            $rateLimit->hit($ip, 'kim_update', 5, 3600);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $kimRepo = new KimMemberRepo($this->app->getDatabase());
        $members = $kimRepo->findAllByEmail($email);

        // Find any approved member with a matching password
        $authenticated = false;
        foreach ($members as $member) {
            if (!$member->pending && password_verify($password, $member->password)) {
                $authenticated = true;
                break;
            }
        }

        if (!$authenticated) {
            $rateLimit->hit($ip, 'kim_update', 5, 3600);
            $session->set('lstr_flash_error', 'No matching entry found, or incorrect password.');
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $rateLimit->clear($ip, 'kim_update');
        $session->set(self::SESSION_AUTH_KEY, $email);

        $dashboardUrl = $embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/kim/update/dashboard';
        return Response::redirect($dashboardUrl);
    }

    // -----------------------------------------------------------------------
    // GET /kim/update/sign-out
    // -----------------------------------------------------------------------

    public function signOut(Request $request, array $params): Response
    {
        $session       = $this->app->getSession();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $session->remove(self::SESSION_AUTH_KEY);
        $fallbackUrl = $this->app->getBaseUrl() . '/kim/update';
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // GET /kim/update/dashboard — step 2
    // -----------------------------------------------------------------------

    public function showDashboard(Request $request, array $params): Response
    {
        $session    = $this->app->getSession();
        $authedEmail = (string) $session->get(self::SESSION_AUTH_KEY, '');
        $baseUrl    = $this->app->getBaseUrl();

        if ($authedEmail === '') {
            return Response::redirect($baseUrl . '/kim/update');
        }

        $successHtml = (string) $session->get('lstr_flash_success_html', '');
        $error       = (string) $session->get('lstr_flash_error',        '');
        $session->remove('lstr_flash_success_html');
        $session->remove('lstr_flash_error');

        $kimRepo     = new KimMemberRepo($this->app->getDatabase());
        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $members     = $kimRepo->findAllByEmail($authedEmail);

        // If no entries remain (e.g. all removed, or email changed externally), sign out
        if ($members === []) {
            $session->remove(self::SESSION_AUTH_KEY);
            return Response::redirect($baseUrl . '/kim/update');
        }

        $entries = [];
        foreach ($members as $member) {
            $listing       = $listingRepo->getById($member->fanlistingId);
            $entries[] = [
                'id'           => $member->id,
                'fanlisting_id'=> $member->fanlistingId,
                'listing_title' => $listing !== null
                    ? ($listing->subject ?: $listing->title)
                    : 'Listing #' . $member->fanlistingId,
                'name'         => $member->name,
                'url'          => $member->url ?? '',
                'email'        => $member->email,
                'pending'      => $member->pending,
            ];
        }

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'text',     'name' => 'new_name',             'id' => 'kim-new-name',             'label' => 'New Name',             'required' => false, 'attrs' => ['maxlength' => '100'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'email',    'name' => 'new_email',            'id' => 'kim-new-email',            'label' => 'New Email',            'required' => false, 'attrs' => ['maxlength' => '255'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'url',      'name' => 'new_url',              'id' => 'kim-new-url',              'label' => 'New Website URL',      'required' => false, 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://'], 'hint' => 'Leave blank to keep current'],
            ['type' => 'password', 'name' => 'new_password',         'id' => 'kim-new-password',         'label' => 'New Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters. Leave blank to keep current.'],
            ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'kim-new-password-confirm', 'label' => 'Confirm New Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
        ], '');

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $kimUrls  = $siteRepo->getMany(['kim_url_reset', 'kim_url_list']);
        $kimParsed   = parse_url((string) ($this->app->getConfig()->get('site_url', '')));
        $kimSiteRoot = ($kimParsed['scheme'] ?? 'https') . '://' . ($kimParsed['host'] ?? '');
        if (!empty($kimParsed['port'])) {
            $kimSiteRoot .= ':' . $kimParsed['port'];
        }
        $toLink = static fn(?string $v): ?string =>
            ($v !== null && $v !== '') ? $kimSiteRoot . '/' . ltrim($v, '/') : null;

        return $this->renderKimPage('update-dashboard.php', [
            'page_title'    => 'KIM — Manage Your Entries',
            'auth_email'    => $authedEmail,
            'entries'       => $entries,
            'save_action'   => $baseUrl . '/kim/update/save',
            'sign_out_url'  => $baseUrl . '/kim/update/sign-out',
            'csrf_token'    => $this->app->getCSRF()->token(),
            'errors'        => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'success_html'  => $successHtml,
            'f'             => $f,
            'link_reset'    => $toLink($kimUrls['kim_url_reset'] ?? null, 'kim-reset.php'),
            'link_list'     => $toLink($kimUrls['kim_url_list']  ?? null, 'kim-list.php'),
            'base_url'      => $baseUrl,
            'theme_path'    => $baseUrl . '/assets/css/default/',
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /kim/update/save
    // -----------------------------------------------------------------------

    public function submitSave(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $authedEmail   = (string) $session->get(self::SESSION_AUTH_KEY, '');
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $dashboardUrl  = $embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/kim/update/dashboard';

        if ($authedEmail === '') {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/kim/update');
        }

        // Honeypot
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($dashboardUrl);
        }

        $kimRepo     = new KimMemberRepo($this->app->getDatabase());
        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());

        // Collect IDs owned by this authenticated email (security guard)
        $ownedMembers = $kimRepo->findAllByEmail($authedEmail);
        $ownedIds     = array_map(static fn($m) => $m->id, $ownedMembers);

        // Removals (Remove? column in the merged form)
        $submittedRemoveIds = array_map('intval', (array) $request->post('remove_ids', []));
        $deleteIds          = array_values(array_intersect($submittedRemoveIds, $ownedIds));

        // Updates — exclude entries that are also marked for removal
        $submittedIds = array_map('intval', (array) $request->post('entry_ids', []));
        $targetIds    = array_values(array_diff(array_intersect($submittedIds, $ownedIds), $deleteIds));

        $newName            = trim((string) $request->post('new_name',             ''));
        $newUrl             = trim((string) $request->post('new_url',              ''));
        $newEmail           = trim((string) $request->post('new_email',            ''));
        $newPassword        = (string)      $request->post('new_password',         '');
        $newPasswordConfirm = (string)      $request->post('new_password_confirm', '');

        $hasFieldChanges = $newName !== '' || $newUrl !== '' || $newEmail !== '' || $newPassword !== '';

        if ($deleteIds === [] && ($targetIds === [] || !$hasFieldChanges)) {
            $session->set('lstr_flash_error', ($targetIds === [] && $deleteIds === []) ? 'No entries selected.' : 'No changes were submitted.');
            return Response::redirect($dashboardUrl);
        }

        // Validate update fields (only when there are entries to update)
        if ($targetIds !== [] && $hasFieldChanges) {
            if ($newUrl !== '') {
                $vUrl = new Validator(['new_url' => $newUrl]);
                $vUrl->field('new_url')->httpUrl()->maxLength(500);
                if ($vUrl->fails()) {
                    $session->set('lstr_flash_error', implode(' ', $vUrl->allErrors()));
                    return Response::redirect($dashboardUrl);
                }
            }

            if ($newEmail !== '') {
                $vEmail = new Validator(['new_email' => $newEmail]);
                $vEmail->field('new_email')->email()->maxLength(255);
                if ($vEmail->fails()) {
                    $session->set('lstr_flash_error', implode(' ', $vEmail->allErrors()));
                    return Response::redirect($dashboardUrl);
                }
            }

            if ($newPassword !== '') {
                $vPw = new Validator(['new_password' => $newPassword]);
                PasswordPolicy::apply($vPw->field('new_password'));
                if ($vPw->fails()) {
                    $session->set('lstr_flash_error', implode(' ', $vPw->allErrors()));
                    return Response::redirect($dashboardUrl);
                }
                if ($newPassword !== $newPasswordConfirm) {
                    $session->set('lstr_flash_error', 'New passwords do not match.');
                    return Response::redirect($dashboardUrl);
                }
            }
        }

        // Delete entries marked for removal first
        if ($deleteIds !== []) {
            $kimRepo->deleteByIds($deleteIds);
        }

        // If no entries remain to update, check whether we're already done
        if ($targetIds === [] || !$hasFieldChanges) {
            $remaining = $kimRepo->findAllByEmail($authedEmail);
            if ($remaining === []) {
                $session->remove(self::SESSION_AUTH_KEY);
                $session->remove('lstr_embed_return_to');
                $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-removed'));
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/kim/update');
            }
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-updated'));
            return Response::redirect($dashboardUrl);
        }

        // For email changes: check for conflicts across all target entries
        $emailConflict = false;
        if ($newEmail !== '' && $newEmail !== $authedEmail) {
            foreach ($targetIds as $tid) {
                // Find this member to get its fanlisting_id
                foreach ($ownedMembers as $m) {
                    if ($m->id === $tid) {
                        if ($kimRepo->findByEmailAndFanlisting($newEmail, $m->fanlistingId) !== null) {
                            $emailConflict = true;
                        }
                        break;
                    }
                }
                if ($emailConflict) {
                    break;
                }
            }
            if ($emailConflict) {
                $session->set('lstr_flash_error', 'That email address is already registered for one or more of the selected fanlistings. Email not changed.');
                // Clear newEmail so the rest of the update still proceeds
                $newEmail = '';
            }
        }

        // Apply updates
        $config    = $this->app->getConfig();
        $siteName  = (string) $config->get('site_name', '');
        $ownerName  = (string) $config->get('owner_name',  '');
        $ownerEmail = (string) $config->get('owner_email', '');
        $emailRepo  = new EmailTemplateRepo($this->app->getDatabase());

        foreach ($targetIds as $tid) {
            $member = null;
            foreach ($ownedMembers as $m) {
                if ($m->id === $tid) {
                    $member = $m;
                    break;
                }
            }
            if ($member === null) {
                continue;
            }

            $fields = [];
            if ($newName !== '')    $fields['name']     = $newName;
            if ($newUrl !== '')     $fields['url']       = $newUrl;
            if ($newEmail !== '')   $fields['email']     = $newEmail;
            if ($newPassword !== '') $fields['password'] = password_hash($newPassword, PASSWORD_ARGON2ID);

            if ($fields !== []) {
                $kimRepo->update($tid, $fields);
            }

            // Send update confirmation email
            try {
                $listing     = $listingRepo->getById($member->fanlistingId);
                $listingName = $listing !== null ? ($listing->subject ?: $listing->title) : $siteName;
                $listingUrl  = $listing !== null ? $listing->url : (string) $this->app->getConfig()->get('site_url', '');
                $recipientEmail = $newEmail !== '' ? $newEmail : $member->email;
                $recipientName  = $newName  !== '' ? $newName  : $member->name;

                $tokens = EmailTemplateRepo::buildKimTokens(
                    listingName: $listingName,
                    listingUrl:  $listingUrl,
                    siteName:    $siteName,
                    ownerName:   $ownerName,
                    ownerEmail:  $ownerEmail,
                    kimName:     $recipientName,
                    kimEmail:    $recipientEmail,
                    kimUrl:      $newUrl !== '' ? $newUrl : ($member->url ?? ''),
                );
                [$subject, $body] = $emailRepo->resolveForListing(null, EmailTemplateRepo::TYPE_KIM_UPDATE, $tokens);
                (new Mailer($config))->send($recipientEmail, $recipientName, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[KIM] Update confirmation email failed: ' . $e->getMessage());
            }
        }

        // If email changed, the auth session is now stale — clear it so the user re-authenticates
        if ($newEmail !== '' && $newEmail !== $authedEmail) {
            $session->remove(self::SESSION_AUTH_KEY);
            $session->set(
                'lstr_flash_success_html',
                SuccessRenderer::render('Your entries were updated. Please sign in again with your new email address.', 'kim-updated'),
            );
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/kim/update');
        }

        // Check whether all entries are now gone (updates may not have deleted any,
        // but deletions earlier in this request may have cleared the last ones)
        $remaining = $kimRepo->findAllByEmail($authedEmail);
        if ($remaining === []) {
            $session->remove(self::SESSION_AUTH_KEY);
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-removed'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/kim/update');
        }

        $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-updated'));
        return Response::redirect($dashboardUrl);
    }

    // -----------------------------------------------------------------------
    // Internals — continued
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function renderSignInForm(string $oldEmail = '', string $error = ''): Response
    {
        $baseUrl  = $this->app->getBaseUrl();
        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'email',    'name' => 'email',    'id' => 'kim-email',    'label' => 'Email',    'required' => true, 'value' => $oldEmail, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'password', 'name' => 'password', 'id' => 'kim-password', 'label' => 'Password', 'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
        ], '');

        $kimUrls  = $siteRepo->getMany(['kim_url_reset', 'kim_url_list']);
        $kimParsed   = parse_url((string) ($this->app->getConfig()->get('site_url', '')));
        $kimSiteRoot = ($kimParsed['scheme'] ?? 'https') . '://' . ($kimParsed['host'] ?? '');
        if (!empty($kimParsed['port'])) {
            $kimSiteRoot .= ':' . $kimParsed['port'];
        }
        $toLink = static fn(?string $v, string $default = ''): ?string =>
            (($v !== null && $v !== '') || $default !== '')
                ? $kimSiteRoot . '/' . ltrim($v !== null && $v !== '' ? $v : $default, '/')
                : null;

        $resolvedResetUrl = $toLink($kimUrls['kim_url_reset'] ?? null, 'kim-reset.php');

        $formHtml  = null;
        $updateTpl = $siteRepo->get('kim_update_form_template') ?? '';
        if ($updateTpl !== '') {
            $tpl    = TemplateEngine::conditionals($updateTpl, ['lost_password_url' => true]);
            $tokens = [
                '{site_name}'                        => View::e((string) $this->app->getConfig()->get('site_name', 'Collective')),
                '{field_email}'                      => $f['email'],
                '{field_email_input}'                => $f['email_input'],
                '{field_password}'                   => $f['password'],
                '{field_password_input}'             => $f['password_input'],
                '{submit_button}'                    => '<button type="submit" class="fl-submit">Sign In</button>',
                '{clear_button}'                     => '<button type="reset" class="fl-btn-secondary">Clear</button>',
                '{lost_password_url}'                => View::e($resolvedResetUrl),
            ];
            $innerHtml = FormTemplateRenderer::expandTokens($tpl, $tokens);
            $formHtml  = FormTemplateRenderer::wrapForm($innerHtml, $baseUrl . '/kim/update/sign-in', $this->app->getCSRF()->token(), false, formMarker: 'kim_update_auth');
        }

        return $this->renderKimPage('update.php', [
            'page_title'  => 'KIM — Sign In',
            'form_action' => $baseUrl . '/kim/update/sign-in',
            'csrf_token'  => $this->app->getCSRF()->token(),
            'errors'      => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'f'           => $f,
            'form_html'   => $formHtml,
            'link_reset'  => $toLink($kimUrls['kim_url_reset'] ?? null, 'kim-reset.php'),
            'link_list'   => $toLink($kimUrls['kim_url_list']  ?? null, 'kim-list.php'),
            'base_url'    => $baseUrl,
            'theme_path'  => $baseUrl . '/assets/css/default/',
        ]);
    }

    private function renderKimPage(string $template, array $data): Response
    {
        $view     = $this->app->getView();
        $baseUrl  = $this->app->getBaseUrl();
        $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');

        $content = $view->render('default/addons/kim/' . $template, $data);

        $layoutData = array_merge($data, [
            'content'      => $content,
            'listing'      => null,
            'listing_title' => $siteName,
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
