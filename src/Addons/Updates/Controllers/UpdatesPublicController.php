<?php

declare(strict_types=1);

namespace Luster\Addons\Updates\Controllers;

use Luster\Addons\Updates\UpdateRepo;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;

/**
 * Public read-only controller for the Updates add-on.
 *
 * No auth, no CSRF, no rate limiting — both routes are GET only.
 */
final class UpdatesPublicController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /updates/{id}  — per-fanlisting feed
    // -----------------------------------------------------------------------

    public function feed(Request $request, array $params): Response
    {
        $fid    = (int) ($params['id'] ?? 0);
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($fid);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $perPage = max(1, (int) ($config->get('per_page', 10)));
        $page    = max(1, (int) ($request->query('page', 1)));
        $cat     = trim((string) $request->query('cat', ''));

        $updateRepo = new UpdateRepo($db);
        if ($cat !== '') {
            $updates = $updateRepo->getForFanlistingByCategory($fid, $cat, $page, $perPage);
            $total   = $updateRepo->countForFanlistingByCategory($fid, $cat);
        } else {
            $updates = $updateRepo->getForFanlisting($fid, $page, $perPage);
            $total   = $updateRepo->countForFanlisting($fid);
        }
        $pages = max(1, (int) ceil($total / $perPage));

        $baseUrl   = $this->app->getBaseUrl();
        $themePath = $baseUrl . '/assets/default/';

        $ownerName = (string) $config->get('owner_name', '');

        $html = $this->app->getView()->renderPublic('default/addons/updates/feed.php', [
            'listing'       => $listing,
            'listing_name'  => $listing->name,
            'listing_url'   => $listing->url,
            'page_title'    => 'Updates — ' . $listing->name,
            'base_url'      => $baseUrl,
            'theme_path'    => $themePath,
            'updates'       => $updates,
            'total'         => $total,
            'page'          => $page,
            'pages'         => $pages,
            'is_collective' => false,
            'show_cats'     => true,
            'owner_name'    => $ownerName,
            'active_cat'    => $cat,
            'feed_url'      => $baseUrl . '/updates/' . $fid,
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // GET /updates  — collective feed
    // -----------------------------------------------------------------------

    public function collective(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $perPage = max(1, (int) ($config->get('per_page', 10)));
        $page    = max(1, (int) ($request->query('page', 1)));
        $cat     = trim((string) $request->query('cat', ''));

        $updateRepo = new UpdateRepo($db);
        if ($cat !== '') {
            $updates = $updateRepo->getCollectiveByCategory($cat, $page, $perPage);
            $total   = $updateRepo->countCollectiveByCategory($cat);
        } else {
            $updates = $updateRepo->getCollective($page, $perPage);
            $total   = $updateRepo->countCollective();
        }
        $pages = max(1, (int) ceil($total / $perPage));

        $baseUrl   = $this->app->getBaseUrl();
        $themePath = $baseUrl . '/assets/default/';
        $siteName  = (string) $config->get('site_name', 'Luster');

        $ownerName = (string) $config->get('owner_name', '');

        $html = $this->app->getView()->renderPublic('default/addons/updates/feed.php', [
            'listing'       => null,
            'listing_name'  => $siteName,
            'listing_url'   => $baseUrl,
            'page_title'    => 'Updates — ' . $siteName,
            'base_url'      => $baseUrl,
            'theme_path'    => $themePath,
            'updates'       => $updates,
            'total'         => $total,
            'page'          => $page,
            'pages'         => $pages,
            'is_collective' => true,
            'show_cats'     => true,
            'owner_name'    => $ownerName,
            'active_cat'    => $cat,
            'feed_url'      => $baseUrl . '/updates',
        ]);

        return Response::html($html);
    }
}
