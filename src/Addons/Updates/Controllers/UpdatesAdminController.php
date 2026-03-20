<?php

declare(strict_types=1);

namespace Luster\Addons\Updates\Controllers;

use Luster\Addons\Updates\Update;
use Luster\Addons\Updates\UpdateRepo;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;

/**
 * Admin controller for the Updates add-on.
 *
 * All state-changing actions require CSRF validation (handled globally by
 * CsrfMiddleware) and log to the activity log.
 */
final class UpdatesAdminController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/updates  — overview index
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $updateRepo  = new UpdateRepo($db);

        $listings      = $listingRepo->getAll();
        $latestPerFl   = $updateRepo->getLatestPerFanlisting();
        $latestColl    = $updateRepo->getLatestCollective();
        $collectiveCnt = $updateRepo->countCollective();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/addons/updates/index.php', [
            'listings'       => $listings,
            'latest_per_fl'  => $latestPerFl,
            'latest_coll'    => $latestColl,
            'collective_cnt' => $collectiveCnt,
            'success'        => $success,
            'error'          => $error,
        ], 'Updates', 'addons');
    }

    // -----------------------------------------------------------------------
    // GET /admin/updates/collective  — collective updates list
    // -----------------------------------------------------------------------

    public function collectiveList(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $config  = $this->app->getConfig();

        $perPage = max(1, (int) $config->get('per_page', 20));
        $page    = max(1, (int) ($request->query('page', 1)));

        $updateRepo = new UpdateRepo($db);
        $updates    = $updateRepo->getCollective($page, $perPage);
        $total      = $updateRepo->countCollective();
        $pages      = max(1, (int) ceil($total / $perPage));

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/addons/updates/list.php', [
            'updates'       => $updates,
            'total'         => $total,
            'page'          => $page,
            'pages'         => $pages,
            'is_collective' => true,
            'listing'       => null,
            'success'       => $success,
            'error'         => $error,
        ], 'Updates — Collective', 'addons', 'Collective');
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/updates/collective/add
    // -----------------------------------------------------------------------

    public function collectiveAddForm(Request $request, array $params): Response
    {
        return $this->renderAddForm($request, null, 'Collective');
    }

    public function collectiveAdd(Request $request, array $params): Response
    {
        return $this->handleAdd($request, null, 'Collective');
    }

    // -----------------------------------------------------------------------
    // GET /admin/updates/{fid}  — per-fanlisting list
    // -----------------------------------------------------------------------

    public function list(Request $request, array $params): Response
    {
        $fid = (int) ($params['fid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($fid);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $perPage = max(1, (int) $config->get('per_page', 20));
        $page    = max(1, (int) ($request->query('page', 1)));

        $updateRepo = new UpdateRepo($db);
        $updates    = $updateRepo->getForFanlisting($fid, $page, $perPage);
        $total      = $updateRepo->countForFanlisting($fid);
        $pages      = max(1, (int) ceil($total / $perPage));

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/addons/updates/list.php', [
            'updates'       => $updates,
            'total'         => $total,
            'page'          => $page,
            'pages'         => $pages,
            'is_collective' => false,
            'listing'       => $listing,
            'success'       => $success,
            'error'         => $error,
        ], 'Updates — ' . $listing->name, 'addons', 'Owned › ' . $listing->name);
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/updates/{fid}/add
    // -----------------------------------------------------------------------

    public function addForm(Request $request, array $params): Response
    {
        $fid     = (int) ($params['fid'] ?? 0);
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $listing = (new ListingRepo($db, $config))->getById($fid);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        return $this->renderAddForm($request, $fid, 'Owned › ' . $listing->name);
    }

    public function add(Request $request, array $params): Response
    {
        $fid     = (int) ($params['fid'] ?? 0);
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $listing = (new ListingRepo($db, $config))->getById($fid);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        return $this->handleAdd($request, $fid, 'Owned › ' . $listing->name);
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/updates/entry/{id}/edit
    // -----------------------------------------------------------------------

    public function editForm(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $updateRepo = new UpdateRepo($db);
        $update     = $updateRepo->getById($id);

        if ($update === null) {
            return Response::notFound('Update not found.');
        }

        [$subtitle, $backUrl] = $this->subtitleAndBack($update, $config, $db);

        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/addons/updates/form.php', [
            'update'           => $update,
            'known_categories' => $updateRepo->getKnownCategories(),
            'back_url'         => $backUrl,
            'is_edit'          => true,
            'error'            => $error,
        ], 'Edit Update', 'addons', $subtitle);
    }

    public function edit(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $updateRepo = new UpdateRepo($db);
        $update     = $updateRepo->getById($id);

        if ($update === null) {
            return Response::notFound('Update not found.');
        }

        [$subtitle, $backUrl] = $this->subtitleAndBack($update, $config, $db);

        $title    = trim((string) $request->post('title',     ''));
        $body     = trim((string) $request->post('body',      ''));
        $category = trim((string) $request->post('category',  ''));
        $postedAt = trim((string) $request->post('posted_at', ''));

        $errors = $this->validateFields($title, $body, $postedAt);

        if (!empty($errors)) {
            $session->set('lstr_flash_error', implode(' ', $errors));
            return Response::redirect($baseUrl . '/admin/updates/entry/' . $id . '/edit');
        }

        $updateRepo->update($id, [
            'title'     => $title,
            'body'      => $body,
            'category'  => $category,
            'posted_at' => $postedAt,
        ]);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'update_edited',
            'Edited update "' . $title . '"',
        );

        $session->set('lstr_flash_success', 'Update saved.');
        return Response::redirect($baseUrl . $backUrl);
    }

    // -----------------------------------------------------------------------
    // POST /admin/updates/entry/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $updateRepo = new UpdateRepo($db);
        $update     = $updateRepo->getById($id);

        if ($update === null) {
            return Response::notFound('Update not found.');
        }

        [, $backUrl] = $this->subtitleAndBack($update, $config, $db);

        $updateRepo->delete($id);

        $context = $update->fanlistingId === null
            ? 'Collective'
            : 'listing #' . $update->fanlistingId;

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'update_deleted',
            'Deleted update "' . $update->title . '" for ' . $context,
        );

        $session->set('lstr_flash_success', 'Update deleted.');
        return Response::redirect($baseUrl . $backUrl);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Shared GET form renderer for add (both collective and per-fanlisting).
     */
    private function renderAddForm(Request $request, ?int $fanlistingId, string $subtitle): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $updateRepo = new UpdateRepo($db);

        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        $backUrl = $fanlistingId !== null
            ? $baseUrl . '/admin/updates/' . $fanlistingId
            : $baseUrl . '/admin/updates/collective';

        return $this->layout('admin/addons/updates/form.php', [
            'update'           => null,
            'fanlisting_id'    => $fanlistingId,
            'known_categories' => $updateRepo->getKnownCategories(),
            'back_url'         => $backUrl,
            'is_edit'          => false,
            'error'            => $error,
        ], 'Add Update', 'addons', $subtitle);
    }

    /**
     * Shared POST handler for add (both collective and per-fanlisting).
     */
    private function handleAdd(Request $request, ?int $fanlistingId, string $subtitle): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $title    = trim((string) $request->post('title',     ''));
        $body     = trim((string) $request->post('body',      ''));
        $category = trim((string) $request->post('category',  ''));
        $postedAt = trim((string) $request->post('posted_at', ''));
        $author   = (string) $session->get('lstr_admin_username', '');

        $errors = $this->validateFields($title, $body, $postedAt);

        $addUrl = $fanlistingId !== null
            ? $baseUrl . '/admin/updates/' . $fanlistingId . '/add'
            : $baseUrl . '/admin/updates/collective/add';

        $backUrl = $fanlistingId !== null
            ? $baseUrl . '/admin/updates/' . $fanlistingId
            : $baseUrl . '/admin/updates/collective';

        if (!empty($errors)) {
            $session->set('lstr_flash_error', implode(' ', $errors));
            return Response::redirect($addUrl);
        }

        $updateRepo = new UpdateRepo($db);
        $updateRepo->insert($fanlistingId, [
            'title'     => $title,
            'body'      => $body,
            'category'  => $category,
            'author'    => $author,
            'posted_at' => $postedAt,
        ]);

        $context = $fanlistingId !== null
            ? 'listing #' . $fanlistingId
            : 'Collective';

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'update_added',
            'Added update "' . $title . '" for ' . $context,
        );

        $session->set('lstr_flash_success', 'Update posted.');
        return Response::redirect($backUrl);
    }

    /**
     * Validates shared fields. Returns array of error strings (empty = valid).
     *
     * @return string[]
     */
    private function validateFields(string $title, string $body, string $postedAt): array
    {
        $errors = [];

        if ($title === '') {
            $errors[] = 'Title is required.';
        } elseif (mb_strlen($title) > 255) {
            $errors[] = 'Title must be 255 characters or fewer.';
        }

        if ($body === '') {
            $errors[] = 'Body is required.';
        }

        if ($postedAt === '') {
            $errors[] = 'Date is required.';
        } elseif (\DateTime::createFromFormat('Y-m-d\TH:i', $postedAt) === false
               && \DateTime::createFromFormat('Y-m-d H:i:s', $postedAt) === false
               && \DateTime::createFromFormat('Y-m-d H:i', $postedAt) === false) {
            $errors[] = 'Date is not a valid datetime.';
        }

        return $errors;
    }

    /**
     * Derives page subtitle and back URL for an existing update (edit/delete).
     *
     * @return array{0: string, 1: string}  [subtitle, backUrl (relative to baseUrl)]
     */
    private function subtitleAndBack(Update $update, mixed $config, mixed $db): array
    {
        if ($update->fanlistingId === null) {
            return ['Collective', '/admin/updates/collective'];
        }

        $listing = (new ListingRepo($db, $config))->getById($update->fanlistingId);
        $name    = $listing?->name ?? ('Listing #' . $update->fanlistingId);

        return [
            'Owned › ' . $name,
            '/admin/updates/' . $update->fanlistingId,
        ];
    }

    /**
     * Shared admin layout renderer — same pattern as AffiliateController::layout().
     */
    private function layout(
        string $template,
        array  $data,
        string $pageTitle,
        string $section,
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
