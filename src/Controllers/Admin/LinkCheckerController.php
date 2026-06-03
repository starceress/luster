<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\LinkChecker;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

final class LinkCheckerController extends AdminController
{
    // -----------------------------------------------------------------------
    // Index — summary page with affiliate + joined tables
    // -----------------------------------------------------------------------

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

        $statusValues = ['ok', 'redirect', 'dead', 'unchecked'];
        $statusParam  = trim((string) $request->query('status', ''));
        if (!in_array($statusParam, $statusValues, true)) { $statusParam = ''; }

        $searchParam = trim((string) $request->query('search', ''));

        $affSortAllowed = ['name' => 'name', 'link_status' => 'link_status', 'reciprocal' => 'reciprocal_status', 'last_checked' => 'link_checked_at'];
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

        $statusArg    = $statusParam !== '' ? $statusParam : null;
        $searchArg    = $searchParam !== '' ? $searchParam : null;
        $affResult    = $affRepo->paginateForLinkCheck($listingFilter, $perPage, $affPage, $affOrderBy, $statusArg, $searchArg);
        $joinedResult = $joinedRepo->paginateForLinkCheck($perPage, $joinedPage, $joinedOrderBy, $statusArg, $searchArg);

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
                'moved_affiliates'   => $affRepo->getByLinkStatus('redirect', $listingFilter),
                'moved_joined'       => $joinedRepo->getByLinkStatus('redirect'),
                'dead_affiliates'    => $affRepo->getByLinkStatus('dead', $listingFilter),
                'dead_joined'        => $joinedRepo->getByLinkStatus('dead'),
                'owner_url'          => $this->resolveReciprocalUrl(),
                'listings'           => (new ListingRepo($db, $config))->getAll(),
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
        );

        return Response::redirect($this->adminUrl('/admin/link-checker/settings'));
    }

    // -----------------------------------------------------------------------
    // AJAX — return all IDs for a panel (used by "Check All" to span pages)
    // -----------------------------------------------------------------------

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
        }

        return Response::json(array_values($ids));
    }

    // -----------------------------------------------------------------------
    // AJAX — check a single affiliate
    // -----------------------------------------------------------------------

    public function checkAffiliate(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid CSRF token'], 403);
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

    public function checkJoined(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::json(['error' => 'Invalid CSRF token'], 403);
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
        );

        return Response::redirect($this->adminUrl('/admin/link-checker'));
    }

    // -----------------------------------------------------------------------
    // Apply stored redirect URL to a joined listing record
    // -----------------------------------------------------------------------

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
        );

        return Response::redirect($this->adminUrl('/admin/link-checker'));
    }

    // -----------------------------------------------------------------------
    // Reset all affiliate link statuses
    // -----------------------------------------------------------------------

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
        );

        return Response::redirect($this->adminUrl('/admin/link-checker'));
    }

    // -----------------------------------------------------------------------
    // Reset all joined listing link statuses
    // -----------------------------------------------------------------------

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
        );

        return Response::redirect($this->adminUrl('/admin/link-checker'));
    }

    // -----------------------------------------------------------------------
    // Reset all link statuses (affiliates + joined listings)
    // -----------------------------------------------------------------------

    public function resetAll(Request $request): Response
    {
        if (!$this->validCsrf($request)) {
            return Response::redirect($this->adminUrl('/admin/link-checker'));
        }

        $db = $this->app->getDatabase();
        (new AffiliateRepo($db))->resetLinkStatus();
        (new JoinedListingRepo($db))->resetLinkStatus();

        $this->app->getSession()->set('lstr_flash_success', 'All link statuses have been reset.');

        (new ActivityRepo($db))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0) ?: null,
            'link-checker',
            'Reset all affiliate and joined-listing link statuses',
        );

        return Response::redirect($this->adminUrl('/admin/link-checker'));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function dateFormat(): string
    {
        try {
            return (new SiteSettingsRepo($this->app->getDatabase()))->get('date_format', 'j M Y') ?? 'j M Y';
        } catch (\Throwable) {
            return 'j M Y';
        }
    }

    private function validCsrf(Request $request): bool
    {
        $token = (string) ($request->post('_csrf') ?? '');
        return $this->app->getCSRF()->validate($token);
    }

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
