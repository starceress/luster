<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public controller for the KIM update flow. Fans sign in with their email
 and password to reach a dashboard showing the KIM entries that use that
 password, across every fanlisting. From there they can update their details, change their
 password, or remove individual entries. Session-based authentication
 persists across requests until the fan signs out.
*/

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Addons\Kim\KimPasswordResetRepo;
use Luster\Addons\Kim\KimSignIn;
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
use Luster\Support\MemberChangeList;
use Luster\Support\OwnerNotice;
use Luster\Support\SuccessRenderer;

/**
 * Public KIM update flow — sign in then manage all entries.
 *
 * POST /kim/update/sign-in      — authenticate; store session; redirect to dashboard
 * POST /kim/update/save         — apply bulk changes / removals to selected entries
 */
final class KimUpdateController
{
    private const CHANGED_MESSAGE = 'Something about these entries changed, so nothing was saved. Please check them and try again.';

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // POST /kim/update/sign-in
    // -----------------------------------------------------------------------

    /**
     * Authenticates a KIM fan by checking their email and password against
     * every entry at that address. It needs an approved match, as it always has,
     * and the sign-in only reaches the entries the password matched (KimSignIn).
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
            $session->set('lstr_flash_error:kim_update', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->set('lstr_flash_success_html:kim_update', SuccessRenderer::render('', 'kim-updated'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $email    = trim((string) $request->post('email',    ''));
        $password = (string)      $request->post('password', '');

        $v = new Validator(['email' => $email, 'password' => $password]);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('password')->required();

        if ($v->fails()) {
            $rateLimit->hit($ip, 'kim_update', 5, 3600);
            $session->set('lstr_flash_error:kim_update', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old_email:kim_update', $email);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $kimRepo = new KimMemberRepo($this->app->getDatabase());
        $members = $kimRepo->findAllByEmail($email);

        // Entries sharing one hash are only checked once.
        $matched       = [];
        $checked       = [];
        $authenticated = false;
        foreach ($members as $member) {
            $checked[$member->password] ??= password_verify($password, $member->password);
            if ($checked[$member->password]) {
                $matched[]     = $member;
                $authenticated = $authenticated || !$member->pending;
            }
        }

        if (!$authenticated) {
            $rateLimit->hit($ip, 'kim_update', 5, 3600);
            $session->set('lstr_flash_error:kim_update', 'No matching entry found, or incorrect password.');
            $session->set('lstr_flash_old_email:kim_update', $email);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $rateLimit->clear($ip, 'kim_update');
        KimSignIn::remember($session, $email, $matched);

        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // POST /kim/update/save
    // -----------------------------------------------------------------------

    /**
     * Applies bulk updates and removals submitted from the dashboard.
     *
     * Every submitted id has to be an entry this sign-in can reach, and if any isn't,
     * nothing is saved. The entries being written are locked and checked again before
     * the writes and stay locked through them, because an entry's id staying the same
     * doesn't stop it changing hands mid-save. Mail goes after the commit. When the
     * email address changes, the auth session is cleared to force re-authentication.
     */
    public function submitSave(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $authedEmail   = KimSignIn::email($session);
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

        $db          = $this->app->getDatabase();
        $kimRepo     = new KimMemberRepo($db);
        $listingRepo = new ListingRepo($db, $this->app->getConfig());

        // The entries this sign-in can still reach (security guard)
        $reachable = KimSignIn::currentEntries($kimRepo, $session);
        if ($reachable === []) {
            $session->set('lstr_flash_error:kim_update', KimSignIn::SIGN_IN_AGAIN);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $kimUpdateUrl);
        }
        $reachableIds = array_map(static fn($m) => $m->id, $reachable);

        // Removals (Remove? column in the merged form)
        $deleteIds = array_values(array_unique(array_map('intval', (array) $request->post('remove_ids', []))));

        // Updates — exclude entries that are also marked for removal
        $submittedIds = array_values(array_unique(array_map('intval', (array) $request->post('entry_ids', []))));
        $targetIds    = array_values(array_diff($submittedIds, $deleteIds));

        // The ids as submitted. One that can't be reached refuses the whole save,
        // rather than quietly saving the rest.
        if (array_diff(array_merge($deleteIds, $submittedIds), $reachableIds) !== []) {
            $session->set('lstr_flash_error:kim_update', self::CHANGED_MESSAGE);
            return Response::redirect($dashboardUrl);
        }

        $newName            = trim((string) $request->post('new_name',             ''));
        $newUrl             = trim((string) $request->post('new_url',              ''));
        $newEmail           = trim((string) $request->post('new_email',            ''));
        $newPassword        = (string)      $request->post('new_password',         '');
        $newPasswordConfirm = (string)      $request->post('new_password_confirm', '');

        $hasFieldChanges = $newName !== '' || $newUrl !== '' || $newEmail !== '' || $newPassword !== '';

        if ($deleteIds === [] && ($targetIds === [] || !$hasFieldChanges)) {
            $session->set('lstr_flash_error:kim_update', ($targetIds === [] && $deleteIds === []) ? 'No entries selected.' : 'No changes were submitted.');
            return Response::redirect($dashboardUrl);
        }

        // Validate update fields (only when there are entries to update)
        if ($targetIds !== [] && $hasFieldChanges) {
            if ($newUrl !== '') {
                $vUrl = new Validator(['new_url' => $newUrl]);
                $vUrl->field('new_url')->httpUrl()->maxLength(500);
                if ($vUrl->fails()) {
                    $session->set('lstr_flash_error:kim_update', implode(' ', $vUrl->allErrors()));
                    return Response::redirect($dashboardUrl);
                }
            }

            if ($newEmail !== '') {
                $vEmail = new Validator(['new_email' => $newEmail]);
                $vEmail->field('new_email')->email()->maxLength(255);
                if ($vEmail->fails()) {
                    $session->set('lstr_flash_error:kim_update', implode(' ', $vEmail->allErrors()));
                    return Response::redirect($dashboardUrl);
                }
            }

            if ($newPassword !== '') {
                $vPw = new Validator(['new_password' => $newPassword]);
                PasswordPolicy::apply($vPw->field('new_password'));
                if ($vPw->fails()) {
                    $session->set('lstr_flash_error:kim_update', implode(' ', $vPw->allErrors()));
                    return Response::redirect($dashboardUrl);
                }
                if ($newPassword !== $newPasswordConfirm) {
                    $session->set('lstr_flash_error:kim_update', 'New passwords do not match.');
                    return Response::redirect($dashboardUrl);
                }
            }
        }

        $updateTargets = $hasFieldChanges ? $targetIds : [];
        $newHash       = ($updateTargets !== [] && $newPassword !== '') ? password_hash($newPassword, PASSWORD_ARGON2ID) : null;
        $locked        = [];

        $db->beginTransaction();
        try {
            $locked = $kimRepo->lockByIds(array_merge($deleteIds, $updateTargets));

            foreach (array_merge($deleteIds, $updateTargets) as $id) {
                if (!isset($locked[$id]) || !KimSignIn::canReach($locked[$id], $session)) {
                    $db->rollBack();
                    KimSignIn::currentEntries($kimRepo, $session);
                    $session->set('lstr_flash_error:kim_update', self::CHANGED_MESSAGE);
                    return Response::redirect($dashboardUrl);
                }
            }

            // For email changes: check for conflicts across all target entries
            if ($newEmail !== '' && $newEmail !== $authedEmail) {
                foreach ($updateTargets as $tid) {
                    $holder = $kimRepo->findByEmailAndFanlisting($newEmail, $locked[$tid]->fanlistingId);
                    if ($holder !== null && $holder->id !== $tid) {
                        $session->set('lstr_flash_error:kim_update', 'That email address is already registered for one or more of the selected fanlistings. Email not changed.');
                        // Clear newEmail so the rest of the update still proceeds
                        $newEmail = '';
                        break;
                    }
                }
            }

            // Delete entries marked for removal first
            $kimRepo->deleteByIds($deleteIds);

            // Apply updates
            $resetRepo = new KimPasswordResetRepo($db);
            foreach ($updateTargets as $tid) {
                $fields = [];
                if ($newName !== '')    $fields['name']     = $newName;
                if ($newUrl !== '')     $fields['url']       = $newUrl;
                if ($newEmail !== '')   $fields['email']     = $newEmail;
                if ($newHash !== null)  $fields['password'] = $newHash;

                if ($fields !== []) {
                    $kimRepo->update($tid, $fields);
                }

                // A link for the old address mustn't work once the entry has moved.
                if ($newEmail !== '' && mb_strtolower($newEmail, 'UTF-8') !== mb_strtolower($locked[$tid]->email, 'UTF-8')) {
                    $resetRepo->deleteByMember($tid);
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->pdo()->inTransaction()) {
                $db->rollBack();
            }
            error_log('[KIM] Saving a fan\'s entries failed: ' . $e->getMessage());
            $session->set('lstr_flash_error:kim_update', "Couldn't save your changes because of a database error. Nothing was changed.");
            return Response::redirect($dashboardUrl);
        }

        $stillReaches = KimSignIn::afterSave($session, $deleteIds, $updateTargets, $newHash);

        // Send the confirmation emails now the changes are saved
        $config    = $this->app->getConfig();
        $siteName  = (string) $config->get('site_name', '');
        $ownerName  = (string) $config->get('owner_name',  '');
        $ownerEmail = (string) $config->get('owner_email', '');
        $emailRepo  = new EmailTemplateRepo($db);

        foreach ($updateTargets as $tid) {
            $member = $locked[$tid];

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

        $this->notifyOwner($locked, $deleteIds, $updateTargets, $newName, $newUrl, $newEmail, $newHash !== null, $listingRepo);

        // If email changed, the auth session is now stale — clear it so the user re-authenticates
        if ($updateTargets !== [] && $newEmail !== '' && $newEmail !== $authedEmail) {
            KimSignIn::forget($session);
            $session->set(
                'lstr_flash_success_html:kim_update',
                SuccessRenderer::render('Your entries were updated. Please sign in again with your new email address.', 'kim-updated'),
            );
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $kimUpdateUrl);
        }

        // Check whether every entry this sign-in reached is now gone
        if (!$stillReaches) {
            KimSignIn::forget($session);
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html:kim_update', SuccessRenderer::render('', 'kim-removed'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $kimUpdateUrl);
        }

        $session->set('lstr_flash_success_html:kim_update', SuccessRenderer::render('', 'kim-updated'));
        return Response::redirect($dashboardUrl);
    }

    /**
     * Tells the owner what this save did (best-effort: it's already committed). One save
     * can touch entries on many listings, so each address gets one email about its entries.
     *
     * @param array<int, object> $locked        The entries as they were before the save, by id.
     * @param list<int>          $deleteIds
     * @param list<int>          $updateTargets
     */
    private function notifyOwner(
        array $locked,
        array $deleteIds,
        array $updateTargets,
        string $newName,
        string $newUrl,
        string $newEmail,
        bool $passwordChanged,
        ListingRepo $listingRepo,
    ): void {
        $notice = OwnerNotice::for($this->app);
        $what   = 'KIM update notice';

        try {
            $entries = [];
            foreach ($deleteIds as $id) {
                $entries[] = $this->ownerEntry($locked[$id], [MemberChangeList::REMOVED_KIM_ENTRY], $listingRepo);
            }
            foreach ($updateTargets as $id) {
                $member  = $locked[$id];
                $changes = array_filter(
                    ['name' => $newName, 'url' => $newUrl, 'email' => $newEmail],
                    static fn(string $value, string $column): bool => $value !== '' && $value !== (string) ($member->{$column} ?? ''),
                    ARRAY_FILTER_USE_BOTH,
                );
                $entries[] = $this->ownerEntry(
                    $member,
                    MemberChangeList::lines($changes, ['name' => $member->name, 'url' => $member->url ?? '', 'email' => $member->email], [], $passwordChanged),
                    $listingRepo,
                );
            }

            $config   = $this->app->getConfig();
            $siteName = (string) $config->get('site_name', '');
            $tokens   = EmailTemplateRepo::buildKimTokens(
                siteName:   $siteName,
                ownerName:  (string) $config->get('owner_name', ''),
                ownerEmail: (string) $config->get('owner_email', ''),
                siteUrl:    (string) $config->get('site_url', ''),
            );

            $notice->sendForEachAddress(EmailTemplateRepo::TYPE_NOTIFY_KIM_UPDATE, $tokens, $entries, $siteName, $what);
        } catch (\Throwable $e) {
            $notice->reportFailure($what, $e);
        }
    }

    /**
     * One entry's part of the owner's notice. $member is as it was before this save,
     * so the owner sees the details they know this entry by.
     *
     * @param list<string> $lines
     * @return array{listing: ?\Luster\Models\Listing, heading: string, lines: list<string>, identity: array<string, string>}
     */
    private function ownerEntry(object $member, array $lines, ListingRepo $listingRepo): array
    {
        $listing = $listingRepo->getById($member->fanlistingId);

        return [
            'listing'  => $listing,
            'heading'  => $listing !== null ? ($listing->subject ?: $listing->title) : 'A listing that no longer exists',
            'lines'    => $lines,
            'identity' => [
                '{kim_name}'  => (string) $member->name,
                '{kim_email}' => (string) $member->email,
                '{kim_url}'   => (string) ($member->url ?? ''),
            ],
        ];
    }
}
