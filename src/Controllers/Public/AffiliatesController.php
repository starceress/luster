<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Pagination;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Models\Affiliate;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\AffiliateRowRenderer;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\ListingTemplateResolver;

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
        $uploadsUrl = $baseUrl . '/uploads/affiliates';

        $settingsRepo       = new ListingSettingsRepo($this->app->getDatabase());
        $templates          = $settingsRepo->getTemplates($listingId);
        $pagination         = Pagination::render($page, $result['pages'], $baseHref,
            $settingsRepo->getPaginationTemplate($listingId) ?? '');

        $globalTemplates = (new SiteSettingsRepo($this->app->getDatabase()))->getGlobalTemplates();
        $resolved        = ListingTemplateResolver::resolve(
            $templates['affiliates_section_template'] ?? null,
            $globalTemplates['listing_default_affiliates_section_template'] ?? null,
            '',
        );
        $sectionTemplate = $resolved['should_render_template'] ? $resolved['value'] : null;

        $affiliateApplyUrl = $baseUrl . '/affiliate-apply/' . $listingId;
        $sectionHtml = $sectionTemplate !== null
            ? $this->applySectionTemplate($sectionTemplate, $result['items'], $result['total'], $uploadsUrl, $affiliateApplyUrl)
            : null;

        $html = $this->app->getView()->renderPublic('affiliates.php', [
            'listing'       => $listing,
            'listing_name'  => $listing->title,
            'listing_url'   => $listing->url,
            'page_title'       => 'Affiliates — ' . $listing->title,
            'affiliates'       => $result['items'],
            'section_html'     => $sectionHtml,
            'total'            => $result['total'],
            'pagination'       => $pagination,
            'is_collective'    => false,
            'join_url'         => $baseUrl . '/join/' . $listingId,
            'base_url'         => $baseUrl,
            'uploads_url'      => $uploadsUrl,
            'theme_path'       => $baseUrl . '/assets/css/default/',
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
        $uploadsUrl = $baseUrl . '/uploads/affiliates';

        $siteRepo     = new SiteSettingsRepo($this->app->getDatabase());
        $pagination   = Pagination::render($page, $result['pages'], $baseHref,
            $siteRepo->get('collective_pagination_template') ?? '');
        $siteName     = (string) $this->app->getConfig()->get('site_name', 'Luster');
        $sectTplRaw   = (string) ($siteRepo->getMany(['collective_affiliates_section_template'])['collective_affiliates_section_template'] ?? '');
        $sectionTemplate = trim($sectTplRaw) !== '' ? $sectTplRaw : null;

        $siteUrl           = (string) ($this->app->getConfig()->get('site_url', '') ?: $baseUrl);
        $affApplyOverride  = $siteRepo->get('collective_form_url_affiliate_apply');
        $affiliateApplyUrl = CollectiveFormUrlResolver::resolve($affApplyOverride, $siteUrl, $baseUrl . '/affiliate-apply/collective');
        $sectionHtml = $sectionTemplate !== null
            ? $this->applySectionTemplate($sectionTemplate, $result['items'], $result['total'], $uploadsUrl, $affiliateApplyUrl)
            : null;

        $html = $this->app->getView()->renderPublic('affiliates.php', [
            'listing'      => null,
            'listing_name' => $siteName,
            'listing_url'  => $baseUrl,
            'page_title'      => 'Affiliates',
            'affiliates'      => $result['items'],
            'section_html'    => $sectionHtml,
            'total'           => $result['total'],
            'pagination'      => $pagination,
            'is_collective'   => true,
            'join_url'        => '',
            'base_url'        => $baseUrl,
            'uploads_url'     => $uploadsUrl,
            'theme_path'      => $baseUrl . '/assets/css/default/',
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Applies a per-affiliate row template to a list of affiliates and returns
     * the concatenated HTML string. See AffiliateRowRenderer for the {aff_*}
     * token reference.
     *
     * @param Affiliate[] $affiliates
     */
    private function buildAffiliateRows(string $template, array $affiliates, string $uploadsUrl): string
    {
        return AffiliateRowRenderer::renderRows($template, $affiliates, $uploadsUrl);
    }

    /**
     * Applies a page-level section template for the affiliates block.
     *
     * Supported tokens:
     *   {each_affiliate}...{/each_affiliate} — inline per-row loop; {aff_*} tokens work inside
     *   {affiliates}           — rendered affiliate rows (built-in link/image rows)
     *   {affiliates_count}     — total active affiliate count
     *   {affiliates_list}      — comma-separated <a href>name</a> list
     *   {affiliate_apply_url}  — URL of the affiliate application form
     *   {if_affiliates}...{/if_affiliates}           — shown when count > 0
     *   {if_no_affiliates}...{/if_no_affiliates}     — shown when count = 0
     *
     * @param Affiliate[]  $affiliates
     * @param int          $total       total active count (may differ from $affiliates on paginated pages)
     */
    private function applySectionTemplate(
        string $template,
        array  $affiliates,
        int    $total,
        string $uploadsUrl,
        string $affiliateApplyUrl = '',
    ): string {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // {each_affiliate}...{/each_affiliate} — inline per-row loop block.
        // Process before conditionals so the block is substituted while still in context.
        if (preg_match('/\{each_affiliate\}(.*?)\{\/each_affiliate\}/s', $template, $m)) {
            $rendered = $this->buildAffiliateRows($m[1], $affiliates, $uploadsUrl);
            $template = (string) preg_replace('/\{each_affiliate\}.*?\{\/each_affiliate\}/s', $rendered, $template, 1);
        }

        // Build {affiliates} content — built-in link/image rows
        $rowParts = [];
        foreach ($affiliates as $aff) {
            $imageUrl = ($aff->image !== null && $aff->image !== '') ? $uploadsUrl . '/' . $aff->image : '';
            if ($imageUrl !== '') {
                $rowParts[] = '<a href="' . $e($aff->url) . '" target="_blank" rel="noopener noreferrer">'
                    . '<img src="' . $e($imageUrl) . '" alt="' . $e($aff->name) . '"></a>';
            } else {
                $rowParts[] = '<a href="' . $e($aff->url) . '" target="_blank" rel="noopener noreferrer">'
                    . $e($aff->name) . '</a>';
            }
        }
        $rows = implode("\n", $rowParts);

        // Build {affiliates_list} — comma-separated plain links
        $listParts = [];
        foreach ($affiliates as $aff) {
            $listParts[] = '<a href="' . $e($aff->url) . '">' . $e($aff->name) . '</a>';
        }
        $plainList = implode(', ', $listParts);

        // Process conditional blocks
        if ($total > 0) {
            $output = preg_replace('/\{if_no_affiliates\}.*?\{\/if_no_affiliates\}/s', '', $template) ?? $template;
            $output = str_replace(['{if_affiliates}', '{/if_affiliates}'], '', $output);
        } else {
            $output = preg_replace('/\{if_affiliates\}.*?\{\/if_affiliates\}/s', '', $template) ?? $template;
            $output = str_replace(['{if_no_affiliates}', '{/if_no_affiliates}'], '', $output);
        }

        return str_replace(
            ['{affiliates}', '{affiliates_count}', '{affiliates_list}', '{affiliate_apply_url}'],
            [$rows, (string) $total, $plainList, htmlspecialchars($affiliateApplyUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')],
            $output,
        );
    }

}
