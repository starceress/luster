<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;

/**
 * Admin workflow for pending member update requests.
 *
 * Routes:
 *   GET  /admin/pending-updates              — list all pending updates
 *   GET  /admin/pending-updates/{id}         — detail view
 *   POST /admin/pending-updates/{id}/approve — apply changes + delete record
 *   POST /admin/pending-updates/{id}/reject  — delete record without applying
 */
final class PendingUpdateController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/pending-updates
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $pendingRepo    = new PendingUpdateRepo($db);
        $fanlistingRepo = new FanlistingRepo($db, $config);

        $pending     = $pendingRepo->getAll();
        $fanlistings = $fanlistingRepo->getAll();

        // Build id→name lookup so templates don't need DB access
        $flById = [];
        foreach ($fanlistings as $fl) {
            $flById[$fl->id] = $fl->name;
        }

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/pending-updates/index.php', [
            'pending'  => $pending,
            'fl_by_id' => $flById,
            'success'  => $success,
            'error'    => $error,
        ], 'Pending Updates', 'pending-updates');
    }

    // -----------------------------------------------------------------------
    // GET /admin/pending-updates/{id}
    // -----------------------------------------------------------------------

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $pendingRepo    = new PendingUpdateRepo($db);
        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);

        $pending = $pendingRepo->getById($id);

        if ($pending === null) {
            return Response::notFound('Pending update not found.');
        }

        $fanlisting = $fanlistingRepo->getById($pending->fanlisting_id);

        if ($fanlisting === null) {
            return Response::notFound('Associated fanlisting not found.');
        }

        // Load current member record for side-by-side comparison
        $member = $memberRepo->getByEmail($fanlisting->dbprefix, $pending->member_email);

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/pending-updates/show.php', [
            'pending'    => $pending,
            'fanlisting' => $fanlisting,
            'member'     => $member,
            'success'    => $success,
            'error'      => $error,
        ], 'Review Update', 'pending-updates');
    }

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/{id}/approve
    // -----------------------------------------------------------------------

    public function approve(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $pendingRepo    = new PendingUpdateRepo($db);
        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $activityRepo   = new ActivityRepo($db);

        $pending = $pendingRepo->getById($id);

        if ($pending === null) {
            $session->set('lstr_flash_error', 'Pending update not found.');
            return Response::redirect($baseUrl . '/admin/pending-updates');
        }

        $fanlisting = $fanlistingRepo->getById($pending->fanlisting_id);

        if ($fanlisting === null) {
            $session->set('lstr_flash_error', 'Associated fanlisting no longer exists.');
            $pendingRepo->delete($id);
            return Response::redirect($baseUrl . '/admin/pending-updates');
        }

        // Verify the member still exists
        $member = $memberRepo->getByEmail($fanlisting->dbprefix, $pending->member_email);

        if ($member === null) {
            $session->set('lstr_flash_error', 'Member no longer exists — update discarded.');
            $pendingRepo->delete($id);
            return Response::redirect($baseUrl . '/admin/pending-updates');
        }

        // Apply the queued changes
        $memberRepo->update($fanlisting->dbprefix, $pending->member_email, $pending->changes);

        $pendingRepo->delete($id);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'pending_update.approve',
            "Approved update #{$id} for {$pending->member_email} on {$fanlisting->name}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Update for {$pending->member_email} approved and applied.");
        return Response::redirect($baseUrl . '/admin/pending-updates');
    }

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/{id}/reject
    // -----------------------------------------------------------------------

    public function reject(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $pendingRepo  = new PendingUpdateRepo($db);
        $activityRepo = new ActivityRepo($db);

        $pending = $pendingRepo->getById($id);

        if ($pending === null) {
            $session->set('lstr_flash_error', 'Pending update not found.');
            return Response::redirect($baseUrl . '/admin/pending-updates');
        }

        $pendingRepo->delete($id);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'pending_update.reject',
            "Rejected update #{$id} for {$pending->member_email}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Update for {$pending->member_email} rejected.");
        return Response::redirect($baseUrl . '/admin/pending-updates');
    }

    // -----------------------------------------------------------------------
    // Shared render helper
    // -----------------------------------------------------------------------

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
