<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FanlistingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;

/**
 * Manages members for a specific fanlisting.
 *
 * Routes:
 *   GET  /admin/members/{id}             — list with search + status filter
 *   GET  /admin/members/{id}/edit        — edit form (?email=...)
 *   POST /admin/members/{id}/edit        — save edits
 *   POST /admin/members/{id}/approve     — approve one member
 *   POST /admin/members/{id}/reject      — reject one member
 *   POST /admin/members/{id}/delete      — delete one member
 *   POST /admin/members/{id}/bulk        — bulk approve / reject / delete
 *   GET  /admin/members/{id}/export      — CSV download of approved members
 */
final class MemberController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/members/{id}
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $fieldRepo      = new FieldRepo($db, $config);
        $settingsRepo   = new FanlistingSettingsRepo($db);

        $fanlisting = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $perPage = $settingsRepo->getMembersPerPage($fanlistingId);
        $page    = max(1, (int) $request->query('page', 1));
        $search  = trim((string) $request->query('search', ''));
        $status  = (string) $request->query('status', 'approved');

        if (!in_array($status, ['approved', 'pending', 'all'], true)) {
            $status = 'approved';
        }

        $fields = $fieldRepo->getFieldsForFanlisting($fanlistingId);

        if ($search !== '') {
            // Search ignores status filter and paginates manually
            $allMatches = $memberRepo->search($fanlisting->dbprefix, $search);
            $total      = count($allMatches);
            $pages      = $total > 0 ? (int) ceil($total / $perPage) : 0;
            $members    = array_slice($allMatches, ($page - 1) * $perPage, $perPage);
        } else {
            $result  = $memberRepo->paginateByStatus($fanlisting->dbprefix, $status, $page, $perPage);
            $members = $result['members'];
            $total   = $result['total'];
            $pages   = $result['pages'];
        }

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/members/index.php', [
            'fanlisting'  => $fanlisting,
            'members'     => $members,
            'fields'      => $fields,
            'total'       => $total,
            'page'        => $page,
            'pages'       => $pages,
            'per_page'    => $perPage,
            'search'      => $search,
            'status'      => $status,
            'success'     => $success,
            'error'       => $error,
        ], 'Members — ' . $fanlisting->name, 'members');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/members/{id}/edit  (?email=addr@example.com)
    // -----------------------------------------------------------------------

    public function edit(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $fieldRepo      = new FieldRepo($db, $config);
        $activityRepo   = new ActivityRepo($db);

        $fanlisting = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();
        $fields  = $fieldRepo->getFieldsForFanlisting($fanlistingId);

        // ---- POST: save ----
        if ($request->isPost()) {
            $email = trim((string) $request->post('member_email', ''));

            if ($email === '') {
                return Response::notFound('Member email not provided.');
            }

            $member = $memberRepo->getByEmail($fanlisting->dbprefix, $email);

            if ($member === null) {
                return Response::notFound('Member not found.');
            }

            $name    = trim((string) $request->post('name', ''));
            $url     = trim((string) $request->post('url', ''));
            $country = trim((string) $request->post('country', ''));
            $showurl = $request->post('showurl') === '1';

            $v = new Validator(['name' => $name, 'url' => $url]);
            $v->field('name')->required()->maxLength(255);
            if ($url !== '') {
                $v->field('url')->url()->maxLength(500);
            }

            if ($v->fails()) {
                return $this->layout('admin/members/edit.php', [
                    'fanlisting' => $fanlisting,
                    'member'     => $member,
                    'fields'     => $fields,
                    'error'      => implode(' ', $v->allErrors()),
                    'old'        => $request->postAll(),
                ], 'Edit Member', 'members');
            }

            $updateData = [
                'name'    => $name,
                'url'     => $url,
                'country' => $country,
                'showurl' => (int) $showurl,
            ];

            foreach ($fields as $field) {
                $col   = $field->name; // bare name for unprefixed schema
                $value = trim((string) $request->post('field_' . $field->name, ''));
                $updateData[$col] = $value;
            }

            $memberRepo->update($fanlisting->dbprefix, $email, $updateData);

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'member.edit',
                "Edited member {$email} in fanlisting #{$fanlistingId}",
                $request->ip(),
            );

            $session->set('lstr_flash_success', 'Member updated.');
            return Response::redirect($baseUrl . '/admin/members/' . $fanlistingId);
        }

        // ---- GET: show form ----
        $email = trim((string) $request->query('email', ''));

        if ($email === '') {
            return Response::notFound('Member email not specified.');
        }

        $member = $memberRepo->getByEmail($fanlisting->dbprefix, $email);

        if ($member === null) {
            return Response::notFound('Member not found.');
        }

        return $this->layout('admin/members/edit.php', [
            'fanlisting' => $fanlisting,
            'member'     => $member,
            'fields'     => $fields,
            'error'      => '',
            'old'        => [],
        ], 'Edit Member', 'members');
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/approve
    // -----------------------------------------------------------------------

    public function approve(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);
        $email        = trim((string) $request->post('member_email', ''));

        return $this->singleAction($fanlistingId, $email, 'approve', $request);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/reject
    // -----------------------------------------------------------------------

    public function reject(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);
        $email        = trim((string) $request->post('member_email', ''));

        return $this->singleAction($fanlistingId, $email, 'reject', $request);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/delete
    // -----------------------------------------------------------------------

    public function deleteMember(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);
        $email        = trim((string) $request->post('member_email', ''));

        return $this->singleAction($fanlistingId, $email, 'delete', $request);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/bulk
    // -----------------------------------------------------------------------

    public function bulk(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $activityRepo   = new ActivityRepo($db);

        $fanlisting = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action = (string) $request->post('bulk_action', '');
        $emails = (array) $request->post('emails', []);
        $emails = array_filter(array_map('trim', $emails));

        if (empty($emails) || !in_array($action, ['approve', 'reject', 'delete'], true)) {
            $session->set('lstr_flash_error', 'No members selected or invalid action.');
            return Response::redirect($baseUrl . '/admin/members/' . $fanlistingId);
        }

        $count = 0;
        foreach ($emails as $email) {
            if ($email === '') {
                continue;
            }

            match ($action) {
                'approve' => $memberRepo->approve($fanlisting->dbprefix, $email),
                'reject'  => $memberRepo->reject($fanlisting->dbprefix, $email),
                'delete'  => $memberRepo->deleteByEmail($fanlisting->dbprefix, $email),
            };

            $count++;
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            "member.bulk_{$action}",
            "Bulk {$action}: {$count} member(s) in fanlisting #{$fanlistingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Bulk {$action}: {$count} member(s) processed.");
        return Response::redirect($baseUrl . '/admin/members/' . $fanlistingId);
    }

    // -----------------------------------------------------------------------
    // GET /admin/members/{id}/export
    // -----------------------------------------------------------------------

    public function export(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $fieldRepo      = new FieldRepo($db, $config);

        $fanlisting = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $members = $memberRepo->getApproved($fanlisting->dbprefix);
        $fields  = $fieldRepo->getFieldsForFanlisting($fanlistingId);

        // Build CSV in memory
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return Response::serverError('Could not open temp stream for CSV export.');
        }

        // Header row
        $headerRow = ['name', 'email', 'url', 'country', 'added'];
        foreach ($fields as $field) {
            $headerRow[] = $field->name;
        }
        fputcsv($handle, $headerRow);

        // Data rows
        foreach ($members as $m) {
            $row = [
                $m->name,
                $m->email,
                $m->url,
                $m->country,
                $m->added,
            ];
            foreach ($fields as $field) {
                $row[] = $m->additionalFields[$field->name] ?? '';
            }
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'members_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($fanlisting->name)) . '.csv';

        return Response::csv((string) $csv, $filename);
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * Executes a single approve / reject / delete action and redirects.
     */
    private function singleAction(int $fanlistingId, string $email, string $action, Request $request): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $activityRepo   = new ActivityRepo($db);

        $fanlisting = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($email === '') {
            $session->set('lstr_flash_error', 'No member email provided.');
            return Response::redirect($baseUrl . '/admin/members/' . $fanlistingId);
        }

        match ($action) {
            'approve' => $memberRepo->approve($fanlisting->dbprefix, $email),
            'reject'  => $memberRepo->reject($fanlisting->dbprefix, $email),
            'delete'  => $memberRepo->deleteByEmail($fanlisting->dbprefix, $email),
        };

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            "member.{$action}",
            ucfirst($action) . "d member {$email} in fanlisting #{$fanlistingId}",
            $request->ip(),
        );

        $label = match ($action) {
            'approve' => 'Member approved.',
            'reject'  => 'Member rejected and removed.',
            'delete'  => 'Member deleted.',
        };

        $session->set('lstr_flash_success', $label);
        return Response::redirect($baseUrl . '/admin/members/' . $fanlistingId);
    }

    private function layout(string $template, array $data, string $pageTitle, string $section): Response
    {
        $view      = $this->app->getView();
        $config    = $this->app->getConfig();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $inner = $view->render($template, array_merge($data, [
            'base_url'   => $baseUrl,
            'csrf_token' => $csrfToken,
        ]));

        return Response::html($view->render('admin/layout.php', [
            'page_title'      => $pageTitle,
            'app_name'        => (string) $config->get('app_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => $section,
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ]));
    }
}
