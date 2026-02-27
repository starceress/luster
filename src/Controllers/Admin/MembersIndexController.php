<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\MemberRepo;

/**
 * Top-level members index: lists all fanlistings so the admin can
 * choose which one to manage.
 *
 * Route: GET /admin/members
 */
final class MembersIndexController
{
    public function __construct(private readonly Application $app) {}

    public function index(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);

        $fanlistings = $fanlistingRepo->getAll();

        $memberCounts  = [];
        $pendingCounts = [];
        foreach ($fanlistings as $fl) {
            $memberCounts[$fl->id]  = $memberRepo->countApproved($fl->dbprefix);
            $pendingCounts[$fl->id] = $memberRepo->countPending($fl->dbprefix);
        }

        $view    = $this->app->getView();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $inner = $view->render('admin/members/fanlistings.php', [
            'fanlistings'    => $fanlistings,
            'member_counts'  => $memberCounts,
            'pending_counts' => $pendingCounts,
            'base_url'       => $baseUrl,
        ]);

        return Response::html($view->render('admin/layout.php', [
            'page_title'      => 'Members',
            'app_name'        => (string) $config->get('app_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $this->app->getCSRF()->token(),
            'current_section' => 'members',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ]));
    }
}
