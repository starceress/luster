<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;

/**
 * Shows the import repair centre: a diagnostic view over external refs
 * (lstr_listing_external_refs) that summarises import coverage, highlights
 * listings with no refs, shows FansFansFans IDs, and allows individual ref
 * rows to be cleared when stale.
 *
 * Routes:
 *   GET  /admin/import/repair              — overview + repair actions
 *   POST /admin/import/repair/clear-ref    — delete one external ref row
 *   POST /admin/import/repair/clear-all    — delete all refs for one source
 */
final class ImportRepairController extends AdminController
{
    // -----------------------------------------------------------------------
    // GET /admin/import/repair
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $repo    = new ListingRepo($db, $this->app->getConfig());
        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        // All owned listings: id → title
        $allListings = [];
        foreach ($repo->getAll() as $fl) {
            $allListings[$fl->id] = $fl;
        }

        // External refs grouped by source
        $refsBySource = [];
        $refsByListingId = [];
        try {
            $stmt = $db->prepare(
                'SELECT id, listing_id, source, external_id, external_slug_or_url, created_at
                 FROM lstr_listing_external_refs
                 ORDER BY source ASC, listing_id ASC'
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $src = (string) $row['source'];
                $refsBySource[$src][] = $row;
                $refsByListingId[(int) $row['listing_id']][] = $src;
            }
        } catch (\Throwable) {}

        // Listings with no external refs
        $listingsWithNoRefs = [];
        foreach ($allListings as $id => $fl) {
            if (!isset($refsByListingId[$id])) {
                $listingsWithNoRefs[] = $fl;
            }
        }

        // FansFansFans IDs (source = 'fansfansfans')
        $fansFansRefs = $refsBySource['fansfansfans'] ?? [];

        // Summary counts
        $summary = [];
        foreach ($refsBySource as $src => $refs) {
            $summary[$src] = [
                'count'    => count($refs),
                'listings' => count(array_unique(array_column($refs, 'listing_id'))),
            ];
        }

        return $this->adminLayout('admin/import/repair.php', 'Import Repair Centre', 'import', [
            'refs_by_source'       => $refsBySource,
            'all_listings'         => $allListings,
            'listings_no_refs'     => $listingsWithNoRefs,
            'fansfans_refs'        => $fansFansRefs,
            'summary'              => $summary,
            'success'              => $success,
            'error'                => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/import/repair/clear-ref
    // -----------------------------------------------------------------------

    public function clearRef(Request $request, array $params): Response
    {
        $refId   = (int) $request->post('ref_id', '0');
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($refId <= 0) {
            $session->set('lstr_flash_error', 'Invalid ref ID.');
            return Response::redirect($baseUrl . '/admin/import/repair');
        }

        try {
            $db->prepare('DELETE FROM lstr_listing_external_refs WHERE id = :id')
               ->execute(['id' => $refId]);
            $session->set('lstr_flash_success', 'External ref deleted.');
        } catch (\Throwable $e) {
            $session->set('lstr_flash_error', 'Could not delete ref: ' . $e->getMessage());
        }

        return Response::redirect($baseUrl . '/admin/import/repair');
    }

    // -----------------------------------------------------------------------
    // POST /admin/import/repair/clear-all
    // -----------------------------------------------------------------------

    public function clearAll(Request $request, array $params): Response
    {
        $source  = trim((string) $request->post('source', ''));
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($source === '') {
            $session->set('lstr_flash_error', 'Source is required.');
            return Response::redirect($baseUrl . '/admin/import/repair');
        }

        try {
            $stmt = $db->prepare('DELETE FROM lstr_listing_external_refs WHERE source = :source');
            $stmt->execute(['source' => $source]);
            $deleted = $stmt->rowCount();
            $session->set('lstr_flash_success', "Deleted {$deleted} ref(s) for source '{$source}'.");
        } catch (\Throwable $e) {
            $session->set('lstr_flash_error', 'Could not clear refs: ' . $e->getMessage());
        }

        return Response::redirect($baseUrl . '/admin/import/repair');
    }
}
