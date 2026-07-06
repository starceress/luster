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
use Luster\Repositories\SiteSettingsRepo;
use Luster\Repositories\TemplateRevisionRepo;
use Luster\Support\ListingTemplateResolver;

final class TemplatePackController
{
    /** Template keys included in export/import/copy operations. */
    private const TEMPLATE_KEYS = [
        'stats_template',
        'members_template',
        'affiliates_section_template',
        'newsfeed_template',
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
        'newsfeed_template'              => 'News Feed',
        'codes_template'                => 'Codes',
        'codes_submit_form_template'    => 'Code Submit Form',
        'join_form_template'            => 'Join Form',
        'affiliate_apply_form_template' => 'Affiliate Apply Form',
        'contact_form_template'         => 'Contact Form',
        'update_form_template'          => 'Update Form',
        'reset_form_template'           => 'Password Reset Form',
    ];

    /**
     * Maps pack logical key → lstr_site_settings storage key for global templates.
     * The pack uses the same logical keys as per-listing so packs are cross-context portable.
     */
    private const GLOBAL_KEY_MAP = [
        'stats_template'                => 'listing_default_stats_template',
        'members_template'              => 'listing_default_members_template',
        'affiliates_section_template'   => 'listing_default_affiliates_section_template',
        'newsfeed_template'              => 'listing_default_newsfeed_template',
        'codes_template'                => 'listing_default_codes_template',
        'codes_submit_form_template'    => 'listing_default_codes_submit_form_template',
        'join_form_template'            => 'listing_default_join_form_template',
        'affiliate_apply_form_template' => 'listing_default_affiliate_apply_form_template',
        'contact_form_template'         => 'listing_default_contact_form_template',
        'update_form_template'          => 'listing_default_update_form_template',
        'reset_form_template'           => 'listing_default_reset_form_template',
    ];

    /** Collective template keys — storage key names are the pack key names. */
    public const COLLECTIVE_TEMPLATE_KEYS = [
        'owned_template',
        'joined_template',
        'collective_stats_template',
        'collective_affiliates_section_template',
        'random_listing_template',
        'latest_listing_template',
        'random_joined_listing_template',
        'latest_joined_listing_template',
        'collective_affiliate_apply_form_template',
        'kim_signup_form_template',
        'wishlist_template',
        'collective_newsfeed_template',
        'collective_codes_template',
        'collective_codes_submit_form_template',
        'collective_kim_list_overview_template',
        'collective_kim_list_subject_template',
        'kim_update_form_template',
        'kim_reset_form_template',
        'collective_contact_form_template',
        'collective_adoption_form_template',
        'collective_update_form_template',
    ];

    /** Human-readable labels for collective template keys. */
    private const COLLECTIVE_TEMPLATE_LABELS = [
        'owned_template'                           => 'Owned Listings',
        'joined_template'                          => 'Joined Listings',
        'collective_stats_template'                => 'Collective Stats',
        'collective_affiliates_section_template'   => 'Affiliates Section',
        'random_listing_template'                  => 'Random Listing',
        'latest_listing_template'                  => 'Latest Listing',
        'random_joined_listing_template'           => 'Random Joined Listing',
        'latest_joined_listing_template'           => 'Latest Joined Listing',
        'collective_affiliate_apply_form_template' => 'Affiliate Apply Form',
        'kim_signup_form_template'                 => 'KIM Sign-up Form',
        'wishlist_template'                        => 'Wishlist',
        'collective_newsfeed_template'              => 'News Feed',
        'collective_codes_template'                => 'Codes',
        'collective_codes_submit_form_template'    => 'Code Submit Form',
        'collective_kim_list_overview_template'    => 'KIM List Overview',
        'collective_kim_list_subject_template'     => 'KIM List Subject',
        'kim_update_form_template'                 => 'KIM Update Form',
        'kim_reset_form_template'                  => 'KIM Reset Form',
        'collective_contact_form_template'         => 'Contact Form',
        'collective_adoption_form_template'        => 'Adoption Form',
        'collective_update_form_template'          => 'Collective Update Form',
    ];

    /** Token substrings that indicate a required add-on. */
    private const ADDON_SIGNALS = [
        'Newsfeed'  => ['{each_newsfeed_entry}', '{newsfeed_title}', '{newsfeed_body}'],
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
    // POST /admin/listings/{id}/template-pack/export-zip
    // -------------------------------------------------------------------------

    /**
     * Exports all per-listing template overrides as a ZIP archive.
     * Each non-null template is written as {key}.html inside a listing-{id}/ folder.
     * A pack.json at the archive root is the full importable JSON pack.
     */
    public function exportZip(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        if (!class_exists(\ZipArchive::class)) {
            return Response::json(['error' => 'The PHP zip extension is required for ZIP export.'], 500);
        }

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $listing = (new ListingRepo($db, $config))->getById($id);

        if ($listing === null) {
            return Response::json(['error' => 'Listing not found.'], 404);
        }

        $settingsRepo = new ListingSettingsRepo($db);
        $raw          = $settingsRepo->getTemplates($id);
        $raw['codes_template'] = $settingsRepo->getCodesTemplate($id);

        $templates = [];
        foreach (self::TEMPLATE_KEYS as $key) {
            if (($raw[$key] ?? null) !== null) {
                $templates[$key] = $raw[$key];
            }
        }

        $manifest = [
            'luster_template_pack' => '1.0',
            'luster_version'       => Version::CURRENT,
            'exported_at'          => gmdate('Y-m-d\TH:i:s\Z'),
            'scope'                => 'listing',
            'listing_title'        => $listing->title,
            'listing_subject'      => $listing->subject,
            'required_addons'      => $this->detectRequiredAddons($templates),
        ];

        try {
            $tempPath = $this->buildZipFile('listing-' . $id, $templates, $manifest);
        } catch (\RuntimeException) {
            return Response::json(['error' => 'Could not create ZIP archive.'], 500);
        }

        $slug     = preg_replace('/[^a-z0-9]+/', '-', strtolower($listing->title));
        $filename = 'luster-templates-' . $slug . '.zip';

        return Response::streamFile($tempPath, $filename, 'application/zip');
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
    // POST /admin/listings/{id}/template-revisions/flush
    // -------------------------------------------------------------------------

    /**
     * Deletes all revision history for one template key on a listing.
     * JSON response: {ok}
     */
    public function flushRevisions(Request $request, array $params): Response
    {
        $id  = (int) ($params['id'] ?? 0);
        $key = (string) $request->post('key', '');

        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        if (!in_array($key, self::TEMPLATE_KEYS, true)) {
            return Response::json(['ok' => false, 'error' => 'Unknown template key.']);
        }

        $db = $this->app->getDatabase();
        $config = $this->app->getConfig();

        if ((new ListingRepo($db, $config))->getById($id) === null) {
            return Response::json(['ok' => false, 'error' => 'Listing not found.'], 404);
        }

        (new TemplateRevisionRepo($db))->deleteByKey($id, $key);

        return Response::json(['ok' => true]);
    }

    // =========================================================================
    // Global template pack endpoints
    // =========================================================================

    // -------------------------------------------------------------------------
    // POST /admin/listings/global-templates/template-pack/export
    // -------------------------------------------------------------------------

    /**
     * Exports all global template overrides as a versioned JSON pack.
     * Uses the same logical key names as per-listing packs so packs are portable
     * across per-listing and global contexts.
     */
    public function globalExport(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $stored   = $siteRepo->getGlobalTemplates();

        $templates = [];
        foreach (self::GLOBAL_KEY_MAP as $packKey => $storageKey) {
            if (($stored[$storageKey] ?? null) !== null) {
                $templates[$packKey] = $stored[$storageKey];
            }
        }

        $pack = [
            'luster_template_pack' => '1.0',
            'luster_version'       => Version::CURRENT,
            'exported_at'          => gmdate('Y-m-d\TH:i:s\Z'),
            'scope'                => 'global',
            'required_addons'      => $this->detectRequiredAddons($templates),
            'templates'            => $templates,
        ];

        $siteName = (string) ($siteRepo->get('site_name') ?? 'luster');
        $filename = 'luster-global-templates-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($siteName)) . '.json';
        $json = json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return Response::download($json, $filename, 'application/json');
    }

    // -------------------------------------------------------------------------
    // POST /admin/listings/global-templates/template-pack/export-zip
    // -------------------------------------------------------------------------

    /**
     * Exports all global template overrides as a ZIP archive.
     * Each non-null template is written as {key}.html inside a global/ folder.
     * A pack.json at the archive root is the full importable JSON pack.
     */
    public function globalExportZip(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        if (!class_exists(\ZipArchive::class)) {
            return Response::json(['error' => 'The PHP zip extension is required for ZIP export.'], 500);
        }

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $stored   = $siteRepo->getGlobalTemplates();

        $templates = [];
        foreach (self::GLOBAL_KEY_MAP as $packKey => $storageKey) {
            if (($stored[$storageKey] ?? null) !== null) {
                $templates[$packKey] = $stored[$storageKey];
            }
        }

        $manifest = [
            'luster_template_pack' => '1.0',
            'luster_version'       => Version::CURRENT,
            'exported_at'          => gmdate('Y-m-d\TH:i:s\Z'),
            'scope'                => 'global',
            'required_addons'      => $this->detectRequiredAddons($templates),
        ];

        try {
            $tempPath = $this->buildZipFile('global', $templates, $manifest);
        } catch (\RuntimeException) {
            return Response::json(['error' => 'Could not create ZIP archive.'], 500);
        }

        $siteName = (string) ($siteRepo->get('site_name') ?? 'luster');
        $slug     = preg_replace('/[^a-z0-9]+/', '-', strtolower($siteName));
        $filename = 'luster-global-templates-' . $slug . '.zip';

        return Response::streamFile($tempPath, $filename, 'application/zip');
    }

    // -------------------------------------------------------------------------
    // POST /admin/listings/global-templates/template-pack/import-preview
    // -------------------------------------------------------------------------

    public function globalImportPreview(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

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

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $stored   = $siteRepo->getGlobalTemplates();

        $changes = [];
        foreach (self::GLOBAL_KEY_MAP as $packKey => $storageKey) {
            if (!array_key_exists($packKey, $incoming)) {
                continue;
            }
            $incomingValue = is_string($incoming[$packKey]) ? $incoming[$packKey] : null;
            $currentValue  = $stored[$storageKey] ?? null;
            $changes[] = [
                'key'      => $packKey,
                'label'    => self::TEMPLATE_LABELS[$packKey] ?? $packKey,
                'current'  => $currentValue,
                'incoming' => $incomingValue,
                'changed'  => ($this->norm($incomingValue) !== $this->norm($currentValue)),
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
    // POST /admin/listings/global-templates/template-pack/import-apply
    // -------------------------------------------------------------------------

    public function globalImportApply(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $body      = (string) $request->post('templates_json', '');
        $templates = json_decode($body, true);
        if (!is_array($templates)) {
            return Response::json(['error' => 'Invalid payload.']);
        }

        $db       = $this->app->getDatabase();
        $siteRepo = new SiteSettingsRepo($db);
        $stored   = $siteRepo->getGlobalTemplates();

        // Default every key to its current value, then override only the keys
        // the import payload actually touched — so recordChanged() below sees
        // untouched keys as unchanged, not as a diff against a missing key.
        $newValues = $stored;
        $touched   = [];
        foreach (self::GLOBAL_KEY_MAP as $packKey => $storageKey) {
            if (!array_key_exists($packKey, $templates)) {
                continue;
            }
            $value = is_string($templates[$packKey]) && $templates[$packKey] !== '' ? $templates[$packKey] : null;
            $newValues[$storageKey] = $value;
            $touched[]              = $storageKey;
        }

        $adminId = (int) $this->app->getSession()->get('lstr_admin_id');
        (new TemplateRevisionRepo($db))->recordChanged(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, $stored, $newValues, $adminId);

        $saved = 0;
        foreach ($touched as $storageKey) {
            $siteRepo->set($storageKey, $newValues[$storageKey]);
            $saved++;
        }

        return Response::json(['ok' => true, 'saved' => $saved]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/listings/global-templates/template-pack/copy-source?from={id}
    // -------------------------------------------------------------------------

    /**
     * Returns the effective template values from a listing for global copy-preview.
     * JSON response: {ok, listing_title, changes: [{key, label, current, incoming, changed}]}
     */
    public function globalCopySource(Request $request, array $params): Response
    {
        $sourceId = (int) ($request->query('from', '0'));
        if ($sourceId === 0) {
            return Response::json(['error' => 'Invalid source listing.']);
        }

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $sourceListing = (new ListingRepo($db, $config))->getById($sourceId);
        if ($sourceListing === null) {
            return Response::json(['error' => 'Source listing not found.']);
        }

        $siteRepo = new SiteSettingsRepo($db);
        $resolved = $this->resolveGlobalSourceTemplates($sourceId, $db, $siteRepo);
        $stored   = $siteRepo->getGlobalTemplates();

        $changes = [];
        foreach (self::GLOBAL_KEY_MAP as $packKey => $storageKey) {
            $incomingValue = $resolved[$packKey];
            $currentValue  = $stored[$storageKey] ?? null;
            $changes[] = [
                'key'      => $packKey,
                'label'    => self::TEMPLATE_LABELS[$packKey] ?? $packKey,
                'current'  => $currentValue,
                'incoming' => $incomingValue,
                'changed'  => ($this->norm($incomingValue) !== $this->norm($currentValue)),
            ];
        }

        return Response::json([
            'ok'            => true,
            'listing_title' => $sourceListing->title,
            'changes'       => $changes,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/listings/global-templates/template-pack/copy-apply
    // -------------------------------------------------------------------------

    public function globalCopyApply(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db       = $this->app->getDatabase();
        $config   = $this->app->getConfig();
        $sourceId = (int) $request->post('from', 0);

        if ($sourceId === 0) {
            return Response::json(['error' => 'Invalid source listing.']);
        }
        if ((new ListingRepo($db, $config))->getById($sourceId) === null) {
            return Response::json(['error' => 'Source listing not found.'], 404);
        }

        $keysJson     = (string) $request->post('keys_json', '[]');
        $selectedKeys = json_decode($keysJson, true);
        if (!is_array($selectedKeys)) {
            return Response::json(['error' => 'Invalid keys payload.']);
        }

        $siteRepo  = new SiteSettingsRepo($db);
        $resolved  = $this->resolveGlobalSourceTemplates($sourceId, $db, $siteRepo);
        $validKeys = array_intersect($selectedKeys, array_keys(self::GLOBAL_KEY_MAP));

        $stored    = $siteRepo->getGlobalTemplates();
        $newValues = $stored;
        foreach ($validKeys as $packKey) {
            $newValues[self::GLOBAL_KEY_MAP[$packKey]] = $resolved[$packKey];
        }

        $adminId = (int) $this->app->getSession()->get('lstr_admin_id');
        (new TemplateRevisionRepo($db))->recordChanged(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, $stored, $newValues, $adminId);

        $saved = 0;
        foreach ($validKeys as $packKey) {
            $storageKey = self::GLOBAL_KEY_MAP[$packKey];
            $siteRepo->set($storageKey, $resolved[$packKey]);
            $saved++;
        }

        return Response::json(['ok' => true, 'saved' => $saved]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves effective template values for a source listing through the full
     * three-tier cascade: per-listing override → global default → built-in.
     * Returns a map of pack key → resolved string (never null).
     *
     * @return array<string, string>
     */
    private function resolveGlobalSourceTemplates(int $sourceId, \Luster\Core\Database $db, SiteSettingsRepo $siteRepo): array
    {
        $settingsRepo    = new ListingSettingsRepo($db);
        $perListing      = $settingsRepo->getTemplates($sourceId);
        $perListing['codes_template'] = $settingsRepo->getCodesTemplate($sourceId);
        $globalTemplates = $siteRepo->getGlobalTemplates();

        $builtIns = [
            'stats_template'                => ListingSettingsRepo::defaultStatsTemplate(),
            'members_template'              => ListingSettingsRepo::defaultMembersTemplate(),
            'affiliates_section_template'   => ListingSettingsRepo::defaultAffiliatesSectionTemplate(),
            'newsfeed_template'              => ListingSettingsRepo::defaultNewsfeedTemplate(),
            'codes_template'                => ListingSettingsRepo::defaultCodesTemplate(),
            'codes_submit_form_template'    => ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
            'join_form_template'            => ListingSettingsRepo::defaultJoinFormTemplate(),
            'affiliate_apply_form_template' => ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),
            'contact_form_template'         => ListingSettingsRepo::defaultContactFormTemplate(),
            'update_form_template'          => ListingSettingsRepo::defaultUpdateFormTemplate(),
            'reset_form_template'           => ListingSettingsRepo::defaultResetFormTemplate(),
        ];

        $resolved = [];
        foreach (self::GLOBAL_KEY_MAP as $packKey => $storageKey) {
            $resolved[$packKey] = ListingTemplateResolver::resolve(
                $perListing[$packKey] ?? null,
                $globalTemplates[$storageKey] ?? null,
                $builtIns[$packKey] ?? '',
            )['value'];
        }
        return $resolved;
    }

    // -------------------------------------------------------------------------
    // GET /admin/listings/global-templates/template-pack/listings-for-copy
    // -------------------------------------------------------------------------

    public function globalListingsForCopy(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $all = (new ListingRepo($db, $config))->getAll();
        $result = array_values(array_map(
            static fn(\Luster\Models\Listing $l): array => ['id' => $l->id, 'title' => $l->title],
            $all,
        ));

        return Response::json(['ok' => true, 'listings' => $result]);
    }

    // =========================================================================
    // Global Templates revision history
    // =========================================================================

    // -------------------------------------------------------------------------
    // GET /admin/listings/global-templates/template-revisions?key=...
    // -------------------------------------------------------------------------

    /**
     * Returns the revision history list for one global template key.
     * JSON response: {ok, revisions: [{id, saved_at, preview, has_value}]}
     */
    public function globalRevisions(Request $request, array $params): Response
    {
        $key = (string) ($request->query('key', ''));

        if (!in_array($key, self::GLOBAL_KEY_MAP, true)) {
            return Response::json(['error' => 'Unknown template key.']);
        }

        $history = (new TemplateRevisionRepo($this->app->getDatabase()))
            ->getHistory(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, $key);

        $result = array_map(static fn(array $r): array => [
            'id'        => $r['id'],
            'saved_at'  => $r['saved_at'],
            'preview'   => mb_substr((string) ($r['value'] ?? ''), 0, 200),
            'has_value' => $r['value'] !== null,
        ], $history);

        return Response::json(['ok' => true, 'revisions' => $result]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/listings/global-templates/template-revisions/{revisionId}
    // -------------------------------------------------------------------------

    /**
     * Returns the full value of a single global-template revision (for preview before restore).
     * JSON response: {ok, value}
     */
    public function globalRevisionValue(Request $request, array $params): Response
    {
        $revisionId = (int) ($params['revisionId'] ?? 0);

        $revision = (new TemplateRevisionRepo($this->app->getDatabase()))->getById($revisionId);

        if ($revision === null || $revision['fanlisting_id'] !== TemplateRevisionRepo::GLOBAL_DEFAULTS_ID) {
            return Response::json(['error' => 'Revision not found.'], 404);
        }

        return Response::json(['ok' => true, 'value' => $revision['value']]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/listings/global-templates/template-revisions/{revisionId}/restore
    // -------------------------------------------------------------------------

    /**
     * Restores a global-template revision value as the current template value.
     * JSON response: {ok, key, value}
     */
    public function globalRestoreRevision(Request $request, array $params): Response
    {
        $revisionId = (int) ($params['revisionId'] ?? 0);

        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db      = $this->app->getDatabase();
        $adminId = (int) $this->app->getSession()->get('lstr_admin_id');

        $revisionRepo = new TemplateRevisionRepo($db);
        $revision     = $revisionRepo->getById($revisionId);

        if ($revision === null || $revision['fanlisting_id'] !== TemplateRevisionRepo::GLOBAL_DEFAULTS_ID) {
            return Response::json(['error' => 'Revision not found.'], 404);
        }

        $key   = $revision['template_key'];
        $value = $revision['value'];

        if (!in_array($key, self::GLOBAL_KEY_MAP, true)) {
            return Response::json(['error' => 'Unknown template key.']);
        }

        $siteRepo     = new SiteSettingsRepo($db);
        $currentValue = $siteRepo->get($key);

        // Record the current value before replacing it
        $revisionRepo->record(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, $key, $currentValue, $adminId);

        // Apply the restored value (empty string means reset to default = null)
        $siteRepo->set($key, ($value !== null && $value !== '') ? $value : null);

        return Response::json(['ok' => true, 'key' => $key, 'value' => $value]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/listings/global-templates/template-revisions/flush
    // -------------------------------------------------------------------------

    /**
     * Deletes all revision history for one global template key.
     * JSON response: {ok}
     */
    public function globalFlushRevisions(Request $request, array $params): Response
    {
        $key = (string) $request->post('key', '');

        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        if (!in_array($key, self::GLOBAL_KEY_MAP, true)) {
            return Response::json(['ok' => false, 'error' => 'Unknown template key.']);
        }

        (new TemplateRevisionRepo($this->app->getDatabase()))
            ->deleteByKey(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, $key);

        return Response::json(['ok' => true]);
    }

    // =========================================================================
    // Collective template pack endpoints
    // =========================================================================

    // -------------------------------------------------------------------------
    // POST /admin/collective/templates/template-pack/export
    // -------------------------------------------------------------------------

    public function collectiveExport(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $stored   = $siteRepo->getMany(self::COLLECTIVE_TEMPLATE_KEYS);

        $templates = array_filter($stored, static fn(?string $v): bool => $v !== null);

        $pack = [
            'luster_template_pack' => '1.0',
            'luster_version'       => Version::CURRENT,
            'exported_at'          => gmdate('Y-m-d\TH:i:s\Z'),
            'scope'                => 'collective',
            'required_addons'      => $this->detectRequiredAddons($templates),
            'templates'            => $templates,
        ];

        $siteName = (string) ($siteRepo->get('site_name') ?? 'luster');
        $filename = 'luster-collective-templates-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($siteName)) . '.json';
        $json = json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return Response::download($json, $filename, 'application/json');
    }

    // -------------------------------------------------------------------------
    // POST /admin/collective/templates/template-pack/export-zip
    // -------------------------------------------------------------------------

    /**
     * Exports all collective template overrides as a ZIP archive.
     * Each non-null template is written as {key}.html inside a collective/ folder.
     * A pack.json at the archive root is the full importable JSON pack.
     */
    public function collectiveExportZip(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        if (!class_exists(\ZipArchive::class)) {
            return Response::json(['error' => 'The PHP zip extension is required for ZIP export.'], 500);
        }

        $siteRepo  = new SiteSettingsRepo($this->app->getDatabase());
        $stored    = $siteRepo->getMany(self::COLLECTIVE_TEMPLATE_KEYS);
        $templates = array_filter($stored, static fn(?string $v): bool => $v !== null);

        $manifest = [
            'luster_template_pack' => '1.0',
            'luster_version'       => Version::CURRENT,
            'exported_at'          => gmdate('Y-m-d\TH:i:s\Z'),
            'scope'                => 'collective',
            'required_addons'      => $this->detectRequiredAddons($templates),
        ];

        try {
            $tempPath = $this->buildZipFile('collective', $templates, $manifest);
        } catch (\RuntimeException) {
            return Response::json(['error' => 'Could not create ZIP archive.'], 500);
        }

        $siteName = (string) ($siteRepo->get('site_name') ?? 'luster');
        $slug     = preg_replace('/[^a-z0-9]+/', '-', strtolower($siteName));
        $filename = 'luster-collective-templates-' . $slug . '.zip';

        return Response::streamFile($tempPath, $filename, 'application/zip');
    }

    // -------------------------------------------------------------------------
    // POST /admin/collective/templates/template-pack/import-preview
    // -------------------------------------------------------------------------

    public function collectiveImportPreview(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

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

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $stored   = $siteRepo->getMany(self::COLLECTIVE_TEMPLATE_KEYS);

        $changes = [];
        foreach (self::COLLECTIVE_TEMPLATE_KEYS as $key) {
            if (!array_key_exists($key, $incoming)) {
                continue;
            }
            $incomingValue = is_string($incoming[$key]) ? $incoming[$key] : null;
            $currentValue  = $stored[$key] ?? null;
            $changes[] = [
                'key'      => $key,
                'label'    => self::COLLECTIVE_TEMPLATE_LABELS[$key] ?? $key,
                'current'  => $currentValue,
                'incoming' => $incomingValue,
                'changed'  => ($this->norm($incomingValue) !== $this->norm($currentValue)),
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
    // POST /admin/collective/templates/template-pack/import-apply
    // -------------------------------------------------------------------------

    public function collectiveImportApply(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $body      = (string) $request->post('templates_json', '');
        $templates = json_decode($body, true);
        if (!is_array($templates)) {
            return Response::json(['error' => 'Invalid payload.']);
        }

        $db         = $this->app->getDatabase();
        $siteRepo   = new SiteSettingsRepo($db);
        $validKeys  = array_intersect(array_keys($templates), self::COLLECTIVE_TEMPLATE_KEYS);

        $currentValues = $siteRepo->getMany(self::COLLECTIVE_TEMPLATE_KEYS);

        $incoming = [];
        foreach (self::COLLECTIVE_TEMPLATE_KEYS as $key) {
            if (!in_array($key, $validKeys, true)) {
                $incoming[$key] = $currentValues[$key] ?? null;
            } else {
                $raw = $templates[$key];
                $incoming[$key] = is_string($raw) && $raw !== '' ? $raw : null;
            }
        }

        $adminId = (int) $this->app->getSession()->get('lstr_admin_id');
        (new TemplateRevisionRepo($db))->recordChanged(TemplateRevisionRepo::COLLECTIVE_ID, $currentValues, $incoming, $adminId);

        $saved = 0;
        foreach ($validKeys as $key) {
            $siteRepo->set($key, $incoming[$key]);
            $saved++;
        }

        return Response::json(['ok' => true, 'saved' => $saved]);
    }

    // =========================================================================
    // Collective template revision history
    // =========================================================================

    // -------------------------------------------------------------------------
    // GET /admin/collective/templates/template-revisions?key=...
    // -------------------------------------------------------------------------

    /**
     * Returns the revision history list for one collective template key.
     * JSON response: {ok, revisions: [{id, saved_at, preview, has_value}]}
     */
    public function collectiveRevisions(Request $request, array $params): Response
    {
        $key = (string) ($request->query('key', ''));

        if (!in_array($key, self::COLLECTIVE_TEMPLATE_KEYS, true)) {
            return Response::json(['error' => 'Unknown template key.']);
        }

        $history = (new TemplateRevisionRepo($this->app->getDatabase()))
            ->getHistory(TemplateRevisionRepo::COLLECTIVE_ID, $key);

        $result = array_map(static fn(array $r): array => [
            'id'        => $r['id'],
            'saved_at'  => $r['saved_at'],
            'preview'   => mb_substr((string) ($r['value'] ?? ''), 0, 200),
            'has_value' => $r['value'] !== null,
        ], $history);

        return Response::json(['ok' => true, 'revisions' => $result]);
    }

    // -------------------------------------------------------------------------
    // GET /admin/collective/templates/template-revisions/{revisionId}
    // -------------------------------------------------------------------------

    /**
     * Returns the full value of a single collective revision (for preview before restore).
     * JSON response: {ok, value}
     */
    public function collectiveRevisionValue(Request $request, array $params): Response
    {
        $revisionId = (int) ($params['revisionId'] ?? 0);

        $revision = (new TemplateRevisionRepo($this->app->getDatabase()))->getById($revisionId);

        if ($revision === null || $revision['fanlisting_id'] !== TemplateRevisionRepo::COLLECTIVE_ID) {
            return Response::json(['error' => 'Revision not found.'], 404);
        }

        return Response::json(['ok' => true, 'value' => $revision['value']]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/collective/templates/template-revisions/{revisionId}/restore
    // -------------------------------------------------------------------------

    /**
     * Restores a collective revision value as the current template value.
     * JSON response: {ok, key, value}
     */
    public function collectiveRestoreRevision(Request $request, array $params): Response
    {
        $revisionId = (int) ($params['revisionId'] ?? 0);

        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db      = $this->app->getDatabase();
        $adminId = (int) $this->app->getSession()->get('lstr_admin_id');

        $revisionRepo = new TemplateRevisionRepo($db);
        $revision     = $revisionRepo->getById($revisionId);

        if ($revision === null || $revision['fanlisting_id'] !== TemplateRevisionRepo::COLLECTIVE_ID) {
            return Response::json(['error' => 'Revision not found.'], 404);
        }

        $key   = $revision['template_key'];
        $value = $revision['value'];

        if (!in_array($key, self::COLLECTIVE_TEMPLATE_KEYS, true)) {
            return Response::json(['error' => 'Unknown template key.']);
        }

        $siteRepo     = new SiteSettingsRepo($db);
        $currentValue = $siteRepo->get($key);

        // Record the current value before replacing it
        $revisionRepo->record(TemplateRevisionRepo::COLLECTIVE_ID, $key, $currentValue, $adminId);

        // Apply the restored value (empty string means reset to default = null)
        $siteRepo->set($key, ($value !== null && $value !== '') ? $value : null);

        return Response::json(['ok' => true, 'key' => $key, 'value' => $value]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/collective/templates/template-revisions/flush
    // -------------------------------------------------------------------------

    /**
     * Deletes all revision history for one collective template key.
     * JSON response: {ok}
     */
    public function collectiveFlushRevisions(Request $request, array $params): Response
    {
        $key = (string) $request->post('key', '');

        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        if (!in_array($key, self::COLLECTIVE_TEMPLATE_KEYS, true)) {
            return Response::json(['ok' => false, 'error' => 'Unknown template key.']);
        }

        (new TemplateRevisionRepo($this->app->getDatabase()))
            ->deleteByKey(TemplateRevisionRepo::COLLECTIVE_ID, $key);

        return Response::json(['ok' => true]);
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

    /**
     * Builds a ZIP archive at a temp file path containing:
     * - manifest.json  — scope/version/add-on metadata (no template bodies)
     * - pack.json      — full importable JSON pack (same format as JSON export; import
     *                    it via the "Import Templates" button after extracting from the ZIP)
     * - {folder}/{key}.html — one HTML file per template for human editing
     *
     * @param  string                $folder     Subfolder for HTML files (e.g. 'listing-7', 'global', 'collective')
     * @param  array<string, string> $templates  Key → value; only non-null values should be passed
     * @param  array<string, mixed>  $manifest   Metadata (scope, version, required_addons — no 'templates' key)
     * @return string                            Path to the temp ZIP file
     * @throws \RuntimeException                 If ZipArchive cannot open the file
     */
    private function buildZipFile(string $folder, array $templates, array $manifest): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'luster_tpl_');

        $zip = new \ZipArchive();
        if ($zip->open($tempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($tempPath);
            throw new \RuntimeException('Could not create ZIP archive.');
        }

        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        $zip->addFromString('manifest.json', json_encode($manifest, $flags));
        $zip->addFromString('pack.json',     json_encode($manifest + ['templates' => $templates], $flags));

        foreach ($templates as $key => $value) {
            $zip->addFromString($folder . '/' . $key . '.html', $value);
        }

        $zip->close();

        return $tempPath;
    }

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
            $incoming['newsfeed_template']              ?? null,
            $incoming['codes_submit_form_template']    ?? null,
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
