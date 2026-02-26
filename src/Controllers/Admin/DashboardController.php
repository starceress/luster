<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;

/**
 * Admin dashboard — the landing page after a successful login.
 * Renders a summary view inside the admin layout shell.
 */
final class DashboardController
{
    public function __construct(private readonly Application $app) {}

    public function index(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $config  = $this->app->getConfig();
        $view    = $this->app->getView();

        $inner = $view->render('admin/dashboard.php', [
            'admin_username' => (string) $session->get('lstr_admin_username', ''),
            'app_name'       => (string) $config->get('app_name', 'Luster'),
        ]);

        return Response::html($view->render('admin/layout.php', [
            'page_title'     => 'Dashboard',
            'app_name'       => (string) $config->get('app_name', 'Luster'),
            'admin_username' => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'     => $this->app->getCSRF()->token(),
            'content'        => $inner,
        ]));
    }
}
