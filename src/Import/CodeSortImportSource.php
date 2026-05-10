<?php

declare(strict_types=1);

namespace Luster\Import;

use PDO;

/**
 * Import adapter for CodeSort databases.
 *
 * CodeSort manages fan codes (small web graphics) across multiple fanlistings.
 * Two table naming variants are auto-detected:
 *
 *   'codesort_bare'     — bare table names: codes, cat, sizes, donors, collective
 *   'codesort_prefixed' — prefixed names: codes_codes, codes_cat, codes_sizes,
 *                         codes_donors, codes_collective
 *
 * What this adapter imports into Luster's lstr_* tables:
 *   - lstr_code_categories ← cat
 *   - lstr_code_sizes      ← sizes (global in CodeSort → per-listing in Luster)
 *   - lstr_code_donors     ← donors (global in CodeSort → per-listing in Luster)
 *   - lstr_codes           ← codes (with optional image file migration)
 *   - lstr_code_category_map ← one row per code that has a non-zero code_cat
 *
 * Fanlisting matching: CodeSort's `collective` table (fl_id, fl_subject) is matched
 * case-insensitively against Luster's listing titles. Codes for unmatched fanlistings
 * are skipped with a warning.
 *
 * Sizes in CodeSort are global (not per-fanlisting). This adapter creates one
 * lstr_code_sizes row per Luster listing for each CodeSort size.
 *
 * Donors in CodeSort are global. This adapter creates per-listing donor rows only
 * for listings that actually have codes from that donor.
 *
 * Image migration: code_image filenames are resolved under the `codes/` subdirectory
 * of the CodeSort install root. Width/height are taken from getimagesize() when
 * image migration is enabled, falling back to the mapped size's dimensions.
 */
final class CodeSortImportSource implements ImportSourceInterface
{
    private const CHUNK = 500;

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
        // Bare table names (default install)
        $stmt = $source->query("SHOW TABLES LIKE 'codes'");
        if ($stmt && $stmt->fetchColumn() !== false) {
            try {
                $source->query("SELECT code_id FROM `codes` LIMIT 0");
                return 'codesort_bare';
            } catch (\Throwable) {}
        }

        // Prefixed variant (codes_codes, codes_cat, …)
        $stmt = $source->query("SHOW TABLES LIKE 'codes_codes'");
        if ($stmt && $stmt->fetchColumn() !== false) {
            return 'codesort_prefixed';
        }

        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // Preview (read-only)
    // -----------------------------------------------------------------------

    public function preview(PDO $source, ImportOptions $options): ImportPreview
    {
        $schema = $options->schemaVariant !== '' ? $options->schemaVariant : $this->detectSchema($source);
        $p      = $options->tablePrefix . $this->tablePrefix($schema);
        $rows   = [];

        if ($options->importCodeCategories) {
            $rows[] = $this->previewCategories($source, $p);
        }
        if ($options->importCodeSizes) {
            $rows[] = $this->previewTable($source, "{$p}sizes", 'Code sizes');
        }
        if ($options->importCodeDonors) {
            $rows[] = $this->previewTable($source, "{$p}donors", 'Code donors');
        }
        if ($options->importCodes) {
            $rows[] = $this->previewCodes($source, $p, $options);
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
        $rows   = [];
        $imgRows = [];

        // Build image migrator if needed
        $migrator  = $options->imageMode !== ''
            ? new ImageMigrator(dirname(__DIR__, 2) . '/public')
            : null;
        $publicRoot = dirname(__DIR__, 2) . '/public';

        // Build Luster listing map: lowercase(title) → listing_id
        $listingMap = $this->buildListingMap($target);

        // Build CodeSort collective map: cs_fl_id → lstr_listing_id (null = unmatched)
        [$collectiveMap, $unmatchedSubjects] = $this->buildCollectiveMap($source, $p, $listingMap);
        $matchedListingIds = array_values(array_unique(array_filter(array_values($collectiveMap))));

        // Maps built during sub-imports and consumed by importCodes
        $catMap   = []; // cs_cat_id  → lstr_cat_id
        $sizeMap  = []; // cs_size_id → [lstr_lid => lstr_size_id]
        $donorMap = []; // cs_donor_id → [lstr_lid => lstr_donor_id]

        if ($options->importCodeCategories) {
            ['row' => $catRow, 'map' => $catMap] = $this->runCategories(
                $source, $target, $p, $collectiveMap, $options
            );
            $rows[] = $catRow;
        }

        if ($options->importCodeSizes) {
            ['row' => $sizeRow, 'map' => $sizeMap] = $this->runSizes(
                $source, $target, $p, $matchedListingIds, $options
            );
            $rows[] = $sizeRow;
        }

        if ($options->importCodeDonors) {
            ['row' => $donorRow, 'map' => $donorMap] = $this->runDonors(
                $source, $target, $p, $collectiveMap, $options
            );
            $rows[] = $donorRow;
        }

        if ($options->importCodes) {
            ['row' => $codeRow, 'imageRow' => $imgRow] = $this->runCodes(
                $source, $target, $p, $collectiveMap, $catMap, $sizeMap, $donorMap,
                $options, $migrator, $publicRoot
            );
            $rows[] = $codeRow;
            if ($imgRow !== null) {
                $imgRows[] = $imgRow;
            }

            // Store image map in session for ZIP mode
            if ($options->imageMode === 'zip' && !empty($codeRow['image_map'])) {
                $_SESSION['lstr_import_image_map'] = array_merge(
                    $_SESSION['lstr_import_image_map'] ?? [],
                    $codeRow['image_map']
                );
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
    private function previewCategories(PDO $source, string $p): array
    {
        $warns = [];
        try {
            $found = (int) $source->query("SELECT COUNT(*) FROM `{$p}cat`")->fetchColumn();
        } catch (\Throwable) {
            return ['type' => 'Code categories', 'found' => 0, 'import' => 0, 'skip' => 0, 'warnings' => []];
        }
        try {
            $collective = (int) $source->query("SELECT COUNT(*) FROM `{$p}cat` WHERE cat_fl = 0")->fetchColumn();
            if ($collective > 0) {
                $warns[] = "{$collective} category/categories have CodeSort collective scope (cat_fl=0) and will be skipped — Luster's code categories are always per-listing.";
            }
        } catch (\Throwable) {}
        return ['type' => 'Code categories', 'found' => $found, 'import' => $found - ($collective ?? 0), 'skip' => ($collective ?? 0), 'warnings' => $warns];
    }

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewCodes(PDO $source, string $p, ImportOptions $options): array
    {
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

        return ['type' => 'Codes', 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => $warns];
    }

    // -----------------------------------------------------------------------
    // Run helpers
    // -----------------------------------------------------------------------

    /**
     * @return array{row: array, map: array<int,int>}  cs_cat_id → lstr_cat_id
     */
    private function runCategories(
        PDO    $source,
        PDO    $target,
        string $p,
        array  $collectiveMap,
        ImportOptions $options,
    ): array {
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_code_categories (fanlisting_id, name, parent_id, sort_order)
             VALUES (:fid, :name, NULL, :sort)'
        );
        $checkStmt = $target->prepare(
            'SELECT id FROM lstr_code_categories WHERE fanlisting_id = :fid AND name = :name LIMIT 1'
        );

        $catMap           = [];
        $collectiveSkipped = 0;

        try {
            $rows = $source->query("SELECT cat_id, cat_fl, cat_name FROM `{$p}cat` ORDER BY cat_id ASC")->fetchAll();
        } catch (\Throwable) {
            return [
                'row' => ['type' => 'Code categories', 'imported' => 0, 'skipped' => 0, 'warnings' => ['Table not found.'], 'errors' => 1],
                'map' => [],
            ];
        }

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
                if ($options->conflictStrategy === 'skip') {
                    $checkStmt->execute(['fid' => $lstrLid, 'name' => $name]);
                    $existingId = $checkStmt->fetchColumn();
                    if ($existingId !== false) {
                        $catMap[(int) $row['cat_id']] = (int) $existingId;
                        $skipped++;
                        continue;
                    }
                }

                $insertStmt->execute(['fid' => $lstrLid, 'name' => $name, 'sort' => $idx]);
                $newId = (int) $target->lastInsertId();
                $catMap[(int) $row['cat_id']] = $newId;
                $imported++;
            } catch (\Throwable) {
                $errors++;
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
            'map' => $catMap,
        ];
    }

    /**
     * Creates one lstr_code_sizes row per Luster listing per CodeSort size.
     * Returns a nested map: cs_size_id → [lstr_lid => lstr_size_id].
     *
     * @param int[] $matchedListingIds
     * @return array{row: array, map: array<int, array<int,int>>}
     */
    private function runSizes(
        PDO    $source,
        PDO    $target,
        string $p,
        array  $matchedListingIds,
        ImportOptions $options,
    ): array {
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        if (empty($matchedListingIds)) {
            return [
                'row' => ['type' => 'Code sizes', 'imported' => 0, 'skipped' => 0, 'warnings' => ['No matched listings.'], 'errors' => 0],
                'map' => [],
            ];
        }

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_code_sizes (fanlisting_id, label, width, height, sort_order)
             VALUES (:fid, :label, :width, :height, :sort)'
        );
        $checkStmt = $target->prepare(
            'SELECT id FROM lstr_code_sizes WHERE fanlisting_id = :fid AND label = :label LIMIT 1'
        );

        $sizeMap = [];

        try {
            $csSizes = $source->query(
                "SELECT size_id, size_order, size_size FROM `{$p}sizes` ORDER BY size_order ASC, size_id ASC"
            )->fetchAll();
        } catch (\Throwable) {
            return [
                'row' => ['type' => 'Code sizes', 'imported' => 0, 'skipped' => 0, 'warnings' => ['Table not found.'], 'errors' => 1],
                'map' => [],
            ];
        }

        foreach ($csSizes as $csSize) {
            $csId  = (int) $csSize['size_id'];
            $label = (string) $csSize['size_size'];
            [$w, $h] = $this->parseSizeString($label);
            $sizeMap[$csId] = [];

            foreach ($matchedListingIds as $lstrLid) {
                try {
                    if ($options->conflictStrategy === 'skip') {
                        $checkStmt->execute(['fid' => $lstrLid, 'label' => $label]);
                        $existingId = $checkStmt->fetchColumn();
                        if ($existingId !== false) {
                            $sizeMap[$csId][$lstrLid] = (int) $existingId;
                            $skipped++;
                            continue;
                        }
                    }

                    $insertStmt->execute([
                        'fid'   => $lstrLid,
                        'label' => $label,
                        'width' => $w ?: null,
                        'height'=> $h ?: null,
                        'sort'  => (int) $csSize['size_order'],
                    ]);
                    $newId = (int) $target->lastInsertId();
                    $sizeMap[$csId][$lstrLid] = $newId;
                    $imported++;
                } catch (\Throwable) {
                    $errors++;
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
     * Creates per-listing donor rows for each CodeSort donor, scoped to the
     * listings that actually reference that donor via the codes table.
     *
     * @return array{row: array, map: array<int, array<int,int>>}  cs_donor_id → [lstr_lid => lstr_donor_id]
     */
    private function runDonors(
        PDO    $source,
        PDO    $target,
        string $p,
        array  $collectiveMap,
        ImportOptions $options,
    ): array {
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_code_donors (fanlisting_id, name, email, url)
             VALUES (:fid, :name, NULL, :url)'
        );
        $checkStmt = $target->prepare(
            'SELECT id FROM lstr_code_donors WHERE fanlisting_id = :fid AND name = :name LIMIT 1'
        );

        $donorMap = [];

        try {
            $donors = $source->query(
                "SELECT donor_id, donor_name, donor_url FROM `{$p}donors` ORDER BY donor_id ASC"
            )->fetchAll();
        } catch (\Throwable) {
            return [
                'row' => ['type' => 'Code donors', 'imported' => 0, 'skipped' => 0, 'warnings' => ['Table not found.'], 'errors' => 1],
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
            $name = (string) $donor['donor_name'];
            $url  = (string) $donor['donor_url'];
            $donorMap[$csId] = [];

            $csFlIds = $donorListings[$csId] ?? [];

            foreach ($csFlIds as $csFlId) {
                $lstrLid = $collectiveMap[$csFlId] ?? null;
                if ($lstrLid === null) {
                    continue;
                }

                try {
                    if ($options->conflictStrategy === 'skip') {
                        $checkStmt->execute(['fid' => $lstrLid, 'name' => $name]);
                        $existingId = $checkStmt->fetchColumn();
                        if ($existingId !== false) {
                            $donorMap[$csId][$lstrLid] = (int) $existingId;
                            $skipped++;
                            continue;
                        }
                    }

                    $insertStmt->execute([
                        'fid'  => $lstrLid,
                        'name' => $name,
                        'url'  => $url !== '' ? $url : null,
                    ]);
                    $newId = (int) $target->lastInsertId();
                    $donorMap[$csId][$lstrLid] = $newId;
                    $imported++;
                } catch (\Throwable) {
                    $errors++;
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
     * Imports codes from CodeSort into lstr_codes, with optional image migration.
     *
     * @param array<int,int|null> $collectiveMap  cs_fl_id → lstr_lid|null
     * @param array<int,int>      $catMap         cs_cat_id → lstr_cat_id
     * @param array<int,array<int,int>> $sizeMap  cs_size_id → [lstr_lid => lstr_size_id]
     * @param array<int,array<int,int>> $donorMap cs_donor_id → [lstr_lid => lstr_donor_id]
     * @return array{row: array, imageRow: ?array}
     */
    private function runCodes(
        PDO           $source,
        PDO           $target,
        string        $p,
        array         $collectiveMap,
        array         $catMap,
        array         $sizeMap,
        array         $donorMap,
        ImportOptions $options,
        ?ImageMigrator $migrator,
        string        $publicRoot,
    ): array {
        $imported          = 0;
        $skipped           = 0;
        $collectiveSkipped = 0;
        $errors            = 0;
        $imgCopied         = 0;
        $imgSkipped        = 0;
        $imgErrors         = 0;
        $warnings          = [];
        $imageMap          = []; // for ZIP mode

        // Sort order counter per listing
        $sortCounters = [];

        $insertCode = $target->prepare(
            'INSERT INTO lstr_codes
             (fanlisting_id, name, filename, width, height, size_id, donor_id, sort_order, status, submitter_name, submitter_email, submitter_url)
             VALUES (:fid, NULL, :filename, :width, :height, :size_id, :donor_id, :sort, :status, NULL, NULL, NULL)'
        );
        // Prepared lazily on first use so that databases without the junction
        // table (or test DBs that don't support INSERT IGNORE) don't fail before
        // any codes are processed.
        $insertCatMap = null;

        $offset = 0;

        while (true) {
            $stmt = $source->prepare(
                "SELECT code_id, code_fl, code_cat, code_size, code_image, code_donor, code_approved
                 FROM `{$p}codes`
                 ORDER BY code_id ASC
                 LIMIT :limit OFFSET :offset"
            );
            $stmt->bindValue(':limit',  self::CHUNK, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset,     PDO::PARAM_INT);
            $stmt->execute();
            $chunk = $stmt->fetchAll();

            if (empty($chunk)) {
                break;
            }

            foreach ($chunk as $row) {
                $csFlId  = (int) $row['code_fl'];

                if ($csFlId === 0) {
                    // code_fl=0 means "whole collective" scope in CodeSort — no per-listing equivalent in Luster
                    $collectiveSkipped++;
                    continue;
                }

                $lstrLid = $collectiveMap[$csFlId] ?? null;

                if ($lstrLid === null) {
                    $skipped++;
                    continue;
                }

                $csFilename = (string) $row['code_image'];
                $csSizeId   = (int) $row['code_size'];
                $csCatId    = (int) $row['code_cat'];
                $csDonorId  = (int) $row['code_donor'];
                $approved   = (string) $row['code_approved'];

                // Resolve IDs
                $lstrSizeId  = ($csSizeId > 0) ? ($sizeMap[$csSizeId][$lstrLid] ?? null) : null;
                $lstrCatId   = ($csCatId  > 0) ? ($catMap[$csCatId]   ?? null) : null;
                $lstrDonorId = ($csDonorId > 0) ? ($donorMap[$csDonorId][$lstrLid] ?? null) : null;
                $status      = $approved === 'y' ? 'approved' : 'pending';

                // Sort order
                $sortCounters[$lstrLid] = ($sortCounters[$lstrLid] ?? -1) + 1;
                $sortOrder = $sortCounters[$lstrLid];

                // Migrate image
                $newFilename = null;
                $width       = 0;
                $height      = 0;

                if ($migrator !== null && $csFilename !== '') {
                    if ($options->imageMode === 'path' && $options->imagePath !== '') {
                        // $options->imagePath is the value of images_folder from codes-config.php —
                        // the full path to the image directory, not the install root.
                        // No subdirectory is appended; filenames live directly in that folder.
                        $srcPath = rtrim($options->imagePath, '/\\') . '/' . $csFilename;
                        $newFilename = $migrator->copyFromPath($srcPath, 'codes', 'cs');
                    } elseif ($options->imageMode === 'url' && $options->imageUrl !== '') {
                        // $options->imageUrl is the value of images_url from codes-config.php.
                        $srcUrl = rtrim($options->imageUrl, '/') . '/' . rawurlencode($csFilename);
                        $newFilename = $migrator->fetchFromUrl($srcUrl, 'codes', 'cs');
                    } elseif ($options->imageMode === 'zip') {
                        // Record for post-import ZIP step
                        $imageMap[$csFilename] = ['type' => 'codes', 'listing_id' => $lstrLid];
                        $newFilename = null; // filled in later
                    }

                    if ($newFilename !== null) {
                        $imgCopied++;
                        // Try to read actual dimensions from the copied file
                        $fullPath = $publicRoot . '/uploads/codes/' . $newFilename;
                        $imgInfo  = @getimagesize($fullPath);
                        if ($imgInfo !== false) {
                            $width  = (int) $imgInfo[0];
                            $height = (int) $imgInfo[1];
                        }
                    } elseif ($options->imageMode !== 'zip') {
                        $imgErrors++;
                    }
                }

                // Fall back to size dimensions if image not available
                if ($width === 0 && $lstrSizeId !== null) {
                    // Look up parsed size dimensions from source
                    if ($csSizeId > 0) {
                        try {
                            $s = $source->prepare("SELECT size_size FROM `{$p}sizes` WHERE size_id = :id LIMIT 1");
                            $s->execute(['id' => $csSizeId]);
                            $sizeStr = (string) $s->fetchColumn();
                            [$width, $height] = $this->parseSizeString($sizeStr);
                        } catch (\Throwable) {}
                    }
                }

                // Use placeholder filename in ZIP mode
                $filename = $newFilename ?? ($options->imageMode === 'zip' ? $csFilename : $csFilename);

                try {
                    $insertCode->execute([
                        'fid'      => $lstrLid,
                        'filename' => $filename,
                        'width'    => $width,
                        'height'   => $height,
                        'size_id'  => $lstrSizeId,
                        'donor_id' => $lstrDonorId,
                        'sort'     => $sortOrder,
                        'status'   => $status,
                    ]);
                    $newCodeId = (int) $target->lastInsertId();
                    $imported++;

                    // Assign category via junction table (lazy-prepare on first use)
                    if ($lstrCatId !== null) {
                        if ($insertCatMap === null) {
                            $insertCatMap = $target->prepare(
                                'INSERT IGNORE INTO lstr_code_category_map (code_id, category_id) VALUES (:code_id, :cat_id)'
                            );
                        }
                        $insertCatMap->execute(['code_id' => $newCodeId, 'cat_id' => $lstrCatId]);
                    }
                } catch (\Throwable) {
                    $errors++;
                    if ($newFilename !== null) {
                        $imgCopied--; // revert count if insert failed
                        $imgErrors++;
                    }
                }
            }

            $offset += self::CHUNK;
        }

        if ($collectiveSkipped > 0) {
            $warnings[] = "{$collectiveSkipped} code(s) with CodeSort collective scope (code_fl=0) were skipped — Luster's codes are always per-listing.";
        }

        if ($options->imageMode === '' && $imported > 0) {
            $warnings[] = 'Code image files were not migrated. Use the Codes admin panel to upload images, or re-run the import with image migration enabled.';
        }

        $codeRow = [
            'type'      => 'Codes',
            'imported'  => $imported,
            'skipped'   => $skipped + $collectiveSkipped,
            'warnings'  => $warnings,
            'errors'    => $errors,
            'image_map' => $imageMap,
        ];

        $imageRow = ($imgCopied + $imgErrors + $imgSkipped > 0) ? [
            'type'    => 'Images (codes)',
            'copied'  => $imgCopied,
            'skipped' => $imgSkipped,
            'errors'  => $imgErrors,
        ] : null;

        return ['row' => $codeRow, 'imageRow' => $imageRow];
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    /**
     * Returns the table prefix string for the detected schema variant.
     * 'codesort_bare' → ''   (tables: codes, cat, sizes, donors, collective)
     * 'codesort_prefixed' → 'codes_'  (tables: codes_codes, codes_cat, …)
     */
    private function tablePrefix(string $schema): string
    {
        return $schema === 'codesort_prefixed' ? 'codes_' : '';
    }

    /**
     * Loads CodeSort's collective table and builds cs_fl_id → lstr_listing_id map.
     * Returns [$collectiveMap, $unmatchedSubjects].
     *
     * @param array<string,int> $listingMap  lowercase(title) → lstr listing id
     * @return array{array<int,int|null>, string[]}
     */
    private function buildCollectiveMap(PDO $source, string $p, array $listingMap): array
    {
        $collectiveMap    = [];
        $unmatchedSubjects = [];

        try {
            $stmt = $source->query("SELECT fl_id, fl_subject FROM `{$p}collective` ORDER BY fl_id ASC");
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
     * Returns a lowercase-title → listing_id map from Luster's own DB.
     *
     * @return array<string, int>
     */
    private function buildListingMap(PDO $target): array
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
                        $map[strtolower(trim((string) $row['title']))] = (int) $row['listingid'];
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
                        $map[strtolower(trim((string) $row['name']))] = (int) $row['id'];
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
                    $map[strtolower(trim((string) $row['title']))] = (int) $row['listingid'];
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
                    $map[strtolower(trim((string) $row['name']))] = (int) $row['id'];
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
}
