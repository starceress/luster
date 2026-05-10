<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\UpdateChecker;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\CategoryRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Admin dashboard — shows aggregate stats and recent activity.
 */
final class DashboardController
{
    public function __construct(private readonly Application $app) {}

    /**
     * POST /admin/maintenance/touch-all-updated
     *
     * Sets last_updated to today for every listing — useful when first
     * enabling the staleness check on an existing install.
     */
    /**
     * POST /admin/dismiss-update
     *
     * Stores the dismissed version in session so the update banner is hidden
     * for the rest of the session. Re-appears if a newer version is released.
     */
    public function dismissUpdate(Request $request, array $params): Response
    {
        $version = trim((string) $request->post('version', ''));
        if ($version !== '') {
            $this->app->getSession()->set('lstr_update_dismissed', $version);
        }
        return Response::redirect($this->app->getBaseUrl() . '/admin');
    }

    /**
     * POST /admin/listings/{id}/touch-updated  (JSON — called via fetch)
     *
     * Sets last_updated to today for a single listing.
     */
    public function touchListing(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::json(['ok' => false, 'error' => 'Invalid ID'], 400);
        }

        $db           = $this->app->getDatabase();
        $settingsRepo = new ListingSettingsRepo($db);
        $activityRepo = new ActivityRepo($db);
        $session      = $this->app->getSession();

        $settingsRepo->touchLastUpdated($id);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'maintenance.touch_updated',
            "Touched last_updated for listing #$id",
            $request->ip(),
        );

        return Response::json(['ok' => true]);
    }

    public function touchAllUpdated(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $settingsRepo = new ListingSettingsRepo($db);
        $activityRepo = new ActivityRepo($db);
        $session      = $this->app->getSession();

        $thresholdDays = (int) $config->get('inactive_threshold_days', 60);
        $staleIds      = $settingsRepo->getStaleIds($thresholdDays);

        foreach ($staleIds as $id) {
            $settingsRepo->touchLastUpdated($id);
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'maintenance.touch_all_updated',
            'Bulk-touched last_updated to today for ' . count($staleIds) . ' neglected listing(s)',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Last Updated date set to today for ' . count($staleIds) . ' neglected listing(s).');
        return Response::redirect($this->app->getBaseUrl() . '/admin');
    }

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $view    = $this->app->getView();
        $baseUrl = $this->app->getBaseUrl();

        $listingRepo  = new ListingRepo($db, $config);
        $memberRepo   = new MemberRepo($db, $config);
        $activityRepo = new ActivityRepo($db);
        $joinedRepo   = new JoinedListingRepo($db);
        $categoryRepo = new CategoryRepo($db);
        $settingsRepo = new ListingSettingsRepo($db);

        // ── Owned listings ────────────────────────────────────────────
        $fanStats    = $listingRepo->getStats();
        $listings    = $listingRepo->getAll();
        $fanPending  = (int) $fanStats['trouble'];   // 'trouble' = pending status
        $fanUpcoming = (int) $fanStats['upcoming'];
        $fanCurrent  = (int) $fanStats['total'] - $fanPending - $fanUpcoming;

        $newestOwned       = !empty($listings) ? $listings[count($listings) - 1] : null;
        $newestOwnedName   = $newestOwned?->name;
        $newestOwnedOpened = $newestOwned?->opened;

        // ── Members ──────────────────────────────────────────────────────
        $totalMembers        = 0;
        $totalPending        = 0;
        $recentMembers       = [];
        $allCountries        = [];
        $topCountryCounts    = [];
        $mostActiveId        = null;
        $mostActiveName      = null;
        $mostActiveCount     = 0;
        $growthThisMonth     = 0;
        $growthLastMonth     = 0;
        $thisMonthStart      = date('Y-m-01');
        $lastMonthStart      = date('Y-m-01', strtotime('first day of last month'));

        foreach ($listings as $fl) {
            if ($fl->dbprefix === '') {
                continue;
            }
            $approved      = $memberRepo->countApproved($fl->dbprefix);
            $totalMembers += $approved;
            $totalPending += $memberRepo->countPending($fl->dbprefix);

            if ($approved > $mostActiveCount) {
                $mostActiveCount = $approved;
                $mostActiveName  = $fl->name;
                $mostActiveId    = $fl->id;
            }

            foreach ($memberRepo->getDistinctCountryNames($fl->dbprefix) as $c) {
                $allCountries[$c] = true;
            }

            foreach ($memberRepo->getCountrySummary($fl->dbprefix) as $row) {
                $topCountryCounts[$row['country']] = ($topCountryCounts[$row['country']] ?? 0) + $row['fan_count'];
            }

            foreach ($memberRepo->getRecent($fl->dbprefix, 2) as $m) {
                $recentMembers[] = ['member' => $m, 'listing_name' => $fl->name, 'listing_id' => $fl->id];
            }

            $thisMonthCount   = $memberRepo->countApprovedSince($fl->dbprefix, $thisMonthStart);
            $sinceLastMonth   = $memberRepo->countApprovedSince($fl->dbprefix, $lastMonthStart);
            $growthThisMonth += $thisMonthCount;
            $growthLastMonth += $sinceLastMonth - $thisMonthCount;
        }

        arsort($topCountryCounts);
        $topCountries    = array_slice($topCountryCounts, 0, 3, true);
        $memberCountries = count($allCountries);

        usort($recentMembers, fn($a, $b) => strcmp(
            (string) $b['member']->added,
            (string) $a['member']->added,
        ));
        $recentMembers = array_slice($recentMembers, 0, 5);

        // ── Joined listings ───────────────────────────────────────────
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS total, SUM(active = 0) AS inactive FROM lstr_joined'
        );
        $stmt->execute();
        $joinedRow           = $stmt->fetch();
        $totalJoined         = (int) ($joinedRow['total']    ?? 0);
        $totalJoinedInactive = (int) ($joinedRow['inactive'] ?? 0);
        $totalJoinedActive   = $totalJoined - $totalJoinedInactive;
        $totalJoinedCats     = count($joinedRepo->getCategories());

        $stmt = $db->prepare('SELECT name, date_joined FROM lstr_joined ORDER BY id DESC LIMIT 1');
        $stmt->execute();
        $row              = $stmt->fetch();
        $newestJoinedName = $row !== false ? (string) $row['name']        : null;
        $newestJoinedDate = $row !== false ? (string) ($row['date_joined'] ?? '') : null;

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM lstr_joined WHERE date_joined >= ? AND date_joined IS NOT NULL"
        );
        $stmt->execute([date('Y-01-01')]);
        $joinedThisYear = (int) $stmt->fetchColumn();

        // ── Opened this year ──────────────────────────────────────────
        $currentYear    = (int) date('Y');
        $openedThisYear = 0;
        foreach ($listings as $fl) {
            if ($fl->opened !== null && $fl->opened !== ''
                && (int) date('Y', strtotime((string) $fl->opened)) === $currentYear) {
                $openedThisYear++;
            }
        }

        // ── Affiliates ───────────────────────────────────────────────────
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS total, SUM(fanlisting_id IS NULL) AS collective FROM lstr_affiliates'
        );
        $stmt->execute();
        $affRow                    = $stmt->fetch();
        $totalAffiliates           = (int) ($affRow['total']      ?? 0);
        $totalAffiliatesCollective = (int) ($affRow['collective'] ?? 0);
        $totalAffiliatesPerFl      = $totalAffiliates - $totalAffiliatesCollective;

        // ── Categories ───────────────────────────────────────────────────
        $totalCategories = count($categoryRepo->getAll());

        // ── Recent activity ──────────────────────────────────────────────
        $recentActivity = $activityRepo->getRecent(10);

        // ── Update status (read cache only — no network on dashboard load) ──
        $siteRepo      = new SiteSettingsRepo($db);
        $updateStatus  = (new UpdateChecker($siteRepo))->getStatus();
        $dismissedVer  = (string) $session->get('lstr_update_dismissed', '');
        $showUpdateBanner = $updateStatus->updateAvailable
            && ($dismissedVer === '' || $dismissedVer !== $updateStatus->latest);

        // ── Staleness detection ───────────────────────────────────────────
        $thresholdDays = (int) $config->get('inactive_threshold_days', 60);
        $staleIds      = $settingsRepo->getStaleIds($thresholdDays);
        $staleListings = [];
        if (!empty($staleIds)) {
            // Fetch last_updated dates for stale listings in one query
            $placeholders   = implode(',', array_fill(0, count($staleIds), '?'));
            $stmt           = $db->prepare(
                "SELECT fanlisting_id, last_updated FROM lstr_fanlisting_settings
                  WHERE fanlisting_id IN ($placeholders)"
            );
            $stmt->execute($staleIds);
            $lastUpdatedMap = [];
            foreach ($stmt->fetchAll() as $luRow) {
                $lastUpdatedMap[(int) $luRow['fanlisting_id']] = $luRow['last_updated'];
            }

            foreach ($listings as $fl) {
                if (in_array($fl->id, $staleIds, true)) {
                    $lu      = $lastUpdatedMap[$fl->id] ?? null;
                    $daysAgo = ($lu !== null && $lu !== '')
                        ? (int) floor((time() - (int) strtotime($lu)) / 86400)
                        : null;
                    $staleListings[] = ['listing' => $fl, 'days_ago' => $daysAgo];
                }
            }
        }

        // ── Render ───────────────────────────────────────────────────────
        $csrfToken = $this->app->getCSRF()->token();

        $inner = $view->render('admin/dashboard.php', [
            'admin_username'              => (string) $session->get('lstr_admin_username', ''),
            'site_name'                   => (string) $config->get('site_name', 'Luster'),
            'csrf_token'                  => $csrfToken,
            'total_listings'              => $fanStats['total'],
            'fan_current'                 => $fanCurrent,
            'fan_pending'                 => $fanPending,
            'fan_upcoming'               => $fanUpcoming,
            'total_joined'               => $totalJoined,
            'total_joined_active'        => $totalJoinedActive,
            'total_joined_inactive'      => $totalJoinedInactive,
            'total_joined_categories'    => $totalJoinedCats,
            'total_members'              => $totalMembers,
            'member_countries'           => $memberCountries,
            'total_pending'              => $totalPending,
            'total_affiliates'           => $totalAffiliates,
            'total_affiliates_collective' => $totalAffiliatesCollective,
            'total_affiliates_per_fl'    => $totalAffiliatesPerFl,
            'total_categories'           => $totalCategories,
            'newest_owned_name'          => $newestOwnedName,
            'newest_owned_opened'        => $newestOwnedOpened,
            'newest_joined_name'         => $newestJoinedName,
            'newest_joined_date'         => $newestJoinedDate,
            'most_active_listing_name'   => $mostActiveName,
            'most_active_listing_id'     => $mostActiveId,
            'most_active_listing_count'  => $mostActiveCount,
            'top_countries'              => $topCountries,
            'growth_this_month'          => $growthThisMonth,
            'growth_last_month'          => $growthLastMonth,
            'opened_this_year'           => $openedThisYear,
            'joined_this_year'           => $joinedThisYear,
            'listings_up_to_date'        => max(0, (int) $fanStats['total'] - count($staleListings)),
            'recent_members'             => $recentMembers,
            'recent_activity'            => $recentActivity,
            'stale_listings'             => $staleListings,
            'inactive_threshold_days'    => $thresholdDays,
            'update_status'              => $updateStatus,
            'show_update_banner'         => $showUpdateBanner,
            'base_url'                   => $baseUrl,
        ]);

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => 'Dashboard',
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => 'dashboard',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
