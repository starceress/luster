<?php

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMember;
use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;

/**
 * Admin KIM management controller.
 *
 * All state-changing actions validate CSRF (via CsrfMiddleware) and log to ActivityRepo.
 */
final class KimAdminController
{
    public function __construct(private readonly Application $app) {}

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

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminResponse('admin/addons/kim/index.php', [
            'page_title'      => 'KIM List',
            'current_section' => 'kim',
            'fanlistings_data' => $fanlistingsData,
            'success'         => $success,
            'error'           => $error,
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

        $kimRepo = new KimMemberRepo($db);
        $members = $kimRepo->getAllByFanlisting($fanlistingId);

        $pendingCount  = 0;
        $approvedCount = 0;
        foreach ($members as $m) {
            if ($m->pending) {
                $pendingCount++;
            } else {
                $approvedCount++;
            }
        }

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminResponse('admin/addons/kim/show.php', [
            'page_title'      => 'KIM — ' . $listing->subject,
            'current_section' => 'kim',
            'listing'         => $listing,
            'members'         => $members,
            'pending_count'   => $pendingCount,
            'approved_count'  => $approvedCount,
            'success'         => $success,
            'error'           => $error,
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

            return $this->adminResponse('admin/addons/kim/edit.php', [
                'page_title'      => 'Edit KIM Member',
                'current_section' => 'kim',
                'member'          => $member,
                'fanlistings'     => $fanlistings,
                'fanlisting_name' => $fanlistingName,
                'error'           => $error,
            ]);
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
    // GET /admin/kim/{fid}/mass-email
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

        $kimRepo     = new KimMemberRepo($db);
        $recipients  = $kimRepo->getEmailsByFanlisting($fanlistingId);

        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        return $this->adminResponse('admin/addons/kim/mass-email.php', [
            'page_title'      => 'Mass Email — KIM ' . $listing->subject,
            'current_section' => 'kim',
            'listing'         => $listing,
            'recipient_count' => count($recipients),
            'error'           => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{fid}/mass-email
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
            $session->set('lstr_flash_error', 'Subject and body are required.');
            return Response::redirect($baseUrl . '/admin/kim/' . $fanlistingId . '/mass-email');
        }

        $kimRepo    = new KimMemberRepo($db);
        $recipients = $kimRepo->getEmailsByFanlisting($fanlistingId);

        $mailer = new Mailer($this->app->getConfig());
        $sent   = 0;
        $failed = 0;

        foreach ($recipients as $r) {
            try {
                $mailer->send($r['email'], $r['name'], $subject, $body);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                error_log('[KIM] Mass email failed for ' . $r['email'] . ': ' . $e->getMessage());
            }
        }

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.mass_email',
            "Sent mass email to KIM members for fanlisting {$fanlistingId}: {$sent} sent, {$failed} failed",
            $request->ip(),
        );

        $session->set(
            'lstr_flash_success',
            "Mass email sent: {$sent} delivered" . ($failed > 0 ? ", {$failed} failed" : '') . '.'
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

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($member->fanlistingId);

        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        return $this->adminResponse('admin/addons/kim/email-member.php', [
            'page_title'      => 'Email ' . $member->name,
            'current_section' => 'kim',
            'member'          => $member,
            'listing'         => $listing,
            'error'           => $error,
        ]);
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

        $subject = trim((string) $request->post('subject', ''));
        $body    = trim((string) $request->post('body',    ''));

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Subject and body are required.');
            return Response::redirect($baseUrl . '/admin/kim/' . $memberId . '/email-member');
        }

        try {
            $mailer = new Mailer($this->app->getConfig());
            $mailer->send($member->email, $member->name, $subject, $body);
        } catch (\Throwable $e) {
            error_log('[KIM] Email member failed for ' . $member->email . ': ' . $e->getMessage());
        }

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.email_member',
            "Sent email to KIM member #{$memberId} ({$member->email})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Email sent to ' . $member->name . '.');
        return Response::redirect($baseUrl . '/admin/kim/' . $member->fanlistingId . '/show');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function adminResponse(string $template, array $data): Response
    {
        $view    = $this->app->getView();
        $session = $this->app->getSession();
        $config  = $this->app->getConfig();
        $baseUrl = $this->app->getBaseUrl();

        $csrfToken = $this->app->getCSRF()->token();

        $inner = $view->render($template, array_merge($data, [
            'base_url'       => $baseUrl,
            'csrf_token'     => $csrfToken,
        ]));

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => $data['page_title'] ?? 'KIM',
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => $data['current_section'] ?? 'kim',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
