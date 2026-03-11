<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
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

        $commonData = [
            'fanlisting'      => $fanlisting,
            'fanlisting_name' => $fanlisting->name,
            'fanlisting_url'  => $fanlisting->url,
            'page_title'      => 'Members',
            'join_url'        => $baseUrl . '/join/' . $fanlistingId,
            'members_base'    => $membersBase,
            'sort'            => $sort,
            'fields'          => $fields,
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ];

        // -----------------------------------------------------------------------
        // Path A: filtered by a specific country
        // -----------------------------------------------------------------------

        if ($country !== '') {
            $result = $memberRepo->paginateByCountry(
                $fanlisting->dbprefix, $country, $page, $perPage,
            );

            $statsLine = $country . ': ' . $result['total'] . ' member(s)'
                . ($result['pages'] > 1 ? ' — page ' . $page . ' of ' . $result['pages'] : '');

            $paginationHtml = $this->buildPagination(
                $page, $result['pages'], $membersBase,
                ['sort' => 'country', 'country' => $country],
            );

            $html = $this->app->getView()->renderPublic('members.php', array_merge($commonData, [
                'country'        => $country,
                'members'        => $result['members'],
                'country_summary' => [],
                'stats_line'     => $statsLine,
                'pagination'     => $paginationHtml,
            ]));

            return Response::html($html);
        }

        // -----------------------------------------------------------------------
        // Path B: country summary (sort=country, no filter)
        // -----------------------------------------------------------------------

        if ($sort === 'country') {
            $countries = $memberRepo->getCountrySummary($fanlisting->dbprefix);

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
                'country_summary' => $countrySummary,
                'stats_line'      => $statsLine,
                'pagination'      => '',
            ]));

            return Response::html($html);
        }

        // -----------------------------------------------------------------------
        // Path C: alphabetical by name (sort=name)
        // -----------------------------------------------------------------------

        $result = $memberRepo->paginate($fanlisting->dbprefix, $page, $perPage, true, 'name');

        $statsLine      = $result['total'] . ' member(s) — page ' . $page . ' of ' . $result['pages'];
        $paginationHtml = $this->buildPagination(
            $page, $result['pages'], $membersBase, ['sort' => 'name'],
        );

        $html = $this->app->getView()->renderPublic('members.php', array_merge($commonData, [
            'country'         => '',
            'members'         => $result['members'],
            'country_summary' => [],
            'stats_line'      => $statsLine,
            'pagination'      => $paginationHtml,
        ]));

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

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

        $html = '<nav class="fl-pagination" aria-label="Members pagination"><ul>';

        if ($currentPage > 1) {
            $html .= '<li><a class="fl-page-link" href="' . $makeUrl($currentPage - 1) . '">&laquo; Previous</a></li>';
        }

        $start = max(1, $currentPage - 2);
        $end   = min($totalPages, $currentPage + 2);

        if ($start > 1) {
            $html .= '<li><a class="fl-page-link" href="' . $makeUrl(1) . '">1</a></li>';
            if ($start > 2) {
                $html .= '<li><span>&hellip;</span></li>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $currentPage) {
                $html .= '<li><span class="fl-page-current" aria-current="page">' . $i . '</span></li>';
            } else {
                $html .= '<li><a class="fl-page-link" href="' . $makeUrl($i) . '">' . $i . '</a></li>';
            }
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<li><span>&hellip;</span></li>';
            }
            $html .= '<li><a class="fl-page-link" href="' . $makeUrl($totalPages) . '">' . $totalPages . '</a></li>';
        }

        if ($currentPage < $totalPages) {
            $html .= '<li><a class="fl-page-link" href="' . $makeUrl($currentPage + 1) . '">Next &raquo;</a></li>';
        }

        $html .= '</ul></nav>';

        return $html;
    }
}
