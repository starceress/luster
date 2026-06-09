<?php

declare(strict_types=1);

namespace Luster\Addons\Adoption\Controllers;

use Luster\Addons\Adoption\AdoptionRepo;
use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Admin management of adoption applications.
 *
 * Routes (registered by AdoptionAddon::registerRoutes()):
 *   GET  /admin/adoptions                         index()       — applications overview
 *   POST /admin/adoptions/bulk                    bulk()        — bulk actions
 *   GET  /admin/adoptions/settings                settingsForm()
 *   POST /admin/adoptions/settings                saveSettings()
 *   GET  /admin/adoptions/{id}/edit               editForm()
 *   POST /admin/adoptions/{id}/edit               editSave()
 *   POST /admin/adoptions/{id}/approve            approve()
 *   POST /admin/adoptions/{id}/reject             reject()
 *   POST /admin/adoptions/{id}/status             setStatus()
 *   POST /admin/adoptions/{id}/delete             delete()
 */
final class AdoptionAdminController
{
    private const APP_STATUS_TABS = ['all', 'pending', 'reviewed', 'approved', 'rejected', 'granted'];
    private const VALID_STATUSES  = ['pending', 'reviewed', 'approved', 'rejected', 'granted'];

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/adoptions
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $tab = trim((string) $request->query('tab', 'all'));
        if (!in_array($tab, array_merge(self::APP_STATUS_TABS, ['listings']), true)) {
            $tab = 'all';
        }

        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $adoptionRepo = new AdoptionRepo($db);
        $listingRepo  = new ListingRepo($db, $config);
        $settingsRepo = new ListingSettingsRepo($db);
        $siteSettings = new SiteSettingsRepo($db);
        $session      = $this->app->getSession();

        $defaultLanding = $siteSettings->get('adoption_default_landing', 'applications') ?? 'applications';
        $perPage        = max(5, min(25, (int) ($siteSettings->get('adoption_per_page', '10') ?? '10')));

        $isBareNavigation = $request->query('tab', null) === null
                         && $request->query('listing_id', null) === null
                         && $request->query('search', null) === null
                         && $request->query('sort', null) === null
                         && $request->query('page', null) === null;

        if ($isBareNavigation) {
            if ($defaultLanding === 'listings') {
                return Response::redirect($this->app->getBaseUrl() . '/admin/adoptions?tab=listings');
            }
            $pendingCount = $adoptionRepo->queryCounts()['pending'] ?? 0;
            $landingTab   = $pendingCount > 0 ? 'pending' : 'all';
            return Response::redirect($this->app->getBaseUrl() . '/admin/adoptions?tab=' . $landingTab);
        }

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $data = ['tab' => $tab, 'success' => $success, 'error' => $error];

        if (in_array($tab, self::APP_STATUS_TABS, true)) {
            $listingId     = (int) $request->query('listing_id', 0);
            $search        = trim((string) $request->query('search', ''));
            $sort          = trim((string) $request->query('sort', 'applied_at'));
            $dir           = trim((string) $request->query('dir',  'desc'));
            $filtersActive = $listingId > 0 || $search !== '';

            if (!in_array($sort, ['name', 'email', 'listing', 'status', 'applied_at'], true)) {
                $sort = 'applied_at';
            }
            if (!in_array($dir, ['asc', 'desc'], true)) {
                $dir = 'desc';
            }

            $allApplications = $adoptionRepo->query($tab, $listingId, $search);
            $counts          = $adoptionRepo->queryCounts($listingId, $search);
            $allListings     = $listingRepo->getAll();

            $listingMap = [];
            foreach ($allListings as $fl) {
                $listingMap[$fl->id] = $fl;
            }

            // Sort in PHP — avoids JOIN complexity; adoption sets are small
            usort($allApplications, static function (array $a, array $b) use ($sort, $dir, $listingMap): int {
                $av = match ($sort) {
                    'name'    => strtolower($a['name']),
                    'email'   => strtolower($a['email']),
                    'listing' => strtolower($listingMap[(int) $a['fanlisting_id']]?->subject ?? ''),
                    'status'  => $a['status'],
                    default   => $a['applied_at'],
                };
                $bv = match ($sort) {
                    'name'    => strtolower($b['name']),
                    'email'   => strtolower($b['email']),
                    'listing' => strtolower($listingMap[(int) $b['fanlisting_id']]?->subject ?? ''),
                    'status'  => $b['status'],
                    default   => $b['applied_at'],
                };
                $cmp = $av <=> $bv;
                return $dir === 'desc' ? -$cmp : $cmp;
            });

            $total      = count($allApplications);
            $totalPages = max(1, (int) ceil($total / $perPage));
            $page       = min(max(1, (int) $request->query('page', 1)), $totalPages);
            $offset     = ($page - 1) * $perPage;
            $applications = array_slice($allApplications, $offset, $perPage);

            $data += [
                'applications'          => $applications,
                'counts'                => $counts,
                'adoption_listing_counts' => $adoptionRepo->getAllListingCounts(),
                'fanlistings'           => $allListings,
                'listing_map'           => $listingMap,
                'selected_listing' => $listingId > 0 ? ($listingMap[$listingId] ?? null) : null,
                'listing_id'       => $listingId,
                'search'           => $search,
                'filters_active'   => $filtersActive,
                'sort'             => $sort,
                'dir'              => $dir,
                'page'             => $page,
                'total_pages'      => $totalPages,
                'total'            => $total,
                'per_page'         => $perPage,
            ];
        } elseif ($tab === 'listings') {
            $adoptionListingIds = $settingsRepo->getAdoptionListingIds();
            $allListings        = $listingRepo->getAll();
            $listingCounts      = $adoptionRepo->getAllListingCounts();
            $adoptionListings   = [];
            foreach ($allListings as $fl) {
                if (!in_array($fl->id, $adoptionListingIds, true)) {
                    continue;
                }
                $lc = $listingCounts[$fl->id] ?? ['all' => 0, 'pending' => 0, 'approved' => 0];
                $adoptionListings[] = [
                    'listing'       => $fl,
                    'count_pending'  => $lc['pending'],
                    'count_approved' => $lc['approved'],
                    'count_all'      => $lc['all'],
                ];
            }
            usort($adoptionListings, static fn($a, $b) => $b['count_pending'] <=> $a['count_pending']);
            $data['adoption_listings'] = $adoptionListings;
        }

        return $this->layout('admin/addons/adoptions/index.php', 'Adoptions', $data);
    }

    // -----------------------------------------------------------------------
    // POST /admin/adoptions/bulk
    // -----------------------------------------------------------------------

    public function bulk(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db           = $this->app->getDatabase();
        $adoptionRepo = new AdoptionRepo($db);
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $action  = trim((string) $request->post('bulk_action', ''));
        $ids     = array_map('intval', (array) $request->post('ids', []));
        $ids     = array_filter($ids, static fn(int $id): bool => $id > 0);
        $returnUrl = (string) $request->post('_return', $baseUrl . '/admin/adoptions');

        if (empty($ids) || !in_array($action, ['approve', 'reject', 'delete'], true)) {
            $session->set('lstr_flash_error', 'No action or applications selected.');
            return Response::redirect($returnUrl);
        }

        $count = 0;
        foreach ($ids as $id) {
            if ($action === 'delete') {
                $adoptionRepo->delete($id);
            } else {
                $status = $action === 'approve' ? 'approved' : 'rejected';
                $adoptionRepo->setStatus($id, $status);
            }
            $count++;
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id', 0) ?: null,
            'adoption_bulk',
            "Bulk {$action} on {$count} adoption application(s)"
        );

        $session->set('lstr_flash_success', ucfirst($action) . 'd ' . $count . ' application' . ($count !== 1 ? 's' : '') . '.');
        return Response::redirect($returnUrl);
    }

    // -----------------------------------------------------------------------
    // GET /admin/adoptions/{id}/edit
    // POST /admin/adoptions/{id}/edit
    // -----------------------------------------------------------------------

    public function editForm(Request $request, array $params): Response
    {
        $id           = (int) ($params['id'] ?? 0);
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $adoptionRepo = new AdoptionRepo($db);
        $listingRepo  = new ListingRepo($db, $config);

        $application = $adoptionRepo->getById($id);
        if ($application === null) {
            return Response::notFound('Application not found.');
        }

        $listing = $listingRepo->getById((int) $application['fanlisting_id']);

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        // Pending (or legacy 'new') applications open in review mode
        $isReviewMode = in_array($application['status'] ?? 'pending', ['pending', 'new'], true);

        return $this->layout('admin/addons/adoptions/edit.php', $isReviewMode ? 'Review Application' : 'Edit Application', [
            'application'    => $application,
            'listing'        => $listing,
            'error'          => $error,
            'is_review_mode' => $isReviewMode,
        ], [
            $this->crumb('Adoptions', $this->adminUrl('/admin/adoptions')),
            $this->crumb('Edit Application'),
        ]);
    }

    public function editSave(Request $request, array $params): Response
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

        $name       = trim((string) $request->post('name',        ''));
        $email      = trim((string) $request->post('email',       ''));
        $url        = trim((string) $request->post('url',         ''));
        $message    = trim((string) $request->post('message',     ''));
        $adminNotes = trim((string) $request->post('admin_notes', ''));
        $status     = trim((string) $request->post('status', 'pending'));

        if ($name === '' || $email === '') {
            $session->set('lstr_flash_error', 'Name and email are required.');
            return Response::redirect($baseUrl . '/admin/adoptions/' . $id . '/edit');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $session->set('lstr_flash_error', 'Invalid email address.');
            return Response::redirect($baseUrl . '/admin/adoptions/' . $id . '/edit');
        }

        $adoptionRepo->update($id, $name, $email, $url, $message, $status, $adminNotes);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id', 0) ?: null,
            'adoption_edit',
            "Edited adoption application #{$id}"
        );

        $session->set('lstr_flash_success', 'Application updated.');
        return Response::redirect($baseUrl . '/admin/adoptions');
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
            "Set adoption application #{$id} status to '{$status}'"
        );

        $session->set('lstr_flash_success', 'Application status updated.');
        return $this->redirectAfterAction($request, $baseUrl);
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
        return $this->redirectAfterAction($request, $baseUrl);
    }

    // -----------------------------------------------------------------------
    // GET /admin/adoptions/settings
    // POST /admin/adoptions/settings
    // -----------------------------------------------------------------------

    public function settingsForm(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();

        $siteSettings = new SiteSettingsRepo($db);

        $defaultLanding = $siteSettings->get('adoption_default_landing', 'applications') ?? 'applications';
        $perPage        = max(5, min(25, (int) ($siteSettings->get('adoption_per_page', '10') ?? '10')));

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/addons/adoptions/settings.php', 'Adoption Settings', [
            'adoption_default_landing' => $defaultLanding,
            'adoption_per_page'        => $perPage,
            'success'                  => $success,
            'error'                    => $error,
        ], [
            $this->crumb('Adoptions', $this->adminUrl('/admin/adoptions')),
            $this->crumb('Settings'),
        ]);
    }

    public function saveSettings(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $siteSettings = new SiteSettingsRepo($db);

        $landing = $request->post('adoption_default_landing', 'applications');
        $siteSettings->set(
            'adoption_default_landing',
            $landing === 'listings' ? 'listings' : 'applications'
        );

        $siteSettings->set(
            'adoption_per_page',
            (string) max(5, min(25, (int) $request->post('adoption_per_page', 10)))
        );

        $session->set('lstr_flash_success', 'Adoption settings saved.');
        return Response::redirect($baseUrl . '/admin/adoptions/settings');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

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

        if ($status === 'approved' || $status === 'rejected') {
            $this->sendAdoptionStatusEmail($application, $status);
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id', 0) ?: null,
            'adoption_status',
            "Set adoption application #{$id} to '{$status}'"
        );

        $session->set('lstr_flash_success', 'Application ' . $status . '.');
        return $this->redirectAfterAction($request, $baseUrl);
    }

    private function sendAdoptionStatusEmail(array $application, string $status): void
    {
        try {
            $config    = $this->app->getConfig();
            $db        = $this->app->getDatabase();
            $listingId = (int) $application['fanlisting_id'];
            $listing   = (new ListingRepo($db, $config))->getById($listingId);
            $emailRepo = new EmailTemplateRepo($db);

            $type = $status === 'approved'
                ? EmailTemplateRepo::TYPE_ADOPTION_APPROVED
                : EmailTemplateRepo::TYPE_ADOPTION_REJECTED;

            $tokens = EmailTemplateRepo::buildAdoptionTokens(
                listingName      : $listing?->title ?? '',
                listingUrl       : $listing?->url   ?? '',
                siteName         : (string) $config->get('site_name',   ''),
                ownerName        : (string) $config->get('owner_name',  ''),
                ownerEmail       : (string) $config->get('owner_email', ''),
                applicantName    : (string) $application['name'],
                applicantEmail   : (string) $application['email'],
                applicantUrl     : (string) ($application['url']     ?? ''),
                applicantMessage : (string) ($application['message'] ?? ''),
            );

            [$subject, $body] = $emailRepo->resolveForListing($listingId, $type, $tokens);

            $toEmail = (string) $application['email'];
            if ($toEmail === '') {
                return;
            }

            (new Mailer($config))->send($toEmail, (string) $application['name'], $subject, $body);
        } catch (\Throwable) {
            // Email failures are non-fatal
        }
    }

    private function redirectAfterAction(Request $request, string $baseUrl): Response
    {
        $adoptionRepo = new AdoptionRepo($this->app->getDatabase());
        $tab = ($adoptionRepo->queryCounts(0, '')['pending'] ?? 0) > 0 ? 'pending' : 'all';
        return Response::redirect($baseUrl . '/admin/adoptions?tab=' . $tab);
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
