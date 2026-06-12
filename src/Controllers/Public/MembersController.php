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
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingTemplateResolver;

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
                ? $this->applyMembersTemplate($membersTemplate, $result['members'], $fields, $baseUrl . '/join/' . $listingId, $result['total'], $statsLine, $sortLinkAll, $sortLinkName, $sortLinkCountry)
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
            ? $this->applyMembersTemplate($membersTemplate, $result['members'], $fields, $baseUrl . '/join/' . $listingId, $result['total'], $statsLine, $sortLinkAll, $sortLinkName, $sortLinkCountry)
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
     * Applies a members list template and returns the rendered HTML string.
     *
     * Two modes:
     *   New-style: template contains {each_member}...{/each_member} — the block
     *     is extracted and repeated per member; the outer wrapper renders once.
     *     Page-level tokens and conditionals are then applied to the full result.
     *   Legacy: no {each_member} block — the entire template is treated as the
     *     per-row template and concatenated once per member (backward compat).
     *
     * Per-member tokens (inside {each_member}):
     *   {fan_name}, {fan_url}, {fan_email}, {fan_country}, {fan_FIELDNAME}
     *
     * Per-member conditionals (inside {each_member}):
     *   {if_fan_url}...{/if_fan_url}             — member has a URL
     *   {if_no_fan_url}...{/if_no_fan_url}       — member has no URL
     *   {if_fan_country}...{/if_fan_country}     — member has a country
     *   {if_no_fan_country}...{/if_no_fan_country} — member has no country
     *
     * Page-level conditionals (new-style only):
     *   {if_members}...{/if_members}       — shown when total > 0
     *   {if_no_members}...{/if_no_members} — shown when total = 0
     *
     * Page-level tokens (new-style only):
     *   {join_url}          — URL of the join form
     *   {member_count}      — total approved member count
     *   {stats_line}        — stats summary ("11 member(s) — page 1 of 2" or "5 countries represented")
     *   {sort_link_all}     — "View All" sort link (<strong> when active, <a> otherwise)
     *   {sort_link_name}    — "Name" sort link (<strong> when active, <a> otherwise)
     *   {sort_link_country} — "Country" sort link (<strong> when active, <a> otherwise)
     *
     * @param Member[]                         $members
     * @param \Luster\Models\AdditionalField[] $fields
     */
    private function applyMembersTemplate(
        string $template,
        array  $members,
        array  $fields,
        string $joinUrl,
        int    $total,
        string $statsLine = '',
        string $sortLinkAll = '',
        string $sortLinkName = '',
        string $sortLinkCountry = '',
    ): string {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $buildTokens = function (Member $member) use ($e, $fields): array {
            $tokens = [
                '{fan_name}'    => $e($member->name),
                '{fan_url}'     => $e($member->url     ?? ''),
                '{fan_email}'   => $e($member->email),
                '{fan_country}' => $e($member->country ?? ''),
            ];
            foreach ($fields as $field) {
                $val = (string) ($member->additionalFields[$field->name] ?? '');
                $tokens['{fan_' . $field->name . '}'] = $e($val);
            }
            return $tokens;
        };

        if (preg_match('/\{each_member\}(.*?)\{\/each_member\}/s', $template, $m)) {
            // New-style: loop block extracted, outer wrapper rendered once
            $rowTpl   = $m[1];
            $rendered = '';
            foreach ($members as $member) {
                $row     = $this->applyMemberRowConditionals($rowTpl, $member);
                $rendered .= str_replace(array_keys($buildTokens($member)), array_values($buildTokens($member)), $row);
            }
            $template = (string) preg_replace('/\{each_member\}.*?\{\/each_member\}/s', $rendered, $template, 1);

            // Page-level conditionals
            if ($total > 0) {
                $template = preg_replace('/\{if_no_members\}.*?\{\/if_no_members\}/s', '', $template) ?? $template;
                $template = str_replace(['{if_members}', '{/if_members}'], '', $template);
            } else {
                $template = preg_replace('/\{if_members\}.*?\{\/if_members\}/s', '', $template) ?? $template;
                $template = str_replace(['{if_no_members}', '{/if_no_members}'], '', $template);
            }

            // Page-level tokens
            return str_replace(
                ['{join_url}', '{member_count}', '{stats_line}', '{sort_link_all}', '{sort_link_name}', '{sort_link_country}'],
                [$e($joinUrl), (string) $total, $e($statsLine), $sortLinkAll, $sortLinkName, $sortLinkCountry],
                $template,
            );
        }

        // Legacy: entire template is per-row; concatenate rows
        $rows = [];
        foreach ($members as $member) {
            $row    = $this->applyMemberRowConditionals($template, $member);
            $tokens = $buildTokens($member);
            $rows[] = str_replace(array_keys($tokens), array_values($tokens), $row);
        }
        return implode(“\n”, $rows);
    }

    /**
     * Processes per-member conditional blocks within a single row template.
     */
    private function applyMemberRowConditionals(string $row, Member $member): string
    {
        $hasUrl     = ($member->url     ?? '') !== '';
        $hasCountry = ($member->country ?? '') !== '';

        if ($hasUrl) {
            $row = preg_replace('/\{if_no_fan_url\}.*?\{\/if_no_fan_url\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_fan_url}', '{/if_fan_url}'], '', $row);
        } else {
            $row = preg_replace('/\{if_fan_url\}.*?\{\/if_fan_url\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_no_fan_url}', '{/if_no_fan_url}'], '', $row);
        }

        if ($hasCountry) {
            $row = preg_replace('/\{if_no_fan_country\}.*?\{\/if_no_fan_country\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_fan_country}', '{/if_fan_country}'], '', $row);
        } else {
            $row = preg_replace('/\{if_fan_country\}.*?\{\/if_fan_country\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_no_fan_country}', '{/if_no_fan_country}'], '', $row);
        }

        return $row;
    }

}
