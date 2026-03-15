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
 *   owned_template           — per-row HTML template for embed/owned.php
 *   joined_template          — per-row HTML template for embed/joined.php
 *   collective_stats_template — HTML template for embed/collective-stats.php
 */
final class SiteSettingsRepo
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
