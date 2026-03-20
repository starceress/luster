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
 *   GET  /admin/activity?page=N — pagination
 *   GET  /admin/activity/export — download CSV
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

        $perPage = (int) $config->get('per_page', 25);
        $page    = max(1, (int) $request->query('page', 1));

        $result  = $activityRepo->getPaginated($page, $perPage);

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $session->remove('lstr_flash_success');

        return $this->layout('admin/activity/index.php', [
            'entries' => $result['entries'],
            'total'   => $result['total'],
            'page'    => $page,
            'pages'   => $result['pages'],
            'success' => $success,
        ], 'Activity Log', 'activity');
    }

    // -----------------------------------------------------------------------
    // GET /admin/activity/export
    // -----------------------------------------------------------------------

    public function export(Request $request, array $params): Response
    {
        $repo = new ActivityRepo($this->app->getDatabase());
        $rows = $repo->getAll();

        $filename = 'activity-log-' . date('Y-m-d') . '.csv';

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date & Time', 'Action', 'User', 'IP']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['created_at']  ?? '',
                $row['description'] ?? '',
                $row['username']    ?? '',
                $row['ip_address']  ?? '',
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return Response::csv($csv, $filename);
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

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => $pageTitle,
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => $section,
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
