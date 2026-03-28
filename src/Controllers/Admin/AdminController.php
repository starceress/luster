<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Response;

/**
 * Abstract base for all admin controllers.
 *
 * Provides adminLayout() — the single shared method for rendering an admin
 * page inside the Nebula shell (layout.php).
 *
 * Usage in subclasses:
 *   return $this->adminLayout('admin/foo/index.php', 'Page Title', 'section', $data);
 *   return $this->adminLayout('admin/foo/edit.php',  'Edit Foo',   'section', $data, 'Foo › Edit');
 */
abstract class AdminController
{
    public function __construct(protected readonly Application $app) {}

    /**
     * Renders an admin page inside the Nebula layout shell.
     *
     * @param string $template     Path relative to templates/ (e.g. 'admin/foo/index.php')
     * @param string $pageTitle    Value of $page_title in the layout
     * @param string $section      Value of $current_section (drives sidebar highlight)
     * @param array  $data         Variables injected into the inner content template
     * @param string $pageSubtitle Optional breadcrumb subtitle (e.g. 'Foo › Edit')
     */
    protected function adminLayout(
        string $template,
        string $pageTitle,
        string $section,
        array  $data         = [],
        string $pageSubtitle = '',
    ): Response {
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
            'page_subtitle'   => $pageSubtitle,
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => $section,
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
