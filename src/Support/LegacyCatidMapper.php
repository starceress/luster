<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Translates Enthusiast-era category IDs (stored in the owned.catid column) to
 their corresponding lstr_categories.id values in Luster. This is needed by
 Migration 51 because old Enthusiast installs used a different category table
 whose auto-increment IDs don't match Luster's. The mapper probes for the
 legacy table, builds a name-based crosswalk, and returns the ID map.
*/

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
     *
     * Supports both the current format (Migration 63+: lstr_categories stores
     * 'Anime/Manga: Foo') and the legacy bare-name format (pre-Migration-63).
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
            $normalizedKey     = strtolower(trim($normalizedName));
            // Bare form of the canonical name: strips "Anime/Manga: " for pre-Migration-63 DBs.
            $normalizedBareKey = strtolower(trim((string) preg_replace('#^Anime/Manga:\s*#i', '', $normalizedName)));
            $strippedKey       = strtolower(trim((string) preg_replace('#^Anime/Manga:\s*#i', '', $raw)));
            $plain             = strtolower(trim($raw));
            $hadPrefix         = preg_match('#^Anime/Manga:\s*#i', $raw) === 1;
            $preferTafl        = $normalizedSource === 'tafl' || $hadPrefix;

            // 1. Try exact normalized key (works for Migration-63+ DBs with prefixed names).
            $resolved = self::resolveCandidate($lstrByNameSource, $normalizedKey, $preferTafl);
            // 2. Try bare canonical name (works for pre-Migration-63 DBs with bare names).
            if ($resolved === null && $preferTafl && $normalizedBareKey !== $normalizedKey) {
                $resolved = self::resolveCandidate($lstrByNameSource, $normalizedBareKey, true);
            }
            // 3. Try raw-input stripped key (catches variant spellings in older DBs).
            if ($resolved === null && $hadPrefix) {
                $resolved = self::resolveCandidate($lstrByNameSource, $strippedKey, true);
            }
            // 4. Last resort: un-stripped name (pre-Migration-49 lstr data).
            if ($resolved === null && $hadPrefix) {
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
