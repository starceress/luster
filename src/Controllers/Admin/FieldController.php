<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages additional (custom) field definitions for a single listing. Lets the
 admin add, edit, and delete extra member fields like "Favourite character" or
 "Favourite episode". Adding a field creates the database column via ALTER TABLE;
 deleting one drops the column. Field metadata (label, type, required) is stored
 in the listing registry table and read dynamically by FieldRepo.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use InvalidArgumentException;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\FieldRepo;

/**
 * Manages custom additional-field definitions for a single listing.
 *
 * Adding a field issues an ALTER TABLE on the member table; deleting one
 * drops the column. Field metadata (name, label, type, required, options) is
 * stored in the listing registry row and loaded dynamically by FieldRepo.
 */
final class FieldController extends AdminController
{
    /**
     * Field names that would collide with MembersTemplateRenderer's aggregate
     * tokens (`{fan_additional_fields}`, `{fan_additional_field_cells}`).
     */
    private const RESERVED_FIELD_NAMES = ['additional_fields', 'additional_field_cells'];

    // -----------------------------------------------------------------------
    // GET /admin/listings/{fid}/fields
    // -----------------------------------------------------------------------

    /**
     * Lists all custom fields defined for the given listing.
     */
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

        return $this->adminLayout('admin/listings/fields/index.php', 'Fields', 'listings', [
            'listing' => $listing,
            'fields'     => $fields,
            'success'    => $success,
            'error'      => $error,
        ], '', [
            $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
            $this->crumb($listing->subject, $this->adminUrl('/admin/listings/' . $listing->id . '/edit')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/fields/add
    // -----------------------------------------------------------------------

    /**
     * Validates the new field definition, adds the column via ALTER TABLE,
     * and saves the updated field list to the listing registry.
     */
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

        // These exact names would collide with MembersTemplateRenderer's aggregate
        // {fan_additional_fields} / {fan_additional_field_cells} tokens, silently
        // shadowing the field's own {fan_<name>} value in member templates.
        if (self::isReservedFieldName($name)) {
            $v->addFieldError('name', "'{$name}' is a reserved name and can't be used for a field.");
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

    /**
     * Renders the edit form (GET) or saves updated label/type/required/options
     * back to the listing registry row (POST). The DB column itself is not
     * renamed; only the metadata changes.
     */
    public function edit(Request $req, array $params): Response
    {
        $listingId = (int) ($params['fid'] ?? 0);

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
                return $this->redirectAfterFieldAction($listingId, true, 'error', implode(' ', $v->allErrors()));
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
                return $this->redirectAfterFieldAction($listingId, true, 'error', 'Field not found.');
            }

            $fieldRepo->saveFields($listingId, $fieldsArray);

            return $this->redirectAfterFieldAction($listingId, true, 'success', 'Field updated successfully.');
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
            return $this->redirectAfterFieldAction($listingId, true, 'error', 'Field not found.');
        }

        return $this->adminLayout('admin/listings/fields/edit.php', 'Edit Field', 'listings', [
            'listing' => $listing,
            'field'      => $current,
            'options'    => $current->options,
            'error'      => (string) $req->query('error', ''),
        ], '', [
            $this->crumb($listing->subject, $this->adminUrl('/admin/listings/' . $listing->id . '/edit')),
            $this->crumb('Fields', $this->adminUrl('/admin/listings/' . $listing->id . '/edit?tab=fields')),
            $this->crumb($current->label),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/listings/{fid}/fields/delete
    // -----------------------------------------------------------------------

    /**
     * Drops the field column from the member table via ALTER TABLE and removes
     * the field definition from the listing registry row.
     */
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
     * True when $name would collide with one of MembersTemplateRenderer's
     * aggregate additional-field tokens (case-sensitive exact match, matching
     * how field names are compared/stored elsewhere in this controller).
     */
    private static function isReservedFieldName(string $name): bool
    {
        return in_array($name, self::RESERVED_FIELD_NAMES, true);
    }

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
                $this->app->getBaseUrl() . '/admin/listings/' . $listingId . '/edit?tab=fields'
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

}
