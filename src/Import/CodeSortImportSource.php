<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import adapter for CodeSort databases. CodeSort manages fan codes (small web
 graphics) across multiple fanlistings with global size/donor tables. This
 adapter auto-detects the two CodeSort table naming variants (bare and prefixed),
 matches CodeSort fanlistings to Luster listings by subject name, and imports
 code categories, sizes, donors, and code images into Luster's per-listing code
 tables. Global CodeSort sizes and donors are expanded to per-listing rows in
 Luster's schema.
*/

declare(strict_types=1);

namespace Luster\Import;

use PDO;

/**
 * Import adapter for CodeSort databases.
 *
 * CodeSort manages fan graphic codes across multiple fanlistings using global
 * size and donor tables. This adapter auto-detects the two CodeSort table naming
 * conventions (bare: `codes`, `cat`, `sizes`, `donors`, `collective`; and
 * prefixed: `codes_codes`, `codes_cat`, etc.), matches CodeSort fanlistings to
 * Luster listings by subject name (case-insensitive), and imports code categories,
 * sizes, donors, and code images. Global sizes and donors are expanded to
 * per-listing rows in Luster's schema. Unmatched fanlistings are skipped with a
 * warning.
 *
 * Re-running is safe: sizes, donors and categories are matched before anything is
 * added, and codes are found again by their import_ref (see runCodes()).
 */
final class CodeSortImportSource implements ImportSourceInterface
{
    /** Skipped codes listed by name on the result page (the count covers the rest). */
    private const SKIP_CAP = 500;

    private const CODES_NEED_SOURCE_ID =
        'Codes were not imported: Luster can\'t tell this CodeSort install\'s codes apart from another '
        . 'install\'s, because its settings have no full web address for its images. Enter a Source ID on '
        . 'the Options step (for example: old-collective) and run the import again.';

    private const CODES_NEED_MIGRATION =
        'Codes were not imported: run the migration page first (Luster needs Migration 104), then import again.';

    /**
     * The name ImageMigrator gives a copied CodeSort image ('cs' + 16 hex digits). An
     * earlier import's code stored under one can't be traced back to its CodeSort code.
     */
    private const MIGRATED_NAME = '/^cs[0-9a-f]{16}\.(jpg|png|gif|webp)$/';

    private ?\ZipArchive $activeZip = null;

    /** Folder that holds uploads/ — the real public/ folder unless a test points it elsewhere. */
    private readonly string $publicDir;

    public function __construct(?string $publicDir = null)
    {
        $this->publicDir = rtrim($publicDir ?? dirname(__DIR__, 2) . '/public', '/\\');
    }

    // -----------------------------------------------------------------------
    // ImportSourceInterface
    // -----------------------------------------------------------------------

    public function getName(): string
    {
        return 'CodeSort';
    }

    public function testConnection(array $credentials): ?string
    {
        try {
            $this->openConnection($credentials);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    public function openConnection(array $credentials): PDO
    {
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
        $driver = $source->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            return $this->detectSchemaSqlite($source);
        }

        // MySQL / MariaDB path.

        // 'codes' table is the codes table in both 'default' and 'bare' layouts.
        $stmt = $source->query("SHOW TABLES LIKE 'codes'");
        if ($stmt && $stmt->fetchColumn() !== false) {
            $hasCodeId = false;
            try {
                $source->query("SELECT code_id FROM `codes` LIMIT 0");
                $hasCodeId = true;
            } catch (\Throwable) {}

            if ($hasCodeId) {
                // Distinguish real default install (codes_cat companion) from the legacy
                // bare layout (cat companion).  Default is more common in practice.
                $s = $source->query("SHOW TABLES LIKE 'codes_cat'");
                if ($s && $s->fetchColumn() !== false) {
                    return 'codesort_default';
                }
                $s = $source->query("SHOW TABLES LIKE 'cat'");
                if ($s && $s->fetchColumn() !== false) {
                    return 'codesort_bare';
                }
                // codes table exists but no recognised companion — default is safest assumption.
                return 'codesort_default';
            }
        }

        // Prefixed variant: codes_codes, codes_cat, codes_sizes, codes_donors, codes_collective
        $stmt = $source->query("SHOW TABLES LIKE 'codes_codes'");
        if ($stmt && $stmt->fetchColumn() !== false) {
            return 'codesort_prefixed';
        }

        return 'unknown';
    }

    private function detectSchemaSqlite(PDO $source): string
    {
        $tables = array_flip(
            $source->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN)
        );

        // Prefixed variant first (codes_codes is unambiguous)
        if (isset($tables['codes_codes'])) {
            return 'codesort_prefixed';
        }

        if (isset($tables['codes'])) {
            $hasCodeId = false;
            try {
                $source->query("SELECT code_id FROM `codes` LIMIT 0");
                $hasCodeId = true;
            } catch (\Throwable) {}

            if ($hasCodeId) {
                if (isset($tables['codes_cat'])) {
                    return 'codesort_default';
                }
                if (isset($tables['cat'])) {
                    return 'codesort_bare';
                }
                return 'codesort_default';
            }
        }

        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // Preview (read-only)
    // -----------------------------------------------------------------------

    public function preview(PDO $source, PDO $target, ImportOptions $options): ImportPreview
    {
        $schema = $options->schemaVariant !== '' ? $options->schemaVariant : $this->detectSchema($source);
        $p      = $options->tablePrefix . $this->tablePrefix($schema);
        $compP  = $options->tablePrefix . $this->companionPrefix($schema);
        $rows   = [];

        // Resolved before anything else so a bad Source ID stops the preview up front.
        $csSource = $this->csSourceKey($source, $compP, $options);

        if ($options->importCodeCategories) {
            $rows[] = $this->previewCategories($source, $compP);
        }
        if ($options->importCodeSizes) {
            $rows[] = $this->previewTable($source, "{$compP}sizes", 'Code sizes');
        }
        if ($options->importCodeDonors) {
            $rows[] = $this->previewTable($source, "{$compP}donors", 'Code donors');
        }
        if ($options->importCodes) {
            $rows[] = $this->previewCodes($source, $target, $p, $compP, $schema, $options, $csSource['key']);
        }

        return new ImportPreview($rows, $schema, false);
    }

    // -----------------------------------------------------------------------
    // Run (writes to $target)
    // -----------------------------------------------------------------------

    public function run(PDO $source, PDO $target, ImportOptions $options): ImportResult
    {
        set_time_limit(0);
        ini_set('memory_limit', '256M');

        $start  = microtime(true);
        $schema = $options->schemaVariant !== '' ? $options->schemaVariant : $this->detectSchema($source);
        $p      = $options->tablePrefix . $this->tablePrefix($schema);
        $compP  = $options->tablePrefix . $this->companionPrefix($schema);
        $rows   = [];
        $imgRows = [];

        // Resolved before any writes so a bad Source ID can't leave a half-run import.
        $csSource = $this->csSourceKey($source, $compP, $options);

        // Build image migrator if needed
        $migrator  = $options->imageMode !== ''
            ? new ImageMigrator($this->publicDir)
            : null;

        // Open a pre-uploaded ZIP archive.
        if ($options->imageMode === 'zip' && $options->zipPath !== '' && $migrator !== null) {
            $this->activeZip = new \ZipArchive();
            if ($this->activeZip->open($options->zipPath) !== true) {
                $this->activeZip = null;
            }
        }

        // Build Luster listing map: lowercase(title) → listing_id
        $titles     = $this->listingTitles($target);
        $listingMap = self::listingMapFromTitles($titles);

        // Build CodeSort collective map: cs_fl_id → lstr_listing_id (null = unmatched)
        [$collectiveMap, $unmatchedSubjects] = $this->buildCollectiveMap(
            $source, $p, $compP, $schema, $listingMap
        );
        $matchedListingIds = array_values(array_unique(array_filter(array_values($collectiveMap))));

        // Store cs_fl_id → Luster listing_id so the CodeSort compat shim can resolve
        // $listing by external ref rather than assuming IDs are identical.
        // REPLACE INTO repairs stale mappings if a re-import changes the Luster listing.
        foreach ($collectiveMap as $csFlId => $lstrId) {
            if ($lstrId !== null) {
                try {
                    $target->prepare(
                        'REPLACE INTO lstr_listing_external_refs (listing_id, source, external_id)
                         VALUES (:lid, \'codesort\', :eid)'
                    )->execute(['lid' => $lstrId, 'eid' => (string) $csFlId]);
                } catch (\Throwable) {}
            }
        }

        // Maps built during sub-imports and consumed by importCodes
        $catMap      = []; // cs_cat_id  → lstr_cat_id
        $catListings = []; // cs_cat_id  → the lstr listing that category is in
        $sizeMap     = []; // cs_size_id → [lstr_lid => lstr_size_id]
        $donorMap    = []; // cs_donor_id → [lstr_lid => lstr_donor_id]

        if ($options->importCodeCategories) {
            ['row' => $catRow, 'map' => $catMap, 'listings' => $catListings] = $this->runCategories(
                $source, $target, $compP, $collectiveMap, $options
            );
            $rows[] = $catRow;
        }

        if ($options->importCodeSizes) {
            ['row' => $sizeRow, 'map' => $sizeMap] = $this->runSizes(
                $source, $target, $compP, $matchedListingIds, $options
            );
            $rows[] = $sizeRow;
        }

        if ($options->importCodeDonors) {
            ['row' => $donorRow, 'map' => $donorMap] = $this->runDonors(
                $source, $target, $p, $compP, $collectiveMap, $options
            );
            $rows[] = $donorRow;
        }

        if ($options->importCodes) {
            ['row' => $codeRow, 'imageRow' => $imgRow] = $this->runCodes(
                $source, $target, $p, $compP, $collectiveMap,
                ['cats' => $catMap, 'catListings' => $catListings, 'sizes' => $sizeMap, 'donors' => $donorMap],
                $options, $migrator, $csSource['key'], $titles
            );
            $rows[] = $codeRow;
            if ($imgRow !== null) {
                $imgRows[] = $imgRow;
            }

        }

        // Close and clean up pre-uploaded ZIP if one was opened.
        if ($this->activeZip !== null) {
            $this->activeZip->close();
            $this->activeZip = null;
            if ($options->zipPath !== '') {
                @unlink($options->zipPath);
            }
        }

        if (!empty($unmatchedSubjects)) {
            // Prepend unmatched warning to first result row
            $warn = 'Unmatched fanlistings (no codes imported): ' . implode(', ', $unmatchedSubjects);
            if (!empty($rows)) {
                $rows[0]['warnings'][] = $warn;
            }
        }

        return new ImportResult(
            rows:          $rows,
            imageRows:     $imgRows,
            duration:      microtime(true) - $start,
            schemaVariant: $options->schemaVariant !== '' ? $options->schemaVariant : $schema,
        );
    }

    // -----------------------------------------------------------------------
    // Preview helpers
    // -----------------------------------------------------------------------

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewTable(PDO $source, string $table, string $label): array
    {
        try {
            $found = (int) $source->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        } catch (\Throwable) {
            $found = 0;
        }
        return ['type' => $label, 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => []];
    }

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewCategories(PDO $source, string $compP): array
    {
        $warns = [];
        try {
            $found = (int) $source->query("SELECT COUNT(*) FROM `{$compP}cat`")->fetchColumn();
        } catch (\Throwable) {
            return ['type' => 'Code categories', 'found' => 0, 'import' => 0, 'skip' => 0, 'warnings' => []];
        }
        try {
            $collective = (int) $source->query("SELECT COUNT(*) FROM `{$compP}cat` WHERE cat_fl = 0")->fetchColumn();
            if ($collective > 0) {
                $warns[] = "{$collective} category/categories have CodeSort collective scope (cat_fl=0) and will be skipped — Luster's code categories are always per-listing.";
            }
        } catch (\Throwable) {}
        return ['type' => 'Code categories', 'found' => $found, 'import' => $found - ($collective ?? 0), 'skip' => ($collective ?? 0), 'warnings' => $warns];
    }

    /**
     * Preview row for codes. The counts are CodeSort's (as before); the notes come from
     * planCodes(), the same decisions run() acts on: codes imported before, codes from an
     * earlier import that can or can't be matched, and why codes won't import at all.
     *
     * @return array{type:string,found:int,import:int,skip:int,warnings:string[]}
     */
    private function previewCodes(
        PDO $source, PDO $target, string $p, string $compP, string $schema, ImportOptions $options, ?string $sourceKey
    ): array {
        $warns = [];
        try {
            $found = (int) $source->query("SELECT COUNT(*) FROM `{$p}codes`")->fetchColumn();
        } catch (\Throwable) {
            $found = 0;
        }

        try {
            $collective = (int) $source->query("SELECT COUNT(*) FROM `{$p}codes` WHERE code_fl = 0")->fetchColumn();
            if ($collective > 0) {
                $warns[] = "{$collective} code(s) have CodeSort collective scope (code_fl=0) and will be skipped — Luster's codes are always per-listing.";
            }
        } catch (\Throwable) {}

        if ($options->imageMode === '') {
            $warns[] = 'Image migration is disabled — code image files will not be copied. Width/height will be taken from the mapped size record.';
        }

        if ($sourceKey === null) {
            $warns[] = self::CODES_NEED_SOURCE_ID;
            return ['type' => 'Codes', 'found' => $found, 'import' => 0, 'skip' => $found, 'warnings' => $warns];
        }
        if (!$this->hasImportRefColumns($target)) {
            $warns[] = self::CODES_NEED_MIGRATION;
            return ['type' => 'Codes', 'found' => $found, 'import' => 0, 'skip' => $found, 'warnings' => $warns];
        }

        $titles = $this->listingTitles($target);
        [$collectiveMap] = $this->buildCollectiveMap($source, $p, $compP, $schema, self::listingMapFromTitles($titles));
        $plan = $this->planCodes($source, $target, $p, $collectiveMap, $sourceKey, $options->csMatchEarlier);
        if ($plan === null) {
            return ['type' => 'Codes', 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => $warns];
        }

        $warns[] = "Codes will be saved under Source ID {$sourceKey}, so running the import again won't add copies of them.";

        $actions = array_count_values(array_column($plan['decisions'], 'action'));
        $matched = $actions['matched'] ?? 0;
        if ($matched > 0) {
            $warns[] = $options->conflictStrategy === 'overwrite'
                ? "{$matched} code(s) were imported before and will be updated in place."
                : "{$matched} code(s) were imported before and will be left as they are (choose Update matching to refresh them).";
        }
        foreach ($plan['claims'] as $lid => $files) {
            $n = count($files);
            $warns[] = ($titles[$lid] ?? "Listing #{$lid}") . ': ' . ($n === 1 ? '1 code' : "{$n} codes")
                . ' (' . implode(', ', array_slice($files, 0, 3)) . ($n > 3 ? ', …' : '') . ')'
                . ' from an earlier CodeSort import will be linked to this install instead of added again'
                . ($options->conflictStrategy === 'overwrite' ? ', and updated.' : '.');
        }
        if (($actions['ambiguous'] ?? 0) > 0) {
            $warns[] = "{$actions['ambiguous']} code(s) will be skipped: more than one existing code could be the same one.";
        }

        return [
            'type'     => 'Codes',
            'found'    => $found,
            'import'   => $found,
            'skip'     => 0,
            'warnings' => array_merge(
                $warns,
                $this->earlierImportNotes($plan['unclaimed'], $plan['random'], $plan['newIn'], $titles, false)
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Run helpers
    // -----------------------------------------------------------------------

    /**
     * Each CodeSort category is matched to the oldest Luster category with the same
     * name in its listing, or created. Both conflict strategies match the same way —
     * the name is all CodeSort has, so there's nothing to update.
     *
     * @return array{row: array, map: array<int,int>, listings: array<int,int>}
     *         map: cs_cat_id → lstr_cat_id; listings: cs_cat_id → the lstr listing it's in
     */
    private function runCategories(
        PDO    $source,
        PDO    $target,
        string $compP,
        array  $collectiveMap,
        ImportOptions $options,
    ): array {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_code_categories (fanlisting_id, name, parent_id, sort_order)
             VALUES (:fid, :name, NULL, :sort)'
        );
        $checkStmt = $target->prepare(
            'SELECT id FROM lstr_code_categories WHERE fanlisting_id = :fid AND name = :name ORDER BY id LIMIT 1'
        );

        $catMap           = [];
        $collectiveSkipped = 0;

        try {
            $rows = $source->query("SELECT cat_id, cat_fl, cat_name FROM `{$compP}cat` ORDER BY cat_id ASC")->fetchAll();
        } catch (\Throwable) {
            return [
                'row'      => ['type' => 'Code categories', 'imported' => 0, 'skipped' => 0, 'warnings' => [], 'errors' => ['Table not found.']],
                'map'      => [],
                'listings' => [],
            ];
        }

        $catListings = [];

        foreach ($rows as $idx => $row) {
            $csCatFl = (int) $row['cat_fl'];
            if ($csCatFl === 0) {
                // cat_fl=0 means "whole collective" scope in CodeSort — no per-listing equivalent in Luster
                $collectiveSkipped++;
                continue;
            }

            $lstrLid = $collectiveMap[$csCatFl] ?? null;
            if ($lstrLid === null) {
                $skipped++;
                continue;
            }

            $name = (string) $row['cat_name'];

            try {
                $checkStmt->execute(['fid' => $lstrLid, 'name' => $name]);
                $existingId = $checkStmt->fetchColumn();
                $checkStmt->closeCursor();
                if ($existingId !== false) {
                    $catMap[(int) $row['cat_id']]      = (int) $existingId;
                    $catListings[(int) $row['cat_id']] = $lstrLid;
                    $skipped++;
                    continue;
                }

                $insertStmt->execute(['fid' => $lstrLid, 'name' => $name, 'sort' => $idx]);
                $newId = (int) $target->lastInsertId();
                $catMap[(int) $row['cat_id']]      = $newId;
                $catListings[(int) $row['cat_id']] = $lstrLid;
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Code category \"{$name}\": " . $e->getMessage();
            }
        }

        $warnings = [];
        if ($collectiveSkipped > 0) {
            $warnings[] = "{$collectiveSkipped} category/categories with CodeSort collective scope (cat_fl=0) were skipped — Luster's code categories are always per-listing.";
        }

        return [
            'row' => [
                'type'     => 'Code categories',
                'imported' => $imported,
                'skipped'  => $skipped + $collectiveSkipped,
                'warnings' => $warnings,
                'errors'   => $errors,
            ],
            'map'      => $catMap,
            'listings' => $catListings,
        ];
    }

    /**
     * Gives each Luster listing a size for each CodeSort size: the oldest one with the
     * same label, or else (for a label like "88x31" with both numbers above 0) the oldest
     * with those dimensions, or a new one. Both conflict strategies match the same way;
     * Update matching also copies CodeSort's size_order onto a matched size. Sort orders
     * are kept within 0-65535, what the column holds.
     * Returns a nested map: cs_size_id → [lstr_lid => lstr_size_id].
     *
     * @param int[] $matchedListingIds
     * @return array{row: array, map: array<int, array<int,int>>}
     */
    private function runSizes(
        PDO    $source,
        PDO    $target,
        string $compP,
        array  $matchedListingIds,
        ImportOptions $options,
    ): array {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        if (empty($matchedListingIds)) {
            return [
                'row' => ['type' => 'Code sizes', 'imported' => 0, 'skipped' => 0, 'warnings' => ['No matched listings.'], 'errors' => []],
                'map' => [],
            ];
        }

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_code_sizes (fanlisting_id, label, width, height, sort_order)
             VALUES (:fid, :label, :width, :height, :sort)'
        );
        $byLabel = $target->prepare(
            'SELECT id FROM lstr_code_sizes WHERE fanlisting_id = :fid AND label = :label ORDER BY id LIMIT 1'
        );
        $byDims = $target->prepare(
            'SELECT id FROM lstr_code_sizes WHERE fanlisting_id = :fid AND width = :w AND height = :h ORDER BY id LIMIT 1'
        );
        $updateSort = $target->prepare('UPDATE lstr_code_sizes SET sort_order = :sort WHERE id = :id');
        $overwrite  = $options->conflictStrategy === 'overwrite';

        $sizeMap = [];

        try {
            $csSizes = $source->query(
                "SELECT size_id, size_order, size_size FROM `{$compP}sizes` ORDER BY size_order ASC, size_id ASC"
            )->fetchAll();
        } catch (\Throwable) {
            return [
                'row' => ['type' => 'Code sizes', 'imported' => 0, 'skipped' => 0, 'warnings' => [], 'errors' => ['Table not found.']],
                'map' => [],
            ];
        }

        foreach ($csSizes as $csSize) {
            $csId  = (int) $csSize['size_id'];
            $label = (string) $csSize['size_size'];
            [$w, $h] = $this->parseSizeString($label);
            $order = min(65535, max(0, (int) $csSize['size_order']));
            $sizeMap[$csId] = [];

            foreach ($matchedListingIds as $lstrLid) {
                try {
                    $byLabel->execute(['fid' => $lstrLid, 'label' => $label]);
                    $existingId = $byLabel->fetchColumn();
                    $byLabel->closeCursor();
                    if ($existingId === false && $w > 0 && $h > 0) {
                        $byDims->execute(['fid' => $lstrLid, 'w' => $w, 'h' => $h]);
                        $existingId = $byDims->fetchColumn();
                        $byDims->closeCursor();
                    }

                    if ($existingId !== false) {
                        $sizeMap[$csId][$lstrLid] = (int) $existingId;
                        if ($overwrite) {
                            $updateSort->execute(['sort' => $order, 'id' => (int) $existingId]);
                            $imported++;
                        } else {
                            $skipped++;
                        }
                        continue;
                    }

                    $insertStmt->execute([
                        'fid'   => $lstrLid,
                        'label' => $label,
                        'width' => $w ?: null,
                        'height'=> $h ?: null,
                        'sort'  => $order,
                    ]);
                    $newId = (int) $target->lastInsertId();
                    $sizeMap[$csId][$lstrLid] = $newId;
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Code size \"{$label}\": " . $e->getMessage();
                }
            }
        }

        return [
            'row' => [
                'type'     => 'Code sizes',
                'imported' => $imported,
                'skipped'  => $skipped,
                'warnings' => [],
                'errors'   => $errors,
            ],
            'map' => $sizeMap,
        ];
    }

    /**
     * Gives each CodeSort donor a donor row in the listings whose codes use it, matched the
     * way the Listing Admin importer does: same name, no email (CodeSort has none, so a
     * donor given an email in Luster is never taken over) and exactly the same URL, compared
     * in PHP so the path's case counts. The oldest match is used; otherwise a new row is
     * added. Donor rows are never edited, under either conflict strategy, so codes already
     * sharing one don't change. A donor with a blank name is no donor.
     *
     * @return array{row: array, map: array<int, array<int,int>>}  cs_donor_id → [lstr_lid => lstr_donor_id]
     */
    private function runDonors(
        PDO    $source,
        PDO    $target,
        string $p,
        string $compP,
        array  $collectiveMap,
        ImportOptions $options,
    ): array {
        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_code_donors (fanlisting_id, name, email, url)
             VALUES (:fid, :name, NULL, :url)'
        );
        $findStmt = $target->prepare(
            'SELECT id, url FROM lstr_code_donors WHERE fanlisting_id = :fid AND name = :name AND email IS NULL ORDER BY id'
        );

        $donorMap = [];

        try {
            $donors = $source->query(
                "SELECT donor_id, donor_name, donor_url FROM `{$compP}donors` ORDER BY donor_id ASC"
            )->fetchAll();
        } catch (\Throwable) {
            return [
                'row' => ['type' => 'Code donors', 'imported' => 0, 'skipped' => 0, 'warnings' => [], 'errors' => ['Table not found.']],
                'map' => [],
            ];
        }

        // Pre-scan codes table to find which listings use each donor
        $donorListings = []; // cs_donor_id → [cs_fl_id, ...]
        try {
            $stmt = $source->query(
                "SELECT DISTINCT code_donor, code_fl FROM `{$p}codes` WHERE code_donor > 0"
            );
            foreach ($stmt->fetchAll() as $row) {
                $donorListings[(int) $row['code_donor']][] = (int) $row['code_fl'];
            }
        } catch (\Throwable) {}

        foreach ($donors as $donor) {
            $csId = (int) $donor['donor_id'];
            $name = self::trimTo((string) ($donor['donor_name'] ?? ''), 150);
            $url  = self::trimTo((string) ($donor['donor_url'] ?? ''), 500);
            $donorMap[$csId] = [];

            if ($name === null) {
                continue;
            }

            $csFlIds = $donorListings[$csId] ?? [];

            foreach ($csFlIds as $csFlId) {
                $lstrLid = $collectiveMap[$csFlId] ?? null;
                if ($lstrLid === null) {
                    continue;
                }

                try {
                    $findStmt->execute(['fid' => $lstrLid, 'name' => $name]);
                    $existingId = false;
                    foreach ($findStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                        $candidateUrl = $candidate['url'] !== null ? (string) $candidate['url'] : null;
                        if ($candidateUrl === $url) {
                            $existingId = (int) $candidate['id'];
                            break;
                        }
                    }

                    if ($existingId !== false) {
                        $donorMap[$csId][$lstrLid] = $existingId;
                        $skipped++;
                        continue;
                    }

                    $insertStmt->execute([
                        'fid'  => $lstrLid,
                        'name' => $name,
                        'url'  => $url,
                    ]);
                    $newId = (int) $target->lastInsertId();
                    $donorMap[$csId][$lstrLid] = $newId;
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Code donor \"{$name}\": " . $e->getMessage();
                }
            }
        }

        return [
            'row' => [
                'type'     => 'Code donors',
                'imported' => $imported,
                'skipped'  => $skipped,
                'warnings' => [],
                'errors'   => $errors,
            ],
            'map' => $donorMap,
        ];
    }

    /**
     * Imports CodeSort codes into lstr_codes.
     *
     * Every code is saved with import_ref = 'codesort:<source key>:<code_id>'
     * (CodeSortSourceKey), so a re-run finds it again, even after its image was copied
     * under a new name or the file was renamed in CodeSort. What happens to each code is
     * decided up front by planCodes():
     *
     * - A new code has its image copied first, then goes after the listing's existing
     *   codes.
     * - A code imported before (or claimed, see planCodes()) is left as it is under Keep
     *   existing; a claim only saves its import_ref. Under Update matching its approval,
     *   size, donor and category come from CodeSort, and a size, donor or category
     *   CodeSort doesn't give leaves the code's own alone. If its CodeSort listing now
     *   maps to another Luster listing, it moves there, to the end of that listing's
     *   codes, and keeps only what belongs to that listing: CodeSort's size, donor and
     *   category there, or none. A matched code never gets a new image copy.
     *
     * Each code is written in its own transaction. Its counts, the image count, the
     * listing's next sort position and the notes about codes added again only change once
     * it has committed, and a new code that fails has its copied image deleted. Every code this run writes or keeps has its
     * image checked; the ones without it are listed in the row's missing_images.
     *
     * @param array<int,int|null> $collectiveMap cs_fl_id → lstr_lid|null
     * @param array{cats: array<int,int>, catListings: array<int,int>, sizes: array<int,array<int,int>>, donors: array<int,array<int,int>>} $maps
     *        What the categories, sizes and donors steps matched (empty when a step wasn't ticked).
     * @param array<int,string> $titles lstr listing ID → title, for messages
     * @return array{row: array, imageRow: ?array}
     */
    private function runCodes(
        PDO            $source,
        PDO            $target,
        string         $p,
        string         $compP,
        array          $collectiveMap,
        array          $maps,
        ImportOptions  $options,
        ?ImageMigrator $migrator,
        ?string        $sourceKey,
        array          $titles,
    ): array {
        $row = [
            'type'           => 'Codes',
            'imported'       => 0,
            'skipped'        => 0,
            'warnings'       => [],
            'errors'         => [],
            'skipped_items'  => [],
            'image_map'      => [], // for ZIP mode
            'missing_images' => [],
        ];
        $images = ['copied' => 0, 'skipped' => 0, 'errors' => []];

        if ($sourceKey === null) {
            $row['errors'][] = self::CODES_NEED_SOURCE_ID;
            return ['row' => $row, 'imageRow' => null];
        }
        if (!$this->hasImportRefColumns($target)) {
            $row['errors'][] = self::CODES_NEED_MIGRATION;
            return ['row' => $row, 'imageRow' => null];
        }
        $plan = $this->planCodes($source, $target, $p, $collectiveMap, $sourceKey, $options->csMatchEarlier);
        if ($plan === null) {
            $row['errors'][] = 'Table not found.';
            return ['row' => $row, 'imageRow' => null];
        }

        // Resolve effective image path/URL: user-supplied values take precedence;
        // fall back to values read from the codes_options table when both are absent.
        $effectivePath = $options->imagePath;
        $effectiveUrl  = $options->imageUrl;
        if ($migrator !== null && $effectivePath === '' && $effectiveUrl === '') {
            ['path' => $effectivePath, 'url' => $effectiveUrl] =
                $this->readCodeSortImageSettings($source, $compP);
        }

        $ctx = [
            'source' => $source, 'compP' => $compP, 'options' => $options, 'migrator' => $migrator,
            'path' => $effectivePath, 'url' => $effectiveUrl, 'maps' => $maps, 'titles' => $titles,
            'key' => $sourceKey,
        ];
        // Changed only after a code commits (see the docblock).
        $state = [
            'nextSort'   => [], // lstr_lid → next sort position
            'addedIn'    => [], // lstr_lid → new codes added
            'readded'    => [], // lstr_lid → files of new codes an earlier import may already have added
            'collective' => 0,  // CodeSort codes for the whole collective (code_fl = 0)
            'noFile'     => 0,  // codes written or kept that CodeSort gives no image file
        ];
        $overwrite = $options->conflictStrategy === 'overwrite';

        foreach ($plan['decisions'] as $decision) {
            $code = $decision['code'];
            switch ($decision['action']) {
                case 'collective':
                    // code_fl=0 means "whole collective" scope in CodeSort — no per-listing equivalent in Luster
                    $state['collective']++;
                    $row['skipped']++;
                    break;
                case 'unmatched':
                    $this->skipCode($row, $code, 'No Luster listing for CodeSort listing #' . (int) $code['code_fl']);
                    break;
                case 'ambiguous':
                    $this->skipCode($row, $code, 'More than one existing code could be this one');
                    break;
                case 'new':
                    $added = $this->importNewCode($target, $ctx, $code, (int) $decision['listing'], $state, $row, $images);
                    if ($added && $decision['earlier']) {
                        $state['readded'][(int) $decision['listing']][] = (string) $code['code_image'];
                    }
                    break;
                default: // 'matched' or 'claim'
                    if ($overwrite) {
                        $this->updateMatchedCode($target, $ctx, $decision, $state, $row);
                    } else {
                        $this->keepMatchedCode($target, $ctx, $decision, $state, $row);
                    }
            }
        }

        if ($state['collective'] > 0) {
            $row['warnings'][] = "{$state['collective']} code(s) with CodeSort collective scope (code_fl=0) were skipped — Luster's codes are always per-listing.";
        }
        $row['warnings'] = array_merge(
            $row['warnings'],
            $this->earlierImportNotes($state['readded'], $plan['random'], $state['addedIn'], $titles, true)
        );
        $missing = count($row['missing_images']);
        if ($missing > 0) {
            $row['warnings'][] = $missing === 1
                ? "1 code has no image yet. Running the import again won't add an image to it — see below."
                : "{$missing} codes have no image yet. Running the import again won't add images to them — see below.";
        }
        if ($state['noFile'] > 0) {
            $row['warnings'][] = $state['noFile'] === 1
                ? "1 CodeSort code doesn't name an image file, so there was nothing to copy for it."
                : "{$state['noFile']} CodeSort codes don't name an image file, so there was nothing to copy for them.";
        }

        $imageRow = ($images['copied'] + count($images['errors']) + $images['skipped'] > 0)
            ? ['type' => 'Images (codes)'] + $images
            : null;

        return ['row' => $row, 'imageRow' => $imageRow];
    }

    /**
     * Decides what happens to each CodeSort code, before anything is written. Shared by
     * preview() and run(), so the preview says what the run will do.
     *
     * A code is found in Luster by its import_ref first. Codes imported before
     * import_refs existed are found as candidates: a code in the listing the CodeSort code
     * maps to, with no import_ref, import_source_file or name, whose filename is
     * byte-for-byte CodeSort's code_image — what the old importer wrote. That isn't proof
     * (another importer's code can look the same once its name is cleared, and one row
     * can't say which CodeSort install it came from), so a candidate is only claimed when
     * $matchEarlier is on ("Match codes from an earlier CodeSort import"), and only when it
     * pairs up exactly: the only candidate for that file in that listing, and the only
     * CodeSort code there using that file. Otherwise, with the option on, the code is
     * skipped as ambiguous; with it off, candidates are never touched and the code is
     * added as new (and reported). Earlier codes stored under a copied image's random
     * name (MIGRATED_NAME) can't be matched at all; they're only counted, per listing.
     *
     * @param array<int,int|null> $collectiveMap cs_fl_id → lstr_lid|null
     * @return array{
     *     decisions: list<array{action: string, code: array<string,mixed>, listing: ?int, existing: ?array{id:int, fanlisting_id:int, filename:string}, earlier?: bool}>,
     *     claims: array<int, list<string>>,
     *     unclaimed: array<int, list<string>>,
     *     random: array<int, int>,
     *     newIn: array<int, int>,
     * }|null  null when CodeSort's codes table can't be read. action is one of 'new',
     *         'matched', 'claim', 'ambiguous', 'unmatched' or 'collective', and only a 'new'
     *         decision has earlier (true when a candidate exists but isn't claimed); claims
     *         is lstr_lid → files of the candidates to claim; unclaimed is lstr_lid → files
     *         to add as new although a candidate exists; random is lstr_lid → random-name
     *         codes; newIn is lstr_lid → codes to add.
     */
    private function planCodes(
        PDO $source, PDO $target, string $p, array $collectiveMap, string $sourceKey, bool $matchEarlier
    ): ?array {
        try {
            $codes = $source->query(
                "SELECT code_id, code_fl, code_cat, code_size, code_image, code_donor, code_approved
                   FROM `{$p}codes`
                  ORDER BY code_id ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return null;
        }

        // Codes this install imported before: import_ref → the code.
        $byRef = [];
        $stmt  = $target->prepare('SELECT id, fanlisting_id, filename, import_ref FROM lstr_codes WHERE import_ref LIKE :pattern');
        $stmt->execute(['pattern' => CodeSortSourceKey::refPrefix($sourceKey) . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byRef[(string) $r['import_ref']] = self::codeSummary($r);
        }

        // Codes an earlier import may have left, in the listings this run maps to.
        $candidates = []; // lstr_lid → filename → list of codes
        $random     = []; // lstr_lid → count
        $listingIds = array_values(array_unique(array_filter($collectiveMap, static fn($id): bool => $id !== null)));
        if ($listingIds !== []) {
            $marks = implode(',', array_fill(0, count($listingIds), '?'));
            $stmt  = $target->prepare(
                "SELECT id, fanlisting_id, filename, import_source_file FROM lstr_codes
                  WHERE import_ref IS NULL AND name IS NULL AND fanlisting_id IN ({$marks})
                  ORDER BY id"
            );
            $stmt->execute($listingIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lid  = (int) $r['fanlisting_id'];
                $file = (string) $r['filename'];
                if (preg_match(self::MIGRATED_NAME, $file) === 1) {
                    $random[$lid] = ($random[$lid] ?? 0) + 1;
                } elseif ($r['import_source_file'] === null && $file !== '') {
                    // Array keys compare byte for byte (MySQL's usual collations ignore case).
                    $candidates[$lid][$file][] = self::codeSummary($r);
                }
            }
        }

        // How many CodeSort codes in each listing use each file.
        $fileUse = [];
        foreach ($codes as $code) {
            $lid = (int) $code['code_fl'] !== 0 ? ($collectiveMap[(int) $code['code_fl']] ?? null) : null;
            if ($lid !== null) {
                $file = (string) $code['code_image'];
                $fileUse[$lid][$file] = ($fileUse[$lid][$file] ?? 0) + 1;
            }
        }

        $decisions = [];
        $claims    = [];
        $unclaimed = [];
        $newIn     = [];
        foreach ($codes as $code) {
            $fl = (int) $code['code_fl'];
            if ($fl === 0) {
                $decisions[] = ['action' => 'collective', 'code' => $code, 'listing' => null, 'existing' => null];
                continue;
            }
            $lid = $collectiveMap[$fl] ?? null;
            if ($lid === null) {
                $decisions[] = ['action' => 'unmatched', 'code' => $code, 'listing' => null, 'existing' => null];
                continue;
            }

            $ref = CodeSortSourceKey::importRef($sourceKey, (int) $code['code_id']);
            if (isset($byRef[$ref])) {
                $decisions[] = ['action' => 'matched', 'code' => $code, 'listing' => $lid, 'existing' => $byRef[$ref]];
                continue;
            }

            $file = (string) $code['code_image'];
            $fits = $file !== '' ? ($candidates[$lid][$file] ?? []) : [];
            if ($fits !== []) {
                if ($matchEarlier) {
                    if (count($fits) === 1 && ($fileUse[$lid][$file] ?? 0) === 1) {
                        $decisions[]    = ['action' => 'claim', 'code' => $code, 'listing' => $lid, 'existing' => $fits[0]];
                        $claims[$lid][] = $file;
                    } else {
                        $decisions[] = ['action' => 'ambiguous', 'code' => $code, 'listing' => $lid, 'existing' => null];
                    }
                    continue;
                }
                $unclaimed[$lid][] = $file;
            }

            $decisions[]  = ['action' => 'new', 'code' => $code, 'listing' => $lid, 'existing' => null, 'earlier' => $fits !== []];
            $newIn[$lid]  = ($newIn[$lid] ?? 0) + 1;
        }

        return ['decisions' => $decisions, 'claims' => $claims, 'unclaimed' => $unclaimed, 'random' => $random, 'newIn' => $newIn];
    }

    /**
     * Adds one CodeSort code that isn't in Luster yet. See runCodes().
     *
     * @param array<string,mixed> $ctx  See runCodes().
     * @param array<string,mixed> $code One CodeSort codes row.
     * @return bool Whether the code was added (committed).
     */
    private function importNewCode(
        PDO $target, array $ctx, array $code, int $listingId, array &$state, array &$row, array &$images
    ): bool {
        $file    = (string) $code['code_image'];
        $options = $ctx['options'];
        [$sizeId, $donorId, $catId] = self::codeRefs($ctx['maps'], $code, $listingId);

        // The image is a file, outside the database: copied first, deleted again if the code fails.
        $newFilename = null;
        $imageError  = null;
        if ($ctx['migrator'] !== null && $file !== '') {
            $newFilename = $this->copyCodeImage($ctx, $file, $listingId, $row);
            if ($newFilename === null
                && ($options->imageMode === 'auto' || ($options->imageMode === 'zip' && $this->activeZip !== null))
            ) {
                $imageError = "{$file}: couldn't be copied from the image path, web address or ZIP";
            }
        }

        [$width, $height] = $newFilename !== null ? $this->codeImageDims($newFilename) : [0, 0];
        // Fall back to size dimensions if image not available
        if ($width === 0 && $sizeId !== null) {
            [$width, $height] = $this->sourceSizeDims($ctx, (int) $code['code_size']);
        }

        $filename = $newFilename ?? $file;
        $sort     = $this->nextSort($target, $listingId, $state['nextSort']);

        $target->beginTransaction();
        try {
            $target->prepare(
                'INSERT INTO lstr_codes
                 (fanlisting_id, name, filename, width, height, size_id, donor_id, sort_order, status,
                  submitter_name, submitter_email, submitter_url, import_ref, import_source_file)
                 VALUES (:fid, NULL, :filename, :width, :height, :size_id, :donor_id, :sort, :status,
                  NULL, NULL, NULL, :ref, :src)'
            )->execute([
                'fid'      => $listingId,
                'filename' => $filename,
                'width'    => min(65535, max(0, $width)),
                'height'   => min(65535, max(0, $height)),
                'size_id'  => $sizeId,
                'donor_id' => $donorId,
                'sort'     => min(65535, $sort),
                'status'   => self::codeStatus($code),
                'ref'      => CodeSortSourceKey::importRef($ctx['key'], (int) $code['code_id']),
                'src'      => self::sourceFile($code),
            ]);
            $codeId = (int) $target->lastInsertId();
            $this->linkCategory($target, $codeId, $catId);
            $target->commit();
        } catch (\Throwable $e) {
            if ($target->inTransaction()) {
                $target->rollBack();
            }
            if ($newFilename !== null) {
                $this->deleteCodeImage($newFilename);
            }
            $row['errors'][] = self::codeLabel($code) . ': ' . $e->getMessage();
            if ($imageError !== null) {
                $images['errors'][] = $imageError;
            }
            return false;
        }

        // Committed: only now do the counts and the listing's next position move on.
        $row['imported']++;
        $state['nextSort'][$listingId] = $sort + 1;
        $state['addedIn'][$listingId]  = ($state['addedIn'][$listingId] ?? 0) + 1;
        if ($newFilename !== null) {
            $images['copied']++;
        }
        if ($imageError !== null) {
            $images['errors'][] = $imageError;
        }
        $this->checkCodeImage($ctx, $code, $filename, $codeId, $listingId, $state, $row);
        return true;
    }

    /**
     * Update matching: brings a matched code up to date with CodeSort. See runCodes().
     *
     * @param array<string,mixed> $ctx      See runCodes().
     * @param array<string,mixed> $decision From planCodes() ('matched' or 'claim').
     */
    private function updateMatchedCode(PDO $target, array $ctx, array $decision, array &$state, array &$row): void
    {
        $code      = $decision['code'];
        $existing  = $decision['existing'];
        $listingId = (int) $decision['listing'];
        $moved     = $existing['fanlisting_id'] !== $listingId;
        [$sizeId, $donorId, $catId] = self::codeRefs($ctx['maps'], $code, $listingId);

        $fields = ['status' => self::codeStatus($code)];
        $sort   = null;
        if ($moved) {
            // Everything it points at must belong to the new listing: what CodeSort maps there, or nothing.
            $sort    = $this->nextSort($target, $listingId, $state['nextSort']);
            $fields += [
                'fanlisting_id' => $listingId,
                'sort_order'    => min(65535, $sort),
                'size_id'       => $sizeId,
                'donor_id'      => $donorId,
            ];
        } else {
            // What CodeSort leaves blank stays as it is.
            if ($sizeId !== null) {
                $fields['size_id'] = $sizeId;
            }
            if ($donorId !== null) {
                $fields['donor_id'] = $donorId;
            }
        }
        if ($decision['action'] === 'claim') {
            $fields['import_ref']         = CodeSortSourceKey::importRef($ctx['key'], (int) $code['code_id']);
            $fields['import_source_file'] = self::sourceFile($code);
        }
        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = :{$c}", array_keys($fields)));

        $target->beginTransaction();
        try {
            $target->prepare("UPDATE lstr_codes SET {$set} WHERE id = :id")->execute($fields + ['id' => $existing['id']]);
            // A code with no CodeSort category keeps its Luster ones, unless it moved.
            if ($moved || $catId !== null) {
                $this->linkCategory($target, $existing['id'], $catId);
            }
            $target->commit();
        } catch (\Throwable $e) {
            if ($target->inTransaction()) {
                $target->rollBack();
            }
            $row['errors'][] = self::codeLabel($code) . ': ' . $e->getMessage();
            return;
        }

        $row['imported']++;
        if ($sort !== null) {
            $state['nextSort'][$listingId] = $sort + 1;
        }
        $this->checkCodeImage($ctx, $code, $existing['filename'], $existing['id'], $listingId, $state, $row);
    }

    /**
     * Keep existing: leaves a matched code as it is; a claimed one only gets its
     * import_ref, so later runs find it directly. See runCodes().
     *
     * @param array<string,mixed> $ctx      See runCodes().
     * @param array<string,mixed> $decision From planCodes() ('matched' or 'claim').
     */
    private function keepMatchedCode(PDO $target, array $ctx, array $decision, array &$state, array &$row): void
    {
        $code     = $decision['code'];
        $existing = $decision['existing'];

        if ($decision['action'] === 'claim') {
            try {
                $target->prepare('UPDATE lstr_codes SET import_ref = :ref, import_source_file = :src WHERE id = :id')
                    ->execute([
                        'ref' => CodeSortSourceKey::importRef($ctx['key'], (int) $code['code_id']),
                        'src' => self::sourceFile($code),
                        'id'  => $existing['id'],
                    ]);
            } catch (\Throwable $e) {
                $row['errors'][] = self::codeLabel($code) . ': ' . $e->getMessage();
                return;
            }
            $this->skipCode($row, $code, 'Linked to a code from an earlier CodeSort import');
        } else {
            $this->skipCode($row, $code, 'Already imported');
        }
        $this->checkCodeImage($ctx, $code, $existing['filename'], $existing['id'], $existing['fanlisting_id'], $state, $row);
    }

    /**
     * Copies one code's image into uploads/codes/: the server path first, then the web
     * address, or from the uploaded ZIP.
     *
     * @param array<string,mixed> $ctx See runCodes().
     */
    private function copyCodeImage(array $ctx, string $file, int $listingId, array &$row): ?string
    {
        $options  = $ctx['options'];
        $migrator = $ctx['migrator'];

        if ($options->imageMode === 'auto') {
            $newFilename = null;
            // Try server path first (images_folder; no subdir appended).
            if ($ctx['path'] !== '') {
                $newFilename = $migrator->copyFromPath(rtrim($ctx['path'], '/\\') . '/' . $file, 'codes', 'cs');
            }
            // Fall back to URL
            if ($newFilename === null && $ctx['url'] !== '') {
                $newFilename = $migrator->fetchFromUrl(rtrim($ctx['url'], '/') . '/' . rawurlencode($file), 'codes', 'cs');
            }
            return $newFilename;
        }

        if ($options->imageMode === 'zip') {
            if ($this->activeZip !== null) {
                // Pre-uploaded ZIP: extract image inline during run().
                return $migrator->copyFromZip($this->activeZip, $file, 'codes', 'cs');
            }
            // Legacy deferred / ZIP could not be opened.
            $row['image_map'][$file] = ['type' => 'codes', 'listing_id' => $listingId];
        }

        return null;
    }

    /**
     * Lists a code this run wrote or kept when its image isn't there. A code CodeSort
     * gives no image file is only counted: there's nothing to copy for it.
     *
     * @param array<string,mixed> $ctx  See runCodes().
     * @param array<string,mixed> $code One CodeSort codes row.
     */
    private function checkCodeImage(
        array $ctx, array $code, string $stored, int $codeId, int $listingId, array &$state, array &$row
    ): void {
        if ((string) $code['code_image'] === '') {
            $state['noFile']++;
            return;
        }
        if (!$this->codeImageIsPresent($stored)) {
            $title = $ctx['titles'][$listingId] ?? "Listing #{$listingId}";
            $row['missing_images'][] = "{$title}: {$stored} (code #{$codeId})";
        }
    }

    /**
     * Notes about codes an earlier CodeSort import left without an import_ref (see
     * planCodes()): ones added as new because "Match codes from an earlier CodeSort
     * import" isn't ticked, and, for listings that get new codes, ones stored under a
     * random image name. $done picks the result's wording over the preview's. The result
     * passes only codes that were really added, so one that failed is never "added again".
     *
     * @param array<int,list<string>> $readded lstr_lid → files of those new codes (added, or to add in the preview)
     * @param array<int,int>          $random  lstr_lid → random-name codes, from planCodes()
     * @param array<int,int>          $addedIn lstr_lid → codes added (or to be added, in the preview)
     * @param array<int,string>       $titles  lstr listing ID → title
     * @return list<string>
     */
    private function earlierImportNotes(array $readded, array $random, array $addedIn, array $titles, bool $done): array
    {
        $notes = [];

        foreach ($readded as $lid => $files) {
            $n     = count($files);
            $one   = $n === 1;
            $title = $titles[$lid] ?? "Listing #{$lid}";
            $what  = ($one ? '1 code' : "{$n} codes")
                . ' (' . implode(', ', array_slice($files, 0, 3)) . ($n > 3 ? ', …' : '') . ')';
            $notes[] = $done
                ? "{$title}: {$what} may already have been in Luster from an earlier CodeSort import, and "
                    . ($one ? 'was' : 'were') . ' added again. If the older ' . ($one ? 'copy' : 'copies')
                    . ' came from this CodeSort install, you can delete ' . ($one ? 'it' : 'them') . ' on the Codes page.'
                : "{$title}: {$what} may already be in Luster from an earlier CodeSort import. "
                    . ($one ? "It'll" : "They'll") . ' be added again. If ' . ($one ? 'it' : 'they')
                    . " came from this CodeSort install, go back to Options and tick 'Match codes from an earlier CodeSort import'.";
        }

        foreach ($random as $lid => $n) {
            if (($addedIn[$lid] ?? 0) === 0) {
                continue; // nothing new for that listing, so no extra copies to warn about
            }
            $one   = $n === 1;
            $title = $titles[$lid] ?? "Listing #{$lid}";
            $has   = ($one ? 'has 1 code' : "has {$n} codes")
                . " from an earlier CodeSort import that Luster can't match to CodeSort anymore";
            $notes[] = $done
                ? "{$title} {$has}, so this import may have added another copy of " . ($one ? 'it' : 'each')
                    . ". Running it again won't add more. You can delete the older " . ($one ? 'copy' : 'copies')
                    . ' on the Codes page.'
                : "{$title} {$has}. This import may add another copy of " . ($one ? 'it' : 'each')
                    . "; after that, running it again won't add more. You can then delete the older "
                    . ($one ? 'copy' : 'copies') . ' on the Codes page. To avoid the extra copies, untick Codes.';
        }

        return $notes;
    }

    /**
     * The size, donor and category a CodeSort code has in a Luster listing: only ones that
     * belong to that listing (CodeSort's categories are per listing too), null otherwise.
     *
     * @param array<string,array> $maps See runCodes().
     * @param array<string,mixed> $code One CodeSort codes row.
     * @return array{0: ?int, 1: ?int, 2: ?int}
     */
    private static function codeRefs(array $maps, array $code, int $listingId): array
    {
        $csSize  = (int) $code['code_size'];
        $csDonor = (int) $code['code_donor'];
        $csCat   = (int) $code['code_cat'];

        return [
            $csSize  > 0 ? ($maps['sizes'][$csSize][$listingId] ?? null) : null,
            $csDonor > 0 ? ($maps['donors'][$csDonor][$listingId] ?? null) : null,
            $csCat   > 0 && ($maps['catListings'][$csCat] ?? null) === $listingId ? ($maps['cats'][$csCat] ?? null) : null,
        ];
    }

    /** Replaces a code's category links with $categoryId (none when null). */
    private function linkCategory(PDO $target, int $codeId, ?int $categoryId): void
    {
        $target->prepare('DELETE FROM lstr_code_category_map WHERE code_id = :code')->execute(['code' => $codeId]);
        if ($categoryId !== null) {
            $target->prepare('INSERT INTO lstr_code_category_map (code_id, category_id) VALUES (:code, :cat)')
                ->execute(['code' => $codeId, 'cat' => $categoryId]);
        }
    }

    /**
     * Next sort position in a listing: after every code it has, read once and then
     * carried on from the last committed code.
     *
     * @param array<int,int> $nextSort lstr_lid → next position, after committed codes
     */
    private function nextSort(PDO $target, int $listingId, array $nextSort): int
    {
        if (isset($nextSort[$listingId])) {
            return $nextSort[$listingId];
        }
        $stmt = $target->prepare('SELECT MAX(sort_order) FROM lstr_codes WHERE fanlisting_id = :fid');
        $stmt->execute(['fid' => $listingId]);
        $max = $stmt->fetchColumn();
        return $max === null || $max === false ? 0 : (int) $max + 1;
    }

    /** @param array<string,mixed> $code One CodeSort codes row. */
    private function skipCode(array &$row, array $code, string $reason): void
    {
        $row['skipped']++;
        if (count($row['skipped_items']) < self::SKIP_CAP) {
            $row['skipped_items'][] = ['label' => self::codeLabel($code), 'reason' => $reason];
        }
    }

    /** @param array<string,mixed> $code One CodeSort codes row. */
    private static function codeLabel(array $code): string
    {
        $file = (string) $code['code_image'];
        return 'CodeSort code #' . (int) $code['code_id'] . ($file !== '' ? " ({$file})" : '');
    }

    /** @param array<string,mixed> $code One CodeSort codes row. */
    private static function codeStatus(array $code): string
    {
        return (string) $code['code_approved'] === 'y' ? 'approved' : 'pending';
    }

    /**
     * The CodeSort file a code came from, saved in import_source_file (cut to the
     * column's 255 characters), or null when CodeSort names none.
     *
     * @param array<string,mixed> $code One CodeSort codes row.
     */
    private static function sourceFile(array $code): ?string
    {
        $file = (string) $code['code_image'];
        return $file !== '' ? mb_substr($file, 0, 255, 'UTF-8') : null;
    }

    /**
     * @param array<string,mixed> $r A lstr_codes row.
     * @return array{id:int, fanlisting_id:int, filename:string}
     */
    private static function codeSummary(array $r): array
    {
        return ['id' => (int) $r['id'], 'fanlisting_id' => (int) $r['fanlisting_id'], 'filename' => (string) $r['filename']];
    }

    /** Whether lstr_codes has Migration 104's import_ref / import_source_file columns. */
    private function hasImportRefColumns(PDO $target): bool
    {
        try {
            $target->query('SELECT import_ref, import_source_file FROM lstr_codes LIMIT 1')->closeCursor();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * This CodeSort install's source key (see CodeSortSourceKey): the Source ID typed on
     * the Options step, or worked out from images_url in its own codes_options.
     *
     * @return array{key: ?string, origin: string}
     * @throws \RuntimeException When a typed-in Source ID isn't valid.
     */
    private function csSourceKey(PDO $source, string $compP, ImportOptions $options): array
    {
        return CodeSortSourceKey::resolve(
            $options->csSourceId,
            $this->readCodeSortImageSettings($source, $compP)['url'],
        );
    }

    /**
     * Width and height from CodeSort's size label, for a code whose image wasn't copied.
     *
     * @param array<string,mixed> $ctx See runCodes().
     * @return array{0:int,1:int}
     */
    private function sourceSizeDims(array $ctx, int $csSizeId): array
    {
        if ($csSizeId <= 0) {
            return [0, 0];
        }
        try {
            $s = $ctx['source']->prepare("SELECT size_size FROM `{$ctx['compP']}sizes` WHERE size_id = :id LIMIT 1");
            $s->execute(['id' => $csSizeId]);
            return $this->parseSizeString((string) $s->fetchColumn());
        } catch (\Throwable) {
            return [0, 0];
        }
    }

    /** @return array{0:int,1:int} The copied image's real size ([0, 0] if unreadable). */
    private function codeImageDims(string $filename): array
    {
        // ImageMigrator has already checked by MIME type that this is a real image.
        $info = getimagesize($this->codeImagePath($filename));
        return $info !== false ? [(int) $info[0], (int) $info[1]] : [0, 0];
    }

    private function codeImagePath(string $filename): string
    {
        return $this->publicDir . '/uploads/codes/' . $filename;
    }

    /** Whether a code's image file is really there (a bare filename inside uploads/codes/). */
    private function codeImageIsPresent(string $filename): bool
    {
        return $filename !== '' && basename($filename) === $filename && is_file($this->codeImagePath($filename));
    }

    private function deleteCodeImage(string $filename): void
    {
        if ($this->codeImageIsPresent($filename)) {
            unlink($this->codeImagePath($filename));
        }
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    /**
     * Reads configured image paths and URLs from the CodeSort options table
     * ({$compP}options, e.g. codes_options for the default layout).
     *
     * Returns ['path' => ..., 'url' => ...] with empty strings when the table
     * is absent or the relevant keys are not set.
     *
     * @return array{path: string, url: string}
     */
    private function readCodeSortImageSettings(PDO $source, string $compP): array
    {
        $table = $compP . 'options';
        $empty = ['path' => '', 'url' => ''];

        // Try common CodeSort column layouts: name/value, then name/option_value.
        foreach (['value', 'option_value'] as $valCol) {
            try {
                $stmt = $source->prepare(
                    "SELECT `name`, `{$valCol}` FROM `{$table}`
                      WHERE `name` IN ('images_folder', 'images_url')"
                );
                $stmt->execute();
                $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
                if (!empty($rows)) {
                    return [
                        'path' => rtrim((string) ($rows['images_folder'] ?? ''), '/\\'),
                        'url'  => rtrim((string) ($rows['images_url']    ?? ''), '/'),
                    ];
                }
            } catch (\Throwable) {}
        }

        return $empty;
    }

    /**
     * Prefix for the codes table itself.
     *   codesort_prefixed → 'codes_'  (table is codes_codes)
     *   codesort_default  → ''        (table is codes)
     *   codesort_bare     → ''        (table is codes)
     */
    private function tablePrefix(string $schema): string
    {
        return $schema === 'codesort_prefixed' ? 'codes_' : '';
    }

    /**
     * Prefix for companion tables (cat, sizes, donors, options, collective).
     *   codesort_default  → 'codes_'  (tables: codes_cat, codes_sizes, codes_donors, codes_options)
     *   codesort_prefixed → 'codes_'  (tables: codes_cat, codes_sizes, codes_donors, codes_collective)
     *   codesort_bare     → ''        (tables: cat, sizes, donors, collective)
     */
    private function companionPrefix(string $schema): string
    {
        return in_array($schema, ['codesort_default', 'codesort_prefixed'], true) ? 'codes_' : '';
    }

    /**
     * Builds cs_fl_id → lstr_listing_id map, using the appropriate strategy for
     * the detected schema variant.
     *
     * codesort_default — no collective table exists; code_fl is a direct FK into
     *   the fanlisting registry (owned.listingid or enth_fanlistings.id).  We match
     *   by ID first (direct passthrough when the ID exists in the Luster registry),
     *   falling back to "unmatched" for any code_fl that has no corresponding Luster
     *   listing.
     *
     * codesort_bare / codesort_prefixed — a dedicated collective table exists; match
     *   by fl_subject (case-insensitive title match against the Luster registry).
     *
     * @param array<string,int> $listingMap  lowercase(title) → lstr listing id
     * @return array{array<int,int|null>, string[]}
     */
    private function buildCollectiveMap(
        PDO $source, string $p, string $compP, string $schema, array $listingMap
    ): array {
        $collectiveMap     = [];
        $unmatchedSubjects = [];

        if ($schema === 'codesort_default') {
            // For the default layout, code_fl / cat_fl / donor references are direct FKs
            // into the Luster listing registry (owned.listingid).  Pre-populate the map
            // with every valid Luster listing ID so that categories, sizes, and donors
            // import correctly even when the codes table is empty or imported separately.
            $validIds = array_flip(array_values($listingMap)); // [listingid => 0, ...]
            foreach ($validIds as $lstrId => $_) {
                $collectiveMap[(int) $lstrId] = (int) $lstrId;
            }

            // Also scan the codes table for code_fl values that have no matching Luster
            // listing and add them to the map as null so they produce a warning later.
            try {
                $stmt = $source->query(
                    "SELECT DISTINCT code_fl FROM `{$p}codes` WHERE code_fl > 0 ORDER BY code_fl ASC"
                );
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
                    $csFlId = (int) $raw;
                    if (!isset($validIds[$csFlId])) {
                        $collectiveMap[$csFlId] = null;
                        $unmatchedSubjects[]    = "CodeSort listing #{$csFlId}";
                    }
                }
            } catch (\Throwable) {}

            return [$collectiveMap, $unmatchedSubjects];
        }

        // codesort_bare / codesort_prefixed: title-match via collective table.
        try {
            $stmt = $source->query(
                "SELECT fl_id, fl_subject FROM `{$compP}collective` ORDER BY fl_id ASC"
            );
            foreach ($stmt->fetchAll() as $row) {
                $csId   = (int) $row['fl_id'];
                $key    = strtolower(trim((string) $row['fl_subject']));
                $lstrId = $listingMap[$key] ?? null;

                $collectiveMap[$csId] = $lstrId;

                if ($lstrId === null) {
                    $unmatchedSubjects[] = (string) $row['fl_subject'];
                }
            }
        } catch (\Throwable) {}

        return [$collectiveMap, $unmatchedSubjects];
    }

    /**
     * A lowercase-title → listing_id map, for matching CodeSort listings by title.
     *
     * @param  array<int, string> $titles From listingTitles().
     * @return array<string, int>
     */
    private static function listingMapFromTitles(array $titles): array
    {
        $map = [];
        foreach ($titles as $id => $title) {
            $map[strtolower(trim($title))] = $id;
        }
        return $map;
    }

    /**
     * Returns a listing_id → title map from Luster's own DB.
     *
     * @return array<int, string>
     */
    private function listingTitles(PDO $target): array
    {
        $driver = $target->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            // SQLite: use sqlite_master instead of SHOW TABLES
            try {
                $chk = $target->query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='owned'"
                );
                if ($chk && $chk->fetchColumn() !== false) {
                    $stmt = $target->query("SELECT listingid, title FROM `owned`");
                    $map  = [];
                    foreach ($stmt->fetchAll() as $row) {
                        $map[(int) $row['listingid']] = (string) $row['title'];
                    }
                    return $map;
                }
            } catch (\Throwable) {}

            try {
                $chk = $target->query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='enth_fanlistings'"
                );
                if ($chk && $chk->fetchColumn() !== false) {
                    $stmt = $target->query("SELECT id, name FROM `enth_fanlistings`");
                    $map  = [];
                    foreach ($stmt->fetchAll() as $row) {
                        $map[(int) $row['id']] = (string) $row['name'];
                    }
                    return $map;
                }
            } catch (\Throwable) {}

            return [];
        }

        // MySQL / MariaDB
        try {
            $chk = $target->query("SHOW TABLES LIKE 'owned'");
            if ($chk && $chk->fetchColumn() !== false) {
                $stmt = $target->query("SELECT listingid, title FROM `owned`");
                $map  = [];
                foreach ($stmt->fetchAll() as $row) {
                    $map[(int) $row['listingid']] = (string) $row['title'];
                }
                return $map;
            }
        } catch (\Throwable) {}

        try {
            $chk = $target->query("SHOW TABLES LIKE 'enth_fanlistings'");
            if ($chk && $chk->fetchColumn() !== false) {
                $stmt = $target->query("SELECT id, name FROM `enth_fanlistings`");
                $map  = [];
                foreach ($stmt->fetchAll() as $row) {
                    $map[(int) $row['id']] = (string) $row['name'];
                }
                return $map;
            }
        } catch (\Throwable) {}

        return [];
    }

    /**
     * Parses a CodeSort size string like "50x50" or "88x31" into [width, height].
     * Returns [0, 0] if the format is unrecognised.
     *
     * @return array{int, int}
     */
    private function parseSizeString(string $s): array
    {
        if (preg_match('/^(\d+)\s*[xX×]\s*(\d+)$/', trim($s), $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        return [0, 0];
    }

    /** Trimmed and cut to $max characters, or null when blank. */
    private static function trimTo(string $value, int $max): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }
}
