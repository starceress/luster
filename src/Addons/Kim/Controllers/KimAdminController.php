<?php

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMember;
use Luster\Addons\Kim\KimMemberRepo;
use Luster\Controllers\Admin\AdminController;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Admin KIM management controller.
 *
 * All state-changing actions validate CSRF (via CsrfMiddleware) and log to ActivityRepo.
 */
final class KimAdminController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/kim  — unified tabbed member view
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $kimRepo     = new KimMemberRepo($db);
        $listingRepo = new ListingRepo($db, $config);
        $settings    = new SiteSettingsRepo($db);

        // Honour the default-landing preference when hitting /admin/kim bare (no params)
        $isBareNavigation = $request->query('tab', null) === null
                         && $request->query('listing_id', null) === null
                         && $request->query('search', null) === null
                         && $request->query('sort', null) === null
                         && $request->query('page', null) === null;

        if ($isBareNavigation && $settings->get('kim_default_landing', 'members') === 'listings') {
            return Response::redirect($this->app->getBaseUrl() . '/admin/kim/listings');
        }

        $perPage   = max(5, min(25, (int) ($settings->get('kim_per_page', '10') ?? '10')));
        $listingId = max(0, (int) $request->query('listing_id', 0));
        $search    = trim((string) $request->query('search', ''));

        $sortParam = trim((string) $request->query('sort', 'added_at'));
        $dirParam  = trim((string) $request->query('dir',  'desc'));
        if (!in_array($sortParam, ['name', 'email', 'status', 'listing', 'added_at'], true)) {
            $sortParam = 'added_at';
        }
        if (!in_array($dirParam, ['asc', 'desc'], true)) {
            $dirParam = 'desc';
        }

        // Tab counts (no tab filter, just listing + search context)
        $counts = $kimRepo->getTabCounts($listingId, $search);

        // Global pending count for module nav badge (avoid extra query when unfiltered)
        $globalPending = ($listingId === 0 && $search === '')
            ? $counts['pending']
            : $kimRepo->getTabCounts(0, '')['pending'];

        // Resolve active tab — only auto-default to 'pending' when no tab param provided
        $tabParam = trim((string) $request->query('tab', ''));
        if ($tabParam === '') {
            $tab = $counts['pending'] > 0 ? 'pending' : 'all';
        } elseif (in_array($tabParam, ['all', 'approved', 'pending', 'granted'], true)) {
            $tab = $tabParam;
        } else {
            $tab = 'all';
        }

        $total      = $counts[$tab];
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min(max(1, (int) $request->query('page', 1)), $totalPages);
        $offset     = ($page - 1) * $perPage;

        $members          = $kimRepo->getFiltered($listingId, $tab, $search, $perPage, $offset, $sortParam, $dirParam);
        $fanlistings      = $listingRepo->getAll();
        $kimListingCounts = $kimRepo->getCountsByListing();

        $listingMap      = [];
        $selectedListing = null;
        foreach ($fanlistings as $fl) {
            $listingMap[$fl->id] = $fl;
            if ($fl->id === $listingId) {
                $selectedListing = $fl;
            }
        }

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/kim/index.php', 'Keep In Mind', 'kim', [
            'members'          => $members,
            'fanlistings'      => $fanlistings,
            'listing_map'      => $listingMap,
            'selected_listing' => $selectedListing,
            'listing_id'       => $listingId,
            'tab'              => $tab,
            'counts'           => $counts,
            'kim_listing_counts' => $kimListingCounts,
            'search'           => $search,
            'filters_active'   => $search !== '' || $listingId > 0,
            'page'             => $page,
            'total_pages'      => $totalPages,
            'total'            => $total,
            'per_page'         => $perPage,
            'sort'             => $sortParam,
            'dir'              => $dirParam,
            'global_pending'   => $globalPending,
            'kim_section'      => 'members',
            'success'          => $success,
            'error'            => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/listings  — per-listing overview table
    // -----------------------------------------------------------------------

    public function listings(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $kimRepo     = new KimMemberRepo($db);
        $settings    = new SiteSettingsRepo($db);

        $fanlistings        = $listingRepo->getAll();
        $countsByFanlisting = $kimRepo->getAllCountsByFanlisting();
        $globalPending      = $kimRepo->getTabCounts(0, '')['pending'];

        $fanlistingsData = [];
        foreach ($fanlistings as $fl) {
            $c = $countsByFanlisting[$fl->id] ?? ['pending' => 0, 'approved' => 0, 'granted' => 0];
            $fanlistingsData[] = [
                'listing'  => $fl,
                'pending'  => $c['pending'],
                'approved' => $c['approved'],
                'granted'  => $c['granted'],
            ];
        }

        $sort = trim((string) $request->query('sort', 'subject'));
        $dir  = trim((string) $request->query('dir',  'asc'));
        if (!in_array($sort, ['subject', 'pending'], true)) { $sort = 'subject'; }
        if (!in_array($dir,  ['asc', 'desc'], true))        { $dir  = 'asc'; }

        usort($fanlistingsData, static function (array $a, array $b) use ($sort, $dir): int {
            if ($sort === 'pending') {
                $diff = $a['pending'] - $b['pending'];
                return $dir === 'asc' ? $diff : -$diff;
            }
            $cmp = strcasecmp($a['listing']->subject, $b['listing']->subject);
            return $dir === 'asc' ? $cmp : -$cmp;
        });

        $totalListings   = count($fanlistingsData);
        $perPage         = max(5, min(25, (int) ($settings->get('kim_per_page', '10') ?? '10')));
        $pages           = $totalListings > 0 ? (int) ceil($totalListings / $perPage) : 1;
        $page            = min(max(1, (int) $request->query('page', 1)), $pages);
        $fanlistingsData = array_slice($fanlistingsData, ($page - 1) * $perPage, $perPage);

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/kim/listings.php', 'Keep In Mind — Listings', 'kim', [
            'fanlistings_data' => $fanlistingsData,
            'total_listings'   => $totalListings,
            'sort'             => $sort,
            'dir'              => $dir,
            'page'             => $page,
            'pages'            => $pages,
            'per_page'         => $perPage,
            'global_pending'   => $globalPending,
            'kim_section'      => 'listings',
            'success'          => $success,
            'error'            => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/bulk  — cross-listing bulk actions
    // -----------------------------------------------------------------------

    public function bulkGlobal(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action = (string) $request->post('bulk_action', '');
        $rawIds = $request->post('ids', []);
        $ids    = array_values(array_filter(
            array_map('intval', is_array($rawIds) ? $rawIds : []),
            static fn(int $id) => $id > 0
        ));

        // Validated return URL — must stay within the KIM admin area
        $returnTo = trim((string) $request->post('_return', ''));
        if ($returnTo === '' || !str_starts_with($returnTo, $baseUrl . '/admin/kim')) {
            $returnTo = $baseUrl . '/admin/kim';
        }

        if (empty($ids)) {
            $session->set('lstr_flash_error', 'No members selected.');
            return Response::redirect($returnTo);
        }

        $kimRepo      = new KimMemberRepo($db);
        $activityRepo = new ActivityRepo($db);
        $adminId      = (int) $session->get('lstr_admin_id');
        $count        = count($ids);

        if ($action === 'email_selected') {
            $qs = http_build_query(['ids' => array_values($ids), '_return' => $returnTo]);
            return Response::redirect($baseUrl . '/admin/kim/email-selected?' . $qs);
        }

        match ($action) {
            'approve' => (function () use ($kimRepo, $activityRepo, $ids, $adminId, $count, $request, $session): void {
                $kimRepo->approveByIds($ids);
                $activityRepo->log($adminId, 'kim.bulk_approve', "Bulk approved {$count} KIM member(s)", $request->ip());
                $session->set('lstr_flash_success', "Approved {$count} member(s).");
            })(),
            'delete' => (function () use ($kimRepo, $activityRepo, $ids, $adminId, $count, $request, $session): void {
                $kimRepo->deleteByIds($ids);
                $activityRepo->log($adminId, 'kim.bulk_delete', "Bulk deleted {$count} KIM member(s)", $request->ip());
                $session->set('lstr_flash_success', "Deleted {$count} member(s).");
            })(),
            default => $session->set('lstr_flash_error', 'Unknown bulk action.'),
        };

        return Response::redirect($returnTo);
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/{fid}/show
    // -----------------------------------------------------------------------

    public function show(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        return Response::redirect($this->app->getBaseUrl() . '/admin/kim?listing_id=' . $fanlistingId);
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/kim/{id}/edit
    // -----------------------------------------------------------------------

    public function edit(Request $request, array $params): Response
    {
        $memberId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();
        $config   = $this->app->getConfig();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $listingRepo = new ListingRepo($db, $config);
        $fanlistings = $listingRepo->getAll();

        // Find listing name for breadcrumb
        $fanlistingName = '';
        foreach ($fanlistings as $fl) {
            if ($fl->id === $member->fanlistingId) {
                $fanlistingName = $fl->subject;
                break;
            }
        }

        if ($request->method() === 'GET') {
            $error = (string) $session->get('lstr_flash_error', '');
            $session->remove('lstr_flash_error');

            return $this->adminLayout('admin/addons/kim/edit.php', 'Edit Keep In Mind Member', 'kim', [
                'member'          => $member,
                'fanlistings'     => $fanlistings,
                'fanlisting_name' => $fanlistingName,
                'error'           => $error,
            ], '', array_values(array_filter([
                $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
                $fanlistingName !== '' ? $this->crumb($fanlistingName, $this->adminUrl('/admin/kim?listing_id=' . $member->fanlistingId)) : null,
                $this->crumb($member->name),
            ])));
        }

        // POST — validate and update
        $name        = trim((string) $request->post('name',         ''));
        $email       = trim((string) $request->post('email',        ''));
        $url         = trim((string) $request->post('url',          ''));
        $fanlistingId = (int) $request->post('fanlisting_id',        0);

        $fanlistingIds = array_map(static fn($fl) => $fl->id, $fanlistings);

        $v = new Validator([
            'name'         => $name,
            'email'        => $email,
            'url'          => $url,
            'fanlisting_id' => (string) $fanlistingId,
        ]);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->httpUrl()->maxLength(500);
        $v->field('fanlisting_id')->required();

        $validationFailed = $v->fails();
        if (!$validationFailed && ($fanlistingId <= 0 || !in_array($fanlistingId, $fanlistingIds, true))) {
            $validationFailed = true;
        }

        if ($validationFailed) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()) ?: 'Invalid input.');
            return Response::redirect($baseUrl . '/admin/kim/' . $memberId . '/edit');
        }

        $granted = $request->post('granted', '0') === '1' ? 1 : 0;

        $updateFields = [
            'name'          => $name,
            'email'         => $email,
            'url'           => $url,
            'fanlisting_id' => $fanlistingId,
            'granted'       => $granted,
        ];

        $newPassword        = (string) $request->post('new_password',         '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');

        if ($newPassword !== '') {
            $pwv = new Validator(['new_password' => $newPassword]);
            PasswordPolicy::applyOptional($pwv->field('new_password'), $newPassword);
            if ($pwv->fails()) {
                $session->set('lstr_flash_error', implode(' ', $pwv->allErrors()));
                return Response::redirect($baseUrl . '/admin/kim/' . $memberId . '/edit');
            }
            if ($newPassword !== $newPasswordConfirm) {
                $session->set('lstr_flash_error', 'Passwords do not match.');
                return Response::redirect($baseUrl . '/admin/kim/' . $memberId . '/edit');
            }
            $updateFields['password'] = password_hash($newPassword, PASSWORD_ARGON2ID);
        }

        $kimRepo->update($memberId, $updateFields);

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.edit',
            "Edited KIM member #{$memberId} ({$email})",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'KIM member updated.');
        return Response::redirect($baseUrl . '/admin/kim?listing_id=' . $fanlistingId);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{id}/approve
    // -----------------------------------------------------------------------

    public function approve(Request $request, array $params): Response
    {
        $memberId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $kimRepo->approve($memberId);

        // Send approval email to KIM member
        try {
            $config      = $this->app->getConfig();
            $listingRepo = new ListingRepo($db, $config);
            $listing     = $listingRepo->getById($member->fanlistingId);
            $emailRepo   = new EmailTemplateRepo($db);
            $tokens      = EmailTemplateRepo::buildKimTokens(
                listingName : $listing?->title ?? '',
                listingUrl  : $listing?->url   ?? '',
                siteName    : (string) $config->get('site_name',   ''),
                ownerName   : (string) $config->get('owner_name',  ''),
                ownerEmail  : (string) $config->get('owner_email', ''),
                kimName     : $member->name,
                kimEmail    : $member->email,
                kimUrl      : $member->url,
            );
            [$subject, $body] = $emailRepo->resolveForListing(
                $member->fanlistingId,
                EmailTemplateRepo::TYPE_KIM_APPROVED,
                $tokens,
            );
            (new Mailer($config))->send($member->email, $member->name, $subject, $body);
        } catch (\Throwable) {
            // Email failures are non-fatal
        }

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.approve',
            "Approved KIM member #{$memberId} ({$member->email}) for fanlisting {$member->fanlistingId}",
            $request->ip(),
        );

        $fromFid  = (int) $request->post('from_fid', $member->fanlistingId);
        $session->set('lstr_flash_success', 'KIM member approved.');
        $tab = $kimRepo->getTabCounts($fromFid, '')['pending'] > 0 ? 'pending' : 'all';
        return Response::redirect($baseUrl . '/admin/kim?listing_id=' . $fromFid . '&tab=' . $tab);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $memberId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $fanlistingId = $member->fanlistingId;
        $kimRepo->delete($memberId);

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.delete',
            "Deleted KIM member #{$memberId} ({$member->email}) for fanlisting {$fanlistingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'KIM member deleted.');
        return Response::redirect($baseUrl . '/admin/kim?listing_id=' . $fanlistingId);
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/{fid}/bulk-email
    // -----------------------------------------------------------------------

    public function massEmailForm(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $session      = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($fanlistingId);

        if ($listing === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $kimRepo     = new KimMemberRepo($db);
        $selectedIds = (array) $session->get('lstr_kim_selected_ids', []);
        $session->remove('lstr_kim_selected_ids');

        if (!empty($selectedIds)) {
            // Selected mode: use ID-keyed map (supports chip removal by ID)
            $idEmailMap    = $kimRepo->getEmailsByIds(array_map('intval', $selectedIds), $fanlistingId);
            $allRecipients = [];
            $selectedOnly  = true;
        } else {
            // Bulk mode: all approved members; no chip removal UI
            $idEmailMap    = [];
            $allRecipients = $kimRepo->getEmailsByFanlisting($fanlistingId);
            $selectedOnly  = false;
        }

        $recipients  = $selectedOnly ? array_values($idEmailMap) : $allRecipients;
        $memberNames = array_combine(
            array_column($recipients, 'email'),
            array_column($recipients, 'name'),
        ) ?: [];

        $fromEmail = (string) $config->get('owner_email', '');
        $fromName  = (string) $config->get('owner_name',  '');

        $savedEmails = (new \Luster\Repositories\SavedEmailRepo($db))->getAll();
        $error   = (string) $session->get('lstr_flash_error',   '');
        $success = (string) $session->get('lstr_flash_success', '');
        $old     = (array)  $session->get('lstr_email_old',     []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_email_old');

        return $this->adminLayout('admin/addons/kim/bulk-email.php', 'Bulk Email', 'kim', [
            'listing'         => $listing,
            'id_email_map'    => $idEmailMap,
            'all_recipients'  => $allRecipients,
            'recipient_count' => count($recipients),
            'selected_only'   => $selectedOnly,
            'member_names'    => $memberNames,
            'from_name'       => $fromName,
            'from_email'      => $fromEmail,
            'owner_name'      => (string) $config->get('owner_name',  ''),
            'owner_email'     => (string) $config->get('owner_email', ''),
            'site_name'       => (string) $config->get('site_name',   ''),
            'saved_emails'    => $savedEmails,
            'old'             => $old,
            'error'           => $error,
            'success'         => $success,
        ], '', [
            $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
            $this->crumb($listing->subject, $this->adminUrl('/admin/kim?listing_id=' . $listing->id)),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{fid}/bulk-email
    // -----------------------------------------------------------------------

    public function massEmail(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($fanlistingId);

        if ($listing === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $subject = trim((string) $request->post('subject', ''));
        $body    = trim((string) $request->post('body',    ''));

        $kimRepo  = new KimMemberRepo($db);
        $postedIds = array_values(array_filter(
            array_map('intval', (array) $request->post('kim_member_ids', [])),
            static fn(int $id) => $id > 0
        ));

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Please fill in both the subject and message before sending.');
            $session->set('lstr_email_old', ['subject' => $subject, 'body' => $body]);
            if (!empty($postedIds)) {
                $session->set('lstr_kim_selected_ids', $postedIds);
            }
            return Response::redirect($baseUrl . '/admin/kim/' . $fanlistingId . '/bulk-email');
        }

        if (!empty($postedIds)) {
            $recipients = array_values($kimRepo->getEmailsByIds($postedIds, $fanlistingId));
        } else {
            $recipients = $kimRepo->getEmailsByFanlisting($fanlistingId);
        }

        $siteName   = (string) $config->get('site_name', 'Luster');
        $ownerName  = (string) $config->get('owner_name', '');
        $ownerEmail = (string) $config->get('owner_email', '');

        $search = ['{name}', '{email}', '{url}', '{added_at}',
                   '{listing_subject}', '{listing_url}',
                   '{site_name}', '{owner_name}', '{owner_email}'];

        $mailer = new Mailer($this->app->getConfig());
        $sent   = 0;
        $failed = 0;

        foreach ($recipients as $r) {
            $replace = [$r['name'], $r['email'], $r['url'], $r['added_at'],
                        $listing->subject, $listing->url,
                        $siteName, $ownerName, $ownerEmail];

            $personalSubject = str_replace($search, $replace, $subject);
            $personalBody    = str_replace($search, $replace, $body);

            try {
                $mailer->send($r['email'], $r['name'], $personalSubject, $personalBody);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                error_log('[KIM] Bulk email failed for ' . $r['email'] . ': ' . $e->getMessage());
            }
        }

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.bulk_email',
            "Sent bulk email to KIM members for fanlisting {$fanlistingId}: {$sent} sent, {$failed} failed",
            $request->ip(),
        );

        $session->set(
            'lstr_flash_success',
            "Bulk email sent: {$sent} delivered" . ($failed > 0 ? ", {$failed} failed" : '') . '.'
        );
        return Response::redirect($baseUrl . '/admin/kim?listing_id=' . $fanlistingId);
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/{id}/email-member
    // -----------------------------------------------------------------------

    public function emailMemberForm(Request $request, array $params): Response
    {
        $memberId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();
        $config   = $this->app->getConfig();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($member->fanlistingId);

        $fromEmail = (string) $config->get('owner_email', '');
        $fromName  = (string) $config->get('owner_name',  '');

        $savedEmails = (new \Luster\Repositories\SavedEmailRepo($db))->getAll();
        $error   = (string) $session->get('lstr_flash_error',   '');
        $success = (string) $session->get('lstr_flash_success', '');
        $old     = (array)  $session->get('lstr_email_old',     []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_email_old');

        $rawReferer = $request->header('referer') ?? '';
        $referer = ($rawReferer !== '' && \Luster\Core\UrlGuard::isSameOriginUrl($rawReferer, $baseUrl))
            ? $rawReferer
            : $baseUrl . '/admin/kim/' . $member->fanlistingId . '/show';

        return $this->adminLayout('admin/addons/kim/email-member.php', 'Email Keep In Mind Member', 'kim', [
            'member'       => $member,
            'listing'      => $listing,
            'from_email'   => $fromEmail,
            'from_name'    => $fromName,
            'owner_name'   => (string) $config->get('owner_name',  ''),
            'owner_email'  => (string) $config->get('owner_email', ''),
            'site_name'    => (string) $config->get('site_name',   ''),
            'saved_emails' => $savedEmails,
            'old'          => $old,
            'referer'      => $referer,
            'error'        => $error,
            'success'      => $success,
        ], '', array_values(array_filter([
            $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
            $listing !== null ? $this->crumb($listing->subject, $this->adminUrl('/admin/kim?listing_id=' . $listing->id)) : null,
            $this->crumb($member->name),
        ])));
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{id}/email-member
    // -----------------------------------------------------------------------

    public function emailMember(Request $request, array $params): Response
    {
        $memberId = (int) ($params['id'] ?? 0);
        $db       = $this->app->getDatabase();
        $config   = $this->app->getConfig();
        $session  = $this->app->getSession();
        $baseUrl  = $this->app->getBaseUrl();

        $kimRepo = new KimMemberRepo($db);
        $member  = $kimRepo->findById($memberId);

        if ($member === null) {
            return Response::notFound('KIM member not found.');
        }

        $suffix  = trim((string) $request->post('subject_suffix', ''));
        $body    = trim((string) $request->post('body',           ''));

        $config  = $this->app->getConfig();
        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($member->fanlistingId);
        $flSubject   = $listing ? $listing->subject : '';
        $subject     = $flSubject !== '' ? $flSubject . ': ' . $suffix : $suffix;

        $redirectBack = $baseUrl . '/admin/kim/' . $memberId . '/email-member';

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Please fill in both the subject and message before sending.');
            $session->set('lstr_email_old', ['subject' => $suffix, 'body' => $body]);
            return Response::redirect($redirectBack);
        }

        $search  = ['{name}', '{email}', '{url}', '{added_at}',
                    '{listing_subject}', '{listing_url}',
                    '{site_name}', '{owner_name}', '{owner_email}'];
        $replace = [
            $member->name, $member->email, $member->url, $member->addedAt,
            $listing ? $listing->subject : '', $listing ? $listing->url : '',
            (string) $config->get('site_name', 'Luster'),
            (string) $config->get('owner_name', ''),
            (string) $config->get('owner_email', ''),
        ];

        $finalSubject = str_replace($search, $replace, $subject);
        $finalBody    = str_replace($search, $replace, $body);

        try {
            $mailer = new Mailer($this->app->getConfig());
            $mailer->send($member->email, $member->name, $finalSubject, $finalBody);
            $session->set('lstr_flash_success', 'Email sent to ' . $member->name . '.');
        } catch (\Throwable $e) {
            error_log('[KIM] Email member failed for ' . $member->email . ': ' . $e->getMessage());
            $session->set('lstr_flash_error', 'The email could not be sent. Check that Owner Email is set in Settings and that your server supports outgoing mail.');
            $session->set('lstr_email_old', ['subject' => $suffix, 'body' => $body]);
            return Response::redirect($redirectBack);
        }

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.email_member',
            "Sent email to KIM member #{$memberId} ({$member->email})",
            $request->ip(),
        );

        return Response::redirect($redirectBack);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/{fid}/bulk
    // -----------------------------------------------------------------------

    public function bulk(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['fid'] ?? 0);
        $db           = $this->app->getDatabase();
        $config       = $this->app->getConfig();
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        $listingRepo = new ListingRepo($db, $config);
        if ($listingRepo->getById($fanlistingId) === null) {
            return Response::notFound('Fanlisting not found.');
        }

        $action  = (string) $request->post('bulk_action', '');
        $rawIds  = $request->post('ids', []);
        $ids     = array_map('intval', is_array($rawIds) ? $rawIds : []);
        $ids     = array_filter($ids, static fn(int $id) => $id > 0);

        if (empty($ids)) {
            $session->set('lstr_flash_error', 'No members selected.');
            return Response::redirect($baseUrl . '/admin/kim?listing_id=' . $fanlistingId);
        }

        $kimRepo      = new KimMemberRepo($db);
        $activityRepo = new ActivityRepo($db);
        $adminId      = (int) $session->get('lstr_admin_id');
        $count        = count($ids);

        if ($action === 'email_selected') {
            $session->set('lstr_kim_selected_ids', array_values($ids));
            return Response::redirect($baseUrl . '/admin/kim/' . $fanlistingId . '/bulk-email');
        }

        match ($action) {
            'approve' => (function () use ($kimRepo, $activityRepo, $ids, $fanlistingId, $adminId, $count, $request, $session): void {
                $kimRepo->approveByIds($ids);
                $activityRepo->log($adminId, 'kim.bulk_approve', "Bulk approved {$count} KIM member(s) for fanlisting {$fanlistingId}", $request->ip());
                $session->set('lstr_flash_success', "Approved {$count} member(s).");
            })(),
            'delete' => (function () use ($kimRepo, $activityRepo, $ids, $fanlistingId, $adminId, $count, $request, $session): void {
                $kimRepo->deleteByIds($ids);
                $activityRepo->log($adminId, 'kim.bulk_delete', "Bulk deleted {$count} KIM member(s) for fanlisting {$fanlistingId}", $request->ip());
                $session->set('lstr_flash_success', "Deleted {$count} member(s).");
            })(),
            default => $session->set('lstr_flash_error', 'Unknown bulk action.'),
        };

        $tab = $kimRepo->getTabCounts($fanlistingId, '')['pending'] > 0 ? 'pending' : 'all';
        return Response::redirect($baseUrl . '/admin/kim?listing_id=' . $fanlistingId . '&tab=' . $tab);
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/settings
    // -----------------------------------------------------------------------

    public function showSettings(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();

        $siteSettings = new SiteSettingsRepo($db);
        $kimUrls = $siteSettings->getMany([
            'kim_url_signup', 'kim_url_update', 'kim_url_reset', 'kim_url_list',
        ]);
        $kimPerPage       = max(5, min(25, (int) ($siteSettings->get('kim_per_page', '10') ?? '10')));
        $kimDefaultLanding = in_array($siteSettings->get('kim_default_landing', 'members'), ['members', 'listings'], true)
            ? $siteSettings->get('kim_default_landing', 'members')
            : 'members';

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $globalPending = (new KimMemberRepo($db))->getTabCounts(0, '')['pending'];

        return $this->adminLayout('admin/addons/kim/settings.php', 'Keep In Mind Settings', 'kim', [
            'kim_urls'              => $kimUrls,
            'kim_per_page'          => $kimPerPage,
            'kim_default_landing'   => $kimDefaultLanding,
            'global_pending'        => $globalPending,
            'kim_section'           => 'settings',
            'success'               => $success,
            'error'                 => $error,
        ], '', [
            $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/settings
    // -----------------------------------------------------------------------

    public function saveSettings(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $siteSettings = new SiteSettingsRepo($db);

        if ($request->post('reset_defaults') === '1') {
            $siteSettings->set('kim_url_signup', 'kim.php');
            $siteSettings->set('kim_url_update', 'kim-update.php');
            $siteSettings->set('kim_url_reset',  'kim-reset.php');
            $siteSettings->set('kim_url_list',   'kim-list.php');
            $siteSettings->set('kim_per_page', '10');
            $siteSettings->set('kim_default_landing', 'members');
            $session->set('lstr_flash_success', 'KIM settings reset to defaults.');
            return Response::redirect($baseUrl . '/admin/kim/settings');
        }

        foreach (['kim_url_signup', 'kim_url_update', 'kim_url_reset', 'kim_url_list'] as $key) {
            $field = str_replace('kim_url_', '', $key);
            $value = trim((string) $request->post($field, ''));
            $siteSettings->set($key, $value !== '' ? $value : null);
        }

        $siteSettings->set('kim_per_page', (string) max(5, min(25, (int) $request->post('kim_per_page', 10))));

        $landing = $request->post('kim_default_landing', 'members');
        $siteSettings->set('kim_default_landing', $landing === 'listings' ? 'listings' : 'members');

        $session->set('lstr_flash_success', 'KIM settings saved.');
        return Response::redirect($baseUrl . '/admin/kim/settings');
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/add
    // -----------------------------------------------------------------------

    public function addForm(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $fanlistings = $listingRepo->getAll();

        $error = (string) $session->get('lstr_flash_error', '');
        $old   = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        return $this->adminLayout('admin/addons/kim/add.php', 'Add KIM Entry', 'kim', [
            'fanlistings' => $fanlistings,
            'old'         => $old,
            'error'       => $error,
        ], '', [
            $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/add
    // -----------------------------------------------------------------------

    public function add(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $name         = trim((string) $request->post('name',         ''));
        $email        = trim((string) $request->post('email',        ''));
        $url          = trim((string) $request->post('url',          ''));
        $fanlistingId = (int) $request->post('fanlisting_id', 0);
        $approveNow   = $request->post('approve_now', '0') === '1';
        $newPassword  = (string) $request->post('password', '');

        $listingRepo   = new ListingRepo($db, $config);
        $fanlistings   = $listingRepo->getAll();
        $fanlistingIds = array_map(static fn($fl) => $fl->id, $fanlistings);

        $v = new Validator([
            'name'         => $name,
            'email'        => $email,
            'url'          => $url,
            'fanlisting_id'=> (string) $fanlistingId,
        ]);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->httpUrl()->maxLength(500);
        $v->field('fanlisting_id')->required();

        $validationFailed = $v->fails();
        if (!$validationFailed && ($fanlistingId <= 0 || !in_array($fanlistingId, $fanlistingIds, true))) {
            $validationFailed = true;
        }

        if ($newPassword !== '') {
            $pwv = new Validator(['password' => $newPassword]);
            PasswordPolicy::applyOptional($pwv->field('password'), $newPassword);
            if ($pwv->fails()) {
                $session->set('lstr_flash_error', implode(' ', $pwv->allErrors()));
                $session->set('lstr_flash_old', ['name' => $name, 'email' => $email, 'url' => $url, 'fanlisting_id' => $fanlistingId]);
                return Response::redirect($baseUrl . '/admin/kim/add');
            }
        }

        if ($validationFailed) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()) ?: 'Invalid input.');
            $session->set('lstr_flash_old', ['name' => $name, 'email' => $email, 'url' => $url, 'fanlisting_id' => $fanlistingId]);
            return Response::redirect($baseUrl . '/admin/kim/add');
        }

        $kimRepo = new KimMemberRepo($db);

        if ($kimRepo->findByEmailAndFanlisting($email, $fanlistingId) !== null) {
            $session->set('lstr_flash_error', 'This email address is already registered for that fanlisting.');
            $session->set('lstr_flash_old', ['name' => $name, 'email' => $email, 'url' => $url, 'fanlisting_id' => $fanlistingId]);
            return Response::redirect($baseUrl . '/admin/kim/add');
        }

        $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_ARGON2ID) : '';
        $newId = $kimRepo->create($fanlistingId, $name, $email, $url, $passwordHash);

        if ($approveNow) {
            $kimRepo->approve($newId);
        }

        $activityRepo = new ActivityRepo($db);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'kim.add',
            "Admin added KIM entry #{$newId} ({$email}) for fanlisting {$fanlistingId}" . ($approveNow ? ' (approved immediately)' : ''),
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'KIM entry added' . ($approveNow ? ' and approved.' : ' as pending.'));
        return Response::redirect($baseUrl . '/admin/kim?listing_id=' . $fanlistingId);
    }

    // -----------------------------------------------------------------------
    // GET /admin/kim/email-selected  — cross-listing email-selected compose
    // -----------------------------------------------------------------------

    public function emailSelectedGlobalForm(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $returnUrl = trim((string) $request->query('_return', $baseUrl . '/admin/kim'));
        if (!str_starts_with($returnUrl, $baseUrl . '/admin/kim')) {
            $returnUrl = $baseUrl . '/admin/kim';
        }

        $rawIds = array_filter(
            array_map('intval', (array) $request->query('ids', [])),
            static fn(int $id): bool => $id > 0
        );

        if (empty($rawIds)) {
            $session->set('lstr_flash_error', 'No members selected.');
            return Response::redirect($returnUrl);
        }

        $kimRepo    = new KimMemberRepo($db);
        $idEmailMap = $kimRepo->getEmailsByIds(array_values($rawIds));

        if (empty($idEmailMap)) {
            $session->set('lstr_flash_error', 'No valid members selected.');
            return Response::redirect($returnUrl);
        }

        // Pre-load all referenced listings for per-recipient token preview
        $listingRepo = new ListingRepo($db, $config);
        $listingIds  = array_unique(array_column(array_values($idEmailMap), 'fanlisting_id'));
        $listingMap  = [];
        foreach ($listingIds as $lid) {
            $fl = $listingRepo->getById($lid);
            if ($fl !== null) {
                $listingMap[$lid] = $fl;
            }
        }

        $memberNames = array_combine(
            array_column(array_values($idEmailMap), 'email'),
            array_column(array_values($idEmailMap), 'name'),
        ) ?: [];

        $fromEmail   = (string) $config->get('owner_email', '');
        $fromName    = (string) $config->get('owner_name',  '');
        $savedEmails = (new \Luster\Repositories\SavedEmailRepo($db))->getAll();

        $error   = (string) $session->get('lstr_flash_error',   '');
        $success = (string) $session->get('lstr_flash_success', '');
        $old     = (array)  $session->get('lstr_email_old',     []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_email_old');

        return $this->adminLayout('admin/addons/kim/email-selected.php', 'Email Selected KIM Members', 'kim', [
            'id_email_map'   => $idEmailMap,
            'listing_map'    => $listingMap,
            'recipient_count'=> count($idEmailMap),
            'member_names'   => $memberNames,
            'from_name'      => $fromName,
            'from_email'     => $fromEmail,
            'owner_name'     => (string) $config->get('owner_name',  ''),
            'owner_email'    => (string) $config->get('owner_email', ''),
            'site_name'      => (string) $config->get('site_name',   ''),
            'saved_emails'   => $savedEmails,
            'return_url'     => $returnUrl,
            'old'            => $old,
            'error'          => $error,
            'success'        => $success,
        ], '', [
            $this->crumb('Keep In Mind', $this->adminUrl('/admin/kim')),
            $this->crumb('Email Selected'),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/kim/email-selected  — cross-listing email-selected send
    // -----------------------------------------------------------------------

    public function emailSelectedGlobalSend(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $returnUrl = trim((string) $request->post('_return', $baseUrl . '/admin/kim'));
        if (!str_starts_with($returnUrl, $baseUrl . '/admin/kim')) {
            $returnUrl = $baseUrl . '/admin/kim';
        }

        $rawIds = array_filter(
            array_map('intval', (array) $request->post('kim_member_ids', [])),
            static fn(int $id): bool => $id > 0
        );

        $subject = trim((string) $request->post('subject', ''));
        $body    = trim((string) $request->post('body',    ''));

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Please fill in both the subject and message before sending.');
            $session->set('lstr_email_old', ['subject' => $subject, 'body' => $body]);
            $qs = http_build_query(['ids' => array_values($rawIds), '_return' => $returnUrl]);
            return Response::redirect($baseUrl . '/admin/kim/email-selected?' . $qs);
        }

        if (empty($rawIds)) {
            $session->set('lstr_flash_error', 'No recipients selected.');
            return Response::redirect($returnUrl);
        }

        $kimRepo     = new KimMemberRepo($db);
        $listingRepo = new ListingRepo($db, $config);
        $recipients  = array_values($kimRepo->getEmailsByIds(array_values($rawIds)));

        if (empty($recipients)) {
            $session->set('lstr_flash_error', 'No valid recipients found. The selected members may have been deleted.');
            return Response::redirect($returnUrl);
        }

        // Pre-load unique listings for token substitution
        $listingIds = array_unique(array_column($recipients, 'fanlisting_id'));
        $listingMap = [];
        foreach ($listingIds as $lid) {
            $fl = $listingRepo->getById($lid);
            if ($fl !== null) {
                $listingMap[$lid] = $fl;
            }
        }

        $siteName   = (string) $config->get('site_name',  '');
        $ownerName  = (string) $config->get('owner_name',  '');
        $ownerEmail = (string) $config->get('owner_email', '');

        $search = ['{name}', '{email}', '{url}', '{added_at}',
                   '{listing_subject}', '{listing_url}',
                   '{site_name}', '{owner_name}', '{owner_email}'];

        $mailer = new Mailer($config);
        $sent   = 0;
        $failed = 0;

        foreach ($recipients as $r) {
            $fl = $listingMap[$r['fanlisting_id']] ?? null;
            $replace = [
                $r['name'], $r['email'], $r['url'], $r['added_at'],
                $fl?->subject ?? '', $fl?->url ?? '',
                $siteName, $ownerName, $ownerEmail,
            ];

            try {
                $mailer->send(
                    $r['email'], $r['name'],
                    str_replace($search, $replace, $subject),
                    str_replace($search, $replace, $body)
                );
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                error_log('[KIM] Cross-listing email failed for ' . $r['email'] . ': ' . $e->getMessage());
            }
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'kim.bulk_email',
            "Sent cross-listing email to {$sent} KIM member(s)" . ($failed > 0 ? ", {$failed} failed" : ''),
            $request->ip(),
        );

        if ($sent === 0) {
            $session->set('lstr_flash_error', "Email could not be delivered. {$failed} message(s) failed.");
        } else {
            $session->set(
                'lstr_flash_success',
                "Email sent: {$sent} delivered" . ($failed > 0 ? ", {$failed} failed" : '') . '.'
            );
        }
        return Response::redirect($returnUrl);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

}
