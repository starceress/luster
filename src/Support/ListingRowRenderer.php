<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Models\Listing;

/**
 * Renders a single owned-listing row by substituting tokens in the
 * owned_template string from Admin → Collective.
 *
 * Shared by owned.php (multiple rows), random-listing.php,
 * and latest-listing.php to keep token/escaping logic in one place.
 */
final class ListingRowRenderer
{
    /**
     * Replace all {token} placeholders for one listing and return the HTML row.
     *
     * @param array $settings     Row from lstr_fanlisting_settings (keys: image, last_updated).
     * @param array $memberStats  ['approved' => int, 'country_count' => int]
     */
    public static function render(
        Listing $listing,
        string  $template,
        array   $settings,
        string  $uploadsUrl,
        string  $categoryName = '',
        array   $memberStats  = ['approved' => 0, 'country_count' => 0],
    ): string {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $statusLabel = match (true) {
            $listing->trouble  => 'Pending',
            $listing->upcoming => 'Upcoming',
            default            => 'Current',
        };

        $image    = (string) ($settings['image'] ?? '');
        $imageTag = $image !== ''
            ? '<img src="' . $e($uploadsUrl . '/' . $image) . '"'
              . ' alt="' . $e($listing->title) . '"'
              . ' title="' . $e($listing->subject) . '">'
            : '';

        $countryCount = (int) ($memberStats['country_count'] ?? 0);

        // Resolve {if_countries}...{/if_countries} before flat token substitution
        $template = (string) preg_replace(
            '/\{if_countries\}(.*?)\{\/if_countries\}/s',
            $countryCount > 0 ? '$1' : '',
            $template,
        );

        return str_replace(
            [
                '{listing_title}', '{listing_url}', '{listing_subject}', '{listing_status}',
                '{listing_email}', '{listing_type}', '{listing_category}',
                '{listing_image}', '{date_opened}', '{date_updated}',
                '{member_count}', '{country_count}', '{countries_count}',
            ],
            [
                $e($listing->title),
                $e($listing->url),
                $e($listing->subject),
                $e($statusLabel),
                $e($listing->email),
                $e($listing->listingtype),
                $e($categoryName),
                $imageTag,
                $e($listing->opened ?? ''),
                $e((string) ($settings['last_updated'] ?? '')),
                (string) ($memberStats['approved'] ?? 0),
                (string) $countryCount,
                (string) $countryCount,   // {countries_count} — Fanatically-compatible alias
            ],
            $template,
        );
    }
}
