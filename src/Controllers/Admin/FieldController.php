<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use InvalidArgumentException;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\FieldRepo;

/**
 * Manages additional field definitions for a single listing.
 *
 * Routes (all scoped under /admin/listings/{fid}/fields):
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
    // GET /admin/listings/{fid}/fields
    // -----------------------------------------------------------------------

    public function index(Request $req, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);

        [$listing, $fieldRepo] = $this->resolve($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $fields  = $fieldRepo->getFieldsForListing($listingId);
        $success = (string) $req->query('success', '');
        $error   = (string) $req->query('error', '');

        return $this->layout('admin/fields/index.php', [
            'listing' => $listing,
            'fields'     => $fields,
            'success'    => $success,
            'error'      => $error,
        ], 'Fields — ' . $listing->name, 'listings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/fields/add
    // -----------------------------------------------------------------------

    public function add(Request $req, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $fromEdit     = $req->post('from_edit', '') === '1';

        if (!$req->isPost()) {
            return $this->redirectAfterFieldAction($listingId, $fromEdit, 'error', 'Invalid request method.');
        }

        [$listing, $fieldRepo] = $this->resolve($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
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
            return $this->redirectAfterFieldAction($listingId, $fromEdit, 'error', implode(' ', $v->allErrors()));
        }

        $existingFields = $fieldRepo->getFieldsForListing($listingId);

        foreach ($existingFields as $f) {
            if ($f->name === $name) {
                return $this->redirectAfterFieldAction($listingId, $fromEdit, 'error', "A field named '{$name}' already exists.");
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
            $fieldRepo->addColumn($listing->dbprefix, $name, $options);
        } catch (InvalidArgumentException $e) {
            return $this->redirectAfterFieldAction($listingId, $fromEdit, 'error', $e->getMessage());
        }

        $fieldRepo->saveFields($listingId, $fieldsArray);

        return $this->redirectAfterFieldAction($listingId, $fromEdit, 'success', 'Field added successfully.');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/listings/{fid}/fields/edit
    // -----------------------------------------------------------------------

    public function edit(Request $req, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $indexUrl     = $this->indexUrl($listingId);

        [$listing, $fieldRepo] = $this->resolve($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $fields = $fieldRepo->getFieldsForListing($listingId);

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

            $fieldRepo->saveFields($listingId, $fieldsArray);

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
            'listing' => $listing,
            'field'      => $current,
            'options'    => $current->options,
            'error'      => (string) $req->query('error', ''),
        ], 'Edit Field — ' . $listing->name, 'listings');
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/fields/delete
    // -----------------------------------------------------------------------

    public function delete(Request $req, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);
        $fromEdit     = $req->post('from_edit', '') === '1';

        if (!$req->isPost()) {
            return $this->redirectAfterFieldAction($listingId, $fromEdit, 'error', 'Invalid request method.');
        }

        [$listing, $fieldRepo] = $this->resolve($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $fieldName = trim((string) ($params['name'] ?? ''));

        if ($fieldName === '') {
            return $this->redirectAfterFieldAction($listingId, $fromEdit, 'error', 'No field specified.');
        }

        $fields      = $fieldRepo->getFieldsForListing($listingId);
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
            return $this->redirectAfterFieldAction($listingId, $fromEdit, 'error', 'Field not found.');
        }

        try {
            // Drop column first; if it fails, definition is unchanged
            $fieldRepo->dropColumn($listing->dbprefix, $fieldName);
        } catch (InvalidArgumentException $e) {
            return $this->redirectAfterFieldAction($listingId, $fromEdit, 'error', $e->getMessage());
        }

        $fieldRepo->saveFields($listingId, $fieldsArray);

        return $this->redirectAfterFieldAction($listingId, $fromEdit, 'success', 'Field deleted successfully.');
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Loads the listing and a FieldRepo instance together.
     *
     * @return array{0: \Luster\Models\Listing|null, 1: FieldRepo}
     */
    private function resolve(int $listingId): array
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $fieldRepo      = new FieldRepo($db, $config);

        return [$listingRepo->getById($listingId), $fieldRepo];
    }

    /**
     * Returns the base URL for the field index of a listing.
     */
    private function indexUrl(int $listingId): string
    {
        return $this->app->getBaseUrl() . '/admin/listings/' . $listingId . '/fields';
    }

    /**
     * Builds the redirect Response after a field action.
     *
     * When $fromEdit is true, the user submitted from within the listing
     * edit page — redirect back there using session flash so the message is
     * displayed by ListingController::edit().
     * Otherwise, redirect to the standalone fields index with a query param.
     */
    private function redirectAfterFieldAction(
        int    $listingId,
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
                $this->app->getBaseUrl() . '/admin/listings/' . $listingId . '/edit'
            );
        }

        $param = $type === 'success' ? 'success' : 'error';
        return Response::redirect($this->indexUrl($listingId) . '?' . $param . '=' . urlencode($message));
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
