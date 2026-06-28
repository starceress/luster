<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders owned-listing rows by substituting tokens in the admin-defined
 owned_template string from the Collective Templates page. Handles tokens for
 title, subject, URL, status, member counts, category, image, dates, network
 approval status, and description, plus conditional blocks for each. Shared by
 the owned embed (multiple rows), random-listing, and latest-listing (single row)
 to centralise all token logic and HTML-escaping.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;
use Luster\Models\Listing;
use Luster\Support\DateFormatter;

final class ListingRowRenderer
{
    /**
     * Replace all {token} placeholders for one listing and return the HTML row.
     *
     * @param array<string, mixed> $settings    Row from lstr_fanlisting_settings
     *                                           (keys: image, last_updated, approved_by, description).
     * @param array{approved?: int, pending?: int, country_count?: int} $memberStats
     */
    public static function render(
        Listing $listing,
        string  $template,
        array   $settings,
        string  $uploadsUrl,
        string  $categoryName = '',
        array   $memberStats  = ['approved' => 0, 'pending' => 0, 'country_count' => 0],
        string  $dateFormat   = '',
    ): string {
        $status = match (true) {
            $listing->trouble  => 'pending',
            $listing->upcoming => 'upcoming',
            default            => 'current',
        };

        $image    = (string) ($settings['image'] ?? '');
        $imageUrl = $image !== '' ? $uploadsUrl . '/' . $image : '';

        $countryCount = (int) ($memberStats['country_count'] ?? 0);
        $pendingCount = (int) ($memberStats['pending']        ?? 0);
        $approvedBy   = (string) ($settings['approved_by'] ?? '');
        $description  = (string) ($settings['description'] ?? '');

        $network     = strtoupper($approvedBy);
        $networkName = match ($approvedBy) {
            'tfl'   => 'The Fanlistings Network',
            'tafl'  => 'The Anime Fanlistings Network',
            default => '',
        };

        return TemplateEngine::render(
            $template,
            escaped: [
                'listing_title'        => $listing->title,
                'listing_url'          => $listing->url,
                'listing_subject'      => $listing->subject,
                'listing_status'       => $status,
                'listing_email'        => $listing->email,
                'listing_type'         => ((string) ($settings['listing_type'] ?? '')) !== ''
                                            ? (string) $settings['listing_type']
                                            : (((string) ($listing->listingtype ?? '')) !== '' ? (string) $listing->listingtype : 'listing'),
                'listing_category'     => $categoryName,
                'listing_image_url'    => $imageUrl,
                'date_opened'          => DateFormatter::formatWith($listing->opened ?? '', $dateFormat),
                'date_updated'         => DateFormatter::formatWith(
                    DateFormatter::mostRecent((string) ($memberStats['newest_date'] ?? ''), (string) ($settings['last_updated'] ?? '')) ?? '',
                    $dateFormat,
                ),
                'member_count'         => (int) ($memberStats['approved'] ?? 0),
                'member_pending_count' => $pendingCount,
                'pending_count'        => $pendingCount,
                'country_count'        => $countryCount,
                'approved_by'          => $network,
                'network_name'         => $networkName,
                'tfl_url'              => 'https://thefanlistings.org/',
                'tafl_url'             => 'https://www.animefanlistings.com/',
                'listing_description'  => $description,
            ],
            conditions: [
                'listing_image'    => $imageUrl !== '',
                'no_listing_image' => $imageUrl === '',
                'countries'       => $countryCount > 0,
                'pending'         => $pendingCount > 0,
                'status_current'  => $status === 'current',
                'status_upcoming' => $status === 'upcoming',
                'status_pending'  => $status === 'pending',
                'tfl'             => $approvedBy === 'tfl',
                'tafl'            => $approvedBy === 'tafl',
                'approved_by'     => $approvedBy !== '',
                'date_updated'    => DateFormatter::mostRecent((string) ($memberStats['newest_date'] ?? ''), (string) ($settings['last_updated'] ?? '')) !== null,
                'description'     => $description !== '',
            ],
        );
    }

    /**
     * Renders one {each_$loopName}...{/each_$loopName} block within $template,
     * substituting one row per listing via render().
     *
     * Caller loops over loop names ('listing', 'current', 'upcoming', 'pending')
     * by calling this once per name — TemplateEngine::loop()'s regex only
     * touches its own {each_$loopName} block, leaving sibling blocks intact for
     * the next call (see TemplateEngine::loop() docblock on nesting support).
     *
     * $categoryMap follows the same [listingId => value] batch-map convention
     * as $settingsMap/$statsMap; owned.php batch-fetches all three before
     * calling the renderer so no DB handle is needed here.
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
        string $dateFormat  = '',
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
                $dateFormat,
            ),
        );
    }

    /**
     * Renders a complete combined owned_template against a set of listings —
     * auto-detecting flat ({each_listing}) vs sectioned
     * ({each_current}/{each_upcoming}/{each_pending}) mode from the template
     * string itself, exactly as embed/owned.php does for its own
     * site-owner-authored templates.
     *
     * Shared by owned.php (many listings, any status pool), latest-listing.php,
     * and random-listing.php (always exactly one listing) so every owned-template
     * surface stays behaviorally identical — a sectioned template renders
     * correctly from a single-listing embed instead of leaking literal
     * {each_current}/{each_listing} tags into the page (the bug this method
     * replaces: those two embeds previously called render() directly, which
     * has no loop-processing step at all).
     *
     * Sectioned mode applies the outer section-count/conditional tokens to the
     * template SHELL first — before any row HTML is substituted in. This order
     * matters: TemplateEngine::tokens() performs one simultaneous strtr() scan
     * per call, so running it a second time over a string that already contains
     * rendered row HTML would re-scan that HTML's literal escaped text too
     * (e.g. a listing title of literally "{pending_count}" would be overwritten
     * by the section's pending count on the second pass). Resolving the shell
     * first means loop bodies are the last thing substituted in, so their
     * literal content can never be re-matched — preserving the "escaped values
     * stay plain text" guarantee end-to-end.
     *
     * @param Listing[] $listings
     * @param array<int, array<string, mixed>> $settingsMap [listingId => settings row]
     * @param array<int, array{approved?: int, pending?: int, country_count?: int}> $statsMap [listingId => member stats]
     * @param array<int, string> $categoryMap [listingId => comma-separated category names]
     */
    public static function renderTemplate(
        string $template,
        array  $listings,
        string $uploadsUrl,
        array  $settingsMap,
        array  $statsMap,
        array  $categoryMap = [],
        string $dateFormat  = '',
    ): string {
        $isSectioned = str_contains($template, '{each_current}')
                    || str_contains($template, '{each_upcoming}')
                    || str_contains($template, '{each_pending}');

        if (!$isSectioned) {
            return self::renderGroup('listing', $template, $listings, $uploadsUrl, $settingsMap, $statsMap, $categoryMap, $dateFormat);
        }

        $current  = [];
        $upcoming = [];
        $pending  = [];
        foreach ($listings as $listing) {
            if ($listing->trouble) {
                $pending[] = $listing;
            } elseif ($listing->upcoming) {
                $upcoming[] = $listing;
            } else {
                $current[] = $listing;
            }
        }

        $html = TemplateEngine::render(
            $template,
            escaped: [
                'current_owned_count'  => count($current),
                'upcoming_owned_count' => count($upcoming),
                'pending_owned_count'  => count($pending),
                'total_count'          => count($listings),
            ],
            conditions: [
                'current_listings'  => $current !== [],
                'upcoming_listings' => $upcoming !== [],
                'pending_listings'  => $pending !== [],
            ],
        );

        foreach (['current' => $current, 'upcoming' => $upcoming, 'pending' => $pending] as $name => $group) {
            $html = self::renderGroup($name, $html, $group, $uploadsUrl, $settingsMap, $statsMap, $categoryMap, $dateFormat);
        }

        return $html;
    }
}
