<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;

/**
 * Paginated activity log viewer.
 *
 * Routes:
 *   GET  /admin/activity        — all entries, newest first
 *   GET  /admin/activity?action=foo — filter by action type
 *   GET  /admin/activity?page=N    — pagination
 *   POST /admin/activity/flush  — delete all entries
 */
final class ActivityLogController
{
    public function __construct(private readonly Application $app) {}

    public function index(Request $request, array $params): Response
    {
        $config  = $this->app->getConfig();
        $db      = $this->app->getDatabase();

        $activityRepo = new ActivityRepo($db);

        $perPage      = (int) $config->get('per_page', 25);
        $page         = max(1, (int) $request->query('page', 1));
        $filterAction = trim((string) $request->query('action', ''));

        $result  = $activityRepo->getPaginated($page, $perPage, $filterAction);
        $actions = $activityRepo->getActions();

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $session->remove('lstr_flash_success');

        return $this->layout('admin/activity/index.php', [
            'entries'       => $result['entries'],
            'total'         => $result['total'],
            'page'          => $page,
            'pages'         => $result['pages'],
            'filter_action' => $filterAction,
            'actions'       => $actions,
            'success'       => $success,
        ], 'Activity Log', 'activity');
    }

    // -----------------------------------------------------------------------
    // POST /admin/activity/flush
    // -----------------------------------------------------------------------

    public function flush(Request $request, array $params): Response
    {
        $activityRepo = new ActivityRepo($this->app->getDatabase());
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $activityRepo->flush();

        $session->set('lstr_flash_success', 'Activity log cleared.');
        return Response::redirect($baseUrl . '/admin/activity');
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
