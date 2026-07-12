<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import adapter for Fanatically 1.x databases. Fanatically is a newer fanlisting
 management script with a central member table, JSON-encoded affiliate mappings,
 and a configurable table prefix (default fanatic_). This adapter imports
 listings, members, categories, affiliates, joined listings, codes, site
 settings, and (when the corresponding add-ons are installed) wishlist entries,
 KIM sign-ups, and adoption applications. Custom member fields are parsed from
 the Fanatically JSON field definitions.
*/

declare(strict_types=1);

namespace Luster\Import;

use Luster\Addons\Adoption\AdoptionAddon;
use Luster\Addons\Kim\KimAddon;
use Luster\Addons\Wishlist\WishlistAddon;
use PDO;

/**
 * Import adapter for Fanatically 1.x databases.
 *
 * Fanatically uses a central member table with JSON-encoded custom field
 * definitions, JSON-encoded affiliate listing mappings, and a configurable table
 * prefix (default "fanatic_"). This adapter imports listings, members, categories,
 * affiliates, joined listings, fan graphic codes, and non-sensitive site settings.
 * When the corresponding add-ons are installed it also imports wishlist entries,
 * KIM sign-ups, and adoption applications. Table prefix validation is enforced via
 * assertValidPrefix() before any SQL interpolation.
 */
final class FanaticallyImportSource implements ImportSourceInterface
{
    private const CHUNK = 500;

    // Settings keys never imported into Luster.
    private const EXCLUDED_SETTINGS = [
        'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption',
        'smtp_username', 'smtp_password',
        'admin_username', 'admin_password_hash', 'api_key',
        'bridge_la_enabled', 'bridge_enth_enabled',
        'import_codesort_enabled', 'import_bellabuffs_enabled', 'import_kimadmin_enabled',
    ];

    private ?\ZipArchive $activeZip = null;

    /**
     * Validates a table prefix string against the same pattern as Database::validatePrefix().
     * Only [a-zA-Z0-9_] characters are allowed. An empty string is valid (prefix-less install).
     *
     * @throws \RuntimeException when the prefix contains illegal characters.
     */
    private static function assertValidPrefix(string $prefix): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new \RuntimeException(
                "Invalid table prefix: '{$prefix}'. Only [a-zA-Z0-9_] allowed."
            );
        }
    }

    // -----------------------------------------------------------------------
    // ImportSourceInterface
    // -----------------------------------------------------------------------

    public function getName(): string
    {
        return 'Fanatically';
    }

    public function testConnection(array $credentials): ?string
    {
        try {
            $pdo    = $this->openConnection($credentials);
            $prefix = $credentials['fn_prefix'] ?? 'fanatic_';
            if ($prefix !== '') {
                self::assertValidPrefix($prefix);
            }
            foreach (["{$prefix}settings", "{$prefix}listings", "{$prefix}members"] as $tbl) {
                $stmt = $pdo->query("SHOW TABLES LIKE '{$tbl}'");
                if (!$stmt || $stmt->fetchColumn() === false) {
                    return "Required table `{$tbl}` not found. Check the table prefix (configured: '{$prefix}').";
                }
            }
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    public function openConnection(array $credentials): PDO
    {
        if (isset($credentials['fn_prefix']) && $credentials['fn_prefix'] !== '') {
            self::assertValidPrefix((string) $credentials['fn_prefix']);
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $credentials['host'],
            $credentials['name'],
        );

        return new PDO($dsn, $credentials['user'], $credentials['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function detectSchema(PDO $source): string
    {
        return 'fanatically_1x';
    }

    // -----------------------------------------------------------------------
    // Preview (read-only)
    // -----------------------------------------------------------------------

    public function preview(PDO $source, PDO $target, ImportOptions $options): ImportPreview
    {
        $p = $options->tablePrefix . $options->fnTablePrefix;
        if ($p !== '') {
            self::assertValidPrefix($p);
        }
        $rows = [];
        $caps = $this->detectCapabilities($source, $p);

        // Add-on availability (Wishlist/KIM/Adoption) is checked against the Luster
        // target DB, matching run()'s use of wishlistAddonInstalled($target) etc.
        $addonCheckPdo = $target;

        // ── Data dropped ─────────────────────────────────────────────────
        $droppedNotes = [];

        $pendingUpdateCount = 0;
        if ($caps['has_pending_update']) {
            try {
                $st = $source->query("SELECT COUNT(*) FROM `{$p}members` WHERE pending_update IS NOT NULL AND pending_update != ''");
                $pendingUpdateCount = (int) ($st ? $st->fetchColumn() : 0);
            } catch (\Throwable) {}
        }
        if ($pendingUpdateCount > 0 && !$options->importMembers) {
            $droppedNotes[] = "Data dropped — {$pendingUpdateCount} member(s) have queued update requests (pending_update). Enable Members to migrate them to the pending updates queue.";
        }

        $fansCount = 0;
        try {
            $st = $source->query("SELECT COUNT(*) FROM `{$p}listings` WHERE fansfansfans_id IS NOT NULL AND fansfansfans_id != ''");
            $fansCount = (int) ($st ? $st->fetchColumn() : 0);
        } catch (\Throwable) {}
        if ($fansCount > 0) {
            $droppedNotes[] = "Data dropped — {$fansCount} listing(s) have FansFansFans IDs that have no Luster target.";
        }

        $kimZeroCount = 0;
        if ($caps['has_kim'] && $options->importKim) {
            try {
                $st = $source->query("SELECT COUNT(*) FROM `{$p}kim` WHERE listing_id = 0 OR listing_id IS NULL");
                $kimZeroCount = (int) ($st ? $st->fetchColumn() : 0);
            } catch (\Throwable) {}
        }
        if ($kimZeroCount > 0) {
            $droppedNotes[] = "Data dropped — {$kimZeroCount} KIM entry(ies) with listing_id=0 (collective/unassigned) have no Luster target and will be skipped.";
        }

        // ── Data transformed ─────────────────────────────────────────────
        $transformedNotes = [];

        $blankPwCount = 0;
        if ($options->importMembers) {
            try {
                $st = $source->query("SELECT COUNT(*) FROM `{$p}members` WHERE password_hash IS NULL OR password_hash = ''");
                $blankPwCount = (int) ($st ? $st->fetchColumn() : 0);
            } catch (\Throwable) {}
            if ($blankPwCount > 0) {
                $transformedNotes[] = "Data transformed — {$blankPwCount} member(s) have blank passwords and must use forgot-password to log in.";
            }
        }

        $prevMigratedCount = 0;
        if ($options->importListings) {
            try {
                $st = $source->query("SELECT COUNT(*) FROM `{$p}listings` WHERE data_source != 'native'");
                $prevMigratedCount = (int) ($st ? $st->fetchColumn() : 0);
            } catch (\Throwable) {}
            if ($prevMigratedCount > 0) {
                $transformedNotes[] = "Data transformed — {$prevMigratedCount} listing(s) were previously migrated from another script into Fanatically; they will be imported as native Luster listings.";
            }
        }

        $aflCount = 0;
        if ($options->importListings) {
            try {
                $st = $source->query("SELECT COUNT(*) FROM `{$p}listings` WHERE approved_by = 'afl'");
                $aflCount = (int) ($st ? $st->fetchColumn() : 0);
            } catch (\Throwable) {}
            if ($aflCount > 0) {
                $transformedNotes[] = "Data transformed — {$aflCount} listing(s) have approved_by='afl' which will be mapped to 'tafl'.";
            }
        }

        $upcomingJoinedCount = 0;
        if ($options->importJoined) {
            try {
                $st = $source->query("SELECT COUNT(*) FROM `{$p}joined` WHERE status = 'upcoming'");
                $upcomingJoinedCount = (int) ($st ? $st->fetchColumn() : 0);
            } catch (\Throwable) {}
            if ($upcomingJoinedCount > 0) {
                $transformedNotes[] = "Data transformed — {$upcomingJoinedCount} joined listing(s) have status='upcoming'; Luster has no upcoming state so they will be imported as active/approved.";
            }
        }

        // ── Needs decision / conditional ─────────────────────────────────
        $conditionalNotes = [];

        if ($caps['no_parent_id']) {
            $conditionalNotes[] = 'Needs decision — categories.parent_id not present; all categories will be imported as top-level.';
        }

        $smtpKeys = 0;
        try {
            $st = $source->prepare("SELECT COUNT(*) FROM `{$p}settings` WHERE setting_key LIKE 'smtp_%'");
            $st->execute();
            $smtpKeys = (int) $st->fetchColumn();
        } catch (\Throwable) {}
        if ($smtpKeys > 0) {
            $conditionalNotes[] = 'Needs decision — SMTP, admin credentials, and API key found in source settings are excluded from import for security.';
        }

        if (!$caps['has_listing_categories']) {
            $conditionalNotes[] = 'Needs decision — listing_categories junction table not found; owned multi-category data will not be migrated.';
        }
        if (!$caps['has_joined_categories']) {
            $conditionalNotes[] = 'Needs decision — joined_categories junction table not found; joined multi-category data will not be migrated.';
        }

        // Warn when dependent data types are selected without Listings.
        // The Fanatically→Luster listing ID map is rebuilt on every run; selecting
        // Listings is required even when listings already exist in Luster — otherwise
        // all member/code/KIM/adoption rows will be skipped because the map is empty.
        $dependentWithoutListings = [];
        if ($options->importMembers    && !$options->importListings) { $dependentWithoutListings[] = 'Members'; }
        if ($options->importCodes      && !$options->importListings) { $dependentWithoutListings[] = 'Codes'; }
        if ($options->importKim        && !$options->importListings) { $dependentWithoutListings[] = 'KIM List'; }
        if ($options->fnImportAdoption && !$options->importListings) { $dependentWithoutListings[] = 'Adoption Applications'; }
        if (!empty($dependentWithoutListings)) {
            $label = implode(', ', $dependentWithoutListings);
            $conditionalNotes[] = "Needs decision — {$label} selected without Listings. The listing ID map is rebuilt each run; all {$label} rows will be skipped unless Listings is also selected.";
        }

        // Add grouped warning rows
        foreach ([$droppedNotes, $transformedNotes, $conditionalNotes] as $notes) {
            foreach ($notes as $note) {
                $rows[] = ['type' => '—', 'found' => 0, 'import' => 0, 'skip' => 0, 'warnings' => [$note]];
            }
        }

        // ── Per-table counts ─────────────────────────────────────────────
        if ($options->importCategories) {
            $rows[] = $this->previewTable($source, "{$p}categories", 'Categories');
        }
        if ($options->importListings) {
            $rows[] = $this->previewTable($source, "{$p}listings", 'Owned Listings', $this->previewListingStatusWarnings($source, "{$p}listings"));
        }
        if ($options->importMembers) {
            $rows[] = $this->previewTable($source, "{$p}members", 'Members', [
                'Members are stored centrally in Fanatically and will be split into per-listing tables in Luster.',
            ]);
            if ($caps['has_pending_update'] && $pendingUpdateCount > 0) {
                $rows[] = [
                    'type'     => 'Pending Member Updates',
                    'found'    => $pendingUpdateCount,
                    'import'   => $pendingUpdateCount,
                    'skip'     => 0,
                    'warnings' => ['Queued update requests will be migrated to the pending updates queue.'],
                ];
            }
        }
        if ($options->importAffiliates) {
            $rows[] = $this->previewTable($source, "{$p}affiliates", 'Affiliates', [
                'Multi-listing affiliates (listing_ids JSON array) will produce one Luster row per matched listing.',
            ]);
        }
        if ($options->importCodes) {
            $rows[] = $this->previewTable($source, "{$p}codes", 'Codes', [
                'Fanatically stores codes in a flat table. Size labels and donor fields will be normalized into lstr_code_sizes and lstr_code_donors.',
            ]);
        }
        if ($options->importJoined) {
            $rows[] = $this->previewTable($source, "{$p}joined", 'Joined Listings');
        }
        if ($options->importWishlist) {
            if ($caps['has_wishlist']) {
                $wishlistWarns = $this->wishlistAddonInstalled($addonCheckPdo) ? [] : [
                    'Wishlist add-on is not installed — running the import will install it automatically so this data is not lost.',
                ];
                $rows[] = $this->previewTable($source, "{$p}wishlist", 'Wishlist', $wishlistWarns);
            }
        }
        if ($options->importKim) {
            if ($caps['has_kim']) {
                $kimWarns = $this->kimAddonInstalled($addonCheckPdo) ? [] : [
                    'KIM add-on is not installed — running the import will install it automatically so this data is not lost.',
                ];
                $rows[] = $this->previewTable($source, "{$p}kim", 'KIM List', $kimWarns);
            }
        }
        if ($options->fnImportAdoption) {
            if ($caps['has_adoption']) {
                $adoptionWarns = $this->adoptionAddonInstalled($addonCheckPdo) ? [] : [
                    'Adoption add-on is not installed — running the import will install it automatically so this data is not lost.',
                ];
                $rows[] = $this->previewTable($source, "{$p}adoption_applications", 'Adoption Applications', $adoptionWarns);
            }
        }

        return new ImportPreview($rows, 'fanatically_1x', false);
    }

    // -----------------------------------------------------------------------
    // Run (writes to $target)
    // -----------------------------------------------------------------------

    public function run(PDO $source, PDO $target, ImportOptions $options): ImportResult
    {
        set_time_limit(0);
        ini_set('memory_limit', '256M');

        $start  = microtime(true);
        $fnBase = $options->fnTablePrefix;
        $p      = $options->tablePrefix . $fnBase;
        if ($p !== '') {
            self::assertValidPrefix($p);
        }
        $rows   = [];
        $imgRows = [];
        $caps   = $this->detectCapabilities($source, $p);

        $targetSchema = $this->detectTargetSchema($target);

        $migrator = $options->imageMode !== ''
            ? new ImageMigrator(dirname(__DIR__, 2) . '/public')
            : null;

        if ($options->imageMode === 'zip' && $options->zipPath !== '' && $migrator !== null) {
            $this->activeZip = new \ZipArchive();
            if ($this->activeZip->open($options->zipPath) !== true) {
                $this->activeZip = null;
            }
        }

        // Build a seed-name map for category matching (TFL + TAFL canonical names)
        $seedNames = $this->buildSeedNameMap($target);

        // ── Phase 2: Categories ───────────────────────────────────────────
        $categoryIdMap = []; // fanatic category_id → lstr_categories.id
        if ($options->importCategories) {
            [$catRow, $categoryIdMap] = $this->runCategories($source, $target, $options, $p, $caps, $seedNames);
            $rows[] = $catRow;
        }

        // ── Phase 3: Owned Listings + per-listing settings ────────────────
        $listingIdMap    = []; // fanatic listing_id → Luster registry pk
        $listingDbtables = []; // fanatic listing_id → dbtable slug
        if ($options->importListings) {
            [$listRow, $listingIdMap, $listingDbtables, $imgList] =
                $this->runListings($source, $target, $targetSchema, $options, $p, $caps, $categoryIdMap, $migrator);
            $rows[] = $listRow;
            if ($imgList['copied'] + $imgList['skipped'] > 0) {
                $imgRows[] = array_merge(['type' => 'Images (listings)'], $imgList);
            }
        }

        // ── Phase 4: Members ──────────────────────────────────────────────
        if ($options->importMembers && !empty($listingIdMap)) {
            $rows[] = $this->runMembers($source, $target, $targetSchema, $options, $p, $caps, $listingIdMap, $listingDbtables);
        }

        // ── Phase 11: Pending Member Updates ─────────────────────────────
        if ($options->importMembers && $caps['has_pending_update'] && !empty($listingIdMap)) {
            $rows[] = $this->runPendingUpdates($source, $target, $targetSchema, $p, $listingIdMap, $options);
        }

        // ── Phase 5: Affiliates ───────────────────────────────────────────
        if ($options->importAffiliates) {
            [$affRow, $imgAff] = $this->runAffiliates($source, $target, $options, $p, $listingIdMap, $migrator);
            $rows[] = $affRow;
            if ($imgAff['copied'] + $imgAff['skipped'] > 0) {
                $imgRows[] = array_merge(['type' => 'Images (affiliates)'], $imgAff);
            }
        }

        // ── Phase 6: Codes ────────────────────────────────────────────────
        if ($options->importCodes) {
            [$codeRow, $imgCodes] = $this->runCodes($source, $target, $options, $p, $listingIdMap, $migrator);
            $rows[] = $codeRow;
            if ($imgCodes['copied'] + $imgCodes['skipped'] > 0) {
                $imgRows[] = array_merge(['type' => 'Images (codes)'], $imgCodes);
            }
        }

        // ── Phase 7: Joined listings ──────────────────────────────────────
        if ($options->importJoined) {
            [$joinRow, $imgJoin] = $this->runJoined($source, $target, $options, $p, $caps, $categoryIdMap, $migrator);
            $rows[] = $joinRow;
            if ($imgJoin['copied'] + $imgJoin['skipped'] > 0) {
                $imgRows[] = array_merge(['type' => 'Images (joined)'], $imgJoin);
            }
        }

        // ── Phase 8: Wishlist (conditional) ───────────────────────────────
        if ($options->importWishlist && $caps['has_wishlist']) {
            if (AddonAutoInstaller::ensureInstalled($target, WishlistAddon::class) || $this->wishlistAddonInstalled($target)) {
                [$wlRow, $imgWl] = $this->runWishlist($source, $target, $options, $p, $migrator);
                $rows[] = $wlRow;
                if ($imgWl['copied'] + $imgWl['skipped'] > 0) {
                    $imgRows[] = array_merge(['type' => 'Images (wishlist)'], $imgWl);
                }
            } else {
                $rows[] = ['type' => 'Wishlist', 'imported' => 0, 'skipped' => 0,
                           'warnings' => ['Wishlist add-on could not be installed automatically — wishlist entries were skipped.'],
                           'errors' => [], 'skipped_items' => []];
            }
        }

        // ── Phase 9: KIM (conditional) ────────────────────────────────────
        if ($options->importKim && $caps['has_kim']) {
            if (AddonAutoInstaller::ensureInstalled($target, KimAddon::class) || $this->kimAddonInstalled($target)) {
                $rows[] = $this->runKim($source, $target, $options, $p, $listingIdMap);
            } else {
                $rows[] = ['type' => 'KIM List', 'imported' => 0, 'skipped' => 0,
                           'warnings' => ['KIM add-on could not be installed automatically — KIM entries were skipped.'],
                           'errors' => [], 'skipped_items' => []];
            }
        }

        // ── Phase 10: Adoption applications (conditional) ─────────────────
        if ($options->fnImportAdoption && $caps['has_adoption']) {
            if (AddonAutoInstaller::ensureInstalled($target, AdoptionAddon::class) || $this->adoptionAddonInstalled($target)) {
                $rows[] = $this->runAdoption($source, $target, $options, $p, $listingIdMap);
            } else {
                $rows[] = ['type' => 'Adoption Applications', 'imported' => 0, 'skipped' => 0,
                           'warnings' => ['Adoption add-on could not be installed automatically — adoption applications were skipped.'],
                           'errors' => [], 'skipped_items' => []];
            }
        }

        // ── Phase 12: Site settings (optional) ────────────────────────────
        if ($options->fnImportSiteSettings) {
            $rows[] = $this->runSiteSettings($source, $target, $p, $options);
        }

        if ($this->activeZip !== null) {
            $this->activeZip->close();
            $this->activeZip = null;
            if ($options->zipPath !== '') {
                @unlink($options->zipPath);
            }
        }

        return new ImportResult($rows, $imgRows, microtime(true) - $start, 'fanatically_1x');
    }

    // -----------------------------------------------------------------------
    // Phase 2 — Categories
    // -----------------------------------------------------------------------

    /**
     * @param array<string,bool>               $caps
     * @param array<string,array{id:int,network:string}> $seedNames
     * @return array{0:array,1:array<int,int>}
     */
    private function runCategories(PDO $source, PDO $target, ImportOptions $options, string $p, array $caps, array $seedNames): array
    {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $srcIdToNewId = [];

        try {
            $orderBy = $caps['no_parent_id'] ? 'ORDER BY id ASC' : 'ORDER BY parent_id ASC, id ASC';
            $rows    = $source->query("SELECT * FROM `{$p}categories` {$orderBy}")->fetchAll();
        } catch (\Throwable $e) {
            return [
                ['type' => 'Categories', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                 'errors' => ['Could not read source categories: ' . $e->getMessage()], 'skipped_items' => []],
                [],
            ];
        }

        // Build map of which listing approved_by values use each category (for seed matching)
        $catApprovedBy = $this->buildCategoryApprovedByMap($source, $p, $caps);

        // Load existing Luster categories
        $existStmt = $target->prepare('SELECT id, name, source FROM lstr_categories');
        $existStmt->execute();
        $existing = [];
        foreach ($existStmt->fetchAll() as $row) {
            $existing[(string) $row['source'] . ':' . strtolower((string) $row['name'])] = (int) $row['id'];
            $existing['any:' . strtolower((string) $row['name'])]                        = (int) $row['id'];
        }

        $insStmt = $target->prepare(
            'INSERT INTO lstr_categories (name, slug, source, parent_id) VALUES (:name, :slug, :source, :parent_id)'
        );

        // Track slugs already in the DB + newly created in this batch to avoid collisions.
        $seenSlugs = [];
        try {
            foreach ($target->query("SELECT source, slug FROM lstr_categories WHERE slug != ''")->fetchAll() as $sr) {
                $seenSlugs[(string) $sr['source']][(string) $sr['slug']] = true;
            }
        } catch (\Throwable) {}

        // Tracks Luster category IDs inserted during THIS run. When a second source
        // category tries to reuse the same name, we know it was just created and must
        // get its own distinct entry (suffixed) rather than silently collapsing.
        $newlyCreated = []; // luster_id → true

        foreach ($rows as $cat) {
            $srcId    = (int) $cat['id'];
            $name     = trim((string) $cat['name']);
            $parentId = $caps['no_parent_id'] ? 0 : (int) ($cat['parent_id'] ?? 0);

            if ($name === '') {
                $skippedItems[] = ['label' => "Category #{$srcId}", 'reason' => 'Empty name'];
                $skipped++;
                continue;
            }

            $lusterParentId = null;
            if ($parentId > 0 && isset($srcIdToNewId[$parentId])) {
                $lusterParentId = $srcIdToNewId[$parentId];
            }

            // Try to match to an official TFL/TAFL seed
            $nameLower  = strtolower($name);
            $resolvedId = null;

            if (isset($seedNames[$nameLower])) {
                $seedInfo    = $seedNames[$nameLower];
                $approvedBys = $catApprovedBy[$srcId] ?? [];
                $resolvedId  = $this->resolveSeedId($seedInfo, $approvedBys, $existing, $nameLower);
            }

            if ($resolvedId === null) {
                // Custom category resolution.
                // Reuse a pre-existing Luster category with the same name, unless that
                // entry was created during THIS run (meaning a different source category
                // already claimed it). In that case, suffix the name to preserve both.
                $key = 'custom:' . $nameLower;

                if (isset($existing[$key]) && !isset($newlyCreated[$existing[$key]])) {
                    // Safe to reuse — the match is pre-existing in Luster, not a collision
                    $resolvedId = $existing[$key];
                } else {
                    // Either the name is new, or it was just created this run; build a unique name
                    $finalName = $name;
                    $finalKey  = $key;
                    $suffix    = 2;
                    while (isset($existing[$finalKey])) {
                        $finalName = $name . ' (' . $suffix . ')';
                        $finalKey  = 'custom:' . strtolower($finalName);
                        $suffix++;
                    }
                    $baseSlug  = \Luster\Repositories\CategoryRepo::slugify($finalName);
                    $slug      = $baseSlug;
                    $sn = 2;
                    while (isset($seenSlugs['custom'][$slug])) {
                        $slug = $baseSlug . '-' . $sn++;
                    }
                    $seenSlugs['custom'][$slug] = true;

                    try {
                        $insStmt->execute([
                            'name'      => $finalName,
                            'slug'      => $slug,
                            'source'    => 'custom',
                            'parent_id' => $lusterParentId,
                        ]);
                        $resolvedId = (int) $target->lastInsertId();
                        $existing[$finalKey]                         = $resolvedId;
                        $existing['any:' . strtolower($finalName)]   = $resolvedId;
                        $newlyCreated[$resolvedId]                   = true;
                        $imported++;
                    } catch (\Throwable $e) {
                        $errors[] = "Category '{$name}': " . $e->getMessage();
                        continue;
                    }
                }
            } else {
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                }
            }

            $srcIdToNewId[$srcId] = $resolvedId;
        }

        return [
            ['type' => 'Categories', 'imported' => $imported, 'skipped' => $skipped,
             'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems],
            $srcIdToNewId,
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 3 — Owned Listings
    // -----------------------------------------------------------------------

    /**
     * @param array<int,int>    $categoryIdMap
     * @return array{0:array,1:array<int,int>,2:array<int,string>,3:array}
     */
    private function runListings(
        PDO $source, PDO $target, string $targetSchema,
        ImportOptions $options, string $p, array $caps,
        array $categoryIdMap, ?ImageMigrator $migrator
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $listingIdMap    = [];
        $listingDbtables = [];
        $imgCopied       = 0;
        $imgSkipped      = 0;
        $imgErrors       = [];

        [$regTable, $pkCol, $isOwned] = $this->targetRegistryInfo($targetSchema);

        // Build existing URL set, dbtable collision set, and id→dbtable map in one query.
        // The id→dbtable map is used by the overwrite path to reuse the existing table name
        // instead of generating a new one (which would leave members in an orphaned table).
        $existingUrls        = []; // lower(url) → listing id
        $usedDbtables        = []; // dbtable/dbprefix string → true
        $existingIdToDbtable = []; // listing id → dbtable/dbprefix

        $dtCol = $isOwned ? 'dbtable' : 'dbprefix';
        try {
            $stmt = $target->query("SELECT {$pkCol}, url, {$dtCol} FROM `{$regTable}`");
            foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
                $uKey = strtolower(rtrim((string) $row['url'], '/'));
                $rid  = (int) $row[$pkCol];
                $dt   = (string) $row[$dtCol];
                if ($uKey !== '') {
                    $existingUrls[$uKey] = $rid;
                }
                $usedDbtables[$dt]         = true;
                $existingIdToDbtable[$rid] = $dt;
            }
        } catch (\Throwable) {}

        // Map of fanatically external_id → Luster listing_id, used as fallback
        // matching when a source listing has a blank URL (URL match is always primary).
        // Refs pointing to listings that no longer exist in the registry are filtered out
        // so a deleted listing does not silently absorb a re-imported blank-URL listing.
        $existingByExternalId = []; // (string) fanatically_id → int luster_id
        try {
            $stmt = $target->prepare(
                "SELECT listing_id, external_id FROM lstr_listing_external_refs WHERE source = 'fanatically'"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll() as $row) {
                $existingByExternalId[(string) $row['external_id']] = (int) $row['listing_id'];
            }
        } catch (\Throwable) {}
        $existingByExternalId = array_filter(
            $existingByExternalId,
            fn(int $id): bool => isset($existingIdToDbtable[$id])
        );

        try {
            $srcListings = $source->query("SELECT * FROM `{$p}listings` ORDER BY id ASC")->fetchAll();
        } catch (\Throwable $e) {
            return [
                ['type' => 'Owned Listings', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                 'errors' => ['Could not read listings: ' . $e->getMessage()], 'skipped_items' => [],
                 'image_copied' => 0, 'image_skipped' => 0, 'image_errors' => []],
                [], [], ['copied' => 0, 'skipped' => 0, 'errors' => []],
            ];
        }

        // Load global Fanatically settings that apply to all listings.
        // Fanatically does not store these per-listing; they are site-wide.
        $globalSettings = [];
        try {
            $st = $source->query("SELECT setting_key, setting_value FROM `{$p}settings`");
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $globalSettings[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Throwable) {}

        // Build category assignment junction for all listings at once
        $lcJunction = []; // listing_id → [category_id, ...]
        if ($caps['has_listing_categories']) {
            try {
                $st = $source->query("SELECT listing_id, category_id FROM `{$p}listing_categories`");
                foreach ($st ? $st->fetchAll() : [] as $row) {
                    $lcJunction[(int) $row['listing_id']][] = (int) $row['category_id'];
                }
            } catch (\Throwable) {}
        }

        foreach ($srcListings as $lst) {
            $srcId  = (int) $lst['id'];
            $url    = rtrim(trim((string) ($lst['url'] ?? '')), '/');
            $urlKey = strtolower($url);

            // Status mapping — whitelist-only: only 'current' and 'upcoming' get their
            // corresponding Luster states; closed, adopted_out, pending, and any future
            // unknown status all map to inactive (trouble=1) to fail safe.
            $status   = (string) ($lst['status'] ?? 'current');
            $upcoming = $status === 'upcoming' ? 1 : 0;
            $trouble  = ($status !== 'current' && $status !== 'upcoming') ? 1 : 0;

            // Blank-URL listings are imported as inactive regardless of their source status;
            // they can still be matched on re-import via the external-ref map.
            if ($url === '') {
                $trouble  = 1;
                $upcoming = 0;
            }

            // Preserve unmappable source states so a future lifecycle enhancement can
            // recover them without requiring a full re-import. Only written when the
            // status or blank-URL state produced a lossy mapping.
            $sourceStatusMeta = (($trouble === 1 && $status !== 'pending') || $url === '')
                ? ['source_status' => $status]
                : null;

            // approved_by: '' → '', 'tfl' → 'tfl', 'afl' → 'tafl'
            $approvedBy = (string) ($lst['approved_by'] ?? '');
            if ($approvedBy === 'afl') {
                $approvedBy = 'tafl';
            }

            $openedDate  = (string) ($lst['opened_date'] ?? '');
            $description = (string) ($lst['description'] ?? '');

            // URL match is primary (authoritative Luster state); external-ref is fallback
            // only when URL is blank (handles listings that have no web presence yet).
            $existingId = ($url !== '' ? ($existingUrls[$urlKey] ?? null) : null)
                ?? $existingByExternalId[(string) $srcId]
                ?? null;

            if ($existingId !== null) {
                if ($options->conflictStrategy === 'skip') {
                    $listingIdMap[$srcId] = $existingId;
                    $this->upsertListingSettings($target, $existingId, $approvedBy, $lst['last_updated'] ?? null, $globalSettings, (int) ($lst['for_adoption'] ?? 0), $description);
                    // Backfill opened date only if target row currently has none.
                    // CAST(opened AS CHAR) avoids MySQL strict mode rejecting the ''
                    // literal as an invalid date when comparing a DATE column.
                    if ($openedDate !== '') {
                        $idCol = $isOwned ? 'listingid' : 'id';
                        $tbl   = $isOwned ? '`owned`' : '`enth_fanlistings`';
                        try {
                            $target->prepare(
                                "UPDATE {$tbl} SET opened=:opened WHERE {$idCol}=:id AND (opened IS NULL OR CAST(opened AS CHAR)='')"
                            )->execute(['opened' => $openedDate, 'id' => $existingId]);
                        } catch (\Throwable) {}
                    }
                    // Record external ref even in skip mode — ensures a re-run
                    // backfills the mapping without needing an overwrite pass.
                    $this->upsertExternalRef($target, $existingId, 'fanatically', (string) $srcId, $sourceStatusMeta);
                    $fansFansId = trim((string) ($lst['fansfansfans_id'] ?? ''));
                    if ($fansFansId !== '') {
                        $this->upsertExternalRef($target, $existingId, 'fansfansfans', $fansFansId);
                    }
                    $skipped++;
                    continue;
                }
                // overwrite: fall through and update
            }

            $title   = (string) ($lst['title']   ?? '');
            $subject = (string) ($lst['subject']  ?? '');

            // Overwrite: reuse the existing listing's actual dbtable so that member
            // rows land in the correct table, not a freshly generated orphan.
            // Insert: generate a new unique slug.
            $existingIdForOverwrite = $existingId;
            if ($existingIdForOverwrite !== null && isset($existingIdToDbtable[$existingIdForOverwrite])) {
                $dbtable = $existingIdToDbtable[$existingIdForOverwrite];
            } else {
                $dbtable = $this->generateDbtable($subject ?: $title, $usedDbtables);
                $usedDbtables[$dbtable] = true;
            }

            $catIds = $lcJunction[$srcId] ?? [];
            $primaryCatId = null;
            if (!empty($catIds)) {
                $primaryCatId = $categoryIdMap[$catIds[0]] ?? null;
            }

            try {
                if ($isOwned) {
                    if ($existingId !== null) {
                        $target->prepare(
                            "UPDATE `owned` SET title=:title, subject=:subject, url=:url,
                             catid=:catid, status=:status, opened=:opened
                             WHERE listingid=:id"
                        )->execute([
                            'title' => $title, 'subject' => $subject, 'url' => $url,
                            'catid' => $primaryCatId,
                            'status' => ($upcoming ? 1 : ($trouble ? 0 : 2)),
                            'opened' => $openedDate ?: null,
                            'id' => $existingId,
                        ]);
                        $newId = $existingId;
                    } else {
                        $target->prepare(
                            "INSERT INTO `owned` (dbtable, title, subject, url, catid, status, opened)
                             VALUES (:dbtable, :title, :subject, :url, :catid, :status, :opened)"
                        )->execute([
                            'dbtable' => $dbtable, 'title' => $title, 'subject' => $subject,
                            'url' => $url, 'catid' => $primaryCatId,
                            'status' => ($upcoming ? 1 : ($trouble ? 0 : 2)),
                            'opened' => $openedDate ?: null,
                        ]);
                        $newId = (int) $target->lastInsertId();
                        if ($urlKey !== '') {
                            $existingUrls[$urlKey] = $newId;
                        }
                        $imported++;
                    }
                } else {
                    if ($existingId !== null) {
                        $target->prepare(
                            "UPDATE `enth_fanlistings` SET name=:name, subject=:subject, url=:url,
                             catid=:catid, upcoming=:upcoming, trouble=:trouble, opened=:opened
                             WHERE id=:id"
                        )->execute([
                            'name' => $title, 'subject' => $subject, 'url' => $url,
                            'catid' => $primaryCatId, 'upcoming' => $upcoming, 'trouble' => $trouble,
                            'opened' => $openedDate ?: null,
                            'id' => $existingId,
                        ]);
                        $newId = $existingId;
                    } else {
                        $target->prepare(
                            "INSERT INTO `enth_fanlistings` (dbprefix, name, subject, url, catid, upcoming, trouble, opened)
                             VALUES (:dbprefix, :name, :subject, :url, :catid, :upcoming, :trouble, :opened)"
                        )->execute([
                            'dbprefix' => $dbtable, 'name' => $title, 'subject' => $subject,
                            'url' => $url, 'catid' => $primaryCatId,
                            'upcoming' => $upcoming, 'trouble' => $trouble, 'opened' => $openedDate ?: null,
                        ]);
                        $newId = (int) $target->lastInsertId();
                        if ($urlKey !== '') {
                            $existingUrls[$urlKey] = $newId;
                        }
                        $imported++;
                    }
                }

                $listingIdMap[$srcId]    = $newId;
                $listingDbtables[$srcId] = $dbtable;

                // Record external refs. REPLACE INTO repairs a stale mapping when
                // the same external ID re-imports to a different Luster listing.
                $this->upsertExternalRef($target, $newId, 'fanatically', (string) $srcId, $sourceStatusMeta);
                $existingByExternalId[(string) $srcId] = $newId;
                $fansFansId = trim((string) ($lst['fansfansfans_id'] ?? ''));
                if ($fansFansId !== '') {
                    $this->upsertExternalRef($target, $newId, 'fansfansfans', $fansFansId);
                }

                // Write lstr_listing_categories junction
                if (!empty($catIds)) {
                    foreach ($catIds as $srcCatId) {
                        $lusterCatId = $categoryIdMap[$srcCatId] ?? null;
                        if ($lusterCatId === null) {
                            continue;
                        }
                        try {
                            $target->prepare(
                                'INSERT IGNORE INTO lstr_listing_categories (fanlisting_id, category_id)
                                 VALUES (:fid, :cid)'
                            )->execute(['fid' => $newId, 'cid' => $lusterCatId]);
                        } catch (\Throwable) {}
                    }
                }

                // Insert/update lstr_fanlisting_settings
                $this->upsertListingSettings($target, $newId, $approvedBy, $lst['last_updated'] ?? null, $globalSettings, (int) ($lst['for_adoption'] ?? 0), $description);

                // Image migration for listing banner
                $imageFile = (string) ($lst['image_file'] ?? '');
                if ($migrator !== null && $imageFile !== '') {
                    $resolved = $this->resolveListingImagePath($imageFile, $srcId, $options);
                    $result   = $this->migrateImage($migrator, $resolved, 'listings', $options);
                    if ($result !== null) {
                        try {
                            $target->prepare(
                                'UPDATE lstr_fanlisting_settings SET image=:image WHERE fanlisting_id=:fid'
                            )->execute(['image' => $result, 'fid' => $newId]);
                        } catch (\Throwable) {}
                        $imgCopied++;
                    } else {
                        $imgSkipped++;
                    }
                }

            } catch (\Throwable $e) {
                $errors[] = "Listing '{$title}': " . $e->getMessage();
            }
        }

        return [
            ['type' => 'Owned Listings', 'imported' => $imported, 'skipped' => $skipped,
             'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems,
             'image_copied' => $imgCopied, 'image_skipped' => $imgSkipped, 'image_errors' => $imgErrors],
            $listingIdMap,
            $listingDbtables,
            ['copied' => $imgCopied, 'skipped' => $imgSkipped, 'errors' => $imgErrors],
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 4 — Members
    // -----------------------------------------------------------------------

    /**
     * @param array<int,int>    $listingIdMap
     * @param array<int,string> $listingDbtables
     */
    private function runMembers(
        PDO $source, PDO $target, string $targetSchema,
        ImportOptions $options, string $p, array $caps,
        array $listingIdMap, array $listingDbtables
    ): array {
        $imported         = 0;
        $skipped          = 0;
        $errors           = [];
        $skippedItems     = [];
        $registeredFields = []; // Luster listing IDs for which field defs have been persisted

        [$regTable, $pkCol, $isOwned] = $this->targetRegistryInfo($targetSchema);

        // Load custom_fields JSON per listing
        $customFieldsMap = [];
        try {
            $st = $source->query("SELECT id, custom_fields FROM `{$p}listings`");
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $json = trim((string) ($row['custom_fields'] ?? ''));
                if ($json !== '' && $json !== 'null') {
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        $customFieldsMap[(int) $row['id']] = $decoded;
                    }
                }
            }
        } catch (\Throwable) {}

        $offset = 0;
        do {
            try {
                $st   = $source->prepare("SELECT * FROM `{$p}members` LIMIT :limit OFFSET :offset");
                $st->bindValue(':limit',  self::CHUNK, PDO::PARAM_INT);
                $st->bindValue(':offset', $offset,     PDO::PARAM_INT);
                $st->execute();
                $chunk = $st->fetchAll();
            } catch (\Throwable $e) {
                $errors[] = 'Could not read members: ' . $e->getMessage();
                break;
            }

            foreach ($chunk as $mem) {
                $srcListingId = (int) ($mem['listing_id'] ?? 0);
                $newListingId = $listingIdMap[$srcListingId] ?? null;
                if ($newListingId === null) {
                    $skippedItems[] = ['label' => $mem['email'] ?? '?', 'reason' => 'Listing not imported'];
                    $skipped++;
                    continue;
                }

                $dbtable = $listingDbtables[$srcListingId] ?? null;
                if ($dbtable === null) {
                    // Existing listing — look up its dbtable
                    try {
                        $col     = $isOwned ? 'dbtable' : 'dbprefix';
                        $idCol   = $isOwned ? 'listingid' : 'id';
                        $st2     = $target->prepare("SELECT {$col} FROM `{$regTable}` WHERE {$idCol}=:id");
                        $st2->execute(['id' => $newListingId]);
                        $dbtable = $st2->fetchColumn() ?: null;
                    } catch (\Throwable) {}
                }
                if ($dbtable === null) {
                    $skipped++;
                    continue;
                }

                // Ensure member table exists.
                // enth_ schema: MemberRepo resolves table as {dbprefix}members, so the
                // actual table name is $dbtable . 'members'. Column names use additional_ prefix.
                $memberTable = $isOwned ? $dbtable : $dbtable . 'members';
                $fields = array_map(
                    fn($f) => $this->sanitizeFieldName((string) ($f['name'] ?? '')),
                    $customFieldsMap[$srcListingId] ?? []
                );
                $fields = array_values(array_filter($fields, fn($f) => $f !== ''));
                $this->createMemberTable($target, $memberTable, $fields, $isOwned);

                // Persist field definitions to the listing registry so admin forms
                // know the imported columns exist (once per listing per run).
                if (!empty($fields) && !isset($registeredFields[$newListingId])) {
                    $this->persistCustomFieldDefinitions($target, $newListingId, $isOwned, $fields);
                    $registeredFields[$newListingId] = true;
                }

                $email = strtolower(trim((string) ($mem['email'] ?? '')));
                if ($email === '') {
                    $skipped++;
                    continue;
                }

                // Check duplicate
                try {
                    $ck = $target->prepare("SELECT COUNT(*) FROM `{$memberTable}` WHERE email=:email");
                    $ck->execute(['email' => $email]);
                    $exists = (int) $ck->fetchColumn() > 0;
                } catch (\Throwable) {
                    $exists = false;
                }

                if ($exists && $options->conflictStrategy === 'skip') {
                    $skipped++;
                    continue;
                }

                $approved = (int) ($mem['is_approved'] ?? 1);
                $pending  = $approved === 1 ? 0 : 1;

                // Parse custom field values; map to actual column names for the target schema.
                $fieldValues = []; // actual_column_name => value
                $cfJson      = trim((string) ($mem['custom_field_values'] ?? ''));
                if ($cfJson !== '' && $cfJson !== 'null') {
                    $cf = json_decode($cfJson, true);
                    if (is_array($cf)) {
                        foreach ($cf as $origName => $val) {
                            $safe = $this->sanitizeFieldName((string) $origName);
                            if ($safe !== '') {
                                $colName = $isOwned ? $safe : 'additional_' . $safe;
                                $fieldValues[$colName] = (string) $val;
                            }
                        }
                    }
                }

                try {
                    $cols = 'email, name, country, url, pending, password, showemail, showurl, added';
                    $vals = ':email, :name, :country, :url, :pending, :password, :showemail, :showurl, :added';
                    $params = [
                        'email'     => $email,
                        'name'      => (string) ($mem['name']    ?? ''),
                        'country'   => (string) ($mem['country'] ?? ''),
                        'url'       => (string) ($mem['url']     ?? ''),
                        'pending'   => $pending,
                        'password'  => (string) ($mem['password_hash'] ?? ''),
                        'showemail' => 1,
                        'showurl'   => 1,
                        'added'     => (string) ($mem['joined_at'] ?? date('Y-m-d')),
                    ];
                    foreach ($fieldValues as $colName => $colVal) {
                        $cols   .= ", `{$colName}`";
                        $vals   .= ", :{$colName}";
                        $params[$colName] = $colVal;
                    }

                    if ($exists) {
                        $setClauses = 'name=:name, country=:country, url=:url, pending=:pending, password=:password, added=:added';
                        foreach ($fieldValues as $colName => $_) {
                            $setClauses .= ", `{$colName}`=:{$colName}";
                        }
                        $target->prepare("UPDATE `{$memberTable}` SET {$setClauses} WHERE email=:email")
                               ->execute($params);
                    } else {
                        $target->prepare("INSERT INTO `{$memberTable}` ({$cols}) VALUES ({$vals})")
                               ->execute($params);
                        $imported++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Member '{$email}': " . $e->getMessage();
                }
            }

            $offset += self::CHUNK;
        } while (count($chunk) === self::CHUNK);

        return [
            'type' => 'Members', 'imported' => $imported, 'skipped' => $skipped,
            'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems,
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 5 — Affiliates
    // -----------------------------------------------------------------------

    /** @param array<int,int> $listingIdMap */
    private function runAffiliates(
        PDO $source, PDO $target,
        ImportOptions $options, string $p,
        array $listingIdMap, ?ImageMigrator $migrator
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgSkipped   = 0;
        $imgErrors    = [];

        // Build existing (fanlisting_id, url) → id map for dedup / overwrite
        $existingSet = []; // key → affiliate id
        try {
            $st = $target->query('SELECT id, fanlisting_id, url FROM lstr_affiliates');
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $key = ((int) ($row['fanlisting_id'] ?? 0)) . ':' . strtolower(rtrim((string) $row['url'], '/'));
                $existingSet[$key] = (int) $row['id'];
            }
        } catch (\Throwable) {}

        $insStmt = $target->prepare(
            'INSERT INTO lstr_affiliates
               (fanlisting_id, name, email, url, image, pending, added_at,
                link_status, link_checked_at, link_redirect_url,
                reciprocal_status, reciprocal_checked_at)
             VALUES
               (:fid, :name, :email, :url, :image, :pending, :added_at,
                :link_status, :link_checked_at, :link_redirect_url,
                :reciprocal_status, :reciprocal_checked_at)'
        );

        try {
            $st   = $source->query("SELECT * FROM `{$p}affiliates` ORDER BY id ASC");
            $rows = $st ? $st->fetchAll() : [];
        } catch (\Throwable $e) {
            return [
                ['type' => 'Affiliates', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                 'errors' => ['Could not read affiliates: ' . $e->getMessage()], 'skipped_items' => []],
                ['copied' => 0, 'skipped' => 0, 'errors' => []],
            ];
        }

        foreach ($rows as $aff) {
            $url  = rtrim(trim((string) ($aff['url'] ?? '')), '/');
            $rawIds = (string) ($aff['listing_ids'] ?? '');

            // Decode listing_ids to determine collective vs per-listing
            $decoded     = null;
            $isCollective = false;
            if ($rawIds === '' || $rawIds === '[]' || $rawIds === 'null') {
                $isCollective = true;
            } else {
                $decoded = json_decode($rawIds, true);
                if (!is_array($decoded) || empty($decoded) || $decoded === [0]) {
                    $isCollective = true;
                }
            }

            $targets = [];
            if ($isCollective) {
                $targets[] = null; // fanlisting_id = NULL
            } elseif (is_array($decoded)) {
                foreach ($decoded as $srcLid) {
                    $lid = (int) $srcLid;
                    if ($lid === 0) {
                        $targets[] = null;
                    } elseif (isset($listingIdMap[$lid])) {
                        $targets[] = $listingIdMap[$lid];
                    } else {
                        $skippedItems[] = ['label' => $url, 'reason' => "Fanatically listing_id {$lid} not imported"];
                        $skipped++;
                    }
                }
            }

            // Migrate image once
            $imgFile  = (string) ($aff['image_file'] ?? '');
            $newImage = null;
            if ($migrator !== null && $imgFile !== '') {
                $resolved = $this->resolveAffiliateImagePath($imgFile, $options);
                $newImage = $this->migrateImage($migrator, $resolved, 'affiliates', $options);
                if ($newImage !== null) {
                    $imgCopied++;
                } else {
                    $imgSkipped++;
                }
            }

            $name    = (string) ($aff['subject'] ?? '');
            $email   = (string) ($aff['email']   ?? '');
            $pending = (int) ($aff['is_approved'] ?? 1) === 0 ? 1 : 0;
            $addedAt = (string) ($aff['added_at'] ?? date('Y-m-d'));

            foreach ($targets as $fid) {
                $key = (($fid ?? 0)) . ':' . strtolower($url);
                if (isset($existingSet[$key])) {
                    if ($options->conflictStrategy === 'skip') {
                        $skipped++;
                        continue;
                    }
                    // overwrite: update the existing row
                    try {
                        $target->prepare(
                            'UPDATE lstr_affiliates
                             SET name=:name, email=:email, image=:image, pending=:pending,
                                 link_status=:link_status, link_checked_at=:link_checked_at,
                                 link_redirect_url=:link_redirect_url,
                                 reciprocal_status=:reciprocal_status,
                                 reciprocal_checked_at=:reciprocal_checked_at
                             WHERE id=:id'
                        )->execute([
                            'name'                  => $name,
                            'email'                 => $email,
                            'image'                 => $newImage,
                            'pending'               => $pending,
                            'link_status'           => $this->mapLinkStatus((string) ($aff['link_status'] ?? 'unchecked')),
                            'link_checked_at'       => ($aff['link_checked_at'] ?? null) ?: null,
                            'link_redirect_url'     => (string) ($aff['link_redirect_url'] ?? ''),
                            'reciprocal_status'     => $this->mapReciprocalStatus((string) ($aff['reciprocal_status'] ?? 'unchecked')),
                            'reciprocal_checked_at' => ($aff['reciprocal_checked_at'] ?? null) ?: null,
                            'id'                    => $existingSet[$key],
                        ]);
                        $imported++;
                    } catch (\Throwable $e) {
                        $errors[] = "Affiliate '{$url}': " . $e->getMessage();
                    }
                    continue;
                }

                try {
                    $insStmt->execute([
                        'fid'                   => $fid,
                        'name'                  => $name,
                        'email'                 => $email,
                        'url'                   => $url,
                        'image'                 => $newImage,
                        'pending'               => $pending,
                        'added_at'              => $addedAt,
                        'link_status'           => $this->mapLinkStatus((string) ($aff['link_status'] ?? 'unchecked')),
                        'link_checked_at'       => ($aff['link_checked_at'] ?? null) ?: null,
                        'link_redirect_url'     => (string) ($aff['link_redirect_url'] ?? ''),
                        'reciprocal_status'     => $this->mapReciprocalStatus((string) ($aff['reciprocal_status'] ?? 'unchecked')),
                        'reciprocal_checked_at' => ($aff['reciprocal_checked_at'] ?? null) ?: null,
                    ]);
                    $existingSet[$key] = (int) $target->lastInsertId();
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Affiliate '{$url}': " . $e->getMessage();
                }
            }
        }

        return [
            ['type' => 'Affiliates', 'imported' => $imported, 'skipped' => $skipped,
             'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems],
            ['copied' => $imgCopied, 'skipped' => $imgSkipped, 'errors' => $imgErrors],
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 6 — Codes
    // -----------------------------------------------------------------------

    /** @param array<int,int> $listingIdMap */
    private function runCodes(
        PDO $source, PDO $target,
        ImportOptions $options, string $p,
        array $listingIdMap, ?ImageMigrator $migrator
    ): array {
        $imported      = 0;
        $skipped       = 0;
        $errors        = [];
        $skippedItems  = [];
        $imgCopied     = 0;
        $imgSkipped    = 0;
        $imgErrors     = [];

        // Per-listing code size ID maps: [listing_id][label] → lstr_code_sizes.id
        $codeSizeIdMap  = [];
        $codeDonorIdMap = [];

        $offset = 0;
        do {
            try {
                $st = $source->prepare("SELECT * FROM `{$p}codes` LIMIT :limit OFFSET :offset");
                $st->bindValue(':limit',  self::CHUNK, PDO::PARAM_INT);
                $st->bindValue(':offset', $offset,     PDO::PARAM_INT);
                $st->execute();
                $chunk = $st->fetchAll();
            } catch (\Throwable $e) {
                $errors[] = 'Could not read codes: ' . $e->getMessage();
                break;
            }

            foreach ($chunk as $code) {
                $srcListingId = (int) ($code['listing_id'] ?? 0);
                $newListingId = $listingIdMap[$srcListingId] ?? null;
                if ($newListingId === null) {
                    $skippedItems[] = ['label' => (string) ($code['file_name'] ?? '?'), 'reason' => 'Listing not imported'];
                    $skipped++;
                    continue;
                }

                $sizeLabel  = trim((string) ($code['size_label'] ?? ''));
                $fileName   = trim((string) ($code['file_name']  ?? ''));

                if ($fileName === '') {
                    $skipped++;
                    continue;
                }

                // Upsert code size first — the size_id is part of the dedup key.
                $sizeId = $this->upsertCodeSize($target, $newListingId, $sizeLabel, $codeSizeIdMap);

                // Dedup by (fanlisting_id, name, size_id): same filename under a different
                // size label is a distinct code row and must not be collapsed.
                $existingCodeId = null;
                try {
                    if ($sizeId !== null) {
                        $ck = $target->prepare(
                            'SELECT id FROM lstr_codes WHERE fanlisting_id=:fid AND name=:name AND size_id=:sid LIMIT 1'
                        );
                        $ck->execute(['fid' => $newListingId, 'name' => $fileName, 'sid' => $sizeId]);
                    } else {
                        $ck = $target->prepare(
                            'SELECT id FROM lstr_codes WHERE fanlisting_id=:fid AND name=:name AND size_id IS NULL LIMIT 1'
                        );
                        $ck->execute(['fid' => $newListingId, 'name' => $fileName]);
                    }
                    $ckRow = $ck->fetch();
                    $existingCodeId = $ckRow !== false ? (int) $ckRow['id'] : null;
                } catch (\Throwable) {}

                if ($existingCodeId !== null && $options->conflictStrategy === 'skip') {
                    $skipped++;
                    continue;
                }

                // Upsert donor
                $donorName  = trim((string) ($code['donor_name']  ?? ''));
                $donorUrl   = trim((string) ($code['donor_url']   ?? ''));
                $donorEmail = trim((string) ($code['donor_email'] ?? ''));
                $donorId    = null;
                if ($donorName !== '') {
                    $donorId = $this->upsertCodeDonor($target, $newListingId, $donorName, $donorUrl, $donorEmail, $codeDonorIdMap);
                }

                // Migrate image
                $newImageFile = $fileName;
                if ($migrator !== null) {
                    $resolved    = $this->resolveCodeImagePath($fileName, $sizeLabel, $srcListingId, $options);
                    $migrated    = $this->migrateImage($migrator, $resolved, 'codes', $options);
                    if ($migrated !== null) {
                        $newImageFile = $migrated;
                        $imgCopied++;
                    } else {
                        $imgSkipped++;
                    }
                }

                $codeStatus = (int) ($code['is_approved'] ?? 1) === 1 ? 'approved' : 'pending';
                $codeW      = $codeSizeIdMap[$newListingId]['__dims'][$sizeLabel]['w'] ?? 0;
                $codeH      = $codeSizeIdMap[$newListingId]['__dims'][$sizeLabel]['h'] ?? 0;

                try {
                    if ($existingCodeId !== null) {
                        // overwrite: update the existing row
                        $target->prepare(
                            'UPDATE lstr_codes SET size_id=:sid, donor_id=:did, filename=:filename,
                             status=:status, width=:w, height=:h WHERE id=:id'
                        )->execute([
                            'sid' => $sizeId, 'did' => $donorId, 'filename' => $newImageFile,
                            'status' => $codeStatus, 'w' => $codeW, 'h' => $codeH,
                            'id' => $existingCodeId,
                        ]);
                    } else {
                        $target->prepare(
                            'INSERT INTO lstr_codes
                               (fanlisting_id, size_id, donor_id, name, filename, status, width, height)
                             VALUES (:fid, :sid, :did, :name, :filename, :status, :w, :h)'
                        )->execute([
                            'fid' => $newListingId, 'sid' => $sizeId, 'did' => $donorId,
                            'name' => $fileName,    'filename' => $newImageFile,
                            'status' => $codeStatus, 'w' => $codeW, 'h' => $codeH,
                        ]);
                    }
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Code '{$fileName}': " . $e->getMessage();
                }
            }

            $offset += self::CHUNK;
        } while (count($chunk) === self::CHUNK);

        return [
            ['type' => 'Codes', 'imported' => $imported, 'skipped' => $skipped,
             'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems],
            ['copied' => $imgCopied, 'skipped' => $imgSkipped, 'errors' => $imgErrors],
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 7 — Joined Listings
    // -----------------------------------------------------------------------

    /**
     * @param array<int,int> $categoryIdMap
     */
    private function runJoined(
        PDO $source, PDO $target,
        ImportOptions $options, string $p, array $caps,
        array $categoryIdMap, ?ImageMigrator $migrator
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgSkipped   = 0;
        $imgErrors    = [];

        $existingUrls = [];
        try {
            $st = $target->query('SELECT id, url FROM lstr_joined');
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $existingUrls[strtolower(rtrim((string) $row['url'], '/'))] = (int) $row['id'];
            }
        } catch (\Throwable) {}

        // Load joined categories junction
        $joinedCatJunction = []; // joined_id → [category_id, ...]
        if ($caps['has_joined_categories']) {
            try {
                $st = $source->query("SELECT joined_id, category_id FROM `{$p}joined_categories`");
                foreach ($st ? $st->fetchAll() : [] as $row) {
                    $joinedCatJunction[(int) $row['joined_id']][] = (int) $row['category_id'];
                }
            } catch (\Throwable) {}
        }

        // Pre-load category source values for categories_json building
        $catSourceMap = []; // lstr_categories.id → source ('tfl'|'tafl'|'custom')
        $catNameMap   = []; // lstr_categories.id → name
        try {
            $st = $target->query('SELECT id, name, source FROM lstr_categories');
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $catSourceMap[(int) $row['id']] = (string) $row['source'];
                $catNameMap[(int) $row['id']]   = (string) $row['name'];
            }
        } catch (\Throwable) {}

        $insStmt = $target->prepare(
            'INSERT INTO lstr_joined
               (name, url, image, category, category_id, date_joined, active, status, categories_json, sort_order,
                link_status, link_checked_at, link_redirect_url)
             VALUES
               (:name, :url, :image, :category, :category_id, :date_joined, :active, :status, :categories_json, 0,
                :link_status, :link_checked_at, :link_redirect_url)'
        );

        try {
            $st   = $source->query("SELECT * FROM `{$p}joined` ORDER BY id ASC");
            $rows = $st ? $st->fetchAll() : [];
        } catch (\Throwable $e) {
            return [
                ['type' => 'Joined Listings', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                 'errors' => ['Could not read joined listings: ' . $e->getMessage()], 'skipped_items' => []],
                ['copied' => 0, 'skipped' => 0, 'errors' => []],
            ];
        }

        foreach ($rows as $jl) {
            $srcId = (int) $jl['id'];
            $url   = rtrim(trim((string) ($jl['url'] ?? '')), '/');
            $key   = strtolower($url);

            if ($url === '') {
                $skipped++;
                continue;
            }

            if (isset($existingUrls[$key]) && $options->conflictStrategy === 'skip') {
                $skipped++;
                continue;
            }

            $status     = (string) ($jl['status'] ?? 'current');
            $active     = in_array($status, ['current', 'joined', 'upcoming'], true) ? 1 : 0;
            $lstrStatus = ($status === 'pending' || $status === 'inactive') ? 'pending' : 'joined';

            // Category resolution — build primary (legacy) fields + categories_json
            $catIds        = $joinedCatJunction[$srcId] ?? [];
            $catName       = '';
            $catId         = null;
            $categoriesJson = null;

            if (!empty($catIds)) {
                // Multi-category: resolve all, build JSON array for categories_json
                $jsonEntries = [];
                foreach ($catIds as $i => $srcCatId) {
                    $lusterCatId = $categoryIdMap[$srcCatId] ?? null;
                    if ($lusterCatId === null) {
                        continue;
                    }
                    $resolvedName   = $catNameMap[$lusterCatId]   ?? '';
                    $resolvedSource = $catSourceMap[$lusterCatId] ?? 'custom';
                    $jsonEntries[]  = ['name' => $resolvedName, 'network' => $resolvedSource];
                    if ($i === 0) {
                        $catId   = $lusterCatId;
                        $catName = $resolvedName;
                    }
                }
                if (!empty($jsonEntries)) {
                    $categoriesJson = json_encode($jsonEntries, JSON_UNESCAPED_UNICODE);
                }
            } elseif ($caps['has_joined_categories'] === false) {
                // No junction table — fall back to legacy category_id column
                $legacyCatId = (int) ($jl['category_id'] ?? 0);
                if ($legacyCatId > 0) {
                    $lusterCatId = $categoryIdMap[$legacyCatId] ?? null;
                    if ($lusterCatId !== null) {
                        $catId   = $lusterCatId;
                        $catName = $catNameMap[$lusterCatId] ?? '';
                        $source2 = $catSourceMap[$lusterCatId] ?? 'custom';
                        $categoriesJson = json_encode(
                            [['name' => $catName, 'network' => $source2]],
                            JSON_UNESCAPED_UNICODE
                        );
                    }
                }
            }

            // Migrate image
            $imgFile  = (string) ($jl['image_file'] ?? '');
            $newImage = null;
            if ($migrator !== null && $imgFile !== '') {
                $resolved = $this->resolveJoinedImagePath($imgFile, $options);
                $newImage = $this->migrateImage($migrator, $resolved, 'joined', $options);
                if ($newImage !== null) {
                    $imgCopied++;
                } else {
                    $imgSkipped++;
                }
            }

            $name            = (string) ($jl['subject'] ?? '');
            $dateJoined      = (string) ($jl['added_at'] ?? date('Y-m-d'));
            $linkStatus      = $this->mapLinkStatus((string) ($jl['link_status'] ?? 'unchecked'));
            $linkCheckedAt   = ($jl['link_checked_at'] ?? null) ?: null;
            $linkRedirectUrl = (string) ($jl['link_redirect_url'] ?? '');

            try {
                if (isset($existingUrls[$key])) {
                    $target->prepare(
                        'UPDATE lstr_joined SET name=:name, image=:image, category=:category,
                         category_id=:cid, date_joined=:dj, active=:active, status=:status,
                         categories_json=:cjson, link_status=:link_status,
                         link_checked_at=:link_checked_at, link_redirect_url=:link_redirect_url
                         WHERE id=:id'
                    )->execute([
                        'name'            => $name,
                        'image'           => $newImage,
                        'category'        => $catName,
                        'cid'             => $catId,
                        'dj'              => $dateJoined,
                        'active'          => $active,
                        'status'          => $lstrStatus,
                        'cjson'           => $categoriesJson,
                        'link_status'     => $linkStatus,
                        'link_checked_at' => $linkCheckedAt,
                        'link_redirect_url' => $linkRedirectUrl,
                        'id'              => $existingUrls[$key],
                    ]);
                    $newId = $existingUrls[$key];
                } else {
                    $insStmt->execute([
                        'name'             => $name,
                        'url'              => $url,
                        'image'            => $newImage,
                        'category'         => $catName,
                        'category_id'      => $catId,
                        'date_joined'      => $dateJoined,
                        'active'           => $active,
                        'status'           => $lstrStatus,
                        'categories_json'  => $categoriesJson,
                        'link_status'      => $linkStatus,
                        'link_checked_at'  => $linkCheckedAt,
                        'link_redirect_url' => $linkRedirectUrl,
                    ]);
                    $newId = (int) $target->lastInsertId();
                    $existingUrls[$key] = $newId;
                    $imported++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Joined listing '{$url}': " . $e->getMessage();
            }
        }

        return [
            ['type' => 'Joined Listings', 'imported' => $imported, 'skipped' => $skipped,
             'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems],
            ['copied' => $imgCopied, 'skipped' => $imgSkipped, 'errors' => $imgErrors],
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 8 — Wishlist
    // -----------------------------------------------------------------------

    private function runWishlist(
        PDO $source, PDO $target,
        ImportOptions $options, string $p,
        ?ImageMigrator $migrator
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgSkipped   = 0;
        $imgErrors    = [];

        $existingUrls = []; // key → wishlist id
        try {
            $st = $target->query('SELECT id, url FROM lstr_wishlist');
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $existingUrls[strtolower(rtrim((string) $row['url'], '/'))] = (int) $row['id'];
            }
        } catch (\Throwable) {}

        $typeMap = ['wanted' => 'wanted', 'granted' => 'granted', 'top' => 'top', 'pending' => 'wanted'];

        $insStmt = $target->prepare(
            'INSERT INTO lstr_wishlist (subject, url, image, category, description, type)
             VALUES (:subject, :url, :image, :category, :description, :type)'
        );

        try {
            $st   = $source->query("SELECT * FROM `{$p}wishlist` ORDER BY id ASC");
            $rows = $st ? $st->fetchAll() : [];
        } catch (\Throwable $e) {
            return [
                ['type' => 'Wishlist', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                 'errors' => ['Could not read wishlist: ' . $e->getMessage()], 'skipped_items' => []],
                ['copied' => 0, 'skipped' => 0, 'errors' => []],
            ];
        }

        foreach ($rows as $wl) {
            $url = rtrim(trim((string) ($wl['url'] ?? '')), '/');
            $key = strtolower($url);

            $srcType    = (string) ($wl['type'] ?? 'wanted');
            $lusterType = $typeMap[$srcType] ?? 'wanted';
            $subject    = (string) ($wl['subject']     ?? '');
            $category   = (string) ($wl['category']    ?? '');
            $description = (string) ($wl['description'] ?? '');

            $imgFile  = (string) ($wl['image_file'] ?? '');
            $newImage = null;
            if ($migrator !== null && $imgFile !== '') {
                $resolved = $this->resolveWishlistImagePath($imgFile, $options);
                $newImage = $this->migrateImage($migrator, $resolved, 'wishlist', $options);
                if ($newImage !== null) {
                    $imgCopied++;
                } else {
                    $imgSkipped++;
                }
            }

            if (isset($existingUrls[$key])) {
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                    continue;
                }
                // overwrite
                try {
                    $target->prepare(
                        'UPDATE lstr_wishlist SET subject=:subject, image=:image,
                         category=:category, description=:description, type=:type WHERE id=:id'
                    )->execute([
                        'subject' => $subject, 'image' => $newImage,
                        'category' => $category, 'description' => $description,
                        'type' => $lusterType, 'id' => $existingUrls[$key],
                    ]);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Wishlist '{$url}': " . $e->getMessage();
                }
                continue;
            }

            try {
                $insStmt->execute([
                    'subject'     => $subject,
                    'url'         => $url,
                    'image'       => $newImage,
                    'category'    => $category,
                    'description' => $description,
                    'type'        => $lusterType,
                ]);
                $existingUrls[$key] = (int) $target->lastInsertId();
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Wishlist '{$url}': " . $e->getMessage();
            }
        }

        return [
            ['type' => 'Wishlist', 'imported' => $imported, 'skipped' => $skipped,
             'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems],
            ['copied' => $imgCopied, 'skipped' => $imgSkipped, 'errors' => $imgErrors],
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 9 — KIM
    // -----------------------------------------------------------------------

    /** @param array<int,int> $listingIdMap */
    private function runKim(
        PDO $source, PDO $target,
        ImportOptions $options, string $p,
        array $listingIdMap
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];

        $existingSet = []; // key → kim member id
        try {
            $st = $target->query('SELECT id, fanlisting_id, email FROM lstr_kim_members');
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $key = ((int) ($row['fanlisting_id'] ?? 0)) . ':' . strtolower((string) ($row['email'] ?? ''));
                $existingSet[$key] = (int) $row['id'];
            }
        } catch (\Throwable) {}

        $insStmt = $target->prepare(
            'INSERT INTO lstr_kim_members (fanlisting_id, name, email, url, password, pending, added_at)
             VALUES (:fid, :name, :email, :url, :password, :pending, :added_at)'
        );

        // active = 0 means import as pending (pending = 1); active = 1 means import as active (pending = 0)
        $kimPending = $options->fnKimActive ? 0 : 1;

        try {
            $st   = $source->query("SELECT * FROM `{$p}kim` ORDER BY id ASC");
            $rows = $st ? $st->fetchAll() : [];
        } catch (\Throwable $e) {
            return [
                'type' => 'KIM List', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                'errors' => ['Could not read KIM entries: ' . $e->getMessage()], 'skipped_items' => [],
            ];
        }

        foreach ($rows as $kim) {
            $srcListingId = (int) ($kim['listing_id'] ?? 0);
            if ($srcListingId === 0) {
                $skippedItems[] = ['label' => (string) ($kim['email'] ?? '?'), 'reason' => 'listing_id=0 (collective/unassigned) has no Luster target'];
                $skipped++;
                continue;
            }

            $newListingId = $listingIdMap[$srcListingId] ?? null;
            if ($newListingId === null) {
                $skippedItems[] = ['label' => (string) ($kim['email'] ?? '?'), 'reason' => 'Listing not imported'];
                $skipped++;
                continue;
            }

            $email = strtolower(trim((string) ($kim['email'] ?? '')));
            $key   = $newListingId . ':' . $email;

            $kimName    = (string) ($kim['name']     ?? '');
            $kimUrl     = (string) ($kim['url']      ?? '');
            $kimAddedAt = (string) ($kim['added_at'] ?? date('Y-m-d'));

            if (isset($existingSet[$key])) {
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                    continue;
                }
                // overwrite: update name, url, pending (preserve password — KIM has no password in Fanatically)
                try {
                    $target->prepare(
                        'UPDATE lstr_kim_members SET name=:name, url=:url, pending=:pending WHERE id=:id'
                    )->execute([
                        'name' => $kimName, 'url' => $kimUrl,
                        'pending' => $kimPending, 'id' => $existingSet[$key],
                    ]);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "KIM '{$email}': " . $e->getMessage();
                }
                continue;
            }

            try {
                $insStmt->execute([
                    'fid'      => $newListingId,
                    'name'     => $kimName,
                    'email'    => $email,
                    'url'      => $kimUrl,
                    'password' => '',
                    'pending'  => $kimPending,
                    'added_at' => $kimAddedAt,
                ]);
                $existingSet[$key] = (int) $target->lastInsertId();
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "KIM '{$email}': " . $e->getMessage();
            }
        }

        return [
            'type' => 'KIM List', 'imported' => $imported, 'skipped' => $skipped,
            'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems,
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 10 — Adoption Applications
    // -----------------------------------------------------------------------

    /** @param array<int,int> $listingIdMap */
    private function runAdoption(
        PDO $source, PDO $target,
        ImportOptions $options, string $p,
        array $listingIdMap
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];

        // Build existing (fanlisting_id, email) → id map for dedup / overwrite
        $existingSet = []; // key → adoption application id
        try {
            $st = $target->query('SELECT id, fanlisting_id, email FROM lstr_adoption_applications');
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $key = ((int) $row['fanlisting_id']) . ':' . strtolower((string) $row['email']);
                $existingSet[$key] = (int) $row['id'];
            }
        } catch (\Throwable) {}

        $insStmt = $target->prepare(
            'INSERT INTO lstr_adoption_applications (fanlisting_id, name, email, url, message, status, applied_at)
             VALUES (:fid, :name, :email, :url, :message, :status, :applied_at)'
        );

        try {
            $st   = $source->query("SELECT * FROM `{$p}adoption_applications` ORDER BY id ASC");
            $rows = $st ? $st->fetchAll() : [];
        } catch (\Throwable $e) {
            return [
                'type' => 'Adoption Applications', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                'errors' => ['Could not read adoption applications: ' . $e->getMessage()], 'skipped_items' => [],
            ];
        }

        foreach ($rows as $app) {
            $srcListingId = (int) ($app['listing_id'] ?? 0);
            $newListingId = $listingIdMap[$srcListingId] ?? null;
            if ($newListingId === null) {
                $skippedItems[] = ['label' => (string) ($app['email'] ?? '?'), 'reason' => 'Listing not imported'];
                $skipped++;
                continue;
            }

            $email    = strtolower(trim((string) ($app['email'] ?? '')));
            $key      = $newListingId . ':' . $email;
            $appName  = (string) ($app['name']       ?? '');
            $appUrl   = (string) ($app['url']        ?? '');
            $appMsg   = (string) ($app['message']    ?? '');
            $appAt    = (string) ($app['applied_at'] ?? date('Y-m-d H:i:s'));

            if (isset($existingSet[$key])) {
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                    continue;
                }
                // overwrite
                try {
                    $target->prepare(
                        'UPDATE lstr_adoption_applications SET name=:name, url=:url, message=:message WHERE id=:id'
                    )->execute(['name' => $appName, 'url' => $appUrl, 'message' => $appMsg, 'id' => $existingSet[$key]]);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Adoption '{$email}': " . $e->getMessage();
                }
                continue;
            }

            try {
                $insStmt->execute([
                    'fid'        => $newListingId,
                    'name'       => $appName,
                    'email'      => $email,
                    'url'        => $appUrl,
                    'message'    => $appMsg,
                    'status'     => 'new',
                    'applied_at' => $appAt,
                ]);
                $existingSet[$key] = (int) $target->lastInsertId();
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Adoption '{$email}': " . $e->getMessage();
            }
        }

        return [
            'type' => 'Adoption Applications', 'imported' => $imported, 'skipped' => $skipped,
            'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems,
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 11 — Pending Member Updates
    // -----------------------------------------------------------------------

    /**
     * Migrates Fanatically pending_update JSON into lstr_pending_updates rows.
     *
     * Fanatically stores a JSON blob on the member row when a fan submits an update
     * that requires admin approval. The blob contains the proposed name/email/url/
     * country values plus a 'custom_field_values' JSON string for additional fields.
     * Password hashes in the blob are skipped — Luster never queues password changes.
     *
     * @param array<int,int> $listingIdMap fanatic listing_id → Luster registry pk
     */
    private function runPendingUpdates(
        PDO $source, PDO $target, string $targetSchema,
        string $p, array $listingIdMap, ImportOptions $options
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];

        [,, $isOwned] = $this->targetRegistryInfo($targetSchema);

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_pending_updates (fanlisting_id, member_email, changes)
             VALUES (:fid, :email, :changes)'
        );

        $existsStmt = null;
        if ($options->conflictStrategy === 'skip') {
            $existsStmt = $target->prepare(
                'SELECT COUNT(*) FROM lstr_pending_updates
                 WHERE fanlisting_id = :fid AND member_email = :email'
            );
        }

        $offset = 0;
        while (true) {
            try {
                $st = $source->prepare(
                    "SELECT listing_id, email, pending_update
                     FROM `{$p}members`
                     WHERE pending_update IS NOT NULL AND pending_update != ''
                     ORDER BY id ASC
                     LIMIT :limit OFFSET :offset"
                );
                $st->bindValue(':limit',  self::CHUNK, PDO::PARAM_INT);
                $st->bindValue(':offset', $offset,     PDO::PARAM_INT);
                $st->execute();
                $chunk = $st->fetchAll();
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
                break;
            }

            if (empty($chunk)) {
                break;
            }

            foreach ($chunk as $mem) {
                $srcListingId = (int) ($mem['listing_id'] ?? 0);
                $newListingId = $listingIdMap[$srcListingId] ?? null;
                if ($newListingId === null) {
                    $skippedItems[] = ['label' => (string) ($mem['email'] ?? '?'), 'reason' => 'Listing not imported'];
                    $skipped++;
                    continue;
                }

                $raw = json_decode((string) ($mem['pending_update'] ?? ''), true);
                if (!is_array($raw)) {
                    $skipped++;
                    continue;
                }

                // Standard fields — map directly
                $changes = [];
                foreach (['name', 'email', 'url', 'country'] as $f) {
                    if (array_key_exists($f, $raw)) {
                        $changes[$f] = (string) $raw[$f];
                    }
                }

                // Custom fields: stored as a JSON-encoded string inside the blob.
                // Keys must go through the same sanitizeFieldName() pass that member
                // import uses when creating columns, so the keys match actual DB columns.
                $cfJson = $raw['custom_field_values'] ?? '';
                if (is_string($cfJson) && $cfJson !== '') {
                    $cfValues = json_decode($cfJson, true);
                    if (is_array($cfValues)) {
                        foreach ($cfValues as $fieldName => $value) {
                            $safe = $this->sanitizeFieldName((string) $fieldName);
                            if ($safe === '') {
                                continue;
                            }
                            $key           = $isOwned ? $safe : 'additional_' . $safe;
                            $changes[$key] = (string) $value;
                        }
                    }
                }

                // Password hashes are never queued in Luster — skip them
                if (empty($changes)) {
                    $skipped++;
                    continue;
                }

                $email = (string) ($mem['email'] ?? '');

                try {
                    if ($existsStmt !== null) {
                        $existsStmt->execute(['fid' => $newListingId, 'email' => $email]);
                        if ((int) $existsStmt->fetchColumn() > 0) {
                            $skipped++;
                            continue;
                        }
                    }

                    $insertStmt->execute([
                        'fid'     => $newListingId,
                        'email'   => $email,
                        'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }

            $offset += self::CHUNK;
        }

        return [
            'type'         => 'Pending Member Updates',
            'imported'     => $imported,
            'skipped'      => $skipped,
            'warnings'     => [],
            'errors'       => $errors,
            'skipped_items' => $skippedItems,
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 12 — Site Settings
    // -----------------------------------------------------------------------

    private function runSiteSettings(PDO $source, PDO $target, string $p, ImportOptions $options): array
    {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        // Maps Fanatically setting_key → Luster config key.
        // SMTP and credential keys are never imported (see EXCLUDED_SETTINGS above).
        // email_from / email_from_name are intentionally omitted — they are SMTP
        // config that lives in config/app.php, not in lstr_site_settings.
        $settingMap = [
            'collective_name' => 'site_name',
            'owner_email'     => 'owner_email',
            'owner_name'      => 'owner_name',
            'per_page'        => 'per_page',
        ];

        try {
            $st      = $source->query("SELECT setting_key, setting_value FROM `{$p}settings`");
            $srcRows = $st ? $st->fetchAll() : [];
        } catch (\Throwable $e) {
            return [
                'type' => 'Site Settings', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                'errors' => ['Could not read settings: ' . $e->getMessage()], 'skipped_items' => [],
            ];
        }

        $excluded = [];
        foreach ($srcRows as $row) {
            $key   = (string) ($row['setting_key']   ?? '');
            $value = (string) ($row['setting_value'] ?? '');

            if (in_array($key, self::EXCLUDED_SETTINGS, true)) {
                $excluded[] = $key;
                continue;
            }

            $lusterKey = $settingMap[$key] ?? null;
            if ($lusterKey === null) {
                $skipped++;
                continue;
            }

            // Check whether this key already exists in Luster
            try {
                $chk     = $target->prepare('SELECT setting_value FROM lstr_site_settings WHERE setting_key=:k');
                $chk->execute(['k' => $lusterKey]);
                $current = $chk->fetch();
            } catch (\Throwable $e) {
                $errors[] = "Setting '{$key}': " . $e->getMessage();
                continue;
            }

            if ($current !== false) {
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                    continue;
                }
                // overwrite: update the existing row
                try {
                    $target->prepare('UPDATE lstr_site_settings SET setting_value=:v WHERE setting_key=:k')
                        ->execute(['v' => $value, 'k' => $lusterKey]);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Setting '{$key}': " . $e->getMessage();
                }
            } else {
                try {
                    $target->prepare('INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES (:k, :v)')
                        ->execute(['k' => $lusterKey, 'v' => $value]);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Setting '{$key}': " . $e->getMessage();
                }
            }
        }

        $warnings = [];
        if (!empty($excluded)) {
            $warnings[] = 'Excluded keys (SMTP, admin credentials, API key): ' . implode(', ', $excluded);
        }

        return [
            'type' => 'Site Settings', 'imported' => $imported, 'skipped' => $skipped,
            'warnings' => $warnings, 'errors' => $errors, 'skipped_items' => [],
        ];
    }

    // -----------------------------------------------------------------------
    // Code size / donor upsert helpers
    // -----------------------------------------------------------------------

    /**
     * Parses size_label ("50x50") into [w, h].
     * Non-parseable labels return [0, 0].
     *
     * @return array{0:int,1:int}
     */
    private function parseSize(string $label): array
    {
        if ($label === '') {
            return [0, 0];
        }
        $parts = explode('x', strtolower($label), 2);
        if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1])) {
            return [(int) $parts[0], (int) $parts[1]];
        }
        return [0, 0];
    }

    /**
     * Upserts lstr_code_sizes for a given listing + size label.
     * Parseable labels key by (fanlisting_id, width, height).
     * Non-parseable labels key by (fanlisting_id, label) with width=height=0.
     *
     * @param array<int,array<string,mixed>> $idMap  In/out map for caching
     */
    private function upsertCodeSize(PDO $target, int $listingId, string $label, array &$idMap): ?int
    {
        [$w, $h] = $this->parseSize($label);
        $cacheKey = $label !== '' ? $label : '__blank__';

        if (isset($idMap[$listingId][$cacheKey])) {
            return $idMap[$listingId][$cacheKey];
        }

        // Store dims for codes insert
        $idMap[$listingId]['__dims'][$label] = ['w' => $w, 'h' => $h];

        try {
            // Try to find existing
            if ($w > 0 || $h > 0) {
                $st = $target->prepare(
                    'SELECT id FROM lstr_code_sizes WHERE fanlisting_id=:fid AND width=:w AND height=:h LIMIT 1'
                );
                $st->execute(['fid' => $listingId, 'w' => $w, 'h' => $h]);
            } else {
                $st = $target->prepare(
                    'SELECT id FROM lstr_code_sizes WHERE fanlisting_id=:fid AND label=:label LIMIT 1'
                );
                $st->execute(['fid' => $listingId, 'label' => $label]);
            }
            $existing = $st->fetchColumn();

            if ($existing !== false) {
                $idMap[$listingId][$cacheKey] = (int) $existing;
                return (int) $existing;
            }

            $target->prepare(
                'INSERT INTO lstr_code_sizes (fanlisting_id, label, width, height)
                 VALUES (:fid, :label, :w, :h)'
            )->execute(['fid' => $listingId, 'label' => ($label ?: null), 'w' => $w, 'h' => $h]);
            $newId = (int) $target->lastInsertId();
            $idMap[$listingId][$cacheKey] = $newId;
            return $newId;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Upserts lstr_code_donors for a given listing + donor name.
     *
     * @param array<int,array<string,int>> $idMap  In/out map for caching
     */
    private function upsertCodeDonor(PDO $target, int $listingId, string $name, string $url, string $email, array &$idMap): ?int
    {
        $cacheKey = strtolower($name);

        if (isset($idMap[$listingId][$cacheKey])) {
            return $idMap[$listingId][$cacheKey];
        }

        try {
            $st = $target->prepare(
                'SELECT id FROM lstr_code_donors WHERE fanlisting_id=:fid AND name=:name LIMIT 1'
            );
            $st->execute(['fid' => $listingId, 'name' => $name]);
            $existing = $st->fetchColumn();

            if ($existing !== false) {
                $idMap[$listingId][$cacheKey] = (int) $existing;
                return (int) $existing;
            }

            $target->prepare(
                'INSERT INTO lstr_code_donors (fanlisting_id, name, url, email)
                 VALUES (:fid, :name, :url, :email)'
            )->execute(['fid' => $listingId, 'name' => $name, 'url' => $url ?: null, 'email' => $email ?: null]);
            $newId = (int) $target->lastInsertId();
            $idMap[$listingId][$cacheKey] = $newId;
            return $newId;
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Image path resolution
    // -----------------------------------------------------------------------

    private function resolveListingImagePath(string $file, int $srcListingId, ImportOptions $options): string
    {
        $base = rtrim($options->imagePath, '/\\');
        $bn   = basename($file);
        foreach ([
            $this->safeImagePath($base, $file),
            $base . '/images/listings/' . $bn,
            $base . '/images/' . $bn,
        ] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }
        $url = rtrim($options->imageUrl, '/');
        if ($url !== '') {
            return $url . '/images/listings/' . $bn;
        }
        return '';
    }

    private function resolveAffiliateImagePath(string $file, ImportOptions $options): string
    {
        $base = rtrim($options->imagePath, '/\\');
        $bn   = basename($file);
        foreach ([
            $this->safeImagePath($base, $file),
            $base . '/images/affiliates/' . $bn,
            $base . '/images/' . $bn,
        ] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }
        $url = rtrim($options->imageUrl, '/');
        if ($url !== '') {
            return $url . '/images/affiliates/' . $bn;
        }
        return '';
    }

    private function resolveCodeImagePath(string $file, string $sizeLabel, int $srcListingId, ImportOptions $options): string
    {
        $base      = rtrim($options->imagePath, '/\\');
        $bn        = basename($file);
        // Strip path separators and null bytes from DB-sourced size label before using in paths
        $safeLabel = str_replace(['/', '\\', "\0", '..'], '', $sizeLabel);
        foreach ([
            $base . '/images/codes/' . $srcListingId . '/' . $safeLabel . '/' . $bn,
            $base . '/images/codes/' . $srcListingId . '/' . $bn,
            $base . '/images/codes/' . $bn,
        ] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }
        $url = rtrim($options->imageUrl, '/');
        if ($url !== '') {
            return $url . '/images/codes/' . $srcListingId . '/' . ($safeLabel !== '' ? $safeLabel . '/' : '') . $bn;
        }
        return '';
    }

    private function resolveJoinedImagePath(string $file, ImportOptions $options): string
    {
        $base = rtrim($options->imagePath, '/\\');
        $bn   = basename($file);
        foreach ([
            $this->safeImagePath($base, $file),
            $base . '/images/joined/' . $bn,
            $base . '/images/' . $bn,
        ] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }
        $url = rtrim($options->imageUrl, '/');
        if ($url !== '') {
            return $url . '/images/joined/' . $bn;
        }
        return '';
    }

    private function resolveWishlistImagePath(string $file, ImportOptions $options): string
    {
        $base = rtrim($options->imagePath, '/\\');
        $bn   = basename($file);
        foreach ([
            $this->safeImagePath($base, $file),
            $base . '/images/wishlist/' . $bn,
            $base . '/images/' . $bn,
        ] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }
        $url = rtrim($options->imageUrl, '/');
        if ($url !== '') {
            return $url . '/images/wishlist/' . $bn;
        }
        return '';
    }

    /**
     * Returns "$base/$file" only if the resolved realpath stays within $base.
     * Prevents DB-sourced filenames containing "../" from escaping the image root.
     * Returns '' when the path would escape or the file does not exist yet (is_file
     * is intentionally NOT called here — callers do that themselves).
     */
    private function safeImagePath(string $base, string $file): string
    {
        if ($base === '' || $file === '') {
            return '';
        }
        $candidate = $base . '/' . ltrim($file, '/\\');
        // Reject if any path component traverses upward
        if (str_contains($candidate, '..')) {
            return '';
        }
        return $candidate;
    }

    /**
     * Calls ImageMigrator to copy/fetch an image.
     * Returns the stored filename on success, null on failure.
     */
    private function migrateImage(ImageMigrator $migrator, string $source, string $subdir, ImportOptions $options): ?string
    {
        if ($source === '') {
            return null;
        }

        try {
            if ($options->imageMode === 'zip' && $this->activeZip !== null) {
                return $migrator->copyFromZip($this->activeZip, basename($source), $subdir, 'fn_') ?: null;
            }
            if (is_file($source)) {
                return $migrator->copyFromPath($source, $subdir, 'fn_') ?: null;
            }
            if (filter_var($source, FILTER_VALIDATE_URL)) {
                return $migrator->fetchFromUrl($source, $subdir, 'fn_') ?: null;
            }
        } catch (\Throwable) {}

        return null;
    }

    // -----------------------------------------------------------------------
    // Schema capability detection
    // -----------------------------------------------------------------------

    /**
     * @return array{has_listing_categories:bool,has_joined_categories:bool,has_wishlist:bool,has_kim:bool,has_adoption:bool,has_pending_update:bool,no_parent_id:bool}
     */
    private function detectCapabilities(PDO $source, string $p): array
    {
        $tableExists = function (string $table) use ($source): bool {
            try {
                $driver = $source->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $st = $source->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=:t");
                    $st->execute(['t' => $table]);
                    return $st->fetchColumn() !== false;
                }
                $st = $source->query("SHOW TABLES LIKE '{$table}'");
                return $st && $st->fetchColumn() !== false;
            } catch (\Throwable) {
                return false;
            }
        };

        $columnExists = function (string $table, string $column) use ($source): bool {
            try {
                $driver = $source->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($driver === 'sqlite') {
                    $st = $source->query("PRAGMA table_info(`{$table}`)");
                    foreach ($st ? $st->fetchAll() : [] as $row) {
                        if (($row['name'] ?? '') === $column) {
                            return true;
                        }
                    }
                    return false;
                }
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                    return false;
                }
                $st = $source->prepare(
                    'SELECT 1
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = :table
                       AND COLUMN_NAME = :column
                     LIMIT 1'
                );
                $st->execute(['table' => $table, 'column' => $column]);
                return $st->fetchColumn() !== false;
            } catch (\Throwable) {
                return false;
            }
        };

        return [
            'has_listing_categories' => $tableExists("{$p}listing_categories"),
            'has_joined_categories'  => $tableExists("{$p}joined_categories"),
            'has_wishlist'           => $tableExists("{$p}wishlist"),
            'has_kim'                => $tableExists("{$p}kim"),
            'has_adoption'           => $tableExists("{$p}adoption_applications"),
            'has_pending_update'     => $columnExists("{$p}members", 'pending_update'),
            'no_parent_id'           => !$columnExists("{$p}categories", 'parent_id'),
        ];
    }

    // -----------------------------------------------------------------------
    // Category seed / approval-by mapping helpers
    // -----------------------------------------------------------------------

    /**
     * Builds a map of [lowercase_name => {id:int, network:'tfl'|'tafl'}] for all
     * existing TFL and TAFL seed categories in the Luster target.
     *
     * @return array<string,array{id:int,network:string}>
     */
    private function buildSeedNameMap(PDO $target): array
    {
        $map = [];
        try {
            $st = $target->prepare("SELECT id, name, source FROM lstr_categories WHERE source IN ('tfl', 'tafl')");
            $st->execute();
            foreach ($st->fetchAll() as $row) {
                $map[strtolower((string) $row['name'])] = [
                    'id'      => (int) $row['id'],
                    'network' => (string) $row['source'],
                ];
            }
        } catch (\Throwable) {}
        return $map;
    }

    /**
     * Returns a map of [fanatically_category_id => approved_by[]] by looking at
     * which listings use each category via the listing_categories junction.
     *
     * Used to choose TFL vs TAFL seed when a category name matches both.
     *
     * @param array<string,bool> $caps
     * @return array<int,string[]>
     */
    private function buildCategoryApprovedByMap(PDO $source, string $p, array $caps): array
    {
        $map = [];
        if (!$caps['has_listing_categories']) {
            return $map;
        }
        try {
            $st = $source->query(
                "SELECT lc.category_id, l.approved_by
                 FROM `{$p}listing_categories` lc
                 JOIN `{$p}listings` l ON l.id = lc.listing_id"
            );
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $catId     = (int) $row['category_id'];
                $approvedBy = (string) ($row['approved_by'] ?? '');
                $map[$catId][] = $approvedBy;
            }
        } catch (\Throwable) {}
        return $map;
    }

    /**
     * Attempts to resolve a seed category ID from the approved_by context.
     * Returns null when the context is ambiguous or no match.
     *
     * @param array{id:int,network:string} $seedInfo
     * @param string[]                     $approvedBys  approved_by values from linked listings
     * @param array<string,int>            $existing     existing category name→id map
     */
    private function resolveSeedId(array $seedInfo, array $approvedBys, array $existing, string $nameLower): ?int
    {
        if (empty($approvedBys)) {
            return null; // no context — create as custom
        }

        $approvedBys = array_unique($approvedBys);
        // Normalise: '' and 'tfl' → tfl; 'afl' → tafl
        $networks = array_map(function (string $ab): string {
            return match ($ab) {
                'tfl'  => 'tfl',
                'afl'  => 'tafl',
                default => '', // blank or unknown
            };
        }, $approvedBys);
        $networks = array_unique(array_filter($networks));

        if (count($networks) !== 1) {
            return null; // mixed or all-blank — ambiguous; import as custom
        }

        $network = reset($networks);
        if ($seedInfo['network'] === $network) {
            return $seedInfo['id'];
        }

        // Seed is for the other network — check if there's a matching row
        $altKey = $network . ':' . $nameLower;
        return $existing[$altKey] ?? null;
    }

    // -----------------------------------------------------------------------
    // DDL helpers (shared with ListingAdminImportSource pattern)
    // -----------------------------------------------------------------------

    /**
     * Creates a per-listing member table (Enthusiast-compatible schema).
     *
     * @param string[] $additionalFields Validated bare field names
     */
    private function createMemberTable(PDO $target, string $dbtable, array $additionalFields, bool $isOwned = true): void
    {
        $extraCols = '';
        foreach ($additionalFields as $field) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
                continue;
            }
            $colName = $isOwned ? $field : 'additional_' . $field;
            $extraCols .= "  `{$colName}` TEXT NOT NULL DEFAULT '',\n";
        }

        $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $target->exec(
                "CREATE TABLE IF NOT EXISTS `{$dbtable}` (
                  `email`     TEXT NOT NULL,
                  `name`      TEXT NOT NULL DEFAULT '',
                  `country`   TEXT NOT NULL DEFAULT '',
                  `url`       TEXT NOT NULL DEFAULT '',
                  `pending`   INTEGER NOT NULL DEFAULT 0,
                  `password`  TEXT NOT NULL DEFAULT '',
                  `showemail` INTEGER NOT NULL DEFAULT 1,
                  `showurl`   INTEGER NOT NULL DEFAULT 1,
                  `added`     TEXT NULL,
                  {$extraCols}
                  PRIMARY KEY (`email`)
                )"
            );
            return;
        }

        $target->exec(
            "CREATE TABLE IF NOT EXISTS `{$dbtable}` (
               `email`     VARCHAR(255) NOT NULL,
               `name`      VARCHAR(100) NOT NULL DEFAULT '',
               `country`   VARCHAR(100) NOT NULL DEFAULT '',
               `url`       VARCHAR(255) NOT NULL DEFAULT '',
               `pending`   TINYINT(1)   NOT NULL DEFAULT 0,
               `password`  VARCHAR(255) NOT NULL DEFAULT '',
               `showemail` TINYINT(1)   NOT NULL DEFAULT 1,
               `showurl`   TINYINT(1)   NOT NULL DEFAULT 1,
               `added`     DATE NULL,
               {$extraCols}
               PRIMARY KEY (`email`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function detectTargetSchema(PDO $target): string
    {
        $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $target->query("SELECT name FROM sqlite_master WHERE type='table' AND name='owned'");
            return $st->fetchColumn() !== false ? 'enthusiast_unprefixed' : 'enthusiast_enth';
        }
        $st = $target->query("SHOW TABLES LIKE 'owned'");
        return ($st && $st->fetchColumn() !== false) ? 'enthusiast_unprefixed' : 'enthusiast_enth';
    }

    /** @return array{0:string, 1:string, 2:bool} */
    private function targetRegistryInfo(string $targetSchema): array
    {
        $isOwned = ($targetSchema === 'enthusiast_unprefixed');
        return [$isOwned ? 'owned' : 'enth_fanlistings', $isOwned ? 'listingid' : 'id', $isOwned];
    }

    private function generateDbtable(string $subject, array $existing): string
    {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $subject));
        $base = trim($base, '_') ?: 'listing';
        if (strlen($base) > 50) {
            $base = substr($base, 0, 50);
        }
        $candidate = $base;
        $i = 2;
        while (isset($existing[$candidate])) {
            $candidate = $base . '_' . $i++;
        }
        return $candidate;
    }

    private function sanitizeFieldName(string $name): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', strtolower($name));
        $safe = preg_replace('/_{2,}/', '_', (string) $safe);
        $safe = trim((string) $safe, '_');
        if ($safe !== '' && ctype_digit($safe[0])) {
            $safe = 'f_' . $safe;
        }
        return substr((string) $safe, 0, 64);
    }

    /**
     * @param array<string,string> $globalSettings  Key/value rows from {p}settings (all listings share these)
     */
    private function upsertListingSettings(
        PDO $target, int $listingId, string $approvedBy,
        ?string $lastUpdated, array $globalSettings, int $forAdoption,
        string $description
    ): void {
        $lu = $lastUpdated ? date('Y-m-d', strtotime($lastUpdated)) : null;

        // Fanatically behaviour flags live in the global settings table, not per listing.
        //
        // hold_for_approval=1  → manual approval required → auto_approve_joins=0
        // hold_for_approval=0 or ABSENT (Fanatically default) → auto_approve_joins=1
        $autoApprove = isset($globalSettings['hold_for_approval'])
            ? ((int) $globalSettings['hold_for_approval'] ? 0 : 1)
            : 1; // Fanatically default: no hold → auto-approve

        // hold_update_for_approval=1 → queue_updates=1; 0 or absent → 0
        $queueUpdates = isset($globalSettings['hold_update_for_approval'])
            ? ((int) $globalSettings['hold_update_for_approval'] ? 1 : 0)
            : 0;

        // notify_member_on_join=1 means "send the email" which is Luster's default (NULL).
        // notify_member_on_join=0 means "never send" → join_confirmation_email=0.
        // Absent or 1 → NULL (let Luster decide per its own defaults).
        $joinEmail = (isset($globalSettings['notify_member_on_join'])
            && (int) $globalSettings['notify_member_on_join'] === 0)
            ? 0
            : null;

        // approved_by is ENUM('','tfl','tafl') NOT NULL DEFAULT '' — never store null
        $lstrApprovedBy = in_array($approvedBy, ['tfl', 'tafl'], true) ? $approvedBy : '';

        try {
            $st = $target->prepare('SELECT fanlisting_id FROM lstr_fanlisting_settings WHERE fanlisting_id=:id');
            $st->execute(['id' => $listingId]);
            $exists = $st->fetchColumn() !== false;

            if ($exists) {
                $target->prepare(
                    'UPDATE lstr_fanlisting_settings
                     SET last_updated=:lu, approved_by=:ab, queue_updates=:qu,
                         auto_approve_joins=:aa, join_confirmation_email=:je,
                         description=COALESCE(NULLIF(:description, \'\'), description)
                     WHERE fanlisting_id=:id'
                )->execute([
                    'lu' => $lu,  'ab' => $lstrApprovedBy,
                    'qu' => $queueUpdates, 'aa' => $autoApprove,
                    'je' => $joinEmail,   'id' => $listingId,
                    'description' => $description,
                ]);
            } else {
                $hasAffApply = $this->tableHasColumn($target, 'lstr_fanlisting_settings', 'affiliate_apply_enabled');
                $affCol = $hasAffApply ? ', affiliate_apply_enabled' : '';
                $affVal = $hasAffApply ? ', 1'                       : '';
                $target->prepare(
                    "INSERT INTO lstr_fanlisting_settings
                       (fanlisting_id, last_updated, approved_by, queue_updates, auto_approve_joins, join_confirmation_email, description{$affCol})
                     VALUES (:id, :lu, :ab, :qu, :aa, :je, :description{$affVal})"
                )->execute([
                    'id' => $listingId, 'lu' => $lu,    'ab' => $lstrApprovedBy,
                    'qu' => $queueUpdates, 'aa' => $autoApprove, 'je' => $joinEmail,
                    'description' => $description ?: null,
                ]);
            }
        } catch (\Throwable) {}

        // for_adoption lives in an optional Adoption add-on column.
        // Always attempt the UPDATE (including 0) so overwrite mode can clear an existing 1.
        // Try silently; if the column doesn't exist (add-on not installed) the catch discards it.
        try {
            $target->prepare(
                'UPDATE lstr_fanlisting_settings SET for_adoption=:fa WHERE fanlisting_id=:id'
            )->execute(['fa' => $forAdoption, 'id' => $listingId]);
        } catch (\Throwable) {}
    }

    // -----------------------------------------------------------------------
    // Add-on detection
    // -----------------------------------------------------------------------

    /**
     * Returns true if the given PDO connection points to a Luster database
     * (i.e. lstr_admin_users exists). Used to guard add-on table checks so
     * we don't falsely report add-ons missing when the source is a foreign DB.
     */
    private function looksLikeLusterDb(PDO $pdo): bool
    {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='lstr_admin_users'");
                $st->execute();
                return $st->fetchColumn() !== false;
            }
            $st = $pdo->query("SHOW TABLES LIKE 'lstr_admin_users'");
            return $st && $st->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function wishlistAddonInstalled(PDO $target): bool
    {
        try {
            $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $st = $target->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='lstr_wishlist'");
                $st->execute();
                return $st->fetchColumn() !== false;
            }
            $st = $target->query("SHOW TABLES LIKE 'lstr_wishlist'");
            return $st && $st->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function kimAddonInstalled(PDO $target): bool
    {
        try {
            $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $st = $target->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='lstr_kim_members'");
                $st->execute();
                return $st->fetchColumn() !== false;
            }
            $st = $target->query("SHOW TABLES LIKE 'lstr_kim_members'");
            return $st && $st->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function adoptionAddonInstalled(PDO $target): bool
    {
        try {
            $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $st = $target->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='lstr_adoption_applications'");
                $st->execute();
                return $st->fetchColumn() !== false;
            }
            $st = $target->query("SHOW TABLES LIKE 'lstr_adoption_applications'");
            return $st && $st->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Status mapping helpers
    // -----------------------------------------------------------------------

    /**
     * Maps Fanatically affiliate/joined link_status to Luster's ENUM value.
     * Fanatically uses 'moved'; Luster uses 'redirect'.
     */
    private function mapLinkStatus(string $fnStatus): string
    {
        return match ($fnStatus) {
            'moved' => 'redirect',
            'ok'    => 'ok',
            'dead'  => 'dead',
            default => 'unchecked',
        };
    }

    /**
     * Maps Fanatically reciprocal_status to Luster's ENUM value.
     * Fanatically uses 'ok'; Luster uses 'found'.
     */
    private function mapReciprocalStatus(string $fnStatus): string
    {
        return match ($fnStatus) {
            'ok'      => 'found',
            'missing' => 'missing',
            default   => 'unchecked',
        };
    }

    /**
     * Persists imported custom field names to the listing registry so admin
     * join/update forms know those columns exist.
     *
     * Registers custom field names in the listing registry so admin forms and
     * public templates know the imported columns exist.
     *
     * owned schema:  updates `owned.additional` (comma-separated bare names).
     * enth_ schema:  updates `enth_fanlistings.additional_info` (PHP-serialized
     *                array, the same format written by FieldRepo::saveFields()).
     *
     * @param string[] $fieldNames  Sanitized bare field names
     */
    private function persistCustomFieldDefinitions(PDO $target, int $listingId, bool $isOwned, array $fieldNames): void
    {
        if (empty($fieldNames)) {
            return;
        }

        if ($isOwned) {
            try {
                $st = $target->prepare('SELECT additional FROM `owned` WHERE listingid=:id');
                $st->execute(['id' => $listingId]);
                $current = (string) ($st->fetchColumn() ?: '');

                $existingNames = $current !== ''
                    ? array_filter(array_map('trim', explode(',', $current)))
                    : [];
                $merged = array_values(array_unique(array_merge($existingNames, $fieldNames)));

                $target->prepare('UPDATE `owned` SET additional=:additional WHERE listingid=:id')
                    ->execute(['additional' => implode(',', $merged), 'id' => $listingId]);
            } catch (\Throwable) {}
        } else {
            try {
                $st = $target->prepare('SELECT additional_info FROM `enth_fanlistings` WHERE id=:id');
                $st->execute(['id' => $listingId]);
                $raw = (string) ($st->fetchColumn() ?: '');

                // Parse existing entries into a name-keyed map
                $existing = [];
                if ($raw !== '') {
                    $decoded = @unserialize($raw, ['allowed_classes' => false]);
                    if (is_array($decoded)) {
                        foreach ($decoded as $item) {
                            $n = (string) ($item['name'] ?? $item[0] ?? '');
                            if ($n !== '') {
                                $existing[$n] = $item;
                            }
                        }
                    } else {
                        // pipe-delimited: name,Label,type,required|...
                        foreach (explode('|', $raw) as $piece) {
                            $piece = trim($piece);
                            if ($piece === '') {
                                continue;
                            }
                            $parts = explode(',', $piece, 4);
                            $n = trim($parts[0]);
                            if ($n !== '') {
                                $existing[$n] = [
                                    'name'     => $n,
                                    'label'    => trim($parts[1] ?? ucwords(str_replace('_', ' ', $n))),
                                    'type'     => trim($parts[2] ?? 'text'),
                                    'required' => (bool) (int) trim($parts[3] ?? '0'),
                                ];
                            }
                        }
                    }
                }

                $added = false;
                foreach ($fieldNames as $name) {
                    if (!isset($existing[$name])) {
                        $existing[$name] = [
                            'name'     => $name,
                            'label'    => ucwords(str_replace('_', ' ', $name)),
                            'type'     => 'text',
                            'required' => false,
                        ];
                        $added = true;
                    }
                }

                if ($added || ($raw === '' && !empty($existing))) {
                    $target->prepare('UPDATE `enth_fanlistings` SET additional_info=:info WHERE id=:id')
                        ->execute(['info' => serialize(array_values($existing)), 'id' => $listingId]);
                }
            } catch (\Throwable) {}
        }
    }

    // -----------------------------------------------------------------------
    // External-ref helpers
    /**
     * Writes or replaces a listing→external-ID mapping. When $metadata is provided
     * and the lstr_listing_external_refs table has a metadata_json column (added by
     * Migration 91), the JSON-encoded payload is stored alongside the row.
     *
     * @param array<string,mixed>|null $metadata
     */
    private function upsertExternalRef(
        PDO    $target,
        int    $listingId,
        string $source,
        string $externalId,
        ?array $metadata = null,
    ): void {
        try {
            if ($metadata !== null && $this->tableHasColumn($target, 'lstr_listing_external_refs', 'metadata_json')) {
                $target->prepare(
                    'REPLACE INTO lstr_listing_external_refs (listing_id, source, external_id, metadata_json)
                     VALUES (:lid, :src, :eid, :meta)'
                )->execute([
                    'lid'  => $listingId,
                    'src'  => $source,
                    'eid'  => $externalId,
                    'meta' => json_encode($metadata),
                ]);
            } else {
                $target->prepare(
                    'REPLACE INTO lstr_listing_external_refs (listing_id, source, external_id)
                     VALUES (:lid, :src, :eid)'
                )->execute(['lid' => $listingId, 'src' => $source, 'eid' => $externalId]);
            }
        } catch (\Throwable) {}
    }

    // -----------------------------------------------------------------------

    private function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");
                foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
                    if (($row['name'] ?? '') === $column) {
                        return true;
                    }
                }
                return false;
            }

            if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT 1
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column
                 LIMIT 1'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return string[] */
    private function previewListingStatusWarnings(PDO $source, string $table): array
    {
        $warns = [];
        try {
            $st = $source->query(
                "SELECT
                    SUM(CASE WHEN TRIM(COALESCE(url, '')) = '' THEN 1 ELSE 0 END) AS blank_url,
                    SUM(CASE WHEN status = 'closed'       THEN 1 ELSE 0 END) AS closed,
                    SUM(CASE WHEN status = 'adopted_out'  THEN 1 ELSE 0 END) AS adopted_out
                 FROM `{$table}`"
            );
            $row = $st ? $st->fetch() : null;
            if ($row !== null && $row !== false) {
                $blankUrl   = (int) ($row['blank_url']   ?? 0);
                $closed     = (int) ($row['closed']      ?? 0);
                $adoptedOut = (int) ($row['adopted_out'] ?? 0);

                if ($blankUrl > 0) {
                    $warns[] = "Data transformed — {$blankUrl} listing(s) have no URL and will be imported as inactive; they can be matched again on re-import.";
                }
                if ($closed > 0) {
                    $warns[] = "Data transformed — {$closed} closed listing(s) will be imported as inactive; original status is preserved in metadata.";
                }
                if ($adoptedOut > 0) {
                    $warns[] = "Data transformed — {$adoptedOut} adopted-out listing(s) will be imported as inactive; original status is preserved in metadata.";
                }
            }
        } catch (\Throwable) {}
        return $warns;
    }

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewTable(PDO $source, string $table, string $label, array $warnings = []): array
    {
        try {
            $st    = $source->query("SELECT COUNT(*) FROM `{$table}`");
            $found = (int) ($st ? $st->fetchColumn() : 0);
        } catch (\Throwable) {
            $found = 0;
        }
        return ['type' => $label, 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => $warnings];
    }
}
