<?php

declare(strict_types=1);

namespace Luster\Import;

use PDO;
use RuntimeException;

/**
 * Import adapter for Enthusiast databases.
 *
 * Supports two schema variants, auto-detected from the live connection:
 *
 *   'enthusiast_unprefixed'
 *     Registry table: `owned`
 *     Additional field columns in member tables: bare name (e.g. `favorite`)
 *     Categories/affiliates/joined: no prefix (e.g. `category`, `affiliates`)
 *
 *   'enthusiast_enth'
 *     Registry table: `enth_fanlistings`
 *     Additional field columns: `additional_{name}` (e.g. `additional_fave_song`)
 *     Categories/affiliates/joined: `enth_category`, `enth_affiliates`, `enth_joined`
 *
 * What this adapter imports into Luster's lstr_* tables:
 *   - lstr_categories          ← categories / enth_category
 *   - lstr_affiliates          ← affiliates / enth_affiliates
 *   - lstr_joined              ← joined / enth_joined
 *   - lstr_fanlisting_settings ← owned / enth_fanlistings (per-listing settings rows)
 *   - lstr_email_templates     ← emailsignup/emailapproved/emailupdate/emaillostpass columns
 *
 * Member data: Luster already reads Enthusiast's per-listing member tables natively.
 * If the source DB is the same as Luster's DB, no member migration is needed.
 * If different, the user is instructed to copy the tables manually (out of scope).
 *
 * Image migration is delegated to ImageMigrator.
 */
final class EnthusiastImportSource implements ImportSourceInterface
{
    private const CHUNK    = 500;
    private const SKIP_CAP = 500;

    // Default image subdirectory names relative to the Enthusiast install root.
    // These are the Enthusiast defaults seeded by its install script:
    //   affiliates_dir  → {install_root}/affiliates/
    //   joined_images_dir → {install_root}/joined/
    //   owned_images_dir  → {install_root}/owned/
    // Used only as a fallback when the settings table cannot be queried.
    private const IMG_SUBDIRS = [
        'affiliates' => 'affiliates',
        'joined'     => 'joined',
        'listings'   => 'owned',
    ];

    // -----------------------------------------------------------------------
    // ImportSourceInterface
    // -----------------------------------------------------------------------

    public function getName(): string
    {
        return 'Enthusiast';
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

        $pdo = new PDO($dsn, $credentials['user'], $credentials['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    }

    public function detectSchema(PDO $source): string
    {
        $driver = $source->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            // SQLite (used in tests): query sqlite_master instead of SHOW TABLES.
            $stmt = $source->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
            $stmt->execute(['enth_fanlistings']);
            if ($stmt->fetchColumn() !== false) {
                return 'enthusiast_enth';
            }
            $stmt->execute(['category']);
            if ($stmt->fetchColumn() !== false) {
                return 'enthusiast_unprefixed';
            }
            return 'unknown';
        }

        // MySQL / MariaDB path.
        // Check for `enth_fanlistings` first (standard Enthusiast / enth_ prefix forks).
        $stmt = $source->query("SHOW TABLES LIKE 'enth_fanlistings'");
        if ($stmt && $stmt->fetchColumn() !== false) {
            return 'enthusiast_enth';
        }

        // Unprefixed Enthusiast: the definitive marker is the `category` table.
        // Luster never creates a `category` table (it uses `lstr_categories`), so
        // this distinguishes a real Enthusiast install from Luster's own `owned` table
        // when both share the same database.
        $stmt = $source->query("SHOW TABLES LIKE 'category'");
        if ($stmt && $stmt->fetchColumn() !== false) {
            return 'enthusiast_unprefixed';
        }

        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // Preview (read-only)
    // -----------------------------------------------------------------------

    public function preview(PDO $source, ImportOptions $options): ImportPreview
    {
        $schema     = $options->schemaVariant !== '' ? $options->schemaVariant : $this->detectSchema($source);
        $tp         = $options->tablePrefix;
        $sameDb     = false; // determined by caller; passed through in run()
        $rows       = [];

        if ($options->importCategories) {
            $rows[] = $this->previewCategories($source, $schema, $options);
        }
        if ($options->importAffiliates) {
            $rows[] = $this->previewAffiliates($source, $schema, $options);
        }
        if ($options->importJoined) {
            $rows[] = $this->previewJoined($source, $schema, $options);
        }
        if ($options->importOwnedListings) {
            $rows[] = $this->previewOwnedListings($source, $schema, $options);
        }
        if ($options->importMembers) {
            $rows[] = $this->previewMembers($source, $schema, $options);
        }
        if ($options->importSettings) {
            $rows[] = $this->previewSettings($source, $schema, $options);
        }
        if ($options->importTemplates) {
            $rows[] = $this->previewTemplates($source, $schema, $options);
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

        $start    = microtime(true);
        $schema   = $options->schemaVariant !== '' ? $options->schemaVariant : $this->detectSchema($source);
        $tp       = $options->tablePrefix;
        $rows     = [];
        $imageRows = [];

        // Determine if source and target are the same physical database.
        // We compare DSN strings extracted from PDO attributes.
        $sameDb = $this->isSameDatabase($source, $target);

        // Build image migrator if needed
        $migrator = $options->imageMode !== ''
            ? new ImageMigrator(dirname(__DIR__, 2) . '/public')
            : null;

        // Read image directory/URL configuration from the Enthusiast settings table.
        // This gives us the actual configured paths rather than hardcoded guesses.
        // Returns [] if the settings table is absent or unreadable (SQL dump mode,
        // permissions issue, etc.) — sub-methods fall back to user-provided root.
        $imageSettings = $migrator !== null
            ? $this->readImageSettings($source, $schema, $tp)
            : [];

        // Session image map: original filename => ['type' => subdir, 'record_id' => int]
        // Written for Mode 3 (ZIP) post-import step.
        $imageMap = [];

        if ($options->importCategories) {
            $rows[] = $this->runCategories($source, $target, $schema, $options);
        }

        // Build category name→new-id map for joined listings
        $catNameToId = $this->buildCategoryNameMap($target);

        if ($options->importAffiliates) {
            ['row' => $affRow, 'imageMap' => $affMap] = $this->runAffiliates(
                $source, $target, $schema, $options, $migrator, $imageSettings
            );
            $rows[]   = $affRow;
            $imageMap = array_merge($imageMap, $affMap);
            if ($affRow['image_skipped'] > 0 || $affRow['image_copied'] > 0) {
                $imageRows[] = [
                    'type'    => 'Images (affiliates)',
                    'copied'  => $affRow['image_copied'],
                    'skipped' => $affRow['image_skipped'],
                    'errors'  => $affRow['image_errors'],
                ];
            }
        }

        if ($options->importJoined) {
            ['row' => $joinRow, 'imageMap' => $joinMap] = $this->runJoined(
                $source, $target, $schema, $options, $catNameToId, $migrator, $imageSettings
            );
            $rows[]   = $joinRow;
            $imageMap = array_merge($imageMap, $joinMap);
            if ($joinRow['image_skipped'] > 0 || $joinRow['image_copied'] > 0) {
                $imageRows[] = [
                    'type'    => 'Images (joined listings)',
                    'copied'  => $joinRow['image_copied'],
                    'skipped' => $joinRow['image_skipped'],
                    'errors'  => $joinRow['image_errors'],
                ];
            }
        }

        if ($options->importOwnedListings) {
            $rows[] = $this->runOwnedListings($source, $target, $schema, $options, $sameDb);
        }

        if ($options->importMembers) {
            $rows[] = $this->runMembers($source, $target, $schema, $options, $sameDb);
        }

        if ($options->importSettings) {
            ['row' => $settRow, 'imageMap' => $settMap] = $this->runSettings(
                $source, $target, $schema, $options, $migrator, $imageSettings
            );
            $rows[]   = $settRow;
            $imageMap = array_merge($imageMap, $settMap);
            if ($settRow['image_skipped'] > 0 || $settRow['image_copied'] > 0) {
                $imageRows[] = [
                    'type'    => 'Images (listings)',
                    'copied'  => $settRow['image_copied'],
                    'skipped' => $settRow['image_skipped'],
                    'errors'  => $settRow['image_errors'],
                ];
            }
        }

        if ($options->importTemplates) {
            $rows[] = $this->runTemplates($source, $target, $schema, $options);
        }

        // Store image map in session for ZIP mode
        if ($options->imageMode === 'zip' && !empty($imageMap)) {
            $_SESSION['lstr_import_image_map'] = $imageMap;
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
    private function previewCategories(PDO $source, string $schema, ImportOptions $options): array
    {
        $tp       = $options->tablePrefix;
        $table    = $tp . ($schema === 'enthusiast_enth' ? 'enth_category' : 'category');
        $warnings = [];
        try {
            $stmt = $source->query("SELECT COUNT(*) FROM `{$table}`");
            $found = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $found      = 0;
            $warnings[] = "Table `{$table}` not found — you may be connected to the wrong database, or the schema variant was mis-detected.";
        }
        if ($options->enthCategoryParentId !== null) {
            $warnings[] = "Top-level categories will be imported as children of network id {$options->enthCategoryParentId}.";
        }
        return ['type' => 'Categories', 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => $warnings];
    }

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewAffiliates(PDO $source, string $schema, ImportOptions $options): array
    {
        $tp       = $options->tablePrefix;
        $table    = $tp . ($schema === 'enthusiast_enth' ? 'enth_affiliates' : 'affiliates');
        $warnings = [];
        try {
            $stmt = $source->query("SELECT COUNT(*) FROM `{$table}`");
            $found = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $found      = 0;
            $warnings[] = "Table `{$table}` not found — you may be connected to the wrong database, or the schema variant was mis-detected.";
        }
        if ($options->imageMode === 'url' && $options->imageUrl === '') {
            $warnings[] = 'Image URL not set — affiliate images will be skipped.';
        }
        return ['type' => 'Affiliates', 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => $warnings];
    }

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewJoined(PDO $source, string $schema, ImportOptions $options): array
    {
        $tp    = $options->tablePrefix;
        $table = $tp . ($schema === 'enthusiast_enth' ? 'enth_joined' : 'joined');
        try {
            $stmt  = $source->query("SELECT COUNT(*) FROM `{$table}`");
            $found = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $found = 0;
        }
        $warnings = ['`desc` and `comments` columns have no equivalent in Luster and will be dropped.'];
        return ['type' => 'Joined listings', 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => $warnings];
    }

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewSettings(PDO $source, string $schema, ImportOptions $options): array
    {
        $tp    = $options->tablePrefix;
        $table = $tp . ($schema === 'enthusiast_enth' ? 'enth_fanlistings' : 'owned');
        try {
            $stmt  = $source->query("SELECT COUNT(*) FROM `{$table}`");
            $found = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $found = 0;
        }
        $warnings = [];
        // Check for external per-listing databases
        $extCol = $schema === 'enthusiast_enth' ? 'dbserver' : 'dbserver';
        try {
            $stmt = $source->query(
                "SELECT COUNT(*) FROM `{$table}` WHERE `dbserver` != '' AND `dbserver` != 'localhost'"
            );
            $extCount = (int) $stmt->fetchColumn();
            if ($extCount > 0) {
                $warnings[] = "{$extCount} listing(s) use external databases — their members must be migrated manually.";
            }
        } catch (\Throwable) {}
        return ['type' => 'Listing settings', 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => $warnings];
    }

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewOwnedListings(PDO $source, string $schema, ImportOptions $options): array
    {
        $tp       = $options->tablePrefix;
        $srcTable = $tp . ($schema === 'enthusiast_enth' ? 'enth_fanlistings' : 'owned');
        $warnings = [];
        try {
            $stmt  = $source->query("SELECT COUNT(*) FROM `{$srcTable}`");
            $found = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $found      = 0;
            $warnings[] = "Table `{$srcTable}` not found — you may be connected to the wrong database, or the schema variant was mis-detected.";
        }
        if (!$options->importMembers) {
            $warnings[] = 'Member tables are not copied — also check "Members" below, or migrate per-listing member tables separately (e.g. via mysqldump).';
        }
        if ($schema === 'enthusiast_enth') {
            $warnings[] = 'enth_ schema: dbtable is derived as {dbprefix}members. Verify member table names after import.';
        }
        return ['type' => 'Owned listings', 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => $warnings];
    }

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewTemplates(PDO $source, string $schema, ImportOptions $options): array
    {
        $tp    = $options->tablePrefix;
        $table = $tp . ($schema === 'enthusiast_enth' ? 'enth_fanlistings' : 'owned');
        try {
            $stmt  = $source->query("SELECT COUNT(*) FROM `{$table}`");
            $listingCount = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $listingCount = 0;
        }
        $templateCount = $listingCount * 4; // 4 types per listing
        $warnings = [];
        $warnings[] = 'Enthusiast\'s {password} token will be replaced with {reset_url} in all imported templates.';
        return ['type' => 'Email templates', 'found' => $templateCount, 'import' => $templateCount, 'skip' => 0, 'warnings' => $warnings];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Categories
    // -----------------------------------------------------------------------

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[]} */
    private function runCategories(PDO $source, PDO $target, string $schema, ImportOptions $options): array
    {
        $tp           = $options->tablePrefix;
        $srcTable     = $tp . ($schema === 'enthusiast_enth' ? 'enth_category' : 'category');
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];

        // Load source categories
        try {
            $stmt = $source->query("SELECT * FROM `{$srcTable}` ORDER BY parent ASC, catid ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return ['type' => 'Categories', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [], 'errors' => ['Could not read source categories: ' . $e->getMessage()],
                    'skipped_items' => []];
        }

        // Load existing category names in target to detect conflicts
        $existStmt = $target->prepare('SELECT name FROM lstr_categories');
        $existStmt->execute();
        $existingNames = array_flip($existStmt->fetchAll(PDO::FETCH_COLUMN));

        // Two-pass: first insert parents (parent=0), then children.
        // We track srcId → new Luster id for parent resolution.
        $srcIdToNewId = [];

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_categories (name, parent_id, source) VALUES (:name, :parent_id, :source)'
        );

        foreach ($srcRows as $row) {
            $name = trim(self::decodeEnth((string) ($row['catname'] ?? '')));
            if ($name === '') {
                continue;
            }

            if (isset($existingNames[$name])) {
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                    if (count($skippedItems) < self::SKIP_CAP) {
                        $skippedItems[] = ['label' => $name, 'reason' => 'Already exists in Luster'];
                    }
                    // Still record the new id for parent resolution
                    $idStmt = $target->prepare('SELECT id FROM lstr_categories WHERE name = :name LIMIT 1');
                    $idStmt->execute(['name' => $name]);
                    $existId = $idStmt->fetchColumn();
                    if ($existId !== false) {
                        $srcIdToNewId[(int) $row['catid']] = (int) $existId;
                    }
                    continue;
                }
                // overwrite: update
                try {
                    $updStmt = $target->prepare('UPDATE lstr_categories SET source = :source WHERE name = :name');
                    $updStmt->execute(['source' => 'custom', 'name' => $name]);
                    $idStmt = $target->prepare('SELECT id FROM lstr_categories WHERE name = :name LIMIT 1');
                    $idStmt->execute(['name' => $name]);
                    $srcIdToNewId[(int) $row['catid']] = (int) $idStmt->fetchColumn();
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Category '{$name}': " . $e->getMessage();
                }
                continue;
            }

            $parentId = null;
            $srcParent = (int) ($row['parent'] ?? 0);
            if ($srcParent > 0 && isset($srcIdToNewId[$srcParent])) {
                $parentId = $srcIdToNewId[$srcParent];
            } elseif ($srcParent === 0 && $options->enthCategoryParentId !== null) {
                $parentId = $options->enthCategoryParentId;
            }

            try {
                $insertStmt->execute([
                    'name'      => $name,
                    'parent_id' => $parentId,
                    'source'    => 'custom',
                ]);
                $newId = (int) $target->lastInsertId();
                $srcIdToNewId[(int) $row['catid']] = $newId;
                $existingNames[$name] = true;
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Category '{$name}': " . $e->getMessage();
            }
        }

        return ['type' => 'Categories', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Affiliates
    // -----------------------------------------------------------------------

    /**
     * @return array{row: array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],image_copied:int,image_skipped:int,image_errors:string[]}, imageMap: array}
     */
    private function runAffiliates(
        PDO $source, PDO $target, string $schema, ImportOptions $options,
        ?ImageMigrator $migrator, array $imageSettings
    ): array {
        $tp           = $options->tablePrefix;
        $srcTable     = $tp . ($schema === 'enthusiast_enth' ? 'enth_affiliates' : 'affiliates');
        $imported     = 0;
        $skipped      = 0;
        $unlinked     = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgSkipped   = 0;
        $imgErrors    = [];
        $imageMap     = [];

        // Load valid target listing IDs so we can validate per-listing FK references.
        $validListingIds = [];
        try {
            $vidStmt = $target->prepare('SELECT listingid FROM owned');
            $vidStmt->execute();
            $validListingIds = array_flip($vidStmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable) {}

        // Build per-scope sort_order map; scope key = fanlisting_id or 'null' for collective.
        $sortOrders = [];
        try {
            $soStmt = $target->prepare(
                'SELECT fanlisting_id, COALESCE(MAX(sort_order), 0) AS max_so FROM lstr_affiliates GROUP BY fanlisting_id'
            );
            $soStmt->execute();
            foreach ($soStmt->fetchAll() as $soRow) {
                $scope = $soRow['fanlisting_id'] === null ? 'null' : (string) $soRow['fanlisting_id'];
                $sortOrders[$scope] = (int) $soRow['max_so'];
            }
        } catch (\Throwable) {}

        // Load existing affiliates for conflict detection; composite key = "{scope}:{url}".
        $existingKeys = [];
        try {
            $existStmt = $target->prepare('SELECT fanlisting_id, url FROM lstr_affiliates');
            $existStmt->execute();
            foreach ($existStmt->fetchAll() as $existRow) {
                $scope = $existRow['fanlisting_id'] === null ? 'null' : (string) $existRow['fanlisting_id'];
                $existingKeys[$scope . ':' . $existRow['url']] = true;
            }
        } catch (\Throwable) {}

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_affiliates (fanlisting_id, name, url, email, image, sort_order, active)
             VALUES (:fanlisting_id, :name, :url, :email, :image, :sort_order, 1)'
        );
        $updateCollectiveStmt = $target->prepare(
            'UPDATE lstr_affiliates SET name = :name, email = :email WHERE url = :url AND fanlisting_id IS NULL'
        );
        $updateListingStmt = $target->prepare(
            'UPDATE lstr_affiliates SET name = :name, email = :email WHERE url = :url AND fanlisting_id = :fanlisting_id'
        );

        try {
            $stmt    = $source->query("SELECT * FROM `{$srcTable}` ORDER BY affiliateid ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [
                'row' => ['type' => 'Affiliates', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                          'errors' => ['Could not read source affiliates: ' . $e->getMessage()],
                          'image_copied' => 0, 'image_skipped' => 0, 'image_errors' => [],
                          'skipped_items' => []],
                'imageMap' => [],
            ];
        }

        foreach ($srcRows as $row) {
            $url  = trim((string) ($row['url'] ?? ''));
            $name = trim(self::decodeEnth((string) ($row['title'] ?? ($row['aSubject'] ?? ''))));
            if ($url === '') {
                continue;
            }

            // Extract image filename early so it is available in the retry branch.
            $imageFilename = trim((string) ($row['imagefile'] ?? ''));

            // Determine fanlisting_id: 0 / missing means collective (NULL in Luster).
            $srcFid   = (int) ($row['listingid'] ?? ($row['aListingId'] ?? 0));
            $fid      = $srcFid > 0 ? $srcFid : null;
            $scopeStr = $fid !== null ? (string) $fid : 'null';

            // Skip affiliates whose source listing ID has no corresponding owned row.
            if ($fid !== null && !isset($validListingIds[$fid])) {
                $unlinked++;
                continue;
            }

            $conflictKey = $scopeStr . ':' . $url;

            if (isset($existingKeys[$conflictKey])) {
                if ($options->conflictStrategy === 'skip') {
                    // Re-run: if the existing record has image = NULL (first-run copy
                    // failed), retry the image migration now and update the column.
                    if ($migrator !== null && $imageFilename !== '' && $options->imageMode !== 'zip') {
                        $rSql = $fid === null
                            ? 'SELECT id, image FROM lstr_affiliates WHERE url = :url AND fanlisting_id IS NULL LIMIT 1'
                            : 'SELECT id, image FROM lstr_affiliates WHERE url = :url AND fanlisting_id = :fid LIMIT 1';
                        $rStmt = $target->prepare($rSql);
                        $rParams = ['url' => $url];
                        if ($fid !== null) { $rParams['fid'] = $fid; }
                        $rStmt->execute($rParams);
                        $rRec = $rStmt->fetch(\PDO::FETCH_ASSOC);
                        if ($rRec !== false && $rRec['image'] === null) {
                            $dummyMap = [];
                            $retried  = $this->migrateImage(
                                $migrator, $imageFilename, 'affiliates', 'af_',
                                $options, $imageSettings, $dummyMap, $imgCopied, $imgSkipped, $imgErrors
                            );
                            if ($retried !== null) {
                                $target->prepare('UPDATE lstr_affiliates SET image = :img WHERE id = :id')
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
                // overwrite: update name/email only
                try {
                    $email = trim((string) ($row['email'] ?? ''));
                    if ($fid === null) {
                        $updateCollectiveStmt->execute(['name' => $name, 'email' => $email, 'url' => $url]);
                    } else {
                        $updateListingStmt->execute(['name' => $name, 'email' => $email, 'url' => $url, 'fanlisting_id' => $fid]);
                    }
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Affiliate '{$url}': " . $e->getMessage();
                }
                continue;
            }

            // Image migration for new records
            $newImage = null;
            if ($imageFilename !== '' && $migrator !== null) {
                $newImage = $this->migrateImage(
                    $migrator, $imageFilename, 'affiliates', 'af_', $options, $imageSettings,
                    $imageMap, $imgCopied, $imgSkipped, $imgErrors
                );
            }

            $sortOrders[$scopeStr] = ($sortOrders[$scopeStr] ?? 0) + 1;
            try {
                $insertStmt->execute([
                    'fanlisting_id' => $fid,
                    'name'          => $name,
                    'url'           => $url,
                    'email'         => trim((string) ($row['email'] ?? '')),
                    'image'         => $newImage,
                    'sort_order'    => $sortOrders[$scopeStr],
                ]);
                $existingKeys[$conflictKey] = true;
                $imported++;

                // Record in image map for ZIP mode
                if ($imageFilename !== '' && $newImage === null && $options->imageMode === 'zip') {
                    $imageMap[$imageFilename] = [
                        'type'      => 'affiliates',
                        'record_id' => (int) $target->lastInsertId(),
                        'prefix'    => 'af_',
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = "Affiliate '{$url}': " . $e->getMessage();
            }
        }

        $warnings = [];
        if ($unlinked > 0) {
            $warnings[] = "{$unlinked} affiliate(s) skipped — their source listing was not found in the target database. Import owned listings first to resolve this.";
        }

        return [
            'row' => ['type' => 'Affiliates', 'imported' => $imported, 'skipped' => $skipped,
                      'warnings' => $warnings, 'errors' => $errors,
                      'image_copied' => $imgCopied, 'image_skipped' => $imgSkipped, 'image_errors' => $imgErrors,
                      'skipped_items' => $skippedItems],
            'imageMap' => $imageMap,
        ];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Joined listings
    // -----------------------------------------------------------------------

    /**
     * @return array{row: array, imageMap: array}
     */
    private function runJoined(
        PDO $source, PDO $target, string $schema, ImportOptions $options,
        array $catNameToId, ?ImageMigrator $migrator, array $imageSettings
    ): array {
        $tp           = $options->tablePrefix;
        $srcTable     = $tp . ($schema === 'enthusiast_enth' ? 'enth_joined' : 'joined');
        $srcCatTable  = $tp . ($schema === 'enthusiast_enth' ? 'enth_category' : 'category');
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgSkipped   = 0;
        $imgErrors    = [];
        $imageMap     = [];
        $warnings     = [];

        // Load source categories for name resolution.
        // Try the standard Enthusiast column names first; fall back to id/name for
        // forks that renamed the primary key (e.g. Erin's Fork).
        $srcCatMap = [];
        $catLoadError = null;
        foreach (['SELECT catid, catname FROM', 'SELECT id AS catid, name AS catname FROM'] as $catSql) {
            try {
                $catStmt = $source->query("{$catSql} `{$srcCatTable}`");
                foreach ($catStmt->fetchAll() as $cat) {
                    $srcCatMap[(int) $cat['catid']] = self::decodeEnth((string) $cat['catname']);
                }
                $catLoadError = null;
                break;
            } catch (\Throwable $e) {
                $catLoadError = $e->getMessage();
            }
        }
        if ($catLoadError !== null) {
            $warnings[] = "Could not read source categories from `{$srcCatTable}` — joined listing categories will be blank. ({$catLoadError})";
        } elseif (empty($srcCatMap)) {
            $warnings[] = "Source category table `{$srcCatTable}` is empty — joined listings will have no category assigned.";
        }

        // Load existing URLs for conflict detection
        $existStmt = $target->prepare('SELECT url FROM lstr_joined');
        $existStmt->execute();
        $existingUrls = array_flip($existStmt->fetchAll(PDO::FETCH_COLUMN));

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_joined (name, url, image, category, category_id, date_joined, active, status, sort_order)
             VALUES (:name, :url, :image, :category, :category_id, :date_joined, :active, :status, 0)'
        );

        // ORDER BY the PK — try joinedid first (standard Enthusiast), fall back to id.
        $pkCol = 'joinedid';
        try {
            $source->query("SELECT 1 FROM `{$srcTable}` ORDER BY joinedid LIMIT 1");
        } catch (\Throwable) {
            $pkCol = 'id';
        }

        try {
            $stmt    = $source->query("SELECT * FROM `{$srcTable}` ORDER BY `{$pkCol}` ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [
                'row' => ['type' => 'Joined listings', 'imported' => 0, 'skipped' => 0,
                          'warnings' => [], 'errors' => ['Could not read source joined: ' . $e->getMessage()],
                          'image_copied' => 0, 'image_skipped' => 0, 'image_errors' => [],
                          'skipped_items' => []],
                'imageMap' => [],
            ];
        }

        // Check if desc/comments have any data (for warnings)
        $hasDroppedData = false;
        foreach ($srcRows as $row) {
            if (!empty($row['desc']) || !empty($row['comments'])) {
                $hasDroppedData = true;
                break;
            }
        }
        if ($hasDroppedData) {
            $warnings[] = 'Some joined listings had `desc`/`comments` data — these fields have no equivalent in Luster and were dropped.';
        }

        foreach ($srcRows as $row) {
            $url  = trim((string) ($row['url'] ?? ''));
            $name = trim(self::decodeEnth((string) ($row['subject'] ?? '')));
            if ($url === '') {
                continue;
            }

            // Extract early so the retry branch can use it.
            $imageFilename = trim((string) ($row['imagefile'] ?? ''));

            if (isset($existingUrls[$url])) {
                if ($options->conflictStrategy === 'skip') {
                    // Re-run: retry image if the existing record has image = NULL.
                    if ($migrator !== null && $imageFilename !== '' && $options->imageMode !== 'zip') {
                        $rStmt = $target->prepare(
                            'SELECT id, image FROM lstr_joined WHERE url = :url LIMIT 1'
                        );
                        $rStmt->execute(['url' => $url]);
                        $rRec = $rStmt->fetch(\PDO::FETCH_ASSOC);
                        if ($rRec !== false && $rRec['image'] === null) {
                            $dummyMap = [];
                            $retried  = $this->migrateImage(
                                $migrator, $imageFilename, 'joined', 'jn_',
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
                    // Resolve category for overwrite path too
                    $ovSrcCatId = (int) ($row['catid'] ?? 0);
                    $ovCatName  = $ovSrcCatId > 0 ? ($srcCatMap[$ovSrcCatId] ?? '') : '';
                    if ($ovCatName === '') {
                        $ovCatName = trim(self::decodeEnth((string) ($row['catname'] ?? $row['category'] ?? '')));
                    }
                    $ovCatId = ($ovCatName !== '' && isset($catNameToId[$ovCatName])) ? $catNameToId[$ovCatName] : null;
                    $updStmt = $target->prepare(
                        'UPDATE lstr_joined SET name = :name, category = :category, category_id = :category_id WHERE url = :url'
                    );
                    $updStmt->execute(['name' => $name, 'category' => $ovCatName, 'category_id' => $ovCatId, 'url' => $url]);
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Joined '{$url}': " . $e->getMessage();
                }
                continue;
            }

            // Resolve category.
            // Primary path: catid FK → name via srcCatMap (standard Enthusiast).
            // Fallback: if catid is 0/absent but a catname/category text column exists,
            // use it directly (some Erin's Fork installs store the name inline).
            $srcCatId = (int) ($row['catid'] ?? 0);
            $catName  = $srcCatId > 0 ? ($srcCatMap[$srcCatId] ?? '') : '';
            if ($catName === '') {
                $catName = trim((string) ($row['catname'] ?? $row['category'] ?? ''));
            }
            $newCatId = ($catName !== '' && isset($catNameToId[$catName])) ? $catNameToId[$catName] : null;

            // Invert pending flag: Enthusiast pending=0 means approved → active=1
            $active = ((int) ($row['pending'] ?? 0)) === 0 ? 1 : 0;

            // Date
            $dateJoined = !empty($row['added']) ? $row['added'] : null;

            // Image migration for new records
            $newImage = null;
            if ($imageFilename !== '' && $migrator !== null) {
                $newImage = $this->migrateImage(
                    $migrator, $imageFilename, 'joined', 'jn_', $options, $imageSettings,
                    $imageMap, $imgCopied, $imgSkipped, $imgErrors
                );
            }

            try {
                $insertStmt->execute([
                    'name'        => $name,
                    'url'         => $url,
                    'image'       => $newImage,
                    'category'    => $catName,
                    'category_id' => $newCatId,
                    'date_joined' => $dateJoined,
                    'active'      => $active,
                    'status'      => $active === 1 ? 'approved' : 'pending',
                ]);
                $existingUrls[$url] = true;
                $imported++;

                if ($imageFilename !== '' && $newImage === null && $options->imageMode === 'zip') {
                    $imageMap[$imageFilename] = [
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
                      'warnings' => $warnings, 'errors' => $errors,
                      'image_copied' => $imgCopied, 'image_skipped' => $imgSkipped, 'image_errors' => $imgErrors,
                      'skipped_items' => $skippedItems],
            'imageMap' => $imageMap,
        ];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Per-listing settings
    // -----------------------------------------------------------------------

    /**
     * @return array{row: array, imageMap: array}
     */
    private function runSettings(
        PDO $source, PDO $target, string $schema, ImportOptions $options,
        ?ImageMigrator $migrator, array $imageSettings
    ): array {
        $tp           = $options->tablePrefix;
        $srcTable     = $tp . ($schema === 'enthusiast_enth' ? 'enth_fanlistings' : 'owned');
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgSkipped   = 0;
        $imgErrors    = [];
        $imageMap     = [];

        // Columns available vary by schema variant
        $idCol = $schema === 'enthusiast_enth' ? 'id' : 'listingid';

        try {
            $stmt    = $source->query("SELECT * FROM `{$srcTable}` ORDER BY {$idCol} ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return [
                'row' => ['type' => 'Listing settings', 'imported' => 0, 'skipped' => 0,
                          'warnings' => [], 'errors' => ['Could not read source listings: ' . $e->getMessage()],
                          'image_copied' => 0, 'image_skipped' => 0, 'image_errors' => [],
                          'skipped_items' => []],
                'imageMap' => [],
            ];
        }

        $checkStmt  = $target->prepare('SELECT fanlisting_id, image FROM lstr_fanlisting_settings WHERE fanlisting_id = :fid');
        $insertStmt = $target->prepare(
            'INSERT INTO lstr_fanlisting_settings
               (fanlisting_id, members_per_page, queue_updates, members_template, affiliates_template, stats_template, image)
             VALUES
               (:fanlisting_id, :members_per_page, :queue_updates, :members_template, :affiliates_template, :stats_template, :image)'
        );
        $updateStmt = $target->prepare(
            'UPDATE lstr_fanlisting_settings
             SET members_per_page = :members_per_page,
                 queue_updates = :queue_updates,
                 members_template = COALESCE(NULLIF(:members_template, \'\'), members_template),
                 affiliates_template = COALESCE(NULLIF(:affiliates_template, \'\'), affiliates_template),
                 stats_template = COALESCE(NULLIF(:stats_template, \'\'), stats_template),
                 image = COALESCE(:image, image)
             WHERE fanlisting_id = :fanlisting_id'
        );

        foreach ($srcRows as $row) {
            $listingId = (int) ($row[$idCol] ?? 0);
            if ($listingId === 0) {
                continue;
            }

            $perPage      = max(1, min(200, (int) ($row['perpage'] ?? 25)));
            $holdUpdate   = (int) ($row['holdupdate'] ?? 0);
            $membersTpl   = trim((string) ($row['listtemplate'] ?? ''));
            $affiliatesTpl = trim((string) ($row['affiliatestemplate'] ?? ''));
            $statsTpl     = trim((string) ($row['statstemplate'] ?? ''));

            // Image migration for listing image
            $imageFilename = trim((string) ($row['imagefile'] ?? ''));
            $newImage = null;
            if ($imageFilename !== '' && $migrator !== null) {
                $newImage = $this->migrateImage(
                    $migrator, $imageFilename, 'listings', 'fl_', $options, $imageSettings,
                    $imageMap, $imgCopied, $imgSkipped, $imgErrors
                );
            }

            $checkStmt->execute(['fid' => $listingId]);
            $existsRec = $checkStmt->fetch(\PDO::FETCH_ASSOC);
            $exists    = $existsRec !== false;

            $listingLabel = trim(self::decodeEnth((string) ($row[$schema === 'enthusiast_enth' ? 'name' : 'title'] ?? '')));
            if ($listingLabel === '') {
                $listingLabel = (string) $listingId;
            }

            try {
                if ($exists) {
                    if ($options->conflictStrategy === 'skip') {
                        // Re-run: if the existing settings row has image = NULL, retry now.
                        if ($newImage === null && $migrator !== null && $imageFilename !== ''
                            && $options->imageMode !== 'zip' && $existsRec['image'] === null
                        ) {
                            $dummyMap = [];
                            $retried  = $this->migrateImage(
                                $migrator, $imageFilename, 'listings', 'fl_',
                                $options, $imageSettings, $dummyMap, $imgCopied, $imgSkipped, $imgErrors
                            );
                            if ($retried !== null) {
                                $target->prepare(
                                    'UPDATE lstr_fanlisting_settings SET image = :img WHERE fanlisting_id = :fid'
                                )->execute(['img' => $retried, 'fid' => $listingId]);
                            }
                        }
                        $skipped++;
                        if (count($skippedItems) < self::SKIP_CAP) {
                            $skippedItems[] = ['label' => $listingLabel, 'reason' => 'Settings already exist for this listing'];
                        }
                        continue;
                    }
                    $updateStmt->execute([
                        'fanlisting_id'      => $listingId,
                        'members_per_page'   => $perPage,
                        'queue_updates'      => $holdUpdate,
                        'members_template'   => $membersTpl,
                        'affiliates_template' => $affiliatesTpl,
                        'stats_template'     => $statsTpl,
                        'image'              => $newImage,
                    ]);
                    $skipped++;
                } else {
                    $insertStmt->execute([
                        'fanlisting_id'      => $listingId,
                        'members_per_page'   => $perPage,
                        'queue_updates'      => $holdUpdate,
                        'members_template'   => $membersTpl ?: null,
                        'affiliates_template' => $affiliatesTpl ?: null,
                        'stats_template'     => $statsTpl ?: null,
                        'image'              => $newImage,
                    ]);
                    $imported++;
                }

                if ($imageFilename !== '' && $newImage === null && $options->imageMode === 'zip') {
                    $imageMap[$imageFilename] = [
                        'type'       => 'listings',
                        'fanlisting_id' => $listingId,
                        'prefix'     => 'fl_',
                        'column'     => 'image',
                        'table'      => 'lstr_fanlisting_settings',
                        'key'        => 'fanlisting_id',
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = "Listing #{$listingId}: " . $e->getMessage();
            }
        }

        return [
            'row' => ['type' => 'Listing settings', 'imported' => $imported, 'skipped' => $skipped,
                      'warnings' => [], 'errors' => $errors,
                      'image_copied' => $imgCopied, 'image_skipped' => $imgSkipped, 'image_errors' => $imgErrors,
                      'skipped_items' => $skippedItems],
            'imageMap' => $imageMap,
        ];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Email templates
    // -----------------------------------------------------------------------

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[]} */
    private function runTemplates(PDO $source, PDO $target, string $schema, ImportOptions $options): array
    {
        $tp           = $options->tablePrefix;
        $srcTable     = $tp . ($schema === 'enthusiast_enth' ? 'enth_fanlistings' : 'owned');
        $idCol        = $schema === 'enthusiast_enth' ? 'id' : 'listingid';
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];

        // Column name map: source column → Luster type key
        $typeMap = [
            'emailsignup'   => 'join',
            'emailapproved' => 'approve',
            'emailupdate'   => 'update',
            'emaillostpass' => 'reset',
        ];

        try {
            $stmt = $source->query(
                "SELECT {$idCol}, emailsignup, emailapproved, emailupdate, emaillostpass
                 FROM `{$srcTable}`"
            );
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return ['type' => 'Email templates', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [], 'errors' => ['Could not read source listings: ' . $e->getMessage()],
                    'skipped_items' => []];
        }

        $checkStmt  = $target->prepare(
            'SELECT id FROM lstr_email_templates WHERE fanlisting_id = :fid AND type = :type LIMIT 1'
        );
        $insertStmt = $target->prepare(
            'INSERT INTO lstr_email_templates (fanlisting_id, type, enabled, subject, body)
             VALUES (:fanlisting_id, :type, 1, \'\', :body)'
        );
        $updateStmt = $target->prepare(
            'UPDATE lstr_email_templates SET body = :body WHERE fanlisting_id = :fanlisting_id AND type = :type'
        );

        foreach ($srcRows as $row) {
            $listingId = (int) ($row[$idCol] ?? 0);
            if ($listingId === 0) {
                continue;
            }

            foreach ($typeMap as $srcCol => $lstrType) {
                $body = trim((string) ($row[$srcCol] ?? ''));
                if ($body === '') {
                    continue;
                }
                $body = str_replace('{password}', '{reset_url}', $body);

                $checkStmt->execute(['fid' => $listingId, 'type' => $lstrType]);
                $exists = $checkStmt->fetchColumn() !== false;

                try {
                    if ($exists) {
                        if ($options->conflictStrategy === 'skip') {
                            $skipped++;
                            if (count($skippedItems) < self::SKIP_CAP) {
                                $skippedItems[] = ['label' => $listingId . ' / ' . $lstrType, 'reason' => 'Template already exists'];
                            }
                            continue;
                        }
                        $updateStmt->execute(['body' => $body, 'fanlisting_id' => $listingId, 'type' => $lstrType]);
                        $skipped++;
                    } else {
                        $insertStmt->execute(['fanlisting_id' => $listingId, 'type' => $lstrType, 'body' => $body]);
                        $imported++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Template listing#{$listingId}/{$lstrType}: " . $e->getMessage();
                }
            }
        }

        $warnings = $imported > 0 || $skipped > 0
            ? ['Enthusiast\'s {password} token has been replaced with {reset_url} in all imported templates.']
            : [];

        return ['type' => 'Email templates', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => $warnings, 'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Owned listings
    // -----------------------------------------------------------------------

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[]} */
    private function runOwnedListings(
        PDO $source, PDO $target, string $schema, ImportOptions $options, bool $sameDb
    ): array {
        // Same DB + unprefixed: the `owned` table in source IS the target table — nothing to copy.
        if ($sameDb && $schema === 'enthusiast_unprefixed') {
            return ['type' => 'Owned listings', 'imported' => 0, 'skipped' => 0,
                    'warnings' => ['Same database — owned table is already accessible in Luster.'], 'errors' => [],
                    'skipped_items' => []];
        }

        $tp           = $options->tablePrefix;
        $srcTable     = $tp . ($schema === 'enthusiast_enth' ? 'enth_fanlistings' : 'owned');
        $idCol        = $schema === 'enthusiast_enth' ? 'id' : 'listingid';
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $warnings     = [];

        try {
            $stmt    = $source->query("SELECT * FROM `{$srcTable}` ORDER BY `{$idCol}` ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return ['type' => 'Owned listings', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [], 'errors' => ['Could not read source listings: ' . $e->getMessage()],
                    'skipped_items' => []];
        }

        // Load existing URLs for conflict detection.
        $existUrlStmt = $target->prepare('SELECT url FROM owned');
        $existUrlStmt->execute();
        $existingUrls = array_flip($existUrlStmt->fetchAll(PDO::FETCH_COLUMN));

        // Load existing listingids so we know whether to preserve the source ID.
        $existIdStmt = $target->prepare('SELECT listingid FROM owned');
        $existIdStmt->execute();
        $existingIds = array_flip($existIdStmt->fetchAll(PDO::FETCH_COLUMN));

        // Two INSERT variants: one preserving the source ID, one letting MySQL auto-assign.
        $insertWithIdStmt = $target->prepare(
            'INSERT INTO owned (listingid, dbtable, title, subject, email, url, catid, additional, status)
             VALUES (:listingid, :dbtable, :title, :subject, :email, :url, :catid, :additional, :status)'
        );
        $insertAutoStmt = $target->prepare(
            'INSERT INTO owned (dbtable, title, subject, email, url, catid, additional, status)
             VALUES (:dbtable, :title, :subject, :email, :url, :catid, :additional, :status)'
        );
        $updateStmt = $target->prepare(
            'UPDATE owned SET title = :title, subject = :subject, email = :email,
                              catid = :catid, additional = :additional, status = :status
             WHERE url = :url'
        );

        foreach ($srcRows as $row) {
            $srcId = (int) ($row[$idCol] ?? 0);
            $url   = trim((string) ($row['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            // Map columns from whichever schema variant we're reading.
            if ($schema === 'enthusiast_enth') {
                $dbprefix   = trim((string) ($row['dbprefix'] ?? ''));
                $dbtable    = $dbprefix . 'members';
                $title      = trim(self::decodeEnth((string) ($row['name'] ?? '')));
                $subject    = trim(self::decodeEnth((string) ($row['subject'] ?? '')));
                $email      = trim((string) ($row['email'] ?? ''));
                $catid      = trim((string) ($row['catid'] ?? ''));
                $additional = $this->parseAdditionalInfo((string) ($row['additional_info'] ?? ''));
                $trouble    = (int) ($row['trouble']  ?? 0);
                $upcoming   = (int) ($row['upcoming'] ?? 0);
                $status     = $trouble ? 0 : ($upcoming ? 1 : 2);
            } else {
                $dbtable    = trim((string) ($row['dbtable'] ?? ''));
                $title      = trim(self::decodeEnth((string) ($row['title'] ?? '')));
                $subject    = trim(self::decodeEnth((string) ($row['subject'] ?? '')));
                $email      = trim((string) ($row['email'] ?? ''));
                $catid      = trim((string) ($row['catid'] ?? ''));
                $additional = trim((string) ($row['additional'] ?? ''));
                $status     = (int) ($row['status'] ?? 2);
            }

            $params = [
                'dbtable'    => $dbtable,
                'title'      => $title,
                'subject'    => $subject,
                'email'      => $email,
                'url'        => $url,
                'catid'      => $catid,
                'additional' => $additional,
                'status'     => $status,
            ];

            // URL conflict → skip or overwrite.
            if (isset($existingUrls[$url])) {
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                    if (count($skippedItems) < self::SKIP_CAP) {
                        $skippedItems[] = ['label' => ($title !== '' ? $title : $url), 'reason' => 'Already exists (same URL)'];
                    }
                    continue;
                }
                try {
                    $updateStmt->execute(array_merge($params, ['url' => $url]));
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Listing '{$url}': " . $e->getMessage();
                }
                continue;
            }

            // Try to preserve the source listingid so that linked settings/templates
            // rows keep their fanlisting_id references intact.  If the ID is already
            // taken in the target, fall back to auto-increment and warn the user.
            $idTaken = $srcId > 0 && isset($existingIds[$srcId]);
            if ($idTaken) {
                $warnings[] = "Listing #{$srcId} ({$url}): ID already in use — auto-assigned a new ID. "
                    . 'Per-listing settings and email templates linked to this listing may need correction.';
            }

            try {
                if ($srcId > 0 && !$idTaken) {
                    $insertWithIdStmt->execute(array_merge(['listingid' => $srcId], $params));
                    $existingIds[$srcId] = true;
                } else {
                    $insertAutoStmt->execute($params);
                    $existingIds[(int) $target->lastInsertId()] = true;
                }
                $existingUrls[$url] = true;
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Listing '{$url}': " . $e->getMessage();
            }
        }

        if (!$options->importMembers) {
            $warnings[] = 'Member tables are not copied — also check "Members" in the import options, or migrate per-listing member tables separately (e.g. via mysqldump).';
        }

        return ['type' => 'Owned listings', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => array_unique($warnings), 'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    /**
     * Converts an enth_fanlistings.additional_info value (pipe-separated field definitions
     * or PHP-serialized array) to the bare comma-separated field-name format used by
     * the unprefixed `owned.additional` column.
     *
     * Examples:
     *   'fave_song,Favourite Song,text,0|fave_band,Favourite Band,text,0'
     *     → 'fave_song,fave_band'
     *   PHP-serialized array of field definitions → extracted names, comma-joined
     */
    private function parseAdditionalInfo(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $names = [];

        // PHP-serialized format (legacy Enthusiast)
        if (str_starts_with($raw, 'a:') || str_starts_with($raw, 's:')) {
            $data = @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($data)) {
                foreach ($data as $item) {
                    $name = is_array($item) ? (string) ($item[0] ?? '') : (string) $item;
                    $name = trim($name);
                    if ($name !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                        $names[] = $name;
                    }
                }
                return implode(',', $names);
            }
        }

        // Pipe-delimited: name,Label,type,required|name2,...
        foreach (explode('|', $raw) as $fieldStr) {
            $parts = explode(',', trim($fieldStr), 4);
            $name  = trim($parts[0]);
            if ($name !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                $names[] = $name;
            }
        }

        return implode(',', $names);
    }

    // -----------------------------------------------------------------------
    // Preview + Run helpers — Members
    // -----------------------------------------------------------------------

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewMembers(PDO $source, string $schema, ImportOptions $options): array
    {
        $tp       = $options->tablePrefix;
        $regTable = $tp . ($schema === 'enthusiast_enth' ? 'enth_fanlistings' : 'owned');
        $dbCol    = $schema === 'enthusiast_enth' ? 'dbprefix'         : 'dbtable';

        $warnings = [
            'If the source and target are the same database, member tables are already accessible and will not be copied.',
        ];

        try {
            $regStmt  = $source->query("SELECT `{$dbCol}` FROM `{$regTable}`");
            $listings = $regStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return ['type' => 'Members', 'found' => 0, 'import' => 0, 'skip' => 0,
                    'warnings' => ['Could not read listing registry: ' . $e->getMessage()]];
        }

        $totalFound    = 0;
        $tablesMissing = 0;

        foreach ($listings as $rawPrefix) {
            $dbtable    = $schema === 'enthusiast_enth'
                ? trim((string) $rawPrefix) . 'members'
                : trim((string) $rawPrefix);

            if ($dbtable === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $dbtable)) {
                continue;
            }

            $srcDbtable = $tp . $dbtable;

            try {
                $cnt = $source->query("SELECT COUNT(*) FROM `{$srcDbtable}`");
                $totalFound += (int) $cnt->fetchColumn();
            } catch (\Throwable) {
                $tablesMissing++;
            }
        }

        if ($tablesMissing > 0) {
            $warnings[] = "{$tablesMissing} member table(s) not found in source database — they will be skipped.";
        }

        return ['type' => 'Members', 'found' => $totalFound, 'import' => $totalFound, 'skip' => 0,
                'warnings' => $warnings];
    }

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[]} */
    private function runMembers(
        PDO $source, PDO $target, string $schema, ImportOptions $options, bool $sameDb
    ): array {
        if ($sameDb) {
            return ['type' => 'Members', 'imported' => 0, 'skipped' => 0,
                    'warnings' => ['Same database — member tables are already accessible in Luster; nothing to copy.'],
                    'errors'   => [], 'skipped_items' => []];
        }

        $tp           = $options->tablePrefix;
        $regTable     = $tp . ($schema === 'enthusiast_enth' ? 'enth_fanlistings' : 'owned');
        $idCol        = $schema === 'enthusiast_enth' ? 'id'               : 'listingid';
        $dbCol        = $schema === 'enthusiast_enth' ? 'dbprefix'         : 'dbtable';
        $addCol       = $schema === 'enthusiast_enth' ? 'additional_info'  : 'additional';

        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $warnings     = [];

        // Load all listings from the source registry.
        try {
            $regStmt  = $source->query(
                "SELECT `{$idCol}`, `{$dbCol}`, `{$addCol}` FROM `{$regTable}`"
            );
            $listings = $regStmt->fetchAll();
        } catch (\Throwable $e) {
            return ['type' => 'Members', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [], 'errors' => ['Could not read listing registry: ' . $e->getMessage()],
                    'skipped_items' => []];
        }

        foreach ($listings as $listing) {
            $rawPrefix  = trim((string) ($listing[$dbCol] ?? ''));
            $dbtable    = $schema === 'enthusiast_enth' ? $rawPrefix . 'members' : $rawPrefix;

            if ($dbtable === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $dbtable)) {
                $errors[] = "Listing has invalid or empty dbtable '{$dbtable}' — skipped.";
                continue;
            }

            $srcDbtable = $tp . $dbtable;

            // Derive additional field column names for this listing.
            $addRaw     = (string) ($listing[$addCol] ?? '');
            $fieldNames = $this->parseAdditionalFieldNames($addRaw, $schema);

            // Ensure the target member table exists (create if absent).
            try {
                $this->createMemberTable($target, $dbtable, $fieldNames);
            } catch (\Throwable $e) {
                $errors[] = "Could not create table `{$dbtable}`: " . $e->getMessage();
                continue;
            }

            // Copy members in chunks of CHUNK rows.
            $offset = 0;
            do {
                try {
                    $stmt = $source->prepare(
                        "SELECT * FROM `{$srcDbtable}` LIMIT :lim OFFSET :off"
                    );
                    $stmt->bindValue(':lim', self::CHUNK, PDO::PARAM_INT);
                    $stmt->bindValue(':off', $offset,     PDO::PARAM_INT);
                    $stmt->execute();
                    $chunk = $stmt->fetchAll();
                } catch (\Throwable $e) {
                    $errors[] = "Could not read `{$srcDbtable}` at offset {$offset}: " . $e->getMessage();
                    break;
                }

                foreach ($chunk as $row) {
                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email === '') {
                        continue;
                    }

                    // Check for an existing row in the target table.
                    $exists = false;
                    try {
                        $chk = $target->prepare(
                            "SELECT email FROM `{$dbtable}` WHERE email = :email LIMIT 1"
                        );
                        $chk->execute(['email' => $email]);
                        $exists = $chk->fetchColumn() !== false;
                    } catch (\Throwable) {
                        // Table confirmed to exist (we just created it), so treat as not found.
                    }

                    if ($exists) {
                        if ($options->conflictStrategy === 'skip') {
                            $skipped++;
                            if (count($skippedItems) < self::SKIP_CAP) {
                                $skippedItems[] = ['label' => $email, 'reason' => 'Already exists'];
                            }
                            continue;
                        }

                        // Overwrite — UPDATE all member fields.
                        $updCols = [
                            'name = :name', 'country = :country', 'url = :url',
                            'pending = :pending', 'password = :password',
                            'showemail = :showemail', 'showurl = :showurl',
                        ];
                        $updVals = [
                            'email'     => $email,
                            'name'      => trim((string) ($row['name']      ?? '')),
                            'country'   => trim((string) ($row['country']   ?? '')),
                            'url'       => trim((string) ($row['url']       ?? '')),
                            'pending'   => (int)         ($row['pending']   ?? 0),
                            'password'  => (string)      ($row['password']  ?? ''),
                            'showemail' => (int)         ($row['showemail'] ?? 1),
                            'showurl'   => (int)         ($row['showurl']   ?? 1),
                        ];
                        foreach ($fieldNames as $field) {
                            $updCols[]       = "`{$field}` = :{$field}";
                            $updVals[$field] = (string) ($row[$field] ?? '');
                        }

                        try {
                            $upd = $target->prepare(
                                "UPDATE `{$dbtable}` SET " . implode(', ', $updCols) . " WHERE email = :email"
                            );
                            $upd->execute($updVals);
                            $imported++;
                        } catch (\Throwable $e) {
                            $errors[] = "Member {$email} in `{$dbtable}`: " . $e->getMessage();
                        }
                        continue;
                    }

                    // New member — INSERT.
                    $cols = ['email', 'name', 'country', 'url', 'pending', 'password', 'showemail', 'showurl', 'added'];
                    $vals = [
                        'email'     => $email,
                        'name'      => trim((string) ($row['name']      ?? '')),
                        'country'   => trim((string) ($row['country']   ?? '')),
                        'url'       => trim((string) ($row['url']       ?? '')),
                        'pending'   => (int)         ($row['pending']   ?? 0),
                        'password'  => (string)      ($row['password']  ?? ''),
                        'showemail' => (int)         ($row['showemail'] ?? 1),
                        'showurl'   => (int)         ($row['showurl']   ?? 1),
                        'added'     => !empty($row['added']) ? $row['added'] : null,
                    ];
                    foreach ($fieldNames as $field) {
                        $cols[] = $field;
                        $vals[$field] = (string) ($row[$field] ?? '');
                    }

                    $placeholders = implode(', ', array_map(fn(string $c) => ':' . $c, $cols));
                    $colList      = implode(', ', array_map(fn(string $c) => "`{$c}`", $cols));

                    try {
                        $ins = $target->prepare(
                            "INSERT INTO `{$dbtable}` ({$colList}) VALUES ({$placeholders})"
                        );
                        $ins->execute($vals);
                        $imported++;
                    } catch (\Throwable $e) {
                        $errors[] = "Member {$email} in `{$dbtable}`: " . $e->getMessage();
                    }
                }

                $offset += self::CHUNK;
            } while (count($chunk) === self::CHUNK);
        }

        return ['type' => 'Members', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => $warnings, 'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    /**
     * Returns validated additional field column names for a member table.
     *
     * For unprefixed schema, `$raw` is the `owned.additional` value —
     * a comma-separated list of bare field names (e.g. "favorite,country2").
     *
     * For enth_ schema, `$raw` is `enth_fanlistings.additional_info` — a
     * pipe-delimited or serialised definition string. Field names are extracted
     * via parseAdditionalInfo(), then prefixed with `additional_` to match the
     * actual member table column names.
     *
     * @return string[]
     */
    private function parseAdditionalFieldNames(string $raw, string $schema): array
    {
        if ($schema === 'enthusiast_enth') {
            // enth_ schema: additional_info is pipe-delimited "name,Label,type,required|..."
            // parseAdditionalInfo() returns a bare comma-separated name list.
            $bare = array_values(array_filter(
                array_map('trim', explode(',', $this->parseAdditionalInfo($raw)))
            ));
            return array_values(array_map(fn(string $n) => 'additional_' . $n, $bare));
        }

        // Unprefixed schema: `owned.additional` is already a bare comma-separated list
        // of field names (e.g. "favorite,country2"). Do not pass through parseAdditionalInfo()
        // which would treat the whole string as a single pipe-segment and return only the
        // first token.
        return array_values(array_filter(
            array_map(
                static fn(string $n) => preg_match('/^[a-zA-Z0-9_]+$/', $n) ? $n : '',
                array_map('trim', explode(',', $raw))
            )
        ));
    }

    /**
     * Creates a per-listing member table in the target DB if it does not already exist.
     * Uses driver detection to omit MySQL-specific engine/charset options when running
     * against SQLite (e.g. in unit tests).
     *
     * @param string[] $fieldNames Validated additional field column names (no prefix for
     *                             unprefixed schema; `additional_*` for enth_ schema).
     */
    private function createMemberTable(PDO $target, string $dbtable, array $fieldNames): void
    {
        $extraCols = '';
        foreach ($fieldNames as $field) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
                continue;
            }
            $extraCols .= "  `{$field}` TEXT NOT NULL DEFAULT '',\n";
        }

        $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);
        $suffix = $driver === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            : '';

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
            ){$suffix}"
        );
    }

    // -----------------------------------------------------------------------
    // Shared utilities
    // -----------------------------------------------------------------------

    /**
     * Builds a category name → Luster id map from the target DB.
     * Used when populating category_id on joined listings.
     *
     * @return array<string, int>
     */
    private function buildCategoryNameMap(PDO $target): array
    {
        try {
            $stmt = $target->prepare('SELECT id, name FROM lstr_categories');
            $stmt->execute();
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $map[(string) $row['name']] = (int) $row['id'];
            }
            return $map;
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Checks whether two PDO connections point to the same database.
     * Used to decide whether to show the "same DB — members already accessible" note.
     */
    private function isSameDatabase(PDO $a, PDO $b): bool
    {
        // If they are literally the same object, definitely same DB
        if ($a === $b) {
            return true;
        }

        try {
            $stmtA = $a->query('SELECT DATABASE()');
            $stmtB = $b->query('SELECT DATABASE()');
            return $stmtA && $stmtB && $stmtA->fetchColumn() === $stmtB->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Migrates a single image file using the configured mode.
     *
     * Path/URL resolution priority:
     *   1. Use the directory/URL read from the Enthusiast settings table
     *      ($imageSettings['affiliates_dir'], 'joined_dir', 'listings_dir', etc.)
     *   2. Fall back to the user-supplied install root + correct default subdirectory
     *      (affiliates/, joined/, owned/ — the Enthusiast defaults)
     *
     * @param array<string,string> $imageSettings  From readImageSettings(); may be empty.
     */
    private function migrateImage(
        ImageMigrator $migrator,
        string $imageFilename,
        string $targetSubDir,
        string $prefix,
        ImportOptions $options,
        array $imageSettings,
        array &$imageMap,
        int &$copied,
        int &$skipped,
        array &$errors,
    ): ?string {
        $newFilename = null;

        if ($options->imageMode === 'path') {
            $settingsKey = match ($targetSubDir) {
                'affiliates' => 'affiliates_dir',
                'joined'     => 'joined_dir',
                default      => 'listings_dir',
            };
            $configuredDir = $imageSettings[$settingsKey] ?? '';

            if ($configuredDir !== '') {
                $srcPath = $configuredDir . '/' . $imageFilename;
            } elseif ($options->imagePath !== '') {
                $srcPath = $options->imagePath . '/' . self::IMG_SUBDIRS[$targetSubDir] . '/' . $imageFilename;
            } else {
                $skipped++;
                return null;
            }

            $newFilename = $migrator->copyFromPath($srcPath, $targetSubDir, $prefix);
            if ($newFilename !== null) {
                $copied++;
            } else {
                $skipped++;
                $errors[] = "Image not found or invalid: {$imageFilename}";
            }
        } elseif ($options->imageMode === 'url') {
            $settingsKey = match ($targetSubDir) {
                'affiliates' => 'affiliates_url',
                'joined'     => 'joined_url',
                default      => 'listings_url',
            };
            $configuredUrl = $imageSettings[$settingsKey] ?? '';

            if ($configuredUrl !== '') {
                $srcUrl = $configuredUrl . '/' . rawurlencode($imageFilename);
            } elseif ($options->imageUrl !== '') {
                $srcUrl = $options->imageUrl . '/' . self::IMG_SUBDIRS[$targetSubDir] . '/' . rawurlencode($imageFilename);
            } else {
                $skipped++;
                return null;
            }

            $newFilename = $migrator->fetchFromUrl($srcUrl, $targetSubDir, $prefix);
            if ($newFilename !== null) {
                $copied++;
            } else {
                $skipped++;
                $errors[] = "Could not fetch image: {$imageFilename}";
            }
        } elseif ($options->imageMode === 'zip') {
            // ZIP mode: defer — image map is populated by the caller.
            $skipped++;
        } else {
            $skipped++;
        }

        return $newFilename;
    }

    /**
     * Reads image directory paths and web URLs from the Enthusiast settings table.
     *
     * Returns an associative array with keys:
     *   affiliates_dir, joined_dir, listings_dir  (absolute filesystem paths)
     *   affiliates_url, joined_url, listings_url  (web-accessible URLs)
     *
     * All values are rtrim'd (no trailing slash). Returns [] on any failure so
     * callers fall back to the user-provided install root + default subdirectories.
     *
     * @param  string $schema  'enthusiast_enth' | 'enthusiast_unprefixed'
     * @param  string $tp      Table prefix from ImportOptions (e.g. '' or 'tmp_x_')
     * @return array<string,string>
     */
    private function readImageSettings(PDO $source, string $schema, string $tp): array
    {
        $table = $tp . ($schema === 'enthusiast_enth' ? 'enth_settings' : 'settings');

        try {
            $stmt = $source->prepare(
                "SELECT setting_name, setting_value FROM `{$table}`
                  WHERE setting_name IN (
                      'affiliates_dir', 'joined_images_dir', 'owned_images_dir',
                      'affiliates_url', 'joined_images_url', 'owned_images_url'
                  )"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {
            return [];
        }

        return [
            'affiliates_dir' => rtrim((string) ($rows['affiliates_dir']    ?? ''), '/\\'),
            'joined_dir'     => rtrim((string) ($rows['joined_images_dir'] ?? ''), '/\\'),
            'listings_dir'   => rtrim((string) ($rows['owned_images_dir']  ?? ''), '/\\'),
            'affiliates_url' => rtrim((string) ($rows['affiliates_url']    ?? ''), '/'),
            'joined_url'     => rtrim((string) ($rows['joined_images_url'] ?? ''), '/'),
            'listings_url'   => rtrim((string) ($rows['owned_images_url']  ?? ''), '/'),
        ];
    }

    /**
     * Decodes HTML entities stored by Enthusiast in its database.
     * Enthusiast HTML-encodes text fields (title, subject, catname, etc.) before
     * writing to the DB, so "&amp;" → "&" and "&#039;" → "'" must be reversed
     * on read. ENT_HTML5 covers both numeric (&#039;) and named (&amp;) entities.
     */
    private static function decodeEnth(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
