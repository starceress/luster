<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Models\Listing;

/**
 * Renders owned-listing rows by substituting tokens in the owned_template
 * string from Admin → Collective.
 *
 * Shared by owned.php (multiple rows, flat or grouped via renderGroup()),
 * random-listing.php, and latest-listing.php (single row via render()) to
 * keep token/escaping logic in one place. Built on TemplateEngine so the
 * loop → conditionals → flat-token processing order matches every other
 * template surface in the Public Template System.
 */
final class ListingRowRenderer
{
    /**
     * Replace all {token} placeholders for one listing and return the HTML row.
     *
     * @param array<string, mixed> $settings    Row from lstr_fanlisting_settings
     *                                           (keys: image, last_updated, approved_by).
     * @param array{approved?: int, pending?: int, country_count?: int} $memberStats
     */
    public static function render(
        Listing $listing,
        string  $template,
        array   $settings,
        string  $uploadsUrl,
        string  $categoryName = '',
        array   $memberStats  = ['approved' => 0, 'pending' => 0, 'country_count' => 0],
    ): string {
        $status = match (true) {
            $listing->trouble  => 'pending',
            $listing->upcoming => 'upcoming',
            default            => 'current',
        };

        $image    = (string) ($settings['image'] ?? '');
        $imageUrl = $image !== '' ? $uploadsUrl . '/' . $image : '';
        $imageHtml = $imageUrl !== ''
            ? '<img src="' . View::e($imageUrl) . '"'
              . ' alt="' . View::e($listing->title) . '"'
              . ' title="' . View::e($listing->subject) . '">'
            : '';

        $countryCount = (int) ($memberStats['country_count'] ?? 0);
        $pendingCount = (int) ($memberStats['pending']        ?? 0);
        $approvedBy   = (string) ($settings['approved_by'] ?? '');

        return TemplateEngine::render(
            $template,
            escaped: [
                'listing_title'        => $listing->title,
                'listing_url'          => $listing->url,
                'listing_subject'      => $listing->subject,
                'listing_status'       => $status,
                'listing_email'        => $listing->email,
                'listing_type'         => $listing->listingtype,
                'listing_category'     => $categoryName,
                'listing_image_url'    => $imageUrl,
                'date_opened'          => $listing->opened ?? '',
                'date_updated'         => (string) ($settings['last_updated'] ?? ''),
                'member_count'         => (int) ($memberStats['approved'] ?? 0),
                'member_pending_count' => $pendingCount,
                // Internal alias — pre-Phase-2 templates used {pending_count} for
                // the per-listing pending member count; {member_pending_count} is
                // now the canonical name (see docs/PUBLIC-TEMPLATES-PLAN.md). Both
                // resolve here, inside the row, before the outer template's own
                // {pending_count} *section* count token is processed.
                'pending_count'        => $pendingCount,
                'country_count'        => $countryCount,
                'countries_count'      => $countryCount,
                'approved_by'          => $approvedBy,
            ],
            raw: [
                'listing_image_html' => $imageHtml,
            ],
            conditions: [
                'listing_image'   => $imageUrl !== '',
                'countries'       => $countryCount > 0,
                // Carried over from the pre-Phase-2 default template (symmetric
                // with {if_countries}) — not in the plan's owned-row token table,
                // but dropping it would silently break "{if_pending} (+N pending)"
                // which is the obvious counterpart pattern site owners would expect.
                'pending'         => $pendingCount > 0,
                'status_current'  => $status === 'current',
                'status_upcoming' => $status === 'upcoming',
                'status_pending'  => $status === 'pending',
                'tfl'             => $approvedBy === 'tfl',
                'tafl'            => $approvedBy === 'tafl',
                'approved'        => $approvedBy !== '',
            ],
        );
    }

    /**
     * Renders one {each_$loopName}...{/each_$loopName} block within $template,
     * substituting one row per listing via render().
     *
     * Deliberate deviation from the plan's literal renderGroup() signature
     * (docs/PUBLIC-TEMPLATES-PLAN.md Phase 2), which listed `PDO $db` and
     * `string $prefix` parameters: owned.php already batch-fetches settings,
     * categories, and member stats before calling the renderer (the same
     * architecture render() has always used), so a fresh DB handle here would
     * be redundant and inconsistent. $categoryMap follows the same
     * [listingId => value] batch-map convention as $settingsMap/$statsMap.
     *
     * Caller loops over loop names ('listing', 'current', 'upcoming', 'pending')
     * by calling this once per name — TemplateEngine::loop()'s regex only
     * touches its own {each_$loopName} block, leaving sibling blocks intact for
     * the next call (see TemplateEngine::loop() docblock on nesting support).
     *
     * @param Listing[] $listings
     * @param array<int, array<string, mixed>> $settingsMap [listingId => settings row]
     * @param array<int, array{approved?: int, pending?: int, country_count?: int}> $statsMap [listingId => member stats]
     * @param array<int, string> $categoryMap [listingId => comma-separated category names]
     */
    public static function renderGroup(
        string $loopName,
        string $template,
        array  $listings,
        string $uploadsUrl,
        array  $settingsMap,
        array  $statsMap,
        array  $categoryMap = [],
    ): string {
        return TemplateEngine::loop(
            $template,
            $loopName,
            $listings,
            static fn(string $inner, Listing $listing): string => self::render(
                $listing,
                $inner,
                $settingsMap[$listing->id] ?? [],
                $uploadsUrl,
                $categoryMap[$listing->id] ?? '',
                $statsMap[$listing->id] ?? ['approved' => 0, 'pending' => 0, 'country_count' => 0],
            ),
        );
    }
}
