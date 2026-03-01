<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\CategoryRepo;

/**
 * Manages categories for joined fanlistings.
 *
 * Routes:
 *   GET  /admin/categories          — list all categories
 *   POST /admin/categories/add      — insert new category
 *   POST /admin/categories/{id}/delete — delete category
 */
final class CategoryController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/categories
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $categoryRepo = new CategoryRepo($this->app->getDatabase());
        $all          = $categoryRepo->getAll();

        // Group: parents first, then children keyed under parent id
        $parents  = [];
        $children = [];

        foreach ($all as $cat) {
            if ($cat->parentId === null) {
                $parents[$cat->id] = $cat;
            } else {
                $children[$cat->parentId][] = $cat;
            }
        }

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/categories/index.php', [
            'parents'  => $parents,
            'children' => $children,
            'all'      => $all,
            'success'  => $success,
            'error'    => $error,
        ], 'Categories', 'categories');
    }

    // -----------------------------------------------------------------------
    // POST /admin/categories/add
    // -----------------------------------------------------------------------

    public function add(Request $request, array $params): Response
    {
        $name     = trim((string) $request->post('name', ''));
        $parentId = (int) $request->post('parent_id', 0);
        $baseUrl  = $this->app->getBaseUrl();
        $session  = $this->app->getSession();

        $v = new Validator(['name' => $name]);
        $v->field('name')->required()->maxLength(150);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            return Response::redirect($baseUrl . '/admin/categories');
        }

        $categoryRepo = new CategoryRepo($this->app->getDatabase());
        $categoryRepo->add($name, $parentId > 0 ? $parentId : null, 'custom');

        $session->set('lstr_flash_success', 'Category added.');
        return Response::redirect($baseUrl . '/admin/categories');
    }

    // -----------------------------------------------------------------------
    // POST /admin/categories/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();

        $categoryRepo = new CategoryRepo($this->app->getDatabase());

        if ($categoryRepo->getById($id) === null) {
            return Response::notFound('Category not found.');
        }

        $categoryRepo->delete($id);

        $session->set('lstr_flash_success', 'Category deleted.');
        return Response::redirect($baseUrl . '/admin/categories');
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
