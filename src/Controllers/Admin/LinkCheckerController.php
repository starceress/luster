<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\LinkChecker;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\ActivityRepo;

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

        $db         = $this->app->getDatabase();
        $affRepo    = new AffiliateRepo($db);
        $joinedRepo = new JoinedListingRepo($db);

        $affiliates = $affRepo->getAllForLinkCheck();
        $joined     = $joinedRepo->getAllForLinkCheck();

        $affStats    = $this->summarise($affiliates, 'link');
        $joinedStats = $this->summarise($joined, 'link');

        return $this->adminLayout(
            'admin/link-checker/index.php',
            'Link Checker',
            'link-checker',
            [
                'affiliates'       => $affiliates,
                'joined'           => $joined,
                'aff_stats'        => $affStats,
                'joined_stats'     => $joinedStats,
                'moved_affiliates' => $affRepo->getByLinkStatus('redirect'),
                'moved_joined'     => $joinedRepo->getByLinkStatus('redirect'),
                'dead_affiliates'  => $affRepo->getByLinkStatus('dead'),
                'dead_joined'      => $joinedRepo->getByLinkStatus('dead'),
                'owner_url'        => rtrim((string) $this->app->getConfig()->get('app_url', ''), '/'),
                'success'          => $success,
                'error'            => $error,
            ],
        );
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
            $ownerUrl = rtrim((string) $this->app->getConfig()->get('app_url', ''), '/');
            $reciprocalStatus = LinkChecker::checkReciprocal($aff->url, $ownerUrl);
            $repo->updateReciprocalStatus($id, $reciprocalStatus);
        }

        return Response::json([
            'link_status'       => $result['status'],
            'http_code'         => $result['http_code'],
            'redirect_url'      => $result['redirect_url'],
            'reciprocal_status' => $reciprocalStatus,
            'checked_at'        => date('Y-m-d H:i'),
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
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Builds a summary array of status → count from any array of model objects.
     * $field is the property prefix: 'link' → reads ->linkStatus.
     *
     * @param object[] $items
     * @return array{ok:int,dead:int,redirect:int,unchecked:int}
     */
    private function summarise(array $items, string $field): array
    {
        $prop = $field . 'Status';
        $out  = ['ok' => 0, 'dead' => 0, 'redirect' => 0, 'unchecked' => 0];
        foreach ($items as $item) {
            $status = $item->$prop ?? 'unchecked';
            if (isset($out[$status])) {
                $out[$status]++;
            } else {
                $out['unchecked']++;
            }
        }
        return $out;
    }

    private function validCsrf(Request $request): bool
    {
        $token = (string) ($request->post('_csrf') ?? '');
        return $this->app->getCSRF()->validate($token);
    }
}
