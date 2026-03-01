<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\ViewRaw;
use Luster\Models\Member;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;

/**
 * Public members list.
 *
 * GET /members/{id}[?sort=country|name&country=...&page=N]
 *
 * sort=country (default), no country param → country summary (countries + counts)
 * sort=country, country=X               → paginated members from that country
 * sort=name                             → paginated alphabetical list of all members
 */
final class MembersController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /members/{id}
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $fieldRepo = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields    = $fieldRepo->getFieldsForFanlisting($fanlistingId);

        $baseUrl     = $this->app->getBaseUrl();
        $membersBase = $baseUrl . '/members/' . $fanlistingId;
        $perPage     = max(1, (int) $this->app->getConfig()->get('per_page', 50));
        $page        = max(1, (int) ($request->query('page', 1)));

        // Whitelist $sort; fall back to 'country'
        $rawSort = (string) $request->query('sort', 'country');
        $sort    = in_array($rawSort, ['name', 'country'], true) ? $rawSort : 'country';
        $country = trim((string) $request->query('country', ''));

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());

        // Sort toggle links — active view rendered as <strong>, the other as <a>
        $sortNameEscUrl    = htmlspecialchars($membersBase . '?sort=name',    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $sortCountryEscUrl = htmlspecialchars($membersBase . '?sort=country', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $sortNameLink = $sort === 'name'
            ? new ViewRaw('<strong>Name</strong>')
            : new ViewRaw('<a href="' . $sortNameEscUrl . '">Name</a>');

        $sortCountryLink = $sort === 'country'
            ? new ViewRaw('<strong>Country</strong>')
            : new ViewRaw('<a href="' . $sortCountryEscUrl . '">Country</a>');

        // -----------------------------------------------------------------------
        // Path A: filtered by a specific country
        // -----------------------------------------------------------------------

        if ($country !== '') {
            $result = $memberRepo->paginateByCountry(
                $fanlisting->dbprefix, $country, $page, $perPage,
            );

            $memberLoop = $this->buildMemberLoop($result['members'], $fields);

            $backEscUrl      = htmlspecialchars($membersBase . '?sort=country', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $backToCountries = new ViewRaw(
                '<p class="back-link"><a href="' . $backEscUrl . '">&larr; Back to countries</a></p>',
            );

            $statsLine = $country . ': ' . $result['total'] . ' member(s)'
                . ($result['pages'] > 1 ? ' — page ' . $page . ' of ' . $result['pages'] : '');

            $pagination = new ViewRaw($this->buildPagination(
                $page, $result['pages'], $membersBase,
                ['sort' => 'country', 'country' => $country],
            ));

            return Response::html($this->app->getView()->render('default/members.html', [
                'fanlisting_name'   => $fanlisting->name,
                'fanlisting_url'    => $fanlisting->url,
                'fanlisting_id'     => (string) $fanlistingId,
                'join_url'          => $baseUrl . '/join/' . $fanlistingId,
                'sort_name_link'    => $sortNameLink,
                'sort_country_link' => $sortCountryLink,
                'back_to_countries' => $backToCountries,
                'stats_line'        => $statsLine,
                'country_loop'      => [],
                'member_loop'       => $memberLoop,
                'pagination'        => $pagination,
                'app_name'          => (string) $this->app->getConfig()->get('app_name', 'Luster'),
                'base_url'          => $baseUrl,
            ]));
        }

        // -----------------------------------------------------------------------
        // Path B: country summary (sort=country, no filter)
        // -----------------------------------------------------------------------

        if ($sort === 'country') {
            $countries = $memberRepo->getCountrySummary($fanlisting->dbprefix);

            $countryLoop = [];
            foreach ($countries as $row) {
                $countryLoop[] = [
                    'country_name'  => $row['country'],
                    'country_url'   => $membersBase . '?sort=country&country=' . urlencode($row['country']),
                    'country_count' => (string) $row['fan_count'],
                ];
            }

            $total     = count($countries);
            $statsLine = $total . ' ' . ($total === 1 ? 'country' : 'countries') . ' represented';

            return Response::html($this->app->getView()->render('default/members.html', [
                'fanlisting_name'   => $fanlisting->name,
                'fanlisting_url'    => $fanlisting->url,
                'fanlisting_id'     => (string) $fanlistingId,
                'join_url'          => $baseUrl . '/join/' . $fanlistingId,
                'sort_name_link'    => $sortNameLink,
                'sort_country_link' => $sortCountryLink,
                'back_to_countries' => new ViewRaw(''),
                'stats_line'        => $statsLine,
                'country_loop'      => $countryLoop,
                'member_loop'       => [],
                'pagination'        => new ViewRaw(''),
                'app_name'          => (string) $this->app->getConfig()->get('app_name', 'Luster'),
                'base_url'          => $baseUrl,
            ]));
        }

        // -----------------------------------------------------------------------
        // Path C: alphabetical by name (sort=name)
        // -----------------------------------------------------------------------

        $result = $memberRepo->paginate($fanlisting->dbprefix, $page, $perPage, true, 'name');

        $memberLoop = $this->buildMemberLoop($result['members'], $fields);
        $statsLine  = $result['total'] . ' member(s) — page ' . $page . ' of ' . $result['pages'];
        $pagination = new ViewRaw($this->buildPagination(
            $page, $result['pages'], $membersBase, ['sort' => 'name'],
        ));

        return Response::html($this->app->getView()->render('default/members.html', [
            'fanlisting_name'   => $fanlisting->name,
            'fanlisting_url'    => $fanlisting->url,
            'fanlisting_id'     => (string) $fanlistingId,
            'join_url'          => $baseUrl . '/join/' . $fanlistingId,
            'sort_name_link'    => $sortNameLink,
            'sort_country_link' => $sortCountryLink,
            'back_to_countries' => new ViewRaw(''),
            'stats_line'        => $statsLine,
            'country_loop'      => [],
            'member_loop'       => $memberLoop,
            'pagination'        => $pagination,
            'app_name'          => (string) $this->app->getConfig()->get('app_name', 'Luster'),
            'base_url'          => $baseUrl,
        ]));
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Builds the member_loop rows array for the View template engine.
     *
     * @param Member[]                            $members
     * @param \Luster\Models\AdditionalField[]    $fields
     * @return array<int, array<string, string>>
     */
    private function buildMemberLoop(array $members, array $fields): array
    {
        $loop = [];

        foreach ($members as $member) {
            $row = [
                'fan_name'    => $member->name,
                'fan_email'   => $member->email,
                'fan_url'     => $member->showurl ? $member->url : '',
                'fan_country' => $member->country,
                'fan_added'   => $member->added,
            ];

            foreach ($fields as $field) {
                $row['fan_' . $field->name] = $member->additionalFields[$field->name] ?? '';
            }

            $loop[] = $row;
        }

        return $loop;
    }

    /**
     * Builds paginated navigation HTML.
     *
     * $extraParams are merged into every page URL before the page number,
     * e.g. ['sort' => 'country', 'country' => 'Australia'].
     *
     * @param array<string, string> $extraParams
     */
    private function buildPagination(
        int    $currentPage,
        int    $totalPages,
        string $baseUrl,
        array  $extraParams = [],
    ): string {
        if ($totalPages <= 1) {
            return '';
        }

        $makeUrl = static function (int $p) use ($baseUrl, $extraParams): string {
            return htmlspecialchars(
                $baseUrl . '?' . http_build_query(array_merge($extraParams, ['page' => $p])),
                ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8',
            );
        };

        $html  = '<nav class="pagination" aria-label="Members pagination"><ul>';

        if ($currentPage > 1) {
            $html .= '<li><a href="' . $makeUrl($currentPage - 1) . '">&laquo; Previous</a></li>';
        }

        $start = max(1, $currentPage - 2);
        $end   = min($totalPages, $currentPage + 2);

        if ($start > 1) {
            $html .= '<li><a href="' . $makeUrl(1) . '">1</a></li>';
            if ($start > 2) {
                $html .= '<li><span>&hellip;</span></li>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $currentPage) {
                $html .= '<li><span class="current" aria-current="page">' . $i . '</span></li>';
            } else {
                $html .= '<li><a href="' . $makeUrl($i) . '">' . $i . '</a></li>';
            }
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<li><span>&hellip;</span></li>';
            }
            $html .= '<li><a href="' . $makeUrl($totalPages) . '">' . $totalPages . '</a></li>';
        }

        if ($currentPage < $totalPages) {
            $html .= '<li><a href="' . $makeUrl($currentPage + 1) . '">Next &raquo;</a></li>';
        }

        $html .= '</ul></nav>';

        return $html;
    }
}
