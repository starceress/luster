<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Models\Affiliate;

/**
 * Renders per-affiliate row templates by substituting {aff_*} tokens.
 *
 * Shared by AffiliatesController, the admin Collective/per-listing template
 * previews, and the affiliates embed so the {each_affiliate} row tokens stay
 * in sync across every surface.
 *
 * Tokens:
 *   {aff_name}      — affiliate name (escaped)
 *   {aff_url}       — affiliate URL (escaped)
 *   {aff_image_url} — raw image URL (escaped), empty string when no image
 *   {aff_image}     — ready-made <img src="..." alt="{aff_name}"> tag, empty string when no image
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

        $imageTag = $imageUrl !== ''
            ? '<img src="' . View::e($imageUrl) . '" alt="' . View::e($affiliate->name) . '">'
            : '';

        return TemplateEngine::render(
            $rowTemplate,
            escaped: [
                'aff_name'      => $affiliate->name,
                'aff_url'       => $affiliate->url,
                'aff_image_url' => $imageUrl,
            ],
            raw: [
                'aff_image' => $imageTag,
            ],
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
