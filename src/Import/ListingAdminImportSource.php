<?php

declare(strict_types=1);

namespace Luster\Import;

use PDO;

/**
 * Import adapter for Listing Admin (v3.x) databases.
 *
 * Listing Admin schema key points:
 *   - `main`      — central listing registry (one row per fanlisting)
 *   - `members`   — all fan members across all listings, joined by fNiq → main.id
 *   - `categories`— collective categories (catid, catname, parent)
 *   - `affiliates`— affiliate sites (aID, fNiq, aSubject, aURL, aImage, aEmail)
 *   - `joined`    — joined fanlistings (jID, jSubject, jURL, jImage, jCategory, jStatus, jAdd)
 *   - `updates`   — news/update entries (uID, fNiq, uTitle, uEntry, uAdded)
 *
 * What this adapter imports into Luster:
 *   - `owned` (or `enth_fanlistings`)  ← `main`
 *   - Per-listing member tables        ← `members` partitioned by fNiq
 *   - `lstr_fanlisting_settings`       ← `main` (per-listing settings)
 *   - `lstr_categories`               ← `categories`
 *   - `lstr_affiliates`               ← `affiliates`
 *   - `lstr_joined`                   ← `joined`
 *   - `lstr_updates`                  ← `updates` (requires Updates Feed add-on)
 *   - `lstr_email_templates`          ← Not applicable (LA uses its own template system)
 *
 * Member table creation:
 *   For each row in `main`, a new per-listing member table is created in
 *   Luster's target database using Enthusiast's standard member table structure.
 *   The `dbtable` slug is generated from `main.subject` and stored in `owned.dbtable`.
 *
 * Additional fields (mExtra):
 *   LA stores custom field values in `members.mExtra` as a delimited text blob.
 *   The field names come from `main.fave_fields`. Parsing is best-effort;
 *   members are still imported if parsing fails (without custom field data).
 */
final class ListingAdminImportSource implements ImportSourceInterface
{
    private const CHUNK    = 500;
    private const SKIP_CAP = 500;

    private ?\ZipArchive $activeZip = null;

    private const IMG_DIRS = [
        'affiliates' => 'images/affiliates',
        'joined'     => 'images/joined',
        'listings'   => 'images',
    ];

    // -----------------------------------------------------------------------
    // ImportSourceInterface
    // -----------------------------------------------------------------------

    public function getName(): string
    {
        return 'Listing Admin';
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
            // SQLite path (used in tests).
            $allTables = $source->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
            $tableSet  = array_flip($allTables);

            if (isset($tableSet['main'])) {
                $cols = array_column(
                    $source->query("PRAGMA table_info(`main`)")->fetchAll(PDO::FETCH_ASSOC),
                    'name'
                );
                if (in_array('fNiq', $cols, true) || in_array('dbtabl', $cols, true)) {
                    return 'listing_admin_3x';
                }
            }

            foreach ($allTables as $tbl) {
                if (isset($tableSet[$tbl . '_members']) && isset($tableSet[$tbl . '_affiliates'])) {
                    return 'listing_admin_3x';
                }
            }

            return 'unknown';
        }

        // MySQL / MariaDB path.
        // Default LA install: registry table is `main`
        try {
            $stmt = $source->query("SHOW TABLES LIKE 'main'");
            if ($stmt && $stmt->fetchColumn() !== false) {
                $cols = $source->query("SHOW COLUMNS FROM `main`")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('fNiq', $cols, true) || in_array('dbtabl', $cols, true)) {
                    return 'listing_admin_3x';
                }
            }
        } catch (\Throwable) {}

        // Custom prefix: look for any table where tableName_members also exists.
        // (e.g., with prefix 'trex_', the registry is 'trex' and 'trex_members' exists.)
        try {
            $allTables = $source->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $tableSet  = array_flip($allTables);
            foreach ($allTables as $tbl) {
                if (isset($tableSet[$tbl . '_members']) && isset($tableSet[$tbl . '_affiliates'])) {
                    return 'listing_admin_3x';
                }
            }
        } catch (\Throwable) {}

        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // Preview (read-only)
    // -----------------------------------------------------------------------

    public function preview(PDO $source, ImportOptions $options): ImportPreview
    {
        $schema = $options->schemaVariant !== '' ? $options->schemaVariant : $this->detectSchema($source);
        $tp     = $options->tablePrefix;
        $laP    = $options->laTablePrefix;
        // Registry table: custom prefix strips trailing underscores (e.g. 'trex_' → 'trex').
        // Default (empty prefix) uses 'main'.
        $laReg  = $laP !== '' ? str_replace('_', '', $laP) : 'main';
        $sp     = $tp . $laP;    // full source prefix for non-registry tables
        $sreg   = $tp . $laReg;  // full source registry table name
        $rows   = [];

        if ($options->importCategories) {
            $rows[] = $this->previewTable($source, $sp . 'categories', 'Categories');
        }
        if ($options->importListings) {
            $rows[] = $this->previewTable($source, $sreg, 'Owned Listings');
        }
        if ($options->importMembers) {
            $rows[] = $this->previewTable($source, $sp . 'members', 'Members', [
                'LA stores members in a single central table. Each member row will be distributed to its listing\'s dedicated table.',
            ]);
        }
        if ($options->importAffiliates) {
            $rows[] = $this->previewTable($source, $sp . 'affiliates', 'Affiliates', [
                'Per-listing affiliates (fNiq > 0) are linked to their listing. Multi-listing affiliates (fNiq format !1!!2!) produce one row per matched listing. Affiliates for unimported listings fall back to collective.',
            ]);
        }
        if ($options->importJoined) {
            $rows[] = $this->previewTable($source, $sp . 'joined', 'Joined listings');
        }
        if ($options->importUpdates) {
            if ($this->updatesAddonInstalled($source)) {
                $rows[] = $this->previewTable($source, $sp . 'updates', 'Updates', [
                    'LA crossposting fields (uDW, uIJ, uLJ) will be dropped.',
                ]);
            } else {
                $rows[] = ['type' => 'Updates', 'found' => 0, 'import' => 0, 'skip' => 0,
                           'warnings' => ['Updates Feed add-on is not installed — updates will be skipped.']];
            }
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
        $tp     = $options->tablePrefix;
        $laP    = $options->laTablePrefix;
        $laReg  = $laP !== '' ? str_replace('_', '', $laP) : 'main';
        $sp     = $tp . $laP;    // full source prefix for non-registry tables
        $sreg   = $tp . $laReg;  // full source registry table name
        $rows   = [];
        $imageRows = [];

        // Detect Luster's target schema (owned vs enth_fanlistings)
        $targetSchema = $this->detectTargetSchema($target);

        // Image migrator
        $migrator = $options->imageMode !== ''
            ? new ImageMigrator(dirname(__DIR__, 2) . '/public')
            : null;

        // Open a pre-uploaded ZIP archive (Phase 3 ZIP mode).
        if ($options->imageMode === 'zip' && $options->zipPath !== '' && $migrator !== null) {
            $this->activeZip = new \ZipArchive();
            if ($this->activeZip->open($options->zipPath) !== true) {
                $this->activeZip = null;
            }
        }

        // Read actual image paths/URLs from the Listing Admin options table.
        // These are the paths configured by the site owner (can be anything).
        // Returns [] if the table is absent or values are blank — callers fall
        // back to $options->imagePath / $options->imageUrl directly.
        $imageSettings = $migrator !== null
            ? $this->readImageSettings($source, $laP)
            : [];

        $imageMap = [];

        // Source category ID→name map for resolving pipe-delimited category IDs
        $srcCatIdToName = $this->buildSourceCategoryMap($source, $sp);

        // ── Step 1: Categories ────────────────────────────────────────────
        if ($options->importCategories) {
            $rows[] = $this->runCategories($source, $target, $options, $sp);
        }

        // Category name→id map for downstream use
        $catNameToId = $this->buildCategoryNameMap($target);

        // ── Step 2: Listings + member tables ─────────────────────────────
        $listingIdMap = []; // LA main.id → new owned listingid
        if ($options->importListings) {
            ['row' => $listRow, 'idMap' => $listingIdMap, 'imageMap' => $lim] =
                $this->runListings($source, $target, $targetSchema, $options, $catNameToId, $srcCatIdToName, $migrator, $imageSettings, $sp, $sreg);
            $rows[]   = $listRow;
            $imageMap = array_merge($imageMap, $lim);
            if ($listRow['image_copied'] > 0 || $listRow['image_skipped'] > 0) {
                $imageRows[] = ['type' => 'Images (listings)', 'copied' => $listRow['image_copied'],
                                'skipped' => $listRow['image_skipped'], 'errors' => $listRow['image_errors']];
            }
        }

        // ── Step 3: Members ───────────────────────────────────────────────
        if ($options->importMembers && !empty($listingIdMap)) {
            $rows[] = $this->runMembers($source, $target, $targetSchema, $options, $listingIdMap, $sp, $sreg);
        }

        // ── Step 4: Affiliates ────────────────────────────────────────────
        if ($options->importAffiliates) {
            ['row' => $affRow, 'imageMap' => $affMap] =
                $this->runAffiliates($source, $target, $options, $migrator, $imageSettings, $listingIdMap, $sp);
            $rows[]   = $affRow;
            $imageMap = array_merge($imageMap, $affMap);
            if ($affRow['image_copied'] > 0 || $affRow['image_skipped'] > 0) {
                $imageRows[] = ['type' => 'Images (affiliates)', 'copied' => $affRow['image_copied'],
                                'skipped' => $affRow['image_skipped'], 'errors' => $affRow['image_errors']];
            }
        }

        // ── Step 5: Joined listings ───────────────────────────────────────
        if ($options->importJoined) {
            ['row' => $joinRow, 'imageMap' => $joinMap] =
                $this->runJoined($source, $target, $options, $catNameToId, $srcCatIdToName, $migrator, $imageSettings, $sp);
            $rows[]   = $joinRow;
            $imageMap = array_merge($imageMap, $joinMap);
            if ($joinRow['image_copied'] > 0 || $joinRow['image_skipped'] > 0) {
                $imageRows[] = ['type' => 'Images (joined)', 'copied' => $joinRow['image_copied'],
                                'skipped' => $joinRow['image_skipped'], 'errors' => $joinRow['image_errors']];
            }
        }

        // ── Step 6: Updates ───────────────────────────────────────────────
        if ($options->importUpdates) {
            $rows[] = $this->runUpdates($source, $target, $options, $listingIdMap, $sp);
        }

        // Close and clean up pre-uploaded ZIP if one was opened.
        if ($this->activeZip !== null) {
            $this->activeZip->close();
            $this->activeZip = null;
            if ($options->zipPath !== '') {
                @unlink($options->zipPath);
            }
        }

        return new ImportResult(
            rows:          $rows,
            imageRows:     $imageRows,
            duration:      microtime(true) - $start,
            schemaVariant: $schema,
        );
    }

    // -----------------------------------------------------------------------
    // Preview helpers
    // -----------------------------------------------------------------------

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewTable(PDO $source, string $table, string $label, array $warnings = []): array
    {
        try {
            $stmt  = $source->query("SELECT COUNT(*) FROM `{$table}`");
            $found = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $found = 0;
        }
        return ['type' => $label, 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => $warnings];
    }

    private function updatesAddonInstalled(PDO $target): bool
    {
        try {
            $stmt = $target->query("SHOW TABLES LIKE 'lstr_updates'");
            return $stmt && $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Run helpers — Categories (same logic as Enthusiast adapter)
    // -----------------------------------------------------------------------

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[]} */
    private function runCategories(PDO $source, PDO $target, ImportOptions $options, string $tp = ''): array
    {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];

        try {
            $stmt    = $source->query("SELECT * FROM `{$tp}categories` ORDER BY parent ASC, catid ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return ['type' => 'Categories', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [], 'errors' => ['Could not read source categories: ' . $e->getMessage()],
                    'skipped_items' => []];
        }

        $existStmt = $target->prepare('SELECT id, name FROM lstr_categories');
        $existStmt->execute();
        $existingNames = [];
        foreach ($existStmt->fetchAll() as $r) {
            $existingNames[$r['name']] = (int) $r['id'];
        }

        $srcIdToNewId = [];
        $insertStmt   = $target->prepare(
            'INSERT INTO lstr_categories (name, parent_id, source) VALUES (:name, :parent_id, :source)'
        );

        foreach ($srcRows as $row) {
            $name = trim((string) ($row['catname'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (isset($existingNames[$name])) {
                $skipped++;
                if (count($skippedItems) < self::SKIP_CAP) {
                    $skippedItems[] = ['label' => $name, 'reason' => 'Already exists in Luster'];
                }
                $srcIdToNewId[(int) $row['catid']] = $existingNames[$name];
                continue;
            }

            $parentId  = null;
            $srcParent = (int) ($row['parent'] ?? 0);
            if ($srcParent > 0 && isset($srcIdToNewId[$srcParent])) {
                $parentId = $srcIdToNewId[$srcParent];
            }

            try {
                $insertStmt->execute(['name' => $name, 'parent_id' => $parentId, 'source' => 'custom']);
                $newId = (int) $target->lastInsertId();
                $srcIdToNewId[(int) $row['catid']] = $newId;
                $existingNames[$name]              = $newId;
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Category '{$name}': " . $e->getMessage();
            }
        }

        return ['type' => 'Categories', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Listings
    // -----------------------------------------------------------------------

    /**
     * Creates `owned` rows and per-listing member tables for each LA `main` row.
     *
     * @return array{row: array, idMap: array<int,int>, imageMap: array}
     */
    private function runListings(
        PDO $source, PDO $target, string $targetSchema,
        ImportOptions $options, array $catNameToId, array $srcCatIdToName,
        ?ImageMigrator $migrator, array $imageSettings, string $tp = '', string $sreg = 'main'
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgSkipped   = 0;
        $imgErrors    = [];
        $imageMap     = [];
        $idMap        = []; // LA id → new Luster id

        [$regTable, $idCol, $isOwned] = $this->targetRegistryInfo($targetSchema);

        $checkStmt = $target->prepare("SELECT {$idCol} FROM `{$regTable}` WHERE url = :url LIMIT 1");

        try {
            $stmt    = $source->query("SELECT * FROM `{$sreg}` ORDER BY id ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [
                'row' => ['type' => 'Listings', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                          'errors' => ['Could not read source listings: ' . $e->getMessage()],
                          'image_copied' => 0, 'image_skipped' => 0, 'image_errors' => [],
                          'skipped_items' => []],
                'idMap' => [], 'imageMap' => [],
            ];
        }

        // Track existing dbtable values to avoid collisions when generating slugs
        $existingDbtables = $this->loadExistingDbtables($target, $regTable, $isOwned);

        foreach ($srcRows as $row) {
            $laId   = (int) ($row['id'] ?? 0);
            $url    = trim((string) ($row['url'] ?? ''));
            $title  = trim((string) ($row['title'] ?? ($row['subject'] ?? '')));
            $subject = trim((string) ($row['subject'] ?? $title));

            if ($url === '' || $subject === '') {
                continue;
            }

            // Extract early for use in the retry branch.
            $imgFilename = trim((string) ($row['image'] ?? ''));

            // Skip if URL already exists in target
            $checkStmt->execute(['url' => $url]);
            $existId = $checkStmt->fetchColumn();
            if ($existId !== false) {
                if ($options->conflictStrategy === 'skip') {
                    $idMap[$laId] = (int) $existId;
                    // Re-run: if the settings row for this listing has image = NULL, retry.
                    if ($migrator !== null && $imgFilename !== '' && $options->imageMode !== 'zip') {
                        $rStmt = $target->prepare(
                            'SELECT image FROM lstr_fanlisting_settings WHERE fanlisting_id = :fid LIMIT 1'
                        );
                        $rStmt->execute(['fid' => (int) $existId]);
                        $curImg = $rStmt->fetchColumn();
                        if ($curImg === null || $curImg === false) {
                            $dummyMap = [];
                            $retried  = $this->migrateImage(
                                $migrator, $imgFilename, 'listings', 'fl_',
                                $options, $imageSettings, $dummyMap, $imgCopied, $imgSkipped, $imgErrors
                            );
                            if ($retried !== null) {
                                $target->prepare(
                                    'UPDATE lstr_fanlisting_settings SET image = :img WHERE fanlisting_id = :fid'
                                )->execute(['img' => $retried, 'fid' => (int) $existId]);
                            }
                        }
                    }
                    $skipped++;
                    if (count($skippedItems) < self::SKIP_CAP) {
                        $skippedItems[] = ['label' => ($title !== '' ? $title : $url) . ' — ' . $url, 'reason' => 'Already exists (same URL)'];
                    }
                    continue;
                }
            }

            // Generate unique dbtable slug
            $dbtable = $this->generateDbtable($subject, $existingDbtables);
            $existingDbtables[$dbtable] = true;

            // Resolve category — handles pipe-delimited IDs (|12|) and plain names.
            $catRaw      = trim((string) ($row['category'] ?? ''));
            $parsedCatIds = self::parseLegacyCategoryIds($catRaw);
            $catName     = '';
            if (!empty($parsedCatIds) && !empty($srcCatIdToName)) {
                $catName = $srcCatIdToName[$parsedCatIds[0]] ?? '';
            }
            if ($catName === '' && $catRaw !== '' && empty($parsedCatIds)) {
                $catName = $catRaw; // plain text name — use directly
            }
            $catId = ($catName !== '' && isset($catNameToId[$catName])) ? $catNameToId[$catName] : null;

            // Status: LA has no exact equivalent; assume active
            $status = 2; // active in owned schema

            // Opened date
            $opened = !empty($row['date']) ? $row['date'] : null;

            // Image migration for new records
            $newImage = null;
            if ($imgFilename !== '' && $migrator !== null) {
                $newImage = $this->migrateImage(
                    $migrator, $imgFilename, 'listings', 'fl_', $options, $imageSettings,
                    $imageMap, $imgCopied, $imgSkipped, $imgErrors
                );
            }

            // fave_fields — additional field names for the member table
            $faveFields = $this->parseFaveFields((string) ($row['fave_fields'] ?? ''));

            try {
                if ($existId !== false) {
                    // Overwrite: update existing listing
                    $newListingId = (int) $existId;
                    $this->updateListing($target, $regTable, $idCol, $newListingId,
                        $title, $subject, $url, $dbtable, $catId, $opened, $isOwned, $status, $newImage);
                    $skipped++;
                } else {
                    // Insert new listing row
                    $newListingId = $this->insertListing($target, $regTable, $idCol,
                        $title, $subject, $url, $dbtable, $catId, $opened, $isOwned, $status, $newImage);
                    $imported++;
                }

                $idMap[$laId] = $newListingId;

                // Create per-listing member table (DDL is idempotent via CREATE TABLE IF NOT EXISTS)
                $memberTable = $isOwned ? $dbtable : $dbtable . '_members';
                $this->createMemberTable($target, $memberTable, $faveFields);

                // Create lstr_fanlisting_settings row
                $this->ensureListingSettings($target, $newListingId, $row);

                // ZIP image map entry
                if ($imgFilename !== '' && $newImage === null && $options->imageMode === 'zip') {
                    $imageMap[$imgFilename] = [
                        'table'        => 'lstr_fanlisting_settings',
                        'key'          => 'fanlisting_id',
                        'column'       => 'image',
                        'fanlisting_id' => $newListingId,
                        'type'         => 'listings',
                        'prefix'       => 'fl_',
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = "Listing '{$subject}': " . $e->getMessage();
                unset($existingDbtables[$dbtable]);
            }
        }

        return [
            'row' => ['type' => 'Listings', 'imported' => $imported, 'skipped' => $skipped,
                      'warnings' => [], 'errors' => $errors,
                      'image_copied' => $imgCopied, 'image_skipped' => $imgSkipped, 'image_errors' => $imgErrors,
                      'skipped_items' => $skippedItems],
            'idMap'     => $idMap,
            'imageMap'  => $imageMap,
        ];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Members
    // -----------------------------------------------------------------------

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],skipped_items:array<array{label:string,reason:string}>} */
    private function runMembers(
        PDO $source, PDO $target, string $targetSchema,
        ImportOptions $options, array $listingIdMap, string $tp = '', string $sreg = 'main'
    ): array {
        $imported      = 0;
        $skipped       = 0;
        $errors        = [];
        $skippedItems  = [];
        $warnings      = [];
        $extraFailures = 0;

        [$regTable, $idCol, $isOwned] = $this->targetRegistryInfo($targetSchema);

        // Build map: new Luster listing id → dbtable name
        $dbtableMap = $this->buildDbtableMap($target, $regTable, $idCol, array_values($listingIdMap));

        // Load fave_fields for each LA listing (for mExtra parsing)
        $faveMap = []; // LA id → field names array
        try {
            $stmt = $source->query("SELECT id, fave_fields FROM `{$sreg}`");
            foreach ($stmt->fetchAll() as $r) {
                $faveMap[(int) $r['id']] = $this->parseFaveFields((string) ($r['fave_fields'] ?? ''));
            }
        } catch (\Throwable) {}

        // Process members in chunks, grouped by fNiq
        $offset = 0;
        do {
            try {
                $stmt = $source->prepare(
                    "SELECT * FROM `{$tp}members` ORDER BY fNiq ASC, mID ASC LIMIT :lim OFFSET :off"
                );
                $stmt->bindValue(':lim', self::CHUNK, PDO::PARAM_INT);
                $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $chunk = $stmt->fetchAll();
            } catch (\Throwable $e) {
                $errors[] = 'Could not read members chunk at offset ' . $offset . ': ' . $e->getMessage();
                break;
            }

            foreach ($chunk as $row) {
                $laListingId  = (int) ($row['fNiq'] ?? 0);
                $newListingId = $listingIdMap[$laListingId] ?? null;
                if ($newListingId === null) {
                    continue; // LA listing was not imported
                }

                $dbtable = $dbtableMap[$newListingId] ?? null;
                if ($dbtable === null || $dbtable === '') {
                    continue;
                }

                $email = strtolower(trim((string) ($row['mEmail'] ?? '')));
                if ($email === '') {
                    continue;
                }

                // Check for existing email in this member table
                try {
                    $chk = $target->prepare("SELECT email FROM `{$dbtable}` WHERE email = :email LIMIT 1");
                    $chk->execute(['email' => $email]);
                    if ($chk->fetchColumn() !== false) {
                        if ($options->conflictStrategy === 'skip') {
                            $skipped++;
                            if (count($skippedItems) < self::SKIP_CAP) {
                                $skippedItems[] = ['label' => $email, 'reason' => 'Already exists'];
                            }
                            continue;
                        }
                    }
                } catch (\Throwable) {
                    // Table may not exist yet for this listing
                }

                // Parse mExtra
                $faveFields   = $faveMap[$laListingId] ?? [];
                $extraValues  = $this->parseMExtra((string) ($row['mExtra'] ?? ''), $faveFields);
                $extraParsed  = ($extraValues !== null);
                if (!$extraParsed && !empty($faveFields)) {
                    $extraFailures++;
                }

                // Build INSERT
                $cols = ['email', 'name', 'country', 'url', 'pending', 'password', 'showemail', 'showurl', 'added'];
                $vals = [
                    'email'     => $email,
                    'name'      => trim((string) ($row['mName'] ?? '')),
                    'country'   => trim((string) ($row['mCountry'] ?? '')),
                    'url'       => trim((string) ($row['mURL'] ?? '')),
                    'pending'   => (int) ($row['mPending'] ?? 0),
                    'password'  => (string) ($row['mPassword'] ?? ''),
                    'showemail' => (int) ($row['mVisible'] ?? 1),
                    'showurl'   => 1,
                    'added'     => !empty($row['mAdd']) ? $row['mAdd'] : date('Y-m-d'),
                ];

                // Append additional field values
                foreach ($faveFields as $i => $fieldName) {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldName)) {
                        continue;
                    }
                    $cols[] = $fieldName;
                    $vals[$fieldName] = $extraParsed ? ($extraValues[$i] ?? '') : '';
                }

                $placeholders = implode(', ', array_map(fn(string $c) => ':' . $c, $cols));
                $colList      = implode(', ', array_map(fn(string $c) => "`{$c}`", $cols));

                try {
                    $ins = $target->prepare(
                        "INSERT INTO `{$dbtable}` ({$colList}) VALUES ({$placeholders})
                         ON DUPLICATE KEY UPDATE
                         name = VALUES(name), country = VALUES(country), url = VALUES(url)"
                    );
                    $ins->execute($vals);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Member {$email} in {$dbtable}: " . $e->getMessage();
                }
            }

            $offset += self::CHUNK;
        } while (count($chunk) === self::CHUNK);

        if ($extraFailures > 0) {
            $warnings[] = "{$extraFailures} member(s) imported without custom field data (mExtra could not be parsed).";
        }

        return ['type' => 'Members', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => $warnings, 'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Affiliates
    // -----------------------------------------------------------------------

    /**
     * @return array{row: array, imageMap: array}
     */
    private function runAffiliates(
        PDO $source, PDO $target, ImportOptions $options, ?ImageMigrator $migrator,
        array $imageSettings, array $listingIdMap, string $tp = ''
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgSkipped   = 0;
        $imgErrors    = [];
        $imageMap     = [];

        // Load existing (fanlisting_id|url) pairs for conflict detection
        $existPairs = [];
        foreach ($target->query('SELECT fanlisting_id, url FROM lstr_affiliates')->fetchAll() as $r) {
            $existPairs[(($r['fanlisting_id'] ?? 'null') . '|' . $r['url'])] = true;
        }

        // Track sort_order per fanlisting_id key ('null' = collective)
        $sortOrders = [];
        foreach ($target->query('SELECT fanlisting_id, COALESCE(MAX(sort_order), 0) AS mx FROM lstr_affiliates GROUP BY fanlisting_id')->fetchAll() as $r) {
            $sortOrders[$r['fanlisting_id'] ?? 'null'] = (int) $r['mx'];
        }

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_affiliates (fanlisting_id, name, url, email, image, sort_order, active)
             VALUES (:fanlisting_id, :name, :url, :email, :image, :sort_order, 1)'
        );

        try {
            $stmt    = $source->query("SELECT * FROM `{$tp}affiliates` ORDER BY aID ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [
                'row' => ['type' => 'Affiliates', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                          'errors' => ['Could not read affiliates: ' . $e->getMessage()],
                          'image_copied' => 0, 'image_skipped' => 0, 'image_errors' => [],
                          'skipped_items' => []],
                'imageMap' => [],
            ];
        }

        foreach ($srcRows as $row) {
            $url  = trim((string) ($row['aURL'] ?? ''));
            $name = trim((string) ($row['aSubject'] ?? ''));
            if ($url === '') {
                continue;
            }

            // Resolve fNiq → array of Luster fanlisting IDs (null = collective)
            $flIds = $this->parseFNiq((string) ($row['fNiq'] ?? '0'), $listingIdMap);

            // Extract early so the retry branch can use it.
            $imgFilename   = trim((string) ($row['aImage'] ?? ''));
            $newImage      = null;
            $imageMigrated = false;

            foreach ($flIds as $flId) {
                $pairKey = ($flId === null ? 'null' : $flId) . '|' . $url;

                if (isset($existPairs[$pairKey])) {
                    if ($options->conflictStrategy === 'skip') {
                        // Re-run: retry image if existing record has image = NULL.
                        if ($migrator !== null && $imgFilename !== '' && $options->imageMode !== 'zip') {
                            $rSql = $flId === null
                                ? 'SELECT id, image FROM lstr_affiliates WHERE url = :url AND fanlisting_id IS NULL LIMIT 1'
                                : 'SELECT id, image FROM lstr_affiliates WHERE url = :url AND fanlisting_id = :fid LIMIT 1';
                            $rStmt = $target->prepare($rSql);
                            $rParams = ['url' => $url];
                            if ($flId !== null) { $rParams['fid'] = $flId; }
                            $rStmt->execute($rParams);
                            $rRec = $rStmt->fetch(\PDO::FETCH_ASSOC);
                            if ($rRec !== false && $rRec['image'] === null) {
                                if (!$imageMigrated) {
                                    $dummyMap    = [];
                                    $newImage     = $this->migrateImage(
                                        $migrator, $imgFilename, 'affiliates', 'af_',
                                        $options, $imageSettings, $dummyMap, $imgCopied, $imgSkipped, $imgErrors
                                    );
                                    $imageMigrated = true;
                                }
                                if ($newImage !== null) {
                                    $target->prepare('UPDATE lstr_affiliates SET image = :img WHERE id = :id')
                                           ->execute(['img' => $newImage, 'id' => $rRec['id']]);
                                }
                            }
                        }
                        $skipped++;
                        if (count($skippedItems) < self::SKIP_CAP) {
                            $skippedItems[] = ['label' => $name . ' — ' . $url, 'reason' => 'Already exists (same URL)'];
                        }
                        continue;
                    }
                    try {
                        $whereClause = $flId === null
                            ? 'url = :url AND fanlisting_id IS NULL'
                            : 'url = :url AND fanlisting_id = :fid';
                        $upd    = $target->prepare(
                            "UPDATE lstr_affiliates SET name = :name, email = :email WHERE {$whereClause}"
                        );
                        $params = ['name' => $name, 'email' => trim((string) ($row['aEmail'] ?? '')), 'url' => $url];
                        if ($flId !== null) {
                            $params['fid'] = $flId;
                        }
                        $upd->execute($params);
                        $skipped++;
                    } catch (\Throwable $e) {
                        $errors[] = "Affiliate '{$url}': " . $e->getMessage();
                    }
                    continue;
                }

                // Migrate image once; reuse new filename for subsequent per-listing rows
                if (!$imageMigrated && $imgFilename !== '' && $migrator !== null) {
                    $newImage      = $this->migrateImage(
                        $migrator, $imgFilename, 'affiliates', 'af_', $options, $imageSettings,
                        $imageMap, $imgCopied, $imgSkipped, $imgErrors
                    );
                    $imageMigrated = true;
                }

                $soKey = $flId === null ? 'null' : (string) $flId;
                $sortOrders[$soKey] = ($sortOrders[$soKey] ?? 0) + 1;

                try {
                    $insertStmt->execute([
                        'fanlisting_id' => $flId,
                        'name'          => $name,
                        'url'           => $url,
                        'email'         => trim((string) ($row['aEmail'] ?? '')),
                        'image'         => $newImage,
                        'sort_order'    => $sortOrders[$soKey],
                    ]);
                    $existPairs[$pairKey] = true;
                    $imported++;

                    // ZIP map: store first inserted record_id for this image filename
                    if ($imgFilename !== '' && $newImage === null
                        && $options->imageMode === 'zip'
                        && !isset($imageMap[$imgFilename])
                    ) {
                        $imageMap[$imgFilename] = [
                            'type'      => 'affiliates',
                            'record_id' => (int) $target->lastInsertId(),
                            'prefix'    => 'af_',
                        ];
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Affiliate '{$url}': " . $e->getMessage();
                }
            }
        }

        return [
            'row' => ['type' => 'Affiliates', 'imported' => $imported, 'skipped' => $skipped,
                      'warnings' => [], 'errors' => $errors,
                      'image_copied' => $imgCopied, 'image_skipped' => $imgSkipped, 'image_errors' => $imgErrors,
                      'skipped_items' => $skippedItems],
            'imageMap' => $imageMap,
        ];
    }

    /**
     * Parse LA's fNiq field into an array of Luster fanlisting IDs (null = collective).
     * Formats: '0' (collective), '1' (single listing), '!1!!2!' (multi-listing)
     *
     * @param  array<int,int> $listingIdMap  LA main.id → Luster owned.listingid
     * @return array<int|null>
     */
    private function parseFNiq(string $fNiq, array $listingIdMap): array
    {
        $fNiq = trim($fNiq);

        // Multi-listing format: !1!!2!!3!
        if (str_starts_with($fNiq, '!')) {
            preg_match_all('/!(\d+)!/', $fNiq, $matches);
            $ids = [];
            foreach ($matches[1] as $raw) {
                $laId = (int) $raw;
                if ($laId === 0) {
                    $ids[] = null;
                } elseif (isset($listingIdMap[$laId])) {
                    $ids[] = $listingIdMap[$laId];
                }
                // unmatched listing → skip (not imported)
            }
            return $ids !== [] ? $ids : [null];
        }

        $laId = (int) $fNiq;
        if ($laId === 0) {
            return [null];
        }
        // Known listing → per-listing; unknown (not imported) → collective fallback
        return [isset($listingIdMap[$laId]) ? $listingIdMap[$laId] : null];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Joined listings
    // -----------------------------------------------------------------------

    /**
     * @return array{row: array, imageMap: array}
     */
    private function runJoined(
        PDO $source, PDO $target, ImportOptions $options,
        array $catNameToId, array $srcCatIdToName, ?ImageMigrator $migrator,
        array $imageSettings, string $tp = ''
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgSkipped   = 0;
        $imgErrors    = [];
        $imageMap     = [];

        $existStmt = $target->prepare('SELECT url FROM lstr_joined');
        $existStmt->execute();
        $existingUrls = array_flip($existStmt->fetchAll(PDO::FETCH_COLUMN));

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_joined (name, url, image, category, categories_json, category_id, date_joined, active, status, sort_order)
             VALUES (:name, :url, :image, :category, :categories_json, :category_id, :date_joined, :active, :status, 0)'
        );

        try {
            $stmt    = $source->query("SELECT * FROM `{$tp}joined` ORDER BY jID ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [
                'row' => ['type' => 'Joined listings', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                          'errors' => ['Could not read joined listings: ' . $e->getMessage()],
                          'image_copied' => 0, 'image_skipped' => 0, 'image_errors' => [],
                          'skipped_items' => []],
                'imageMap' => [],
            ];
        }

        foreach ($srcRows as $row) {
            $url  = trim((string) ($row['jURL'] ?? ''));
            $name = trim((string) ($row['jSubject'] ?? ''));
            if ($url === '') {
                continue;
            }

            // Extract early for the retry branch.
            $imgFilename = trim((string) ($row['jImage'] ?? ''));

            if (isset($existingUrls[$url])) {
                if ($options->conflictStrategy === 'skip') {
                    // Re-run: retry image if existing record has image = NULL.
                    if ($migrator !== null && $imgFilename !== '' && $options->imageMode !== 'zip') {
                        $rStmt = $target->prepare(
                            'SELECT id, image FROM lstr_joined WHERE url = :url LIMIT 1'
                        );
                        $rStmt->execute(['url' => $url]);
                        $rRec = $rStmt->fetch(\PDO::FETCH_ASSOC);
                        if ($rRec !== false && $rRec['image'] === null) {
                            $dummyMap = [];
                            $retried  = $this->migrateImage(
                                $migrator, $imgFilename, 'joined', 'jn_',
                                $options, $imageSettings, $dummyMap, $imgCopied, $imgSkipped, $imgErrors
                            );
                            if ($retried !== null) {
                                $target->prepare('UPDATE lstr_joined SET image = :img WHERE id = :id')
                                       ->execute(['img' => $retried, 'id' => $rRec['id']]);
                            }
                        }
                    }
                    $skipped++;
                    if (count($skippedItems) < self::SKIP_CAP) {
                        $skippedItems[] = ['label' => $name . ' — ' . $url, 'reason' => 'Already exists (same URL)'];
                    }
                    continue;
                }
                try {
                    $upd = $target->prepare('UPDATE lstr_joined SET name = :name WHERE url = :url');
                    $upd->execute(['name' => $name, 'url' => $url]);
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Joined '{$url}': " . $e->getMessage();
                }
                continue;
            }

            // Resolve all categories — handles pipe-delimited IDs (|12|) and plain names.
            $jCatRaw    = trim((string) ($row['jCategory'] ?? ''));
            $parsedIds  = self::parseLegacyCategoryIds($jCatRaw);
            $catNames   = [];
            if (!empty($parsedIds) && !empty($srcCatIdToName)) {
                foreach ($parsedIds as $srcId) {
                    $n = $srcCatIdToName[$srcId] ?? '';
                    if ($n !== '') {
                        $catNames[] = $n;
                    }
                }
            } elseif ($jCatRaw !== '' && empty($parsedIds)) {
                $catNames = [$jCatRaw]; // plain text name
            }
            $firstCatName   = $catNames[0] ?? '';
            $newCatId       = ($firstCatName !== '' && isset($catNameToId[$firstCatName])) ? $catNameToId[$firstCatName] : null;
            $catJsonEntries = array_map(static fn(string $n) => ['network' => 'Imported', 'name' => $n], $catNames);
            $categoriesJson = !empty($catJsonEntries) ? json_encode($catJsonEntries) : null;
            $active         = ((int) ($row['jStatus'] ?? 1)) === 1 ? 1 : 0;

            // Image migration for new records
            $newImage = null;
            if ($imgFilename !== '' && $migrator !== null) {
                $newImage = $this->migrateImage(
                    $migrator, $imgFilename, 'joined', 'jn_', $options, $imageSettings,
                    $imageMap, $imgCopied, $imgSkipped, $imgErrors
                );
            }

            try {
                $insertStmt->execute([
                    'name'            => $name,
                    'url'             => $url,
                    'image'           => $newImage,
                    'category'        => $firstCatName,
                    'categories_json' => $categoriesJson,
                    'category_id'     => $newCatId,
                    'date_joined'     => !empty($row['jAdd']) ? $row['jAdd'] : null,
                    'active'          => $active,
                    'status'          => $active === 1 ? 'approved' : 'pending',
                ]);
                $existingUrls[$url] = true;
                $imported++;

                if ($imgFilename !== '' && $newImage === null && $options->imageMode === 'zip') {
                    $imageMap[$imgFilename] = [
                        'type'      => 'joined',
                        'record_id' => (int) $target->lastInsertId(),
                        'prefix'    => 'jn_',
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = "Joined '{$url}': " . $e->getMessage();
            }
        }

        return [
            'row' => ['type' => 'Joined listings', 'imported' => $imported, 'skipped' => $skipped,
                      'warnings' => [], 'errors' => $errors,
                      'image_copied' => $imgCopied, 'image_skipped' => $imgSkipped, 'image_errors' => $imgErrors,
                      'skipped_items' => $skippedItems],
            'imageMap' => $imageMap,
        ];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Updates
    // -----------------------------------------------------------------------

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],skipped_items:array<array{label:string,reason:string}>} */
    private function runUpdates(PDO $source, PDO $target, ImportOptions $options, array $listingIdMap, string $tp = ''): array
    {
        if (!$this->updatesAddonInstalled($target)) {
            return ['type' => 'Updates', 'imported' => 0, 'skipped' => 0,
                    'warnings' => ['Updates Feed add-on is not installed — skipped.'], 'errors' => [],
                    'skipped_items' => []];
        }

        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_updates (fanlisting_id, title, body, category, author, posted_at)
             VALUES (:fanlisting_id, :title, :body, :category, :author, :posted_at)'
        );

        $offset = 0;
        do {
            try {
                $stmt = $source->prepare(
                    "SELECT uID, fNiq, uTitle, uEntry, uAdded FROM `{$tp}updates`
                     ORDER BY uID ASC LIMIT :lim OFFSET :off"
                );
                $stmt->bindValue(':lim', self::CHUNK, PDO::PARAM_INT);
                $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $chunk = $stmt->fetchAll();
            } catch (\Throwable $e) {
                $errors[] = 'Could not read updates chunk: ' . $e->getMessage();
                break;
            }

            foreach ($chunk as $row) {
                $laListingId  = (int) ($row['fNiq'] ?? 0);
                $newListingId = $laListingId > 0 ? ($listingIdMap[$laListingId] ?? null) : null;

                $postedAt = !empty($row['uAdded']) ? $row['uAdded'] : date('Y-m-d H:i:s');

                try {
                    $insertStmt->execute([
                        'fanlisting_id' => $newListingId,
                        'title'         => trim((string) ($row['uTitle'] ?? '')),
                        'body'          => trim((string) ($row['uEntry'] ?? '')),
                        'category'      => '',
                        'author'        => '',
                        'posted_at'     => $postedAt,
                    ]);
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "Update row #{$row['uID']}: " . $e->getMessage();
                    $skipped++;
                }
            }

            $offset += self::CHUNK;
        } while (count($chunk) === self::CHUNK);

        return ['type' => 'Updates', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => ['LA crossposting fields (uDW, uIJ, uLJ) were dropped.'], 'errors' => $errors,
                'skipped_items' => $skippedItems];
    }

    // -----------------------------------------------------------------------
    // DDL helpers
    // -----------------------------------------------------------------------

    /**
     * Creates a per-listing member table compatible with Enthusiast's schema.
     * The CREATE TABLE is idempotent (IF NOT EXISTS).
     *
     * @param string[] $additionalFields  Validated bare field names for extra columns
     */
    private function createMemberTable(PDO $target, string $dbtable, array $additionalFields): void
    {
        $extraCols = '';
        foreach ($additionalFields as $field) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
                continue;
            }
            $extraCols .= "  `{$field}` TEXT NOT NULL DEFAULT '',\n";
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
              `email`     VARCHAR(64)  NOT NULL,
              `name`      VARCHAR(128) NOT NULL DEFAULT '',
              `country`   VARCHAR(128) NOT NULL DEFAULT '',
              `url`       VARCHAR(255) NOT NULL DEFAULT '',
              `pending`   TINYINT(1)   NOT NULL DEFAULT 0,
              `password`  VARCHAR(255) NOT NULL DEFAULT '',
              `showemail` TINYINT(1)   NOT NULL DEFAULT 1,
              `showurl`   TINYINT(1)   NOT NULL DEFAULT 1,
              `added`     DATE         NULL,
              {$extraCols}
              PRIMARY KEY (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * Inserts a row into the target listing registry and returns the new ID.
     */
    private function insertListing(
        PDO $target, string $regTable, string $idCol,
        string $title, string $subject, string $url, string $dbtable,
        ?int $catId, ?string $opened, bool $isOwned, int $status, ?string $image
    ): int {
        if ($isOwned) {
            $stmt = $target->prepare(
                "INSERT INTO `{$regTable}`
                 (title, subject, url, dbtable, catid, category_id, opened, status, imagefile,
                  email, dbserver, dbuser, dbpassword, dbdatabase, additional)
                 VALUES (:title, :subject, :url, :dbtable, '', :catid, :opened, :status, :image,
                         '', '', '', '', '', '')"
            );
            $stmt->execute([
                'title'   => $title,
                'subject' => $subject,
                'url'     => $url,
                'dbtable' => $dbtable,
                'catid'   => $catId,
                'opened'  => $opened,
                'status'  => $status,
                'image'   => $image,
            ]);
        } else {
            // enth_fanlistings schema
            $stmt = $target->prepare(
                "INSERT INTO `{$regTable}`
                 (name, subject, url, dbprefix, category, category_id, opened, upcoming, trouble,
                  imagefile, email, dbserver, dbuser, dbpassword, dbdatabase, additional_info)
                 VALUES (:name, :subject, :url, :dbprefix, :catid, :catid2, :opened, 0, 0,
                         :image, '', '', '', '', '', '')"
            );
            $stmt->execute([
                'name'    => $title,
                'subject' => $subject,
                'url'     => $url,
                'dbprefix' => $dbtable . '_',
                'catid'   => $catId ?? 0,
                'catid2'  => $catId,
                'opened'  => $opened,
                'image'   => $image,
            ]);
        }

        return (int) $target->lastInsertId();
    }

    /**
     * Updates an existing listing row (overwrite strategy).
     */
    private function updateListing(
        PDO $target, string $regTable, string $idCol, int $listingId,
        string $title, string $subject, string $url, string $dbtable,
        ?int $catId, ?string $opened, bool $isOwned, int $status, ?string $image
    ): void {
        if ($isOwned) {
            $stmt = $target->prepare(
                "UPDATE `{$regTable}`
                 SET title = :title, subject = :subject, dbtable = :dbtable,
                     catid = COALESCE(:catid, catid), opened = COALESCE(:opened, opened),
                     status = :status, imagefile = COALESCE(:image, imagefile)
                 WHERE {$idCol} = :id"
            );
            $stmt->execute([
                'title'   => $title,
                'subject' => $subject,
                'dbtable' => $dbtable,
                'catid'   => $catId,
                'opened'  => $opened,
                'status'  => $status,
                'image'   => $image,
                'id'      => $listingId,
            ]);
        } else {
            $stmt = $target->prepare(
                "UPDATE `{$regTable}`
                 SET name = :name, subject = :subject, dbprefix = :dbprefix,
                     category = COALESCE(:catid, category), opened = COALESCE(:opened, opened),
                     imagefile = COALESCE(:image, imagefile)
                 WHERE {$idCol} = :id"
            );
            $stmt->execute([
                'name'    => $title,
                'subject' => $subject,
                'dbprefix' => $dbtable . '_',
                'catid'   => $catId,
                'opened'  => $opened,
                'image'   => $image,
                'id'      => $listingId,
            ]);
        }
    }

    /**
     * Creates or skips the lstr_fanlisting_settings row for a newly imported listing.
     */
    private function ensureListingSettings(PDO $target, int $listingId, array $mainRow): void
    {
        $chk = $target->prepare(
            'SELECT fanlisting_id FROM lstr_fanlisting_settings WHERE fanlisting_id = :fid'
        );
        $chk->execute(['fid' => $listingId]);
        if ($chk->fetchColumn() !== false) {
            return; // already exists
        }

        $perPage    = max(1, min(200, (int) ($mainRow['perpage'] ?? 25)));
        $membersTpl  = trim((string) ($mainRow['members_header'] ?? ''));
        $affsTpl     = '';
        $statsTpl    = '';

        $ins = $target->prepare(
            'INSERT INTO lstr_fanlisting_settings
               (fanlisting_id, members_per_page, members_template, affiliates_template, stats_template)
             VALUES (:fid, :perpage, :mtpl, :atpl, :stpl)'
        );
        $ins->execute([
            'fid'    => $listingId,
            'perpage' => $perPage,
            'mtpl'   => $membersTpl ?: null,
            'atpl'   => $affsTpl    ?: null,
            'stpl'   => $statsTpl   ?: null,
        ]);
    }

    // -----------------------------------------------------------------------
    // Shared utilities
    // -----------------------------------------------------------------------

    /**
     * Detects Luster's own target schema (owned vs enth_fanlistings).
     */
    private function detectTargetSchema(PDO $target): string
    {
        $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $target->query("SELECT name FROM sqlite_master WHERE type='table' AND name='owned'");
            if ($stmt->fetchColumn() !== false) {
                return 'enthusiast_unprefixed';
            }
            return 'enthusiast_enth';
        }

        $stmt = $target->query("SHOW TABLES LIKE 'owned'");
        if ($stmt && $stmt->fetchColumn() !== false) {
            return 'enthusiast_unprefixed';
        }
        return 'enthusiast_enth';
    }

    /**
     * Returns [registryTable, pkColumn, isOwnedSchema] for the target.
     *
     * @return array{0:string, 1:string, 2:bool}
     */
    private function targetRegistryInfo(string $targetSchema): array
    {
        $isOwned = ($targetSchema === 'enthusiast_unprefixed');
        $table   = $isOwned ? 'owned' : 'enth_fanlistings';
        $pkCol   = $isOwned ? 'listingid' : 'id';
        return [$table, $pkCol, $isOwned];
    }

    /**
     * Loads all existing dbtable / dbprefix values from the target registry.
     *
     * @return array<string,bool>  table name → true
     */
    private function loadExistingDbtables(PDO $target, string $regTable, bool $isOwned): array
    {
        $col  = $isOwned ? 'dbtable' : 'dbprefix';
        $stmt = $target->query("SELECT `{$col}` FROM `{$regTable}` WHERE `{$col}` != ''");
        $map  = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
            // For enth_ targets, dbprefix is stored with trailing underscore (e.g. "music_").
            // Strip it so generateDbtable() collision detection works against the bare slug.
            $key = $isOwned ? (string) $v : rtrim((string) $v, '_');
            $map[$key] = true;
        }
        return $map;
    }

    /**
     * Builds a map of new Luster listing id → dbtable name.
     *
     * @param int[] $listingIds
     * @return array<int,string>
     */
    private function buildDbtableMap(PDO $target, string $regTable, string $pkCol, array $listingIds): array
    {
        if (empty($listingIds)) {
            return [];
        }

        $col = ($regTable === 'owned') ? 'dbtable' : 'dbprefix';
        $ph  = implode(',', array_fill(0, count($listingIds), '?'));
        $stmt = $target->prepare(
            "SELECT `{$pkCol}`, `{$col}` FROM `{$regTable}` WHERE `{$pkCol}` IN ({$ph})"
        );
        $stmt->execute(array_values($listingIds));

        $isOwned = ($regTable === 'owned');
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $raw   = (string) $row[$col];
            // For enth_ targets, dbprefix (e.g. "music_") + "members" = physical table name.
            $map[(int) $row[$pkCol]] = $isOwned ? $raw : $raw . 'members';
        }
        return $map;
    }

    /**
     * Builds a category name → Luster id map from the target DB.
     *
     * @return array<string,int>
     */
    private function buildCategoryNameMap(PDO $target): array
    {
        $stmt = $target->prepare('SELECT id, name FROM lstr_categories');
        $stmt->execute();
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['name']] = (int) $row['id'];
        }
        return $map;
    }

    /**
     * Generates a unique, validated dbtable slug from a subject string.
     *
     * @param array<string,bool> $existing  Already-used table names
     */
    private function generateDbtable(string $subject, array $existing): string
    {
        // Normalize: lowercase, replace non-alphanumeric runs with underscore, trim
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $subject));
        $base = trim($base, '_');

        if ($base === '') {
            $base = 'listing';
        }

        // Truncate to 50 chars to leave room for collision suffix
        if (strlen($base) > 50) {
            $base = substr($base, 0, 50);
        }

        $candidate = $base;
        $i = 2;
        while (isset($existing[$candidate])) {
            $candidate = $base . '_' . $i;
            $i++;
        }

        return $candidate;
    }

    /**
     * Parses Listing Admin's `fave_fields` column into an array of field names.
     * LA stores field names as comma-separated or pipe-separated strings.
     *
     * @return string[]
     */
    private function parseFaveFields(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        // Try comma-separated first, then pipe-separated
        $delimiter = str_contains($raw, ',') ? ',' : '|';
        $fields    = array_filter(
            array_map('trim', explode($delimiter, $raw)),
            fn(string $f) => $f !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $f)
        );

        return array_values($fields);
    }

    /**
     * Parses Listing Admin's `mExtra` text blob into positional values.
     * Returns null if parsing cannot be reliably performed.
     *
     * @param  string[] $faveFields  Field names from parseFaveFields()
     * @return string[]|null
     */
    private function parseMExtra(string $raw, array $faveFields): ?array
    {
        if (empty($faveFields)) {
            return [];
        }

        if ($raw === '') {
            return array_fill(0, count($faveFields), '');
        }

        // LA typically delimiters values with '||' (double pipe)
        if (str_contains($raw, '||')) {
            $parts = explode('||', $raw);
        } elseif (str_contains($raw, '|')) {
            $parts = explode('|', $raw);
        } elseif (str_contains($raw, "\n")) {
            $parts = explode("\n", $raw);
        } else {
            // Cannot determine delimiter — return null to signal failure
            return null;
        }

        $parts = array_map('trim', $parts);

        // If part count doesn't match field count, still return what we have
        // (positional — extra values are ignored, missing ones default to '')
        $result = [];
        foreach ($faveFields as $i => $field) {
            $result[$i] = $parts[$i] ?? '';
        }

        return $result;
    }

    /**
     * Migrates a single image file using the configured mode.
     *
     * Path/URL resolution priority for Listing Admin:
     *   1. Use the path/URL read directly from the la_options table
     *      ($imageSettings['affiliates_dir'], 'listings_dir', 'joined_dir',
     *       'affiliates_url', 'listings_url', 'joined_url')
     *   2. Fall back to the user-supplied path/URL as the direct image directory
     *      (no subdirectory appended — LA has no fixed structure, so if the
     *      options table had no configured path, the user must provide it directly)
     *
     * @param array<string,string> $imageSettings  From readImageSettings(); may be empty.
     */
    private function migrateImage(
        ImageMigrator $migrator, string $imageFilename, string $targetSubDir, string $prefix,
        ImportOptions $options, array $imageSettings, array &$imageMap, int &$copied,
        int &$skipped, array &$errors
    ): ?string {
        if ($options->imageMode === 'path') {
            $settingsKey   = match ($targetSubDir) {
                'affiliates' => 'affiliates_dir',
                'joined'     => 'joined_dir',
                default      => 'listings_dir',
            };
            $configuredDir = $imageSettings[$settingsKey] ?? '';

            // Priority 1: path from la_options table (complete, authoritative)
            // Priority 2: user-provided path used directly (no subdir — LA has no fixed structure)
            if ($configuredDir !== '') {
                $srcPath = $configuredDir . '/' . $imageFilename;
            } elseif ($options->imagePath !== '') {
                $srcPath = rtrim($options->imagePath, '/\\') . '/' . $imageFilename;
            } else {
                $skipped++;
                return null;
            }

            $newFilename = $migrator->copyFromPath($srcPath, $targetSubDir, $prefix);
            if ($newFilename !== null) {
                $copied++;
            } else {
                $skipped++;
                $errors[] = "Image not found: {$imageFilename}";
            }
            return $newFilename;
        }

        if ($options->imageMode === 'url') {
            $settingsKey   = match ($targetSubDir) {
                'affiliates' => 'affiliates_url',
                'joined'     => 'joined_url',
                default      => 'listings_url',
            };
            $configuredUrl = $imageSettings[$settingsKey] ?? '';

            if ($configuredUrl !== '') {
                $srcUrl = $configuredUrl . '/' . rawurlencode($imageFilename);
            } elseif ($options->imageUrl !== '') {
                $srcUrl = rtrim($options->imageUrl, '/') . '/' . rawurlencode($imageFilename);
            } else {
                $skipped++;
                return null;
            }

            $newFilename = $migrator->fetchFromUrl($srcUrl, $targetSubDir, $prefix);
            if ($newFilename !== null) {
                $copied++;
            } else {
                $skipped++;
                $errors[] = "Could not fetch: {$imageFilename}";
            }
            return $newFilename;
        }

        if ($options->imageMode === 'zip') {
            if ($this->activeZip !== null) {
                $newFilename = $migrator->copyFromZip($this->activeZip, $imageFilename, $targetSubDir, $prefix);
                if ($newFilename !== null) {
                    $copied++;
                } else {
                    $skipped++;
                    $errors[] = "Image not found in ZIP: {$imageFilename}";
                }
                return $newFilename;
            }
        }

        $skipped++;
        return null;
    }

    /**
     * Parses legacy category ID strings used by Enthusiast and Listing Admin.
     *
     * Handled formats:
     *   '12'       → [12]
     *   '|12|'     → [12]
     *   '|12|34|'  → [12, 34]
     *   '!12!'     → [12]
     *   '!12!!34!' → [12, 34]
     *
     * Returns empty array for blank input or non-numeric strings (plain names).
     *
     * @return int[]
     */
    private static function parseLegacyCategoryIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        if (str_starts_with($raw, '|')) {
            preg_match_all('/\|(\d+)(?=\|)/', $raw, $m);
            return array_map('intval', $m[1]);
        }
        if (str_starts_with($raw, '!')) {
            preg_match_all('/!(\d+)!/', $raw, $m);
            return array_map('intval', $m[1]);
        }
        if (ctype_digit($raw)) {
            return [(int) $raw];
        }
        return [];
    }

    /**
     * Loads a source catid → catname map from the LA categories table.
     * Returns [] if the table is absent or unreadable.
     *
     * @return array<int,string>
     */
    private function buildSourceCategoryMap(PDO $source, string $tp): array
    {
        try {
            $stmt = $source->query("SELECT catid, catname FROM `{$tp}categories`");
            $map  = [];
            foreach ($stmt->fetchAll() as $r) {
                $map[(int) $r['catid']] = trim((string) $r['catname']);
            }
            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Reads configured image directory paths and web URLs from the Listing Admin
     * options table (la_options or {prefix}options).
     *
     * Returns an associative array with keys:
     *   affiliates_dir, joined_dir, listings_dir  (absolute filesystem paths)
     *   affiliates_url, joined_url, listings_url  (web-accessible URLs)
     *
     * All values are rtrim'd. Returns [] on any failure.
     *
     * @param  string $laPrefix  The LA table prefix (e.g. 'la_', 'trex_', or '')
     * @return array<string,string>
     */
    private function readImageSettings(PDO $source, string $laPrefix): array
    {
        // The options table follows the same prefix as other LA tables.
        // e.g. '' → 'options', 'la_' → 'la_options', 'trex_' → 'trex_options'
        // LA itself derives its table names this way.
        $table = $laPrefix !== '' ? $laPrefix . 'options' : 'options';

        try {
            $stmt = $source->prepare(
                "SELECT `name`, `text` FROM `{$table}`
                  WHERE `name` IN (
                      'aff_path',  'aff_http',
                      'img_path',  'img_http',
                      'jnd_path',  'jnd_http'
                  )"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {
            return [];
        }

        return [
            'affiliates_dir' => rtrim((string) ($rows['aff_path'] ?? ''), '/\\'),
            'affiliates_url' => rtrim((string) ($rows['aff_http'] ?? ''), '/'),
            'listings_dir'   => rtrim((string) ($rows['img_path'] ?? ''), '/\\'),
            'listings_url'   => rtrim((string) ($rows['img_http'] ?? ''), '/'),
            'joined_dir'     => rtrim((string) ($rows['jnd_path'] ?? ''), '/\\'),
            'joined_url'     => rtrim((string) ($rows['jnd_http'] ?? ''), '/'),
        ];
    }
}
