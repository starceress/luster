<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 AJAX endpoint for the TFL/TAFL approval URL lookup tool. Accepts a listing ID
 and network, fetches (or reads from cache) the network's approved-listings index
 page via TflLookupService, and returns a JSON object with the best-match URL and
 candidate suggestions for the admin to review.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\TflLookupService;
use Luster\Repositories\CategoryRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Handles POST /admin/tfl-lookup — AJAX endpoint for TFL/TAFL URL lookup.
 */
final class TflLookupController
{
    public function __construct(private readonly Application $app) {}

    /**
     * POST /admin/tfl-lookup
     *
     * Expected POST fields:
     *   listing_id  (int)          — the listing to look up
     *   network     (tfl|tafl)     — which network to search
     *
     * JSON response:
     *   url         string|null    — exact-match URL, or null
     *   candidates  array          — up to 10 [{text, url}] partial matches
     *   error       string|null    — human-readable error when lookup failed
     *   cache_age   int|null       — seconds since the cached index page was fetched
     */
    public function lookup(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        // Auth check (AuthMiddleware covers the route, but belt-and-suspenders for AJAX)
        if (!$session->get('lstr_admin_id')) {
            return Response::json(['error' => 'Unauthorized.', 'url' => null, 'candidates' => [], 'cache_age' => null], 401);
        }

        $listingId = (int) $request->post('listing_id', 0);
        $network   = (string) $request->post('network', '');

        if ($listingId <= 0) {
            return Response::json(['error' => 'Missing listing ID.', 'url' => null, 'candidates' => [], 'cache_age' => null]);
        }

        if (!in_array($network, ['tfl', 'tafl'], true)) {
            return Response::json(['error' => 'Network must be "tfl" or "tafl".', 'url' => null, 'candidates' => [], 'cache_age' => null]);
        }

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::json(['error' => 'Listing not found.', 'url' => null, 'candidates' => [], 'cache_age' => null]);
        }

        $subject     = $listing->subject;
        $siteRepo    = new SiteSettingsRepo($db);
        $categoryRepo = new CategoryRepo($db);
        $categoryName = null;

        foreach ($listingRepo->getCategoryIdsForListing($listingId) as $categoryId) {
            $category = $categoryRepo->getById($categoryId);
            if ($category !== null && $category->source === $network) {
                $categoryName = $category->name;
                break;
            }
        }

        $result = TflLookupService::lookup($subject, $network, $siteRepo, $categoryName);

        return Response::json([
            'url'        => $result['url'],
            'candidates' => $result['candidates'],
            'error'      => $result['error'],
            'cache_age'  => $result['cache_age'],
        ]);
    }
}
