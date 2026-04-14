<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;

/**
 * Paginated activity log viewer.
 *
 * Routes:
 *   GET  /admin/activitylog        — all entries, newest first
 *   GET  /admin/activitylog?page=N — pagination
 *   GET  /admin/activitylog/export — download CSV
 *   POST /admin/activitylog/flush  — delete all entries
 */
final class ActivityLogController extends AdminController
{

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

        return $this->adminLayout('admin/activitylog/index.php', 'Activity Log', 'activitylog', [
            'entries' => $result['entries'],
            'total'   => $result['total'],
            'page'    => $page,
            'pages'   => $result['pages'],
            'success' => $success,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/activitylog/export
    // -----------------------------------------------------------------------

    public function export(Request $request, array $params): Response
    {
        $repo = new ActivityRepo($this->app->getDatabase());
        $rows = $repo->getAll();

        $filename = 'activity-log-' . date('Y-m-d') . '.csv';

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Time', 'Action', 'User', 'IP']);
        foreach ($rows as $row) {
            $dt = $row['created_at'] ?? '';
            [$date, $time] = str_contains($dt, ' ') ? explode(' ', $dt, 2) : [$dt, ''];
            fputcsv($out, [
                $date,
                $time,
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
    // POST /admin/activitylog/flush
    // -----------------------------------------------------------------------

    public function flush(Request $request, array $params): Response
    {
        $activityRepo = new ActivityRepo($this->app->getDatabase());
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $activityRepo->flush();

        $session->set('lstr_flash_success', 'Activity log cleared.');
        return Response::redirect($baseUrl . '/admin/activitylog');
    }

}
