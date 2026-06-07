<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Key/value store for site-wide (non-per-listing) settings.
 *
 * Backed by the lstr_site_settings table (Migration 28).
 * All methods degrade silently if the table does not yet exist so that
 * pages load normally before the migration has been run.
 *
 * Keys in use:
 *   owned_template            — combined block template for embed/owned.php; fully self-contained
 *                               (incl. wrapper markup) using {each_listing} (flat) or
 *                               {each_current}/{each_upcoming}/{each_pending} (sectioned) loops
 *   joined_template           — combined block template for embed/joined.php; fully self-contained
 *                               using {each_joined} (flat) or {each_current_joined} (grouped) loops
 *   collective_stats_template — HTML template for embed/collective-stats.php
 */
class SiteSettingsRepo
{
    public function __construct(private readonly Database $db) {}

    /**
     * Returns the stored value for $key, or $default when absent or not yet migrated.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT setting_value FROM lstr_site_settings WHERE setting_key = :key'
            );
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch();

            return $row !== false ? (string) $row['setting_value'] : $default;
        } catch (\Exception) {
            return $default;
        }
    }

    /**
     * Upserts a value. Pass null to clear the stored value.
     */
    public function set(string $key, ?string $value): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_site_settings (setting_key, setting_value)
                 VALUES (:key, :value)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $stmt->execute(['key' => $key, 'value' => $value]);
        } catch (\Exception) {
            // Table not yet created — Migration 28 not run; silently ignore
        }
    }

    /**
     * Default HTML row/block templates used when no custom value is stored.
     *
     * Shared by the admin collective page and the embed files so
     * both always show the same fallback markup.
     *
     * @return array{owned_template: string, joined_template: string, collective_stats_template: string, collective_affiliates_section_template: string}
     */
    public static function collectiveDefaults(): array
    {
        return [
            'owned_template' => '<ul style="list-style:none;padding:0;margin:0;">' . "\n"
                             . '{each_listing}' . "\n"
                             . '<li style="display:flex;gap:0.75em;align-items:flex-start;padding:0.75em 0;">' . "\n"
                             . '<div style="flex-shrink:0;max-width:120px;">{listing_image_html}</div>'
                             . '<div>' . "\n"
                             . '<b><a href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_subject}</a></b><br>' . "\n"
                             . '<i>{listing_title}</i><br>' . "\n"
                             . '<b>Category:</b> {listing_category}<br>' . "\n"
                             . '<b>Members:</b> {member_count} member(s){if_pending} (+{member_pending_count} pending){/if_pending}{if_countries} across {country_count} countries{/if_countries}<br>' . "\n"
                             . '<b>Opened:</b> {date_opened}<br>' . "\n"
                             . '<b>Last Updated:</b> {date_updated}' . "\n"
                             . '</div>' . "\n"
                             . '</li>' . "\n"
                             . '{/each_listing}' . "\n"
                             . '</ul>',
            'joined_template' => '<ul style="list-style:none;padding:0;margin:0;">' . "\n"
                               . '{each_joined}' . "\n"
                               . '<li><a href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_title}</a> — {listing_category}</li>' . "\n"
                               . '{/each_joined}' . "\n"
                               . '</ul>',
            'collective_stats_template' => 'Owned Listings: {owned_active} open, {owned_upcoming} upcoming<br>' . "\n"
                                         . 'Joined Listings: {joined_active}</br>' . "\n"
                                         . 'Total Members: {members_approved} approved (+ {members_pending} pending)</br>' . "\n"
                                         . 'Featured Listing: <a href="{owned_random_url}" target="_blank">{owned_random_name}</a></br>',
            'collective_affiliates_section_template' => '',
        ];
    }

    /**
     * Returns multiple keys in one query.
     *
     * @param  string[] $keys
     * @return array<string, string|null>  key → value (null when absent)
     */
    public function getMany(array $keys): array
    {
        $result = array_fill_keys($keys, null);

        if (empty($keys)) {
            return $result;
        }

        try {
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $stmt = $this->db->prepare(
                "SELECT setting_key, setting_value FROM lstr_site_settings
                 WHERE setting_key IN ({$placeholders})"
            );
            $stmt->execute(array_values($keys));

            foreach ($stmt->fetchAll() as $row) {
                $result[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Exception) {
            // Table not yet created — silently ignore
        }

        return $result;
    }
}
