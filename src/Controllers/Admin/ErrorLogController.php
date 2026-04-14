<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ErrorLogRepo;

/**
 * Admin error log viewer.
 *
 * Routes:
 *   GET  /admin/errorlog        — paginated list of log entries
 *   POST /admin/errorlog/flush  — delete all entries
 */
final class ErrorLogController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/errorlog
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $repo    = new ErrorLogRepo($this->app->getDatabase());
        $session = $this->app->getSession();

        $perPage = 10;
        $page    = max(1, (int) $request->query('page', 1));

        $result = $repo->getPaginated($page, $perPage);

        $success = (string) $session->get('lstr_flash_success', '');
        $session->remove('lstr_flash_success');

        return $this->adminLayout('admin/errorlog/index.php', 'Error Log', 'errorlog', [
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'pages'   => $result['pages'],
            'per_page' => $perPage,
            'success' => $success,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/errorlog/export
    // -----------------------------------------------------------------------

    public function export(Request $request, array $params): Response
    {
        $repo = new ErrorLogRepo($this->app->getDatabase());
        $rows = $repo->getAll();

        $filename = 'error-log-' . date('Y-m-d') . '.csv';

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Time', 'Source', 'Message']);
        foreach ($rows as $row) {
            $dt = $row['created_at'] ?? '';
            [$date, $time] = str_contains($dt, ' ') ? explode(' ', $dt, 2) : [$dt, ''];
            fputcsv($out, [
                $date,
                $time,
                $row['source']  ?? '',
                $row['message'] ?? '',
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return Response::csv($csv, $filename);
    }

    // -----------------------------------------------------------------------
    // POST /admin/errorlog/flush
    // -----------------------------------------------------------------------

    public function flush(Request $request, array $params): Response
    {
        $repo    = new ErrorLogRepo($this->app->getDatabase());
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $repo->flush();

        $session->set('lstr_flash_success', 'Error log cleared.');
        return Response::redirect($baseUrl . '/admin/errorlog');
    }

}
