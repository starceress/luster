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
use Luster\Core\Validator;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\SuccessRenderer;

/**
 * Public KIM update flow — sign in then manage all entries.
 *
 * POST /kim/update/sign-in      — authenticate; store session; redirect to dashboard
 * POST /kim/update/save         — apply bulk changes / removals to selected entries
 */
final class KimUpdateController
{
    private const SESSION_AUTH_KEY = 'lstr_kim_update_authed';

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // POST /kim/update/sign-in
    // -----------------------------------------------------------------------

    /**
     * Authenticates a KIM fan by checking their email and password against
     * all their approved member rows. Sets the auth session key on success.
     */
    public function submitSignIn(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $siteUrl       = (string) $this->app->getConfig()->get('site_url', '');
        $fallbackUrl   = CollectiveFormUrlResolver::resolve(
            (new SiteSettingsRepo($this->app->getDatabase()))->get('kim_url_update') ?: 'kim-update.php',
            $siteUrl !== '' ? $siteUrl : $baseUrl,
            $siteUrl !== '' ? $siteUrl : $baseUrl,
        );

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

        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // POST /kim/update/save
    // -----------------------------------------------------------------------

    /**
     * Applies bulk updates and removals submitted from the dashboard.
     *
     * Guards every operation against the authenticated email so fans can only
     * modify their own entries. Sends a per-entry confirmation email and, when
     * the email address changes, clears the auth session to force re-authentication.
     */
    public function submitSave(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $authedEmail   = (string) $session->get(self::SESSION_AUTH_KEY, '');
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $siteUrl       = (string) $this->app->getConfig()->get('site_url', '');
        $kimUpdateUrl  = CollectiveFormUrlResolver::resolve(
            (new SiteSettingsRepo($this->app->getDatabase()))->get('kim_url_update') ?: 'kim-update.php',
            $siteUrl !== '' ? $siteUrl : $baseUrl,
            $siteUrl !== '' ? $siteUrl : $baseUrl,
        );
        $dashboardUrl  = $embedReturnTo !== '' ? $embedReturnTo : $kimUpdateUrl;

        if ($authedEmail === '') {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $kimUpdateUrl);
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
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $kimUpdateUrl);
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
                    listingName:    $listingName,
                    listingUrl:     $listingUrl,
                    siteName:       $siteName,
                    ownerName:      $ownerName,
                    ownerEmail:     $ownerEmail,
                    kimName:        $recipientName,
                    kimEmail:       $recipientEmail,
                    kimUrl:         $newUrl !== '' ? $newUrl : ($member->url ?? ''),
                    listingSubject: $listing->subject ?? '',
                    listingType:    $listing !== null
                        ? (new ListingSettingsRepo($this->app->getDatabase()))->getListingType((int) $listing->id)
                        : 'listing',
                    kimUpdateUrl:   \Luster\Support\CollectiveFormUrlResolver::resolve(
                        (new \Luster\Repositories\SiteSettingsRepo($this->app->getDatabase()))->get('kim_url_update') ?: 'kim-update.php',
                        ((string) $config->get('site_url', '')) ?: (string) $config->get('app_url', ''),
                        ((string) $config->get('site_url', '')) ?: (string) $config->get('app_url', ''),
                    ),
                );
                [$subject, $body] = $emailRepo->resolveForListing(null, EmailTemplateRepo::TYPE_KIM_UPDATE, $tokens);
                if ($subject !== '') {
                    (new Mailer($config))->send($recipientEmail, $recipientName, $subject, $body);
                }
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
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $kimUpdateUrl);
        }

        // Check whether all entries are now gone (updates may not have deleted any,
        // but deletions earlier in this request may have cleared the last ones)
        $remaining = $kimRepo->findAllByEmail($authedEmail);
        if ($remaining === []) {
            $session->remove(self::SESSION_AUTH_KEY);
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-removed'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $kimUpdateUrl);
        }

        $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-updated'));
        return Response::redirect($dashboardUrl);
    }

}
