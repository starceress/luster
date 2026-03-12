<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\CategoryRepo;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FanlistingSettingsRepo;
use Luster\Repositories\JoinedFanlistingRepo;
use Luster\Repositories\MemberRepo;

/**
 * Admin dashboard — shows aggregate stats and recent activity.
 */
final class DashboardController
{
    public function __construct(private readonly Application $app) {}

    /**
     * POST /admin/maintenance/touch-all-updated
     *
     * Sets last_updated to today for every fanlisting — useful when first
     * enabling the staleness check on an existing install.
     */
    public function touchAllUpdated(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $settingsRepo   = new FanlistingSettingsRepo($db);
        $activityRepo   = new ActivityRepo($db);
        $session        = $this->app->getSession();

        $fanlistings = $fanlistingRepo->getAll();
        foreach ($fanlistings as $fl) {
            $settingsRepo->touchLastUpdated($fl->id);
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'maintenance.touch_all_updated',
            'Bulk-touched last_updated to today for all ' . count($fanlistings) . ' fanlistings',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Last Updated date set to today for all fanlistings.');
        return Response::redirect($this->app->getBaseUrl() . '/admin');
    }

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $view    = $this->app->getView();
        $baseUrl = $this->app->getBaseUrl();

        $fanlistingRepo  = new FanlistingRepo($db, $config);
        $memberRepo      = new MemberRepo($db, $config);
        $activityRepo    = new ActivityRepo($db);
        $affiliateRepo   = new AffiliateRepo($db);
        $joinedRepo      = new JoinedFanlistingRepo($db);
        $categoryRepo    = new CategoryRepo($db);
        $settingsRepo    = new FanlistingSettingsRepo($db);

        // ── Owned fanlistings ────────────────────────────────────────────
        $fanStats    = $fanlistingRepo->getStats();
        $fanlistings = $fanlistingRepo->getAll();
        $fanPending  = (int) $fanStats['trouble'];   // 'trouble' = pending status
        $fanUpcoming = (int) $fanStats['upcoming'];

        $newestOwnedName = null;
        if (!empty($fanlistings)) {
            $newestOwnedName = $fanlistings[count($fanlistings) - 1]->name;
        }

        // ── Members ──────────────────────────────────────────────────────
        $totalMembers  = 0;
        $totalPending  = 0;
        $recentMembers = [];

        foreach ($fanlistings as $fl) {
            if ($fl->dbprefix === '') {
                continue;
            }
            $totalMembers += $memberRepo->countApproved($fl->dbprefix);
            $totalPending += $memberRepo->countPending($fl->dbprefix);

            foreach ($memberRepo->getRecent($fl->dbprefix, 2) as $m) {
                $recentMembers[] = ['member' => $m, 'fanlisting_name' => $fl->name];
            }
        }

        usort($recentMembers, fn($a, $b) => strcmp(
            (string) $b['member']->added,
            (string) $a['member']->added,
        ));
        $recentMembers = array_slice($recentMembers, 0, 5);

        // ── Joined fanlistings ───────────────────────────────────────────
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS total, SUM(active = 0) AS inactive FROM lstr_joined'
        );
        $stmt->execute();
        $joinedRow           = $stmt->fetch();
        $totalJoined         = (int) ($joinedRow['total']    ?? 0);
        $totalJoinedInactive = (int) ($joinedRow['inactive'] ?? 0);
        $totalJoinedCats     = count($joinedRepo->getCategories());

        $stmt = $db->prepare('SELECT name FROM lstr_joined ORDER BY id DESC LIMIT 1');
        $stmt->execute();
        $row              = $stmt->fetch();
        $newestJoinedName = $row !== false ? (string) $row['name'] : null;

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

        // ── Staleness detection ───────────────────────────────────────────
        $thresholdDays = (int) $config->get('inactive_threshold_days', 60);
        $staleIds      = $settingsRepo->getStaleIds($thresholdDays);
        $staleFanlistings = [];
        if (!empty($staleIds)) {
            foreach ($fanlistings as $fl) {
                if (in_array($fl->id, $staleIds, true)) {
                    $staleFanlistings[] = $fl;
                }
            }
        }

        // ── Render ───────────────────────────────────────────────────────
        $inner = $view->render('admin/dashboard.php', [
            'admin_username'              => (string) $session->get('lstr_admin_username', ''),
            'app_name'                    => (string) $config->get('app_name', 'Luster'),
            'total_fanlistings'           => $fanStats['total'],
            'fan_pending'                 => $fanPending,
            'fan_upcoming'               => $fanUpcoming,
            'total_joined'               => $totalJoined,
            'total_joined_inactive'      => $totalJoinedInactive,
            'total_joined_categories'    => $totalJoinedCats,
            'total_members'              => $totalMembers,
            'total_pending'              => $totalPending,
            'total_affiliates'           => $totalAffiliates,
            'total_affiliates_collective' => $totalAffiliatesCollective,
            'total_affiliates_per_fl'    => $totalAffiliatesPerFl,
            'total_categories'           => $totalCategories,
            'newest_owned_name'          => $newestOwnedName,
            'newest_joined_name'         => $newestJoinedName,
            'recent_members'             => $recentMembers,
            'recent_activity'            => $recentActivity,
            'stale_fanlistings'          => $staleFanlistings,
            'inactive_threshold_days'    => $thresholdDays,
            'base_url'                   => $baseUrl,
        ]);

        return Response::html($view->render('admin/layout.php', [
            'page_title'      => 'Dashboard',
            'app_name'        => (string) $config->get('app_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $this->app->getCSRF()->token(),
            'current_section' => 'dashboard',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ]));
    }
}
