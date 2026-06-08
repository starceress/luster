<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Pagination;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Models\Member;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;

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
        $membersTemplate   = ($templates['members_template'] !== null && trim($templates['members_template']) !== '')
            ? $templates['members_template']
            : null;
        $paginationTemplate = $settingsRepo->getPaginationTemplate($listingId) ?? '';

        $baseUrl     = $this->app->getBaseUrl();
        $membersBase = $baseUrl . '/members/' . $listingId;
        $perPage     = max(1, (int) $this->app->getConfig()->get('per_page', 50));
        $page        = max(1, (int) ($request->query('page', 1)));

        // Whitelist $sort; fall back to 'country'
        $rawSort = (string) $request->query('sort', 'country');
        $sort    = in_array($rawSort, ['name', 'country', 'added'], true) ? $rawSort : 'country';
        $country = trim((string) $request->query('country', ''));

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

            $membersHtml = $membersTemplate !== null && !empty($result['members'])
                ? $this->buildMemberRows($membersTemplate, $result['members'], $fields)
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

        $membersHtml = $membersTemplate !== null && !empty($result['members'])
            ? $this->buildMemberRows($membersTemplate, $result['members'], $fields)
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

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Applies a per-member row template to a list of members and returns the
     * concatenated HTML string. Each member row replaces these tokens:
     *
     *   {fan_name}    â€” member name (HTML-escaped)
     *   {fan_url}     â€” member URL (HTML-escaped)
     *   {fan_email}   â€” member email (HTML-escaped)
     *   {fan_country} â€” member country (HTML-escaped)
     *   {fan_{name}}  â€” additional field value (HTML-escaped), e.g. {fan_fave_song}
     *
     * @param Member[]            $members
     * @param \Luster\Models\AdditionalField[] $fields
     */
    private function buildMemberRows(string $template, array $members, array $fields): string
    {
        $rows = [];

        foreach ($members as $member) {
            $tokens = [
                '{fan_name}'    => htmlspecialchars($member->name,    ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{fan_url}'     => htmlspecialchars($member->url,     ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{fan_email}'   => htmlspecialchars($member->email,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                '{fan_country}' => htmlspecialchars($member->country, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ];

            foreach ($fields as $field) {
                $val = (string) ($member->additionalFields[$field->name] ?? '');
                $tokens['{fan_' . $field->name . '}'] = htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }

            $rows[] = str_replace(array_keys($tokens), array_values($tokens), $template);
        }

        return implode("\n", $rows);
    }

}
