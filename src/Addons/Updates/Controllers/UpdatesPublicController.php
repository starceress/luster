<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public read-only controller for the News Feed add-on. Serves the
 per-fanlisting feed (GET /updates/{id}) and the collective feed
 (GET /updates). Both routes are GET-only, require no authentication,
 and support pagination and optional category filtering.
*/

declare(strict_types=1);

namespace Luster\Addons\Updates\Controllers;

use Luster\Addons\Updates\UpdateRepo;
use Luster\Core\Application;
use Luster\Core\Pagination;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingTemplateResolver;

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

    /**
     * Renders the public updates feed for a single fanlisting. Supports pagination and
     * optional category filtering via the "cat" query parameter. When a custom updates
     * template is configured it is rendered via TemplateEngine; otherwise the default
     * fragment template is used.
     */
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
        $themePath = $baseUrl . '/assets/css/default/';
        $feedUrl   = $baseUrl . '/updates/' . $fid;

        $ownerName   = (string) $config->get('owner_name', '');
        $settingsRepo       = new ListingSettingsRepo($db);
        $paginationTemplate = $settingsRepo->getPaginationTemplate($fid) ?? '';
        $pagination         = Pagination::render($page, $pages, $feedUrl, $paginationTemplate,
            $cat !== '' ? ['cat' => $cat] : []);

        $globalTemplates     = (new SiteSettingsRepo($db))->getGlobalTemplates();
        $resolved            = ListingTemplateResolver::resolve(
            $settingsRepo->getUpdatesTemplate($fid),
            $globalTemplates['listing_default_updates_template'] ?? null,
            '',
        );
        $updatesTemplateHtml = null;
        if ($resolved['should_render_template']) {
            $resolvedTpl = str_replace(
                ['{site_name}', '{listing_title}', '{listing_subject}', '{listing_url}'],
                [
                    htmlspecialchars((string) $this->app->getConfig()->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars((string) $listing->title,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars((string) $listing->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars((string) $listing->url,     ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                ],
                $resolved['value'],
            );
            $updatesTemplateHtml = self::buildUpdatesHtml($updates, $resolvedTpl);
        }

        $html = $this->app->getView()->renderPublic('addons/updates/feed.php', [
            'listing'               => $listing,
            'listing_title'          => $listing->title,
            'listing_url'           => $listing->url,
            'page_title'            => 'Updates — ' . $listing->title,
            'base_url'              => $baseUrl,
            'theme_path'            => $themePath,
            'updates'               => $updates,
            'total'                 => $total,
            'page'                  => $page,
            'pages'                 => $pages,
            'is_collective'         => false,
            'show_cats'             => true,
            'owner_name'            => $ownerName,
            'active_cat'            => $cat,
            'feed_url'              => $feedUrl,
            'pagination'            => $pagination,
            'updates_template_html' => $updatesTemplateHtml,
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Public proxy to buildUpdatesHtml(). Used by embed/updates.php and
     * embed/collective-updates.php so they can render a custom template without
     * re-implementing the TemplateEngine loop.
     *
     * @param \Luster\Addons\Updates\Update[] $updates
     */
    public static function buildUpdatesHtmlPublic(array $updates, string $template): string
    {
        return self::buildUpdatesHtml($updates, $template);
    }

    /**
     * Renders an updates list through TemplateEngine using the provided template string.
     * Handles {if_updates}/{if_no_updates} page-level conditionals and {each_update} loops
     * with per-entry token substitution.
     *
     * @param \Luster\Addons\Updates\Update[] $updates
     */
    private static function buildUpdatesHtml(array $updates, string $template): string
    {
        $hasUpdates = count($updates) > 0;
        $template = TemplateEngine::conditionals($template, [
            'updates'    => $hasUpdates,
            'no_updates' => !$hasUpdates,
        ]);

        return TemplateEngine::loop(
            $template,
            'update',
            $updates,
            static function (string $inner, object $update): string {
                return TemplateEngine::render(
                    $inner,
                    [
                        'update_title'      => $update->title,
                        'update_date'       => date('F j, Y', strtotime($update->postedAt)),
                        'update_author'     => $update->author ?? '',
                        'update_categories' => implode(', ', $update->categories()),
                    ],
                    [
                        'update_body_html' => nl2br(View::e($update->body)),
                    ],
                    [
                        'author'     => ($update->author ?? '') !== '',
                        'categories' => count($update->categories()) > 0,
                    ],
                );
            },
        );
    }

    // -----------------------------------------------------------------------
    // GET /updates  — collective feed
    // -----------------------------------------------------------------------

    /**
     * Renders the public collective updates feed (fanlisting_id IS NULL). Supports
     * pagination and optional category filtering. Uses the collective updates template
     * from site settings when set; falls back to the default fragment template.
     */
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
        $themePath = $baseUrl . '/assets/css/default/';
        $siteName  = (string) $config->get('site_name', 'Luster');
        $feedUrl   = $baseUrl . '/updates';

        $ownerName   = (string) $config->get('owner_name', '');
        $siteRepo           = new SiteSettingsRepo($db);
        $paginationTemplate = $siteRepo->get('collective_pagination_template') ?? '';
        $pagination         = Pagination::render($page, $pages, $feedUrl, $paginationTemplate,
            $cat !== '' ? ['cat' => $cat] : []);

        $updatesTemplateHtml = null;
        $collectiveUpdatesTpl = $siteRepo->get('collective_updates_template');
        if ($collectiveUpdatesTpl !== null && trim($collectiveUpdatesTpl) !== '') {
            $collectiveUpdatesTpl = str_replace('{site_name}', htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $collectiveUpdatesTpl);
            $updatesTemplateHtml = self::buildUpdatesHtml($updates, $collectiveUpdatesTpl);
        }

        $html = $this->app->getView()->renderPublic('addons/updates/feed.php', [
            'listing'               => null,
            'listing_title'          => $siteName,
            'listing_url'           => $baseUrl,
            'page_title'            => 'Updates — ' . $siteName,
            'base_url'              => $baseUrl,
            'theme_path'            => $themePath,
            'updates'               => $updates,
            'total'                 => $total,
            'page'                  => $page,
            'pages'                 => $pages,
            'is_collective'         => true,
            'show_cats'             => true,
            'owner_name'            => $ownerName,
            'active_cat'            => $cat,
            'feed_url'              => $feedUrl,
            'pagination'            => $pagination,
            'updates_template_html' => $updatesTemplateHtml,
        ]);

        return Response::html($html);
    }
}
