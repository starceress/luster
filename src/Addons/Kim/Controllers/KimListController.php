<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public controller for the KIM list browser. The overview page shows all
 fanlistings that have at least one approved KIM member, along with their
 interest counts. The subject page shows the approved KIM members for a
 single fanlisting. Both views are read-only and require no authentication.
*/

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
        $db          = $this->app->getDatabase();
        $listingRepo = new ListingRepo($db, $this->app->getConfig());
        $kimRepo     = new KimMemberRepo($db);

        $fanlistings = $listingRepo->getAll();

        $filtered = array_filter($fanlistings, static fn($fl) => !$fl->trouble);

        $approvedCounts = $kimRepo->getApprovedCountsAll();

        $fanlistingsWithCounts = [];
        foreach ($filtered as $fl) {
            $count = $approvedCounts[$fl->id] ?? 0;
            if ($count === 0) {
                continue;
            }
            $fanlistingsWithCounts[] = ['listing' => $fl, 'count' => $count];
        }

        $baseUrl = $this->app->getBaseUrl();

        $overviewTpl = (new SiteSettingsRepo($db))->get('collective_kim_list_overview_template') ?? '';
        $overviewTemplateHtml = null;
        if ($overviewTpl !== '') {
            $overviewTpl = str_replace('{site_name}', htmlspecialchars((string) $this->app->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $overviewTpl);
            $hasListings = count($fanlistingsWithCounts) > 0;
            $tpl = TemplateEngine::conditionals($overviewTpl, [
                'has_listings' => $hasListings,
                'no_listings'  => !$hasListings,
            ]);
            $overviewTemplateHtml = TemplateEngine::loop(
                $tpl,
                'kim_listing',
                array_values($fanlistingsWithCounts),
                static function (string $inner, mixed $entry) use ($baseUrl): string {
                    $fl    = $entry['listing'];
                    $count = (int) $entry['count'];
                    return TemplateEngine::render($inner, [
                        'listing_title'     => $fl->title,
                        'listing_subject'   => $fl->subject,
                        'listing_url'       => $fl->url,
                        'listing_kim_url'   => $baseUrl . '/kim/list/' . $fl->id,
                        'listing_kim_count' => (string) $count,
                    ]);
                },
            );
        }

        return $this->renderKimPage('list.php', [
            'page_title'              => 'KIM List',
            'fanlistings_with_counts' => $fanlistingsWithCounts,
            'base_url'                => $baseUrl,
            'theme_path'              => $baseUrl . '/assets/css/default/',
            'link_signup'             => null,
            'link_list_base'          => null,
            'overview_template_html'  => $overviewTemplateHtml,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /kim/list/{id}
    // -----------------------------------------------------------------------

    public function subject(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);
        $db           = $this->app->getDatabase();

        $listingRepo = new ListingRepo($db, $this->app->getConfig());
        $listing     = $listingRepo->getById($fanlistingId);

        if ($listing === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $kimRepo = new KimMemberRepo($db);
        $members = $kimRepo->getApprovedByFanlisting($fanlistingId);

        $baseUrl    = $this->app->getBaseUrl();
        $siteRepo   = new SiteSettingsRepo($db);

        // Legacy per-member template (still supported)
        $kimMemberTemplate = $siteRepo->get('kim_member_template');
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

        // Collective subject template (Phase C)
        $subjectTpl = $siteRepo->get('collective_kim_list_subject_template') ?? '';
        $subjectTemplateHtml = null;
        if ($subjectTpl !== '') {
            $subjectTpl = str_replace('{site_name}', htmlspecialchars((string) $this->app->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $subjectTpl);
            $hasMembers = count($members) > 0;
            $tpl = TemplateEngine::conditionals($subjectTpl, [
                'has_kim_members' => $hasMembers,
                'no_kim_members'  => !$hasMembers,
            ]);
            $tpl = TemplateEngine::loop(
                $tpl,
                'kim_member',
                $members,
                static function (string $inner, object $member): string {
                    return TemplateEngine::render(
                        $inner,
                        [
                            'member_name' => $member->name,
                            'member_url'  => $member->url,
                        ],
                        [],
                        [
                            'member_url'    => $member->url !== '',
                            'no_member_url' => $member->url === '',
                        ],
                    );
                },
            );
            $kimOverviewUrl = $baseUrl . '/kim/list';
            $kimSignupUrl   = $baseUrl . '/kim';
            $tpl = TemplateEngine::conditionals($tpl, [
                'kim_overview_url' => true,
                'kim_signup_url'   => true,
            ]);
            $subjectTemplateHtml = TemplateEngine::tokens(
                $tpl,
                [
                    'listing_title'   => $listing->title,
                    'listing_subject' => $listing->subject,
                    'listing_url'     => $listing->url,
                    'kim_overview_url' => $kimOverviewUrl,
                    'kim_signup_url'   => $kimSignupUrl,
                ],
                ['pagination' => ''],
            );
        }

        return $this->renderKimPage('subject.php', [
            'page_title'               => 'KIM — ' . $listing->subject,
            'listing'                  => $listing,
            'members'                  => $members,
            'base_url'                 => $baseUrl,
            'theme_path'               => $baseUrl . '/assets/css/default/',
            'link_list'                => null,
            'link_signup'              => null,
            'kim_member_template_html' => $kimMemberHtml,
            'subject_template_html'    => $subjectTemplateHtml,
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
            'listing_title' => $siteName,
            'listing_url'  => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/css/default/',
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
