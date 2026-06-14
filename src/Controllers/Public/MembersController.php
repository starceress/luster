<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Pagination;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingTemplateResolver;
use Luster\Support\MembersTemplateRenderer;

/**
 * Public members list.
 *
 * GET /members/{id}[?sort=country|name&country=...&page=N]
 *
 * sort=country (default), no country param â†’ country summary (countries + counts)
 * sort=country, country=X               â†’ paginated members from that country
 * sort=name                             â†’ paginated alphabetical list of all members
 */
final class MembersController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /members/{id}
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $fieldRepo = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields    = $fieldRepo->getFieldsForListing($listingId);

        $settingsRepo      = new ListingSettingsRepo($this->app->getDatabase());
        $templates         = $settingsRepo->getTemplates($listingId);
        $globalTemplates   = (new SiteSettingsRepo($this->app->getDatabase()))->getGlobalTemplates();
        $resolved          = ListingTemplateResolver::resolve(
            $templates['members_template'] ?? null,
            $globalTemplates['listing_default_members_template'] ?? null,
            '',
        );
        $membersTemplate   = $resolved['should_render_template'] ? $resolved['value'] : null;
        $paginationTemplate = $settingsRepo->getPaginationTemplate($listingId) ?? '';

        $baseUrl     = $this->app->getBaseUrl();
        $membersBase = $baseUrl . '/members/' . $listingId;
        $perPage     = max(1, (int) $this->app->getConfig()->get('per_page', 50));
        $page        = max(1, (int) ($request->query('page', 1)));

        // Whitelist $sort; fall back to 'country'
        $rawSort = (string) $request->query('sort', 'country');
        $sort    = in_array($rawSort, ['name', 'country', 'added'], true) ? $rawSort : 'country';
        $country = trim((string) $request->query('country', ''));

        // Build pre-rendered sort link tokens for the members template
        $eSortLink       = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sortLinkAll     = $sort === 'added'   ? '<strong aria-current="page" class="fl-member-sort-link">View All</strong>'  : '<a class="fl-member-sort-link" href="' . $eSortLink($membersBase . '?sort=added')   . '">View All</a>';
        $sortLinkName    = $sort === 'name'    ? '<strong aria-current="page" class="fl-member-sort-link">Name</strong>'      : '<a class="fl-member-sort-link" href="' . $eSortLink($membersBase . '?sort=name')    . '">Name</a>';
        $sortLinkCountry = $sort === 'country' ? '<strong aria-current="page" class="fl-member-sort-link">Country</strong>'   : '<a class="fl-member-sort-link" href="' . $eSortLink($membersBase . '?sort=country') . '">Country</a>';

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());

        $commonData = [
            'listing'      => $listing,
            'listing_name' => $listing->title,
            'listing_url'  => $listing->url,
            'page_title'      => 'Members',
            'join_url'        => $baseUrl . '/join/' . $listingId,
            'members_base'    => $membersBase,
            'sort'            => $sort,
            'show_search'     => true,
            'fields'          => $fields,
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/css/default/',
        ];

        // -----------------------------------------------------------------------
        // Path A: filtered by a specific country
        // -----------------------------------------------------------------------

        if ($country !== '') {
            $result = $memberRepo->paginateByCountry(
                $listing->dbprefix, $country, $page, $perPage,
            );

            $statsLine = $country . ': ' . $result['total'] . ' member(s)'
                . ($result['pages'] > 1 ? ' â€” page ' . $page . ' of ' . $result['pages'] : '');

            $paginationHtml = Pagination::render(
                $page, $result['pages'], $membersBase, $paginationTemplate,
                ['sort' => 'country', 'country' => $country],
            );

            $membersHtml = $membersTemplate !== null
                ? MembersTemplateRenderer::render($membersTemplate, $result['members'], $fields, $baseUrl . '/join/' . $listingId, $result['total'], $statsLine, $sortLinkAll, $sortLinkName, $sortLinkCountry)
                : null;

            $html = $this->app->getView()->renderPublic('members.php', array_merge($commonData, [
                'country'         => $country,
                'members'         => $result['members'],
                'members_html'    => $membersHtml,
                'country_summary' => [],
                'stats_line'      => $statsLine,
                'pagination'      => $paginationHtml,
            ]));

            return Response::html($html);
        }

        // -----------------------------------------------------------------------
        // Path B: country summary (sort=country, no filter)
        // -----------------------------------------------------------------------

        if ($sort === 'country' || ($sort !== 'name' && $sort !== 'added')) {
            $countries = $memberRepo->getCountrySummary($listing->dbprefix);

            $countrySummary = [];
            foreach ($countries as $row) {
                $countrySummary[] = [
                    'country'   => $row['country'],
                    'fan_count' => (int) $row['fan_count'],
                    'url'       => $membersBase . '?sort=country&country=' . urlencode($row['country']),
                ];
            }

            $total     = count($countries);
            $statsLine = $total . ' ' . ($total === 1 ? 'country' : 'countries') . ' represented';

            $html = $this->app->getView()->renderPublic('members.php', array_merge($commonData, [
                'country'         => '',
                'members'         => [],
                'members_html'    => null,
                'country_summary' => $countrySummary,
                'stats_line'      => $statsLine,
                'pagination'      => '',
            ]));

            return Response::html($html);
        }

        // -----------------------------------------------------------------------
        // Path C: alphabetical by name (sort=name)
        // -----------------------------------------------------------------------

        $result = $memberRepo->paginate($listing->dbprefix, $page, $perPage, true, $sort);

        $statsLine      = $result['total'] . ' member(s) â€” page ' . $page . ' of ' . $result['pages'];
        $paginationHtml = Pagination::render(
            $page, $result['pages'], $membersBase, $paginationTemplate, ['sort' => $sort],
        );

        $membersHtml = $membersTemplate !== null
            ? MembersTemplateRenderer::render($membersTemplate, $result['members'], $fields, $baseUrl . '/join/' . $listingId, $result['total'], $statsLine, $sortLinkAll, $sortLinkName, $sortLinkCountry)
            : null;

        $html = $this->app->getView()->renderPublic('members.php', array_merge($commonData, [
            'country'         => '',
            'members'         => $result['members'],
            'members_html'    => $membersHtml,
            'country_summary' => [],
            'stats_line'      => $statsLine,
            'pagination'      => $paginationHtml,
        ]));

        return Response::html($html);
    }

}
