<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\FanlistingRepo;

/**
 * Public affiliates page.
 *
 * GET /affiliates/{id}  — affiliates for a specific fanlisting
 * GET /affiliates       — collective / site-wide affiliates (fanlisting_id IS NULL)
 *
 * Only active affiliates (active = 1) are shown.
 * Supports optional pagination via ?page= query parameter.
 */
final class AffiliatesController
{
    private const PER_PAGE = 50;

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /affiliates/{id}
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $fanlistingId = (int) ($params['id'] ?? 0);

        $fanlistingRepo = new FanlistingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlisting     = $fanlistingRepo->getById($fanlistingId);

        if ($fanlisting === null) {
            return Response::notFound('That fanlisting does not exist.');
        }

        $page = max(1, (int) $request->query('page', 1));

        $affiliateRepo = new AffiliateRepo($this->app->getDatabase());
        $result        = $affiliateRepo->getActiveByFanlisting($fanlistingId, self::PER_PAGE, $page);

        $baseUrl    = $this->app->getBaseUrl();
        $baseHref   = $baseUrl . '/affiliates/' . $fanlistingId;
        $pagination = $this->buildPagination($page, $result['pages'], $baseHref);

        $html = $this->app->getView()->renderPublic('affiliates.php', [
            'fanlisting'      => $fanlisting,
            'fanlisting_name' => $fanlisting->name,
            'fanlisting_url'  => $fanlisting->url,
            'page_title'      => 'Affiliates — ' . $fanlisting->name,
            'affiliates'      => $result['items'],
            'total'           => $result['total'],
            'pagination'      => $pagination,
            'is_collective'   => false,
            'join_url'        => $baseUrl . '/join/' . $fanlistingId,
            'base_url'        => $baseUrl,
            'uploads_url'     => $baseUrl . '/uploads',
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // GET /affiliates
    // -----------------------------------------------------------------------

    public function collective(Request $request, array $params): Response
    {
        $page = max(1, (int) $request->query('page', 1));

        $affiliateRepo = new AffiliateRepo($this->app->getDatabase());
        $result        = $affiliateRepo->getActiveCollective(self::PER_PAGE, $page);

        $baseUrl    = $this->app->getBaseUrl();
        $baseHref   = $baseUrl . '/affiliates';
        $pagination = $this->buildPagination($page, $result['pages'], $baseHref);

        $appName = (string) $this->app->getConfig()->get('app_name', 'Luster');

        $html = $this->app->getView()->renderPublic('affiliates.php', [
            'fanlisting'      => null,
            'fanlisting_name' => $appName,
            'fanlisting_url'  => $baseUrl,
            'page_title'      => 'Affiliates',
            'affiliates'      => $result['items'],
            'total'           => $result['total'],
            'pagination'      => $pagination,
            'is_collective'   => true,
            'join_url'        => '',
            'base_url'        => $baseUrl,
            'uploads_url'     => $baseUrl . '/uploads',
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function buildPagination(int $current, int $total, string $baseHref): string
    {
        if ($total <= 1) {
            return '';
        }

        $parts = [];
        for ($i = 1; $i <= $total; $i++) {
            $href = htmlspecialchars($baseHref . '?page=' . $i, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($i === $current) {
                $parts[] = '<span class="fl-page-current">' . $i . '</span>';
            } else {
                $parts[] = '<a class="fl-page-link" href="' . $href . '">' . $i . '</a>';
            }
        }

        return implode(' ', $parts);
    }
}
