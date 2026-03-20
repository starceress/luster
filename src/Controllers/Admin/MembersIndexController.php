<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;

/**
 * Top-level members index: lists all listings so the admin can
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

        $listingRepo = new ListingRepo($db, $config);
        $memberRepo     = new MemberRepo($db, $config);

        $listings = $listingRepo->getAll();

        $memberCounts  = [];
        $pendingCounts = [];
        foreach ($listings as $fl) {
            $memberCounts[$fl->id]  = $memberRepo->countApproved($fl->dbprefix);
            $pendingCounts[$fl->id] = $memberRepo->countPending($fl->dbprefix);
        }

        $view    = $this->app->getView();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $inner = $view->render('admin/members/listings.php', [
            'listings'    => $listings,
            'member_counts'  => $memberCounts,
            'pending_counts' => $pendingCounts,
            'base_url'       => $baseUrl,
        ]);

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'      => 'Members',
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $this->app->getCSRF()->token(),
            'current_section' => 'members',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ], $this->app->getAdminNavData())));
    }
}
