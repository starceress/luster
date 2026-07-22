<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages members for individual listings. Provides the unified members page
 (overview across all listings, per-listing member list, and search), plus
 member editing, approval/rejection, bulk actions, CSV export, and the
 compose-and-send flow for emailing selected members. Each member action
 logs to the activity log and sends email notifications where configured.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;

/**
 * Unified member management controller.
 *
 * Serves the member overview (cross-listing counts), per-listing member list
 * (approve/reject/delete/edit, bulk actions, pending updates), global search,
 * CSV export, merge (deduplicate), email-selected, and per-listing member
 * settings. All writes are logged to the activity log.
 */
final class MemberController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/members          — unified listing+overview page
    // GET /admin/members/{id}     — redirects here with listing_id={id}
    // -----------------------------------------------------------------------

    /**
     * Unified member page. Renders the overview (cross-listing counts and a
     * listing selector) when no listing is selected, or the per-listing member
     * list with status tabs, pending-updates section, and sort/search controls
     * when a listing_id is provided. Also handles cross-listing search.
     */
    public function overview(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo       = new ListingRepo($db, $config);
        $memberRepo        = new MemberRepo($db, $config);
        $fieldRepo         = new FieldRepo($db, $config);
        $pendingUpdateRepo = new PendingUpdateRepo($db);
        $allListings       = $listingRepo->getAll();

        $flById = [];
        foreach ($allListings as $fl) {
            $flById[(int) $fl->id] = $fl;
        }

        $listingId   = (int) $request->query('listing_id', 0);
        $search      = trim((string) $request->query('search', ''));
        $statusParam = $request->query('status', null);
        $sort        = trim((string) $request->query('sort', 'name'));
        $dir         = trim((string) $request->query('dir', 'asc'));
        $page        = max(1, (int) $request->query('page', 1));
        $puPage      = max(1, (int) $request->query('pu_page', 1));
        $perPage     = max(5, min(200, (int) $config->get('per_page', 25)));

        if (!in_array($sort, ['id', 'name', 'email', 'country', 'status', 'listing', 'total', 'pending', 'rejected'], true)) { $sort = 'name'; }
        if (!in_array($dir, ['asc', 'desc'], true)) { $dir = 'asc'; }

        $validStatuses = ['all', 'pending', 'approved', 'rejected'];

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        // Resolve listing (null if not selected or not found / no dbprefix)
        $listing = $listingId > 0 ? ($flById[$listingId] ?? null) : null;
        if ($listing !== null && $listing->dbprefix === '') {
            $listing   = null;
            $listingId = 0;
        }

        // ------------------------------------------------------------------
        // Tab counts (per-listing or cross-listing)
        // ------------------------------------------------------------------
        $listingCountsMap = [];
        if ($listing !== null) {
            $cntPendingNew     = $memberRepo->countPending($listing->dbprefix);
            $cntApproved       = $memberRepo->countApproved($listing->dbprefix);
            $cntRejected       = $memberRepo->countRejected($listing->dbprefix);
            $cntPendingUpdates = $pendingUpdateRepo->countByListing($listingId);
            // Populate the full map so the dropdown "All Listings" total is accurate
            foreach ($allListings as $fl) {
                if ($fl->dbprefix === '') { continue; }
                if ((int) $fl->id === (int) $listing->id) {
                    $listingCountsMap[(int) $fl->id] = ['approved' => $cntApproved, 'pending' => $cntPendingNew, 'rejected' => $cntRejected];
                } else {
                    $p = $memberRepo->countPending($fl->dbprefix);
                    $a = $memberRepo->countApproved($fl->dbprefix);
                    $r = $memberRepo->countRejected($fl->dbprefix);
                    $listingCountsMap[(int) $fl->id] = ['approved' => $a, 'pending' => $p, 'rejected' => $r];
                }
            }
        } else {
            $cntPendingNew     = 0;
            $cntApproved       = 0;
            $cntRejected       = 0;
            $cntPendingUpdates = $pendingUpdateRepo->countAll();
            foreach ($allListings as $fl) {
                if ($fl->dbprefix === '') { continue; }
                $p = $memberRepo->countPending($fl->dbprefix);
                $a = $memberRepo->countApproved($fl->dbprefix);
                $r = $memberRepo->countRejected($fl->dbprefix);
                $cntPendingNew += $p;
                $cntApproved   += $a;
                $cntRejected   += $r;
                $listingCountsMap[(int) $fl->id] = ['approved' => $a, 'pending' => $p, 'rejected' => $r];
            }
        }
        $tabCounts = [
            'all'      => $cntPendingNew + $cntApproved + $cntRejected,
            'pending'  => $cntPendingNew + $cntPendingUpdates,
            'approved' => $cntApproved,
            'rejected' => $cntRejected,
        ];

        // Auto-select tab when no explicit status param
        if ($statusParam === null) {
            $status = $tabCounts['pending'] > 0 ? 'pending' : 'all';
        } else {
            $status = in_array($statusParam, $validStatuses, true) ? $statusParam : 'all';
        }

        // ------------------------------------------------------------------
        // Load content data for the active tab
        // ------------------------------------------------------------------
        $members               = [];
        $fields                = [];
        $total                 = 0;
        $pages                 = 1;
        $listingPendingUpdates = [];
        $pendingMemberData     = [];
        $grouped               = [];
        $pendingUpdates        = [];
        $crossListingSearch    = [];
        $puTotal               = 0;
        $puPages               = 1;

        if ($listing !== null) {
            // --- Per-listing
            $fields = $fieldRepo->getFieldsForListing($listingId);

            if ($status === 'pending') {
                // Pending new members
                if ($search !== '') {
                    $all = array_filter(
                        $memberRepo->search($listing->dbprefix, $search),
                        static fn($m) => !$m->approved
                    );
                    usort($all, static fn($a, $b) => strcasecmp($a->name, $b->name));
                    $total   = count($all);
                    $pages   = max(1, (int) ceil($total / $perPage));
                    $members = array_slice(array_values($all), ($page - 1) * $perPage, $perPage);
                } else {
                    $r       = $memberRepo->paginateByStatus($listing->dbprefix, 'pending', $page, $perPage, $sort, $dir);
                    $members = $r['members'];
                    $total   = $r['total'];
                    $pages   = $r['pages'];
                }
                // Pending updates for this listing (paginated)
                $puResult              = $pendingUpdateRepo->paginateByListing($listingId, $puPage, $perPage);
                $listingPendingUpdates = $puResult['updates'];
                $puTotal               = $puResult['total'];
                $puPages               = $puResult['pages'];
                foreach ($listingPendingUpdates as $pu) {
                    $email = $pu->member_email;
                    if (!isset($pendingMemberData[$email])) {
                        $m = $memberRepo->getByEmail($listing->dbprefix, $email);
                        if ($m !== null) {
                            $pendingMemberData[$email] = $m;
                        }
                    }
                }
            } else {
                // All or Approved — paginated member table
                if ($search !== '') {
                    $all = $memberRepo->search($listing->dbprefix, $search);
                    if ($status === 'approved') {
                        $all = array_filter($all, static fn($m) => $m->approved);
                    }
                    usort($all, static function ($a, $b) use ($sort, $dir): int {
                        $cmp = match ($sort) {
                            'email'   => strcasecmp($a->email,   $b->email),
                            'country' => strcasecmp($a->country, $b->country) ?: strcasecmp($a->name, $b->name),
                            'status'  => ($a->approved <=> $b->approved)      ?: strcasecmp($a->name, $b->name),
                            default   => strcasecmp($a->name, $b->name),
                        };
                        return $dir === 'desc' ? -$cmp : $cmp;
                    });
                    $total   = count($all);
                    $pages   = max(1, (int) ceil($total / $perPage));
                    $members = array_slice(array_values($all), ($page - 1) * $perPage, $perPage);
                } else {
                    $r       = $memberRepo->paginateByStatus($listing->dbprefix, $status, $page, $perPage, $sort, $dir);
                    $members = $r['members'];
                    $total   = $r['total'];
                    $pages   = $r['pages'];
                }
            }
        } else {
            // --- Cross-listing
            if ($search !== '') {
                // Cross-listing search: results shown in the active tab
                foreach ($allListings as $fl) {
                    if ($fl->dbprefix === '') { continue; }
                    foreach ($memberRepo->search($fl->dbprefix, $search) as $member) {
                        $crossListingSearch[] = ['listing' => $fl, 'member' => $member];
                    }
                }
                usort($crossListingSearch, static fn($a, $b) =>
                    strcmp($a['listing']->title, $b['listing']->title) ?:
                    strcmp($a['member']->name,   $b['member']->name)
                );
            } elseif ($status === 'pending') {
                // Flat paginated pending new members + paginated pending updates
                $puOrderBy = match ($sort) {
                    'listing' => $dir === 'desc' ? 'fanlisting_id DESC' : 'fanlisting_id ASC',
                    default   => 'submitted_at DESC',
                };
                $puResult       = $pendingUpdateRepo->paginateAll($puPage, $perPage, $puOrderBy);
                $pendingUpdates = $puResult['updates'];
                $puTotal        = $puResult['total'];
                $puPages        = $puResult['pages'];

                // Build member-data map for pending updates (name/url/country lookup)
                foreach ($pendingUpdates as $pu) {
                    $puFl = $flById[(int) $pu->fanlisting_id] ?? null;
                    if ($puFl !== null && $puFl->dbprefix !== '' && !isset($pendingMemberData[$pu->member_email])) {
                        $m = $memberRepo->getByEmail($puFl->dbprefix, $pu->member_email);
                        if ($m !== null) {
                            $pendingMemberData[$pu->member_email] = $m;
                        }
                    }
                }

                $flatPending = [];
                foreach ($allListings as $fl) {
                    if ($fl->dbprefix === '') { continue; }
                    foreach ($memberRepo->getPending($fl->dbprefix) as $m) {
                        $flatPending[] = ['listing' => $fl, 'member' => $m];
                    }
                }
                usort($flatPending, static function (array $a, array $b) use ($sort, $dir): int {
                    $cmp = match ($sort) {
                        'listing' => strcmp($a['listing']->title, $b['listing']->title) ?: strcasecmp($a['member']->name, $b['member']->name),
                        'country' => strcasecmp($a['member']->country, $b['member']->country) ?: strcmp($a['listing']->title, $b['listing']->title),
                        default   => strcasecmp($a['member']->name, $b['member']->name) ?: strcmp($a['listing']->title, $b['listing']->title),
                    };
                    return $dir === 'desc' ? -$cmp : $cmp;
                });
                $total  = count($flatPending);
                $pages  = max(1, (int) ceil($total / $perPage));
                $page   = min($page, $pages);
                $members = array_slice($flatPending, ($page - 1) * $perPage, $perPage);
            }
            // All/Approved with no search: template shows listing summary table
        }

        return $this->adminLayout('admin/members/index.php', 'Members', 'members', [
            'listing'                 => $listing,
            'listing_id'              => $listingId,
            'all_listings'            => $allListings,
            'fl_by_id'                => $flById,
            'status'                  => $status,
            'tab_counts'              => $tabCounts,
            'search'                  => $search,
            'sort'                    => $sort,
            'dir'                     => $dir,
            'page'                    => $page,
            'pu_page'                 => $puPage,
            'per_page'                => $perPage,
            // Per-listing data
            'members'                 => $members,
            'fields'                  => $fields,
            'total'                   => $total,
            'pages'                   => $pages,
            'listing_pending_updates' => $listingPendingUpdates,
            'pending_member_data'     => $pendingMemberData,
            // Cross-listing data
            'pending_updates'         => $pendingUpdates,
            'pending_updates_total'   => $puTotal,
            'pending_updates_pages'   => $puPages,
            'listing_counts'          => $listingCountsMap,
            'cross_listing_search'    => $crossListingSearch,
            'filters_active'          => $listingId > 0 || $search !== '',
            'success'                 => $success,
            'error'                   => $error,
        ]);
    }

    // GET /admin/members/{id} — redirect to unified page
    /**
     * Redirects /members/{id} to the unified members page pre-filtered to the
     * given listing ID (backwards-compatible URL shim).
     */
    public function index(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl   = $this->app->getBaseUrl();
        return Response::redirect($baseUrl . '/admin/members?listing_id=' . $listingId . '&status=all');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/members/{id}/edit  (?email=addr@example.com)
    // -----------------------------------------------------------------------

    /**
     * Renders the member edit form (GET) or saves name/URL/country/custom-field
     * changes (POST). Touches the listing's last_updated date on save.
     */
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

            $newEmail = trim((string) $request->post('email', $email));
            $name     = trim((string) $request->post('name', ''));
            $url      = trim((string) $request->post('url', ''));
            $country  = trim((string) $request->post('country', ''));
            $showurl  = $request->post('showurl') === '1';

            $currentStatus = $member->approved ? 'approved' : ($member->rejected ? 'rejected' : 'pending');
            $status        = (string) $request->post('status', $currentStatus);
            if (!in_array($status, ['approved', 'pending', 'rejected'], true)) {
                $status = $currentStatus;
            }

            $v = new Validator(['name' => $name, 'url' => $url, 'email' => $newEmail]);
            $v->field('name')->required()->maxLength(255);
            $v->field('email')->required()->email()->maxLength(255);
            if ($url !== '') {
                $v->field('url')->httpUrl()->maxLength(500);
            }

            $emailChanged = strtolower($newEmail) !== strtolower($email);
            if (!$v->fails() && $emailChanged && $memberRepo->getByEmail($listing->dbprefix, $newEmail) !== null) {
                $v->addFieldError('email', 'Another member in this listing already uses that email.');
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
                    'supports_rejected_state' => $memberRepo->supportsRejectedState(),
                ], '', $this->memberBreadcrumbs($listing, $member->name));
            }

            $updateData = [
                'email'   => $newEmail,
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
            if ($emailChanged) {
                // Re-key side tables that reference the member by email string
                // (no FK) so queued updates and link-check history follow the
                // rename instead of being orphaned under the old address.
                (new PendingUpdateRepo($db))->renameMemberEmail($listingId, $email, $newEmail);
                try {
                    (new MemberLinkCheckRepo($db, $config))->renameMemberEmail($listingId, $email, $newEmail);
                } catch (\Throwable) {}
                // Any outstanding password-reset token was issued to the old
                // address and can't meaningfully be "renamed" (ResetController
                // now safely rejects it either way) — just invalidate it so a
                // stale link doesn't linger.
                (new FanPasswordResetRepo($db))->deleteByEmail($listingId, $email);
                $email = $newEmail;
            }
            if ($url !== '') {
                try {
                    (new MemberLinkCheckRepo($db, $config))->upsertMember($listingId, $email, $name, $url);
                } catch (\Throwable) {}
            }

            // Status change, applied last since a 'rejected' choice deletes the
            // member outright on schemas with no separate rejected state (see
            // MemberRepo::supportsRejectedState()) — everything else above must
            // already be saved by the time that can happen.
            $deleted = false;
            if ($status !== $currentStatus) {
                if ($status === 'approved') {
                    $memberRepo->approve($listing->dbprefix, $email);
                } elseif ($status === 'pending') {
                    $memberRepo->setPending($listing->dbprefix, $email);
                } else { // 'rejected'
                    $deleted = !$memberRepo->supportsRejectedState();
                    $memberRepo->reject($listing->dbprefix, $email);
                    if ($deleted) {
                        try {
                            (new MemberLinkCheckRepo($db, $config))->removeMember($listingId, $email);
                        } catch (\Throwable) {}
                    }
                }
            }

            (new ListingSettingsRepo($db))->touchLastUpdated($listingId);

            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'member.edit',
                $deleted
                    ? "Edited member {$email} in listing #{$listingId} and rejected (removed, no rejected state on this schema)"
                    : "Edited member {$email} in listing #{$listingId}",
                $request->ip(),
            );

            $session->set('lstr_flash_success', $deleted ? 'Member updated and rejected (removed).' : 'Member updated.');
            return Response::redirect($baseUrl . '/admin/members?listing_id=' . $listingId . '&status=all');
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
            'supports_rejected_state' => $memberRepo->supportsRejectedState(),
        ], '', $this->memberBreadcrumbs($listing, $member->name));
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/approve
    // -----------------------------------------------------------------------

    /**
     * Approves a single pending member and sends a confirmation email if enabled.
     */
    public function approve(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $email        = trim((string) $request->post('member_email', ''));

        return $this->singleAction($listingId, $email, 'approve', $request);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/reject
    // -----------------------------------------------------------------------

    /**
     * Rejects (removes) a pending member without sending a notification email.
     */
    public function reject(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $email        = trim((string) $request->post('member_email', ''));

        return $this->singleAction($listingId, $email, 'reject', $request);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/delete
    // -----------------------------------------------------------------------

    /**
     * Permanently deletes an approved or pending member from the member table.
     */
    public function deleteMember(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $email        = trim((string) $request->post('member_email', ''));

        return $this->singleAction($listingId, $email, 'delete', $request);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/merge
    // -----------------------------------------------------------------------

    /**
     * Merges two member records by deleting the duplicate (secondary) email.
     * The primary member being edited is kept unchanged. Both must belong to
     * the same listing.
     */
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
                . ' in listing #' . $listingId . ' (' . $listing->title . ')',
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

    /**
     * Handles per-listing bulk member actions: approve, pending, reject,
     * delete, and email_selected. The email_selected action stashes the chosen
     * email addresses in the session and redirects to the email-selected composer.
     */
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

        $action    = (string) $request->post('bulk_action', '');
        $selectAll = $request->post('select_all', '0') === '1';

        if ($selectAll) {
            $saStatus = trim((string) $request->post('sa_filter_status', 'all'));
            $saSearch = trim((string) $request->post('sa_filter_search', ''));
            $emails   = $memberRepo->getAllEmailsByFilter($listing->dbprefix, $saStatus, $saSearch);
        } else {
            $emails = (array) $request->post('emails', []);
            $emails = array_filter(array_map('trim', $emails));
        }

        if (empty($emails) || !in_array($action, ['approve', 'pending', 'reject', 'delete', 'email_selected'], true)) {
            $session->set('lstr_flash_error', 'No members selected or invalid action.');
            return Response::redirect($baseUrl . '/admin/members?listing_id=' . $listingId . '&status=all');
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

            // Sync member link check snapshot (best-effort)
            if (in_array($action, ['reject', 'delete'], true)) {
                try {
                    (new MemberLinkCheckRepo($db, $config))->removeMember($listingId, $email);
                } catch (\Throwable) {}
            }

            // Approval email — mirrors the single-action path (best-effort)
            if ($action === 'approve') {
                $member = $memberRepo->getByEmail($listing->dbprefix, $email);
                if ($member !== null) {
                    if (($member->url ?? '') !== '') {
                        try {
                            (new MemberLinkCheckRepo($db, $config))->upsertMember($listingId, $member->email, $member->name, $member->url);
                        } catch (\Throwable) {}
                    }
                    try {
                        $cfg       = $this->app->getConfig();
                        $emailRepo = new EmailTemplateRepo($db);
                        $_lsr      = new ListingSettingsRepo($db);
                        $_es       = $_lsr->getResolvedEmbedSettings($listingId);
                        $tokens    = EmailTemplateRepo::buildTokens(
                            listingName:    $listing->title,
                            listingUrl:     $listing->url,
                            siteName:       (string) $cfg->get('site_name', 'Luster'),
                            listingSubject: $listing->subject,
                            ownerName:      (string) $cfg->get('owner_name', ''),
                            ownerEmail:     (string) $cfg->get('owner_email', ''),
                            fanName:        $member->name,
                            fanEmail:       $member->email,
                            listingEmail:   $listing->email,
                            formUrlJoin:    (string) $_es['form_url_join'],
                            formUrlUpdate:  (string) $_es['form_url_update'],
                            formUrlReset:   (string) $_es['form_url_reset'],
                            listingType:    $_lsr->getListingType($listingId),
                        );
                        [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_APPROVE, $tokens);
                        if ($subject !== '') { $mailer->send($member->email, $member->name, $subject, $body); }
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

        $pendingUpdateRepo = new PendingUpdateRepo($db);
        $from = (string) $request->post('from', '');
        if ($from === 'overview') {
            $allListings   = $listingRepo->getAll();
            $globalPending = $pendingUpdateRepo->countAll();
            foreach ($allListings as $fl) {
                if ($fl->dbprefix !== '') {
                    $globalPending += $memberRepo->countPending($fl->dbprefix);
                }
            }
            $redirectStatus = $globalPending > 0 ? 'pending' : 'all';
            return Response::redirect($baseUrl . '/admin/members?status=' . $redirectStatus);
        }
        $remaining      = $memberRepo->countPending($listing->dbprefix) + $pendingUpdateRepo->countByListing($listingId);
        $redirectStatus = $remaining > 0 ? 'pending' : 'all';
        return Response::redirect($baseUrl . '/admin/members?listing_id=' . $listingId . '&status=' . $redirectStatus);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/approve-all-pending
    // -----------------------------------------------------------------------

    /**
     * Approves all pending members for a single listing in one operation,
     * sending an approval email for each. Touches the listing's last_updated
     * date after all approvals complete.
     */
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
            return Response::redirect($baseUrl . '/admin/members?listing_id=' . $listingId . '&status=all');
        }

        $mailer    = new Mailer($config);
        $emailRepo = new EmailTemplateRepo($db);
        $_lsr      = new ListingSettingsRepo($db);
        $_es       = $_lsr->getResolvedEmbedSettings($listingId);
        $_lt       = $_lsr->getListingType($listingId);
        $count     = 0;

        foreach ($pending as $member) {
            $memberRepo->approve($listing->dbprefix, $member->email);

            if (($member->url ?? '') !== '') {
                try {
                    (new MemberLinkCheckRepo($db, $config))->upsertMember($listingId, $member->email, $member->name, $member->url);
                } catch (\Throwable) {}
            }

            try {
                $tokens    = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $config->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $config->get('owner_name', ''),
                    ownerEmail:     (string) $config->get('owner_email', ''),
                    fanName:        $member->name,
                    fanEmail:       $member->email,
                    listingEmail:   $listing->email,
                    formUrlJoin:    (string) $_es['form_url_join'],
                    formUrlUpdate:  (string) $_es['form_url_update'],
                    formUrlReset:   (string) $_es['form_url_reset'],
                    listingType:    $_lt,
                );
                [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_APPROVE, $tokens);
                if ($subject !== '') { $mailer->send($member->email, $member->name, $subject, $body); }
            } catch (\Throwable $e) {
                error_log('[Luster] Approve-all email failed for ' . $member->email . ': ' . $e->getMessage());
            }

            $count++;
        }

        (new ListingSettingsRepo($db))->touchLastUpdated($listingId);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'member.approve_all_pending',
            "Approved all {$count} pending member(s) in listing #{$listingId} ({$listing->title})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Approved {$count} pending member" . ($count !== 1 ? 's' : '') . '.');
        return Response::redirect($baseUrl . '/admin/members?listing_id=' . $listingId . '&status=all');
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/approve-all-overview
    //
    // Approves every pending new member across all listings AND every pending
    // member update. Sends approval/update emails best-effort for each.
    // -----------------------------------------------------------------------

    /**
     * Approves every pending new member across all listings AND every pending
     * member update in a single operation. Sends approval/update confirmation
     * emails best-effort for each. Logs aggregate counts to the activity log.
     */
    public function approveAllOverview(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo       = new ListingRepo($db, $config);
        $memberRepo        = new MemberRepo($db, $config);
        $settingsRepo      = new ListingSettingsRepo($db);
        $pendingUpdateRepo = new PendingUpdateRepo($db);
        $emailTemplateRepo = new EmailTemplateRepo($db);
        $activityRepo      = new ActivityRepo($db);
        $mailer            = new Mailer($config);

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $approvedMembers = 0;
        $approvedUpdates = 0;

        // --- 1. Approve all pending new members
        foreach ($listingRepo->getAll() as $fl) {
            if ($fl->dbprefix === '') {
                continue;
            }

            $pending     = $memberRepo->getPending($fl->dbprefix);
            $listingCount = 0;

            foreach ($pending as $member) {
                $memberRepo->approve($fl->dbprefix, $member->email);
                $listingCount++;

                if (($member->url ?? '') !== '') {
                    try {
                        (new MemberLinkCheckRepo($db, $config))->upsertMember((int) $fl->id, $member->email, $member->name, $member->url);
                    } catch (\Throwable) {}
                }

                try {
                    $_es    = $settingsRepo->getResolvedEmbedSettings((int) $fl->id);
                    $tokens = EmailTemplateRepo::buildTokens(
                        listingName:    $fl->title,
                        listingUrl:     $fl->url,
                        siteName:       (string) $config->get('site_name', 'Luster'),
                        listingSubject: $fl->subject,
                        ownerName:      (string) $config->get('owner_name', ''),
                        ownerEmail:     (string) $config->get('owner_email', ''),
                        fanName:        $member->name,
                        fanEmail:       $member->email,
                        listingEmail:   $fl->email,
                        formUrlJoin:    (string) $_es['form_url_join'],
                        formUrlUpdate:  (string) $_es['form_url_update'],
                        formUrlReset:   (string) $_es['form_url_reset'],
                        listingType:    $settingsRepo->getListingType((int) $fl->id),
                    );
                    [$subject, $body] = $emailTemplateRepo->resolveForListing(
                        (int) $fl->id,
                        EmailTemplateRepo::TYPE_APPROVE,
                        $tokens,
                    );
                    if ($subject !== '') { $mailer->send($member->email, $member->name, $subject, $body); }
                } catch (\Throwable $e) {
                    error_log('[Luster] approve-all-overview email failed for ' . $member->email . ': ' . $e->getMessage());
                }
            }

            if ($listingCount > 0) {
                $settingsRepo->touchLastUpdated((int) $fl->id);
                $approvedMembers += $listingCount;
            }
        }

        // --- 2. Approve all pending member updates
        foreach ($pendingUpdateRepo->getAll() as $pu) {
            $listing = $listingRepo->getById($pu->fanlisting_id);

            if ($listing === null) {
                $pendingUpdateRepo->delete($pu->id);
                continue;
            }

            $member = $memberRepo->getByEmail($listing->dbprefix, $pu->member_email);

            if ($member === null) {
                $pendingUpdateRepo->delete($pu->id);
                continue;
            }

            $memberRepo->update($listing->dbprefix, $pu->member_email, $pu->changes);
            $pendingUpdateRepo->delete($pu->id);

            try {
                $_es    = $settingsRepo->getResolvedEmbedSettings($pu->fanlisting_id);
                $tokens = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $config->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $config->get('owner_name', ''),
                    ownerEmail:     (string) $config->get('owner_email', ''),
                    fanName:        $member->name,
                    fanEmail:       $member->email,
                    listingEmail:   $listing->email,
                    formUrlJoin:    (string) $_es['form_url_join'],
                    formUrlUpdate:  (string) $_es['form_url_update'],
                    formUrlReset:   (string) $_es['form_url_reset'],
                    listingType:    $settingsRepo->getListingType($pu->fanlisting_id),
                );
                [$subject, $body] = $emailTemplateRepo->resolveForListing(
                    $pu->fanlisting_id,
                    EmailTemplateRepo::TYPE_UPDATE,
                    $tokens,
                );
                if ($subject !== '') { $mailer->send($member->email, $member->name, $subject, $body); }
            } catch (\Throwable $e) {
                error_log('[Luster] approve-all-overview update email failed for ' . $pu->member_email . ': ' . $e->getMessage());
            }

            $approvedUpdates++;
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'member.approve_all_overview',
            "Approved all pending across all listings: {$approvedMembers} new member(s), {$approvedUpdates} update(s)",
            $request->ip(),
        );

        $parts = [];
        if ($approvedMembers > 0) {
            $parts[] = "{$approvedMembers} new member" . ($approvedMembers !== 1 ? 's' : '');
        }
        if ($approvedUpdates > 0) {
            $parts[] = "{$approvedUpdates} pending update" . ($approvedUpdates !== 1 ? 's' : '');
        }
        $msg = empty($parts) ? 'Nothing pending to approve.' : 'Approved: ' . implode(' and ', $parts) . '.';

        $session->set('lstr_flash_success', $msg);
        return Response::redirect($baseUrl . '/admin/members');
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/pending-bulk
    //
    // Cross-listing bulk approve/reject for the unified pending table.
    // Checkbox values are encoded as "{listingId}:{email}".
    // -----------------------------------------------------------------------

    /**
     * Cross-listing bulk approve or reject for the unified pending members table.
     * Checkbox values are encoded as '{listingId}:{email}' strings and grouped
     * by listing for efficient processing.
     */
    public function pendingBulk(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();
        $action       = (string) $request->post('action', '');
        $raw          = (array)  $request->post('items', []);

        if (!in_array($action, ['approve', 'reject'], true) || empty($raw)) {
            $session->set('lstr_flash_error', 'No members selected or invalid action.');
            return Response::redirect($baseUrl . '/admin/members?status=pending');
        }

        $listingRepo  = new ListingRepo($db, $config);
        $memberRepo   = new MemberRepo($db, $config);
        $settingsRepo = new ListingSettingsRepo($db);
        $activityRepo = new ActivityRepo($db);
        $mailer       = new Mailer($config);

        // Group by listing ID
        $byListing = [];
        foreach ($raw as $item) {
            $parts = explode(':', (string) $item, 2);
            if (count($parts) !== 2) { continue; }
            [$lid, $email] = $parts;
            $lid = (int) $lid;
            if ($lid > 0 && $email !== '') {
                $byListing[$lid][] = $email;
            }
        }

        $count = 0;
        foreach ($byListing as $listingId => $emails) {
            $listing = $listingRepo->getById($listingId);
            if ($listing === null) { continue; }

            foreach ($emails as $email) {
                if ($action === 'approve') {
                    $memberRepo->approve($listing->dbprefix, $email);

                    $member = $memberRepo->getByEmail($listing->dbprefix, $email);
                    if ($member !== null) {
                        try {
                            $emailRepo = new EmailTemplateRepo($db);
                            $_es       = (new ListingSettingsRepo($db))->getResolvedEmbedSettings($listingId);
                            $tokens    = EmailTemplateRepo::buildTokens(
                                listingName:    $listing->title,
                                listingUrl:     $listing->url,
                                siteName:       (string) $config->get('site_name', 'Luster'),
                                listingSubject: $listing->subject,
                                ownerName:      (string) $config->get('owner_name', ''),
                                ownerEmail:     (string) $config->get('owner_email', ''),
                                fanName:        $member->name,
                                fanEmail:       $member->email,
                                listingEmail:   $listing->email,
                                formUrlJoin:    (string) $_es['form_url_join'],
                                formUrlUpdate:  (string) $_es['form_url_update'],
                                formUrlReset:   (string) $_es['form_url_reset'],
                                listingType:    $settingsRepo->getListingType($listingId),
                            );
                            [$subj, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_APPROVE, $tokens);
                            if ($subj !== '') { $mailer->send($member->email, $member->name, $subj, $body); }
                        } catch (\Throwable) {}
                    }
                } else {
                    $memberRepo->deleteByEmail($listing->dbprefix, $email);
                }
                $count++;
            }

            if ($action === 'approve') {
                $settingsRepo->touchLastUpdated($listingId);
            }
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'member.pending_bulk_' . $action,
            "Cross-listing pending bulk {$action}: {$count} member(s)",
            $request->ip(),
        );

        $label = $action === 'approve' ? "Approved {$count} member(s)." : "Rejected {$count} member(s).";
        $session->set('lstr_flash_success', $label);

        $allListings   = $listingRepo->getAll();
        $globalPending = (new PendingUpdateRepo($db))->countAll();
        foreach ($allListings as $fl) {
            if ($fl->dbprefix !== '') {
                $globalPending += $memberRepo->countPending($fl->dbprefix);
            }
        }
        $redirectStatus = $globalPending > 0 ? 'pending' : 'all';
        return Response::redirect($baseUrl . '/admin/members?status=' . $redirectStatus);
    }

    // -----------------------------------------------------------------------
    // GET /admin/members/{id}/export
    // -----------------------------------------------------------------------

    /**
     * Streams approved members for a single listing as a CSV file download,
     * including all custom additional-field columns.
     */
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

        $filename = 'members_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($listing->title)) . '.csv';

        return Response::csv((string) $csv, $filename);
    }

    /**
     * Streams all pending members across every listing as a single CSV file
     * download. Includes the listing title alongside each member row.
     */
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
            if ($fl->dbprefix === '') {
                continue;
            }

            foreach ($memberRepo->getPending($fl->dbprefix) as $m) {
                fputcsv($handle, [$fl->title, $m->name, $m->email, $m->country, $m->url]);
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
            return Response::redirect($baseUrl . '/admin/members?listing_id=' . $listingId . '&status=all');
        }

        match ($action) {
            'approve' => $memberRepo->approve($listing->dbprefix, $email),
            'pending' => $memberRepo->setPending($listing->dbprefix, $email),
            'reject'  => $memberRepo->reject($listing->dbprefix, $email),
            'delete'  => $memberRepo->deleteByEmail($listing->dbprefix, $email),
        };

        // Sync member link check snapshot (best-effort)
        if (in_array($action, ['reject', 'delete'], true)) {
            try {
                (new MemberLinkCheckRepo($db, $config))->removeMember($listingId, $email);
            } catch (\Throwable) {}
        }

        // Touch last_updated when a member is approved (signals listing activity)
        if ($action === 'approve') {
            (new ListingSettingsRepo($db))->touchLastUpdated($listingId);

            // Approval email (best-effort)
            $member = $memberRepo->getByEmail($listing->dbprefix, $email);
            if ($member !== null) {
                if (($member->url ?? '') !== '') {
                    try {
                        (new MemberLinkCheckRepo($db, $config))->upsertMember($listingId, $member->email, $member->name, $member->url);
                    } catch (\Throwable) {}
                }
                try {
                    $cfg       = $this->app->getConfig();
                    $mailer    = new Mailer($cfg);
                    $emailRepo = new EmailTemplateRepo($db);
                    $_lsr      = new ListingSettingsRepo($db);
                    $_es       = $_lsr->getResolvedEmbedSettings($listingId);
                    $tokens    = EmailTemplateRepo::buildTokens(
                        listingName:    $listing->title,
                        listingUrl:     $listing->url,
                        siteName:       (string) $cfg->get('site_name', 'Luster'),
                        listingSubject: $listing->subject,
                        ownerName:      (string) $cfg->get('owner_name', ''),
                        ownerEmail:     (string) $cfg->get('owner_email', ''),
                        fanName:        $member->name,
                        fanEmail:       $member->email,
                        listingEmail:   $listing->email,
                        formUrlJoin:    (string) $_es['form_url_join'],
                        formUrlUpdate:  (string) $_es['form_url_update'],
                        formUrlReset:   (string) $_es['form_url_reset'],
                        listingType:    $_lsr->getListingType($listingId),
                    );
                    [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_APPROVE, $tokens);
                    if ($subject !== '') { $mailer->send($member->email, $member->name, $subject, $body); }
                } catch (\Throwable $e) {
                    error_log('[Luster] Approve email failed: ' . $e->getMessage());
                }
            }
        }

        $actionPastTense = match ($action) {
            'approve' => 'Approved',
            'reject'  => 'Rejected',
            'delete'  => 'Deleted',
        };

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            "member.{$action}",
            "{$actionPastTense} member {$email} in listing #{$listingId}",
            $request->ip(),
        );

        $label = match ($action) {
            'approve' => 'Member approved.',
            'reject'  => 'Member rejected and removed.',
            'delete'  => 'Member deleted.',
        };

        $session->set('lstr_flash_success', $label);

        $pendingUpdateRepo = new PendingUpdateRepo($db);
        $fromGlobal        = $request->post('_from_global', '') === '1';

        if ($fromGlobal) {
            $allListings   = (new ListingRepo($db, $config))->getAll();
            $globalPending = $pendingUpdateRepo->countAll();
            foreach ($allListings as $fl) {
                if ($fl->dbprefix !== '') {
                    $globalPending += $memberRepo->countPending($fl->dbprefix);
                }
            }
            $redirectStatus = $globalPending > 0 ? 'pending' : 'all';
            return Response::redirect($baseUrl . '/admin/members?status=' . $redirectStatus);
        }

        $remaining      = $memberRepo->countPending($listing->dbprefix) + $pendingUpdateRepo->countByListing($listingId);
        $redirectStatus = $remaining > 0 ? 'pending' : 'all';
        return Response::redirect($baseUrl . '/admin/members?listing_id=' . $listingId . '&status=' . $redirectStatus);
    }

    // -----------------------------------------------------------------------
    // GET /admin/members/settings
    // -----------------------------------------------------------------------

    /**
     * Renders the member display settings page (members per page, etc.).
     */
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
        ], '', [
            $this->crumb('Members', $this->adminUrl('/admin/members')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/settings
    // -----------------------------------------------------------------------

    /**
     * Saves member display settings (per_page) to config/app.php.
     * The 'reset_defaults' POST flag restores the built-in default of 25.
     */
    public function saveSettings(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($request->post('reset_defaults') === '1') {
            $perPage = 25;
            $msg     = 'Settings reset to defaults.';
        } else {
            $perPage = max(5, min(200, (int) $request->post('per_page', 25)));
            $msg     = 'Settings saved successfully.';
        }

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

        $session->set('lstr_flash_success', $msg);
        return Response::redirect($baseUrl . '/admin/members/settings');
    }

    /**
     * Renders the single-member email composer, pre-populated with the member's
     * name and contact details. Supports saved email template load/save.
     */
    public function emailMemberForm(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();
        $id     = (int) $params['id'];

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($id);

        if ($listing === null) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/members');
        }

        $email       = trim((string) $request->query('email', ''));
        $member      = (new MemberRepo($db, $config))->getByEmail($listing->dbprefix, $email);
        $savedEmails = (new \Luster\Repositories\SavedEmailRepo($db))->getAll();
        $baseUrl     = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $csrf          = $this->app->getCSRF()->token();
        $fromEmail = (string) $config->get('owner_email', '');
        $fromName  = (string) $config->get('owner_name',  '');
        $flash_error   = $session->get('lstr_flash_error', '');
        $flash_success = $session->get('lstr_flash_success', '');
        $old           = (array) $session->get('lstr_email_old', []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_email_old');

        $referer = $request->header('referer') ?? $baseUrl . '/admin/members?listing_id=' . $id . '&status=all';

        return $this->adminLayout('admin/members/email.php', 'Email Member', 'members', [
            'listing'        => $listing,
            'listing_type'   => (new ListingSettingsRepo($db))->getListingType($id),
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
        ], '', $this->memberBreadcrumbs($listing, $member ? $member->name : $email));
    }

    /**
     * Sends a one-off email to a single member with per-recipient token
     * substitution ({fan_name}, {listing_title}, etc.) applied to the subject
     * and body before delivery.
     */
    public function emailMemberSend(Request $request, array $params): Response
    {
        $config   = $this->app->getConfig();
        $id       = (int) $params['id'];
        $toEmail         = trim((string) $request->post('member_email', ''));
        $db              = $this->app->getDatabase();
        $listingRepo  = new ListingRepo($db, $config);
        $listing      = $listingRepo->getById($id);
        $flName          = $listing ? $listing->title : '';
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

        $member = $listing !== null ? (new MemberRepo($db, $config))->getByEmail($listing->dbprefix, $toEmail) : null;

        $_es = $listing ? (new ListingSettingsRepo($db))->getResolvedEmbedSettings($id) : ['form_url_join' => '', 'form_url_update' => '', 'form_url_reset' => ''];
        $_formTokens = $listing ? EmailTemplateRepo::buildTokens(
            listingName: '', listingUrl: $listing->url, siteName: '',
            formUrlJoin: (string) $_es['form_url_join'], formUrlUpdate: (string) $_es['form_url_update'], formUrlReset: (string) $_es['form_url_reset'],
        ) : ['{join_url}' => '', '{update_url}' => '', '{form_url_reset}' => ''];

        $search  = ['{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}',
                    '{listing_title}', '{listing_subject}', '{listing_type}', '{listing_url}', '{listing_email}',
                    '{join_url}', '{update_url}', '{form_url_reset}',
                    '{form_url_join}', '{form_url_update}',
                    '{site_name}', '{owner_name}', '{owner_email}'];
        $replace = [
            $member ? $member->name    : '',
            $toEmail,
            $member ? $member->url     : '',
            $member ? $member->country : '',
            $listing ? $listing->title   : '',
            $listing ? $listing->subject : '',
            $listing ? (new ListingSettingsRepo($db))->getListingType($id) : 'listing',
            $listing ? $listing->url     : '',
            $listing ? $listing->email   : '',
            $_formTokens['{join_url}'],
            $_formTokens['{update_url}'],
            $_formTokens['{form_url_reset}'],
            $_formTokens['{join_url}'],
            $_formTokens['{update_url}'],
            (string) $config->get('site_name', 'Luster'),
            (string) $config->get('owner_name', ''),
            (string) $config->get('owner_email', ''),
        ];

        $finalSubject = str_replace($search, $replace, $subject);
        $finalBody    = str_replace($search, $replace, $body);

        try {
            $mailer = new \Luster\Core\Mailer($config);
            $mailer->send($toEmail, $member ? $member->name : '', $finalSubject, $finalBody);
            $session->set('lstr_flash_success', 'Email sent successfully!');
        } catch (\RuntimeException $e) {
            $session->set('lstr_flash_error', 'The email could not be sent. Check that Owner Email is set in Settings and that your server supports outgoing mail.');
            $session->set('lstr_email_old', ['subject' => $suffix, 'body' => $body]);
        }

        return Response::redirect($redirectBack);
    }

    // -----------------------------------------------------------------------
    // GET /admin/members/{id}/email-selected
    // -----------------------------------------------------------------------

    /**
     * Renders the email-selected-members composer. The list of recipient
     * addresses is read from the session key 'lstr_selected_emails', which
     * is written by the bulk() action when 'email_selected' is chosen.
     */
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
            return Response::redirect($baseUrl . '/admin/members?listing_id=' . $id . '&status=all');
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
            'from_name'       => (string) $config->get('owner_name',  ''),
            'from_email'      => (string) $config->get('owner_email', ''),
            'owner_name'      => (string) $config->get('owner_name', ''),
            'owner_email'     => (string) $config->get('owner_email', ''),
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'error'           => $error,
            'success'         => $success,
            'old'             => $old,
        ], '', $this->memberBreadcrumbs($listing));
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/email-selected/send
    // -----------------------------------------------------------------------

    /**
     * Sends the composed email to each selected member with per-recipient token
     * substitution ({fan_name}, {listing_title}, etc.) applied individually.
     * Partial failures are reported without aborting remaining sends.
     */
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
            return Response::redirect($baseUrl . '/admin/members?listing_id=' . $id . '&status=all');
        }

        $memberRepo  = new MemberRepo($db, $config);
        $siteName    = (string) $config->get('site_name', 'Luster');
        $ownerName   = (string) $config->get('owner_name', '');
        $ownerEmail  = (string) $config->get('owner_email', '');
        $mailer      = new Mailer($this->app->getConfig());

        $_es = (new ListingSettingsRepo($db))->getResolvedEmbedSettings($id);
        $_formTokens = EmailTemplateRepo::buildTokens(
            listingName: '', listingUrl: $listing->url, siteName: '',
            formUrlJoin: (string) $_es['form_url_join'], formUrlUpdate: (string) $_es['form_url_update'], formUrlReset: (string) $_es['form_url_reset'],
        );

        $sent   = 0;
        $failed = [];

        foreach ($emails as $email) {
            $member  = $memberRepo->getByEmail($listing->dbprefix, $email);
            $name    = $member ? $member->name    : '';
            $url     = $member ? $member->url     : '';
            $country = $member ? $member->country : '';

            $search  = ['{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}',
                        '{listing_title}', '{listing_subject}', '{listing_type}', '{listing_url}', '{listing_email}',
                        '{join_url}', '{update_url}', '{form_url_reset}',
                        '{form_url_join}', '{form_url_update}',
                        '{site_name}', '{owner_name}', '{owner_email}'];
            $replace = [$name,        $email,        $url,        $country,
                        $listing->title, $listing->subject, (new ListingSettingsRepo($db))->getListingType($id), $listing->url, $listing->email,
                        $_formTokens['{join_url}'], $_formTokens['{update_url}'], $_formTokens['{form_url_reset}'],
                        $_formTokens['{join_url}'], $_formTokens['{update_url}'],
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
            "Sent email to {$sent} selected member(s) in listing #{$id} ({$listing->title}). Subject: {$subject}",
            $request->ip(),
        );

        if (!empty($failed)) {
            $session->set('lstr_flash_error', "Sent to {$sent} member(s). Failed for: " . implode(', ', $failed));
        } else {
            $session->set('lstr_flash_success', "Email sent to {$sent} member(s).");
        }

        return Response::redirect($baseUrl . '/admin/members?listing_id=' . $id . '&status=all');
    }

    // -----------------------------------------------------------------------
    // POST /admin/members/{id}/email-selected/save-template
    // -----------------------------------------------------------------------

    /**
     * Saves a reusable email template from the email-selected composer to
     * lstr_saved_emails, then restores the compose state (recipient list +
     * draft fields) so the user can continue composing after saving.
     */
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

    /**
     * @return array<int,array{label:string,url?:string|null}>
     */
    private function memberBreadcrumbs(object $listing, ?string $memberLabel = null, bool $includeMembers = true): array
    {
        if (!$includeMembers) {
            return [
                $this->crumb('Owned Listings', $this->adminUrl('/admin/listings')),
                $this->crumb($listing->subject),
            ];
        }

        $crumbs = [
            $this->crumb($listing->subject, $this->adminUrl('/admin/listings/' . $listing->id . '/edit')),
        ];

        if ($memberLabel !== null && trim($memberLabel) !== '') {
            $crumbs[] = $this->crumb('Members', $this->adminUrl('/admin/members?listing_id=' . $listing->id . '&status=all'));
            $crumbs[] = $this->crumb($memberLabel);
        } else {
            $crumbs[] = $this->crumb('Members');
        }

        return $crumbs;
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
            unlink($tmpPath);
            throw new \RuntimeException('Could not rename temp config file to: ' . $configPath);
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configPath, true);
        }
    }
}
