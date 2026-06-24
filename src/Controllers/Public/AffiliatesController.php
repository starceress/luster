<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders the public affiliates page showing active affiliates for a specific
 listing or for the collective (site-wide). Supports pagination and custom
 section templates with per-row loops, conditional blocks, and an inline
 affiliate application form toggle. When a section template is configured, the
 output is rendered through the template engine with {aff_*} row tokens.
*/

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

    /**
     * Renders the affiliates page for a specific listing.
     *
     * Only active affiliates are shown. When a section template is configured
     * (from lstr_fanlisting_settings or the global default), the output is
     * rendered via applySectionTemplate() instead of the plain PHP template.
     * An inline affiliate application form is injected when the template
     * contains the {affiliate_apply_toggle} token and applications are enabled.
     */
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
        $applySettings     = $settingsRepo->getAffiliateApplySettings($listingId);
        $applyStyle        = $settingsRepo->getAffiliateApplyStyle($listingId);
        $applyFormHtml     = '';
        if (($applySettings['enabled'] ?? false) && $sectionTemplate !== null && str_contains($sectionTemplate, '{affiliate_apply_toggle}')) {
            $applyFormHtml = $this->renderInlineApplyForm($listingId, $listing, $applySettings);
        }
        $sectionHtml = $sectionTemplate !== null
            ? $this->applySectionTemplate(
                $sectionTemplate, $result['items'], $result['total'], $uploadsUrl,
                $affiliateApplyUrl, $applySettings['enabled'] ?? false, $applyStyle, $applyFormHtml,
                (string) $this->app->getConfig()->get('site_name', 'Collective'),
            )
            : null;

        $html = $this->app->getView()->renderPublic('affiliates.php', [
            'listing'       => $listing,
            'listing_title'  => $listing->title,
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

    /**
     * Renders the collective (site-wide) affiliates page.
     *
     * Only affiliates whose fanlisting_id IS NULL are included.
     * Behaves identically to index() but reads the collective section template
     * and pagination template from lstr_site_settings instead of per-listing settings.
     */
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
        $collectiveApplyEnabled = ($siteRepo->get('collective_affiliate_apply_enabled') ?? '1') === '1';
        $collectiveApplyStyle   = (string) ($siteRepo->get('collective_affiliate_apply_style') ?? 'link');
        if (!in_array($collectiveApplyStyle, ['link', 'button'], true)) {
            $collectiveApplyStyle = 'link';
        }
        $collectiveFormHtml = '';
        if ($collectiveApplyEnabled && $sectionTemplate !== null && str_contains($sectionTemplate, '{affiliate_apply_toggle}')) {
            $collectiveFormHtml = $this->renderInlineCollectiveApplyForm($siteRepo);
        }
        $sectionHtml = $sectionTemplate !== null
            ? $this->applySectionTemplate(
                $sectionTemplate, $result['items'], $result['total'], $uploadsUrl,
                $affiliateApplyUrl, $collectiveApplyEnabled, $collectiveApplyStyle, $collectiveFormHtml,
                $siteName,
            )
            : null;

        $html = $this->app->getView()->renderPublic('affiliates.php', [
            'listing'      => null,
            'listing_title' => $siteName,
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
     * Renders the affiliate application form as an HTML fragment for inline
     * display inside the {affiliate_apply_toggle} collapsible block.
     *
     * Returns a View::renderFragment() string so the output can be injected
     * into the section template without re-entering a full page render.
     */
    private function renderInlineApplyForm(int $listingId, object $listing, array $applySettings): string
    {
        $baseUrl          = $this->app->getBaseUrl();
        $settingsRepo     = new ListingSettingsRepo($this->app->getDatabase());
        $captchaQuestion  = \Luster\Core\Captcha::getQuestion('affiliate_apply');

        $formUrl = trim($applySettings['form_url'] ?? '');
        if ($formUrl === '') {
            $formUrl = $baseUrl . '/affiliate-apply/' . $listingId;
        }

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'text',     'name' => 'name',           'id' => 'aff-name',    'label' => 'Site Name',                      'required' => true,  'attrs' => ['maxlength' => '200', 'autocomplete' => 'organization']],
            ['type' => 'url',      'name' => 'url',            'id' => 'aff-url',     'label' => 'Website URL',                       'required' => true,  'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
            ['type' => 'email',    'name' => 'email',          'id' => 'aff-email',   'label' => 'Email',          'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'file',     'name' => 'image',          'id' => 'aff-image',   'label' => 'Site Button / Image (optional)', 'required' => false, 'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp'], 'hint' => 'JPEG, PNG, GIF, or WebP. Max 500 KB.'],
            ['type' => 'textarea', 'name' => 'message',        'id' => 'aff-message', 'label' => 'Message (optional)',             'required' => false, 'attrs' => ['maxlength' => '2000', 'rows' => '4']],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'aff-captcha', 'label' => 'Captcha: ' . $captchaQuestion,  'required' => true,  'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ]);

        $globalTemplates  = (new SiteSettingsRepo($this->app->getDatabase()))->getGlobalTemplates();
        $affApplyResolved = ListingTemplateResolver::resolve(
            $settingsRepo->getAffiliateApplyFormTemplate($listingId),
            $globalTemplates['listing_default_affiliate_apply_form_template'] ?? null,
            '',
        );
        $formHtml = null;
        $affApplyTpl = $affApplyResolved['should_render_template'] ? $affApplyResolved['value'] : '';
        if ($affApplyTpl !== '') {
            $tokens = [
                '{listing_title}'         => \Luster\Core\View::e((string) $listing->title),
                '{listing_subject}'       => \Luster\Core\View::e((string) $listing->subject),
                '{listing_url}'           => \Luster\Core\View::e((string) $listing->url),
                '{field_name}'            => $f['name'],
                '{field_name_input}'      => $f['name_input'],
                '{field_url}'             => $f['url'],
                '{field_url_input}'       => $f['url_input'],
                '{field_email}'           => $f['email'],
                '{field_email_input}'     => $f['email_input'],
                '{field_image}'           => $f['image'],
                '{field_image_input}'     => $f['image_input'],
                '{field_message}'         => $f['message'],
                '{field_message_input}'   => $f['message_input'],
                '{captcha_block}'         => $f['captcha_answer'],
                '{captcha_block_input}'   => $f['captcha_answer_input'],
                '{captcha_question}'      => \Luster\Core\View::e($captchaQuestion),
                '{submit_button}'         => '<button type="submit" class="fl-submit">Submit Application</button>',
                '{clear_button}'          => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            ];
            $innerHtml = \Luster\Support\FormTemplateRenderer::expandTokens($affApplyTpl, $tokens);
            $formHtml  = \Luster\Support\FormTemplateRenderer::wrapForm(
                $innerHtml, $formUrl, $this->app->getCSRF()->token(), true,
                formMarker: 'aff_apply:' . $listingId,
            );
        }

        return $this->app->getView()->renderFragment('affiliate-apply.php', [
            'listing'        => $listing,
            'listing_title'  => (string) $listing->title,
            'listing_url'    => (string) $listing->url,
            'form_action'    => $formUrl,
            'csrf_token'     => $this->app->getCSRF()->token(),
            'errors'         => '',
            'f'              => $f,
            'form_html'      => $formHtml,
        ]);
    }

    /**
     * Renders the collective-level affiliate application form as an HTML fragment
     * for inline display. Mirrors renderInlineApplyForm() but reads template and
     * form URL from lstr_site_settings instead of per-listing settings.
     */
    private function renderInlineCollectiveApplyForm(SiteSettingsRepo $siteRepo): string
    {
        $config          = $this->app->getConfig();
        $baseUrl         = $this->app->getBaseUrl();
        $siteName        = (string) $config->get('site_name', 'Collective');
        $siteUrl         = (string) ($config->get('site_url', '') ?: $baseUrl);
        $captchaQuestion = \Luster\Core\Captcha::getQuestion('affiliate_apply');

        $embedBase  = (string) ($config->get('embed_base_url', '') ?: $baseUrl);
        $override   = $siteRepo->get('collective_form_url_affiliate_apply');
        $formUrl    = CollectiveFormUrlResolver::resolve($override, $siteUrl, rtrim($embedBase, '/') . '/affiliate-apply/collective');

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'text',     'name' => 'name',           'id' => 'aff-name',    'label' => 'Site Name',                      'required' => true,  'attrs' => ['maxlength' => '200', 'autocomplete' => 'organization']],
            ['type' => 'url',      'name' => 'url',            'id' => 'aff-url',     'label' => 'Website URL',                       'required' => true,  'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
            ['type' => 'email',    'name' => 'email',          'id' => 'aff-email',   'label' => 'Email',          'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'file',     'name' => 'image',          'id' => 'aff-image',   'label' => 'Site Button / Image (optional)', 'required' => false, 'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp'], 'hint' => 'JPEG, PNG, GIF, or WebP. Max 500 KB.'],
            ['type' => 'textarea', 'name' => 'message',        'id' => 'aff-message', 'label' => 'Message (optional)',             'required' => false, 'attrs' => ['maxlength' => '2000', 'rows' => '4']],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'aff-captcha', 'label' => 'Captcha: ' . $captchaQuestion,  'required' => true,  'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ]);

        $formHtml = null;
        $collectiveAffTpl = $siteRepo->get('collective_affiliate_apply_form_template') ?? '';
        if ($collectiveAffTpl !== '') {
            $tokens = [
                '{site_name}'             => \Luster\Core\View::e($siteName),
                '{listing_title}'         => \Luster\Core\View::e($siteName),
                '{listing_subject}'       => \Luster\Core\View::e($siteName),
                '{listing_url}'           => \Luster\Core\View::e($siteUrl),
                '{field_name}'            => $f['name'],
                '{field_name_input}'      => $f['name_input'],
                '{field_url}'             => $f['url'],
                '{field_url_input}'       => $f['url_input'],
                '{field_email}'           => $f['email'],
                '{field_email_input}'     => $f['email_input'],
                '{field_image}'           => $f['image'],
                '{field_image_input}'     => $f['image_input'],
                '{field_message}'         => $f['message'],
                '{field_message_input}'   => $f['message_input'],
                '{captcha_block}'         => $f['captcha_answer'],
                '{captcha_block_input}'   => $f['captcha_answer_input'],
                '{captcha_question}'      => \Luster\Core\View::e($captchaQuestion),
                '{submit_button}'         => '<button type="submit" class="fl-submit">Submit Application</button>',
                '{clear_button}'          => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            ];
            $innerHtml = \Luster\Support\FormTemplateRenderer::expandTokens($collectiveAffTpl, $tokens);
            $formHtml  = \Luster\Support\FormTemplateRenderer::wrapForm(
                $innerHtml, $formUrl, $this->app->getCSRF()->token(), true,
                formMarker: 'aff_apply_collective:0',
            );
        }

        return $this->app->getView()->renderFragment('affiliate-apply.php', [
            'listing'        => null,
            'listing_title'  => $siteName,
            'listing_url'    => $siteUrl,
            'form_action'    => $formUrl,
            'csrf_token'     => $this->app->getCSRF()->token(),
            'errors'         => '',
            'f'              => $f,
            'form_html'      => $formHtml,
        ]);
    }

    /**
     * Delegates per-affiliate row rendering to AffiliateRowRenderer.
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
     *   {affiliates_count}        — total active affiliate count
     *   {affiliate_apply_url}     — URL of the affiliate application form
     *   {affiliate_apply_toggle}  — collapsible <details>/<summary> linking to the apply page
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
        bool   $applyEnabled = false,
        string $applyStyle = 'link',
        string $applyFormHtml = '',
        string $siteName = 'Collective',
    ): string {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // {each_affiliate}...{/each_affiliate} — inline per-row loop block.
        // Process before conditionals so the block is substituted while still in context.
        if (preg_match('/\{each_affiliate\}(.*?)\{\/each_affiliate\}/s', $template, $m)) {
            $rendered = $this->buildAffiliateRows($m[1], $affiliates, $uploadsUrl);
            $template = (string) preg_replace('/\{each_affiliate\}.*?\{\/each_affiliate\}/s', $rendered, $template, 1);
        }

        // {affiliate_apply_toggle} — collapsible inline affiliate application form
        $toggleHtml = '';
        if ($applyEnabled && $applyFormHtml !== '') {
            $toggleHtml = '<details class="fl-affiliate-apply-collapsible">'
                . '<summary class="fl-affiliate-apply-toggle fl-affiliate-apply-toggle--' . $e($applyStyle) . '">'
                . 'Apply to become an affiliate?'
                . '</summary>'
                . '<div class="fl-affiliate-apply-form-wrap">'
                . $applyFormHtml
                . '</div>'
                . '</details>';
        }
        $template = str_replace('{affiliate_apply_toggle}', $toggleHtml, $template);

        // Process conditional blocks
        if ($total > 0) {
            $output = preg_replace('/\{if_no_affiliates\}.*?\{\/if_no_affiliates\}/s', '', $template) ?? $template;
            $output = str_replace(['{if_affiliates}', '{/if_affiliates}'], '', $output);
        } else {
            $output = preg_replace('/\{if_affiliates\}.*?\{\/if_affiliates\}/s', '', $template) ?? $template;
            $output = str_replace(['{if_no_affiliates}', '{/if_no_affiliates}'], '', $output);
        }

        return str_replace(
            ['{site_name}', '{affiliates_count}', '{affiliate_apply_url}'],
            [htmlspecialchars($siteName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), (string) $total, htmlspecialchars($affiliateApplyUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')],
            $output,
        );
    }

}
