<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Link checker admin page. Scans affiliate and joined-listing URLs for dead links
 and redirects, displaying results across Active, Moved Links, and Dead Links
 tabs. Moved Links offers one-click "Update URL" to apply the redirect target.
 Runs checks via the LinkChecker service with SSRF protection from
 NetworkAddressGuard. Also supports per-listing member URL checking with
 paginated summary and detail views.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\LinkChecker;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Admin link checker — scans affiliate, joined-listing, and member URLs for dead links
 * and permanent redirects.
 *
 * The index page shows three tabs: Active (all entries with link status), Moved Links
 * (one-click "Update URL" applies the detected redirect target), and Dead Links (entries
 * that returned 4xx/5xx or timed out). Per-listing member URL checking is also supported:
 * an admin first syncs member URLs into lstr_member_link_checks via scanMembers(), then
 * individual rows can be checked with checkMember() and redirect URLs applied with
 * applyRedirectMember(). All live checks use LinkChecker with SSRF protection from
 * NetworkAddressGuard. Display settings (per-page count, default sort) are stored in
 * config/app.php. Cron-based automatic checking is gated behind a shared-secret token
 * stored in lstr_site_settings.
 */
final class LinkCheckerController extends AdminController
{
    // -----------------------------------------------------------------------
    // Index — summary page with affiliate + joined tables
    // -----------------------------------------------------------------------

    /**
     * Renders the Link Checker index page with all affiliates, joined listings, and
     * (optionally) member URL rows.
     *
     * When a listing_id query parameter is present and positive, loads member link
     * check rows for that specific listing. When listing_id is absent (null), loads
     * aggregate member stats across all listings. When listing_id=0 (Collective), the
     * member section remains empty. Supports server-side sorting and status filtering
     * on all three tables.
     */
    public function index(Request $request): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $config      = $this->app->getConfig();
        $perPage     = max(5, min(100, (int) ($config->get('lc_per_page', 10) ?? 10)));
        $defaultSort = (string) ($config->get('lc_default_sort', 'name') ?? 'name');
        if (!in_array($defaultSort, ['name', 'link_status'], true)) { $defaultSort = 'name'; }

        $db         = $this->app->getDatabase();
        $affRepo    = new AffiliateRepo($db);
        $joinedRepo = new JoinedListingRepo($db);

        $listingIdRaw  = (string) $request->query('listing_id', '');
        $listingFilter = ($listingIdRaw !== '' && ctype_digit($listingIdRaw)) ? (int) $listingIdRaw : null;

        $affPage    = max(1, (int) ($request->query('aff_page',    1) ?: 1));
        $joinedPage = max(1, (int) ($request->query('joined_page', 1) ?: 1));
        $memPage    = max(1, (int) ($request->query('mem_page',    1) ?: 1));
        $memSumPage = max(1, (int) ($request->query('mem_sum_page', 1) ?: 1));

        $memSumSortAllowed = ['id', 'listing', 'last_checked'];
        $memSumSortParam   = trim((string) $request->query('mem_sum_sort', 'id'));
        $memSumDirParam    = strtolower(trim((string) $request->query('mem_sum_dir', 'asc')));
        if (!in_array($memSumSortParam, $memSumSortAllowed, true)) { $memSumSortParam = 'id'; }
        if (!in_array($memSumDirParam, ['asc', 'desc'], true)) { $memSumDirParam = 'asc'; }

        $statusValues = ['ok', 'redirect', 'dead', 'unchecked'];
        $statusParam  = trim((string) $request->query('status', ''));
        if (!in_array($statusParam, $statusValues, true)) { $statusParam = ''; }

        $searchParam = trim((string) $request->query('search', ''));

        $enthPrefix     = (string) $config->get('enth_table_prefix', '');
        $listingTable   = $enthPrefix === '' ? 'owned' : $enthPrefix . 'listings';
        $listingIdCol   = $enthPrefix === '' ? 'listingid' : 'id';
        $listingSubjCol = 'subject';
        $affListingSub  = "(SELECT {$listingSubjCol} FROM `{$listingTable}` WHERE `{$listingIdCol}` = lstr_affiliates.fanlisting_id)";
        $affSortAllowed = ['name' => 'name', 'listing' => $affListingSub, 'link_status' => 'link_status', 'reciprocal' => 'reciprocal_status', 'last_checked' => 'link_checked_at'];
        $affSortParam   = trim((string) $request->query('aff_sort', $defaultSort));
        $affDirParam    = strtolower(trim((string) $request->query('aff_dir', 'asc')));
        if (!isset($affSortAllowed[$affSortParam])) { $affSortParam = $defaultSort; }
        if (!in_array($affDirParam, ['asc', 'desc'], true)) { $affDirParam = 'asc'; }
        $affSortCol = $affSortAllowed[$affSortParam];
        $affSortDir = $affDirParam === 'desc' ? 'DESC' : 'ASC';
        $affOrderBy = $affSortCol . ' ' . $affSortDir . ($affSortCol !== 'name' ? ', name ASC' : '');

        $joinedSortAllowed = ['name' => 'name', 'link_status' => 'link_status', 'last_checked' => 'link_checked_at'];
        $joinedSortParam   = trim((string) $request->query('joined_sort', $defaultSort));
        $joinedDirParam    = strtolower(trim((string) $request->query('joined_dir', 'asc')));
        if (!isset($joinedSortAllowed[$joinedSortParam])) { $joinedSortParam = $defaultSort; }
        if (!in_array($joinedDirParam, ['asc', 'desc'], true)) { $joinedDirParam = 'asc'; }
        $joinedSortCol = $joinedSortAllowed[$joinedSortParam];
        $joinedSortDir = $joinedDirParam === 'desc' ? 'DESC' : 'ASC';
        $joinedOrderBy = $joinedSortCol . ' ' . $joinedSortDir . ($joinedSortCol !== 'name' ? ', name ASC' : '');

        $memSortAllowed = [
            'member_name'     => 'member_name',
            'member_url'      => 'member_url',
            'link_status'     => 'link_status',
            'link_checked_at' => 'link_checked_at',
        ];
        $memSortParam = trim((string) $request->query('mem_sort', 'member_name'));
        $memDirParam  = strtolower(trim((string) $request->query('mem_dir', 'asc')));
        if (!isset($memSortAllowed[$memSortParam])) { $memSortParam = 'member_name'; }
        if (!in_array($memDirParam, ['asc', 'desc'], true)) { $memDirParam = 'asc'; }
        $memSortCol = $memSortAllowed[$memSortParam];
        $memSortDir = $memDirParam;

        $statusArg    = $statusParam !== '' ? $statusParam : null;
        $searchArg    = $searchParam !== '' ? $searchParam : null;
        $affResult    = $affRepo->paginateForLinkCheck($listingFilter, $perPage, $affPage, $affOrderBy, $statusArg, $searchArg);
        $joinedResult = $joinedRepo->paginateForLinkCheck($perPage, $joinedPage, $joinedOrderBy, $statusArg, $searchArg);

        $listingRepo  = new ListingRepo($db, $config);
        $allListings  = $listingRepo->getAll();

        $memRepo      = new MemberLinkCheckRepo($db, $config);
        $memItems     = [];
        $memTotal     = 0;
        $memStats     = ['ok' => 0, 'dead' => 0, 'redirect' => 0, 'unchecked' => 0];
        $movedMembers = [];
        $deadMembers  = [];
        $memListing   = null;
        $memSummary   = [];

        // Pre-fetch member summary for sorting the overview table by last_checked
        $memSummaryAll = $memRepo->getPerListingSummary();

        // Sort the listing overview table
        if ($memSumSortParam === 'listing') {
            usort($allListings, static fn($a, $b) =>
                strcasecmp($a->subject ?: $a->title, $b->subject ?: $b->title)
            );
            if ($memSumDirParam === 'desc') { $allListings = array_reverse($allListings); }
        } elseif ($memSumSortParam === 'last_checked') {
            usort($allListings, static function ($a, $b) use ($memSummaryAll) {
                $aDate = $memSummaryAll[$a->id]['last_checked'] ?? null;
                $bDate = $memSummaryAll[$b->id]['last_checked'] ?? null;
                if ($aDate === null && $bDate === null) { return 0; }
                if ($aDate === null) { return 1; }
                if ($bDate === null) { return -1; }
                return strcmp($aDate, $bDate);
            });
            if ($memSumDirParam === 'desc') { $allListings = array_reverse($allListings); }
        } elseif ($memSumDirParam === 'desc') {
            $allListings = array_reverse($allListings);
        }

        $memSumTotal      = count($allListings);
        $memSumTotalPages = max(1, (int) ceil($memSumTotal / $perPage));
        $memSumPage       = min($memSumPage, $memSumTotalPages);
        $memSumOffset     = ($memSumPage - 1) * $perPage;
        $memSumListings   = array_slice($allListings, $memSumOffset, $perPage);

        if ($listingFilter !== null && $listingFilter > 0) {
            $memListing   = $listingRepo->getById($listingFilter);
            $memResult    = $memRepo->paginate(
                $listingFilter,
                $statusParam,
                $searchParam,
                $memSortCol,
                $memSortDir,
                $perPage,
                $memPage,
            );
            $memItems     = $memResult['items'];
            $memTotal     = $memResult['total'];
            $memStats     = $memRepo->getLinkStats($listingFilter);
            $movedMembers = $memRepo->getByLinkStatus('redirect', $listingFilter);
            $deadMembers  = $memRepo->getByLinkStatus('dead', $listingFilter);
        } elseif ($listingFilter === null) {
            $memStats     = $memRepo->getLinkStatsAll();
            $memSummary   = $memSummaryAll;
            $memTotal     = array_sum($memStats);
            $movedMembers = $memRepo->getByLinkStatusAll('redirect');
            $deadMembers  = $memRepo->getByLinkStatusAll('dead');
        }
        // listing_id=0 (Collective) has no member table — $mem* defaults stay empty

        return $this->adminLayout(
            'admin/link-checker/index.php',
            'Link Checker',
            'link-checker',
            [
                'affiliates'         => $affResult['items'],
                'joined'             => $joinedResult['items'],
                'aff_total'          => $affResult['total'],
                'joined_total'       => $joinedResult['total'],
                'aff_page'           => $affPage,
                'joined_page'        => $joinedPage,
                'aff_total_pages'    => max(1, (int) ceil($affResult['total'] / $perPage)),
                'joined_total_pages' => max(1, (int) ceil($joinedResult['total'] / $perPage)),
                'aff_stats'          => $affRepo->getLinkStats($listingFilter),
                'joined_stats'       => $joinedRepo->getLinkStats(),
                'aff_scope_counts'   => $affRepo->getScopeCounts(),
                'moved_affiliates'   => $affRepo->getByLinkStatus('redirect', $listingFilter),
                'moved_joined'       => $joinedRepo->getByLinkStatus('redirect'),
                'dead_affiliates'    => $affRepo->getByLinkStatus('dead', $listingFilter),
                'dead_joined'        => $joinedRepo->getByLinkStatus('dead'),
                'members'            => $memItems,
                'mem_total'          => $memTotal,
                'mem_page'           => $memPage,
                'mem_total_pages'    => max(1, (int) ceil($memTotal / $perPage)),
                'mem_stats'          => $memStats,
                'moved_members'      => $movedMembers,
                'dead_members'       => $deadMembers,
                'mem_sort'           => $memSortParam,
                'mem_dir'            => $memDirParam,
                'mem_listing'        => $memListing,
                'mem_summary'        => $memSummary,
                'owner_url'          => $this->resolveReciprocalUrl(),
                'listings'           => $allListings,
                'mem_sum_listings'   => $memSumListings,
                'mem_sum_page'       => $memSumPage,
                'mem_sum_total'      => $memSumTotal,
                'mem_sum_total_pages'=> $memSumTotalPages,
                'mem_sum_sort'       => $memSumSortParam,
                'mem_sum_dir'        => $memSumDirParam,
                'listing_id_filter'  => $listingFilter,
                'status'             => $statusParam,
                'search'             => $searchParam,
                'aff_sort'           => $affSortParam,
                'aff_dir'            => $affDirParam,
                'joined_sort'        => $joinedSortParam,
                'joined_dir'         => $joinedDirParam,
                'lc_per_page'        => $perPage,
                'success'            => $success,
                'error'              => $error,
            ],
        );
    }

    // -----------------------------------------------------------------------
    // Settings — GET shows page, POST saves display settings
    // -----------------------------------------------------------------------

    /**
     * Renders the Link Checker Settings page and processes its form.
     *
     * On GET: loads current display settings (per-page count, default sort column)
     * from config, generates or reads the auto-check cron token from lstr_site_settings,
     * and renders the settings form with the cron URL pre-built.
     * On POST: validates the submitted options, writes them to config/app.php via
     * writeConfig(), then redirects back. A "Reset to defaults" button restores
     * per-page=10 and default-sort=name without touching cron settings.
     */
    public function settings(Request $request): Response
    {
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        if ($request->isPost()) {
            if (!$this->validCsrf($request)) {
                $session->set('lstr_flash_error', 'Invalid security token.');
                return Response::redirect($this->adminUrl('/admin/link-checker/settings'));
            }

            if ($request->post('reset_defaults') === '1') {
                $defaultSort = 'name';
                $perPage     = 10;
            } else {
                $defaultSort = (string) $request->post('lc_default_sort', 'name');
                if (!in_array($defaultSort, ['name', 'link_status'], true)) { $defaultSort = 'name'; }
                $perPage = max(5, min(100, (int) $request->post('lc_per_page', 10)));
            }

            $updated = $config->all();
            $updated['lc_default_sort'] = $defaultSort;
            $updated['lc_per_page']     = $perPage;
            $this->writeConfig($updated);

            $msg = $request->post('reset_defaults') === '1' ? 'Settings reset to defaults.' : 'Link Checker settings saved.';
            $session->set('lstr_flash_success', $msg);
            return Response::redirect($this->adminUrl('/admin/link-checker/settings'));
        }

        $db           = $this->app->getDatabase();
        $settingsRepo = new SiteSettingsRepo($db);
        $cronEnabled  = ($settingsRepo->get('auto_link_check_enabled', '0') ?? '0') === '1';
        $cronToken    = (string) ($settingsRepo->get('auto_link_check_token', '') ?? '');
        $cronLastRun  = (string) ($settingsRepo->get('auto_link_check_last_run', '') ?? '');
        if ($cronToken === '') {
            $cronToken = bin2hex(random_bytes(16));
            $settingsRepo->set('auto_link_check_token', $cronToken);
        }
        $cronUrl = rtrim((string) $config->get('app_url', ''), '/') . '/cron/link-check.php?token=' . urlencode($cronToken);

        return $this->adminLayout(
            'admin/link-checker/settings.php',
            'Link Checker Settings',
            'link-checker',
            [
                'lc_default_sort' => (string) ($config->get('lc_default_sort', 'name') ?? 'name'),
                'lc_per_page'     => max(5, min(100, (int) ($config->get('lc_per_page', 10) ?? 10))),
                'cron_enabled'    => $cronEnabled,
                'cron_token'      => $cronToken,
                'cron_last_run'   => $cronLastRun,
                'cron_url'        => $cronUrl,
                'success'         => $success,
                'error'           => $error,
            ],
            '',
            [$this->crumb('Link Checker', $this->adminUrl('/admin/link-checker'))],
        );
    }

    // -----------------------------------------------------------------------
    // Save auto link-check cron settings
    // -----------------------------------------------------------------------

    /**
     * Saves the automatic link-check cron configuration to lstr_site_settings.
     *
     * Toggles the enabled flag and, when the "Regenerate token" checkbox is
     * ticked, generates a fresh shared-secret token (the old one becomes invalid
     * immediately). If no token exists yet, one is created on first save.
     * Logs the change to lstr_activity_log.
     */
    public function saveCronSettings(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            $this->app->getSession()->set('lstr_flash_error', 'Invalid security token.');
            return Response::redirect($this->adminUrl('/admin/link-checker/settings'));
        }

        $settings = new SiteSettingsRepo($this->app->getDatabase());
        $enabled  = $request->post('auto_link_check_enabled') === '1' ? '1' : '0';
        $settings->set('auto_link_check_enabled', $enabled);

        if ($request->post('regenerate_token') === '1') {
            $settings->set('auto_link_check_token', bin2hex(random_bytes(16)));
        } elseif (($settings->get('auto_link_check_token') ?? '') === '') {
            $settings->set('auto_link_check_token', bin2hex(random_bytes(16)));
        }

        $this->app->getSession()->set('lstr_flash_success', 'Auto-check settings saved.');

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0) ?: null,
            'link-checker',
            'Updated auto link check settings',
            $request->ip(),
        );

        return Response::redirect($this->adminUrl('/admin/link-checker/settings'));
    }

    // -----------------------------------------------------------------------
    // AJAX — return all IDs for a panel (used by "Check All" to span pages)
    // -----------------------------------------------------------------------

    /**
     * Returns a JSON array of every record ID for the requested panel.
     *
     * Used by the "Check All" button to collect IDs across all pages in one
     * request before dispatching per-row check calls. The panel= query parameter
     * selects which table to query: 'affiliates', 'joined', or 'members'.
     * For members, listing_id= must be present and positive; listing_id=0 or
     * absent returns an empty array (Collective has no member table).
     */
    public function allIds(Request $request): Response
    {
        $panel = (string) ($request->query('panel', '') ?? '');
        $db    = $this->app->getDatabase();
        $ids   = [];

        if ($panel === 'affiliates') {
            $listingIdRaw  = (string) $request->query('listing_id', '');
            $listingFilter = ($listingIdRaw !== '' && ctype_digit($listingIdRaw)) ? (int) $listingIdRaw : null;
            $ids = array_map(
                static fn($a) => $a->id,
                (new AffiliateRepo($db))->getAllForLinkCheck($listingFilter),
            );
        } elseif ($panel === 'joined') {
            $ids = array_map(
                static fn($j) => $j->id,
                (new JoinedListingRepo($db))->getAllForLinkCheck(),
            );
        } elseif ($panel === 'members') {
            $listingIdRaw = (string) $request->query('listing_id', '');
            $listingId    = ($listingIdRaw !== '' && ctype_digit($listingIdRaw)) ? (int) $listingIdRaw : -1;
            $memRepo      = new MemberLinkCheckRepo($db, $this->app->getConfig());
            if ($listingId > 0) {
                $ids = $memRepo->getAllIds($listingId);
            } elseif ($listingIdRaw === '') {
                $ids = $memRepo->getAllIdsAll();
            }
            // listing_id=0 (Collective) has no member table — $ids stays empty
        }

        return Response::json(array_values($ids));
    }

    // -----------------------------------------------------------------------
    // AJAX — check a single affiliate
    // -----------------------------------------------------------------------

    /**
     * Checks the URL of a single affiliate and updates its link and reciprocal status.
     *
     * Performs a live HTTP check via LinkChecker::check(), stores the result in
     * lstr_affiliates, then — unless the link is dead — performs a reciprocal
     * check via LinkChecker::checkReciprocal() and stores that result too.
     * Returns JSON with the new statuses and a human-readable checked_at timestamp.
     * Returns 503 if the cURL extension is not available.
     */
    public function checkAffiliate(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid CSRF token'], 403);
        }
        if (!function_exists('curl_init')) {
            return Response::json(['error' => 'The link checker requires the PHP cURL extension. Enable cURL on your server and try again.'], 503);
        }

        $id      = (int) ($request->post('id') ?? 0);
        $db      = $this->app->getDatabase();
        $repo    = new AffiliateRepo($db);
        $aff     = $repo->getById($id);

        if ($aff === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $result = LinkChecker::check($aff->url);
        $repo->updateLinkStatus($id, $result['status'], $result['redirect_url']);

        $reciprocalStatus = 'unchecked';
        if ($result['status'] !== 'dead') {
            $reciprocalStatus = LinkChecker::checkReciprocal($aff->url, $this->resolveReciprocalUrl());
            $repo->updateReciprocalStatus($id, $reciprocalStatus);
        }

        return Response::json([
            'link_status'       => $result['status'],
            'http_code'         => $result['http_code'],
            'redirect_url'      => $result['redirect_url'],
            'reciprocal_status' => $reciprocalStatus,
            'checked_at'        => date($this->dateFormat()),
        ]);
    }

    // -----------------------------------------------------------------------
    // AJAX — check a single joined listing
    // -----------------------------------------------------------------------

    /**
     * Checks the URL of a single joined listing and updates its link status.
     *
     * Performs a live HTTP check via LinkChecker::check() and stores the result
     * in lstr_joined. Does not perform a reciprocal check (joined listings are
     * external sites, not affiliates). Returns JSON with the new status and a
     * checked_at timestamp. Returns 503 if cURL is unavailable.
     */
    public function checkJoined(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid CSRF token'], 403);
        }
        if (!function_exists('curl_init')) {
            return Response::json(['error' => 'The link checker requires the PHP cURL extension. Enable cURL on your server and try again.'], 503);
        }

        $id     = (int) ($request->post('id') ?? 0);
        $db     = $this->app->getDatabase();
        $repo   = new JoinedListingRepo($db);
        $item   = $repo->getById($id);

        if ($item === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $result = LinkChecker::check($item->url);
        $repo->updateLinkStatus($id, $result['status'], $result['redirect_url']);

        return Response::json([
            'link_status'  => $result['status'],
            'http_code'    => $result['http_code'],
            'redirect_url' => $result['redirect_url'],
            'checked_at'   => date('Y-m-d H:i'),
        ]);
    }

    // -----------------------------------------------------------------------
    // Apply stored redirect URL to an affiliate record
    // -----------------------------------------------------------------------

    /**
     * Replaces an affiliate's current URL with the redirect target detected during the last check.
     *
     * Delegates to AffiliateRepo::applyRedirectUrl(), which copies the stored
     * redirect_url value into the url column and clears link_status back to
     * 'unchecked' so the admin can re-check the new URL. Logs the change.
     * Returns a flash error if the record is missing or not in redirect state.
     */
    public function applyRedirectAffiliate(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            $this->app->getSession()->set('lstr_flash_error', 'Invalid security token.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $id   = (int) ($request->post('id') ?? 0);
        $repo = new AffiliateRepo($this->app->getDatabase());

        if ($id <= 0 || !$repo->applyRedirectUrl($id)) {
            $this->app->getSession()->set('lstr_flash_error', 'Could not update URL — record not found or not in redirect state.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $this->app->getSession()->set('lstr_flash_success', 'Affiliate URL updated to redirect target and queued for re-check.');

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0) ?: null,
            'link-checker',
            "Applied redirect URL to affiliate #{$id}",
            $request->ip(),
        );

        return Response::redirect($this->adminUrl('/admin/link-checker'));
    }

    // -----------------------------------------------------------------------
    // Apply stored redirect URL to a joined listing record
    // -----------------------------------------------------------------------

    /**
     * Replaces a joined listing's current URL with the redirect target detected during the last check.
     *
     * Delegates to JoinedListingRepo::applyRedirectUrl(), which copies the stored
     * redirect_url value into the url column and clears link_status back to
     * 'unchecked'. Logs the change. Returns a flash error if the record is missing
     * or not in redirect state.
     */
    public function applyRedirectJoined(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            $this->app->getSession()->set('lstr_flash_error', 'Invalid security token.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $id   = (int) ($request->post('id') ?? 0);
        $repo = new JoinedListingRepo($this->app->getDatabase());

        if ($id <= 0 || !$repo->applyRedirectUrl($id)) {
            $this->app->getSession()->set('lstr_flash_error', 'Could not update URL — record not found or not in redirect state.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $this->app->getSession()->set('lstr_flash_success', 'Joined listing URL updated to redirect target and queued for re-check.');

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0) ?: null,
            'link-checker',
            "Applied redirect URL to joined listing #{$id}",
            $request->ip(),
        );

        return Response::redirect($this->adminUrl('/admin/link-checker'));
    }

    // -----------------------------------------------------------------------
    // Reset all affiliate link statuses
    // -----------------------------------------------------------------------

    /**
     * Resets the link_status and redirect_url on every affiliate row back to 'unchecked'.
     *
     * Useful when switching domains or after a server migration, so previous
     * stale check results do not persist. Logs the action and sets a flash
     * success message.
     */
    public function resetAffiliates(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        (new AffiliateRepo($this->app->getDatabase()))->resetLinkStatus();

        $this->app->getSession()->set('lstr_flash_success', 'Affiliate link statuses have been reset.');

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0) ?: null,
            'link-checker',
            'Reset all affiliate link statuses',
            $request->ip(),
        );

        return Response::redirect($this->adminUrl('/admin/link-checker'));
    }

    // -----------------------------------------------------------------------
    // Reset all joined listing link statuses
    // -----------------------------------------------------------------------

    /**
     * Resets the link_status and redirect_url on every joined-listing row back to 'unchecked'.
     *
     * Mirrors resetAffiliates() for the joined-listings table. Logs the action
     * and sets a flash success message.
     */
    public function resetJoined(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        (new JoinedListingRepo($this->app->getDatabase()))->resetLinkStatus();

        $this->app->getSession()->set('lstr_flash_success', 'Joined-listing link statuses have been reset.');

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0) ?: null,
            'link-checker',
            'Reset all joined-listing link statuses',
            $request->ip(),
        );

        return Response::redirect($this->adminUrl('/admin/link-checker'));
    }

    // -----------------------------------------------------------------------
    // Sync member URLs for a listing into the check table
    // -----------------------------------------------------------------------

    /**
     * Syncs a listing's approved member URLs into lstr_member_link_checks.
     *
     * Reads all approved members with a non-empty URL from the listing's member
     * table via MemberLinkCheckRepo::syncFromListing() and upserts them into
     * the check table (new rows added, existing rows' URLs updated, rows for
     * members who have since removed their URL deleted). Reports the synced
     * count in a flash message. Requires a valid listing with a dbprefix.
     */
    public function scanMembers(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            $this->app->getSession()->set('lstr_flash_error', 'Invalid security token.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $listingId = (int) ($request->post('listing_id') ?? 0);
        if ($listingId <= 0) {
            $this->app->getSession()->set('lstr_flash_error', 'Please select a listing to scan.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $listing = (new ListingRepo($db, $config))->getById($listingId);

        if ($listing === null) {
            $this->app->getSession()->set('lstr_flash_error', 'Listing not found.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        if ($listing->dbprefix === '') {
            $this->app->getSession()->set('lstr_flash_error', 'This listing has no member table configured and cannot be scanned.');
            return Response::redirect($this->adminUrl('/admin/link-checker?listing_id=' . $listingId));
        }

        try {
            $count = (new MemberLinkCheckRepo($db, $config))->syncFromListing($listingId, $listing->dbprefix);
        } catch (\Throwable $e) {
            $this->app->getSession()->set('lstr_flash_error', 'Scan failed: ' . $e->getMessage());
            return Response::redirect($this->adminUrl('/admin/link-checker?listing_id=' . $listingId));
        }

        $noun = $count !== 1 ? 'URLs' : 'URL';
        $this->app->getSession()->set('lstr_flash_success', "Scan complete: {$count} member {$noun} imported for checking.");

        (new ActivityRepo($db))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0) ?: null,
            'link-checker',
            "Scanned {$count} member URLs for listing #{$listingId}",
            $request->ip(),
        );

        return Response::redirect($this->adminUrl('/admin/link-checker?listing_id=' . $listingId));
    }

    // -----------------------------------------------------------------------
    // AJAX — sync one listing's member URLs (used by Scan All and per-row Scan)
    // -----------------------------------------------------------------------

    /**
     * AJAX variant of scanMembers() for use by the "Scan All" batch and per-row Scan buttons.
     *
     * Accepts listing_id as a POST field, syncs member URLs via
     * MemberLinkCheckRepo::syncFromListing(), and returns JSON with the synced
     * count and the updated link-status breakdown for that listing.
     * Returns JSON error responses (404, 422, 500) instead of redirecting.
     */
    public function scanMemberAjax(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid CSRF token'], 403);
        }

        $listingId = (int) ($request->post('listing_id') ?? 0);
        if ($listingId <= 0) {
            return Response::json(['error' => 'Invalid listing ID'], 422);
        }

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $listing = (new ListingRepo($db, $config))->getById($listingId);

        if ($listing === null) {
            return Response::json(['error' => 'Listing not found'], 404);
        }

        if ($listing->dbprefix === '') {
            return Response::json(['error' => 'Listing has no member table configured (skipped)'], 422);
        }

        try {
            $memRepo = new MemberLinkCheckRepo($db, $config);
            $count   = $memRepo->syncFromListing($listingId, $listing->dbprefix);
            $stats   = $memRepo->getLinkStats($listingId);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }

        return Response::json([
            'synced'     => $count,
            'listing_id' => $listingId,
            'stats'      => $stats,
        ]);
    }

    // -----------------------------------------------------------------------
    // AJAX — check a single member URL
    // -----------------------------------------------------------------------

    /**
     * Checks the URL stored in a single lstr_member_link_checks row and updates its status.
     *
     * Performs a live HTTP check via LinkChecker::check() and stores the result.
     * Returns JSON with the new link_status, http_code, redirect_url, and a
     * checked_at timestamp. Returns 503 if the cURL extension is not available.
     */
    public function checkMember(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid CSRF token'], 403);
        }
        if (!function_exists('curl_init')) {
            return Response::json(['error' => 'The link checker requires the PHP cURL extension. Enable cURL on your server and try again.'], 503);
        }

        $id     = (int) ($request->post('id') ?? 0);
        $db     = $this->app->getDatabase();
        $repo   = new MemberLinkCheckRepo($db, $this->app->getConfig());
        $member = $repo->getById($id);

        if ($member === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $result = LinkChecker::check($member->member_url);
        $repo->updateLinkStatus($id, $result['status'], $result['redirect_url']);

        return Response::json([
            'link_status'  => $result['status'],
            'http_code'    => $result['http_code'],
            'redirect_url' => $result['redirect_url'],
            'checked_at'   => date('Y-m-d H:i'),
        ]);
    }

    // -----------------------------------------------------------------------
    // Apply stored redirect URL to a member record
    // -----------------------------------------------------------------------

    /**
     * Applies the stored redirect URL to both the member link check row and the actual member record.
     *
     * Looks up the listing to obtain its dbprefix, then calls
     * MemberLinkCheckRepo::applyRedirectUrl(), which writes the new URL into both
     * lstr_member_link_checks and the member's row in the per-listing member table.
     * Resets link_status to 'unchecked' so the admin can re-verify the new URL.
     * Logs the change. Returns a flash error if the record is not in redirect state.
     */
    public function applyRedirectMember(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            $this->app->getSession()->set('lstr_flash_error', 'Invalid security token.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $id        = (int) ($request->post('id')         ?? 0);
        $listingId = (int) ($request->post('listing_id') ?? 0);

        if ($listingId <= 0) {
            $this->app->getSession()->set('lstr_flash_error', 'Listing not found.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $listing = (new ListingRepo($db, $config))->getById($listingId);

        if ($listing === null) {
            $this->app->getSession()->set('lstr_flash_error', 'Listing not found.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        if ($id <= 0 || !(new MemberLinkCheckRepo($db, $config))->applyRedirectUrl($id, $listing->dbprefix)) {
            $this->app->getSession()->set('lstr_flash_error', 'Could not update URL — record not found or not in redirect state.');
            return Response::redirect($this->adminUrl('/admin/link-checker?listing_id=' . $listingId));
        }

        $this->app->getSession()->set('lstr_flash_success', 'Member URL updated to redirect target and queued for re-check.');

        (new ActivityRepo($db))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0) ?: null,
            'link-checker',
            "Applied redirect URL to member link check record #{$id}",
            $request->ip(),
        );

        return Response::redirect($this->adminUrl('/admin/link-checker?listing_id=' . $listingId));
    }

    // -----------------------------------------------------------------------
    // Reset all member link statuses for a listing
    // -----------------------------------------------------------------------

    /**
     * Resets the link_status and redirect_url on every lstr_member_link_checks row for a listing.
     *
     * Requires a positive listing_id POST field. Returns a flash error if none
     * is provided. Logs the action and redirects back to the member tab for
     * the same listing.
     */
    public function resetMembers(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $listingId = (int) ($request->post('listing_id') ?? 0);
        if ($listingId <= 0) {
            $this->app->getSession()->set('lstr_flash_error', 'No listing selected.');
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $db = $this->app->getDatabase();
        (new MemberLinkCheckRepo($db, $this->app->getConfig()))->resetLinkStatus($listingId);

        $this->app->getSession()->set('lstr_flash_success', 'Member link statuses have been reset.');

        (new ActivityRepo($db))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0) ?: null,
            'link-checker',
            "Reset member link statuses for listing #{$listingId}",
            $request->ip(),
        );

        return Response::redirect($this->adminUrl('/admin/link-checker?listing_id=' . $listingId));
    }

    // -----------------------------------------------------------------------
    // Reset all link statuses (affiliates + joined listings)
    // -----------------------------------------------------------------------

    /**
     * Resets link statuses across affiliates, joined listings, and all member check rows.
     *
     * Calls resetLinkStatus() on all three repos in sequence. Useful before a
     * full re-scan after a domain change. Logs a single combined activity entry
     * and sets a flash success message.
     */
    public function resetAll(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $db = $this->app->getDatabase();
        (new AffiliateRepo($db))->resetLinkStatus();
        (new JoinedListingRepo($db))->resetLinkStatus();
        (new MemberLinkCheckRepo($db, $this->app->getConfig()))->resetLinkStatusAll();

        $this->app->getSession()->set('lstr_flash_success', 'All link statuses have been reset.');

        (new ActivityRepo($db))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0) ?: null,
            'link-checker',
            'Reset all affiliate, joined-listing, and member link statuses',
            $request->ip(),
        );

        return Response::redirect($this->adminUrl('/admin/link-checker'));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the date format string from lstr_site_settings, defaulting to 'j M Y'.
     *
     * Used to format the checked_at timestamp in AJAX responses so it matches
     * the date style the admin sees elsewhere in the panel.
     */
    private function dateFormat(): string
    {
        try {
            return (new SiteSettingsRepo($this->app->getDatabase()))->get('date_format', 'j M Y') ?? 'j M Y';
        } catch (\Throwable) {
            return 'j M Y';
        }
    }

    /**
     * Validates the _csrf POST field against the current session token.
     *
     * Returns true when the token is present and matches; false otherwise.
     * Callers gate their POST handlers on this return value rather than
     * letting CsrfMiddleware throw, because the link-checker endpoints need
     * to return structured error responses (JSON or flash+redirect) rather
     * than a generic 403 page.
     */
    private function validCsrf(Request $request): bool
    {
        $token = (string) ($request->post('_csrf') ?? '');
        return $this->app->getCSRF()->validate($token);
    }

    /**
     * Atomically writes an updated config array to config/app.php.
     *
     * Writes to a .tmp sidecar file first, then renames over the real file so
     * a partial write cannot corrupt the live config. Throws RuntimeException
     * if either the write or the rename fails.
     */
    private function writeConfig(array $config): void
    {
        $configPath = $this->app->getBasePath() . '/config/app.php';
        $tmpPath    = $configPath . '.tmp';

        $export  = "<?php\n\ndeclare(strict_types=1);\n\nreturn ";
        $export .= var_export($config, true);
        $export .= ";\n";

        $bytes = file_put_contents($tmpPath, $export, LOCK_EX);
        if ($bytes === false) {
            throw new \RuntimeException('Could not write temp config file: ' . $tmpPath);
        }

        if (!rename($tmpPath, $configPath)) {
            throw new \RuntimeException('Could not rename temp config to: ' . $configPath);
        }
    }

    /**
     * Returns the URL to search for in affiliate pages when performing a
     * reciprocal-link check. Uses the `reciprocal_url` config key when set,
     * falling back to `app_url`.
     */
    private function resolveReciprocalUrl(): string
    {
        $cfg        = $this->app->getConfig();
        $reciprocal = trim((string) $cfg->get('reciprocal_url', ''));
        $base       = $reciprocal !== '' ? $reciprocal : (string) $cfg->get('app_url', '');
        return rtrim($base, '/');
    }
}
