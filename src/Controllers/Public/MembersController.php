<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\FanlistingRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;

/**
 * Public members list.
 *
 * GET /members/{id}[?page=N] — paginates approved members alphabetically,
 * passes dynamic additional field values as {fan_fieldname} tokens to the
 * template's {member_loop} block.
 *
 * Default page size: 'per_page' config key (default 50).
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

        $perPage  = max(1, (int) $this->app->getConfig()->get('per_page', 50));
        $page     = max(1, (int) ($request->query('page', 1)));

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $result     = $memberRepo->paginate($fanlisting->dbprefix, $page, $perPage, true);

        $members    = $result['members'];
        $total      = $result['total'];
        $totalPages = $result['pages'];

        // Build the member_loop array for the View template engine
        $memberLoop = [];

        foreach ($members as $member) {
            $row = [
                'fan_name'    => $member->name,
                'fan_email'   => $member->email,
                'fan_url'     => $member->url,
                'fan_country' => $member->country,
                'fan_added'   => $member->added,
            ];

            // Dynamic additional field tokens: {fan_fieldname}
            foreach ($fields as $field) {
                $row['fan_' . $field->name] = $member->additionalFields[$field->name] ?? '';
            }

            // Only include URL if member opted in
            if (!$member->showurl) {
                $row['fan_url'] = '';
            }

            $memberLoop[] = $row;
        }

        // Build pagination HTML
        $paginationHtml = $this->buildPagination(
            $page,
            $totalPages,
            $this->app->getBaseUrl() . '/members/' . $fanlistingId,
        );

        $html = $this->app->getView()->render('default/members.html', [
            'fanlisting_name'  => $fanlisting->name,
            'fanlisting_url'   => $fanlisting->url,
            'fanlisting_id'    => (string) $fanlistingId,
            'total_members'    => (string) $total,
            'current_page'     => (string) $page,
            'total_pages'      => (string) $totalPages,
            'pagination'       => $paginationHtml,
            'member_loop'      => $memberLoop,
            'app_name'         => (string) $this->app->getConfig()->get('app_name', 'Luster'),
            'base_url'         => $this->app->getBaseUrl(),
            'join_url'         => $this->app->getBaseUrl() . '/join/' . $fanlistingId,
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function buildPagination(int $currentPage, int $totalPages, string $baseUrl): string
    {
        if ($totalPages <= 1) {
            return '';
        }

        $html = '<nav class="pagination" aria-label="Members pagination"><ul>';

        if ($currentPage > 1) {
            $prev = htmlspecialchars($baseUrl . '?page=' . ($currentPage - 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<li><a href="' . $prev . '">&laquo; Previous</a></li>';
        }

        $start = max(1, $currentPage - 2);
        $end   = min($totalPages, $currentPage + 2);

        if ($start > 1) {
            $first = htmlspecialchars($baseUrl . '?page=1', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<li><a href="' . $first . '">1</a></li>';

            if ($start > 2) {
                $html .= '<li><span>&hellip;</span></li>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $pageUrl = htmlspecialchars($baseUrl . '?page=' . $i, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ($i === $currentPage) {
                $html .= '<li><span class="current" aria-current="page">' . $i . '</span></li>';
            } else {
                $html .= '<li><a href="' . $pageUrl . '">' . $i . '</a></li>';
            }
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<li><span>&hellip;</span></li>';
            }

            $last = htmlspecialchars($baseUrl . '?page=' . $totalPages, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<li><a href="' . $last . '">' . $totalPages . '</a></li>';
        }

        if ($currentPage < $totalPages) {
            $next = htmlspecialchars($baseUrl . '?page=' . ($currentPage + 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<li><a href="' . $next . '">Next &raquo;</a></li>';
        }

        $html .= '</ul></nav>';

        return $html;
    }
}
