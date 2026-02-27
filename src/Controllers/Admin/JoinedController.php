<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\JoinedFanlistingRepo;

/**
 * Manages the site owner's joined fanlistings list.
 *
 * Routes:
 *   GET  /admin/joined              — list, grouped by category
 *   GET  /admin/joined/add          — add form
 *   POST /admin/joined/add          — insert
 *   GET  /admin/joined/{id}/edit    — edit form
 *   POST /admin/joined/{id}/edit    — update
 *   POST /admin/joined/{id}/delete  — delete
 *   POST /admin/joined/{id}/toggle  — toggle active/inactive
 */
final class JoinedController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/joined
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $repo = new JoinedFanlistingRepo($this->app->getDatabase());

        $filterCat  = trim((string) $request->query('category', ''));
        $categories = $repo->getCategories();
        $joined     = $filterCat !== '' ? $repo->getByCategory($filterCat) : $repo->getAll();

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/joined/index.php', [
            'joined'      => $joined,
            'categories'  => $categories,
            'filter_cat'  => $filterCat,
            'success'     => $success,
            'error'       => $error,
        ], 'Joined Fanlistings', 'joined');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/joined/add
    // -----------------------------------------------------------------------

    public function add(Request $request, array $params): Response
    {
        $repo    = new JoinedFanlistingRepo($this->app->getDatabase());
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($request->isPost()) {
            $name       = trim((string) $request->post('name', ''));
            $url        = trim((string) $request->post('url', ''));
            $image      = trim((string) $request->post('image', ''));
            $category   = trim((string) $request->post('category', ''));
            $dateJoined = trim((string) $request->post('date_joined', ''));
            $active     = $request->post('active') === '1';
            $sortOrder  = (int) $request->post('sort_order', 0);

            $v = new Validator(['name' => $name, 'url' => $url]);
            $v->field('name')->required()->maxLength(255);
            $v->field('url')->required()->url()->maxLength(500);

            if ($v->fails()) {
                return $this->layout('admin/joined/form.php', [
                    'joined'  => null,
                    'error'   => implode(' ', $v->allErrors()),
                    'old'     => $request->postAll(),
                ], 'Add Joined Fanlisting', 'joined');
            }

            $repo->insert([
                'name'        => $name,
                'url'         => $url,
                'image'       => $image !== '' ? $image : null,
                'category'    => $category,
                'date_joined' => $dateJoined !== '' ? $dateJoined : null,
                'active'      => $active,
                'sort_order'  => $sortOrder,
            ]);

            $session->set('lstr_flash_success', 'Joined fanlisting added.');
            return Response::redirect($baseUrl . '/admin/joined');
        }

        return $this->layout('admin/joined/form.php', [
            'joined' => null,
            'error'  => '',
            'old'    => [],
        ], 'Add Joined Fanlisting', 'joined');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/joined/{id}/edit
    // -----------------------------------------------------------------------

    public function edit(Request $request, array $params): Response
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new JoinedFanlistingRepo($this->app->getDatabase());

        $joined = $repo->getById($id);

        if ($joined === null) {
            return Response::notFound('Joined fanlisting not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($request->isPost()) {
            $name       = trim((string) $request->post('name', ''));
            $url        = trim((string) $request->post('url', ''));
            $image      = trim((string) $request->post('image', ''));
            $category   = trim((string) $request->post('category', ''));
            $dateJoined = trim((string) $request->post('date_joined', ''));
            $active     = $request->post('active') === '1';
            $sortOrder  = (int) $request->post('sort_order', 0);

            $v = new Validator(['name' => $name, 'url' => $url]);
            $v->field('name')->required()->maxLength(255);
            $v->field('url')->required()->url()->maxLength(500);

            if ($v->fails()) {
                return $this->layout('admin/joined/form.php', [
                    'joined' => $joined,
                    'error'  => implode(' ', $v->allErrors()),
                    'old'    => $request->postAll(),
                ], 'Edit Joined Fanlisting', 'joined');
            }

            $repo->update($id, [
                'name'        => $name,
                'url'         => $url,
                'image'       => $image !== '' ? $image : null,
                'category'    => $category,
                'date_joined' => $dateJoined !== '' ? $dateJoined : null,
                'active'      => $active,
                'sort_order'  => $sortOrder,
            ]);

            $session->set('lstr_flash_success', 'Joined fanlisting updated.');
            return Response::redirect($baseUrl . '/admin/joined');
        }

        return $this->layout('admin/joined/form.php', [
            'joined' => $joined,
            'error'  => '',
            'old'    => [],
        ], 'Edit Joined Fanlisting', 'joined');
    }

    // -----------------------------------------------------------------------
    // POST /admin/joined/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new JoinedFanlistingRepo($this->app->getDatabase());

        if ($repo->getById($id) === null) {
            return Response::notFound('Joined fanlisting not found.');
        }

        $repo->delete($id);

        $session = $this->app->getSession();
        $session->set('lstr_flash_success', 'Joined fanlisting deleted.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/joined');
    }

    // -----------------------------------------------------------------------
    // POST /admin/joined/{id}/toggle
    // -----------------------------------------------------------------------

    public function toggle(Request $request, array $params): Response
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new JoinedFanlistingRepo($this->app->getDatabase());

        if ($repo->getById($id) === null) {
            return Response::notFound('Joined fanlisting not found.');
        }

        $repo->toggleActive($id);

        $session = $this->app->getSession();
        $session->set('lstr_flash_success', 'Status toggled.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/joined');
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
