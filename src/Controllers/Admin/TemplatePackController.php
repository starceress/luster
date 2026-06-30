<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Template pack export/import, copy between listings, and revision history.
 All endpoints are AJAX-friendly (JSON responses), except export() which
 returns a JSON file download.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Version;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\TemplateRevisionRepo;

final class TemplatePackController
{
    /** Template keys included in export/import/copy operations. */
    private const TEMPLATE_KEYS = [
        'stats_template',
        'members_template',
        'affiliates_section_template',
        'updates_template',
        'codes_template',
        'codes_submit_form_template',
        'join_form_template',
        'affiliate_apply_form_template',
        'contact_form_template',
        'update_form_template',
        'reset_form_template',
    ];

    /** Human-readable labels for template keys. */
    private const TEMPLATE_LABELS = [
        'stats_template'                => 'Stats',
        'members_template'              => 'Members',
        'affiliates_section_template'   => 'Affiliates Section',
        'updates_template'              => 'Updates Feed',
        'codes_template'                => 'Codes',
        'codes_submit_form_template'    => 'Code Submit Form',
        'join_form_template'            => 'Join Form',
        'affiliate_apply_form_template' => 'Affiliate Apply Form',
        'contact_form_template'         => 'Contact Form',
        'update_form_template'          => 'Update Form',
        'reset_form_template'           => 'Password Reset Form',
    ];

    /** Token substrings that indicate a required add-on. */
    private const ADDON_SIGNALS = [
        'Updates'   => ['{each_update}', '{update_title}', '{update_body}'],
        'Rules'     => ['{rules_body}', '{rules_title}'],
        'Adoption'  => ['{adopt_form}', 'adopt_form_template'],
        'Wishlist'  => ['{wishlist_'],
        'Kim'       => ['{kim_name}', '{kim_reset}', 'kim_signup'],
    ];

    public function __construct(private readonly Application $app) {}

    // -------------------------------------------------------------------------
    // POST /admin/listings/{id}/template-pack/export
    // -------------------------------------------------------------------------

    /**
     * Exports all per-listing template overrides as a versioned JSON pack.
     * Responds with a downloadable JSON file.
     */
    public function export(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listing = (new ListingRepo($db, $config))->getById($id);
        if ($listing === null) {
            return Response::json(['error' => 'Listing not found.'], 404);
        }

        $settingsRepo = new ListingSettingsRepo($db);
        $templates    = $settingsRepo->getTemplates($id);
        $templates['codes_template'] = $settingsRepo->getCodesTemplate($id);

        $pack = [
            'luster_template_pack' => '1.0',
            'luster_version'       => Version::CURRENT,
            'exported_at'          => gmdate('Y-m-d\TH:i:s\Z'),
            'scope'                => 'listing',
            'listing_title'        => $listing->title,
            'listing_subject'      => $listing->subject,
            'required_addons'      => $this->detectRequiredAddons($templates),
            'templates'            => [],
        ];

        foreach (self::TEMPLATE_KEYS as $key) {
            $value = $templates[$key] ?? null;
            if ($value !== null) {
                $pack['templates'][$key] = $value;
            }
        }

        $filename = 'luster-templates-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($listing->title)) . '.json';
        $json = json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return Response::download($json, $filename, 'application/json');
    }

    // -------------------------------------------------------------------------
    // POST /admin/listings/{id}/template-pack/import-preview
    // -------------------------------------------------------------------------

    /**
     * Parses an uploaded template pack JSON file and returns a preview of what
     * will change. The client stores the parsed data and sends it back on confirm.
     *
     * JSON response: {ok, metadata, changes: [{key, label, current, incoming, changed}]}
     */
    public function importPreview(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listing = (new ListingRepo($db, $config))->getById($id);
        if ($listing === null) {
            return Response::json(['error' => 'Listing not found.'], 404);
        }

        $file = $_FILES['template_pack'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'No file uploaded.']);
        }

        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false || strlen($raw) > 2 * 1024 * 1024) {
            return Response::json(['error' => 'File too large or unreadable.']);
        }

        $pack = json_decode($raw, true);
        if (!is_array($pack) || ($pack['luster_template_pack'] ?? '') !== '1.0') {
            return Response::json(['error' => 'Not a valid Luster template pack (missing or wrong version header).']);
        }

        $incoming = $pack['templates'] ?? [];
        if (!is_array($incoming)) {
            return Response::json(['error' => 'Pack has no templates section.']);
        }

        $settingsRepo = new ListingSettingsRepo($db);
        $current      = $settingsRepo->getTemplates($id);
        $current['codes_template'] = $settingsRepo->getCodesTemplate($id);

        $changes = [];
        foreach (self::TEMPLATE_KEYS as $key) {
            if (!array_key_exists($key, $incoming)) {
                continue;
            }
            $incomingValue = is_string($incoming[$key]) ? $incoming[$key] : null;
            $currentValue  = $current[$key] ?? null;
            $changed = ($this->norm($incomingValue) !== $this->norm($currentValue));

            $changes[] = [
                'key'      => $key,
                'label'    => self::TEMPLATE_LABELS[$key] ?? $key,
                'current'  => $currentValue,
                'incoming' => $incomingValue,
                'changed'  => $changed,
            ];
        }

        $requiredAddons = $pack['required_addons'] ?? [];
        $missingAddons  = array_values(array_filter(
            is_array($requiredAddons) ? $requiredAddons : [],
            fn(string $addon): bool => !$this->app->isAddonInstalled($addon),
        ));

        return Response::json([
            'ok'       => true,
            'metadata' => [
                'luster_version'  => (string) ($pack['luster_version']  ?? ''),
                'exported_at'     => (string) ($pack['exported_at']     ?? ''),
                'listing_title'   => (string) ($pack['listing_title']   ?? ''),
                'listing_subject' => (string) ($pack['listing_subject'] ?? ''),
                'required_addons' => $requiredAddons,
                'missing_addons'  => $missingAddons,
            ],
            'changes'  => $changes,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/listings/{id}/template-pack/import-apply
    // -------------------------------------------------------------------------

    /**
     * Applies a selected subset of template values received as JSON body.
     * The client sends only the confirmed keys.
     *
     * Expected JSON body: {templates: {key: value, ...}}
     * JSON response: {ok, saved: int}
     */
    public function importApply(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $adminId = (int) $session->get('lstr_admin_id');

        $listing = (new ListingRepo($db, $config))->getById($id);
        if ($listing === null) {
            return Response::json(['error' => 'Listing not found.'], 404);
        }

        $body      = (string) $request->post('templates_json', '');
        $templates = json_decode($body, true);
        if (!is_array($templates)) {
            return Response::json(['error' => 'Invalid payload.']);
        }

        $settingsRepo  = new ListingSettingsRepo($db);
        $revisionRepo  = new TemplateRevisionRepo($db);
        $currentValues = $settingsRepo->getTemplates($id);
        $currentValues['codes_template'] = $settingsRepo->getCodesTemplate($id);

        // Build the set of changes to apply (only keys the caller confirmed)
        $incoming = [];
        foreach (self::TEMPLATE_KEYS as $key) {
            if (!array_key_exists($key, $templates)) {
                // Key not in the import payload — preserve existing
                $incoming[$key] = $currentValues[$key] ?? null;
            } else {
                $incoming[$key] = is_string($templates[$key]) ? ($templates[$key] !== '' ? $templates[$key] : null) : null;
            }
        }

        // Record revisions for changed keys
        $revisionRepo->recordChanged($id, $currentValues, $incoming, $adminId);

        $this->applyTemplates($settingsRepo, $id, $incoming, $currentValues);

        $saved = count(array_filter(
            self::TEMPLATE_KEYS,
            fn(string $k): bool => array_key_exists($k, $templates),
        ));

        return Response::json(['ok' => true, 'saved' => $saved]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/listings/{id}/template-pack/copy-source?from={sourceId}
    // -------------------------------------------------------------------------

    /**
     * Returns the template values from another listing for copy-preview.
     * JSON response: {ok, listing_title, templates: {key: value, ...}}
     */
    public function copySource(Request $request, array $params): Response
    {
        $id       = (int) ($params['id']    ?? 0);
        $sourceId = (int) ($request->query('from', '0'));

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        if ($sourceId === 0 || $sourceId === $id) {
            return Response::json(['error' => 'Invalid source listing.']);
        }

        $listingRepo  = new ListingRepo($db, $config);
        $sourceListing = $listingRepo->getById($sourceId);
        if ($sourceListing === null) {
            return Response::json(['error' => 'Source listing not found.']);
        }

        $settingsRepo = new ListingSettingsRepo($db);
        $templates    = $settingsRepo->getTemplates($sourceId);
        $templates['codes_template'] = $settingsRepo->getCodesTemplate($sourceId);

        $current = $settingsRepo->getTemplates($id);
        $current['codes_template'] = $settingsRepo->getCodesTemplate($id);

        $changes = [];
        foreach (self::TEMPLATE_KEYS as $key) {
            $incomingValue = $templates[$key] ?? null;
            if ($incomingValue === null) {
                continue;
            }
            $changed = ($this->norm($incomingValue) !== $this->norm($current[$key] ?? null));
            $changes[] = [
                'key'      => $key,
                'label'    => self::TEMPLATE_LABELS[$key] ?? $key,
                'current'  => $current[$key] ?? null,
                'incoming' => $incomingValue,
                'changed'  => $changed,
            ];
        }

        return Response::json([
            'ok'            => true,
            'listing_title' => $sourceListing->title,
            'changes'       => $changes,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/listings/{id}/template-pack/copy-apply
    // -------------------------------------------------------------------------

    /**
     * Copies confirmed templates from a source listing to this listing.
     * Expected POST: from (int), templates_json (JSON array of keys to copy)
     * JSON response: {ok, saved: int}
     */
    public function copyApply(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $adminId  = (int) $session->get('lstr_admin_id');
        $sourceId = (int) $request->post('from', 0);

        if ($sourceId === 0 || $sourceId === $id) {
            return Response::json(['error' => 'Invalid source listing.']);
        }

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($id) === null || $listingRepo->getById($sourceId) === null) {
            return Response::json(['error' => 'Listing not found.'], 404);
        }

        $keysJson    = (string) $request->post('keys_json', '[]');
        $selectedKeys = json_decode($keysJson, true);
        if (!is_array($selectedKeys)) {
            return Response::json(['error' => 'Invalid keys payload.']);
        }

        $validKeys = array_intersect($selectedKeys, self::TEMPLATE_KEYS);

        $settingsRepo = new ListingSettingsRepo($db);
        $revisionRepo = new TemplateRevisionRepo($db);

        $sourceTemplates  = $settingsRepo->getTemplates($sourceId);
        $sourceTemplates['codes_template'] = $settingsRepo->getCodesTemplate($sourceId);

        $currentValues = $settingsRepo->getTemplates($id);
        $currentValues['codes_template'] = $settingsRepo->getCodesTemplate($id);

        // Only include keys that exist in the source and were confirmed
        $incoming = $currentValues; // start with current to preserve non-copied keys
        foreach ($validKeys as $key) {
            if (array_key_exists($key, $sourceTemplates) && $sourceTemplates[$key] !== null) {
                $incoming[$key] = $sourceTemplates[$key];
            }
        }

        $revisionRepo->recordChanged($id, $currentValues, $incoming, $adminId);
        $this->applyTemplates($settingsRepo, $id, $incoming, $currentValues);

        return Response::json(['ok' => true, 'saved' => count($validKeys)]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/listings/{id}/template-revisions?key={template_key}
    // -------------------------------------------------------------------------

    /**
     * Returns the revision history for one template key.
     * JSON response: {ok, revisions: [{id, saved_at, preview}]}
     */
    public function revisions(Request $request, array $params): Response
    {
        $id  = (int) ($params['id'] ?? 0);
        $key = (string) ($request->query('key', ''));

        if (!in_array($key, self::TEMPLATE_KEYS, true)) {
            return Response::json(['error' => 'Unknown template key.']);
        }

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        if ((new ListingRepo($db, $config))->getById($id) === null) {
            return Response::json(['error' => 'Listing not found.'], 404);
        }

        $revisionRepo = new TemplateRevisionRepo($db);
        $history      = $revisionRepo->getHistory($id, $key);

        $result = array_map(static fn(array $r): array => [
            'id'       => $r['id'],
            'saved_at' => $r['saved_at'],
            'preview'  => mb_substr((string) ($r['value'] ?? ''), 0, 200),
            'has_value' => $r['value'] !== null,
        ], $history);

        return Response::json(['ok' => true, 'revisions' => $result]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/listings/{id}/template-revisions/{revisionId}
    // -------------------------------------------------------------------------

    /**
     * Returns the full value of a single revision (for preview before restore).
     * JSON response: {ok, value}
     */
    public function revisionValue(Request $request, array $params): Response
    {
        $listingId  = (int) ($params['id']         ?? 0);
        $revisionId = (int) ($params['revisionId'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        if ((new ListingRepo($db, $config))->getById($listingId) === null) {
            return Response::json(['error' => 'Listing not found.'], 404);
        }

        $revision = (new TemplateRevisionRepo($db))->getById($revisionId);

        if ($revision === null || $revision['fanlisting_id'] !== $listingId) {
            return Response::json(['error' => 'Revision not found.'], 404);
        }

        return Response::json(['ok' => true, 'value' => $revision['value']]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/listings/{id}/template-revisions/{revisionId}/restore
    // -------------------------------------------------------------------------

    /**
     * Restores a revision value as the current template value.
     * JSON response: {ok, key, value}
     */
    public function restoreRevision(Request $request, array $params): Response
    {
        $listingId  = (int) ($params['id']         ?? 0);
        $revisionId = (int) ($params['revisionId'] ?? 0);

        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $adminId = (int) $session->get('lstr_admin_id');

        if ((new ListingRepo($db, $config))->getById($listingId) === null) {
            return Response::json(['error' => 'Listing not found.'], 404);
        }

        $revisionRepo = new TemplateRevisionRepo($db);
        $revision     = $revisionRepo->getById($revisionId);

        if ($revision === null || $revision['fanlisting_id'] !== $listingId) {
            return Response::json(['error' => 'Revision not found.'], 404);
        }

        $key   = $revision['template_key'];
        $value = $revision['value'];

        if (!in_array($key, self::TEMPLATE_KEYS, true)) {
            return Response::json(['error' => 'Unknown template key.']);
        }

        $settingsRepo  = new ListingSettingsRepo($db);
        $currentValues = $settingsRepo->getTemplates($listingId);
        $currentValues['codes_template'] = $settingsRepo->getCodesTemplate($listingId);

        // Record the current value before replacing it
        $revisionRepo->record($listingId, $key, $currentValues[$key] ?? null, $adminId);

        // Apply the restored value
        $incoming = $currentValues;
        $incoming[$key] = $value;
        $this->applyTemplates($settingsRepo, $listingId, $incoming, $currentValues);

        return Response::json(['ok' => true, 'key' => $key, 'value' => $value]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/listings (AJAX) — list all listings for copy-from selector
    // -------------------------------------------------------------------------

    /**
     * Returns a lightweight listing-ID → title map for the copy-from modal.
     * Excludes the current listing.
     * JSON response: {ok, listings: [{id, title}]}
     */
    public function listingsForCopy(Request $request, array $params): Response
    {
        $currentId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $all = (new ListingRepo($db, $config))->getAll();

        $result = array_values(array_map(
            static fn(\Luster\Models\Listing $l): array => ['id' => $l->id, 'title' => $l->title],
            array_filter($all, static fn(\Luster\Models\Listing $l): bool => $l->id !== $currentId),
        ));

        return Response::json(['ok' => true, 'listings' => $result]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Normalizes a template value for comparison. */
    private function norm(?string $v): string
    {
        return $v === null ? '' : trim(str_replace("\r\n", "\n", $v));
    }

    /**
     * Detects which add-ons are referenced by template tokens in the pack.
     *
     * @param array<string, ?string> $templates
     * @return list<string>
     */
    private function detectRequiredAddons(array $templates): array
    {
        $allContent = implode(' ', array_map(
            static fn(?string $v): string => (string) $v,
            $templates,
        ));

        $required = [];
        foreach (self::ADDON_SIGNALS as $addon => $signals) {
            foreach ($signals as $signal) {
                if (str_contains($allContent, $signal)) {
                    $required[] = $addon;
                    break;
                }
            }
        }

        return $required;
    }

    /**
     * Applies a template set to a listing. Reads adopt_form_template from the
     * incoming array (preserves it if not in the set). Writes codes_template
     * separately since it has its own column.
     *
     * @param array<string, ?string> $incoming  Full template set (all keys)
     * @param array<string, ?string> $current   Current stored values (for adopt_form fallback)
     */
    private function applyTemplates(
        ListingSettingsRepo $settingsRepo,
        int $listingId,
        array $incoming,
        array $current,
    ): void {
        $settingsRepo->saveTemplates(
            $listingId,
            $incoming['stats_template']                ?? null,
            $incoming['members_template']              ?? null,
            $incoming['affiliates_section_template']   ?? null,
            $incoming['updates_template']              ?? null,
            $incoming['codes_submit_form_template']    ?? null,
            null, // rules_intro_template — managed separately
            $incoming['join_form_template']            ?? null,
            $incoming['affiliate_apply_form_template'] ?? null,
            $current['adopt_form_template']            ?? null, // never modified here
            $incoming['contact_form_template']         ?? null,
            $incoming['update_form_template']          ?? null,
            $incoming['reset_form_template']           ?? null,
        );
        $settingsRepo->saveCodesTemplate($listingId, $incoming['codes_template'] ?? null);
    }
}
