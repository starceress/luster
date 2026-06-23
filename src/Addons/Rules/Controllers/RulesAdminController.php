<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin controller for the Join Rules add-on. Provides the paginated listing
 index showing each listing's rule status, the rule editor with live preview,
 bulk enable/disable/delete operations, per-listing toggle and delete actions,
 the global fallback rules editor, and the rules settings page.
*/

declare(strict_types=1);

namespace Luster\Addons\Rules\Controllers;

use Luster\Addons\Rules\RulesRepo;
use Luster\Controllers\Admin\AdminController;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\RulesBodyRenderer;

/**
 * Admin management of per-listing rules.
 *
 * Routes (registered by RulesAddon::registerRoutes()):
 *   GET  /admin/rules                 index()        — list all listings + rules status
 *   POST /admin/rules/bulk            bulk()         — bulk delete / enable / disable
 *   GET  /admin/rules/settings        settingsForm() — per-page settings
 *   POST /admin/rules/settings        settingsSave() — save settings
 *   GET  /admin/rules/{id}/edit       editForm()     — edit rules for a listing
 *   POST /admin/rules/{id}/edit       editSave()     — save rules
 *   POST /admin/rules/{id}/toggle     toggle()       — enable/disable without editing
 *   POST /admin/rules/{id}/delete     delete()       — remove rules for a listing
 */
final class RulesAdminController extends AdminController
{
    // -----------------------------------------------------------------------
    // GET /admin/rules
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $sort = trim((string) $request->query('sort', 'id'));
        $dir  = trim((string) $request->query('dir',  'asc'));
        if (!in_array($sort, ['id', 'subject', 'updated_at'], true)) {
            $sort = 'id';
        }
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'asc';
        }

        $search = trim((string) $request->query('search', ''));
        $type   = trim((string) $request->query('type',   ''));
        if (!in_array($type, ['', 'listing', 'clique', 'collective', 'shrine', 'webring', 'other'], true)) {
            $type = '';
        }

        $siteRepo    = new SiteSettingsRepo($this->app->getDatabase());
        $storedPer   = (string) ($siteRepo->get('rules_index_per_page') ?? '');
        $perPage     = $storedPer !== '' ? max(5, min(100, (int) $storedPer)) : 10;
        $page     = max(1, (int) $request->query('page', 1));

        $repo     = new RulesRepo($this->app->getDatabase());
        $allRows  = $repo->getAllWithListings($sort, $dir, $search, $type);
        $total    = count($allRows);
        $pages    = max(1, (int) ceil($total / $perPage));
        $page     = min($page, $pages);
        $rows     = array_slice($allRows, ($page - 1) * $perPage, $perPage);
        $subjects = $repo->getSubjects();

        // Derive used types from search-only results so the dropdown shows all
        // in-use types regardless of which type filter is currently active.
        $unfiltered = $type !== '' ? $repo->getAllWithListings($sort, $dir, $search, '') : $allRows;
        $usedTypes  = array_values(array_unique(array_filter(
            array_column($unfiltered, 'listing_type'),
            static fn(string $t): bool => $t !== ''
        )));

        return $this->adminLayout('admin/addons/rules/index.php', 'Join Rules', 'rules', [
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'pages'      => $pages,
            'per_page'   => $perPage,
            'subjects'   => $subjects,
            'used_types' => $usedTypes,
            'success'    => $success,
            'error'      => $error,
            'sort'       => $sort,
            'dir'      => $dir,
            'search'   => $search,
            'type'     => $type,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/rules/bulk
    // -----------------------------------------------------------------------

    public function bulk(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action = trim((string) $request->post('bulk_action', ''));
        $ids    = array_values(array_filter(
            array_map('intval', (array) $request->post('ids', [])),
            static fn(int $i): bool => $i > 0,
        ));

        if ($ids === [] || !in_array($action, ['delete', 'enable', 'disable'], true)) {
            $session->set('lstr_flash_error', 'No items selected or invalid action.');
            return Response::redirect($baseUrl . '/admin/rules');
        }

        $repo   = new RulesRepo($this->app->getDatabase());
        $labels = ['delete' => 'deleted', 'enable' => 'enabled', 'disable' => 'disabled'];
        $count  = 0;

        if ($action === 'delete') {
            $count = $repo->bulkDelete($ids);
        } elseif ($action === 'enable') {
            $count = $repo->bulkSetEnabled($ids, true);
        } else {
            $count = $repo->bulkSetEnabled($ids, false);
        }

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $session->get('lstr_admin_id', 0),
            'rules.bulk_' . $action,
            ucfirst($labels[$action]) . ' rules for ' . $count . ' listing' . ($count === 1 ? '' : 's'),
            $request->ip(),
        );

        $session->set(
            'lstr_flash_success',
            $count . ' rule set' . ($count === 1 ? '' : 's') . ' ' . $labels[$action] . '.',
        );

        return Response::redirect($baseUrl . '/admin/rules');
    }

    // -----------------------------------------------------------------------
    // GET /admin/rules/{id}/edit
    // POST /admin/rules/{id}/edit
    // -----------------------------------------------------------------------

    public function editForm(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $listing   = $this->requireListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $ruleSet = (new RulesRepo($this->app->getDatabase()))->getForListing($listingId);

        return $this->adminLayout('admin/addons/rules/edit.php', 'Edit Rules', 'rules', [
            'listing'    => $listing,
            'listing_id' => $listingId,
            'rules'      => $ruleSet,
            'success'    => $success,
            'error'      => $error,
        ], '', [
            $this->crumb('Join Rules', $this->adminUrl('/admin/rules')),
            $this->crumb($listing->subject),
        ]);
    }

    public function editSave(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $listing   = $this->requireListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }

        $body    = trim((string) $request->post('body',    ''));
        $enabled = $request->post('enabled', '') !== '';

        $redirectListingId = (int) $request->post('_redirect_to_listing', 0);

        if ($body === '' && $enabled) {
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', 'Rules cannot be enabled without any rules text. Add text or uncheck "Enabled".');
            $backUrl = ($redirectListingId > 0 && $redirectListingId === $listingId)
                ? '/admin/listings/' . $listingId . '/edit'
                : '/admin/rules/' . $listingId . '/edit';
            return Response::redirect($this->adminUrl($backUrl));
        }

        $gateType = (string) $request->post('gate_type', 'checkbox');
        $gateType = in_array($gateType, ['checkbox', 'button'], true) ? $gateType : 'checkbox';

        $gateLabel = trim((string) $request->post('gate_label', ''));
        if (mb_strlen($gateLabel) > 255) {
            $gateLabel = mb_substr($gateLabel, 0, 255);
        }

        $repo = new RulesRepo($this->app->getDatabase());
        $existing = $repo->getForListing($listingId);
        $oldVersion = $existing?->version ?? 0;

        $repo->upsert($listingId, $body, $enabled, $gateType, $gateLabel);

        $newVersion = $repo->getForListing($listingId)?->version ?? 0;
        $bumped     = $newVersion > $oldVersion;

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0),
            'rules.saved',
            'Saved rules for listing "' . $listing->subject . '"'
                . ($bumped ? ' (version bumped to ' . $newVersion . ')' : ''),
            $request->ip(),
        );

        $session = $this->app->getSession();
        $session->set('lstr_flash_success', 'Rules saved.' . ($bumped ? ' All existing fan agreements have been reset.' : ''));

        if ($redirectListingId > 0 && $redirectListingId === $listingId) {
            return Response::redirect($this->adminUrl('/admin/listings/' . $listingId . '/edit'));
        }

        return Response::redirect($this->adminUrl('/admin/rules/' . $listingId . '/edit'));
    }

    // -----------------------------------------------------------------------
    // POST /admin/rules/{id}/toggle
    // -----------------------------------------------------------------------

    public function toggle(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $listing   = $this->requireListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }

        $repo    = new RulesRepo($this->app->getDatabase());
        $ruleSet = $repo->getForListing($listingId);

        if ($ruleSet === null) {
            $this->app->getSession()->set('lstr_flash_error', 'No rules exist for this listing yet. Use the edit page to create them.');
            return Response::redirect($this->adminUrl('/admin/rules'));
        }

        $newState = !$ruleSet->enabled;

        if ($newState && trim($ruleSet->body) === '') {
            $this->app->getSession()->set('lstr_flash_error', 'Rules cannot be enabled without any rules text. Use the edit page to add text first.');
            return Response::redirect($this->adminUrl('/admin/rules'));
        }

        $repo->setEnabled($listingId, $newState);

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0),
            'rules.toggled',
            ($newState ? 'Enabled' : 'Disabled') . ' rules for listing "' . $listing->subject . '"',
            $request->ip(),
        );

        $this->app->getSession()->set(
            'lstr_flash_success',
            'Rules ' . ($newState ? 'enabled' : 'disabled') . ' for ' . $listing->subject . '.',
        );

        return Response::redirect($this->adminUrl('/admin/rules'));
    }

    // -----------------------------------------------------------------------
    // POST /admin/rules/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $listing   = $this->requireListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }

        (new RulesRepo($this->app->getDatabase()))->delete($listingId);

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0),
            'rules.deleted',
            'Deleted rules for listing "' . $listing->subject . '"',
            $request->ip(),
        );

        $this->app->getSession()->set('lstr_flash_success', 'Rules deleted for ' . $listing->subject . '.');

        return Response::redirect($this->adminUrl('/admin/rules'));
    }

    // -----------------------------------------------------------------------
    // GET /admin/rules/global
    // POST /admin/rules/global
    // -----------------------------------------------------------------------

    public function globalForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());

        return $this->adminLayout('admin/addons/rules/global.php', 'Global Rules Template', 'rules', [
            'global_body'         => (string) ($siteRepo->get('rules_global_body')         ?? ''),
            'global_gate_enabled' => (string) ($siteRepo->get('rules_global_gate_enabled') ?? '0'),
            'global_gate_type'    => (string) ($siteRepo->get('rules_global_gate_type')    ?? 'checkbox'),
            'global_gate_label'   => (string) ($siteRepo->get('rules_global_gate_label')   ?? ''),
            'success'           => $success,
            'error'             => $error,
        ], '', [
            $this->crumb('Join Rules', $this->adminUrl('/admin/rules')),
            $this->crumb('Global Rules Template'),
        ]);
    }

    public function globalSave(Request $request, array $params): Response
    {
        $body     = trim((string) $request->post('body', ''));
        $gateType = (string) $request->post('gate_type', 'checkbox');
        $gateType  = in_array($gateType, ['checkbox', 'button'], true) ? $gateType : 'checkbox';
        $gateLabel = trim((string) $request->post('gate_label', ''));
        if (mb_strlen($gateLabel) > 255) {
            $gateLabel = mb_substr($gateLabel, 0, 255);
        }

        $siteRepo     = new SiteSettingsRepo($this->app->getDatabase());
        $existingBody = trim((string) ($siteRepo->get('rules_global_body') ?? ''));
        $existingVer  = (int) ($siteRepo->get('rules_global_version') ?? 1);

        $bumpVersion = $body !== '' && $body !== $existingBody;
        $newVersion  = $bumpVersion ? $existingVer + 1 : $existingVer;

        $gateEnabled = $request->post('gate_enabled', '') !== '' ? '1' : '0';

        $siteRepo->set('rules_global_body',         $body);
        $siteRepo->set('rules_global_gate_enabled', $gateEnabled);
        $siteRepo->set('rules_global_gate_type',    $gateType);
        $siteRepo->set('rules_global_gate_label',   $gateLabel);
        $siteRepo->set('rules_global_version',      (string) $newVersion);

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0),
            'rules.global_saved',
            'Saved global rules template' . ($bumpVersion ? ' (version bumped to ' . $newVersion . ')' : ''),
            $request->ip(),
        );

        $this->app->getSession()->set(
            'lstr_flash_success',
            'Global rules template saved.' . ($bumpVersion ? ' All existing agreements on listings using the global template have been reset.' : ''),
        );

        return Response::redirect($this->adminUrl('/admin/rules/global'));
    }

    // -----------------------------------------------------------------------
    // POST /admin/rules/global/preview
    // -----------------------------------------------------------------------

    public function globalPreview(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $appUrl    = rtrim((string) $this->app->getConfig()->get('app_url', ''), '/');
        $tpl         = (string) $request->post('template_html', '');
        $gateLabel   = trim((string) $request->post('gate_label', ''));
        $gateEnabled = (string) $request->post('gate_enabled', '1') !== '0';
        $gateType    = trim((string) $request->post('gate_type', ''));

        if ($tpl === '') {
            return $this->previewWrap('<p style="color:#c00">Template is empty.</p>', $appUrl);
        }

        try {
            $html = RulesBodyRenderer::renderPreview($tpl, [
                'subject'       => 'Sample Fanlisting',
                'listing_title' => 'Sample Fanlisting',
                'listing_url'   => 'https://example.com/sample-fanlisting',
                'join_url'      => 'https://example.com/sample-fanlisting/join',
                'update_url'    => 'https://example.com/sample-fanlisting/update',
            ], $gateLabel, $gateEnabled, $gateType);
        } catch (\Throwable $e) {
            $html = '<p style="color:#c00;font-family:monospace">Preview error: '
                  . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        return $this->previewWrap($html, $appUrl);
    }

    /**
     * Wraps a preview fragment in a minimal standalone HTML document, loading
     * the public default stylesheet so previews look like the real public page.
     * Mirrors ListingController::previewWrap() / SettingsController::coltplPreviewWrap().
     */
    private function previewWrap(string $content, string $appUrl): Response
    {
        $cssUrl = htmlspecialchars(
            $appUrl . '/assets/css/default/style.css',
            ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8',
        );
        return Response::html(
            '<!DOCTYPE html><html lang="en"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="' . $cssUrl . '?v=' . filemtime(
                rtrim(LUSTER_BASE, '/') . '/public/assets/css/default/style.css'
            ) . '">'
            . '<style>body{padding:1rem;background:#fff;font-family:sans-serif;font-size:14px}</style>'
            . '</head><body>' . $content . '</body></html>'
        );
    }

    // -----------------------------------------------------------------------
    // GET /admin/rules/settings
    // POST /admin/rules/settings
    // -----------------------------------------------------------------------

    public function settingsForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $perPage  = max(5, min(100, (int) ($siteRepo->get('rules_index_per_page') ?? 10)));

        return $this->adminLayout('admin/addons/rules/settings.php', 'Join Rules Settings', 'rules', [
            'rules_per_page' => $perPage,
            'success'        => $success,
            'error'          => $error,
        ], '', [
            $this->crumb('Join Rules', $this->adminUrl('/admin/rules')),
            $this->crumb('Settings'),
        ]);
    }

    public function settingsSave(Request $request, array $params): Response
    {
        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $action   = trim((string) $request->post('settings_action', ''));

        if ($action === 'reset') {
            $siteRepo->set('rules_index_per_page', '10');
            $this->app->getSession()->set('lstr_flash_success', 'Settings reset to default (10 entries per page).');
        } elseif ($action === 'clear') {
            $siteRepo->set('rules_index_per_page', '');
            $this->app->getSession()->set('lstr_flash_success', 'Settings cleared (will use default of 10 entries per page).');
        } else {
            $perPage = max(5, min(100, (int) $request->post('rules_per_page', 10)));
            $siteRepo->set('rules_index_per_page', (string) $perPage);
            $this->app->getSession()->set('lstr_flash_success', 'Settings saved.');
        }

        return Response::redirect($this->adminUrl('/admin/rules/settings'));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the listing model, or a redirect Response if not found.
     *
     * @return \Luster\Models\Listing|Response
     */
    private function requireListing(int $listingId): mixed
    {
        if ($listingId <= 0) {
            return Response::redirect($this->adminUrl('/admin/rules'));
        }

        $listing = (new ListingRepo($this->app->getDatabase(), $this->app->getConfig()))
            ->getById($listingId);

        if ($listing === null) {
            $this->app->getSession()->set('lstr_flash_error', 'Listing not found.');
            return Response::redirect($this->adminUrl('/admin/rules'));
        }

        return $listing;
    }
}
