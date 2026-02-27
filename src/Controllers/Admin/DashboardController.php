<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\MemberRepo;

/**
 * Admin dashboard — shows aggregate stats and recent activity.
 */
final class DashboardController
{
    public function __construct(private readonly Application $app) {}

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $view    = $this->app->getView();
        $baseUrl = $this->app->getBaseUrl();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);
        $activityRepo   = new ActivityRepo($db);

        // Aggregate stats
        $fanStats    = $fanlistingRepo->getStats();
        $fanlistings = $fanlistingRepo->getAll();

        $totalMembers = 0;
        $totalPending = 0;
        foreach ($fanlistings as $fl) {
            $totalMembers += $memberRepo->countApproved($fl->dbprefix);
            $totalPending += $memberRepo->countPending($fl->dbprefix);
        }

        $recentActivity = $activityRepo->getRecent(10);

        $inner = $view->render('admin/dashboard.php', [
            'admin_username'    => (string) $session->get('lstr_admin_username', ''),
            'app_name'          => (string) $config->get('app_name', 'Luster'),
            'total_fanlistings' => $fanStats['total'],
            'total_members'     => $totalMembers,
            'total_pending'     => $totalPending,
            'recent_activity'   => $recentActivity,
            'base_url'          => $baseUrl,
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
