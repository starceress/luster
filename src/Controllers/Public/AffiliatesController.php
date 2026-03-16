<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Models\Affiliate;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

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
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $page = max(1, (int) $request->query('page', 1));

        $affiliateRepo = new AffiliateRepo($this->app->getDatabase());
        $result        = $affiliateRepo->getActiveByListing($listingId, self::PER_PAGE, $page);

        $baseUrl    = $this->app->getBaseUrl();
        $baseHref   = $baseUrl . '/affiliates/' . $listingId;
        $pagination = $this->buildPagination($page, $result['pages'], $baseHref);
        $uploadsUrl = $baseUrl . '/uploads';

        $settingsRepo       = new ListingSettingsRepo($this->app->getDatabase());
        $templates          = $settingsRepo->getTemplates($listingId);
        $affiliatesTemplate = ($templates['affiliates_template'] !== null && trim($templates['affiliates_template']) !== '')
            ? $templates['affiliates_template']
            : null;

        $affiliatesHtml = $affiliatesTemplate !== null && !empty($result['items'])
            ? $this->buildAffiliateRows($affiliatesTemplate, $result['items'], $uploadsUrl)
            : null;

        $html = $this->app->getView()->renderPublic('affiliates.php', [
            'listing'       => $listing,
            'listing_name'  => $listing->name,
            'listing_url'   => $listing->url,
            'page_title'       => 'Affiliates — ' . $listing->name,
            'affiliates'       => $result['items'],
            'affiliates_html'  => $affiliatesHtml,
            'total'            => $result['total'],
            'pagination'       => $pagination,
            'is_collective'    => false,
            'join_url'         => $baseUrl . '/join/' . $listingId,
            'base_url'         => $baseUrl,
            'uploads_url'      => $uploadsUrl,
            'theme_path'       => $baseUrl . '/assets/default/',
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

        $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');

        $html = $this->app->getView()->renderPublic('affiliates.php', [
            'listing'      => null,
            'listing_name' => $siteName,
            'listing_url'  => $baseUrl,
            'page_title'      => 'Affiliates',
            'affiliates'      => $result['items'],
            'affiliates_html' => null,
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

    /**
     * Applies a per-affiliate row template to a list of affiliates and returns
     * the concatenated HTML string. Each row replaces these tokens:
     *
     *   $aff_name$      — affiliate name (HTML-escaped)
     *   $aff_url$       — affiliate URL (HTML-escaped)
     *   $aff_image$     — image filename only (HTML-escaped), or empty string
     *   $aff_image_url$ — full src-ready URL including uploads path (HTML-escaped)
     *
     * @param Affiliate[] $affiliates
     */
    private function buildAffiliateRows(string $template, array $affiliates, string $uploadsUrl): string
    {
        $rows = [];

        foreach ($affiliates as $affiliate) {
            $imageUrl = ($affiliate->image !== null && $affiliate->image !== '')
                ? $uploadsUrl . '/' . $affiliate->image
                : '';

            $rows[] = str_replace(
                ['$aff_name$', '$aff_url$', '$aff_image$', '$aff_image_url$'],
                [
                    htmlspecialchars($affiliate->name,         ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($affiliate->url,          ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($affiliate->image ?? '',  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars($imageUrl,                ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                ],
                $template,
            );
        }

        return implode("\n", $rows);
    }

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
