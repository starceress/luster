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

    private ?\ZipArchive $activeZip = null;

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
        if ($options->importOwnedListings) {
            $rows[] = $this->previewOwnedListings($source, $schema, $options);
        }
        if ($options->importAffiliates) {
            $rows[] = $this->previewAffiliates($source, $schema, $options);
        }
        if ($options->importJoined) {
            $rows[] = $this->previewJoined($source, $schema, $options);
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
        if ($options->importSavedTemplates) {
            $rows[] = $this->previewSavedTemplates($source, $tp);
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
        // Exception: SQL dump mode uses prefixed temp tables in Luster's own database,
        // so even though PDOs are identical, the data still needs to be copied.
        $sameDb = $tp === '' && $this->isSameDatabase($source, $target);

        // Build image migrator if needed
        $migrator = $options->imageMode !== ''
            ? new ImageMigrator(dirname(__DIR__, 2) . '/public')
            : null;

        // Open a pre-uploaded ZIP archive (Phase 3 ZIP mode).
        // When a ZIP was uploaded in the Scope step, process images inline during run().
        if ($options->imageMode === 'zip' && $options->zipPath !== '' && $migrator !== null) {
            $this->activeZip = new \ZipArchive();
            if ($this->activeZip->open($options->zipPath) !== true) {
                $this->activeZip = null;
            }
        }

        // Read image directory/URL configuration from the Enthusiast settings table.
        // This gives us the actual configured paths rather than hardcoded guesses.
        // Returns [] if the settings table is absent or unreadable (SQL dump mode,
        // permissions issue, etc.) — sub-methods fall back to user-provided root.
        $imageSettings = $migrator !== null
            ? $this->readImageSettings($source, $schema, $tp)
            : [];

        // Legacy image map (ZIP deferred mode) — kept for backward compatibility
        // but no longer stored in session when a pre-uploaded ZIP is used.
        $imageMap = [];

        // $catIdMap holds source-catid → Luster-category-id, built from runCategories().
        // Passed to runOwnedListings() so imported listings' catid column is remapped
        // to the correct ID in the target rather than being copied verbatim.
        $catIdMap = [];
        if ($options->importCategories) {
            ['row' => $catRow, 'idMap' => $catIdMap] = $this->runCategories($source, $target, $schema, $options);
            $rows[] = $catRow;
        }

        // Owned listings run before affiliates and joined so their inserted IDs are
        // available when per-listing affiliate/joined rows need to resolve fanlisting_id.
        // $listingIdMap maps source listingid → target listingid for use by settings and
        // templates phases, which reference listing rows by FK.
        $listingIdMap = [];
        if ($options->importOwnedListings) {
            ['row' => $ownedRow, 'listingIdMap' => $listingIdMap] = $this->runOwnedListings(
                $source, $target, $schema, $options, $sameDb, $catIdMap
            );
            $rows[] = $ownedRow;
        }

        // Build category name→new-id map for joined listings (after listings are settled).
        $catNameToId      = $this->buildCategoryNameMap($target);
        $catNameToNetwork = $this->buildCategoryNetworkMap($target);

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
                $source, $target, $schema, $options, $catNameToId, $catNameToNetwork, $migrator, $imageSettings
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

        if ($options->importMembers) {
            $rows[] = $this->runMembers($source, $target, $schema, $options, $sameDb);
        }

        if ($options->importSettings) {
            ['row' => $settRow, 'imageMap' => $settMap] = $this->runSettings(
                $source, $target, $schema, $options, $migrator, $imageSettings, $listingIdMap
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
            $rows[] = $this->runTemplates($source, $target, $schema, $options, $listingIdMap);
        }

        if ($options->importSavedTemplates) {
            $rows[] = $this->runSavedTemplates($source, $target, $tp, $options);
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
        $warnings[] = 'Enthusiast email tokens ($$fan_name$$, $$fanlisting_title$$, $$site_owner$$, $$site_name$$, etc.) will be translated to Luster\'s token format ({fan_name}, {listing_title}, {owner_name}, {site_name}, etc.).';
        return ['type' => 'Email templates', 'found' => $templateCount, 'import' => $templateCount, 'skip' => 0, 'warnings' => $warnings];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Categories
    // -----------------------------------------------------------------------

    /**
     * @return array{
     *   row: array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],skipped_items:array<int,array{label:string,reason:string}>},
     *   idMap: array<int,int>
     * }
     */
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
            return [
                'row'   => ['type' => 'Categories', 'imported' => 0, 'skipped' => 0,
                            'warnings' => [], 'errors' => ['Could not read source categories: ' . $e->getMessage()],
                            'skipped_items' => []],
                'idMap' => [],
            ];
        }

        // Load existing category names in target to detect conflicts.
        // Keyed by "source:lowercase_name" for source-aware deduplication.
        $existStmt = $target->prepare('SELECT id, name, source FROM lstr_categories');
        $existStmt->execute();
        $existingNames = [];
        foreach ($existStmt->fetchAll() as $er) {
            $existingNames[(string) $er['source'] . ':' . strtolower((string) $er['name'])] = (int) $er['id'];
            $existingNames['any:' . strtolower((string) $er['name'])]                       = (int) $er['id'];
        }

        // Track srcId → new Luster id so runOwnedListings() can remap catid FKs.
        $srcIdToNewId = [];

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_categories (name, slug, source) VALUES (:name, :slug, :source)'
        );

        // Track slugs seen in this batch to avoid within-batch (source, slug) collisions.
        $seenSlugs = [];
        foreach ($existStmt->fetchAll() as $r) {  // reload would be needed — use existing load
            // $existingNames already loaded; populate seenSlugs from current DB slugs.
        }
        // Re-query slugs from target for collision tracking within this import run.
        try {
            $slugRows = $target->query("SELECT source, slug FROM lstr_categories WHERE slug != ''")->fetchAll();
            foreach ($slugRows as $sr) {
                $seenSlugs[(string) $sr['source']][(string) $sr['slug']] = true;
            }
        } catch (\Throwable) {}

        foreach ($srcRows as $row) {
            $name = trim(self::decodeEnth((string) ($row['catname'] ?? '')));
            if ($name === '') {
                continue;
            }

            [$name, $catSource] = \Luster\Support\CategorySeedCatalog::normalizeImportName($name);

            $existKey = $catSource . ':' . strtolower($name);
            if (isset($existingNames[$existKey])) {
                $existId = $existingNames[$existKey];
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                    if (count($skippedItems) < self::SKIP_CAP) {
                        $skippedItems[] = ['label' => $name, 'reason' => 'Already exists in Luster'];
                    }
                    $srcIdToNewId[(int) $row['catid']] = $existId;
                    continue;
                }
                // overwrite: update source if it changed
                try {
                    $target->prepare('UPDATE lstr_categories SET source = :source WHERE id = :id')
                           ->execute(['source' => $catSource, 'id' => $existId]);
                    $srcIdToNewId[(int) $row['catid']] = $existId;
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Category '{$name}': " . $e->getMessage();
                }
                continue;
            }

            // Generate a slug unique within this source in the target DB + this batch.
            $baseSlug = \Luster\Repositories\CategoryRepo::slugify($name);
            $slug     = $baseSlug;
            $n = 2;
            while (isset($seenSlugs[$catSource][$slug])) {
                $slug = $baseSlug . '-' . $n++;
            }
            $seenSlugs[$catSource][$slug] = true;

            try {
                $insertStmt->execute([
                    'name'   => $name,
                    'slug'   => $slug,
                    'source' => $catSource,
                ]);
                $newId = (int) $target->lastInsertId();
                $srcIdToNewId[(int) $row['catid']]       = $newId;
                $existingNames[$existKey]                 = $newId;
                $existingNames['any:' . strtolower($name)] = $newId;
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Category '{$name}': " . $e->getMessage();
            }
        }

        return [
            'row'   => ['type' => 'Categories', 'imported' => $imported, 'skipped' => $skipped,
                        'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems],
            'idMap' => $srcIdToNewId,
        ];
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
            $warnings[] = "{$unlinked} affiliate(s) were not imported because their source listing ID had no "
                . 'corresponding entry in the target `owned` table. This happens when the source listing was '
                . 'excluded from the import scope, already existed under a different URL, or was skipped due '
                . 'to a conflict. Re-run with "Owned listings" enabled to import them.';
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
        array $catNameToId, array $catNameToNetwork, ?ImageMigrator $migrator, array $imageSettings
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
            'INSERT INTO lstr_joined (name, url, image, category, categories_json, category_id, date_joined, active, status, sort_order)
             VALUES (:name, :url, :image, :category, :categories_json, :category_id, :date_joined, :active, :status, 0)'
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
                    // Resolve all categories for overwrite path (handles pipe-delimited catid)
                    $ovParsedIds = self::parseLegacyCategoryIds(trim((string) ($row['catid'] ?? '')));
                    $ovCatNames  = [];
                    foreach ($ovParsedIds as $sid) {
                        $n = $srcCatMap[$sid] ?? '';
                        if ($n !== '') { $ovCatNames[] = $n; }
                    }
                    if (empty($ovCatNames)) {
                        $inline = trim(self::decodeEnth((string) ($row['catname'] ?? $row['category'] ?? '')));
                        if ($inline !== '') { $ovCatNames = [$inline]; }
                    }
                    $ovJsonEntries = [];
                    $ovCatName     = '';
                    $ovCatId       = null;
                    foreach ($ovCatNames as $rawOvCat) {
                        [$ovNormName, $ovCatSrc] = \Luster\Support\CategorySeedCatalog::normalizeImportName($rawOvCat);
                        if ($ovCatName === '') { $ovCatName = $ovNormName; }
                        $ovNetwork = match ($ovCatSrc) { 'tfl' => 'TFL', 'tafl' => 'TAFL', default => 'Custom' };
                        $ovJsonEntries[] = ['network' => $ovNetwork, 'name' => $ovNormName];
                        if ($ovCatId === null) {
                            $ovIdKey = $ovCatSrc !== 'custom' ? $ovCatSrc . ':' . strtolower($ovNormName) : strtolower($ovNormName);
                            $ovCatId = $catNameToId[$ovIdKey] ?? $catNameToId[$ovNormName] ?? null;
                        }
                    }
                    $ovCatJson = !empty($ovJsonEntries) ? json_encode($ovJsonEntries) : null;
                    $updStmt = $target->prepare(
                        'UPDATE lstr_joined SET name = :name, category = :category, categories_json = :categories_json, category_id = :category_id WHERE url = :url'
                    );
                    $updStmt->execute(['name' => $name, 'category' => $ovCatName, 'categories_json' => $ovCatJson, 'category_id' => $ovCatId, 'url' => $url]);
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Joined '{$url}': " . $e->getMessage();
                }
                continue;
            }

            // Resolve all categories — standard Enthusiast stores a single integer catid;
            // some forks store pipe-delimited values (|4|18|). Handle both.
            $parsedCatIds = self::parseLegacyCategoryIds(trim((string) ($row['catid'] ?? '')));
            $catNames     = [];
            foreach ($parsedCatIds as $srcId) {
                $n = $srcCatMap[$srcId] ?? '';
                if ($n !== '') { $catNames[] = $n; }
            }
            if (empty($catNames)) {
                // Fallback: inline catname/category text (Erin's Fork and similar)
                $inline = trim(self::decodeEnth((string) ($row['catname'] ?? $row['category'] ?? '')));
                if ($inline !== '') { $catNames = [$inline]; }
            }
            $catJsonEntries = [];
            $catName        = '';
            $newCatId       = null;
            foreach ($catNames as $rawCat) {
                [$normalizedName, $catSource] = \Luster\Support\CategorySeedCatalog::normalizeImportName($rawCat);
                if ($catName === '') { $catName = $normalizedName; }
                $network = match ($catSource) { 'tfl' => 'TFL', 'tafl' => 'TAFL', default => 'Custom' };
                $catJsonEntries[] = ['network' => $network, 'name' => $normalizedName];
                if ($newCatId === null) {
                    $idKey    = $catSource !== 'custom' ? $catSource . ':' . strtolower($normalizedName) : strtolower($normalizedName);
                    $newCatId = $catNameToId[$idKey] ?? $catNameToId[$normalizedName] ?? null;
                }
            }
            $categoriesJson = !empty($catJsonEntries) ? json_encode($catJsonEntries) : null;

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
                    'name'           => $name,
                    'url'            => $url,
                    'image'          => $newImage,
                    'category'       => $catName,
                    'categories_json' => $categoriesJson,
                    'category_id'    => $newCatId,
                    'date_joined'    => $dateJoined,
                    'active'         => $active,
                    'status'         => $active === 1 ? 'approved' : 'pending',
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
     * @param array<int,int> $listingIdMap  Source listingid → target listingid, from runOwnedListings().
     *                                       Used to attach settings rows to the correct target FK when
     *                                       source and target IDs diverged due to a collision.
     * @return array{row: array, imageMap: array}
     */
    private function runSettings(
        PDO $source, PDO $target, string $schema, ImportOptions $options,
        ?ImageMigrator $migrator, array $imageSettings, array $listingIdMap = []
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

        $hasLastUpdated   = $this->tableHasColumn($target, 'lstr_fanlisting_settings', 'last_updated');
        $hasAffApply      = $this->tableHasColumn($target, 'lstr_fanlisting_settings', 'affiliate_apply_enabled');
        $hasCountryField  = $this->tableHasColumn($target, 'lstr_fanlisting_settings', 'country_field_enabled');
        $hasAffSectionTpl = $this->tableHasColumn($target, 'lstr_fanlisting_settings', 'affiliates_section_template');
        $checkSelect = $hasLastUpdated
            ? 'SELECT fanlisting_id, image, last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id = :fid'
            : 'SELECT fanlisting_id, image FROM lstr_fanlisting_settings WHERE fanlisting_id = :fid';
        $checkStmt  = $target->prepare($checkSelect);
        $insertColumns = 'fanlisting_id, members_per_page, queue_updates, members_template, affiliates_template, stats_template, image'
            . ($hasCountryField  ? ', country_field_enabled'       : '')
            . ($hasLastUpdated   ? ', last_updated'                : '')
            . ($hasAffApply      ? ', affiliate_apply_enabled'     : '')
            . ($hasAffSectionTpl ? ', affiliates_section_template' : '');
        $insertValues  = ':fanlisting_id, :members_per_page, :queue_updates, :members_template, :affiliates_template, :stats_template, :image'
            . ($hasCountryField  ? ', :country_field_enabled'       : '')
            . ($hasLastUpdated   ? ', :last_updated'                : '')
            . ($hasAffApply      ? ', 1'                            : '')
            . ($hasAffSectionTpl ? ', :affiliates_section_template' : '');
        // COALESCE preserves existing last_updated when the source has no real date.
        $lastUpdatedAssignment   = $hasLastUpdated   ? ', last_updated = COALESCE(:last_updated, last_updated)'                                              : '';
        $countryFieldAssignment  = $hasCountryField  ? ', country_field_enabled = :country_field_enabled'                                                    : '';
        $affSectionTplAssignment = $hasAffSectionTpl ? ", affiliates_section_template = COALESCE(NULLIF(:affiliates_section_template, ''), affiliates_section_template)" : '';
        $insertStmt = $target->prepare(
            "INSERT INTO lstr_fanlisting_settings ({$insertColumns}) VALUES ({$insertValues})"
        );
        $updateStmt = $target->prepare(
            "UPDATE lstr_fanlisting_settings
             SET members_per_page = :members_per_page,
                 queue_updates = :queue_updates,
                 members_template = COALESCE(NULLIF(:members_template, ''), members_template),
                 affiliates_template = COALESCE(NULLIF(:affiliates_template, ''), affiliates_template),
                 stats_template = COALESCE(NULLIF(:stats_template, ''), stats_template),
                 image = COALESCE(:image, image)
                 {$countryFieldAssignment}
                 {$lastUpdatedAssignment}
                 {$affSectionTplAssignment}
             WHERE fanlisting_id = :fanlisting_id"
        );

        foreach ($srcRows as $row) {
            $listingId = (int) ($row[$idCol] ?? 0);
            if ($listingId === 0) {
                continue;
            }
            // Translate source ID → target ID in case runOwnedListings() had to reassign it.
            $targetListingId = $listingIdMap[$listingId] ?? $listingId;

            $perPage      = max(1, min(200, (int) ($row['perpage'] ?? 25)));
            $holdUpdate   = (int) ($row['holdupdate'] ?? 0);
            // `country` column: 1 = show country field, 0 = hide. Present in unprefixed `owned`;
            // may be absent in enth_ schema — default to enabled (1) when missing.
            $countryEnabled = isset($row['country']) ? ((int) $row['country'] !== 0 ? 1 : 0) : 1;
            $membersTpl    = self::translateDisplayTokens(trim((string) ($row['listtemplate'] ?? '')));
            $affiliatesTpl = self::translateDisplayTokens(trim((string) ($row['affiliatestemplate'] ?? '')));
            $statsTpl      = self::translateDisplayTokens(trim((string) ($row['statstemplate'] ?? '')));

            // When affiliates_section_template column exists (Migration 53+), wrap the Enthusiast
            // per-row template in {each_affiliate}...{/each_affiliate} and store it there.
            // The legacy affiliates_template column receives null so the section template takes over.
            $sectionTpl = ($hasAffSectionTpl && $affiliatesTpl !== '')
                ? '{each_affiliate}' . $affiliatesTpl . '{/each_affiliate}'
                : '';
            $affRowTpl = $hasAffSectionTpl ? '' : $affiliatesTpl;

            // Only preserve the translated members_template when it explicitly includes tokens
            // for this listing's custom additional fields. Enthusiast's standard listtemplate
            // only shows name/email/url — Luster's PHP loop renders Name → URL, Country, and
            // additional fields natively and is always better for that case.
            if ($membersTpl !== '') {
                // enth_ schema: additional_info is pipe/serialised → parseAdditionalInfo() extracts names.
                // Unprefixed schema: additional is already a bare comma-separated list of names.
                if ($schema === 'enthusiast_enth') {
                    $rawAdditional = trim((string) ($row['additional_info'] ?? ''));
                    $fieldNames = array_filter(array_map('trim',
                        explode(',', $this->parseAdditionalInfo($rawAdditional))
                    ));
                } else {
                    $rawAdditional = trim((string) ($row['additional'] ?? ''));
                    $fieldNames = array_filter(array_map('trim', explode(',', $rawAdditional)));
                }
                if ($fieldNames === []) {
                    // No additional fields — Enthusiast's standard template (name/email/url) is
                    // inferior to Luster's native loop. Always clear so the loop takes over.
                    $membersTpl = '';
                } else {
                    $hasToken = false;
                    foreach ($fieldNames as $f) {
                        if (str_contains($membersTpl, '{fan_' . $f . '}')) {
                            $hasToken = true;
                            break;
                        }
                    }
                    if (!$hasToken) {
                        $membersTpl = '';
                    }
                }
            }

            // Derive last_updated: try explicit source columns first, fall back to MAX(member.added).
            // Never invent today's date when no real source date exists.
            $lastUpdated = self::normalizeImportDate(
                $row['date_updated'] ?? ($row['last_updated'] ?? ($row['updated'] ?? null))
            );
            if ($lastUpdated === null && $hasLastUpdated) {
                $dbtable = $schema === 'enthusiast_enth'
                    ? $tp . trim((string) ($row['dbprefix'] ?? '')) . 'members'
                    : $tp . trim((string) ($row['dbtable'] ?? ''));
                if ($dbtable !== $tp && preg_match('/^[a-zA-Z0-9_]+$/', ltrim($dbtable, $tp))) {
                    try {
                        $luStmt = $source->prepare("SELECT MAX(added) FROM `{$dbtable}`");
                        $luStmt->execute();
                        $maxDate = (string) $luStmt->fetchColumn();
                        if (strlen($maxDate) >= 8 && !str_starts_with($maxDate, '0000')) {
                            $lastUpdated = substr($maxDate, 0, 10);
                        }
                    } catch (\Throwable) {}
                }
            }

            // Image migration for listing image
            $imageFilename = trim((string) ($row['imagefile'] ?? ''));
            $newImage = null;
            if ($imageFilename !== '' && $migrator !== null) {
                $newImage = $this->migrateImage(
                    $migrator, $imageFilename, 'listings', 'fl_', $options, $imageSettings,
                    $imageMap, $imgCopied, $imgSkipped, $imgErrors
                );
            }

            $checkStmt->execute(['fid' => $targetListingId]);
            $existsRec = $checkStmt->fetch(\PDO::FETCH_ASSOC);
            $exists    = $existsRec !== false;

            $listingLabel = trim(self::decodeEnth((string) ($row[$schema === 'enthusiast_enth' ? 'name' : 'title'] ?? '')));
            if ($listingLabel === '') {
                $listingLabel = (string) $listingId;
            }

            try {
                if ($exists) {
                    if ($options->conflictStrategy === 'skip') {
                        // Backfill image if this run migrated it but the stored row still has NULL.
                        if ($existsRec['image'] === null) {
                            $imageToStore = $newImage;
                            if ($imageToStore === null && $migrator !== null && $imageFilename !== ''
                                && $options->imageMode !== 'zip'
                            ) {
                                // Retry for cases where first attempt was deferred (legacy path).
                                $dummyMap    = [];
                                $imageToStore = $this->migrateImage(
                                    $migrator, $imageFilename, 'listings', 'fl_',
                                    $options, $imageSettings, $dummyMap, $imgCopied, $imgSkipped, $imgErrors
                                );
                            }
                            if ($imageToStore !== null) {
                                $target->prepare(
                                    'UPDATE lstr_fanlisting_settings SET image = :img WHERE fanlisting_id = :fid'
                                )->execute(['img' => $imageToStore, 'fid' => $targetListingId]);
                            }
                        }
                        // Backfill last_updated only when the target row has none yet.
                        // Use the source date when available; fall back to today so the
                        // dashboard's staleness queue is never confused by a NULL.
                        if ($hasLastUpdated
                            && ($existsRec['last_updated'] === null || $existsRec['last_updated'] === '')
                        ) {
                            $target->prepare(
                                'UPDATE lstr_fanlisting_settings SET last_updated = :lu WHERE fanlisting_id = :fid'
                            )->execute(['lu' => $lastUpdated ?? date('Y-m-d'), 'fid' => $targetListingId]);
                        }
                        $skipped++;
                        if (count($skippedItems) < self::SKIP_CAP) {
                            $skippedItems[] = ['label' => $listingLabel, 'reason' => 'Settings already exist for this listing'];
                        }
                        continue;
                    }
                    $updateParams = [
                        'fanlisting_id'       => $targetListingId,
                        'members_per_page'    => $perPage,
                        'queue_updates'       => $holdUpdate,
                        'members_template'    => $membersTpl,
                        'affiliates_template' => $affRowTpl,
                        'stats_template'      => $statsTpl,
                        'image'               => $newImage,
                    ];
                    if ($hasCountryField)  { $updateParams['country_field_enabled']       = $countryEnabled; }
                    if ($hasLastUpdated)   { $updateParams['last_updated']                 = $lastUpdated ?? date('Y-m-d'); }
                    if ($hasAffSectionTpl) { $updateParams['affiliates_section_template']  = $sectionTpl; }
                    $updateStmt->execute($updateParams);
                    $skipped++;
                } else {
                    $insertParams = [
                        'fanlisting_id'       => $targetListingId,
                        'members_per_page'    => $perPage,
                        'queue_updates'       => $holdUpdate,
                        'members_template'    => $membersTpl ?: null,
                        'affiliates_template' => $affRowTpl ?: null,
                        'stats_template'      => $statsTpl ?: null,
                        'image'               => $newImage,
                    ];
                    if ($hasCountryField)  { $insertParams['country_field_enabled']       = $countryEnabled; }
                    if ($hasLastUpdated)   { $insertParams['last_updated']                 = $lastUpdated ?? date('Y-m-d'); }
                    if ($hasAffSectionTpl) { $insertParams['affiliates_section_template']  = $sectionTpl ?: null; }
                    $insertStmt->execute($insertParams);
                    $imported++;
                }

                if ($imageFilename !== '' && $newImage === null && $options->imageMode === 'zip') {
                    $imageMap[$imageFilename] = [
                        'type'          => 'listings',
                        'fanlisting_id' => $targetListingId,
                        'prefix'        => 'fl_',
                        'column'        => 'image',
                        'table'         => 'lstr_fanlisting_settings',
                        'key'           => 'fanlisting_id',
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = "Listing #{$listingId} (target #{$targetListingId}): " . $e->getMessage();
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

    /**
     * @param array<int,int> $listingIdMap  Source listingid → target listingid, from runOwnedListings().
     * @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[]}
     */
    private function runTemplates(
        PDO $source, PDO $target, string $schema, ImportOptions $options, array $listingIdMap = []
    ): array {
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

        // Comprehensive Enthusiast → Luster token translation table.
        // Enthusiast uses $$double_dollar$$ delimiters; Luster uses {single_brace}.
        // The {password} single-brace form appears in some Erin's-fork template fields.
        // Variant/alias forms ($$site_owner$$, $$site_name$$, etc.) appear in some
        // customised Enthusiast installs and are normalised to their Luster equivalents.
        $tokenSearch  = [
            '$$fan_name$$',
            '$$fan_email$$',
            '$$fan_url$$',
            '$$fan_country$$',
            '$$fanlisting_title$$',
            '$$fanlisting_subject$$',
            '$$fanlisting_url$$',
            '$$owner_name$$',
            '$$owner_email$$',
            '$$site_owner$$',
            '$$site_email$$',
            '$$site_name$$',
            '$$site_title$$',
            '$$site_url$$',
            '$$listing_type$$',
            '$$fan_password$$',
            '$$password$$',
            '$$lostpass_link$$',
            '{password}',
        ];
        $tokenReplace = [
            '{fan_name}',
            '{fan_email}',
            '{fan_url}',
            '{fan_country}',
            '{listing_title}',
            '{listing_subject}',
            '{listing_url}',
            '{owner_name}',
            '{owner_email}',
            '{owner_name}',
            '{owner_email}',
            '{site_name}',
            '{site_name}',
            '{listing_url}',
            'fanlisting',
            '{reset_url}',
            '{reset_url}',
            '{reset_url}',
            '{reset_url}',
        ];

        foreach ($srcRows as $row) {
            $listingId = (int) ($row[$idCol] ?? 0);
            if ($listingId === 0) {
                continue;
            }
            // Translate source ID → target ID in case runOwnedListings() had to reassign it.
            $targetListingId = $listingIdMap[$listingId] ?? $listingId;

            foreach ($typeMap as $srcCol => $lstrType) {
                $body = trim((string) ($row[$srcCol] ?? ''));
                if ($body === '') {
                    continue;
                }
                $body = str_replace($tokenSearch, $tokenReplace, $body);

                $checkStmt->execute(['fid' => $targetListingId, 'type' => $lstrType]);
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
                        $updateStmt->execute(['body' => $body, 'fanlisting_id' => $targetListingId, 'type' => $lstrType]);
                        $skipped++;
                    } else {
                        $insertStmt->execute(['fanlisting_id' => $targetListingId, 'type' => $lstrType, 'body' => $body]);
                        $imported++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Template listing#{$listingId} (target#{$targetListingId})/{$lstrType}: " . $e->getMessage();
                }
            }
        }

        $warnings = $imported > 0 || $skipped > 0
            ? ['Enthusiast email tokens ($$fan_name$$, $$fanlisting_title$$, $$site_owner$$, $$site_name$$, etc.) have been translated to Luster\'s token format ({fan_name}, {listing_title}, {owner_name}, {site_name}, etc.).']
            : [];

        return ['type' => 'Email templates', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => $warnings, 'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Saved/reusable email templates (Erin's fork: emailtemplate table)
    // -----------------------------------------------------------------------

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewSavedTemplates(PDO $source, string $tp): array
    {
        try {
            $found = (int) $source->query("SELECT COUNT(*) FROM `{$tp}emailtemplate`")->fetchColumn();
        } catch (\Throwable) {
            return ['type' => 'Saved email templates', 'found' => 0, 'import' => 0, 'skip' => 0,
                    'warnings' => ['`emailtemplate` table not found — this table is only present in Erin\'s fork of Enthusiast.']];
        }
        return ['type' => 'Saved email templates', 'found' => $found, 'import' => $found, 'skip' => 0,
                'warnings' => ['Enthusiast tokens will be translated to Luster\'s token format.']];
    }

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],skipped_items:array} */
    private function runSavedTemplates(PDO $source, PDO $target, string $tp, ImportOptions $options): array
    {
        $tp = $tp; // already resolved by caller

        try {
            $stmt    = $source->query("SELECT templateid, templatename, subject, content FROM `{$tp}emailtemplate` ORDER BY templateid ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return ['type' => 'Saved email templates', 'imported' => 0, 'skipped' => 0,
                    'warnings' => ['`emailtemplate` table not found — skipped. This table only exists in Erin\'s fork of Enthusiast.'],
                    'errors' => [], 'skipped_items' => []];
        }

        // Conflict detection: match by name
        $existingNames = [];
        try {
            $existStmt = $target->query('SELECT name FROM lstr_saved_emails');
            $existingNames = array_flip($existStmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable) {}

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_saved_emails (name, subject, body) VALUES (:name, :subject, :body)'
        );
        $updateStmt = $target->prepare(
            'UPDATE lstr_saved_emails SET subject = :subject, body = :body WHERE name = :name'
        );

        // Token translation for saved/reusable templates.
        // Includes the affiliate-specific tokens that appear in affiliation-notification emails.
        $tokenSearch  = [
            '$$fan_name$$', '$$fan_email$$', '$$fan_url$$', '$$fan_country$$',
            '$$fanlisting_title$$', '$$fanlisting_subject$$', '$$fanlisting_url$$',
            '$$owner_name$$', '$$owner_email$$',
            '$$site_owner$$', '$$site_email$$', '$$site_name$$', '$$site_title$$', '$$site_url$$',
            '$$site_aff_title$$', '$$site_aff_url$$',
            '$$listing_type$$',
            '$$fan_password$$', '$$password$$', '$$lostpass_link$$', '{password}',
        ];
        $tokenReplace = [
            '{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}',
            '{listing_title}', '{listing_subject}', '{listing_url}',
            '{owner_name}', '{owner_email}',
            '{owner_name}', '{owner_email}', '{site_name}', '{site_name}', '{listing_url}',
            '{aff_name}', '{aff_url}',
            'fanlisting',
            '{reset_url}', '{reset_url}', '{reset_url}', '{reset_url}',
        ];

        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];

        foreach ($srcRows as $row) {
            $name    = trim((string) ($row['templatename'] ?? ''));
            $subject = str_replace($tokenSearch, $tokenReplace, trim((string) ($row['subject'] ?? '')));
            $body    = str_replace($tokenSearch, $tokenReplace, trim((string) ($row['content'] ?? '')));

            if ($name === '') {
                continue;
            }

            if (isset($existingNames[$name])) {
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                    if (count($skippedItems) < self::SKIP_CAP) {
                        $skippedItems[] = ['label' => $name, 'reason' => 'Already exists'];
                    }
                    continue;
                }
                try {
                    $updateStmt->execute(['name' => $name, 'subject' => $subject, 'body' => $body]);
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Saved template '{$name}': " . $e->getMessage();
                }
                continue;
            }

            try {
                $insertStmt->execute(['name' => $name, 'subject' => $subject, 'body' => $body]);
                $existingNames[$name] = true;
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Saved template '{$name}': " . $e->getMessage();
            }
        }

        $warnings = $imported > 0 || $skipped > 0
            ? ['Enthusiast email tokens ($$fan_name$$, $$site_owner$$, $$site_name$$, etc.) have been translated to Luster\'s token format ({fan_name}, {owner_name}, {site_name}, etc.).']
            : [];

        return ['type' => 'Saved email templates', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => $warnings, 'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    // -----------------------------------------------------------------------
    // Run helpers — Owned listings
    // -----------------------------------------------------------------------

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[]} */
    /**
     * @param array<int,int> $catIdMap  Source catid → Luster lstr_categories.id, built by runCategories().
     *                                   Empty when categories were not imported in this run.
     */
    /**
     * @return array{row: array, listingIdMap: array<int,int>}
     *   listingIdMap maps each source listingid to its final target listingid so that
     *   subsequent phases (settings, templates) can attach rows to the correct target FK.
     */
    private function runOwnedListings(
        PDO $source, PDO $target, string $schema, ImportOptions $options, bool $sameDb,
        array $catIdMap = []
    ): array {
        $tp           = $options->tablePrefix;
        $srcTable     = $tp . ($schema === 'enthusiast_enth' ? 'enth_fanlistings' : 'owned');
        $idCol        = $schema === 'enthusiast_enth' ? 'id' : 'listingid';
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $warnings     = [];
        $touchedIds   = [];
        // Maps source listingid → target listingid for all processed listings.
        $listingIdMap = [];

        if ($sameDb && $schema === 'enthusiast_unprefixed') {
            $warnings[] = 'Same database - owned listings already exist; checked existing rows for category/date backfills.';
        }

        try {
            $stmt    = $source->query("SELECT * FROM `{$srcTable}` ORDER BY `{$idCol}` ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return ['type' => 'Owned listings', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [], 'errors' => ['Could not read source listings: ' . $e->getMessage()],
                    'skipped_items' => []];
        }

        // Migrations 8, 51, and 52 add category_id / opened to `owned` and create
        // lstr_listing_categories.  If migrations ran before the owned table existed
        // (e.g. on a fresh install where the dump is the first thing imported),
        // those steps were skipped but the migration numbers were still recorded.
        // Ensure the schema is complete now so the import can write to these columns.
        // Note: ADD COLUMN IF NOT EXISTS is MariaDB-only; use tableHasColumn first
        // so this works on both MySQL and MariaDB.
        // Errors are surfaced as warnings so the user can see exactly what failed.
        if ($target->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            if (!$this->tableHasColumn($target, 'owned', 'category_id')) {
                try {
                    $target->exec("ALTER TABLE `owned` ADD COLUMN `category_id` INT UNSIGNED NULL");
                } catch (\Throwable $e) {
                    $warnings[] = 'Schema bootstrap (category_id): ' . $e->getMessage();
                }
            }
            if (!$this->tableHasColumn($target, 'owned', 'opened')) {
                try {
                    $target->exec("ALTER TABLE `owned` ADD COLUMN `opened` DATE NULL");
                } catch (\Throwable $e) {
                    $warnings[] = 'Schema bootstrap (opened): ' . $e->getMessage();
                }
            }
            try {
                $target->exec(
                    "CREATE TABLE IF NOT EXISTS `lstr_listing_categories` (
                        `fanlisting_id` INT UNSIGNED NOT NULL,
                        `category_id`   INT UNSIGNED NOT NULL,
                        PRIMARY KEY (`fanlisting_id`, `category_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            } catch (\Throwable $e) {
                $warnings[] = 'Schema bootstrap (lstr_listing_categories): ' . $e->getMessage();
            }
        }

        // Check whether the target owned table has an `opened` column (added by Luster migrations).
        // Older or minimal installs may not have it yet; we degrade gracefully.
        $hasOpened      = $this->tableHasColumn($target, 'owned', 'opened');
        // Migration 8 adds owned.category_id; Migration 51 creates lstr_listing_categories.
        $hasCategoryId  = $this->tableHasColumn($target, 'owned', 'category_id');
        $hasJunction    = $this->tableHasColumn($target, 'lstr_listing_categories', 'fanlisting_id');
        $openedFoundInSource  = 0; // rows where source had a real date
        $openedMissingInSource = 0; // rows where source had no date at all

        // Load existing URLs for conflict detection.
        $existUrlStmt = $target->prepare('SELECT listingid, url FROM owned');
        $existUrlStmt->execute();
        $existingUrls = [];
        foreach ($existUrlStmt->fetchAll() as $existingRow) {
            $existingUrl = (string) ($existingRow['url'] ?? '');
            if ($existingUrl !== '') {
                $existingUrls[$existingUrl] = (int) ($existingRow['listingid'] ?? 0);
            }
        }

        // Load existing listingids so we know whether to preserve the source ID.
        $existIdStmt = $target->prepare('SELECT listingid FROM owned');
        $existIdStmt->execute();
        $existingIds = array_flip($existIdStmt->fetchAll(PDO::FETCH_COLUMN));

        // Two INSERT variants: one preserving the source ID, one letting MySQL auto-assign.
        $openedCol    = $hasOpened     ? ', opened'        : '';
        $openedVal    = $hasOpened     ? ', :opened'       : '';
        $openedUpdate = $hasOpened     ? ', opened = COALESCE(:opened, opened)' : '';
        $catIdCol     = $hasCategoryId ? ', category_id'   : '';
        $catIdVal     = $hasCategoryId ? ', :category_id'  : '';
        $catIdUpdate  = $hasCategoryId ? ', category_id = :category_id' : '';
        $insertWithIdStmt = $target->prepare(
            "INSERT INTO owned (listingid, dbtable, title, subject, email, url, catid, additional, status{$openedCol}{$catIdCol})
             VALUES (:listingid, :dbtable, :title, :subject, :email, :url, :catid, :additional, :status{$openedVal}{$catIdVal})"
        );
        $insertAutoStmt = $target->prepare(
            "INSERT INTO owned (dbtable, title, subject, email, url, catid, additional, status{$openedCol}{$catIdCol})
             VALUES (:dbtable, :title, :subject, :email, :url, :catid, :additional, :status{$openedVal}{$catIdVal})"
        );
        $updateStmt = $target->prepare(
            "UPDATE owned SET title = :title, subject = :subject, email = :email,
                              catid = :catid, additional = :additional, status = :status
                              {$openedUpdate}{$catIdUpdate}
             WHERE url = :url"
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

            // opened date — column name varies by Enthusiast fork.
            // Standard Erin's fork: 'opened'. Fanatically fork: 'opened_date'. Older: 'date'.
            $rawOpened = $row['opened'] ?? ($row['opened_date'] ?? ($row['date'] ?? null));
            $opened    = self::normalizeImportDate($rawOpened);
            if ($opened !== null) {
                $openedFoundInSource++;
            } elseif (array_key_exists('opened', $row) || array_key_exists('opened_date', $row) || array_key_exists('date', $row)) {
                $openedMissingInSource++; // column exists but value is blank/sentinel
            } else {
                $openedMissingInSource++; // column absent from source entirely
            }

            // Remap source category ID(s) to the Luster lstr_categories ID assigned
            // during this import run.  owned.catid may be a bare integer ("12"),
            // pipe-delimited ("|8|31|"), or bang-delimited ("!8!31!") depending on
            // the Enthusiast fork.  Use the first resolved ID as the primary category
            // (owned.catid is a single FK integer in Luster's schema).
            $resolvedCategoryIds = self::resolveImportedCategoryIds($catid, $catIdMap);
            $primaryCategoryId   = $resolvedCategoryIds[0] ?? null;
            if ($resolvedCategoryIds !== []) {
                $catid = self::formatLusterCatid($resolvedCategoryIds);
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
            if ($hasOpened) {
                $params['opened'] = $opened;
            }
            if ($hasCategoryId) {
                $params['category_id'] = $primaryCategoryId;
            }

            // URL conflict → skip or overwrite.
            if (isset($existingUrls[$url])) {
                $targetId = (int) $existingUrls[$url];
                if ($options->conflictStrategy === 'skip') {
                    if ($srcId > 0) {
                        $listingIdMap[$srcId] = $targetId;
                    }
                    // Backfill opened date only when source has one and target is still blank.
                    if ($hasOpened && $opened !== null) {
                        $target->prepare(
                            "UPDATE owned SET opened = :opened WHERE url = :url AND (opened IS NULL OR opened = '' OR opened = '0000-00-00')"
                        )->execute(['opened' => $opened, 'url' => $url]);
                    }
                    // Backfill catid / category_id / junction when the existing row has an
                    // unmapped or invalid value (pipe-/bang-delimited from a prior failed import).
                    if ($resolvedCategoryIds !== []) {
                        try {
                            $curCatStmt = $target->prepare(
                                'SELECT listingid, catid' . ($hasCategoryId ? ', category_id' : '') . ' FROM owned WHERE url = :url LIMIT 1'
                            );
                            $curCatStmt->execute(['url' => $url]);
                            $curRow = $curCatStmt->fetch(PDO::FETCH_ASSOC);
                            $jFid = (int) ($curRow['listingid'] ?? 0);
                            $currentPrimary = null;
                            if ($hasCategoryId && $curRow !== false && array_key_exists('category_id', $curRow) && $curRow['category_id'] !== null) {
                                $currentPrimary = (int) $curRow['category_id'];
                            }
                            if ($this->categoryBackfillNeeded(
                                $target,
                                $jFid,
                                $currentPrimary,
                                (string) ($curRow['catid'] ?? ''),
                                $resolvedCategoryIds,
                                $hasJunction
                            )) {
                                $updateSql = 'UPDATE owned SET catid = :catid' . ($hasCategoryId ? ', category_id = :category_id' : '') . ' WHERE url = :url';
                                $updateP   = ['catid' => self::formatLusterCatid($resolvedCategoryIds), 'url' => $url];
                                if ($hasCategoryId) {
                                    $updateP['category_id'] = $primaryCategoryId;
                                }
                                $target->prepare($updateSql)->execute($updateP);
                                $this->replaceListingCategories($target, $jFid, $resolvedCategoryIds, $hasJunction);
                            }
                        } catch (\Throwable) {}
                    }
                    $skipped++;
                    if (count($skippedItems) < self::SKIP_CAP) {
                        $skippedItems[] = ['label' => ($title !== '' ? $title : $url), 'reason' => 'Already exists (same URL)'];
                    }
                    continue;
                }
                try {
                    // dbtable is not updated (renaming member tables could break existing data).
                    $updateParams = $params;
                    unset($updateParams['dbtable']);
                    $updateStmt->execute($updateParams);
                    if ($targetId > 0 && !$options->importSettings) {
                        $touchedIds[] = $targetId;
                    }
                    if ($srcId > 0) {
                        $listingIdMap[$srcId] = $targetId;
                    }
                    // Overwrite: replace junction row for this listing.
                    if ($resolvedCategoryIds !== []) {
                        $this->replaceListingCategories($target, $targetId, $resolvedCategoryIds, $hasJunction);
                    }
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Listing '{$url}': " . $e->getMessage();
                }
                continue;
            }

            // Try to preserve the source listingid so that linked settings/templates
            // rows keep their fanlisting_id references intact.  If the ID is already
            // taken in the target, fall back to auto-increment; the listingIdMap will
            // carry the correct source→target translation to downstream phases.
            $idTaken = $srcId > 0 && isset($existingIds[$srcId]);
            if ($idTaken) {
                $warnings[] = "Listing #{$srcId} ({$url}): ID already in use in target — auto-assigned a new ID.";
            }

            try {
                if ($srcId > 0 && !$idTaken) {
                    $insertWithIdStmt->execute(array_merge(['listingid' => $srcId], $params));
                    $existingIds[$srcId] = true;
                    $newListingId = $srcId;
                } else {
                    $insertAutoStmt->execute($params);
                    $newListingId = (int) $target->lastInsertId();
                    $existingIds[$newListingId] = true;
                }
                $existingUrls[$url] = $newListingId;
                if ($srcId > 0) {
                    $listingIdMap[$srcId] = $newListingId;
                }
                // Write junction rows so the admin UI and public filters can see every category.
                if ($resolvedCategoryIds !== []) {
                    $this->replaceListingCategories($target, $newListingId, $resolvedCategoryIds, $hasJunction);
                }
                if (!$options->importSettings) {
                    $touchedIds[] = $newListingId;
                }
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Listing '{$url}': " . $e->getMessage();
            }
        }

        if (!$options->importMembers) {
            $warnings[] = 'Member tables are not copied — also check "Members" in the import options, or migrate per-listing member tables separately (e.g. via mysqldump).';
        }

        // Diagnostic: tell the admin whether opened dates were found and stored.
        if (!$hasOpened) {
            $warnings[] = '"Opened On" date: the owned table does not have an opened column — run the Luster migration runner (public/migrate/) to add it, then re-run the import.';
        } elseif ($openedFoundInSource === 0 && $openedMissingInSource > 0) {
            $warnings[] = '"Opened On" date: your Enthusiast source has no opened, opened_date, or date column in the owned table — the date cannot be imported from this dump.';
        } elseif ($openedFoundInSource > 0) {
            $warnings[] = '"Opened On" date: imported dates for ' . $openedFoundInSource . ' of ' . ($openedFoundInSource + $openedMissingInSource) . ' listing(s).';
        }

        if ($touchedIds !== []) {
            $this->touchImportedListingSettings($target, $touchedIds);
        }

        // Reset the owned table AUTO_INCREMENT so future listing creation cannot collide
        // with IDs that were just explicitly inserted.  MySQL treats AUTO_INCREMENT=1 as
        // "use MAX(id)+1", so this is safe even when no explicit IDs were inserted.
        if ($imported > 0 && $target->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            try {
                $target->exec('ALTER TABLE `owned` AUTO_INCREMENT = 1');
            } catch (\Throwable) {}
        }

        return [
            'row' => ['type' => 'Owned listings', 'imported' => $imported, 'skipped' => $skipped,
                      'warnings' => array_unique($warnings), 'errors' => $errors, 'skipped_items' => $skippedItems],
            'listingIdMap' => $listingIdMap,
        ];
    }

    /**
     * Seeds the admin-controlled Last Updated date for freshly imported listings.
     * This keeps a new Enthusiast import from immediately appearing in the
     * dashboard's neglected-listing maintenance queue.
     *
     * @param int[] $listingIds
     */
    private function touchImportedListingSettings(PDO $target, array $listingIds): void
    {
        $listingIds = array_values(array_unique(array_filter(array_map('intval', $listingIds))));
        if ($listingIds === []) {
            return;
        }

        try {
            // INSERT IGNORE / OR IGNORE: only create the row when it doesn't already exist.
            // Never overwrite an existing last_updated with today's date — real source dates
            // are written by runSettings(); this function only seeds brand-new rows.
            $hasAffApply = $this->tableHasColumn($target, 'lstr_fanlisting_settings', 'affiliate_apply_enabled');
            $affCol = $hasAffApply ? ', affiliate_apply_enabled' : '';
            $affVal = $hasAffApply ? ', 1'                       : '';
            if ($target->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $stmt = $target->prepare(
                    "INSERT IGNORE INTO lstr_fanlisting_settings (fanlisting_id, last_updated{$affCol})
                     VALUES (:id, CURRENT_DATE{$affVal})"
                );
            } else {
                $stmt = $target->prepare(
                    "INSERT OR IGNORE INTO lstr_fanlisting_settings (fanlisting_id, last_updated{$affCol})
                     VALUES (:id, CURRENT_DATE{$affVal})"
                );
            }

            foreach ($listingIds as $listingId) {
                $stmt->execute(['id' => $listingId]);
            }
        } catch (\Throwable) {
            // Older installs/tests may not have lstr_fanlisting_settings.last_updated yet.
        }
    }

    /**
     * Returns a YYYY-MM-DD string from $raw, or null for empty/sentinel/invalid values.
     * Rejects '', '0000-00-00', '1970-01-01', and anything not matching YYYY-MM-DD.
     */
    private static function normalizeImportDate(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '' || $s === '0000-00-00' || $s === '1970-01-01') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return substr($s, 0, 10);
        }
        return null;
    }

    /**
     * @param array<int,int> $catIdMap Source Enthusiast catid => Luster category id.
     * @return int[]
     */
    private static function resolveImportedCategoryIds(string $raw, array $catIdMap): array
    {
        if ($catIdMap === []) {
            return [];
        }

        $resolved = [];
        foreach (self::parseLegacyCategoryIds($raw) as $srcCatId) {
            $targetId = (int) ($catIdMap[$srcCatId] ?? 0);
            if ($targetId > 0) {
                $resolved[] = $targetId;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param int[] $categoryIds Luster category ids.
     */
    private static function formatLusterCatid(array $categoryIds): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn(int $id): bool => $id > 0)));
        return $ids === [] ? '' : '|' . implode('|', $ids) . '|';
    }

    /**
     * @param int[] $categoryIds Luster category ids.
     */
    private function replaceListingCategories(PDO $target, int $listingId, array $categoryIds, bool $hasJunction): void
    {
        if (!$hasJunction || $listingId <= 0) {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn(int $id): bool => $id > 0)));

        try {
            $target->prepare('DELETE FROM lstr_listing_categories WHERE fanlisting_id = :fid')
                   ->execute(['fid' => $listingId]);

            if ($ids === []) {
                return;
            }

            $stmt = $target->prepare(
                'INSERT INTO lstr_listing_categories (fanlisting_id, category_id) VALUES (:fid, :cid)'
            );
            foreach ($ids as $categoryId) {
                $stmt->execute(['fid' => $listingId, 'cid' => $categoryId]);
            }
        } catch (\Throwable) {
            // Category assignment backfill should never abort the rest of the import.
        }
    }

    /**
     * @param int[] $expectedIds Luster category ids.
     */
    private function categoryBackfillNeeded(
        PDO $target,
        int $listingId,
        ?int $currentPrimary,
        string $currentCatid,
        array $expectedIds,
        bool $hasJunction
    ): bool {
        $expectedIds = array_values(array_unique(array_filter(array_map('intval', $expectedIds), static fn(int $id): bool => $id > 0)));
        if ($expectedIds === []) {
            return false;
        }

        $expectedCatid = self::formatLusterCatid($expectedIds);
        $currentCatid  = trim($currentCatid);

        if ($currentPrimary === null || $currentPrimary <= 0 || $currentPrimary !== $expectedIds[0]) {
            return true;
        }

        if ($currentCatid === '' || $currentCatid === '0' || $currentCatid !== $expectedCatid) {
            return true;
        }

        if (!$hasJunction || $listingId <= 0) {
            return false;
        }

        try {
            $stmt = $target->prepare(
                'SELECT category_id FROM lstr_listing_categories WHERE fanlisting_id = :fid ORDER BY category_id ASC'
            );
            $stmt->execute(['fid' => $listingId]);
            $currentIds = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
            sort($currentIds);
            $sortedExpected = $expectedIds;
            sort($sortedExpected);
            return $currentIds !== $sortedExpected;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Translates Enthusiast $$token$$ placeholders in display templates
     * (stats, members, affiliates) to Luster's {token} format.
     */
    private static function translateDisplayTokens(string $tpl): string
    {
        if ($tpl === '') {
            return $tpl;
        }
        $tpl = str_replace(
            [
                // Stats tokens
                '$$stat_opened$$',
                '$$stat_updated$$',
                '$$stat_lastupdated$$',
                '$$stat_approved$$',
                '$$stat_pending$$',
                '$$stat_newmembers$$',
                '$$stat_growth$$',
                // Member row tokens (generic variants first — longest match wins)
                '$$fan_email_generic$$',
                '$$fan_url_generic$$',
                '$$fan_name$$',
                '$$fan_email$$',
                '$$fan_url$$',
                '$$fan_country$$',
                // Affiliate row tokens
                '$$aff_name$$',
                '$$aff_url$$',
                '$$aff_image$$',
                // Listing info (may appear in any template)
                '$$fanlisting_title$$',
                '$$fanlisting_url$$',
            ],
            [
                '{stat_opened}',
                '{stat_updated}',
                '{stat_last_updated}',
                '{stat_approved}',
                '{stat_pending}',
                '{stat_newmembers}',
                '{stat_growth}',
                '{fan_email}',
                '{fan_url}',
                '{fan_name}',
                '{fan_email}',
                '{fan_url}',
                '{fan_country}',
                '{aff_name}',
                '{aff_url}',
                '{aff_image_tag}',
                '{listing_title}',
                '{listing_url}',
            ],
            $tpl,
        );
        // Additional field tokens not covered by the table above.
        // enth_ schema uses $$fan_additional_FIELDNAME$$ (strip the prefix).
        // Unprefixed schema uses $$fan_FIELDNAME$$ (keep as-is).
        $tpl = (string) preg_replace('/\$\$fan_additional_([a-zA-Z0-9_]+)\$\$/', '{fan_$1}', $tpl);
        $tpl = (string) preg_replace('/\$\$fan_([a-zA-Z0-9_]+)\$\$/', '{fan_$1}', $tpl);
        return $tpl;
    }

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
     * Builds a category name → Luster id map from the target DB.
     * Stores two keys per row: plain name ("Adult" → id) and source-keyed
     * ("tfl:adult" → id, "tafl:adult" → id) so that runJoined() can resolve
     * the five ambiguous same-name TFL/TAFL categories (Adult, Companies, etc.)
     * correctly when the source is known from normalizeImportName().
     *
     * @return array<string, int>
     */
    private function buildCategoryNameMap(PDO $target): array
    {
        try {
            $stmt = $target->prepare('SELECT id, name, source FROM lstr_categories');
            $stmt->execute();
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $id     = (int)    $row['id'];
                $name   = (string) $row['name'];
                $source = (string) $row['source'];
                $map[$name]                             = $id;
                $map[$source . ':' . strtolower($name)] = $id;
            }
            return $map;
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Builds a name → network-label map from the target lstr_categories.
     * Uses the `source` column as authoritative. Stores two keys per row:
     * plain lowercase name (last wins for ambiguous) and source-keyed
     * ("tfl:adult" → "TFL", "tafl:adult" → "TAFL") for precise disambiguation.
     *
     * @return array<string, string>
     */
    private function buildCategoryNetworkMap(PDO $target): array
    {
        try {
            $stmt = $target->prepare('SELECT name, source FROM lstr_categories');
            $stmt->execute();
            $map = [];
            foreach ($stmt->fetchAll() as $row) {
                $nameLower = strtolower((string) $row['name']);
                $source    = (string) $row['source'];
                $network   = match ($source) { 'tfl' => 'TFL', 'tafl' => 'TAFL', default => 'Custom' };
                $map[$nameLower]                 = $network;
                $map[$source . ':' . $nameLower] = $network;
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

        if ($options->imageMode === 'auto') {
            $dirKey = match ($targetSubDir) {
                'affiliates' => 'affiliates_dir',
                'joined'     => 'joined_dir',
                default      => 'listings_dir',
            };
            $urlKey = match ($targetSubDir) {
                'affiliates' => 'affiliates_url',
                'joined'     => 'joined_url',
                default      => 'listings_url',
            };
            $configuredDir = $imageSettings[$dirKey] ?? '';
            $configuredUrl = $imageSettings[$urlKey] ?? '';

            $newFilename   = null;
            $triedPaths    = [];

            // Try server path first
            if ($configuredDir !== '') {
                $tryPath = $configuredDir . '/' . $imageFilename;
                $triedPaths[] = $tryPath;
                $newFilename  = $migrator->copyFromPath($tryPath, $targetSubDir, $prefix);
            } elseif ($options->imagePath !== '') {
                $tryPath = $options->imagePath . '/' . self::IMG_SUBDIRS[$targetSubDir] . '/' . $imageFilename;
                $triedPaths[] = $tryPath;
                $newFilename  = $migrator->copyFromPath($tryPath, $targetSubDir, $prefix);
            }

            // Fall back to URL
            if ($newFilename === null) {
                if ($configuredUrl !== '') {
                    $tryUrl       = $configuredUrl . '/' . rawurlencode($imageFilename);
                    $triedPaths[] = $tryUrl;
                    $newFilename  = $migrator->fetchFromUrl($tryUrl, $targetSubDir, $prefix);
                } elseif ($options->imageUrl !== '') {
                    $tryUrl       = $options->imageUrl . '/' . self::IMG_SUBDIRS[$targetSubDir] . '/' . rawurlencode($imageFilename);
                    $triedPaths[] = $tryUrl;
                    $newFilename  = $migrator->fetchFromUrl($tryUrl, $targetSubDir, $prefix);
                }
            }

            if ($newFilename !== null) {
                $copied++;
            } else {
                $skipped++;
                $tried = $triedPaths !== [] ? ' (tried: ' . implode(', ', $triedPaths) . ')' : ' (no path or URL configured)';
                $errors[] = "Image not copied: {$imageFilename}{$tried}";
            }
        } elseif ($options->imageMode === 'zip') {
            if ($this->activeZip !== null) {
                // Pre-uploaded ZIP: extract image inline during run().
                $newFilename = $migrator->copyFromZip($this->activeZip, $imageFilename, $targetSubDir, $prefix);
                if ($newFilename !== null) {
                    $copied++;
                } else {
                    $skipped++;
                    $errors[] = "Image not found in ZIP: {$imageFilename}";
                }
            } else {
                // No ZIP opened — skip (legacy deferred mode or ZIP could not be opened).
                $skipped++;
            }
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
            // Enthusiast unprefixed uses columns `setting`/`value`;
            // the enth_ variant uses `setting_name`/`setting_value`.
            $nameCol  = $schema === 'enthusiast_enth' ? 'setting_name' : 'setting';
            $valueCol = $schema === 'enthusiast_enth' ? 'setting_value' : 'value';
            $stmt = $source->prepare(
                "SELECT `{$nameCol}`, `{$valueCol}` FROM `{$table}`
                  WHERE `{$nameCol}` IN (
                      'affiliates_dir', 'joined_images_dir', 'owned_images_dir',
                      'affiliates_url', 'joined_images_url', 'owned_images_url',
                      'root_path_absolute', 'root_path_web',
                      'installation_path', 'collective_url'
                  )"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {
            return [];
        }

        // Derive a public URL equivalent for any dir path that lacks an explicit URL.
        // Enthusiast stores the server's absolute path but not always the public URL.
        // If we know the root absolute path and its web address, we can compute the URL.
        $rootAbs = rtrim((string) ($rows['root_path_absolute'] ?? ($rows['installation_path'] ?? '')), '/\\');
        $rootWeb = rtrim((string) ($rows['root_path_web']      ?? ($rows['collective_url']      ?? '')), '/');

        $deriveUrl = static function (string $dir) use ($rootAbs, $rootWeb): string {
            if ($dir === '' || $rootAbs === '' || $rootWeb === '') {
                return '';
            }
            // Normalise separators so Windows paths (backslash) also match.
            $normDir = str_replace('\\', '/', $dir);
            $normAbs = str_replace('\\', '/', $rootAbs);
            if (str_starts_with($normDir, $normAbs . '/')) {
                $relative = substr($normDir, strlen($normAbs) + 1);
                return $rootWeb . '/' . $relative;
            }
            return '';
        };

        $affiliatesDir = rtrim((string) ($rows['affiliates_dir']    ?? ''), '/\\');
        $joinedDir     = rtrim((string) ($rows['joined_images_dir'] ?? ''), '/\\');
        $listingsDir   = rtrim((string) ($rows['owned_images_dir']  ?? ''), '/\\');

        return [
            'affiliates_dir' => $affiliatesDir,
            'joined_dir'     => $joinedDir,
            'listings_dir'   => $listingsDir,
            'affiliates_url' => rtrim((string) ($rows['affiliates_url']    ?? ''), '/')
                ?: $deriveUrl($affiliatesDir),
            'joined_url'     => rtrim((string) ($rows['joined_images_url'] ?? ''), '/')
                ?: $deriveUrl($joinedDir),
            'listings_url'   => rtrim((string) ($rows['owned_images_url']  ?? ''), '/')
                ?: $deriveUrl($listingsDir),
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
