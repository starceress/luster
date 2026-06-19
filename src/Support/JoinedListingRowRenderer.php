<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Models\JoinedListing;
use Luster\Support\DateFormatter;

/**
 * Renders joined-listing rows by substituting tokens in the joined_template
 * string from Admin → Collective.
 *
 * Shared by joined.php (multiple rows, flat or grouped via renderGroup()),
 * random-joined-listing.php, and latest-joined-listing.php (single row via
 * render()) to keep token/escaping logic in one place. Built on TemplateEngine
 * so the loop → conditionals → flat-token processing order matches every
 * other template surface in the Public Template System.
 */
final class JoinedListingRowRenderer
{
    /**
     * Replace all {token} placeholders for one joined listing and return the HTML row.
     */
    public static function render(JoinedListing $listing, string $template, string $uploadsUrl, string $dateFormat = ''): string
    {
        $imageUrl  = ($listing->image !== null && $listing->image !== '') ? $uploadsUrl . '/' . $listing->image : '';
        $imageHtml = $imageUrl !== ''
            ? '<img src="' . View::e($imageUrl) . '" alt="' . View::e($listing->name) . '" title="' . View::e($listing->name) . '">'
            : '';

        return TemplateEngine::render(
            $template,
            escaped: [
                'listing_subject'   => $listing->name,
                'listing_url'       => $listing->url,
                'listing_category'  => $listing->category,
                'listing_image_url' => $imageUrl,
                'date_joined'       => DateFormatter::formatWith($listing->dateJoined ?? '', $dateFormat),
                'listing_status'    => $listing->status,
            ],
            raw: [
                'listing_image_html' => $imageHtml,
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
