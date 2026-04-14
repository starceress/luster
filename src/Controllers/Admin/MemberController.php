<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;

/**
 * Manages members for a specific listing.
 *
 * Routes:
 *   GET  /admin/members/{id}             — list with search + status filter
 *   GET  /admin/members/{id}/edit        — edit form (?email=...)
 *   POST /admin/members/{id}/edit        — save edits
 *   POST /admin/members/{id}/approve     — approve one member
 *   POST /admin/members/{id}/reject      — reject one member
 *   POST /admin/members/{id}/delete      — delete one member
 *   POST /admin/members/{id}/bulk                 — bulk approve / reject / delete / email_selected
 *   POST /admin/members/{id}/approve-all-pending  — approve every pending member in one click
 *   GET  /admin/members/{id}/email-selected       — compose form for emailing selected members
 *   POST /admin/members/{id}/email-selected/send  — send the composed email to selected members
 *   GET  /admin/members/{id}/export      — CSV download of approved members
 */
final class MemberController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/members
    // -----------------------------------------------------------------------

    public function overview(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo       = new ListingRepo($db, $config);
        $memberRepo        = new MemberRepo($db, $config);
        $pendingUpdateRepo = new PendingUpdateRepo($db);
        $allListings       = $listingRepo->getAll();

        // Build listing-id → Listing map for use in both render paths.
        $flById = [];
        foreach ($allListings as $fl) {
            $flById[(int) $fl->id] = $fl;
        }

        $pendingUpdates = $pendingUpdateRepo->getAll();

        $search = trim((string) $request->query('search', ''));
        $sort   = trim((string) $request->query('sort', 'name'));
        $dir    = trim((string) $request->query('dir', 'asc'));
        if (!in_array($sort, ['name', 'email', 'country'], true)) { $sort = 'name'; }
        if (!in_array($dir, ['asc', 'desc'], true)) { $dir = 'asc'; }

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        // ------------------------------------------------------------------
        // Global search — return results across all listings
        // ------------------------------------------------------------------
        if ($search !== '') {
            $searchResults = []; // [{listing, member}]

            foreach ($allListings as $fl) {
                if ($fl->dbprefix === '') {
                    continue;
                }
                foreach ($memberRepo->search($fl->dbprefix, $search) as $member) {
                    $searchResults[] = ['listing' => $fl, 'member' => $member];
                }
            }

            // Sort by listing name, then member name
            usort($searchResults, fn($a, $b) =>
                strcmp($a['listing']->name, $b['listing']->name) ?:
                strcmp($a['member']->name,  $b['member']->name)
            );

            return $this->adminLayout('admin/members/overview.php', 'Members', 'members', [
                'grouped'         => [],
                'all_listings'    => $allListings,
                'search_results'  => $searchResults,
                'pending_updates' => $pendingUpdates,
                'fl_by_id'        => $flById,
                'success'         => $success,
                'error'           => $error,
                'search'          => $search,
            ]);
        }

        // ------------------------------------------------------------------
        // Default view — pending members grouped by listing
        // ------------------------------------------------------------------
        $grouped = [];
        foreach ($allListings as $fl) {
            if ($fl->dbprefix === '') {
                continue;
            }
            $pending = $memberRepo->getPending($fl->dbprefix, $sort, $dir);
            if (count($pending) > 0) {
                $grouped[] = ['listing' => $fl, 'members' => $pending];
            }
        }

        return $this->adminLayout('admin/members/overview.php', 'Members', 'members', [
            'grouped'         => $grouped,
            'all_listings'    => $allListings,
            'search_results'  => [],
            'pending_updates' => $pendingUpdates,
            'fl_by_id'        => $flById,
            'sort'            => $sort,
            'dir'             => $dir,
            'success'         => $success,
            'error'           => $error,
            'search'          => $search,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/members/{id}
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $fieldRepo      = new FieldRepo($db, $config);
        $settingsRepo   = new ListingSettingsRepo($db);

        $listing = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $page    = max(1, (int) $request->query('page', 1));
        $perPage = max(5, min(200, (int) $config->get('per_page', 25)));
        $search  = trim((string) $request->query('search', ''));
        $status  = trim((string) $request->query('status', 'all'));
        $sort    = trim((string) $request->query('sort', 'name'));
        $dir     = trim((string) $request->query('dir', 'asc'));

        if (!in_array($status, ['approved', 'pending', 'all'], true)) {
            $status = 'all';
        }
        if (!in_array($sort, ['name', 'email', 'country', 'status'], true)) {
            $sort = 'name';
        }
        if (!in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'asc';
        }

        $allListings = (new ListingRepo($db, $config))->getAll();

        $fields = $fieldRepo->getFieldsForListing($listingId);

        if ($search !== '') {
            // Search ignores status filter; sort applied in PHP
            $allMatches = $memberRepo->search($listing->dbprefix, $search);
            usort($allMatches, static function ($a, $b) use ($sort, $dir): int {
                $cmp = match ($sort) {
                    'email'   => strcasecmp($a->email,   $b->email),
                    'country' => strcasecmp($a->country,  $b->country) ?: strcasecmp($a->name, $b->name),
                    'status'  => ($a->approved <=> $b->approved) ?: strcasecmp($a->name, $b->name),
                    default   => strcasecmp($a->name,    $b->name),
                };
                return $dir === 'desc' ? -$cmp : $cmp;
            });
            $total   = count($allMatches);
            $pages   = $total > 0 ? (int) ceil($total / $perPage) : 0;
            $members = array_slice($allMatches, ($page - 1) * $perPage, $perPage);
        } else {
            $result  = $memberRepo->paginateByStatus($listing->dbprefix, $status, $page, $perPage, $sort, $dir);
            $members = $result['members'];
            $total   = $result['total'];
            $pages   = $result['pages'];
        }

        $pendingCount = $listing->dbprefix !== '' ? $memberRepo->countPending($listing->dbprefix) : 0;

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/members/index.php', 'Members', 'members', [
            'listing'         => $listing,
            'members'         => $members,
            'fields'          => $fields,
            'total'           => $total,
            'page'            => $page,
            'pages'           => $pages,
            'per_page'        => $perPage,
            'search'          => $search,
            'status'          => $status,
            'all_listings'    => $allListings,
            'pending_count'   => $pendingCount,
            'sort'            => $sort,
            'dir'             => $dir,
            'success'         => $success,
            'error'           => $error,
        ], 'Owned › ' . $listing->name);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/members/{id}/edit  (?email=addr@example.com)
    // -----------------------------------------------------------------------

    public function edit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $fieldRepo      = new FieldRepo($db, $config);
        $activityRepo   = new ActivityRepo($db);

        $listing     = $listingRepo->getById($listingId);
        $allListings = $listingRepo->getAll();

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();
        $fields  = $fieldRepo->getFieldsForListing($listingId);

        // ---- POST: save ----
        if ($request->isPost()) {
            $email = trim((string) $request->post('member_email', ''));

            if ($email === '') {
                return Response::notFound('Member email not provided.');
            }

            $member = $memberRepo->getByEmail($listing->dbprefix, $email);

            if ($member === null) {
                return Response::notFound('Member not found.');
            }

            $name    = trim((string) $request->post('name', ''));
            $url     = trim((string) $request->post('url', ''));
            $country = trim((string) $request->post('country', ''));
            $showurl = $request->post('showurl') === '1';

            $v = new Validator(['name' => $name, 'url' => $url]);
            $v->field('name')->required()->maxLength(255);
            if ($url !== '') {
                $v->field('url')->url()->maxLength(500);
            }

            if ($v->fails()) {
                return $this->adminLayout('admin/members/edit.php', 'Edit Member', 'members', [
                    'listing'      => $listing,
                    'member'          => $member,
                    'fields'          => $fields,
                    'error'           => implode(' ', $v->allErrors()),
                    'field_errors'    => $v->errors(),
                    'success'         => '',
                    'old'             => $request->postAll(),
                    'all_listings' => $allListings,
                ], 'Owned › ' . $listing->name);
            }

            $updateData = [
                'name'    => $name,
                'url'     => $url,
                'country' => $country,
                'showurl' => (int) $showurl,
            ];

            foreach ($fields as $field) {
                $col   = $field->name; // bare name for unprefixed schema
                $value = trim((string) $request->post('field_' . $field->name, ''));
                $updateData[$col] = $value;
            }

            $memberRepo->update($listing->dbprefix, $email, $updateData);
            (new ListingSettingsRepo($db))->touchLastUpdated($listingId);

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'member.edit',
                "Edited member {$email} in listing #{$listingId}",
                $request->ip(),
            );

            $session->set('lstr_flash_success', 'Member updated.');
            return Response::redirect($baseUrl . '/admin/members/' . $listingId);
        }

        // ---- GET: show form ----
        $email = trim((string) $request->query('email', ''));

        if ($email === '') {
            return Response::notFound('Member email not specified.');
        }

        $member = $memberRepo->getByEmail($listing->dbprefix, $email);

        if ($member === null) {
            return Response::notFound('Member not found.');
        }

        $flashSuccess = (string) $session->get('lstr_flash_success', '');
        $flashError   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/members/edit.php', 'Edit Member', 'members', [
            'listing'      => $listing,
            'member'          => $member,
            'fields'          => $fields,
            'error'           => $flashError,
            'success'         => $flashSuccess,
            'old'             => [],
            'all_listings' => $allListings,
        ], 'Owned › ' . $listing->name);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/approve
    // -----------------------------------------------------------------------

    public function approve(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $email        = trim((string) $request->post('member_email', ''));

        return $this->singleAction($listingId, $email, 'approve', $request);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/reject
    // -----------------------------------------------------------------------

    public function reject(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $email        = trim((string) $request->post('member_email', ''));

        return $this->singleAction($listingId, $email, 'reject', $request);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/delete
    // -----------------------------------------------------------------------

    public function deleteMember(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $email        = trim((string) $request->post('member_email', ''));

        return $this->singleAction($listingId, $email, 'delete', $request);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/merge
    //
    // Merges two members by deleting the secondary (duplicate) member.
    // The primary member (currently being edited) is kept unchanged.
    // Both must exist in the same listing.
    // -----------------------------------------------------------------------

    public function merge(Request $request, array $params): Response
    {
        $listingId    = (int) ($params['id'] ?? 0);
        $primaryEmail = trim((string) $request->post('member_email',   ''));
        $dupeEmail    = trim((string) $request->post('duplicate_email', ''));

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo  = new MemberRepo($db, $config);
        $activityRepo = new ActivityRepo($db);

        $listing = $listingRepo->getById($listingId);
        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $redirectEdit = $baseUrl . '/admin/members/' . $listingId
            . '/edit?email=' . urlencode($primaryEmail);

        if ($primaryEmail === '' || $dupeEmail === '') {
            $session->set('lstr_flash_error', 'Both emails are required for a merge.');
            return Response::redirect($redirectEdit);
        }

        if (strtolower($primaryEmail) === strtolower($dupeEmail)) {
            $session->set('lstr_flash_error', 'The primary and duplicate email must be different.');
            return Response::redirect($redirectEdit);
        }

        // Verify both members exist
        $primary = $memberRepo->getByEmail($listing->dbprefix, $primaryEmail);
        if ($primary === null) {
            $session->set('lstr_flash_error', 'Primary member not found.');
            return Response::redirect($redirectEdit);
        }

        $duplicate = $memberRepo->getByEmail($listing->dbprefix, $dupeEmail);
        if ($duplicate === null) {
            $session->set('lstr_flash_error', 'Duplicate member "' . $dupeEmail . '" not found in this listing.');
            return Response::redirect($redirectEdit);
        }

        // Delete the duplicate
        $memberRepo->deleteByEmail($listing->dbprefix, $dupeEmail);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'member.merge',
            'Merged duplicate member ' . $dupeEmail . ' into ' . $primaryEmail
                . ' in listing #' . $listingId . ' (' . $listing->name . ')',
            $request->ip(),
        );

        $session->set(
            'lstr_flash_success',
            'Duplicate member "' . $dupeEmail . '" removed. "' . $primaryEmail . '" retained.',
        );
        return Response::redirect($baseUrl . '/admin/members/' . $listingId
            . '/edit?email=' . urlencode($primaryEmail));
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/bulk
    // -----------------------------------------------------------------------

    public function bulk(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $activityRepo   = new ActivityRepo($db);

        $listing = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action = (string) $request->post('bulk_action', '');
        $emails = (array) $request->post('emails', []);
        $emails = array_filter(array_map('trim', $emails));

        if (empty($emails) || !in_array($action, ['approve', 'pending', 'reject', 'delete', 'email_selected'], true)) {
            $session->set('lstr_flash_error', 'No members selected or invalid action.');
            return Response::redirect($baseUrl . '/admin/members/' . $listingId);
        }

        if ($action === 'email_selected') {
            $session->set('lstr_selected_emails', array_values($emails));
            return Response::redirect($baseUrl . '/admin/members/' . $listingId . '/email-selected');
        }

        $mailer   = new Mailer($this->app->getConfig());
        $siteName = (string) $config->get('site_name', 'Luster');

        $count = 0;
        foreach ($emails as $email) {
            if ($email === '') {
                continue;
            }

            match ($action) {
                'approve' => $memberRepo->approve($listing->dbprefix, $email),
                'pending' => $memberRepo->setPending($listing->dbprefix, $email),
                'reject'  => $memberRepo->reject($listing->dbprefix, $email),
                'delete'  => $memberRepo->deleteByEmail($listing->dbprefix, $email),
            };

            // Approval email — mirrors the single-action path (best-effort)
            if ($action === 'approve') {
                $member = $memberRepo->getByEmail($listing->dbprefix, $email);
                if ($member !== null) {
                    try {
                        $cfg       = $this->app->getConfig();
                        $emailRepo = new EmailTemplateRepo($db);
                        $tokens    = EmailTemplateRepo::buildTokens(
                            listingName:    $listing->name,
                            listingUrl:     $listing->url,
                            siteName:       (string) $cfg->get('site_name', 'Luster'),
                            listingSubject: $listing->subject,
                            ownerName:      (string) $cfg->get('owner_name', ''),
                            ownerEmail:     (string) $cfg->get('owner_email', ''),
                            fanName:        $member->name,
                            fanEmail:       $member->email,
                        );
                        [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_APPROVE, $tokens);
                        $mailer->send($member->email, $member->name, $subject, $body);
                    } catch (\Throwable $e) {
                        error_log('[Luster] Bulk approve email failed for ' . $email . ': ' . $e->getMessage());
                    }
                }
            }

            $count++;
        }

        // Touch last_updated when at least one member was approved
        if ($count > 0 && $action === 'approve') {
            (new ListingSettingsRepo($db))->touchLastUpdated($listingId);
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            "member.bulk_{$action}",
            "Bulk {$action}: {$count} member(s) in listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Bulk {$action}: {$count} member(s) processed.");
        $from = (string) $request->post('from', '');
        return Response::redirect($baseUrl . '/admin/members/' . ($from === 'overview' ? '' : $listingId));
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/approve-all-pending
    // -----------------------------------------------------------------------

    public function approveAllPending(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo  = new ListingRepo($db, $config);
        $memberRepo   = new MemberRepo($db, $config);
        $activityRepo = new ActivityRepo($db);

        $listing = $listingRepo->getById($listingId);

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $pending = $memberRepo->getPending($listing->dbprefix);

        if (empty($pending)) {
            $session->set('lstr_flash_error', 'No pending members to approve.');
            return Response::redirect($baseUrl . '/admin/members/' . $listingId);
        }

        $mailer = new Mailer($config);
        $count  = 0;

        foreach ($pending as $member) {
            $memberRepo->approve($listing->dbprefix, $member->email);

            try {
                $cfg       = $this->app->getConfig();
                $emailRepo = new EmailTemplateRepo($db);
                $tokens    = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->name,
                    listingUrl:     $listing->url,
                    siteName:       (string) $cfg->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $cfg->get('owner_name', ''),
                    ownerEmail:     (string) $cfg->get('owner_email', ''),
                    fanName:        $member->name,
                    fanEmail:       $member->email,
                );
                [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_APPROVE, $tokens);
                $mailer->send($member->email, $member->name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[Luster] Approve-all email failed for ' . $member->email . ': ' . $e->getMessage());
            }

            $count++;
        }

        (new ListingSettingsRepo($db))->touchLastUpdated($listingId);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'member.approve_all_pending',
            "Approved all {$count} pending member(s) in listing #{$listingId} ({$listing->name})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Approved {$count} pending member" . ($count !== 1 ? 's' : '') . '.');
        return Response::redirect($baseUrl . '/admin/members/' . $listingId);
    }

    // -----------------------------------------------------------------------
    // GET  /admin/members/{id}/import       — show import form
    // POST /admin/members/{id}/import       — process uploaded CSV
    // GET  /admin/members/{id}/import/template — download blank CSV template
    // -----------------------------------------------------------------------

    public function importForm(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $listing = (new ListingRepo($this->app->getDatabase(), $this->app->getConfig()))
            ->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        return $this->adminLayout('admin/members/import.php', 'Import Members', 'members', [
            'listing' => $listing,
            'results' => null,
            'error'   => '',
        ], 'Owned › ' . $listing->name);
    }

    public function importTemplate(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listing = (new ListingRepo($db, $config))->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $fields = (new FieldRepo($db, $config))->getFieldsForListing($listingId);

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return Response::serverError('Could not create CSV stream.');
        }

        $header = ['name', 'email', 'url', 'country', 'password'];
        foreach ($fields as $field) {
            $header[] = $field->name;
        }
        fputcsv($handle, $header);

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($listing->name));
        return Response::csv($csv, "import_template_{$slug}.csv");
    }

    public function import(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo  = new ListingRepo($db, $config);
        $memberRepo   = new MemberRepo($db, $config);
        $fieldRepo    = new FieldRepo($db, $config);
        $activityRepo = new ActivityRepo($db);
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $listing = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $fields = $fieldRepo->getFieldsForListing($listingId);

        // ------------------------------------------------------------------
        // File validation
        // ------------------------------------------------------------------

        $upload = $request->file('csv');

        if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return $this->adminLayout('admin/members/import.php', 'Import Members', 'members', [
                'listing' => $listing,
                'results' => null,
                'error'   => 'Please select a CSV file to upload.',
            ], 'Owned › ' . $listing->name);
        }

        if (($upload['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return $this->adminLayout('admin/members/import.php', 'Import Members', 'members', [
                'listing' => $listing,
                'results' => null,
                'error'   => 'File upload failed (error code ' . (int) $upload['error'] . ').',
            ], 'Owned › ' . $listing->name);
        }

        if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
            return $this->adminLayout('admin/members/import.php', 'Import Members', 'members', [
                'listing' => $listing,
                'results' => null,
                'error'   => 'File exceeds the 2 MB size limit.',
            ], 'Owned › ' . $listing->name);
        }

        $tmpPath = (string) ($upload['tmp_name'] ?? '');

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpPath);
        $allowed  = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];

        if (!in_array($mimeType, $allowed, true)) {
            return $this->adminLayout('admin/members/import.php', 'Import Members', 'members', [
                'listing' => $listing,
                'results' => null,
                'error'   => 'Only CSV files are accepted (detected: ' . htmlspecialchars($mimeType, ENT_QUOTES, 'UTF-8') . ').',
            ], 'Owned › ' . $listing->name);
        }

        // ------------------------------------------------------------------
        // Parse CSV
        // ------------------------------------------------------------------

        $handle = fopen($tmpPath, 'r');

        if ($handle === false) {
            return $this->adminLayout('admin/members/import.php', 'Import Members', 'members', [
                'listing' => $listing,
                'results' => null,
                'error'   => 'Could not open uploaded file for reading.',
            ], 'Owned › ' . $listing->name);
        }

        // Read + normalise header row
        $rawHeader = fgetcsv($handle);

        if ($rawHeader === false || $rawHeader === null) {
            fclose($handle);
            return $this->adminLayout('admin/members/import.php', 'Import Members', 'members', [
                'listing' => $listing,
                'results' => null,
                'error'   => 'The file appears to be empty or could not be read.',
            ], 'Owned › ' . $listing->name);
        }

        $headerMap = []; // column name (lowercase) → column index
        foreach ($rawHeader as $i => $col) {
            $headerMap[strtolower(trim((string) $col))] = $i;
        }

        if (!isset($headerMap['name']) || !isset($headerMap['email'])) {
            fclose($handle);
            return $this->adminLayout('admin/members/import.php', 'Import Members', 'members', [
                'listing' => $listing,
                'results' => null,
                'error'   => 'The CSV must have at least a "name" column and an "email" column in the header row.',
            ], 'Owned › ' . $listing->name);
        }

        // Build set of valid additional field names for this listing
        $fieldNames = [];
        foreach ($fields as $field) {
            $fieldNames[] = $field->name;
        }

        // ------------------------------------------------------------------
        // Process rows
        // ------------------------------------------------------------------

        $prefix      = (string) $config->get('enth_table_prefix', '');
        $imported    = 0;
        $skipped     = 0;
        $errors      = [];
        $rowNumber   = 1; // 1 = header; data rows start at 2

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // Skip entirely blank rows
            if ($row === [null] || array_filter(array_map('trim', $row)) === []) {
                continue;
            }

            $get = static function (string $col) use ($row, $headerMap): string {
                $idx = $headerMap[$col] ?? null;
                return $idx !== null && isset($row[$idx]) ? trim((string) $row[$idx]) : '';
            };

            $name    = $get('name');
            $email   = $get('email');
            $url     = $get('url');
            $country = $get('country');
            $rawPass = $get('password');

            // Validate name
            if ($name === '') {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'reason' => 'Name is required.'];
                continue;
            }
            if (strlen($name) > 255) {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'reason' => 'Name exceeds 255 characters.'];
                continue;
            }

            // Validate email
            if ($email === '') {
                $errors[] = ['row' => $rowNumber, 'email' => '(empty)', 'reason' => 'Email is required.'];
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'reason' => 'Invalid email address.'];
                continue;
            }

            // Duplicate check
            if ($memberRepo->getByEmail($listing->dbprefix, $email) !== null) {
                $skipped++;
                continue;
            }

            // Build insert data
            $password    = $rawPass !== '' ? $rawPass : bin2hex(random_bytes(8));
            $insertData  = [
                'name'     => $name,
                'email'    => $email,
                'url'      => $url,
                'country'  => $country,
                'showurl'  => $url !== '' ? 1 : 0,
                'password' => password_hash($password, PASSWORD_ARGON2ID),
                'added'    => date('Y-m-d H:i:s'),
            ];

            // Schema-specific status column
            if ($prefix === '') {
                $insertData['pending'] = 0; // immediately approved
            } else {
                $insertData['approved'] = 1;
                $insertData['updated']  = date('Y-m-d H:i:s');
            }

            // Additional fields
            foreach ($fields as $field) {
                $colName              = ($prefix === '') ? $field->name : 'additional_' . $field->name;
                $insertData[$colName] = $get($field->name);
            }

            try {
                $memberRepo->insert($listing->dbprefix, $insertData);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNumber, 'email' => $email, 'reason' => 'Database error: ' . $e->getMessage()];
            }
        }

        fclose($handle);

        // Touch last_updated if anything was imported
        if ($imported > 0) {
            (new ListingSettingsRepo($db))->touchLastUpdated($listingId);
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'member.import',
            "CSV import into listing #{$listingId}: {$imported} imported, {$skipped} skipped (duplicates), " . count($errors) . ' errors.',
            $request->ip(),
        );

        return $this->adminLayout('admin/members/import.php', 'Import Members', 'members', [
            'listing' => $listing,
            'results' => [
                'imported' => $imported,
                'skipped'  => $skipped,
                'errors'   => $errors,
            ],
            'error' => '',
        ], 'Owned › ' . $listing->name);
    }

    // -----------------------------------------------------------------------
    // GET /admin/members/{id}/export
    // -----------------------------------------------------------------------

    public function export(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $fieldRepo      = new FieldRepo($db, $config);

        $listing = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $members = $memberRepo->getApproved($listing->dbprefix);
        $fields  = $fieldRepo->getFieldsForListing($listingId);

        // Build CSV in memory
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return Response::serverError('Could not open temp stream for CSV export.');
        }

        // Header row
        $headerRow = ['name', 'email', 'url', 'country', 'added'];
        foreach ($fields as $field) {
            $headerRow[] = $field->name;
        }
        fputcsv($handle, $headerRow);

        // Data rows
        foreach ($members as $m) {
            $row = [
                $m->name,
                $m->email,
                $m->url,
                $m->country,
                $m->added,
            ];
            foreach ($fields as $field) {
                $row[] = $m->additionalFields[$field->name] ?? '';
            }
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $filename = 'members_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($listing->name)) . '.csv';

        return Response::csv((string) $csv, $filename);
    }

    public function exportPending(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);

        $all    = $listingRepo->getAll();
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['Listing', 'Name', 'Email', 'Country', 'URL']);

        foreach ($all as $fl) {
            foreach ($memberRepo->getPending($fl->dbprefix) as $m) {
                fputcsv($handle, [$fl->name, $m->name, $m->email, $m->country, $m->url]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return Response::csv((string) $csv, 'pending_members.csv');
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    /**
     * Executes a single approve / reject / delete action and redirects.
     */
    private function singleAction(int $listingId, string $email, string $action, Request $request): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $activityRepo   = new ActivityRepo($db);

        $listing = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($email === '') {
            $session->set('lstr_flash_error', 'No member email provided.');
            return Response::redirect($baseUrl . '/admin/members/' . $listingId);
        }

        match ($action) {
            'approve' => $memberRepo->approve($listing->dbprefix, $email),
            'pending' => $memberRepo->setPending($listing->dbprefix, $email),
            'reject'  => $memberRepo->reject($listing->dbprefix, $email),
            'delete'  => $memberRepo->deleteByEmail($listing->dbprefix, $email),
        };

        // Touch last_updated when a member is approved (signals listing activity)
        if ($action === 'approve') {
            (new ListingSettingsRepo($db))->touchLastUpdated($listingId);

            // Approval email (best-effort)
            $member = $memberRepo->getByEmail($listing->dbprefix, $email);
            if ($member !== null) {
                try {
                    $cfg       = $this->app->getConfig();
                    $mailer    = new Mailer($cfg);
                    $emailRepo = new EmailTemplateRepo($db);
                    $tokens    = EmailTemplateRepo::buildTokens(
                        listingName:    $listing->name,
                        listingUrl:     $listing->url,
                        siteName:       (string) $cfg->get('site_name', 'Luster'),
                        listingSubject: $listing->subject,
                        ownerName:      (string) $cfg->get('owner_name', ''),
                        ownerEmail:     (string) $cfg->get('owner_email', ''),
                        fanName:        $member->name,
                        fanEmail:       $member->email,
                    );
                    [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_APPROVE, $tokens);
                    $mailer->send($member->email, $member->name, $subject, $body);
                } catch (\Throwable $e) {
                    error_log('[Luster] Approve email failed: ' . $e->getMessage());
                }
            }
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            "member.{$action}",
            ucfirst($action) . "d member {$email} in listing #{$listingId}",
            $request->ip(),
        );

        $label = match ($action) {
            'approve' => 'Member approved.',
            'reject'  => 'Member rejected and removed.',
            'delete'  => 'Member deleted.',
        };

        $session->set('lstr_flash_success', $label);

        $page    = max(1, (int) $request->post('_page', 1));
        $pageQs  = $page > 1 ? '?page=' . $page : '';
        return Response::redirect($baseUrl . '/admin/members/' . $listingId . $pageQs);
    }

    // -----------------------------------------------------------------------
    // GET /admin/members/settings
    // -----------------------------------------------------------------------

    public function indexSettings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/members/settings.php', 'Members Settings', 'members', [
            'cfg'     => $this->app->getConfig()->all(),
            'success' => $success,
            'error'   => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/settings
    // -----------------------------------------------------------------------

    public function saveSettings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $perPage = max(5, min(200, (int) $request->post('per_page', 25)));

        $updated             = $this->app->getConfig()->all();
        $updated['per_page'] = $perPage;

        try {
            $this->writeConfig($updated);
        } catch (\RuntimeException $e) {
            $session->set('lstr_flash_error', 'Could not save settings: ' . $e->getMessage());
            return Response::redirect($baseUrl . '/admin/members/settings');
        }

        $activityRepo = new ActivityRepo($this->app->getDatabase());
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'members.settings.save',
            'Members display settings updated',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Settings saved successfully.');
        return Response::redirect($baseUrl . '/admin/members/settings');
    }

    public function emailMemberForm(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();
        $id     = (int) $params['id'];

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::redirect($this->app->getConfig()->get('site_url', '') . '/admin/members');
        }

        $email       = trim((string) $request->query('email', ''));
        $member      = (new MemberRepo($db, $config))->getByEmail($listing->dbprefix, $email);
        $savedEmails = (new \Luster\Repositories\SavedEmailRepo($db))->getAll();
        $baseUrl     = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $csrf          = $this->app->getCSRF()->token();
        $smtpFrom  = (string) $config->get('smtp_from', '');
        $fromEmail = $smtpFrom !== '' ? $smtpFrom : $listing->email;
        $fromName  = (string) $config->get('smtp_from_name', '') !== ''
            ? (string) $config->get('smtp_from_name', '')
            : (string) $config->get('owner_name', '');
        $flash_error   = $session->get('lstr_flash_error', '');
        $flash_success = $session->get('lstr_flash_success', '');
        $old           = (array) $session->get('lstr_email_old', []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_email_old');

        $referer = $request->header('referer') ?? $baseUrl . '/admin/members/' . $id;

        return $this->adminLayout('admin/members/email.php', 'Email Member', 'members', [
            'listing'        => $listing,
            'member_email'   => $email,
            'member_name'    => $member ? $member->name    : '',
            'member_url'     => $member ? $member->url     : '',
            'member_country' => $member ? $member->country : '',
            'from_email'     => $fromEmail,
            'from_name'      => $fromName,
            'site_name'      => (string) $config->get('site_name', ''),
            'saved_emails'   => $savedEmails,
            'csrf_token'     => $csrf,
            'base_url'       => $baseUrl,
            'error'          => $flash_error,
            'success'        => $flash_success,
            'old'            => $old,
            'referer'        => $referer,
        ], 'Owned › ' . $listing->name);
    }

    public function emailMemberSend(Request $request, array $params): Response
    {
        $config   = $this->app->getConfig();
        $id       = (int) $params['id'];
        $toEmail         = trim((string) $request->post('member_email', ''));
        $db              = $this->app->getDatabase();
        $listingRepo  = new ListingRepo($db, $config);
        $listing      = $listingRepo->getById($id);
        $flName          = $listing ? $listing->name : '';
        $suffix          = trim((string) $request->post('subject_suffix', ''));
        $subject         = $flName !== '' ? $flName . ': ' . $suffix : $suffix;
        $body            = trim((string) $request->post('body', ''));
        $baseUrl  = $this->app->getBaseUrl();
        $session  = $this->app->getSession();

        $redirectBack = $baseUrl . '/admin/members/' . $id . '/emailmember?email=' . urlencode($toEmail);

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Please fill in both the subject and message before sending.');
            $session->set('lstr_email_old', ['subject' => $suffix, 'body' => $body]);
            return Response::redirect($redirectBack);
        }

        try {
            $mailer = new \Luster\Core\Mailer($config);
            $mailer->send($toEmail, '', $subject, $body);
            $session->set('lstr_flash_success', 'Email sent successfully!');
        } catch (\RuntimeException $e) {
            $session->set('lstr_flash_error', 'The email could not be sent. Please check your SMTP settings in Settings and try again.');
            $session->set('lstr_email_old', ['subject' => $suffix, 'body' => $body]);
        }

        return Response::redirect($redirectBack);
    }

    // -----------------------------------------------------------------------
    // GET /admin/members/{id}/email-selected
    // -----------------------------------------------------------------------

    public function emailSelectedForm(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();
        $id     = (int) ($params['id'] ?? 0);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::redirect($baseUrl . '/admin/members');
        }

        $selectedEmails = array_values(array_filter(array_map(
            'trim',
            (array) $session->get('lstr_selected_emails', []),
        )));
        $session->remove('lstr_selected_emails');

        if (empty($selectedEmails)) {
            $session->set('lstr_flash_error', 'No members selected for email.');
            return Response::redirect($baseUrl . '/admin/members/' . $id);
        }

        $savedEmails = (new \Luster\Repositories\SavedEmailRepo($db))->getAll();
        $error   = (string) $session->get('lstr_flash_error', '');
        $success = (string) $session->get('lstr_flash_success', '');
        $old     = (array)  $session->get('lstr_email_old', []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_email_old');

        // Look up member names so chips can show "Name <email>"
        $memberRepo  = new MemberRepo($db, $config);
        $memberNames = [];
        foreach ($selectedEmails as $email) {
            $m = $memberRepo->getByEmail($listing->dbprefix, $email);
            $memberNames[$email] = $m ? $m->name : '';
        }

        return $this->adminLayout('admin/members/email-selected.php', 'Email Selected Members', 'members', [
            'listing'         => $listing,
            'selected_emails' => $selectedEmails,
            'member_names'    => $memberNames,
            'saved_emails'    => $savedEmails,
            'from_name'       => (string) $config->get('smtp_from_name', ''),
            'from_email'      => (string) $config->get('smtp_from', ''),
            'owner_name'      => (string) $config->get('owner_name', ''),
            'owner_email'     => (string) $config->get('owner_email', ''),
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'error'           => $error,
            'success'         => $success,
            'old'             => $old,
        ], 'Owned › ' . $listing->name);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/email-selected/send
    // -----------------------------------------------------------------------

    public function emailSelectedSend(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $id      = (int) ($params['id'] ?? 0);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::redirect($baseUrl . '/admin/members');
        }

        $emails  = array_values(array_filter(array_map('trim', (array) $request->post('emails', []))));
        $subject = trim((string) $request->post('subject', ''));
        $body    = trim((string) $request->post('body', ''));

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Subject and message are required.');
            $session->set('lstr_selected_emails', $emails);
            $session->set('lstr_email_old', ['subject' => $subject, 'body' => $body]);
            return Response::redirect($baseUrl . '/admin/members/' . $id . '/email-selected');
        }

        if (empty($emails)) {
            $session->set('lstr_flash_error', 'No recipients found.');
            return Response::redirect($baseUrl . '/admin/members/' . $id);
        }

        $memberRepo  = new MemberRepo($db, $config);
        $siteName    = (string) $config->get('site_name', 'Luster');
        $ownerName   = (string) $config->get('owner_name', '');
        $ownerEmail  = (string) $config->get('owner_email', '');
        $mailer      = new Mailer($this->app->getConfig());

        $sent   = 0;
        $failed = [];

        foreach ($emails as $email) {
            $member  = $memberRepo->getByEmail($listing->dbprefix, $email);
            $name    = $member ? $member->name    : '';
            $url     = $member ? $member->url     : '';
            $country = $member ? $member->country : '';

            $search  = ['{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}',
                        '{listing_name}', '{listing_subject}', '{listing_url}',
                        '{site_name}', '{owner_name}', '{owner_email}'];
            $replace = [$name,        $email,        $url,        $country,
                        $listing->name, $listing->subject, $listing->url,
                        $siteName,    $ownerName,    $ownerEmail];

            $personalSubject = str_replace($search, $replace, $subject);
            $personalBody    = str_replace($search, $replace, $body);

            try {
                $mailer->send($email, $name, $personalSubject, $personalBody);
                $sent++;
            } catch (\Throwable $e) {
                $failed[] = $email;
                error_log('[Luster] email-selected send failed for ' . $email . ': ' . $e->getMessage());
            }
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'member.email_selected',
            "Sent email to {$sent} selected member(s) in listing #{$id} ({$listing->name}). Subject: {$subject}",
            $request->ip(),
        );

        if (!empty($failed)) {
            $session->set('lstr_flash_error', "Sent to {$sent} member(s). Failed for: " . implode(', ', $failed));
        } else {
            $session->set('lstr_flash_success', "Email sent to {$sent} member(s).");
        }

        return Response::redirect($baseUrl . '/admin/members/' . $id);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/email-selected/save-template
    // -----------------------------------------------------------------------

    public function emailSelectedSaveTemplate(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $id      = (int) ($params['id'] ?? 0);
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::redirect($baseUrl . '/admin/members');
        }

        $name    = trim((string) $request->post('tpl_name', ''));
        $subject = trim((string) $request->post('tpl_subject', ''));
        $body    = trim((string) $request->post('tpl_body', ''));
        $emails  = array_values(array_filter(array_map('trim', (array) $request->post('emails', []))));

        $redirect = $baseUrl . '/admin/members/' . $id . '/email-selected';

        if ($name === '' || $body === '') {
            $session->set('lstr_flash_error', 'Template name and message body are required.');
        } else {
            (new \Luster\Repositories\SavedEmailRepo($db))->create($name, $subject, $body);
            $session->set('lstr_flash_success', 'Template "' . $name . '" saved.');
        }

        // Restore compose state so the page can re-show recipients + filled fields
        if (!empty($emails)) {
            $session->set('lstr_selected_emails', $emails);
        }
        $session->set('lstr_email_old', ['subject' => $subject, 'body' => $body]);

        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------

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
            unlink($tmpPath);
            throw new \RuntimeException('Could not rename temp config file to: ' . $configPath);
        }
    }
}
