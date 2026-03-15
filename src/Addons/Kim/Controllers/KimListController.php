<?php

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;

/**
 * Public KIM listing browser.
 *
 * GET /kim/list       — shows all fanlistings with interest counts
 * GET /kim/list/{id}  — shows approved KIM members for a specific fanlisting
 */
final class KimListController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /kim/list
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $kimRepo     = new KimMemberRepo($this->app->getDatabase());

        $fanlistings = $listingRepo->getAll();

        // Filter out trouble listings
        $filtered = array_filter($fanlistings, static fn($fl) => !$fl->trouble);

        $fanlistingsWithCounts = [];
        foreach ($filtered as $fl) {
            $fanlistingsWithCounts[] = [
                'listing' => $fl,
                'count'   => $kimRepo->countApprovedByFanlisting($fl->id),
            ];
        }

        $baseUrl = $this->app->getBaseUrl();

        return $this->renderKimPage('list.php', [
            'page_title'            => 'KIM List',
            'fanlistings_with_counts' => $fanlistingsWithCounts,
            'base_url'              => $baseUrl,
            'theme_path'            => $baseUrl . '/assets/default/',
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /kim/list/{id}
    // -----------------------------------------------------------------------

    public function subject(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($fanlistingId);

        if ($listing === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $kimRepo = new KimMemberRepo($this->app->getDatabase());
        $members = $kimRepo->getApprovedByFanlisting($fanlistingId);

        $baseUrl = $this->app->getBaseUrl();

        return $this->renderKimPage('subject.php', [
            'page_title' => 'KIM — ' . $listing->subject,
            'listing'    => $listing,
            'members'    => $members,
            'base_url'   => $baseUrl,
            'theme_path' => $baseUrl . '/assets/default/',
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function renderKimPage(string $template, array $data): Response
    {
        $view    = $this->app->getView();
        $baseUrl = $this->app->getBaseUrl();
        $appName = (string) $this->app->getConfig()->get('app_name', 'Luster');

        $content = $view->render('addons/kim/' . $template, $data);

        $layoutData = array_merge($data, [
            'content'      => $content,
            'listing'      => $data['listing'] ?? null,
            'listing_name' => $appName,
            'listing_url'  => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/default/',
        ]);

        $layoutPath = $this->app->getBasePath() . '/templates/default/layout.php';
        if (is_file($layoutPath)) {
            $html = $view->render('default/layout.php', $layoutData);
        } else {
            $html = $content;
        }

        return Response::html($html);
    }
}
