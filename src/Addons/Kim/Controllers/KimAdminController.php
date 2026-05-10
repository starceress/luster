<?php

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMember;
use Luster\Addons\Kim\KimMemberRepo;
use Luster\Controllers\Admin\AdminController;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Admin KIM management controller.
 *
 * All state-changing actions validate CSRF (via CsrfMiddleware) and log to ActivityRepo.
 */
final class KimAdminController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/kim
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $kimRepo     = new KimMemberRepo($db);

        $fanlistings   = $listingRepo->getAll();
        $pendingCounts = $kimRepo->getPendingCounts();

        $fanlistingsData = [];
        foreach ($fanlistings as $fl) {
            $fanlistingsData[] = [
                'listing'  => $fl,
                'pending'  => $pendingCounts[$fl->id] ?? 0,
                'approved' => $kimRepo->countApprovedByFanlisting($fl->id),
            ];
        }

        $sort = trim((string) $request->query('sort', 'id'));
        $dir  = trim((string) $request->query('dir',  'asc'));
        if (!in_array($sort, ['id', 'subject'], true)) { $sort = 'id'; }
        if (!in_array($dir,  ['asc', 'desc'], true))   { $dir  = 'asc'; }

        usort($fanlistingsData, static function (array $a, array $b) use ($sort, $dir): int {
            if ($sort === 'subject') {
                $cmp = strcasecmp($a['listing']->subject, $b['listing']->subject);
                return $dir === 'asc' ? $cmp : -$cmp;
            }
            $diff = $a['listing']->id - $b['listing']->id;
            return $dir === 'asc' ? $diff : -$diff;
        });

        $totalListings   = count($fanlistingsData);
        $perPage         = max(1, (int) $config->get('per_page', 10));
        $pages           = $totalListings > 0 ? (int) ceil($totalListings / $perPage) : 1;
        $page            = min(max(1, (int) $request->query('page', 1)), $pages);
        $fanlistingsData = array_slice($fanlistingsData, ($page - 1) * $perPage, $perPage);

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/kim/index.php', 'Keep In Mind', 'kim', [
            'fanlistings_data' => $fanlistingsData,
            'total_listings'   => $totalListings,
            'sort'             => $sort,
            'dir'              => $dir,
            'page'             => $page,
            'pages'            => $pages,
            'per_page'         => $perPage,
            'success'          => $success,
            'error'            => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/{fid}/show
    // -----------------------------------------------------------------------

    public function show(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $session      = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($fanlistingId);

        if ($listing === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $kimRepo  = new KimMemberRepo($db);
        $perPage  = max(1, (int) $config->get('per_page', 10));
        $page     = max(1, (int) ($request->query('page', 1)));
        $baseUrl  = $this->app->getBaseUrl();

        $pendingMembers = $kimRepo->getPendingByFanlisting($fanlistingId);
        $approvedCount  = $kimRepo->countApprovedByFanlisting($fanlistingId);
        $totalPages     = max(1, (int) ceil($approvedCount / $perPage));
        $page           = min($page, $totalPages);
        $approvedMembers = $kimRepo->getApprovedByFanlistingPaged($fanlistingId, $page, $perPage);

        $pageUrl = static fn(int $p): string =>
            $baseUrl . '/admin/kim/' . $fanlistingId . '?page=' . $p;

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/kim/show.php', 'Keep In Mind', 'kim', [
            'listing'          => $listing,
            'pending_members'  => $pendingMembers,
            'approved_members' => $approvedMembers,
            'pending_count'    => count($pendingMembers),
            'approved_count'   => $approvedCount,
            'page'             => $page,
            'total_pages'      => $totalPages,
            'page_url'         => $pageUrl,
            'success'          => $success,
            'error'            => $error,
        ], '', [
            $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
            $this->crumb($listing->subject),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/kim/{id}/edit
    // -----------------------------------------------------------------------

    public function edit(Request $request, array $params): Response
    {
        $memberId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();
        $config   = $this->app->getConfig();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $listingRepo = new ListingRepo($db, $config);
        $fanlistings = $listingRepo->getAll();

        // Find listing name for breadcrumb
        $fanlistingName = '';
        foreach ($fanlistings as $fl) {
            if ($fl->id === $member->fanlistingId) {
                $fanlistingName = $fl->subject;
                break;
            }
        }

        if ($request->method() === 'GET') {
            $error = (string) $session->get('lstr_flash_error', '');
            $session->remove('lstr_flash_error');

            return $this->adminLayout('admin/addons/kim/edit.php', 'Edit Keep In Mind Member', 'kim', [
                'member'          => $member,
                'fanlistings'     => $fanlistings,
                'fanlisting_name' => $fanlistingName,
                'error'           => $error,
            ], '', array_values(array_filter([
                $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
                $fanlistingName !== '' ? $this->crumb($fanlistingName, $this->adminUrl('/admin/kim/' . $member->fanlistingId . '/show')) : null,
                $this->crumb($member->name),
            ])));
        }

        // POST — validate and update
        $name        = trim((string) $request->post('name',         ''));
        $email       = trim((string) $request->post('email',        ''));
        $url         = trim((string) $request->post('url',          ''));
        $fanlistingId = (int) $request->post('fanlisting_id',        0);

        $fanlistingIds = array_map(static fn($fl) => $fl->id, $fanlistings);

        $v = new Validator([
            'name'         => $name,
            'email'        => $email,
            'url'          => $url,
            'fanlisting_id' => (string) $fanlistingId,
        ]);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->url()->maxLength(500);
        $v->field('fanlisting_id')->required();

        $validationFailed = $v->fails();
        if (!$validationFailed && ($fanlistingId <= 0 || !in_array($fanlistingId, $fanlistingIds, true))) {
            $validationFailed = true;
        }

        if ($validationFailed) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()) ?: 'Invalid input.');
            return Response::redirect($baseUrl . '/admin/kim/' . $memberId . '/edit');
        }

        $updateFields = [
            'name'         => $name,
            'email'        => $email,
            'url'          => $url,
            'fanlisting_id' => $fanlistingId,
        ];

        $newPassword        = (string) $request->post('new_password',         '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');

        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                $session->set('lstr_flash_error', 'New password must be at least 8 characters.');
                return Response::redirect($baseUrl . '/admin/kim/' . $memberId . '/edit');
            }
            if ($newPassword !== $newPasswordConfirm) {
                $session->set('lstr_flash_error', 'Passwords do not match.');
                return Response::redirect($baseUrl . '/admin/kim/' . $memberId . '/edit');
            }
            $updateFields['password'] = password_hash($newPassword, PASSWORD_ARGON2ID);
        }

        $kimRepo->update($memberId, $updateFields);

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.edit',
            "Edited KIM member #{$memberId} ({$email})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'KIM member updated.');
        return Response::redirect($baseUrl . '/admin/kim/' . $fanlistingId . '/show');
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{id}/approve
    // -----------------------------------------------------------------------

    public function approve(Request $request, array $params): Response
    {
        $memberId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $kimRepo->approve($memberId);

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.approve',
            "Approved KIM member #{$memberId} ({$member->email}) for fanlisting {$member->fanlistingId}",
            $request->ip(),
        );

        $fromFid = (int) $request->post('from_fid', $member->fanlistingId);
        $session->set('lstr_flash_success', 'KIM member approved.');
        return Response::redirect($baseUrl . '/admin/kim/' . $fromFid . '/show');
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $memberId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $fanlistingId = $member->fanlistingId;
        $kimRepo->delete($memberId);

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.delete',
            "Deleted KIM member #{$memberId} ({$member->email}) for fanlisting {$fanlistingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'KIM member deleted.');
        return Response::redirect($baseUrl . '/admin/kim/' . $fanlistingId . '/show');
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/{fid}/bulk-email
    // -----------------------------------------------------------------------

    public function massEmailForm(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $session      = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($fanlistingId);

        if ($listing === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $kimRepo    = new KimMemberRepo($db);
        $selectedIds = (array) $session->get('lstr_kim_selected_ids', []);
        $session->remove('lstr_kim_selected_ids');

        if (!empty($selectedIds)) {
            $recipients   = $kimRepo->getEmailsByIds(array_map('intval', $selectedIds));
            $selectedOnly = true;
        } else {
            $recipients   = $kimRepo->getEmailsByFanlisting($fanlistingId);
            $selectedOnly = false;
        }

        $smtpFrom  = (string) $config->get('smtp_from', '');
        $fromEmail = $smtpFrom !== '' ? $smtpFrom : (string) $config->get('owner_email', '');
        $fromName  = (string) $config->get('smtp_from_name', '') !== ''
            ? (string) $config->get('smtp_from_name', '')
            : (string) $config->get('owner_name', '');

        $savedEmails = (new \Luster\Repositories\SavedEmailRepo($db))->getAll();
        $error   = (string) $session->get('lstr_flash_error',   '');
        $success = (string) $session->get('lstr_flash_success', '');
        $old     = (array)  $session->get('lstr_email_old',     []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_email_old');

        $selectedEmails = array_column($recipients, 'email');
        $memberNames    = array_combine(
            array_column($recipients, 'email'),
            array_column($recipients, 'name'),
        );

        return $this->adminLayout('admin/addons/kim/bulk-email.php', 'Bulk Email', 'kim', [
            'listing'         => $listing,
            'recipients'      => $recipients,
            'recipient_count' => count($recipients),
            'selected_only'   => $selectedOnly,
            'selected_emails' => $selectedEmails,
            'member_names'    => $memberNames,
            'from_name'       => $fromName,
            'from_email'      => $fromEmail,
            'owner_name'      => (string) $config->get('owner_name',  ''),
            'owner_email'     => (string) $config->get('owner_email', ''),
            'site_name'       => (string) $config->get('site_name',   ''),
            'saved_emails'    => $savedEmails,
            'old'             => $old,
            'error'           => $error,
            'success'         => $success,
        ], '', [
            $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
            $this->crumb($listing->subject, $this->adminUrl('/admin/kim/' . $listing->id . '/show')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{fid}/bulk-email
    // -----------------------------------------------------------------------

    public function massEmail(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($fanlistingId);

        if ($listing === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $subject = trim((string) $request->post('subject', ''));
        $body    = trim((string) $request->post('body',    ''));

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Please fill in both the subject and message before sending.');
            $session->set('lstr_email_old', ['subject' => $subject, 'body' => $body]);
            return Response::redirect($baseUrl . '/admin/kim/' . $fanlistingId . '/bulk-email');
        }

        $kimRepo = new KimMemberRepo($db);

        $postedEmails = $request->post('emails', []);
        if (is_array($postedEmails) && !empty($postedEmails)) {
            $safeEmails = array_values(array_filter(
                array_map('strval', $postedEmails),
                static fn(string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
            ));
            $recipients = array_map(static fn(string $e) => ['name' => '', 'email' => $e], $safeEmails);
        } else {
            $recipients = $kimRepo->getEmailsByFanlisting($fanlistingId);
        }

        $mailer = new Mailer($this->app->getConfig());
        $sent   = 0;
        $failed = 0;

        foreach ($recipients as $r) {
            try {
                $mailer->send($r['email'], $r['name'], $subject, $body);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                error_log('[KIM] Bulk email failed for ' . $r['email'] . ': ' . $e->getMessage());
            }
        }

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.bulk_email',
            "Sent bulk email to KIM members for fanlisting {$fanlistingId}: {$sent} sent, {$failed} failed",
            $request->ip(),
        );

        $session->set(
            'lstr_flash_success',
            "Bulk email sent: {$sent} delivered" . ($failed > 0 ? ", {$failed} failed" : '') . '.'
        );
        return Response::redirect($baseUrl . '/admin/kim/' . $fanlistingId . '/show');
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/{id}/email-member
    // -----------------------------------------------------------------------

    public function emailMemberForm(Request $request, array $params): Response
    {
        $memberId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();
        $config   = $this->app->getConfig();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($member->fanlistingId);

        $smtpFrom  = (string) $config->get('smtp_from', '');
        $fromEmail = $smtpFrom !== '' ? $smtpFrom : (string) $config->get('owner_email', '');
        $fromName  = (string) $config->get('smtp_from_name', '') !== ''
            ? (string) $config->get('smtp_from_name', '')
            : (string) $config->get('owner_name', '');

        $savedEmails = (new \Luster\Repositories\SavedEmailRepo($db))->getAll();
        $error   = (string) $session->get('lstr_flash_error',   '');
        $success = (string) $session->get('lstr_flash_success', '');
        $old     = (array)  $session->get('lstr_email_old',     []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_email_old');

        $referer = $request->header('referer') ?? $baseUrl . '/admin/kim/' . $member->fanlistingId . '/show';

        return $this->adminLayout('admin/addons/kim/email-member.php', 'Email Keep In Mind Member', 'kim', [
            'member'       => $member,
            'listing'      => $listing,
            'from_email'   => $fromEmail,
            'from_name'    => $fromName,
            'owner_name'   => (string) $config->get('owner_name',  ''),
            'owner_email'  => (string) $config->get('owner_email', ''),
            'site_name'    => (string) $config->get('site_name',   ''),
            'saved_emails' => $savedEmails,
            'old'          => $old,
            'referer'      => $referer,
            'error'        => $error,
            'success'      => $success,
        ], '', array_values(array_filter([
            $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
            $listing !== null ? $this->crumb($listing->subject, $this->adminUrl('/admin/kim/' . $listing->id . '/show')) : null,
            $this->crumb($member->name),
        ])));
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{id}/email-member
    // -----------------------------------------------------------------------

    public function emailMember(Request $request, array $params): Response
    {
        $memberId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();
        $config   = $this->app->getConfig();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $suffix  = trim((string) $request->post('subject_suffix', ''));
        $body    = trim((string) $request->post('body',           ''));

        $config  = $this->app->getConfig();
        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($member->fanlistingId);
        $flSubject   = $listing ? $listing->subject : '';
        $subject     = $flSubject !== '' ? $flSubject . ': ' . $suffix : $suffix;

        $redirectBack = $baseUrl . '/admin/kim/' . $memberId . '/email-member';

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Please fill in both the subject and message before sending.');
            $session->set('lstr_email_old', ['subject' => $suffix, 'body' => $body]);
            return Response::redirect($redirectBack);
        }

        try {
            $mailer = new Mailer($this->app->getConfig());
            $mailer->send($member->email, $member->name, $subject, $body);
            $session->set('lstr_flash_success', 'Email sent to ' . $member->name . '.');
        } catch (\Throwable $e) {
            error_log('[KIM] Email member failed for ' . $member->email . ': ' . $e->getMessage());
            $session->set('lstr_flash_error', 'The email could not be sent. Please check your SMTP settings and try again.');
            $session->set('lstr_email_old', ['subject' => $suffix, 'body' => $body]);
            return Response::redirect($redirectBack);
        }

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.email_member',
            "Sent email to KIM member #{$memberId} ({$member->email})",
            $request->ip(),
        );

        return Response::redirect($redirectBack);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{fid}/bulk
    // -----------------------------------------------------------------------

    public function bulk(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($fanlistingId) === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $action  = (string) $request->post('bulk_action', '');
        $rawIds  = $request->post('ids', []);
        $ids     = array_map('intval', is_array($rawIds) ? $rawIds : []);
        $ids     = array_filter($ids, static fn(int $id) => $id > 0);

        if (empty($ids)) {
            $session->set('lstr_flash_error', 'No members selected.');
            return Response::redirect($baseUrl . '/admin/kim/' . $fanlistingId . '/show');
        }

        $kimRepo      = new KimMemberRepo($db);
        $activityRepo = new ActivityRepo($db);
        $adminId      = (int) $session->get('lstr_admin_id');
        $count        = count($ids);

        if ($action === 'email_selected') {
            $session->set('lstr_kim_selected_ids', array_values($ids));
            return Response::redirect($baseUrl . '/admin/kim/' . $fanlistingId . '/bulk-email');
        }

        match ($action) {
            'approve' => (function () use ($kimRepo, $activityRepo, $ids, $fanlistingId, $adminId, $count, $request, $session): void {
                $kimRepo->approveByIds($ids);
                $activityRepo->log($adminId, 'kim.bulk_approve', "Bulk approved {$count} KIM member(s) for fanlisting {$fanlistingId}", $request->ip());
                $session->set('lstr_flash_success', "Approved {$count} member(s).");
            })(),
            'delete' => (function () use ($kimRepo, $activityRepo, $ids, $fanlistingId, $adminId, $count, $request, $session): void {
                $kimRepo->deleteByIds($ids);
                $activityRepo->log($adminId, 'kim.bulk_delete', "Bulk deleted {$count} KIM member(s) for fanlisting {$fanlistingId}", $request->ip());
                $session->set('lstr_flash_success', "Deleted {$count} member(s).");
            })(),
            default => $session->set('lstr_flash_error', 'Unknown bulk action.'),
        };

        return Response::redirect($baseUrl . '/admin/kim/' . $fanlistingId . '/show');
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/settings
    // -----------------------------------------------------------------------

    public function showSettings(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();

        $siteSettings = new SiteSettingsRepo($db);
        $kimUrls = $siteSettings->getMany([
            'kim_url_signup', 'kim_url_update', 'kim_url_reset', 'kim_url_list',
        ]);

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/kim/settings.php', 'Keep In Mind Settings', 'kim', [
            'kim_urls'        => $kimUrls,
            'success'         => $success,
            'error'           => $error,
        ], '', [
            $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/settings
    // -----------------------------------------------------------------------

    public function saveSettings(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $siteSettings = new SiteSettingsRepo($db);

        if ($request->post('reset_defaults') === '1') {
            foreach (['kim_url_signup', 'kim_url_update', 'kim_url_reset', 'kim_url_list'] as $key) {
                $siteSettings->set($key, null);
            }
            $session->set('lstr_flash_success', 'KIM page URLs reset to defaults.');
            return Response::redirect($baseUrl . '/admin/kim/settings');
        }

        foreach (['kim_url_signup', 'kim_url_update', 'kim_url_reset', 'kim_url_list'] as $key) {
            $field = str_replace('kim_url_', '', $key); // 'signup', 'update', etc.
            $value = trim((string) $request->post($field, ''));
            $siteSettings->set($key, $value !== '' ? $value : null);
        }

        $session->set('lstr_flash_success', 'KIM page URLs saved.');
        return Response::redirect($baseUrl . '/admin/kim/settings');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

}
