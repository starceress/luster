<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders the admin dashboard, which is the first page you see after logging in.
 Aggregates collective-wide statistics (listing counts, member totals, top
 countries, month-over-month growth), recent activity entries, and any pending
 items that need attention. Also handles the "touch all updated dates" maintenance
 action and the update-banner dismiss action.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\UpdateChecker;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\CategoryRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\JoinedListingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Renders the admin dashboard and handles maintenance actions (touch-updated,
 * bulk-touch, dismiss-update banner).
 */
final class DashboardController
{
    public function __construct(private readonly Application $app) {}

    /**
     * Stores the dismissed version in session so the update banner is hidden
     * for the rest of the session; re-appears automatically if a newer version is released.
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
     * Sets last_updated to today for a single listing via JSON (called via fetch).
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

    /**
     * Marks a selected subset of stale listings as updated today via a checkbox POST.
     */
    public function bulkTouchUpdated(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();

        $rawIds = $request->post('listing_ids', []);
        if (!is_array($rawIds)) {
            $rawIds = [];
        }

        $settingsRepo = new ListingSettingsRepo($db);
        $activityRepo = new ActivityRepo($db);

        $updated = [];
        foreach ($rawIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $settingsRepo->touchLastUpdated($id);
                $updated[] = $id;
            }
        }

        if (!empty($updated)) {
            $activityRepo->log(
                (int) $session->get('lstr_admin_id'),
                'maintenance.bulk_touch_updated',
                'Bulk-touched last_updated for ' . count($updated) . ' selected listing(s): IDs ' . implode(', ', $updated),
                $request->ip(),
            );
            $n = count($updated);
            $session->set('lstr_flash_success', $n . ' listing' . ($n !== 1 ? 's' : '') . ' marked as updated.');
        } else {
            $session->set('lstr_flash_error', 'No listings selected.');
        }

        return Response::redirect($this->app->getBaseUrl() . '/admin');
    }

    /**
     * Sets last_updated to today for every listing that is currently past the
     * stale threshold — useful when first enabling the staleness check on an existing install.
     */
    public function touchAllUpdated(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo  = new ListingRepo($db, $config);
        $settingsRepo = new ListingSettingsRepo($db);
        $activityRepo = new ActivityRepo($db);
        $session      = $this->app->getSession();

        $siteRepo      = new SiteSettingsRepo($db);
        $staleDays     = max(1, (int) ($siteRepo->get('stale_days') ?: '45'));
        $staleListings = $listingRepo->getStaleListings($staleDays);

        foreach ($staleListings as $fl) {
            $settingsRepo->touchLastUpdated($fl->id);
        }

        $count = count($staleListings);
        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'maintenance.touch_all_updated',
            'Bulk-touched last_updated to today for ' . $count . ' neglected listing(s)',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Last Updated date set to today for ' . $count . ' neglected listing(s).');
        return Response::redirect($this->app->getBaseUrl() . '/admin');
    }

    /**
     * Returns a day-stable greeting phrase for the welcome block, rotating through
     * a fixed list using today's date as the index.
     */
    private static function dailyGreeting(string $username): string
    {
        $messages = [
            "Hey there, %s! 😀",
            "Let's make something shine today, %s! ✨",
            "Good to see you, %s. 🌈",
            "Welcome back, %s! 😄",
            "There you are, %s. 😊",
            "Glad you're here, %s. 💖",
            "Luster missed you, %s. 🧡",
            "You're right on time, %s. 🌠",
            "Luster feels brighter with you, %s. 🌟",
            "Ready to work some magic, %s? ⭐",
            "A little magic awaits, %s. 🔮",
        ];

        $index = (int) date('Ymd') % count($messages);
        return sprintf($messages[$index], $username);
    }

    /**
     * Renders the admin dashboard, aggregating listing counts, member totals, top
     * countries with month-over-month growth, stale listings, recent activity, and
     * pending-items banners in a single pass over all listings.
     */
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

        // --- Owned listings
        $fanStats    = $listingRepo->getStats();
        $listings    = $listingRepo->getAll();
        $fanPending  = (int) $fanStats['trouble'];   // 'trouble' = pending status
        $fanUpcoming = (int) $fanStats['upcoming'];
        $fanCurrent  = (int) $fanStats['total'] - $fanPending - $fanUpcoming;

        $newestOwned       = $listingRepo->getNewest();
        $newestOwnedName   = $newestOwned !== null
            ? ($newestOwned->subject !== '' ? $newestOwned->subject . ' - ' . $newestOwned->title : $newestOwned->title)
            : null;
        $newestOwnedOpened = $newestOwned?->opened;

        // --- Members
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
                $mostActiveName  = $fl->subject !== '' ? $fl->subject . ' - ' . $fl->title : $fl->title;
                $mostActiveId    = $fl->id;
            }

            foreach ($memberRepo->getDistinctCountryNames($fl->dbprefix) as $c) {
                $allCountries[$c] = true;
            }

            foreach ($memberRepo->getCountrySummary($fl->dbprefix) as $row) {
                $topCountryCounts[$row['country']] = ($topCountryCounts[$row['country']] ?? 0) + $row['fan_count'];
            }

            foreach ($memberRepo->getRecent($fl->dbprefix, 2) as $m) {
                $recentMembers[] = ['member' => $m, 'listing_subject' => ($fl->subject !== '' ? $fl->subject : $fl->title), 'listing_id' => $fl->id];
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

        // --- Joined listings
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

        // --- Opened this year
        $currentYear    = (int) date('Y');
        $openedThisYear = 0;
        foreach ($listings as $fl) {
            if ($fl->opened !== null && $fl->opened !== ''
                && (int) date('Y', strtotime((string) $fl->opened)) === $currentYear) {
                $openedThisYear++;
            }
        }

        // --- Affiliates
        $pendingColStmt = $db->prepare("SHOW COLUMNS FROM `lstr_affiliates` LIKE 'pending'");
        $pendingColStmt->execute();
        $affiliateWhere = $pendingColStmt->fetch() ? 'WHERE pending = 0' : '';
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS total, SUM(fanlisting_id IS NULL) AS collective FROM lstr_affiliates {$affiliateWhere}"
        );
        $stmt->execute();
        $affRow                    = $stmt->fetch();
        $totalAffiliates           = (int) ($affRow['total']      ?? 0);
        $totalAffiliatesCollective = (int) ($affRow['collective'] ?? 0);
        $totalAffiliatesPerFl      = $totalAffiliates - $totalAffiliatesCollective;

        $affiliateRepo    = new AffiliateRepo($db);
        $pendingAffiliates = $affiliateRepo->getPendingCount();

        $pendingCodes = 0;
        try {
            $pendingCodes = (new CodesRepo($db))->countPendingAll();
        } catch (\Throwable) {}

        $navData          = $this->app->getAdminNavData();
        $pendingAdoptions = (int) ($navData['nav_pending_adoptions'] ?? 0);
        $pendingKim       = (int) ($navData['nav_pending_kim']       ?? 0);

        // --- Categories
        $totalCategories = count($categoryRepo->getAll());

        // --- Recent activity
        $recentActivity = $activityRepo->getRecent(10);

        // --- Update status (read cache only — no network on dashboard load)
        $siteRepo      = new SiteSettingsRepo($db);
        $checker       = new UpdateChecker($siteRepo);
        $updateStatus  = $checker->getStatus();
        $dismissedVer  = (string) $session->get('lstr_update_dismissed', '');
        $showUpdateBanner = $updateStatus->updateAvailable
            && ($dismissedVer === '' || $dismissedVer !== $updateStatus->latest);

        if ($checker->shouldSendNotification($updateStatus)) {
            try {
                $ownerEmail = (string) ($this->app->getConfig()->get('owner_email', '') ?? '');
                if ($ownerEmail !== '') {
                    $siteName = (string) ($this->app->getConfig()->get('site_name', 'Luster') ?? '');
                    $mailer   = new \Luster\Core\Mailer($this->app->getConfig());
                    $mailer->send(
                        $ownerEmail,
                        '',
                        '[' . $siteName . '] Luster ' . $updateStatus->latest . ' is available',
                        "A new version of Luster is available.\n\n"
                        . "Installed: " . \Luster\Core\Version::CURRENT . "\n"
                        . "Available: " . $updateStatus->latest . "\n\n"
                        . ($updateStatus->releaseUrl !== null ? "Release: " . $updateStatus->releaseUrl . "\n\n" : '')
                        . "Log in to the admin panel to update."
                    );
                }
                $checker->markNotified($updateStatus->latest);
            } catch (\Throwable $e) {
                // Best-effort — don't break the dashboard if the email fails.
            }
        }

        // --- Staleness detection
        // Uses ListingRepo::getStaleListings() — LEFT JOIN on lstr_fanlisting_settings,
        // so active listings without a settings row are correctly included.
        $staleDays    = max(1, (int) ($siteRepo->get('stale_days') ?: '45'));
        $overdueDays  = max(1, (int) ($siteRepo->get('overdue_days') ?: '55'));
        $staleObjs     = $listingRepo->getStaleListings($staleDays);
        $staleListings = [];
        if (!empty($staleObjs)) {
            // Fetch last_updated dates in one query so dashboard can show days-ago
            $staleIds     = array_map(static fn($fl) => $fl->id, $staleObjs);
            $placeholders = implode(',', array_fill(0, count($staleIds), '?'));
            $stmt         = $db->prepare(
                "SELECT fanlisting_id, last_updated FROM lstr_fanlisting_settings
                  WHERE fanlisting_id IN ($placeholders)"
            );
            $stmt->execute(array_values($staleIds));
            $lastUpdatedMap = [];
            foreach ($stmt->fetchAll() as $luRow) {
                $lastUpdatedMap[(int) $luRow['fanlisting_id']] = $luRow['last_updated'];
            }

            foreach ($staleObjs as $fl) {
                $lu      = $lastUpdatedMap[$fl->id] ?? null;
                $daysAgo = ($lu !== null && $lu !== '')
                    ? (int) floor((time() - (int) strtotime($lu)) / 86400)
                    : null;
                $staleListings[] = ['listing' => $fl, 'days_ago' => $daysAgo];
            }
        }

        // --- Render
        $csrfToken      = $this->app->getCSRF()->token();
        $noListings     = (int) $fanStats['total'] === 0;
        $appUrl = (string) $config->get('app_url', '');
        $showHttpWarning = false;
        if (str_starts_with($appUrl, 'http://')) {
            $host = strtolower((string) (parse_url($appUrl, PHP_URL_HOST) ?? ''));
            $isLocal = $host === 'localhost'
                || $host === '127.0.0.1'
                || $host === '::1'
                || str_ends_with($host, '.local')
                || str_ends_with($host, '.test')
                || preg_match('/^192\.168\./', $host) === 1
                || preg_match('/^10\./', $host) === 1
                || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $host) === 1;
            $showHttpWarning = !$isLocal;
        }

        $adminUsername = (string) $session->get('lstr_admin_username', '');
        $displayName   = (string) $session->get('lstr_admin_display_name', '');
        $greetingName  = $displayName !== '' ? $displayName : $adminUsername;

        $inner = $view->render('admin/dashboard.php', [
            'admin_username'              => $adminUsername,
            'welcome_greeting'            => self::dailyGreeting($greetingName),
            'show_http_warning'           => $showHttpWarning,
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
            'pending_affiliates'         => $pendingAffiliates,
            'pending_codes'              => $pendingCodes,
            'pending_adoptions'          => $pendingAdoptions,
            'pending_kim'                => $pendingKim,
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
            'listings_up_to_date'        => max(0, $fanCurrent - count($staleListings)),
            'recent_members'             => $recentMembers,
            'recent_activity'            => $recentActivity,
            'stale_listings'             => $staleListings,
            'stale_threshold_days'       => $staleDays,
            'overdue_threshold_days'     => $overdueDays,
            'update_status'              => $updateStatus,
            'show_update_banner'         => $showUpdateBanner,
            'no_listings'                => $noListings,
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
