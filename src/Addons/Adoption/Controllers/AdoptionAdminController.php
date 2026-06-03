<?php

declare(strict_types=1);

namespace Luster\Addons\Adoption\Controllers;

use Luster\Addons\Adoption\AdoptionRepo;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

/**
 * Admin management of adoption applications.
 *
 * Routes (registered by AdoptionAddon::registerRoutes()):
 *   GET  /admin/adoptions                         index()       — 3-tab overview
 *   GET  /admin/adoptions/{listingId}             forListing()  — per-listing applicant blocks
 *   POST /admin/adoptions/{id}/approve            approve()     — quick approve
 *   POST /admin/adoptions/{id}/reject             reject()      — quick reject
 *   POST /admin/adoptions/{id}/status             setStatus()   — status dropdown (detail page)
 *   POST /admin/adoptions/{id}/delete             delete()
 */
final class AdoptionAdminController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/adoptions  — tabbed overview
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $tab = trim((string) $request->query('tab', 'pending'));
        if (!in_array($tab, ['pending', 'listings', 'settings'], true)) {
            $tab = 'pending';
        }

        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $adoptionRepo = new AdoptionRepo($db);
        $listingRepo  = new ListingRepo($db, $config);
        $settingsRepo = new ListingSettingsRepo($db);
        $session      = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $data = ['tab' => $tab, 'success' => $success, 'error' => $error];

        if ($tab === 'pending') {
            $pending     = $adoptionRepo->getForAllListings('new');
            $allListings = $listingRepo->getAll();
            $listingMap  = [];
            foreach ($allListings as $fl) {
                $listingMap[$fl->id] = $fl;
            }
            $data['pending']     = $pending;
            $data['listing_map'] = $listingMap;
        } elseif ($tab === 'listings') {
            $adoptionListingIds = $settingsRepo->getAdoptionListingIds();
            $allListings        = $listingRepo->getAll();
            $listingCounts      = $adoptionRepo->getAllListingCounts();
            $adoptionListings   = [];
            foreach ($allListings as $fl) {
                if (!in_array($fl->id, $adoptionListingIds, true)) {
                    continue;
                }
                $counts = $listingCounts[$fl->id] ?? ['all' => 0, 'new' => 0, 'approved' => 0];
                $adoptionListings[] = [
                    'listing'        => $fl,
                    'count_new'      => $counts['new'],
                    'count_approved' => $counts['approved'],
                    'count_all'      => $counts['all'],
                ];
            }
            usort($adoptionListings, static fn($a, $b) => $b['count_new'] <=> $a['count_new']);
            $data['adoption_listings'] = $adoptionListings;
        }

        return $this->layout('admin/addons/adoptions/index.php', 'Adoptions', $data);
    }

    // -----------------------------------------------------------------------
    // GET /admin/adoptions/{listingId}
    // -----------------------------------------------------------------------

    public function forListing(Request $request, array $params): Response
    {
        $listingId = (int) ($params['listingId'] ?? 0);

        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $listingRepo  = new ListingRepo($db, $config);
        $adoptionRepo = new AdoptionRepo($db);

        $listing = $listingRepo->getById($listingId);
        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $statusFilter  = (string) $request->query('status', 'all');
        $validStatuses = ['all', 'new', 'reviewed', 'approved', 'rejected'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        $applications = $adoptionRepo->getForListing($listingId, $statusFilter);
        $counts       = $adoptionRepo->getCountsForListing($listingId);

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/addons/adoptions/listing.php', 'Adoptions — ' . $listing->name, [
            'listing'       => $listing,
            'applications'  => $applications,
            'status_filter' => $statusFilter,
            'counts'        => $counts,
            'success'       => $success,
            'error'         => $error,
        ], [
            $this->crumb('Adoptions', $this->adminUrl('/admin/adoptions')),
            $this->crumb($listing->name),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/adoptions/{id}/approve
    // -----------------------------------------------------------------------

    public function approve(Request $request, array $params): Response
    {
        return $this->quickStatus($request, $params, 'approved');
    }

    // -----------------------------------------------------------------------
    // POST /admin/adoptions/{id}/reject
    // -----------------------------------------------------------------------

    public function reject(Request $request, array $params): Response
    {
        return $this->quickStatus($request, $params, 'rejected');
    }

    // -----------------------------------------------------------------------
    // POST /admin/adoptions/{id}/status
    // -----------------------------------------------------------------------

    public function setStatus(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $id           = (int) ($params['id'] ?? 0);
        $db           = $this->app->getDatabase();
        $adoptionRepo = new AdoptionRepo($db);
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $application = $adoptionRepo->getById($id);
        if ($application === null) {
            return Response::notFound('Application not found.');
        }

        $status = (string) $request->post('status', '');
        $adoptionRepo->setStatus($id, $status);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id', 0) ?: null,
            'adoption_status',
            "Set adoption application #{$id} for listing #{$application['fanlisting_id']} to '{$status}'"
        );

        $session->set('lstr_flash_success', 'Application status updated.');
        return $this->redirectAfterAction($request, $baseUrl, (int) $application['fanlisting_id']);
    }

    // -----------------------------------------------------------------------
    // POST /admin/adoptions/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $id           = (int) ($params['id'] ?? 0);
        $db           = $this->app->getDatabase();
        $adoptionRepo = new AdoptionRepo($db);
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $application = $adoptionRepo->getById($id);
        if ($application === null) {
            return Response::notFound('Application not found.');
        }

        $listingId = (int) $application['fanlisting_id'];
        $adoptionRepo->delete($id);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id', 0) ?: null,
            'adoption_delete',
            "Deleted adoption application #{$id} for listing #{$listingId}"
        );

        $session->set('lstr_flash_success', 'Application deleted.');
        return $this->redirectAfterAction($request, $baseUrl, $listingId);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Quick approve/reject action shared by approve() and reject().
     * Reads _return from POST: 'pending' goes back to the pending tab, else per-listing.
     */
    private function quickStatus(Request $request, array $params, string $status): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $id           = (int) ($params['id'] ?? 0);
        $db           = $this->app->getDatabase();
        $adoptionRepo = new AdoptionRepo($db);
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $application = $adoptionRepo->getById($id);
        if ($application === null) {
            return Response::notFound('Application not found.');
        }

        $adoptionRepo->setStatus($id, $status);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id', 0) ?: null,
            'adoption_status',
            "Set adoption application #{$id} for listing #{$application['fanlisting_id']} to '{$status}'"
        );

        $session->set('lstr_flash_success', 'Application ' . $status . '.');
        return $this->redirectAfterAction($request, $baseUrl, (int) $application['fanlisting_id']);
    }

    /**
     * Redirects after any POST action.
     * _return=pending → pending tab; anything else → per-listing page.
     */
    private function redirectAfterAction(Request $request, string $baseUrl, int $listingId): Response
    {
        if ((string) $request->post('_return', '') === 'pending') {
            return Response::redirect($baseUrl . '/admin/adoptions?tab=pending');
        }
        return Response::redirect($baseUrl . '/admin/adoptions/' . $listingId);
    }

    private function layout(string $template, string $title, array $data, array $breadcrumbs = []): Response
    {
        $config    = $this->app->getConfig();
        $session   = $this->app->getSession();
        $view      = $this->app->getView();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $inner = $view->render($template, array_merge($data, [
            'base_url'   => $baseUrl,
            'csrf_token' => $csrfToken,
        ]));

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'       => $title,
            'site_name'        => (string) $config->get('site_name', 'Luster'),
            'admin_username'   => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'       => $csrfToken,
            'current_section'  => 'adoptions',
            'base_url'         => $baseUrl,
            'content'          => $inner,
            'page_breadcrumbs' => $breadcrumbs,
        ], $this->app->getAdminNavData())));
    }

    private function adminUrl(string $path): string
    {
        return $this->app->getBaseUrl() . $path;
    }

    /** @return array{label: string, url: string} */
    private function crumb(string $label, string $url = ''): array
    {
        return ['label' => $label, 'url' => $url];
    }
}
