<?php

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\TemplateEngine;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

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
            'page_title'             => 'KIM List',
            'fanlistings_with_counts' => $fanlistingsWithCounts,
            'base_url'               => $baseUrl,
            'theme_path'             => $baseUrl . '/assets/default/',
            'link_signup'            => null,
            'link_list_base'         => null,
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

        $kimMemberTemplate = (new SiteSettingsRepo($this->app->getDatabase()))->get('kim_member_template');
        $kimMemberHtml     = null;
        if ($kimMemberTemplate !== null && trim($kimMemberTemplate) !== '') {
            $kimMemberHtml = TemplateEngine::loop(
                $kimMemberTemplate,
                'kim_member',
                $members,
                static function (string $inner, object $member) use ($listing): string {
                    return TemplateEngine::render(
                        $inner,
                        [
                            'kim_name'        => $member->name,
                            'kim_url'         => $member->url,
                            'listing_title'   => $listing->title,
                            'listing_subject' => $listing->subject,
                        ],
                        [],
                        [
                            'kim_url'    => $member->url !== '',
                            'no_kim_url' => $member->url === '',
                        ],
                    );
                },
            );
        }

        return $this->renderKimPage('subject.php', [
            'page_title'               => 'KIM — ' . $listing->subject,
            'listing'                  => $listing,
            'members'                  => $members,
            'base_url'                 => $baseUrl,
            'theme_path'               => $baseUrl . '/assets/default/',
            'link_list'                => null,
            'link_signup'              => null,
            'kim_member_template_html' => $kimMemberHtml,
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function renderKimPage(string $template, array $data): Response
    {
        $view    = $this->app->getView();
        $baseUrl = $this->app->getBaseUrl();
        $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');

        $content = $view->render('default/addons/kim/' . $template, $data);

        $layoutData = array_merge($data, [
            'content'      => $content,
            'listing'      => $data['listing'] ?? null,
            'listing_name' => $siteName,
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
