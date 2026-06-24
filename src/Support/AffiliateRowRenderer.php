<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders individual affiliate rows by substituting {aff_*} tokens in a
 user-defined template string. Shared across the public affiliates embed,
 the admin template preview, and the affiliate section renderer so every
 surface produces identical output. Supports both single-row rendering
 and looped group rendering via TemplateEngine.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;
use Luster\Models\Affiliate;

/**
 * Renders per-affiliate row templates by substituting {aff_*} tokens.
 *
 * Shared by the affiliates embed and admin template previews so the
 * {each_affiliate} row tokens stay in sync across every surface.
 *
 * Tokens:
 *   {aff_name}      — affiliate name (escaped)
 *   {aff_url}       — affiliate URL (escaped)
 *   {aff_image_url} — raw image URL (escaped), empty string when no image
 *   {if_aff_image}...{/if_aff_image}       — kept only when the affiliate has an image
 *   {if_no_aff_image}...{/if_no_aff_image} — kept only when the affiliate has no image
 */
final class AffiliateRowRenderer
{
    /**
     * Replace all {aff_*} placeholders for one affiliate and return the HTML row.
     */
    public static function render(Affiliate $affiliate, string $rowTemplate, string $uploadsUrl): string
    {
        $imageUrl = ($affiliate->image !== null && $affiliate->image !== '')
            ? $uploadsUrl . '/' . $affiliate->image
            : '';

        return TemplateEngine::render(
            $rowTemplate,
            escaped: [
                'aff_name'      => $affiliate->name,
                'aff_url'       => $affiliate->url,
                'aff_image_url' => $imageUrl,
            ],
            raw: [],
            conditions: [
                'aff_image'    => $imageUrl !== '',
                'no_aff_image' => $imageUrl === '',
            ],
        );
    }

    /**
     * Renders one {each_affiliate}...{/each_affiliate} block within $template,
     * substituting one row per affiliate via render().
     *
     * @param Affiliate[] $affiliates
     */
    public static function renderGroup(string $template, array $affiliates, string $uploadsUrl): string
    {
        return TemplateEngine::loop(
            $template,
            'affiliate',
            $affiliates,
            static fn(string $inner, Affiliate $affiliate): string => self::render($affiliate, $inner, $uploadsUrl),
        );
    }

    /**
     * Processes a full affiliates section template: conditionals, count token,
     * apply URL token, and the {each_affiliate} row loop.
     *
     * @param Affiliate[] $affiliates
     */
    public static function renderSection(
        string $template,
        array  $affiliates,
        int    $total,
        string $uploadsUrl,
        string $affiliateApplyUrl = '',
    ): string {
        $hasAffiliates = $total > 0;
        $template = TemplateEngine::conditionals($template, [
            'affiliates'    => $hasAffiliates,
            'no_affiliates' => !$hasAffiliates,
        ]);
        $template = str_replace(
            ['{affiliates_count}', '{affiliate_apply_url}'],
            [(string) $total,      $affiliateApplyUrl],
            $template,
        );
        if (preg_match('/\{each_affiliate\}(.*?)\{\/each_affiliate\}/s', $template, $m)) {
            $rows     = self::renderRows($m[1], $affiliates, $uploadsUrl);
            $template = (string) preg_replace('/\{each_affiliate\}.*?\{\/each_affiliate\}/s', $rows, $template, 1);
        }
        return $template;
    }

    /**
     * Renders a row template against each affiliate, concatenating with "\n".
     *
     * Used when the caller has already extracted the inner row template from
     * an {each_affiliate}...{/each_affiliate} block via regex.
     *
     * @param Affiliate[] $affiliates
     */
    public static function renderRows(string $rowTemplate, array $affiliates, string $uploadsUrl): string
    {
        $rows = [];
        foreach ($affiliates as $affiliate) {
            $rows[] = self::render($affiliate, $rowTemplate, $uploadsUrl);
        }

        return implode("\n", $rows);
    }
}
