<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
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
final class ErrorLogController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/errorlog
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $repo    = new ErrorLogRepo($this->app->getDatabase());
        $session = $this->app->getSession();

        $perPage = 25;
        $page    = max(1, (int) $request->query('page', 1));

        $result = $repo->getPaginated($page, $perPage);

        $success = (string) $session->get('lstr_flash_success', '');
        $session->remove('lstr_flash_success');

        return $this->layout('admin/errorlog/index.php', [
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'pages'   => $result['pages'],
            'per_page' => $perPage,
            'success' => $success,
        ], 'Error Log', 'errorlog');
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
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => $section,
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ]));
    }
}
