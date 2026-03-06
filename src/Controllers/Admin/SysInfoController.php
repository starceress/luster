<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ErrorLogRepo;

/**
 * Displays system information for the current installation.
 *
 * Routes:
 *   GET /admin/sysinfo
 */
final class SysInfoController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/sysinfo
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db  = $this->app->getDatabase();
        $pdo = $db->pdo();

        $phpVersion  = PHP_VERSION;
        $dbVersion   = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

        $errorLogRepo  = new ErrorLogRepo($db);
        $errorLogCount = $errorLogRepo->count();

        return $this->layout('admin/sysinfo/index.php', [
            'php_version'     => $phpVersion,
            'db_version'      => $dbVersion,
            'script_name'     => 'Luster',
            'script_version'  => 'beta',
            'error_log_count' => $errorLogCount,
        ], 'System Info', 'sysinfo');
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
