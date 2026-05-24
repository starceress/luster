<?php

declare(strict_types=1);

namespace Luster\Support;

use PDO;
use PDOException;

/**
 * Resolves Enthusiast legacy catid values to lstr_categories.id during Migration 51.
 *
 * owned.catid is dual-namespace:
 *   - Old Enthusiast installs: stores IDs from the legacy category/categories table
 *   - Luster-managed installs: stores lstr_categories.id values directly
 *
 * buildMap() probes for the legacy table and builds the translation map.
 * Returning null means no legacy table was found — callers treat catid as direct Luster IDs.
 */
final class LegacyCatidMapper
{
    /**
     * Probes for a legacy Enthusiast category table ('category' then 'categories').
     * Builds a map from legacy catid → lstr_categories.id by normalised name matching.
     * 'Anime/Manga: Foo' prefixes are stripped to 'Foo' before matching (Migration 49
     * already removed those prefixes from lstr_categories.name).
     *
     * When the same category name exists under both TFL and TAFL sources, resolution
     * is source-aware: prefixed 'Anime/Manga: Foo' rows prefer the TAFL row; bare
     * 'Foo' rows prefer the TFL row. Falls back to any available match.
     *
     * Returns null when neither legacy table exists (catid values are already Luster IDs).
     *
     * @return array<int,int>|null  legacyCatId → lstr_categories.id, or null if no legacy table
     */
    public static function buildMap(PDO $pdo): ?array
    {
        // Build name → source → id lookup; same name can exist in both tfl and tafl sources.
        $lstrByNameSource = [];
        foreach ($pdo->query('SELECT id, name, source FROM lstr_categories')->fetchAll(PDO::FETCH_ASSOC) as $lr) {
            $key = strtolower(trim((string) $lr['name']));
            $src = (string) ($lr['source'] ?? '');
            $lstrByNameSource[$key][$src] = (int) $lr['id'];
        }

        foreach (['category', 'categories'] as $table) {
            try {
                $rows = $pdo->query("SELECT catid, catname FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                return self::mapRows($rows, $lstrByNameSource);
            } catch (PDOException) {
                // Table doesn't exist — try next
            }
        }

        return null; // No legacy table found
    }

    /**
     * @param  list<array{catid:mixed,catname:mixed}>         $rows
     * @param  array<string,array<string,int>>                $lstrByNameSource  name → source → id
     * @return array<int,int>
     */
    private static function mapRows(array $rows, array $lstrByNameSource): array
    {
        $map = [];
        foreach ($rows as $row) {
            $raw = (string) $row['catname'];
            [$normalizedName, $normalizedSource] = CategorySeedCatalog::normalizeImportName($raw);
            $normalizedKey = strtolower(trim($normalizedName));
            $strippedKey    = strtolower(trim((string) preg_replace('#^Anime/Manga:\s*#i', '', $raw)));
            $plain         = strtolower(trim($raw));
            $hadPrefix     = preg_match('#^Anime/Manga:\s*#i', $raw) === 1;
            $preferTafl    = $normalizedSource === 'tafl' || $hadPrefix;

            // Prefer TAFL for prefixed rows, TFL for plain rows; fall back to any match.
            $resolved = self::resolveCandidate($lstrByNameSource, $normalizedKey, $preferTafl);
            if ($resolved === null && $hadPrefix) {
                // Older Luster data may have the prefix stripped but not the category renamed.
                $resolved = self::resolveCandidate($lstrByNameSource, $strippedKey, true);
            }
            if ($resolved === null && $hadPrefix) {
                // Last resort: look up the un-stripped name (handles pre-Migration-49 lstr data)
                $resolved = self::resolveCandidate($lstrByNameSource, $plain, false);
            }

            if ($resolved !== null) {
                $map[(int) $row['catid']] = $resolved;
            }
        }
        return $map;
    }

    /**
     * Picks the best lstr_categories.id for $key from the name→source→id map.
     * When $preferTafl is true, prefers source='tafl'; otherwise prefers source='tfl'.
     * Falls back to source='custom', then the first available entry.
     *
     * @param  array<string,array<string,int>> $lstrByNameSource
     */
    private static function resolveCandidate(array $lstrByNameSource, string $key, bool $preferTafl): ?int
    {
        $candidates = $lstrByNameSource[$key] ?? [];
        if (empty($candidates)) {
            return null;
        }
        if ($preferTafl) {
            $id = $candidates['tafl'] ?? $candidates['custom'] ?? null;
        } else {
            $id = $candidates['tfl'] ?? $candidates['custom'] ?? null;
        }
        return $id ?? (array_values($candidates)[0] ?? null);
    }
}
