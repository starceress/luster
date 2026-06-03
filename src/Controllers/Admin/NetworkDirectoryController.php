<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\NetworkDirectoryService;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;

/**
 * Checks and displays registration status of owned listings on TFL and TAFL.
 *
 * Results are cached locally (7 days) to avoid hammering the network directories.
 * Site owners trigger a re-check via the refresh button on each row.
 *
 * Routes:
 *   GET  /admin/network-directory             — status overview table
 *   POST /admin/network-directory/refresh     — force re-fetch for one listing + network
 *   POST /admin/network-directory/refresh-all — force re-fetch for all active listings
 */
final class NetworkDirectoryController extends AdminController
{
    private function makeService(): NetworkDirectoryService
    {
        $cacheDir = $this->app->getBasePath() . '/storage/cache';
        return new NetworkDirectoryService($cacheDir);
    }

    // -----------------------------------------------------------------------
    // GET /admin/network-directory
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $repo     = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listings = $repo->getFiltered('current');
        $svc      = $this->makeService();

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        // Build status rows — load cached results only (no live fetch on page load)
        $rows = [];
        foreach ($listings as $listing) {
            $statuses = [];
            foreach (NetworkDirectoryService::networks() as $network) {
                $statuses[$network] = $svc->getCached($listing->url, $network);
            }
            $rows[] = [
                'listing'  => $listing,
                'statuses' => $statuses,
            ];
        }

        return $this->adminLayout('admin/network-directory/index.php', 'Network Directory', 'settings', [
            'rows'     => $rows,
            'networks' => NetworkDirectoryService::networks(),
            'success'  => $success,
            'error'    => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/network-directory/refresh
    // -----------------------------------------------------------------------

    public function refresh(Request $request, array $params): Response
    {
        $listingId  = (int) $request->post('listing_id', '0');
        $network    = trim((string) $request->post('network', ''));
        $baseUrl    = $this->app->getBaseUrl();
        $session    = $this->app->getSession();

        if (!in_array($network, NetworkDirectoryService::networks(), true)) {
            $session->set('lstr_flash_error', 'Invalid network.');
            return Response::redirect($baseUrl . '/admin/network-directory');
        }

        $repo    = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing = null;
        foreach ($repo->getFiltered('current') as $fl) {
            if ($fl->id === $listingId) {
                $listing = $fl;
                break;
            }
        }

        if ($listing === null) {
            $session->set('lstr_flash_error', 'Listing not found.');
            return Response::redirect($baseUrl . '/admin/network-directory');
        }

        $svc    = $this->makeService();
        $result = $svc->refresh($listing->url, $network);
        $label  = NetworkDirectoryService::networkLabel($network);

        if ($result['error'] !== null) {
            $session->set('lstr_flash_error', "Check failed for {$label}: " . $result['error']);
        } elseif ($result['found']) {
            $session->set('lstr_flash_success', "\"{$listing->name}\" is registered on {$label}.");
        } else {
            $session->set('lstr_flash_success', "\"{$listing->name}\" was not found on {$label} (HTTP {$result['http_code']}).");
        }

        return Response::redirect($baseUrl . '/admin/network-directory');
    }

    // -----------------------------------------------------------------------
    // POST /admin/network-directory/refresh-all
    // -----------------------------------------------------------------------

    public function refreshAll(Request $request, array $params): Response
    {
        $repo     = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listings = $repo->getFiltered('current');
        $svc      = $this->makeService();
        $baseUrl  = $this->app->getBaseUrl();
        $session  = $this->app->getSession();

        // Leave 5 s headroom so we return before PHP / web-server kills the request.
        $limit    = max(15, (int) ini_get('max_execution_time') - 5);
        $deadline = microtime(true) + $limit;

        $checked = 0;
        $skipped = 0;
        foreach ($listings as $listing) {
            foreach (NetworkDirectoryService::networks() as $network) {
                if (microtime(true) >= $deadline) {
                    $skipped++;
                    continue;
                }
                // lookup() uses cache when fresh — only makes a live HTTP request
                // when the cached result is missing or older than 7 days.
                $svc->lookup($listing->url, $network);
                $checked++;
            }
        }

        $msg = "Checked {$checked} network registration(s) for " . count($listings) . ' listing(s).';
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped (time limit) — run again to continue.";
        }
        $session->set('lstr_flash_success', $msg);
        return Response::redirect($baseUrl . '/admin/network-directory');
    }
}
