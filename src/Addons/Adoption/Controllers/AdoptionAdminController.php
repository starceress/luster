<?php

declare(strict_types=1);

namespace Luster\Addons\Adoption\Controllers;

use Luster\Addons\Adoption\AdoptionRepo;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\ActivityRepo;

/**
 * Admin management of adoption applications.
 *
 * Routes (registered by AdoptionAddon::registerRoutes()):
 *   GET  /admin/adoptions                    index()
 *   GET  /admin/adoptions/{listingId}        forListing()
 *   POST /admin/adoptions/{id}/status        setStatus()
 *   POST /admin/adoptions/{id}/delete        delete()
 */
final class AdoptionAdminController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/adoptions
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $listingRepo  = new ListingRepo($db, $config);
        $settingsRepo = new ListingSettingsRepo($db);
        $adoptionRepo = new AdoptionRepo($db);

        $adoptionListingIds = $settingsRepo->getAdoptionListingIds();
        $listings           = $listingRepo->getAll();

        $adoptionListings = [];
        foreach ($listings as $fl) {
            if (!in_array($fl->id, $adoptionListingIds, true)) {
                continue;
            }
            $adoptionListings[] = [
                'listing'   => $fl,
                'count_new' => $adoptionRepo->countForListing($fl->id, 'new'),
                'count_all' => $adoptionRepo->countForListing($fl->id, 'all'),
            ];
        }

        usort($adoptionListings, static fn($a, $b) => $b['count_new'] <=> $a['count_new']);

        return $this->layout('admin/adoptions/index.php', 'Adoptions', [
            'adoption_listings' => $adoptionListings,
        ]);
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

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $session->remove('lstr_flash_success');

        return $this->layout('admin/adoptions/listing.php', 'Adoptions — ' . $listing->name, [
            'listing'        => $listing,
            'applications'   => $applications,
            'status_filter'  => $statusFilter,
            'valid_statuses' => $validStatuses,
            'count_new'      => $adoptionRepo->countForListing($listingId, 'new'),
            'success'        => $success,
        ], [
            $this->crumb('Adoptions', $this->adminUrl('/admin/adoptions')),
            $this->crumb($listing->name),
        ]);
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
        return Response::redirect($baseUrl . '/admin/adoptions/' . (int) $application['fanlisting_id']);
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
        return Response::redirect($baseUrl . '/admin/adoptions/' . $listingId);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

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
