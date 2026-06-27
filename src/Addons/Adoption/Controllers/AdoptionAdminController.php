<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin controller for the Adoption add-on. Provides the tabbed overview of
 all adoption applications, per-listing and per-application detail views,
 bulk actions (approve/reject/delete), individual and bulk email composers,
 and the adoption settings page. All state changes are CSRF-protected and
 logged to the activity log.
*/

declare(strict_types=1);

namespace Luster\Addons\Adoption\Controllers;

use Luster\Addons\Adoption\AdoptionRepo;
use Luster\Core\Application;
use Luster\Core\Config;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\UrlGuard;
use Luster\Models\Listing;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SavedEmailRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Admin management of adoption applications.
 *
 * Routes (registered by AdoptionAddon::registerRoutes()):
 *   GET  /admin/adoptions                         index()       — applications overview
 *   POST /admin/adoptions/bulk                    bulk()        — bulk actions
 *   GET  /admin/adoptions/settings                settingsForm()
 *   POST /admin/adoptions/settings                saveSettings()
 *   GET  /admin/adoptions/email                   bulkEmailForm()
 *   POST /admin/adoptions/email                   bulkEmailSend()
 *   GET  /admin/adoptions/{id}/email              emailForm()
 *   POST /admin/adoptions/{id}/email              emailSend()
 *   GET  /admin/adoptions/{id}/edit               editForm()
 *   POST /admin/adoptions/{id}/edit               editSave()
 *   POST /admin/adoptions/{id}/approve            approve()
 *   POST /admin/adoptions/{id}/reject             reject()
 *   POST /admin/adoptions/{id}/status             setStatus()
 *   POST /admin/adoptions/{id}/delete             delete()
 */
final class AdoptionAdminController
{
    private const APP_STATUS_TABS = ['all', 'pending', 'approved', 'rejected', 'granted'];
    private const VALID_STATUSES  = ['pending', 'approved', 'rejected', 'granted'];

    public function __construct(private readonly Application $app) {}

    /** Returns $candidate if it is same-origin, otherwise $fallback. */
    private function safeReturn(string $candidate, string $fallback): string
    {
        return ($candidate !== '' && UrlGuard::isSameOriginUrl($candidate, $this->app->getBaseUrl()))
            ? $candidate
            : $fallback;
    }

    // -----------------------------------------------------------------------
    // GET /admin/adoptions
    // -----------------------------------------------------------------------

    /**
     * Overview of all adoption applications, with tab/listing/search filters.
     *
     * Bare navigation (no query params) auto-redirects to the pending tab when
     * pending applications exist, or to the configured default landing page.
     */
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
                $lc = $listingCounts[$fl->id] ?? ['all' => 0, 'pending' => 0, 'approved' => 0, 'granted' => 0];
                $adoptionListings[] = [
                    'listing'        => $fl,
                    'count_pending'  => $lc['pending'],
                    'count_approved' => $lc['approved'],
                    'count_granted'  => $lc['granted'],
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

    /**
     * Handles bulk approve, reject, delete, and email actions on selected applications.
     *
     * The email action redirects to bulkEmailForm instead of processing inline.
     */
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
        $returnUrl = $this->safeReturn(
            (string) $request->post('_return', ''),
            $baseUrl . '/admin/adoptions',
        );

        if (empty($ids) || !in_array($action, ['approve', 'reject', 'delete', 'email'], true)) {
            $session->set('lstr_flash_error', 'No action or applications selected.');
            return Response::redirect($returnUrl);
        }

        if ($action === 'email') {
            $qs = http_build_query(['ids' => array_values($ids), '_return' => $returnUrl]);
            return Response::redirect($baseUrl . '/admin/adoptions/email?' . $qs);
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
            "Bulk {$action} on {$count} adoption application(s)",
            $request->ip()
        );

        $session->set('lstr_flash_success', ucfirst($action) . 'd ' . $count . ' application' . ($count !== 1 ? 's' : '') . '.');
        return Response::redirect($returnUrl);
    }

    // -----------------------------------------------------------------------
    // GET /admin/adoptions/{id}/edit
    // POST /admin/adoptions/{id}/edit
    // -----------------------------------------------------------------------

    /**
     * Renders the edit/review form for one adoption application.
     *
     * Pending (and legacy 'new') applications open in review mode which
     * prominently shows the Approve/Reject buttons.
     */
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
        $success = (string) $session->get('lstr_flash_success', '');
        $session->remove('lstr_flash_success');

        // Pending (or legacy 'new') applications open in review mode
        $isReviewMode = in_array($application['status'] ?? 'pending', ['pending', 'new'], true);

        return $this->layout('admin/addons/adoptions/edit.php', $isReviewMode ? 'Review Application' : 'Edit Application', [
            'application'    => $application,
            'listing'        => $listing,
            'error'          => $error,
            'success'        => $success,
            'is_review_mode' => $isReviewMode,
        ], [
            $this->crumb('Adoptions', $this->adminUrl('/admin/adoptions')),
            $this->crumb('Edit Application'),
        ]);
    }

    /** Validates and saves changes to an adoption application's fields and status. */
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
            "Edited adoption application #{$id}",
            $request->ip()
        );

        $session->set('lstr_flash_success', 'Application updated.');
        return Response::redirect($baseUrl . '/admin/adoptions');
    }

    // -----------------------------------------------------------------------
    // POST /admin/adoptions/{id}/approve
    // -----------------------------------------------------------------------

    /** Sets the application status to 'approved' and sends a status email. */
    public function approve(Request $request, array $params): Response
    {
        return $this->quickStatus($request, $params, 'approved');
    }

    // -----------------------------------------------------------------------
    // POST /admin/adoptions/{id}/reject
    // -----------------------------------------------------------------------

    /** Sets the application status to 'rejected' and sends a status email. */
    public function reject(Request $request, array $params): Response
    {
        return $this->quickStatus($request, $params, 'rejected');
    }

    // -----------------------------------------------------------------------
    // POST /admin/adoptions/{id}/status
    // -----------------------------------------------------------------------

    /** Sets the application status to any valid value without sending a status email. */
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
            "Set adoption application #{$id} status to '{$status}'",
            $request->ip()
        );

        $session->set('lstr_flash_success', 'Application status updated.');
        return $this->redirectAfterAction($request, $baseUrl);
    }

    // -----------------------------------------------------------------------
    // POST /admin/adoptions/{id}/delete
    // -----------------------------------------------------------------------

    /** Permanently deletes an adoption application and logs the action. */
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
            "Deleted adoption application #{$id} for listing #{$listingId}",
            $request->ip()
        );

        $session->set('lstr_flash_success', 'Application deleted.');
        return $this->redirectAfterAction($request, $baseUrl);
    }

    // -----------------------------------------------------------------------
    // GET /admin/adoptions/settings
    // POST /admin/adoptions/settings
    // -----------------------------------------------------------------------

    /** Renders the Adoption settings page (default landing, per-page count). */
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

    /** Persists the Adoption settings and redirects back to the settings page. */
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
    // GET /admin/adoptions/{id}/email
    // POST /admin/adoptions/{id}/email
    // -----------------------------------------------------------------------

    /** Renders the single-applicant email composer for one application. */
    public function emailForm(Request $request, array $params): Response
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

        $listing     = $listingRepo->getById((int) $application['fanlisting_id']);
        $savedEmails = (new SavedEmailRepo($db))->getAll();

        $session = $this->app->getSession();
        $old     = (array) $session->get('lstr_flash_old', []);
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_old');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/addons/adoptions/email.php', 'Email Applicant', [
            'application'  => $application,
            'listing'      => $listing,
            'from_name'    => $this->resolveFromName($config),
            'from_email'   => $this->resolveFromEmail($config),
            'owner_name'   => (string) $config->get('owner_name',  ''),
            'owner_email'  => (string) $config->get('owner_email', ''),
            'site_name'    => (string) $config->get('site_name',   ''),
            'saved_emails' => $savedEmails,
            'old'          => ['subject' => (string) ($old['subject'] ?? ''), 'body' => (string) ($old['body'] ?? '')],
            'error'        => $error,
        ], [
            $this->crumb('Adoptions', $this->adminUrl('/admin/adoptions')),
            $this->crumb('Email Applicant'),
        ]);
    }

    /**
     * Sends the composed email to a single applicant.
     *
     * Applies {app_*} and {listing_*} token substitution before sending, and
     * logs the action to the activity log.
     */
    public function emailSend(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $id           = (int) ($params['id'] ?? 0);
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $adoptionRepo = new AdoptionRepo($db);

        $application = $adoptionRepo->getById($id);
        if ($application === null) {
            return Response::notFound('Application not found.');
        }

        $subject = trim((string) $request->post('subject', ''));
        $body    = trim((string) $request->post('body',    ''));

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Subject and message are required.');
            $session->set('lstr_flash_old', ['subject' => $subject, 'body' => $body]);
            return Response::redirect($baseUrl . '/admin/adoptions/' . $id . '/email');
        }

        $listing = (new ListingRepo($db, $config))->getById((int) $application['fanlisting_id']);
        $subject = $this->applyAdoptionTokens($subject, $application, $listing, $config);
        $body    = $this->applyAdoptionTokens($body,    $application, $listing, $config);

        try {
            (new Mailer($config))->send(
                (string) $application['email'],
                (string) $application['name'],
                $subject,
                $body
            );
        } catch (\Throwable $e) {
            $session->set('lstr_flash_error', 'Failed to send email: ' . $e->getMessage());
            $session->set('lstr_flash_old', ['subject' => (string) $request->post('subject', ''), 'body' => (string) $request->post('body', '')]);
            return Response::redirect($baseUrl . '/admin/adoptions/' . $id . '/email');
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id', 0) ?: null,
            'adoption_email',
            "Emailed adoption applicant #{$id} (" . (string) $application['email'] . ')',
            $request->ip()
        );

        $session->set('lstr_flash_success', 'Email sent to ' . (string) $application['name'] . '.');
        return Response::redirect($baseUrl . '/admin/adoptions/' . $id . '/edit');
    }

    // -----------------------------------------------------------------------
    // GET /admin/adoptions/email
    // POST /admin/adoptions/email
    // -----------------------------------------------------------------------

    /**
     * Renders the bulk email composer for multiple applicants.
     *
     * Supports two modes: explicit application IDs from the bulk-action
     * selector, or a listing_id + statuses pair (used by emailAllForListing
     * to avoid hitting URL-length limits).
     */
    public function bulkEmailForm(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $returnUrl = $this->safeReturn(
            (string) $request->query('_return', ''),
            $this->app->getBaseUrl() . '/admin/adoptions',
        );

        $adoptionRepo = new AdoptionRepo($db);
        $listingRepo  = new ListingRepo($db, $config);
        $session      = $this->app->getSession();

        $applications = [];

        // Mode A: explicit IDs (from bulk action selector)
        $rawIds = array_filter(
            array_map('intval', (array) $request->query('ids', [])),
            static fn(int $id): bool => $id > 0
        );
        if (!empty($rawIds)) {
            foreach (array_unique($rawIds) as $id) {
                $app = $adoptionRepo->getById($id);
                if ($app !== null) {
                    $applications[] = $app;
                }
            }
        }

        // Mode B: listing_id + statuses (from emailAllForListing — avoids URL length limits)
        $directListingId    = (int) $request->query('listing_id', 0);
        $sourceModeStatuses = '';
        if (empty($applications) && $directListingId > 0) {
            $rawStatuses     = explode(',', (string) $request->query('statuses', 'approved,granted'));
            $allowedStatuses = ['pending', 'approved', 'rejected', 'granted'];
            $statuses        = array_values(array_filter(
                array_map('trim', $rawStatuses),
                static fn(string $s): bool => in_array($s, $allowedStatuses, true)
            ));
            if (empty($statuses)) {
                $statuses = ['approved', 'granted'];
            }
            $sourceModeStatuses = implode(',', $statuses);
            foreach ($statuses as $st) {
                $rows = $adoptionRepo->query($st, $directListingId);
                foreach ($rows as $row) {
                    $applications[] = $row;
                }
            }
        }

        if (empty($applications)) {
            $session->set('lstr_flash_error', 'No valid applications selected.');
            return Response::redirect($returnUrl);
        }

        // Build applicant name map (email => name) and preload listings
        $applicantNames = [];
        $listingIds     = [];
        foreach ($applications as $app) {
            $applicantNames[(string) $app['email']] = (string) $app['name'];
            $listingIds[] = (int) $app['fanlisting_id'];
        }
        $listingMap = [];
        foreach (array_unique($listingIds) as $lid) {
            $fl = $listingRepo->getById($lid);
            if ($fl !== null) {
                $listingMap[$lid] = $fl;
            }
        }

        $savedEmails = (new SavedEmailRepo($db))->getAll();
        $old         = (array) $session->get('lstr_flash_old', []);
        $error       = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_old');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/addons/adoptions/email-selected.php', 'Email Applicants', [
            'applications'   => $applications,
            'applicant_names'=> $applicantNames,
            'listing_map'    => $listingMap,
            'recipient_count'=> count($applications),
            'from_name'      => $this->resolveFromName($config),
            'from_email'     => $this->resolveFromEmail($config),
            'owner_name'     => (string) $config->get('owner_name',  ''),
            'owner_email'    => (string) $config->get('owner_email', ''),
            'site_name'      => (string) $config->get('site_name',   ''),
            'saved_emails'        => $savedEmails,
            'return_url'          => $returnUrl,
            'old'                 => ['subject' => (string) ($old['subject'] ?? ''), 'body' => (string) ($old['body'] ?? '')],
            'error'               => $error,
            'source_listing_id'   => $directListingId,
            'source_statuses'     => $sourceModeStatuses,
        ], [
            $this->crumb('Adoptions', $this->adminUrl('/admin/adoptions')),
            $this->crumb('Email Applicants'),
        ]);
    }

    /**
     * Sends the bulk email to each selected applicant with per-recipient token substitution.
     *
     * Deduplicates by email address (case-insensitive) so applicants with
     * multiple entries receive only one message.
     */
    public function bulkEmailSend(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $returnUrl = $this->safeReturn(
            trim((string) $request->post('_return', '')),
            $baseUrl . '/admin/adoptions',
        );

        $ids = array_filter(
            array_map('intval', (array) $request->post('ids', [])),
            static fn(int $id): bool => $id > 0
        );

        $subject = trim((string) $request->post('subject', ''));
        $body    = trim((string) $request->post('body',    ''));

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Subject and message are required.');
            $session->set('lstr_flash_old', ['subject' => $subject, 'body' => $body]);
            // Prefer Mode B redirect (listing_id + statuses) to avoid URL length limits.
            $sourceLid      = (int) $request->post('source_listing_id', 0);
            $sourceStatuses = trim((string) $request->post('source_statuses', ''));
            if ($sourceLid > 0 && $sourceStatuses !== '') {
                $qs = http_build_query(['listing_id' => $sourceLid, 'statuses' => $sourceStatuses, '_return' => $returnUrl]);
            } else {
                $qs = http_build_query(['ids' => array_values($ids), '_return' => $returnUrl]);
            }
            return Response::redirect($baseUrl . '/admin/adoptions/email?' . $qs);
        }

        if (empty($ids)) {
            $session->set('lstr_flash_error', 'No recipients selected.');
            return Response::redirect($returnUrl);
        }

        $adoptionRepo = new AdoptionRepo($db);
        $listingRepo  = new ListingRepo($db, $config);
        $mailer       = new Mailer($config);

        $sent   = 0;
        $failed = 0;
        $seen   = [];

        foreach (array_unique($ids) as $id) {
            $app = $adoptionRepo->getById($id);
            if ($app === null) {
                continue;
            }
            $email = strtolower(trim((string) $app['email']));
            if ($email === '' || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;

            $listing = $listingRepo->getById((int) $app['fanlisting_id']);
            $sub     = $this->applyAdoptionTokens($subject, $app, $listing, $config);
            $msg     = $this->applyAdoptionTokens($body,    $app, $listing, $config);

            try {
                $mailer->send($email, (string) $app['name'], $sub, $msg);
                $sent++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id', 0) ?: null,
            'adoption_bulk_email',
            "Bulk emailed {$sent} adoption applicant(s)" . ($failed > 0 ? " ({$failed} failed)" : ''),
            $request->ip()
        );

        $msg = 'Email sent to ' . $sent . ' applicant' . ($sent !== 1 ? 's' : '') . '.';
        if ($failed > 0) {
            $msg .= ' ' . $failed . ' failed to send.';
        }
        $session->set('lstr_flash_success', $msg);
        return Response::redirect($returnUrl);
    }

    // -----------------------------------------------------------------------
    // GET /admin/adoptions/{lid}/email-all
    // -----------------------------------------------------------------------

    /**
     * Redirects to the bulk email composer pre-scoped to all approved and
     * granted applicants for a specific listing.
     *
     * Uses the listing_id + statuses URL pattern rather than serialising every
     * application ID, which would exceed URL length limits for large lists.
     */
    public function emailAllForListing(Request $request, array $params): Response
    {
        $lid          = (int) ($params['lid'] ?? 0);
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $adoptionRepo = new AdoptionRepo($db);
        $listingRepo  = new ListingRepo($db, $config);

        $listing = $listingRepo->getById($lid);
        if ($listing === null) {
            $session->set('lstr_flash_error', 'Listing not found.');
            return Response::redirect($baseUrl . '/admin/adoptions?tab=listings');
        }

        $approved = $adoptionRepo->query('approved', $lid);
        $granted  = $adoptionRepo->query('granted',  $lid);
        $all      = array_merge($approved, $granted);

        if (empty($all)) {
            $session->set('lstr_flash_error', 'No approved or granted applicants for this listing.');
            return Response::redirect($baseUrl . '/admin/adoptions?tab=listings');
        }

        // Redirect with listing_id + statuses instead of serialising every ID into the URL,
        // which can hit browser/server URL-length limits for large adoption lists.
        $qs = http_build_query([
            'listing_id' => $lid,
            'statuses'   => 'approved,granted',
            '_return'    => $baseUrl . '/admin/adoptions?tab=listings',
        ]);
        return Response::redirect($baseUrl . '/admin/adoptions/email?' . $qs);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Approves or rejects one application by setting its status and, for
     * approved/rejected, sending the corresponding status email to the applicant.
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

        if ($status === 'approved' || $status === 'rejected') {
            $this->sendAdoptionStatusEmail($application, $status);
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id', 0) ?: null,
            'adoption_status',
            "Set adoption application #{$id} to '{$status}'",
            $request->ip()
        );

        $session->set('lstr_flash_success', 'Application ' . $status . '.');
        return $this->redirectAfterAction($request, $baseUrl);
    }

    /**
     * Sends an adoption approved/rejected status email to the applicant.
     *
     * Uses the per-listing email template if one exists, otherwise falls back
     * to the global default. Email failures are non-fatal and silently swallowed.
     */
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

    /**
     * Redirects to the _return POST value if same-origin, or to the pending/all
     * tab of the adoptions index based on whether pending applications remain.
     */
    private function redirectAfterAction(Request $request, string $baseUrl): Response
    {
        $adoptionRepo = new AdoptionRepo($this->app->getDatabase());
        $hasPending   = ($adoptionRepo->queryCounts(0, '')['pending'] ?? 0) > 0;

        $returnUrl = trim((string) $request->post('_return', ''));
        if ($returnUrl !== '' && UrlGuard::isSameOriginUrl($returnUrl, $baseUrl)) {
            parse_str(parse_url($returnUrl, PHP_URL_QUERY) ?? '', $params);
            if (($params['tab'] ?? '') === 'pending' && !$hasPending) {
                $params['tab'] = 'all';
                unset($params['page']);
                $qs = http_build_query(array_filter($params, static fn($v) => $v !== '' && $v !== null));
                return Response::redirect($baseUrl . '/admin/adoptions' . ($qs !== '' ? '?' . $qs : ''));
            }
            return Response::redirect($returnUrl);
        }

        $tab = $hasPending ? 'pending' : 'all';
        return Response::redirect($baseUrl . '/admin/adoptions?tab=' . $tab);
    }

    /** Renders a page within the admin layout with current_section='adoptions'. */
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

    /** Returns the full admin URL for the given path. */
    private function adminUrl(string $path): string
    {
        return $this->app->getBaseUrl() . $path;
    }

    /** @return array{label: string, url: string} */
    private function crumb(string $label, string $url = ''): array
    {
        return ['label' => $label, 'url' => $url];
    }

    /**
     * Replaces {app_*}, {listing_*}, and {site_*} tokens in email subject/body text.
     *
     * Used for both single-applicant and bulk email sends.
     */
    private function applyAdoptionTokens(string $text, array $app, ?Listing $listing, Config $config): string
    {
        return str_replace(
            ['{app_name}', '{app_email}', '{app_url}', '{app_message}', '{app_status}',
             '{listing_title}', '{listing_url}',
             '{site_name}', '{owner_name}', '{owner_email}'],
            [
                (string) ($app['name']    ?? ''),
                (string) ($app['email']   ?? ''),
                (string) ($app['url']     ?? ''),
                (string) ($app['message'] ?? ''),
                (string) ($app['status']  ?? ''),
                $listing?->title ?? '',
                $listing?->url   ?? '',
                (string) $config->get('site_name',   ''),
                (string) $config->get('owner_name',  ''),
                (string) $config->get('owner_email', ''),
            ],
            $text
        );
    }

    /** Returns the sender display name: smtp_from_name falling back to owner_name. */
    private function resolveFromName(Config $config): string
    {
        $name = (string) $config->get('smtp_from_name', '');
        return $name !== '' ? $name : (string) $config->get('owner_name', '');
    }

    /** Returns the sender email address: smtp_from falling back to owner_email. */
    private function resolveFromEmail(Config $config): string
    {
        $email = (string) $config->get('smtp_from', '');
        return $email !== '' ? $email : (string) $config->get('owner_email', '');
    }
}
