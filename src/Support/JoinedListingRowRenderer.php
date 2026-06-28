<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders joined-listing rows by substituting tokens in the admin-defined
 joined_template string from the Collective Templates page. Handles tokens like
 {listing_subject}, {listing_url}, {listing_image_url}, and {date_joined}, plus
 conditional blocks for image presence. Shared by the joined embed (multiple
 rows), random-joined-listing, and latest-joined-listing (single row) to keep
 all token logic and HTML-escaping in one place.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;
use Luster\Models\JoinedListing;
use Luster\Support\DateFormatter;

final class JoinedListingRowRenderer
{
    /**
     * Replace all {token} placeholders for one joined listing and return the HTML row.
     */
    public static function render(JoinedListing $listing, string $template, string $uploadsUrl, string $dateFormat = ''): string
    {
        $imageUrl  = ($listing->image !== null && $listing->image !== '') ? $uploadsUrl . '/' . $listing->image : '';
        return TemplateEngine::render(
            $template,
            escaped: [
                'listing_subject'   => $listing->name,
                'listing_url'       => $listing->url,
                'listing_category'  => $listing->category,
                'listing_type'      => 'listing',
                'listing_image_url' => $imageUrl,
                'date_joined'       => DateFormatter::formatWith($listing->dateJoined ?? '', $dateFormat),
                'listing_status'    => $listing->status,
            ],
            conditions: [
                'listing_image'    => $imageUrl !== '',
                'no_listing_image' => $imageUrl === '',
            ],
        );
    }

    /**
     * Renders one {each_$loopName}...{/each_$loopName} block within $template,
     * substituting one row per joined listing via render().
     *
     * @param JoinedListing[] $listings
     */
    public static function renderGroup(string $loopName, string $template, array $listings, string $uploadsUrl, string $dateFormat = ''): string
    {
        return TemplateEngine::loop(
            $template,
            $loopName,
            $listings,
            static fn(string $inner, JoinedListing $listing): string => self::render($listing, $inner, $uploadsUrl, $dateFormat),
        );
    }

    /**
     * Renders a complete combined joined_template against a set of joined
     * listings — auto-detecting flat ({each_joined}) vs grouped
     * ({each_current_joined}) mode from the template string itself, exactly
     * as embed/joined.php does for its own site-owner-authored templates.
     *
     * Grouped mode applies the outer count/conditional tokens to the template
     * SHELL first — before any row HTML is substituted in — for the same
     * reason ListingRowRenderer::renderTemplate() does: a second tokens()
     * pass over already-rendered row HTML could re-match literal escaped text.
     *
     * @param JoinedListing[] $listings
     */
    public static function renderTemplate(string $template, array $listings, string $uploadsUrl, string $dateFormat = ''): string
    {
        if (!str_contains($template, '{each_current_joined}')) {
            return self::renderGroup('joined', $template, $listings, $uploadsUrl, $dateFormat);
        }

        $html = TemplateEngine::render(
            $template,
            escaped: [
                'current_count' => count($listings),
                'total_count'   => count($listings),
            ],
            conditions: [
                'current_listings' => $listings !== [],
            ],
        );

        return self::renderGroup('current_joined', $html, $listings, $uploadsUrl, $dateFormat);
    }
}
