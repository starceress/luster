<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FanlistingSettingsRepo;
use Luster\Repositories\MemberRepo;

/**
 * Manages the fanlisting list and per-fanlisting settings.
 *
 * Fanlistings are created/deleted in Enthusiast; Luster only edits
 * metadata (name, URL, email, status) and Luster-specific settings
 * (auto_approve). Requires migrate.php to have been run for auto_approve.
 */
final class FanlistingController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/fanlistings
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);

        $fanlistings = $fanlistingRepo->getAll();

        $memberCounts  = [];
        $pendingCounts = [];
        foreach ($fanlistings as $fl) {
            $memberCounts[$fl->id]  = $memberRepo->countApproved($fl->dbprefix);
            $pendingCounts[$fl->id] = $memberRepo->countPending($fl->dbprefix);
        }

        return $this->layout('admin/fanlistings/index.php', [
            'fanlistings'   => $fanlistings,
            'member_counts'  => $memberCounts,
            'pending_counts' => $pendingCounts,
        ], 'Fanlistings', 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // GET /admin/fanlistings/{id}
    // -----------------------------------------------------------------------

    public function show(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo  = new FanlistingRepo($db, $config);
        $memberRepo      = new MemberRepo($db, $config);
        $settingsRepo    = new FanlistingSettingsRepo($db);
        $affiliateRepo   = new AffiliateRepo($db);

        $fanlisting = $fanlistingRepo->getById($id);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $approved    = $memberRepo->countApproved($fanlisting->dbprefix);
        $pending     = $memberRepo->countPending($fanlisting->dbprefix);
        $autoApprove = $settingsRepo->getAutoApprove($id);
        $affiliates  = $affiliateRepo->getByFanlisting($id);
        $templates   = $settingsRepo->getTemplates($id);

        return $this->layout('admin/fanlistings/show.php', [
            'fanlisting'       => $fanlisting,
            'approved'         => $approved,
            'pending'          => $pending,
            'auto_approve'     => $autoApprove,
            'affiliates'       => $affiliates,
            'stats_template'   => $templates['stats_template'],
            'members_template' => $templates['members_template'],
        ], $fanlisting->name, 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/fanlistings/{id}/edit
    // -----------------------------------------------------------------------

    public function edit(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $settingsRepo   = new FanlistingSettingsRepo($db);
        $activityRepo   = new ActivityRepo($db);

        $fanlisting = $fanlistingRepo->getById($id);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // ---- POST: save ----
        if ($request->isPost()) {
            $title       = trim((string) $request->post('title', ''));
            $url         = trim((string) $request->post('url', ''));
            $email       = trim((string) $request->post('email', ''));
            $status      = (string) $request->post('status', 'active');
            $autoApprove = $request->post('auto_approve') === '1';

            $v = new Validator([
                'title' => $title,
                'url'   => $url,
                'email' => $email,
            ]);
            $v->field('title')->required()->maxLength(255);
            $v->field('url')->required()->url()->maxLength(500);
            $v->field('email')->required()->email()->maxLength(255);

            if ($v->fails()) {
                $errors = $v->allErrors();
                return $this->layout('admin/fanlistings/edit.php', [
                    'fanlisting'   => $fanlisting,
                    'auto_approve' => $autoApprove,
                    'error'        => implode(' ', $errors),
                    'old'          => ['title' => $title, 'url' => $url, 'email' => $email, 'status' => $status],
                ], 'Edit ' . $fanlisting->name, 'fanlistings');
            }

            $upcoming = ($status === 'upcoming');
            $trouble  = ($status === 'trouble');

            $fanlistingRepo->updateMeta($id, $title, $url, $email, $upcoming, $trouble);
            $settingsRepo->setAutoApprove($id, $autoApprove);

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'fanlisting.edit',
                "Edited fanlisting #{$id} ({$title})",
                $request->ip(),
            );

            $session->set('lstr_flash_success', 'Fanlisting updated successfully.');
            return Response::redirect($baseUrl . '/admin/fanlistings/' . $id);
        }

        // ---- GET: show form ----
        $autoApprove = $settingsRepo->getAutoApprove($id);
        $error       = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/fanlistings/edit.php', [
            'fanlisting'   => $fanlisting,
            'auto_approve' => $autoApprove,
            'error'        => $error,
            'old'          => [],
        ], 'Edit ' . $fanlisting->name, 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/{id}/templates
    // -----------------------------------------------------------------------

    public function saveTemplates(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $fanlisting     = $fanlistingRepo->getById($id);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $statsRaw   = (string) $request->post('stats_template',   '');
        $membersRaw = (string) $request->post('members_template', '');

        // Treat blank / whitespace-only input as null (use built-in default)
        $statsTemplate   = trim($statsRaw)   !== '' ? $statsRaw   : null;
        $membersTemplate = trim($membersRaw) !== '' ? $membersRaw : null;

        $settingsRepo = new FanlistingSettingsRepo($db);
        $settingsRepo->saveTemplates($id, $statsTemplate, $membersTemplate);

        $this->app->getSession()->set('lstr_flash_success', 'Templates saved successfully.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/fanlistings/' . $id);
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

        $flash = (string) $session->get('lstr_flash_success', '');
        $session->remove('lstr_flash_success');

        $inner = $view->render($template, array_merge($data, [
            'base_url'   => $baseUrl,
            'success'    => $flash,
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
