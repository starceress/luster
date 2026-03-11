<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use InvalidArgumentException;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FieldRepo;

/**
 * Manages additional field definitions for a single fanlisting.
 *
 * Routes (all scoped under /admin/fanlistings/{fid}/fields):
 *   GET  /                  → index()   — field list + add form
 *   POST /add               → add()     — create field + ALTER TABLE column
 *   GET  /edit?name={name}  → edit()    — edit form for one field
 *   POST /edit              → edit()    — save label / type / required changes
 *   POST /delete            → delete()  — drop column + remove from definition
 *
 * Feedback is passed via ?success= / ?error= query params so no session
 * flash storage is required.
 *
 * NOTE: Constructor accepts Application (not the four individual deps) because
 * the router resolver always passes Application to every controller via
 *   new $class($this)
 * Changing the signature here would require updating the resolver closure.
 */
final class FieldController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/fanlistings/{fid}/fields
    // -----------------------------------------------------------------------

    public function index(Request $req, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);

        [$fanlisting, $fieldRepo] = $this->resolve($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $fields  = $fieldRepo->getFieldsForFanlisting($fanlistingId);
        $success = (string) $req->query('success', '');
        $error   = (string) $req->query('error', '');

        return $this->layout('admin/fields/index.php', [
            'fanlisting' => $fanlisting,
            'fields'     => $fields,
            'success'    => $success,
            'error'      => $error,
        ], 'Fields — ' . $fanlisting->name, 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/{fid}/fields/add
    // -----------------------------------------------------------------------

    public function add(Request $req, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $fromEdit     = $req->post('from_edit', '') === '1';

        if (!$req->isPost()) {
            return $this->redirectAfterFieldAction($fanlistingId, $fromEdit, 'error', 'Invalid request method.');
        }

        [$fanlisting, $fieldRepo] = $this->resolve($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $name     = trim((string) $req->post('name', ''));
        $label    = trim((string) $req->post('label', ''));
        $type     = trim((string) $req->post('type', 'text'));
        $required = (string) $req->post('required', '0');
        $options  = trim((string) $req->post('options', ''));

        $v = new Validator([
            'name'     => $name,
            'label'    => $label,
            'type'     => $type,
            'required' => $required,
        ]);

        $v->field('name')->required()->maxLength(30);

        // Pattern validated separately — Validator has no alphanumeric_underscore rule
        if ($name !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            $v->addFieldError('name', 'The name field may only contain letters, numbers, and underscores.');
        }

        $v->field('label')->required()->maxLength(100);
        $v->field('type')->required()->in(['text', 'dropdown', 'checkbox', 'radio']);
        $v->field('required')->in(['0', '1']);

        if ($v->fails()) {
            return $this->redirectAfterFieldAction($fanlistingId, $fromEdit, 'error', implode(' ', $v->allErrors()));
        }

        $existingFields = $fieldRepo->getFieldsForFanlisting($fanlistingId);

        foreach ($existingFields as $f) {
            if ($f->name === $name) {
                return $this->redirectAfterFieldAction($fanlistingId, $fromEdit, 'error', "A field named '{$name}' already exists.");
            }
        }

        // Build serializable array from existing AdditionalField objects
        $fieldsArray = $this->toArray($existingFields);
        $fieldsArray[] = [
            'name'     => $name,
            'label'    => $label,
            'type'     => $type,
            'required' => $required === '1',
            'options'  => $options,
        ];

        try {
            // Add the DB column first; if it fails, the definition is not saved
            $fieldRepo->addColumn($fanlisting->dbprefix, $name, $options);
        } catch (InvalidArgumentException $e) {
            return $this->redirectAfterFieldAction($fanlistingId, $fromEdit, 'error', $e->getMessage());
        }

        $fieldRepo->saveFields($fanlistingId, $fieldsArray);

        return $this->redirectAfterFieldAction($fanlistingId, $fromEdit, 'success', 'Field added successfully.');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/fanlistings/{fid}/fields/edit
    // -----------------------------------------------------------------------

    public function edit(Request $req, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $indexUrl     = $this->indexUrl($fanlistingId);

        [$fanlisting, $fieldRepo] = $this->resolve($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $fields = $fieldRepo->getFieldsForFanlisting($fanlistingId);

        // ---- POST: save ----
        if ($req->isPost()) {
            $fieldName = trim((string) $req->post('field_name', ''));
            $label     = trim((string) $req->post('label', ''));
            $type      = trim((string) $req->post('type', 'text'));
            $required  = (string) $req->post('required', '0');
            $options   = trim((string) $req->post('options', ''));

            $v = new Validator([
                'label'    => $label,
                'type'     => $type,
                'required' => $required,
            ]);

            $v->field('label')->required()->maxLength(100);
            $v->field('type')->required()->in(['text', 'dropdown', 'checkbox', 'radio']);
            $v->field('required')->in(['0', '1']);

            if ($v->fails()) {
                return Response::redirect(
                    $indexUrl . '?error=' . urlencode(implode(' ', $v->allErrors()))
                );
            }

            $updated     = false;
            $fieldsArray = [];

            foreach ($fields as $f) {
                if ($f->name === $fieldName) {
                    $fieldsArray[] = [
                        'name'     => $f->name,
                        'label'    => $label,
                        'type'     => $type,
                        'required' => $required === '1',
                        'options'  => $options,
                    ];
                    $updated = true;
                } else {
                    $fieldsArray[] = [
                        'name'     => $f->name,
                        'label'    => $f->label,
                        'type'     => $f->type,
                        'required' => $f->required,
                        'options'  => $f->options ?? '',
                    ];
                }
            }

            if (!$updated) {
                return Response::redirect($indexUrl . '?error=' . urlencode('Field not found.'));
            }

            $fieldRepo->saveFields($fanlistingId, $fieldsArray);

            return Response::redirect($indexUrl . '?success=' . urlencode('Field updated successfully.'));
        }

        // ---- GET: show form ----
        $fieldName = trim((string) ($params['name'] ?? ''));
        $current   = null;

        foreach ($fields as $f) {
            if ($f->name === $fieldName) {
                $current = $f;
                break;
            }
        }

        if ($current === null) {
            return Response::redirect($indexUrl . '?error=' . urlencode('Field not found.'));
        }

        return $this->layout('admin/fields/edit.php', [
            'fanlisting' => $fanlisting,
            'field'      => $current,
            'options'    => $current->options,
            'error'      => (string) $req->query('error', ''),
        ], 'Edit Field — ' . $fanlisting->name, 'fanlistings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/fanlistings/{fid}/fields/delete
    // -----------------------------------------------------------------------

    public function delete(Request $req, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $fromEdit     = $req->post('from_edit', '') === '1';

        if (!$req->isPost()) {
            return $this->redirectAfterFieldAction($fanlistingId, $fromEdit, 'error', 'Invalid request method.');
        }

        [$fanlisting, $fieldRepo] = $this->resolve($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $fieldName = trim((string) ($params['name'] ?? ''));

        if ($fieldName === '') {
            return $this->redirectAfterFieldAction($fanlistingId, $fromEdit, 'error', 'No field specified.');
        }

        $fields      = $fieldRepo->getFieldsForFanlisting($fanlistingId);
        $found       = false;
        $fieldsArray = [];

        foreach ($fields as $f) {
            if ($f->name === $fieldName) {
                $found = true;
                // Omit from rebuilt array — this is the field being deleted
            } else {
                $fieldsArray[] = [
                    'name'     => $f->name,
                    'label'    => $f->label,
                    'type'     => $f->type,
                    'required' => $f->required,
                ];
            }
        }

        if (!$found) {
            return $this->redirectAfterFieldAction($fanlistingId, $fromEdit, 'error', 'Field not found.');
        }

        try {
            // Drop column first; if it fails, definition is unchanged
            $fieldRepo->dropColumn($fanlisting->dbprefix, $fieldName);
        } catch (InvalidArgumentException $e) {
            return $this->redirectAfterFieldAction($fanlistingId, $fromEdit, 'error', $e->getMessage());
        }

        $fieldRepo->saveFields($fanlistingId, $fieldsArray);

        return $this->redirectAfterFieldAction($fanlistingId, $fromEdit, 'success', 'Field deleted successfully.');
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Loads the fanlisting and a FieldRepo instance together.
     *
     * @return array{0: \Luster\Models\Fanlisting|null, 1: FieldRepo}
     */
    private function resolve(int $fanlistingId): array
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $fieldRepo      = new FieldRepo($db, $config);

        return [$fanlistingRepo->getById($fanlistingId), $fieldRepo];
    }

    /**
     * Returns the base URL for the field index of a fanlisting.
     */
    private function indexUrl(int $fanlistingId): string
    {
        return $this->app->getBaseUrl() . '/admin/fanlistings/' . $fanlistingId . '/fields';
    }

    /**
     * Builds the redirect Response after a field action.
     *
     * When $fromEdit is true, the user submitted from within the fanlisting
     * edit page — redirect back there using session flash so the message is
     * displayed by FanlistingController::edit().
     * Otherwise, redirect to the standalone fields index with a query param.
     */
    private function redirectAfterFieldAction(
        int    $fanlistingId,
        bool   $fromEdit,
        string $type,
        string $message
    ): Response {
        if ($fromEdit) {
            $session = $this->app->getSession();
            if ($type === 'success') {
                $session->set('lstr_flash_success', $message);
            } else {
                $session->set('lstr_flash_error', $message);
            }
            return Response::redirect(
                $this->app->getBaseUrl() . '/admin/fanlistings/' . $fanlistingId . '/edit'
            );
        }

        $param = $type === 'success' ? 'success' : 'error';
        return Response::redirect($this->indexUrl($fanlistingId) . '?' . $param . '=' . urlencode($message));
    }

    /**
     * Converts an array of AdditionalField objects to plain arrays suitable
     * for serialization by FieldRepo::saveFields().
     *
     * @param \Luster\Models\AdditionalField[] $fields
     * @return array<int, array{name: string, label: string, type: string, required: bool}>
     */
    private function toArray(array $fields): array
    {
        return array_map(fn($f) => [
            'name'     => $f->name,
            'label'    => $f->label,
            'type'     => $f->type,
            'required' => $f->required,
            'options'  => $f->options,
        ], $fields);
    }

    /**
     * Renders an inner template wrapped in the admin layout.
     *
     * @param array<string, mixed> $data
     */
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
