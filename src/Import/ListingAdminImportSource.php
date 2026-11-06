<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import adapter for Listing Admin (v3.x) databases. Creates owned
 listing rows and per-listing member tables in Luster from LA's
 central registry and member store. Also imports categories,
 affiliates, joined listings, updates (when the News Feed add-on
 is installed), wishlist, and KIM entries. Custom field values
 stored in LA's mExtra blob are parsed on a best-effort basis.
*/

declare(strict_types=1);

namespace Luster\Import;

use Luster\Addons\Kim\KimAddon;
use Luster\Addons\Rules\RulesAddon;
use Luster\Addons\Newsfeed\NewsfeedAddon;
use Luster\Addons\Wishlist\WishlistAddon;
use Luster\Support\RequiredColumns;
use PDO;

/**
 * Import adapter for Listing Admin (v3.x) databases.
 *
 * Creates owned listing rows and per-listing member tables in Luster from LA's
 * central `main` registry and `members` table. Also imports categories, affiliates,
 * joined listings, updates (requires the News Feed add-on), the owner's wishlist
 * (requires the Wishlist add-on), KIM fan-interest entries (requires the KIM
 * add-on), and per-listing join rules (requires the Rules add-on). Custom member
 * field values stored in LA's `mExtra` blob are parsed on a best-effort basis.
 * Image paths are read from the LA `options` table when available. For every
 * listing it creates or matches, it also saves the link the Listing Admin
 * compatibility bridge uses to find that listing (see writeBridgeRefs()).
 */
final class ListingAdminImportSource implements ImportSourceInterface
{
    private const CHUNK    = 500;
    private const SKIP_CAP = 500;

    private ?\ZipArchive $activeZip = null;

    /** Codes at or above this count get a "this may take a while" note in the preview. */
    private const CODES_SLOW_COUNT = 200;

    private const CODES_NEED_SOURCE_ID =
        'Codes were not imported: this Listing Admin install has no web address in its options, so Luster '
        . 'can\'t tell its codes apart from another install\'s. Enter a Source ID on the Options step '
        . '(for example: old-collective) and run the import again.';

    /** Folder that holds uploads/ — the real public/ folder unless a test points it elsewhere. */
    private readonly string $publicDir;

    public function __construct(?string $publicDir = null)
    {
        $this->publicDir = rtrim($publicDir ?? dirname(__DIR__, 2) . '/public', '/\\');
    }

    private const IMG_DIRS = [
        'affiliates' => 'images/affiliates',
        'joined'     => 'images/joined',
        'listings'   => 'images',
    ];

    /**
     * Guards every raw SQL interpolation of the admin-supplied table prefix
     * (`la_table_prefix`) against injection. Mirrors
     * FanaticallyImportSource::assertValidPrefix().
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
        if (self::detectInstalls($source) !== []) {
            return 'listing_admin_3x';
        }

        // Looser, name-only checks: the schema is only a label, so keep recognising the
        // layouts this always recognised, even without Listing Admin's columns.
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
    // Finding the Listing Admin tables
    // -----------------------------------------------------------------------

    /**
     * Finds Listing Admin installs in a database by their tables, to fill in the
     * Table Prefix on the Options step.
     *
     * Listing Admin names every table prefix . name (trex_members, trex_options …),
     * and its listings table is the prefix with every underscore removed (trex), as in
     * its own rats.inc.php. Each table ending in "members" suggests a prefix, which
     * counts only when the listings, members and options tables all exist with Listing
     * Admin's columns. A bare "members" next to "main" is the unprefixed layout Luster
     * has always supported, returned with prefix ''.
     *
     * Strict on purpose: it only fills in the field. A prefix the admin types is checked
     * by missingTables() instead, which a partial export can pass.
     *
     * @param list<string>|null $tableNames Original table names of an uploaded dump;
     *                                      null reads them from $pdo.
     * @param string            $tempPrefix The dump's temporary table prefix in $pdo;
     *                                      never part of a returned prefix.
     * @return list<array{prefix: string, registry: string, listings: int}>
     */
    public static function detectInstalls(PDO $pdo, ?array $tableNames = null, string $tempPrefix = ''): array
    {
        if ($tempPrefix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $tempPrefix)) {
            return [];
        }

        $names = $tableNames ?? self::listTables($pdo);
        $set   = array_flip($names);
        $found = [];

        foreach ($names as $name) {
            if (!str_ends_with($name, 'members')) {
                continue;
            }
            $prefix = substr($name, 0, -strlen('members'));
            if ($prefix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
                continue;
            }
            $registry = self::registryTable($prefix);
            if ($registry === '' || !isset($set[$registry], $set[$prefix . 'options'])) {
                continue;
            }
            if (!self::hasColumns($pdo, $tempPrefix . $registry, ['id', 'title', 'subject', 'dbtabl'])
                || !self::hasColumns($pdo, $tempPrefix . $prefix . 'members', ['mEmail', 'fNiq'])
                || !self::hasColumns($pdo, $tempPrefix . $prefix . 'options', ['name', 'text'])) {
                continue;
            }

            try {
                $listings = (int) $pdo->query("SELECT COUNT(*) FROM `{$tempPrefix}{$registry}`")->fetchColumn();
            } catch (\Throwable) {
                continue;
            }

            $found[$prefix] = ['prefix' => $prefix, 'registry' => $registry, 'listings' => $listings];
        }

        ksort($found, SORT_STRING);
        return array_values($found);
    }

    /**
     * Tables the ticked steps need that the source doesn't have, each with the step
     * that needs it, by their real Listing Admin names (never an upload's temporary
     * prefix). Empty means the chosen prefix will do. Used on the Options step and by
     * preview() and run(), so all three agree.
     *
     * A step needs only the table it reads its rows from; Codes also needs the listings
     * table, since per-listing codes are matched to listings through it. Listing Admin's
     * installer always creates all of these, so a full install passes with every step
     * ticked. The codes, KIM and updates tables only exist once that add-on was switched
     * on in Listing Admin, so they never block: those steps say when their table is
     * missing. If none of the tables the ticked steps read exist, the prefix itself is
     * wrong, so all of them are reported.
     *
     * Only table names are checked, not columns, so an unusual Listing Admin version or
     * a partial export can still be imported with a typed prefix.
     *
     * @return list<array{table: string, step: string}>
     */
    public function missingTables(PDO $source, ImportOptions $options): array
    {
        $laP = $options->laTablePrefix;
        if ($laP !== '') {
            self::assertValidPrefix($laP);
        }
        $reg = self::registryTable($laP);

        // [ticked, step label as on the Options step, tables it needs, tables it may lack]
        $steps = [
            [$options->importCategories, 'Categories',      [$laP . 'categories'], []],
            [$options->importListings,   'Owned listings',  [$reg],                []],
            [$options->importMembers,    'Members',         [$laP . 'members'],    []],
            [$options->importAffiliates, 'Affiliates',      [$laP . 'affiliates'], []],
            [$options->importJoined,     'Joined listings', [$laP . 'joined'],     []],
            [$options->importWishlist,   'Wishlist',        [$laP . 'wishlist'],   []],
            [$options->importRules,      'Join Rules',      [$reg],                []],
            [$options->importCodes,      'Codes',           [$reg],                [$laP . 'codes']],
            [$options->importUpdates,    'Updates',         [],                    [$laP . 'updates']],
            [$options->importKim,        'KIM List',        [],                    [$laP . 'kim']],
        ];

        $existing = array_flip(self::listTables($source));
        $tp       = $options->tablePrefix;
        $missing  = [];
        $read     = [];
        $anyFound = false;

        foreach ($steps as [$ticked, $step, $needs, $mayLack]) {
            if (!$ticked) {
                continue;
            }
            foreach ($needs as $table) {
                $read[] = ['table' => $table, 'step' => $step];
                if (isset($existing[$tp . $table])) {
                    $anyFound = true;
                } else {
                    $missing[] = ['table' => $table, 'step' => $step];
                }
            }
            foreach ($mayLack as $table) {
                $read[] = ['table' => $table, 'step' => $step];
                if (isset($existing[$tp . $table])) {
                    $anyFound = true;
                }
            }
        }

        if ($read === []) {
            return [];
        }
        return $anyFound ? $missing : $read;
    }

    /**
     * The message for missingTables()' result, one entry per table with every step
     * that needs it.
     *
     * @param list<array{table: string, step: string}> $missing
     */
    public static function missingTablesMessage(array $missing, string $laPrefix): string
    {
        $byTable = [];
        foreach ($missing as $m) {
            $byTable[$m['table']][$m['step']] = true;
        }

        $parts = [];
        foreach ($byTable as $table => $steps) {
            $parts[] = $table . ' (for ' . implode(', ', array_keys($steps)) . ')';
        }

        $where = $laPrefix !== '' ? "starting with \"{$laPrefix}\"" : 'with no table prefix';
        $fix   = $laPrefix !== ''
            ? 'Check the prefix'
            : 'Enter the start of your Listing Admin table names as the Table Prefix (for example trex_)';

        return "Luster couldn't find these Listing Admin tables {$where}: " . implode(', ', $parts)
            . ". {$fix}, or untick those steps if your export doesn't include them.";
    }

    /** Listing Admin's listings table for a prefix: the prefix without underscores, or "main" for none. */
    private static function registryTable(string $laPrefix): string
    {
        return $laPrefix !== '' ? str_replace('_', '', $laPrefix) : 'main';
    }

    /** @return list<string> Every table in $pdo's database (MySQL or SQLite); [] if they can't be listed. */
    private static function listTables(PDO $pdo): array
    {
        try {
            $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? "SELECT name FROM sqlite_master WHERE type='table'"
                : 'SHOW TABLES';
            return array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Whether $table has every column in $columns, ignoring case as MySQL does.
     *
     * @param list<string> $columns
     */
    private static function hasColumns(PDO $pdo, string $table, array $columns): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $have = array_column($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            } else {
                $stmt = $pdo->prepare(
                    'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
                );
                $stmt->execute(['table' => $table]);
                $have = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        } catch (\Throwable) {
            return false;
        }

        $have = array_flip(array_map('strtolower', array_map('strval', $have)));
        foreach ($columns as $column) {
            if (!isset($have[strtolower($column)])) {
                return false;
            }
        }
        return true;
    }

    /** Whether $table exists in $pdo's database (MySQL or SQLite). */
    private static function tableExists(PDO $pdo, string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        try {
            $stmt = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = :table")
                : $pdo->prepare(
                    'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
                );
            $stmt->execute(['table' => $table]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Says a Listing Admin table wasn't found, by its real name, without guessing why:
     * the add-on may never have been switched on, or an export may have left it out.
     */
    private static function tableNotFoundNote(ImportOptions $options, string $what, string $name): string
    {
        $note = "No Listing Admin {$what} table named \"{$options->laTablePrefix}{$name}\" was found.";
        if ($options->tablePrefix !== '') {
            $note .= ' If you uploaded an SQL file, check that the export includes it.';
        }
        return $note;
    }

    // -----------------------------------------------------------------------
    // Preview (read-only)
    // -----------------------------------------------------------------------

    public function preview(PDO $source, PDO $target, ImportOptions $options): ImportPreview
    {
        $schema = $options->schemaVariant !== '' ? $options->schemaVariant : $this->detectSchema($source);
        $tp     = $options->tablePrefix;
        $laP    = $options->laTablePrefix;
        if ($laP !== '') {
            self::assertValidPrefix($laP);
        }
        // Registry table: the prefix without underscores (e.g. 'trex_' → 'trex'), as
        // Listing Admin names it. No prefix uses 'main'.
        $laReg  = self::registryTable($laP);
        $sp     = $tp . $laP;    // full source prefix for non-registry tables
        $sreg   = $tp . $laReg;  // full source registry table name
        $rows   = [];

        // Same check as the Options step: a wrong prefix shows why, not a page of zeros.
        $missing = $this->missingTables($source, $options);
        if ($missing !== []) {
            throw new \RuntimeException(self::missingTablesMessage($missing, $laP));
        }

        // Resolved before anything else so a bad or unclear Source ID stops the preview up front.
        $laSource = $this->laSourceKey($source, $target, $sp, $options);

        // Add-on availability (News Feed, Wishlist, KIM, Rules) must be checked against
        // the Luster target DB, not the foreign LA source.
        $addonCheckPdo = $target;

        if ($options->importCategories) {
            $rows[] = $this->previewTable($source, $sp . 'categories', 'Categories');
        }
        if ($options->importListings) {
            $rows[] = $this->previewTable($source, $sreg, 'Owned Listings', [
                self::bridgeLinksPreviewNote($laSource['key'], $laSource['origin']),
            ]);
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
            if (!self::tableExists($source, $sp . 'updates')) {
                $rows[] = ['type' => 'Updates', 'found' => 0, 'import' => 0, 'skip' => 0,
                           'warnings' => [self::tableNotFoundNote($options, 'updates', 'updates')]];
            } else {
                $updatesWarns = ['LA crossposting fields (uDW, uIJ, uLJ) will be dropped.'];
                if (!$this->updatesAddonInstalled($addonCheckPdo)) {
                    $updatesWarns[] = 'News Feed add-on is not installed — running the import will install it automatically so this data is not lost.';
                }
                $rows[] = $this->previewTable($source, $sp . 'updates', 'Updates', $updatesWarns);
            }
        }
        if ($options->importWishlist) {
            $rows[] = $this->previewWishlist($source, $addonCheckPdo, $sp);
        }
        if ($options->importKim) {
            $rows[] = $this->previewKim($source, $addonCheckPdo, $sp, $options);
        }
        if ($options->importRules) {
            $rows[] = $this->previewRules($source, $addonCheckPdo, $sreg);
        }
        if ($options->importCodes) {
            $rows[] = $this->previewCodes($source, $target, $options, $sp, $sreg, $laSource['key']);
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
        if ($laP !== '') {
            self::assertValidPrefix($laP);
        }
        $laReg  = self::registryTable($laP);
        $sp     = $tp . $laP;    // full source prefix for non-registry tables
        $sreg   = $tp . $laReg;  // full source registry table name
        $rows   = [];
        $imageRows = [];

        // Same check as the Options step and preview(), before anything is written.
        $missing = $this->missingTables($source, $options);
        if ($missing !== []) {
            throw new \RuntimeException(self::missingTablesMessage($missing, $laP));
        }

        // Resolved before any writes so a bad or unclear Source ID can't leave a half-run import.
        $laSource = $this->laSourceKey($source, $target, $sp, $options);

        // Detect Luster's target schema (owned vs enth_fanlistings)
        $targetSchema = $this->detectTargetSchema($target);

        // Image migrator
        $migrator = $options->imageMode !== ''
            ? new ImageMigrator($this->publicDir)
            : null;

        // Open a pre-uploaded ZIP archive.
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
        // In dump mode $tp holds the temp-table prefix, so the options table lives at
        // "{$tp}{$laP}options" — pass $sp (= $tp.$laP) so readImageSettings() finds it.
        $imageSettings = $migrator !== null
            ? $this->readImageSettings($source, $sp)
            : [];

        $imageMap = [];

        // Source category ID→name map for resolving pipe-delimited category IDs
        $srcCatIdToName = $this->buildSourceCategoryMap($source, $sp);

        // --- Step 1: Categories
        if ($options->importCategories) {
            $rows[] = $this->runCategories($source, $target, $options, $sp);
        }

        // Category name→id and name→network maps for downstream use
        $catNameToId      = $this->buildCategoryNameMap($target);
        $catNameToNetwork = $this->buildCategoryNetworkMap($target);

        // --- Step 2: Listings + member tables
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

            // Links the Listing Admin bridge uses to find each listing from LA's $listid.
            if (!empty($listingIdMap)) {
                $rows[] = $this->writeBridgeRefs($target, $listingIdMap, $laSource['key']);
            }
        }

        // --- Step 3: Members
        if ($options->importMembers && !empty($listingIdMap)) {
            $rows[] = $this->runMembers($source, $target, $targetSchema, $options, $listingIdMap, $sp, $sreg);
        }

        // --- Step 4: Affiliates
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

        // --- Step 5: Joined listings
        if ($options->importJoined) {
            ['row' => $joinRow, 'imageMap' => $joinMap] =
                $this->runJoined($source, $target, $options, $catNameToId, $catNameToNetwork, $srcCatIdToName, $migrator, $imageSettings, $sp);
            $rows[]   = $joinRow;
            $imageMap = array_merge($imageMap, $joinMap);
            if ($joinRow['image_copied'] > 0 || $joinRow['image_skipped'] > 0) {
                $imageRows[] = ['type' => 'Images (joined)', 'copied' => $joinRow['image_copied'],
                                'skipped' => $joinRow['image_skipped'], 'errors' => $joinRow['image_errors']];
            }
        }

        // --- Step 6: Updates
        if ($options->importUpdates) {
            $rows[] = $this->runUpdates($source, $target, $options, $listingIdMap, $sp);
        }

        // --- Step 7: Wishlist (owner's personal wishlist)
        if ($options->importWishlist) {
            $rows[] = $this->runWishlist($source, $target, $options, $migrator, $imageSettings, $sp);
        }

        // --- Step 8: KIM List (fan adoption interest)
        if ($options->importKim) {
            $rows[] = $this->runKim($source, $target, $options, $listingIdMap, $sp);
        }

        // --- Step 9: Join Rules
        if ($options->importRules) {
            $rows[] = $this->runRules($source, $target, $listingIdMap, $sreg);
        }

        // --- Step 10: Codes (fan graphics)
        if ($options->importCodes) {
            $codesListingMap = $listingIdMap;
            if (!$options->importListings) {
                // Codes only: use listings already in Luster, matched by URL the same
                // way the listings step would, and give the bridge links for them too.
                $codesListingMap = self::existingListingMap(
                    $this->planListings($source, $target, $targetSchema, $sreg)
                );
                if (!empty($codesListingMap)) {
                    $rows[] = $this->writeBridgeRefs($target, $codesListingMap, $laSource['key']);
                }
            }

            ['row' => $codesRow, 'images' => $codesImages] = $this->runCodes(
                $source, $target, $options, $sp, $codesListingMap, $laSource['key'], $migrator, $imageSettings
            );
            $rows[] = $codesRow;
            if ($codesImages['copied'] > 0 || $codesImages['skipped'] > 0) {
                $imageRows[] = ['type' => 'Images (codes)', 'copied' => $codesImages['copied'],
                                'skipped' => $codesImages['skipped'], 'errors' => $codesImages['errors']];
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
        $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $stmt = $target->prepare(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='lstr_newsfeed'"
                );
                $stmt->execute();
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $target->query("SHOW TABLES LIKE 'lstr_newsfeed'");
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

        // Load existing categories: source-aware map ("source:lowercase_name" → id)
        // plus "any:lowercase_name" entries for plain-name backward compat.
        $existStmt = $target->prepare('SELECT id, name, source FROM lstr_categories');
        $existStmt->execute();
        $existingNames = [];
        foreach ($existStmt->fetchAll() as $er) {
            $existingNames[(string) $er['source'] . ':' . strtolower((string) $er['name'])] = (int) $er['id'];
            $existingNames['any:' . strtolower((string) $er['name'])]                       = (int) $er['id'];
        }

        $srcIdToNewId = [];

        // Track newly-inserted target id → source ('custom'/'tfl'/'tafl'). Existing
        // matched categories are also recorded here (their source may have been
        // updated on overwrite), so they remain valid 'custom' parents below.
        $targetIdToSource = [];

        // Track ids actually INSERTed in this run. Only these may have their
        // parent_id mutated below — pre-existing categories are never re-parented,
        // even though they may still serve as parents for newly-created children.
        $newlyCreatedIds = [];

        $insertStmt   = $target->prepare(
            'INSERT INTO lstr_categories (name, slug, source) VALUES (:name, :slug, :source)'
        );

        $seenSlugs = [];
        try {
            foreach ($target->query("SELECT source, slug FROM lstr_categories WHERE slug != ''")->fetchAll() as $sr) {
                $seenSlugs[(string) $sr['source']][(string) $sr['slug']] = true;
            }
        } catch (\Throwable) {}

        foreach ($srcRows as $row) {
            $name = trim((string) ($row['catname'] ?? ''));
            if ($name === '') {
                continue;
            }

            [$name, $catSource] = \Luster\Support\CategorySeedCatalog::normalizeImportName($name);

            $existKey = $catSource . ':' . strtolower($name);
            if (isset($existingNames[$existKey])) {
                $existId = $existingNames[$existKey];
                $srcIdToNewId[(int) $row['catid']] = $existId;
                // Existing categories matched by (source, name) keep (skip) or are
                // updated to (overwrite) this $catSource — record it so the
                // parent-link pass below can treat them as valid 'custom'
                // parents/children alongside categories created in this run.
                $targetIdToSource[$existId] = $catSource;
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                    if (count($skippedItems) < self::SKIP_CAP) {
                        $skippedItems[] = ['label' => $name, 'reason' => 'Already exists in Luster'];
                    }
                    continue;
                }
                // overwrite: update source if it changed
                try {
                    $target->prepare('UPDATE lstr_categories SET source = :source WHERE id = :id')
                           ->execute(['source' => $catSource, 'id' => $existId]);
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Category '{$name}': " . $e->getMessage();
                }
                continue;
            }

            $baseSlug = \Luster\Repositories\CategoryRepo::slugify($name);
            $slug     = $baseSlug;
            $n = 2;
            while (isset($seenSlugs[$catSource][$slug])) {
                $slug = $baseSlug . '-' . $n++;
            }
            $seenSlugs[$catSource][$slug] = true;

            try {
                $insertStmt->execute(['name' => $name, 'slug' => $slug, 'source' => $catSource]);
                $newId = (int) $target->lastInsertId();
                $srcIdToNewId[(int) $row['catid']]         = $newId;
                $targetIdToSource[$newId]                   = $catSource;
                $newlyCreatedIds[$newId]                    = true;
                $existingNames[$existKey]                   = $newId;
                $existingNames['any:' . strtolower($name)]  = $newId;
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Category '{$name}': " . $e->getMessage();
            }
        }

        // Link newly-created "custom" categories into a one-level parent/child
        // hierarchy (lstr_categories.parent_id, Migration 56) when the source data
        // defines a parent relationship between two categories created in this run.
        // Pre-existing/seeded categories are never re-parented.
        if ($srcIdToNewId !== []) {
            $targetParentId = [];
            try {
                $ids          = array_values(array_unique($srcIdToNewId));
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $pStmt        = $target->prepare("SELECT id, parent_id FROM lstr_categories WHERE id IN ({$placeholders})");
                $pStmt->execute($ids);
                foreach ($pStmt->fetchAll() as $pRow) {
                    $targetParentId[(int) $pRow['id']] = $pRow['parent_id'] !== null ? (int) $pRow['parent_id'] : null;
                }
            } catch (\Throwable) {}

            $linkStmt = $target->prepare("UPDATE lstr_categories SET parent_id = :pid WHERE id = :id AND source = 'custom'");
            foreach ($srcRows as $row) {
                $parentSrcId = (int) ($row['parent'] ?? 0);
                if ($parentSrcId === 0) {
                    continue;
                }

                $childId  = $srcIdToNewId[(int) $row['catid']] ?? null;
                $parentId = $srcIdToNewId[$parentSrcId] ?? null;
                if ($childId === null || $parentId === null) {
                    continue;
                }
                if (!isset($newlyCreatedIds[$childId])) {
                    continue;
                }
                if (($targetIdToSource[$childId] ?? null) !== 'custom' || ($targetIdToSource[$parentId] ?? null) !== 'custom') {
                    continue;
                }
                if (($targetParentId[$parentId] ?? null) !== null) {
                    continue;
                }

                try {
                    $linkStmt->execute(['pid' => $parentId, 'id' => $childId]);
                    $targetParentId[$childId] = $parentId;
                } catch (\Throwable) {}
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
                    $existingId = (int) $existId;
                    $idMap[$laId] = $existingId;
                    // Ensure a settings row exists even for skipped listings — it may be absent
                    // if the listing row existed but settings were never created (e.g. manual add
                    // before the first import).  ensureListingSettings() is a no-op when present.
                    $luSkip = $this->newestMemberDate($source, $tp, $laId);
                    $this->ensureListingSettings($target, $existingId, $row, $luSkip);
                    // Re-run: if the settings row for this listing has image = NULL, retry.
                    if ($migrator !== null && $imgFilename !== '' && $options->imageMode !== 'zip') {
                        $rStmt = $target->prepare(
                            'SELECT image FROM lstr_fanlisting_settings WHERE fanlisting_id = :fid LIMIT 1'
                        );
                        $rStmt->execute(['fid' => $existingId]);
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
                                )->execute(['img' => $retried, 'fid' => $existingId]);
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

            // Map LA's trouble/upcoming flags — same convention as Enthusiast:
            //   trouble=1 → status 0 (trouble), upcoming=1 → status 1 (upcoming), else 2 (active).
            $trouble  = (int) ($row['trouble']  ?? 0);
            $upcoming = (int) ($row['upcoming'] ?? 0);
            $status   = $trouble ? 0 : ($upcoming ? 1 : 2);

            // Opened date — main.since is the real column; fall back to main.opened for schema variants.
            $opened = self::normalizeImportDate($row['since'] ?? ($row['opened'] ?? null));

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

            // Format field names for the listing registry's additional column.
            // Owned (unprefixed) schema: comma-separated bare names, e.g. "fave_song,fave_book".
            // enth_ schema: pipe-separated bare names; FieldRepo synthesises labels from each segment.
            $additional = $isOwned
                ? implode(',', $faveFields)
                : implode('|', $faveFields);

            try {
                if ($existId !== false) {
                    // Overwrite: update existing listing
                    $newListingId = (int) $existId;
                    $this->updateListing($target, $regTable, $idCol, $newListingId,
                        $title, $subject, $url, $dbtable, $catId, $opened, $isOwned, $status, $newImage, $additional);
                    $skipped++;
                } else {
                    // Insert new listing row
                    $newListingId = $this->insertListing($target, $regTable, $idCol,
                        $title, $subject, $url, $dbtable, $catId, $opened, $isOwned, $status, $newImage, $additional);
                    $imported++;
                }

                $idMap[$laId] = $newListingId;

                // Create per-listing member table (DDL is idempotent via CREATE TABLE IF NOT EXISTS)
                $memberTable = $isOwned ? $dbtable : $dbtable . '_members';
                $this->createMemberTable($target, $memberTable, $faveFields);

                // Derive last_updated: prefer main.updated, fall back to MAX(member mAdd).
                // Never invent today when no real source date exists.
                $lastUpdated = self::normalizeImportDate($row['updated'] ?? null)
                    ?? $this->newestMemberDate($source, $tp, $laId);

                // Create or update the lstr_fanlisting_settings row. On overwrite of an
                // existing listing, re-apply main.desc, form URL overrides, and LA templates.
                $this->ensureListingSettings($target, $newListingId, $row, $lastUpdated, $existId !== false);

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
                    // No choice in the source means nobody agreed to show it.
                    'showemail' => (int) ($row['mVisible'] ?? 0),
                    'showurl'   => 1,
                    // A missing, zero or impossible join date stays blank: it's unknown,
                    // and "today" would make every such member look like the newest one.
                    'added'     => self::normalizeImportDate($row['mAdd'] ?? null),
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
        array $catNameToId, array $catNameToNetwork, array $srcCatIdToName,
        ?ImageMigrator $migrator, array $imageSettings, string $tp = ''
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
            $catJsonEntries = [];
            $firstCatName   = '';
            $newCatId       = null;
            foreach ($catNames as $rawCat) {
                [$normalizedName, $catSource] = \Luster\Support\CategorySeedCatalog::normalizeImportName($rawCat);
                if ($firstCatName === '') { $firstCatName = $normalizedName; }
                $network = match ($catSource) { 'tfl' => 'TFL', 'tafl' => 'TAFL', default => 'Custom' };
                $catJsonEntries[] = ['network' => $network, 'name' => $normalizedName];
                if ($newCatId === null) {
                    $idKey    = $catSource !== 'custom' ? $catSource . ':' . strtolower($normalizedName) : strtolower($normalizedName);
                    $newCatId = $catNameToId[$idKey] ?? $catNameToId[$normalizedName] ?? null;
                }
            }
            $categoriesJson = \Luster\Support\JoinedCategories::encode($catJsonEntries);
            // LA: jStatus=0 means current/active; jStatus=1 means pending.
            $active         = ((int) ($row['jStatus'] ?? 0)) === 0 ? 1 : 0;

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
                    'date_joined'     => self::normalizeImportDate($row['jAdd'] ?? null),
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
        if (!self::tableExists($source, $tp . 'updates')) {
            return ['type' => 'Updates', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [self::tableNotFoundNote($options, 'updates', 'updates')], 'errors' => [],
                    'skipped_items' => []];
        }

        if (!AddonAutoInstaller::ensureInstalled($target, NewsfeedAddon::class) && !$this->updatesAddonInstalled($target)) {
            return ['type' => 'Updates', 'imported' => 0, 'skipped' => 0,
                    'warnings' => ['News Feed add-on could not be installed automatically — updates were skipped.'], 'errors' => [],
                    'skipped_items' => []];
        }

        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_newsfeed (fanlisting_id, title, body, category, author, posted_at)
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
                $errors[] = "Couldn't read Listing Admin's updates ({$options->laTablePrefix}updates): " . $e->getMessage();
                break;
            }

            foreach ($chunk as $row) {
                $laListingId  = (int) ($row['fNiq'] ?? 0);
                $newListingId = $laListingId > 0 ? ($listingIdMap[$laListingId] ?? null) : null;

                // posted_at can't be empty, so an unusable date falls back to now.
                $postedAt = self::normalizeImportDateTime($row['uAdded'] ?? null) ?? date('Y-m-d H:i:s');

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
    // Run helpers — KIM List (wishlist)
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // Run helpers — KIM List (fan adoption interest, LA kim table)
    // -----------------------------------------------------------------------

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewKim(PDO $source, PDO $target, string $tp, ImportOptions $options): array
    {
        if (!self::tableExists($source, $tp . 'kim')) {
            return ['type' => 'KIM List', 'found' => 0, 'import' => 0, 'skip' => 0,
                    'warnings' => [self::tableNotFoundNote($options, 'KIM', 'kim')]];
        }

        $kimWarns = ['Fan adoption-interest entries import as pending KIM members.'];
        if (!$this->kimAddonInstalled($target)) {
            $kimWarns[] = 'Keep In Mind add-on is not installed — running the import will install it automatically so this data is not lost.';
        }
        return $this->previewTable($source, $tp . 'kim', 'KIM List', $kimWarns);
    }

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],skipped_items:array} */
    private function runKim(
        PDO $source, PDO $target, ImportOptions $options,
        array $listingIdMap, string $tp
    ): array {
        if (!self::tableExists($source, $tp . 'kim')) {
            return ['type' => 'KIM List', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [self::tableNotFoundNote($options, 'KIM', 'kim')],
                    'errors' => [], 'skipped_items' => []];
        }

        if (!AddonAutoInstaller::ensureInstalled($target, KimAddon::class) && !$this->kimAddonInstalled($target)) {
            return ['type' => 'KIM List', 'imported' => 0, 'skipped' => 0,
                    'warnings' => ['Keep In Mind add-on could not be installed automatically — KIM entries were skipped.'],
                    'errors' => [], 'skipped_items' => []];
        }

        try {
            $stmt    = $source->query("SELECT * FROM `{$tp}kim` ORDER BY mID ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return ['type' => 'KIM List', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [], 'errors' => ["Couldn't read Listing Admin's KIM list ({$options->laTablePrefix}kim): " . $e->getMessage()],
                    'skipped_items' => []];
        }

        // Conflict detection: composite key = "{fanlisting_id}|{email}"
        $existingPairs = [];
        try {
            $existStmt = $target->query('SELECT fanlisting_id, email FROM lstr_kim_members');
            foreach ($existStmt->fetchAll() as $r) {
                $existingPairs[(int) $r['fanlisting_id'] . '|' . strtolower((string) $r['email'])] = true;
            }
        } catch (\Throwable) {}

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_kim_members
               (fanlisting_id, name, email, url, password, pending, added_at)
             VALUES (:fanlisting_id, :name, :email, :url, :password, :pending, :added_at)'
        );
        $updateStmt = $target->prepare(
            'UPDATE lstr_kim_members SET name = :name, url = :url
             WHERE fanlisting_id = :fid AND email = :email'
        );

        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $unlinked     = 0;

        foreach ($srcRows as $row) {
            $email = strtolower(trim((string) ($row['mEmail'] ?? '')));
            if ($email === '') {
                continue;
            }

            $fNiq  = (string) ($row['fNiq'] ?? '0');
            $flIds = $this->parseFNiq($fNiq, $listingIdMap);

            foreach ($flIds as $flId) {
                if ($flId === null) {
                    $unlinked++;
                    continue;
                }

                $pairKey = $flId . '|' . $email;

                if (isset($existingPairs[$pairKey])) {
                    if ($options->conflictStrategy === 'skip') {
                        $skipped++;
                        if (count($skippedItems) < self::SKIP_CAP) {
                            $skippedItems[] = ['label' => $email, 'reason' => 'Already exists'];
                        }
                        continue;
                    }
                    try {
                        $updateStmt->execute([
                            'name'  => trim((string) ($row['mName'] ?? '')),
                            'url'   => trim((string) ($row['mURL']  ?? '')),
                            'fid'   => $flId,
                            'email' => $email,
                        ]);
                        $skipped++;
                    } catch (\Throwable $e) {
                        $errors[] = "KIM {$email}: " . $e->getMessage();
                    }
                    continue;
                }

                try {
                    $insertStmt->execute([
                        'fanlisting_id' => $flId,
                        'name'          => trim((string) ($row['mName']     ?? '')),
                        'email'         => $email,
                        'url'           => trim((string) ($row['mURL']      ?? '')),
                        'password'      => (string) ($row['mPassword']      ?? ''),
                        'pending'       => (int)    ($row['mPending']       ?? 1),
                        // added_at can't be empty, so an unusable date falls back to today.
                        'added_at'      => self::normalizeImportDate($row['mAdd'] ?? null) ?? date('Y-m-d'),
                    ]);
                    $existingPairs[$pairKey] = true;
                    $imported++;
                } catch (\Throwable $e) {
                    $errors[] = "KIM {$email}: " . $e->getMessage();
                }
            }
        }

        $warnings = ['Imported entries preserve their pending/approved status from LA.',
                     'MD5-hashed passwords are kept as-is — Luster upgrades them to Argon2id on next login.'];
        if ($unlinked > 0) {
            $warnings[] = "{$unlinked} KIM entry/entries had no linked listing in this import and were skipped.";
        }

        return ['type' => 'KIM List', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => $warnings, 'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    private function kimAddonInstalled(PDO $target): bool
    {
        $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $stmt = $target->prepare(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='lstr_kim_members'"
                );
                $stmt->execute();
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $target->query("SHOW TABLES LIKE 'lstr_kim_members'");
            return $stmt && $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Run helpers — Join Rules (main.form_join_rules -> lstr_rules.body)
    // -----------------------------------------------------------------------

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewRules(PDO $source, PDO $target, string $sreg): array
    {
        $rulesWarns = ['Listings that already have join rules configured in Luster are left unchanged.'];
        if (!$this->rulesAddonInstalled($target)) {
            $rulesWarns[] = 'Join Rules add-on is not installed — running the import will install it automatically so this data is not lost.';
        }

        try {
            $stmt  = $source->query("SELECT COUNT(*) FROM `{$sreg}` WHERE form_join_rules IS NOT NULL AND TRIM(form_join_rules) != ''");
            $found = (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            $found = 0;
        }

        return ['type' => 'Join Rules', 'found' => $found, 'import' => $found, 'skip' => 0, 'warnings' => $rulesWarns];
    }

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],skipped_items:array} */
    private function runRules(PDO $source, PDO $target, array $listingIdMap, string $sreg): array
    {
        if (!AddonAutoInstaller::ensureInstalled($target, RulesAddon::class) && !$this->rulesAddonInstalled($target)) {
            return ['type' => 'Join Rules', 'imported' => 0, 'skipped' => 0,
                    'warnings' => ['Join Rules add-on could not be installed automatically — join rules were skipped.'],
                    'errors' => [], 'skipped_items' => []];
        }

        try {
            $stmt    = $source->query("SELECT id, form_join_rules FROM `{$sreg}`");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return ['type' => 'Join Rules', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [], 'errors' => ['Could not read join rules: ' . $e->getMessage()],
                    'skipped_items' => []];
        }

        $chkStmt = $target->prepare('SELECT id FROM lstr_rules WHERE fanlisting_id = :fid');
        $insStmt = $target->prepare('INSERT INTO lstr_rules (fanlisting_id, body) VALUES (:fid, :body)');

        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];

        foreach ($srcRows as $row) {
            $body = trim((string) ($row['form_join_rules'] ?? ''));
            if ($body === '') {
                continue;
            }

            $laId      = (int) ($row['id'] ?? 0);
            $listingId = $listingIdMap[$laId] ?? null;
            if ($listingId === null) {
                $skipped++;
                if (count($skippedItems) < self::SKIP_CAP) {
                    $skippedItems[] = ['label' => "LA listing #{$laId}", 'reason' => 'No matching imported listing'];
                }
                continue;
            }

            $chkStmt->execute(['fid' => $listingId]);
            if ($chkStmt->fetchColumn() !== false) {
                $skipped++;
                if (count($skippedItems) < self::SKIP_CAP) {
                    $skippedItems[] = ['label' => "Listing #{$listingId}", 'reason' => 'Already has join rules'];
                }
                continue;
            }

            try {
                $insStmt->execute(['fid' => $listingId, 'body' => $body]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Join rules for listing #{$listingId}: " . $e->getMessage();
            }
        }

        return ['type' => 'Join Rules', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => ['Imported rules are enabled by default — review wording and gate style from Admin → Join Rules.'],
                'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    private function rulesAddonInstalled(PDO $target): bool
    {
        $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $stmt = $target->prepare(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='lstr_rules'"
                );
                $stmt->execute();
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $target->query("SHOW TABLES LIKE 'lstr_rules'");
            return $stmt && $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    // -----------------------------------------------------------------------
    // Run helpers — Wishlist (owner's personal wishlist, LA wishlist table)
    // -----------------------------------------------------------------------

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewWishlist(PDO $source, PDO $target, string $tp): array
    {
        $wishlistWarns = ['Owner\'s fanlisting wishlist entries. wType values are mapped to Luster\'s wanted/top/granted types.'];
        if (!$this->wishlistAddonInstalled($target)) {
            $wishlistWarns[] = 'Wishlist add-on is not installed — running the import will install it automatically so this data is not lost.';
        }
        return $this->previewTable($source, $tp . 'wishlist', 'Wishlist', $wishlistWarns);
    }

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],skipped_items:array} */
    private function runWishlist(
        PDO $source, PDO $target, ImportOptions $options,
        ?ImageMigrator $migrator, array $imageSettings, string $tp
    ): array {
        if (!AddonAutoInstaller::ensureInstalled($target, WishlistAddon::class) && !$this->wishlistAddonInstalled($target)) {
            return ['type' => 'Wishlist', 'imported' => 0, 'skipped' => 0,
                    'warnings' => ['Wishlist add-on could not be installed automatically — wishlist entries were skipped.'],
                    'errors' => [], 'skipped_items' => []];
        }

        try {
            $stmt    = $source->query("SELECT * FROM `{$tp}wishlist` ORDER BY wID ASC");
            $srcRows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            return ['type' => 'Wishlist', 'imported' => 0, 'skipped' => 0,
                    'warnings' => [], 'errors' => ['Could not read wishlist table: ' . $e->getMessage()],
                    'skipped_items' => []];
        }

        // Conflict detection by URL
        $existingUrls = [];
        try {
            $existStmt = $target->query('SELECT url FROM lstr_wishlist');
            $existingUrls = array_flip($existStmt->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable) {}

        $insertStmt = $target->prepare(
            'INSERT INTO lstr_wishlist (subject, url, image, category, type, description, sort_order, active)
             VALUES (:subject, :url, :image, :category, :type, :description, 0, 1)'
        );
        $updateStmt = $target->prepare(
            'UPDATE lstr_wishlist SET subject = :subject, category = :category,
             type = :type, description = :description WHERE url = :url'
        );

        // LA wType → Luster type mapping
        $typeMap = ['custom' => 'wanted', 'granted' => 'granted', 'list' => 'wanted', 'top' => 'top'];

        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgSkipped   = 0;
        $imgErrors    = [];
        $imageMap     = []; // unused for wishlist but required by migrateImage() signature

        foreach ($srcRows as $row) {
            $url     = trim((string) ($row['wURL']     ?? ''));
            $subject = trim((string) ($row['wSubject'] ?? ''));
            if ($url === '') {
                continue;
            }

            $type        = $typeMap[trim((string) ($row['wType'] ?? ''))] ?? 'wanted';
            $category    = trim((string) ($row['wCategory'] ?? ''));
            $description = trim((string) ($row['wDesc']     ?? '')) ?: null;
            $imgFilename = trim((string) ($row['wImage']    ?? ''));

            // Migrate image if available
            $newImage = null;
            if ($imgFilename !== '' && $migrator !== null) {
                $newImage = $this->migrateImage(
                    $migrator, $imgFilename, 'wishlist', 'wl_',
                    $options, $imageSettings, $imageMap, $imgCopied, $imgSkipped, $imgErrors
                );
            }

            if (isset($existingUrls[$url])) {
                if ($options->conflictStrategy === 'skip') {
                    $skipped++;
                    if (count($skippedItems) < self::SKIP_CAP) {
                        $skippedItems[] = ['label' => ($subject ?: $url), 'reason' => 'Already exists'];
                    }
                    continue;
                }
                try {
                    $updateStmt->execute([
                        'subject'     => $subject,
                        'category'    => $category,
                        'type'        => $type,
                        'description' => $description,
                        'url'         => $url,
                    ]);
                    $skipped++;
                } catch (\Throwable $e) {
                    $errors[] = "Wishlist '{$url}': " . $e->getMessage();
                }
                continue;
            }

            try {
                $insertStmt->execute([
                    'subject'     => $subject,
                    'url'         => $url,
                    'image'       => $newImage,
                    'category'    => $category,
                    'type'        => $type,
                    'description' => $description,
                ]);
                $existingUrls[$url] = true;
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Wishlist '{$url}': " . $e->getMessage();
            }
        }

        return ['type' => 'Wishlist', 'imported' => $imported, 'skipped' => $skipped,
                'warnings' => [], 'errors' => $errors, 'skipped_items' => $skippedItems];
    }

    private function wishlistAddonInstalled(PDO $target): bool
    {
        $driver = $target->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $stmt = $target->prepare(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name='lstr_wishlist'"
                );
                $stmt->execute();
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $target->query("SHOW TABLES LIKE 'lstr_wishlist'");
            return $stmt && $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
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
              `email`     VARCHAR(255) NOT NULL,
              `name`      VARCHAR(128) NOT NULL DEFAULT '',
              `country`   VARCHAR(128) NOT NULL DEFAULT '',
              `url`       VARCHAR(500) NOT NULL DEFAULT '',
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
        ?int $catId, ?string $opened, bool $isOwned, int $status, ?string $image,
        string $additional = ''
    ): int {
        if ($isOwned) {
            $fill = RequiredColumns::forInsert($target, $regTable, [
                'title', 'subject', 'url', 'dbtable', 'catid', 'category_id', 'opened', 'status', 'imagefile',
                'email', 'dbserver', 'dbuser', 'dbpassword', 'dbdatabase', 'additional',
            ]);
            $stmt = $target->prepare(
                "INSERT INTO `{$regTable}`
                 (title, subject, url, dbtable, catid, category_id, opened, status, imagefile,
                  email, dbserver, dbuser, dbpassword, dbdatabase, additional{$fill['columns']})
                 VALUES (:title, :subject, :url, :dbtable, '', :catid, :opened, :status,
                         COALESCE(:image, ''), '', '', '', '', '', :additional{$fill['values']})"
            );
            $stmt->execute([
                'title'      => $title,
                'subject'    => $subject,
                'url'        => $url,
                'dbtable'    => $dbtable,
                'catid'      => $catId,
                'opened'     => $opened,
                'status'     => $status,
                'image'      => $image,
                'additional' => $additional,
            ]);
        } else {
            // enth_fanlistings schema: derive trouble/upcoming from the unified $status value.
            $enthTrouble  = $status === 0 ? 1 : 0;
            $enthUpcoming = $status === 1 ? 1 : 0;
            $fill = RequiredColumns::forInsert($target, $regTable, [
                'name', 'subject', 'url', 'dbprefix', 'category', 'category_id', 'opened', 'upcoming', 'trouble',
                'imagefile', 'email', 'dbserver', 'dbuser', 'dbpassword', 'dbdatabase', 'additional_info',
            ]);
            $stmt = $target->prepare(
                "INSERT INTO `{$regTable}`
                 (name, subject, url, dbprefix, category, category_id, opened, upcoming, trouble,
                  imagefile, email, dbserver, dbuser, dbpassword, dbdatabase, additional_info{$fill['columns']})
                 VALUES (:name, :subject, :url, :dbprefix, :catid, :catid2, :opened, :upcoming, :trouble,
                         :image, '', '', '', '', '', :additional{$fill['values']})"
            );
            $stmt->execute([
                'name'       => $title,
                'subject'    => $subject,
                'url'        => $url,
                'dbprefix'   => $dbtable . '_',
                'catid'      => $catId ?? 0,
                'catid2'     => $catId,
                'opened'     => $opened,
                'upcoming'   => $enthUpcoming,
                'trouble'    => $enthTrouble,
                'image'      => $image,
                'additional' => $additional,
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
        ?int $catId, ?string $opened, bool $isOwned, int $status, ?string $image,
        string $additional = ''
    ): void {
        if ($isOwned) {
            $stmt = $target->prepare(
                "UPDATE `{$regTable}`
                 SET title = :title, subject = :subject, dbtable = :dbtable,
                     catid = COALESCE(:catid, catid), opened = COALESCE(:opened, opened),
                     status = :status, imagefile = COALESCE(:image, imagefile),
                     additional = COALESCE(NULLIF(:additional, ''), additional)
                 WHERE {$idCol} = :id"
            );
            $stmt->execute([
                'title'      => $title,
                'subject'    => $subject,
                'dbtable'    => $dbtable,
                'catid'      => $catId,
                'opened'     => $opened,
                'status'     => $status,
                'image'      => $image,
                'additional' => $additional,
                'id'         => $listingId,
            ]);
        } else {
            $enthTrouble  = $status === 0 ? 1 : 0;
            $enthUpcoming = $status === 1 ? 1 : 0;
            $stmt = $target->prepare(
                "UPDATE `{$regTable}`
                 SET name = :name, subject = :subject, dbprefix = :dbprefix,
                     category = COALESCE(:catid, category), opened = COALESCE(:opened, opened),
                     upcoming = :upcoming, trouble = :trouble,
                     imagefile = COALESCE(:image, imagefile),
                     additional_info = COALESCE(NULLIF(:additional, ''), additional_info)
                 WHERE {$idCol} = :id"
            );
            $stmt->execute([
                'name'       => $title,
                'subject'    => $subject,
                'dbprefix'   => $dbtable . '_',
                'catid'      => $catId,
                'opened'     => $opened,
                'upcoming'   => $enthUpcoming,
                'trouble'    => $enthTrouble,
                'image'      => $image,
                'additional' => $additional,
                'id'         => $listingId,
            ]);
        }
    }

    /**
     * Creates the lstr_fanlisting_settings row for a newly imported listing, or — when
     * $overwrite is true and the row already exists — re-applies main.desc, form URL
     * overrides, and LA templates to it (blank-safe, mirroring
     * EnthusiastImportSource::runSettings()'s "exists && !skip" branch). When the row
     * already exists and $overwrite is false, only backfills last_updated if it is
     * still missing — existing customisations are left untouched.
     *
     * @param string|null $lastUpdated  Real source date (YYYY-MM-DD); null = leave column NULL.
     */
    private function ensureListingSettings(
        PDO $target, int $listingId, array $mainRow, ?string $lastUpdated = null, bool $overwrite = false
    ): void {
        $hasLastUpdated   = $this->tableHasColumn($target, 'lstr_fanlisting_settings', 'last_updated');
        $hasAffApply      = $this->tableHasColumn($target, 'lstr_fanlisting_settings', 'affiliate_apply_enabled');
        $hasAffSectionTpl = $this->tableHasColumn($target, 'lstr_fanlisting_settings', 'affiliates_section_template');

        $selectCols = 'fanlisting_id' . ($hasLastUpdated ? ', last_updated' : '');
        $chk = $target->prepare("SELECT {$selectCols} FROM lstr_fanlisting_settings WHERE fanlisting_id = :fid");
        $chk->execute(['fid' => $listingId]);
        $existing = $chk->fetch(\PDO::FETCH_ASSOC);

        $perPage = max(1, min(200, (int) ($mainRow['perpage'] ?? 25)));

        // main.members/stats/affiliates -> members_template/stats_template/affiliates_section_template
        $membersTpl    = self::translateLaMembersTemplate((string) ($mainRow['members'] ?? ''));
        $statsTpl      = self::translateLaStatsTemplate((string) ($mainRow['stats'] ?? ''));
        $affiliatesTpl = self::translateLaAffiliatesTemplate((string) ($mainRow['affiliates'] ?? ''));

        // main.desc -> description
        $description = html_entity_decode((string) ($mainRow['desc'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // main.form_join/form_update/form_reset -> form_url_join/update/reset overrides;
        // only when non-empty and different from LA's own default filename.
        $formUrlJoin   = self::normalizeFormUrlOverride((string) ($mainRow['form_join']   ?? ''), 'join.php');
        $formUrlUpdate = self::normalizeFormUrlOverride((string) ($mainRow['form_update'] ?? ''), 'update.php');
        $formUrlReset  = self::normalizeFormUrlOverride((string) ($mainRow['form_reset']  ?? ''), 'reset.php');

        // Wrap the LA affiliate row template in {each_affiliate}...{/each_affiliate} so it
        // becomes a section template, mirroring EnthusiastImportSource::runSettings().
        $sectionTpl = ($hasAffSectionTpl && $affiliatesTpl !== '')
            ? '{each_affiliate}' . $affiliatesTpl . '{/each_affiliate}'
            : '';

        if ($existing !== false) {
            if (!$overwrite) {
                // Skip-backfill: leave existing customisations untouched, only fill in
                // last_updated when the target row has none yet (image is backfilled
                // separately by the caller).
                if ($hasLastUpdated && $lastUpdated !== null
                    && ($existing['last_updated'] === null || $existing['last_updated'] === '')
                ) {
                    $target->prepare(
                        'UPDATE lstr_fanlisting_settings SET last_updated = :lu WHERE fanlisting_id = :fid'
                    )->execute(['lu' => $lastUpdated, 'fid' => $listingId]);
                }
                return;
            }

            // Overwrite: re-apply main.desc, form URL overrides, and LA templates.
            // COALESCE(NULLIF(...)) keeps the existing value when the LA source is blank.
            $assignments = 'members_per_page = :perpage,'
                . " members_template = COALESCE(NULLIF(:mtpl, ''), members_template),"
                . " stats_template = COALESCE(NULLIF(:stpl, ''), stats_template),"
                . " description = COALESCE(NULLIF(:description, ''), description),"
                . ' form_url_join = :form_url_join,'
                . ' form_url_update = :form_url_update,'
                . ' form_url_reset = :form_url_reset';
            $params = [
                'fid'             => $listingId,
                'perpage'         => $perPage,
                'mtpl'            => $membersTpl,
                'stpl'            => $statsTpl,
                'description'     => $description,
                'form_url_join'   => $formUrlJoin,
                'form_url_update' => $formUrlUpdate,
                'form_url_reset'  => $formUrlReset,
            ];
            if ($hasAffSectionTpl) {
                $assignments .= ", affiliates_section_template = COALESCE(NULLIF(:affiliates_section_template, ''), affiliates_section_template)";
                $params['affiliates_section_template'] = $sectionTpl;
            }
            if ($hasLastUpdated) {
                $assignments .= ', last_updated = COALESCE(:last_updated, last_updated)';
                $params['last_updated'] = $lastUpdated;
            }
            $target->prepare("UPDATE lstr_fanlisting_settings SET {$assignments} WHERE fanlisting_id = :fid")
                ->execute($params);
            return;
        }

        $luCol  = $hasLastUpdated   ? ', last_updated'                : '';
        $luVal  = $hasLastUpdated   ? ', :last_updated'               : '';
        $affCol = $hasAffApply      ? ', affiliate_apply_enabled'     : '';
        $affVal = $hasAffApply      ? ', 1'                          : '';
        $secCol = $hasAffSectionTpl ? ', affiliates_section_template' : '';
        $secVal = $hasAffSectionTpl ? ', :affiliates_section_template' : '';

        $ins = $target->prepare(
            "INSERT INTO lstr_fanlisting_settings
               (fanlisting_id, members_per_page, members_template, stats_template,
                description, form_url_join, form_url_update, form_url_reset{$luCol}{$affCol}{$secCol})
             VALUES (:fid, :perpage, :mtpl, :stpl,
                     :description, :form_url_join, :form_url_update, :form_url_reset{$luVal}{$affVal}{$secVal})"
        );
        $params = [
            'fid'             => $listingId,
            'perpage'         => $perPage,
            'mtpl'            => $membersTpl ?: null,
            'stpl'            => $statsTpl   ?: null,
            'description'     => $description ?: null,
            'form_url_join'   => $formUrlJoin,
            'form_url_update' => $formUrlUpdate,
            'form_url_reset'  => $formUrlReset,
        ];
        if ($hasLastUpdated) {
            $params['last_updated'] = $lastUpdated;
        }
        if ($hasAffSectionTpl) {
            $params['affiliates_section_template'] = $sectionTpl ?: null;
        }
        $ins->execute($params);
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
     * Stores two keys per row: plain name and source-keyed ("tfl:adult" → id)
     * for disambiguation of same-name TFL/TAFL categories.
     *
     * @return array<string,int>
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
     * Uses the `source` column as authoritative. Stores plain and source-keyed
     * entries for precise disambiguation of same-name TFL/TAFL categories.
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
     * The newest real join date among a Listing Admin listing's members (YYYY-MM-DD), or
     * null when there isn't one. Zero, sentinel and impossible dates (normalizeImportDate())
     * are passed over rather than taken as the newest, so a bad row can neither reach
     * last_updated nor hide the real newest date behind it.
     */
    private function newestMemberDate(PDO $source, string $tp, int $laId): ?string
    {
        try {
            $stmt = $source->prepare(
                "SELECT DISTINCT mAdd FROM `{$tp}members`
                  WHERE fNiq = :laId AND mAdd IS NOT NULL
                  ORDER BY mAdd DESC"
            );
            $stmt->execute(['laId' => $laId]);
            while (($raw = $stmt->fetchColumn()) !== false) {
                $date = self::normalizeImportDate($raw);
                if ($date !== null) {
                    $stmt->closeCursor();
                    return $date;
                }
            }
        } catch (\Throwable) {
            // No members table (or no mAdd column): no date to offer.
        }
        return null;
    }

    /**
     * Returns a YYYY-MM-DD string from $raw, or null for empty/sentinel/invalid values.
     * Rejects '', '0000-00-00', '1970-01-01', anything not starting with YYYY-MM-DD,
     * and dates that don't exist (2024-02-30) — strict MySQL refuses those too.
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
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return substr($s, 0, 10);
        }
        return null;
    }

    /**
     * Returns '' when $value is empty or matches LA's own default filename for this
     * form (no override needed); otherwise returns the trimmed override value.
     */
    private static function normalizeFormUrlOverride(string $value, string $default): string
    {
        $value = trim($value);
        if ($value === '' || $value === $default) {
            return '';
        }
        return $value;
    }

    /**
     * Translates LA's `main.members` per-member row template into Luster's
     * members_template token syntax. Returns '' when the source value is empty or
     * byte-identical to LA's own default (Luster's native member loop is preferred).
     */
    private static function translateLaMembersTemplate(string $tpl): string
    {
        $trimmed = trim($tpl);
        if ($trimmed === '' || $trimmed === "<li>{name}<br />\n{email} &middot; {url}{fave_field}</li>") {
            return '';
        }

        return str_replace(
            ['{name}', '{email}', '{url}', '{fave_field}'],
            [
                '{fan_name}',
                '<a href="mailto:{fan_email}">{fan_email}</a>',
                '{if_fan_url}<a href="{fan_url}" target="_blank" rel="noopener noreferrer">URL &raquo;</a>{/if_fan_url}{if_no_fan_url}<del>URL</del>{/if_no_fan_url}',
                '',
            ],
            $trimmed
        );
    }

    /**
     * Translates LA's `main.stats` template into Luster's stats_template token
     * syntax. Returns '' when the source value is empty.
     */
    private static function translateLaStatsTemplate(string $tpl): string
    {
        $trimmed = trim($tpl);
        if ($trimmed === '') {
            return '';
        }

        return str_replace(
            ['{members}', '{pending}', '{since}', '{updated}', '{newest}'],
            ['{stat_approved}', '{stat_pending}', '{stat_opened}', '{stat_updated}', '{stat_newmembers}'],
            $trimmed
        );
    }

    /**
     * Translates LA's `main.affiliates` per-affiliate row template into Luster's
     * affiliate row token syntax (for wrapping in {each_affiliate}...{/each_affiliate}).
     * Returns '' when the source value is empty.
     */
    private static function translateLaAffiliatesTemplate(string $tpl): string
    {
        $trimmed = trim($tpl);
        if ($trimmed === '') {
            return '';
        }

        // LA's per-affiliate template typically embeds {image} inside an <img> src=""
        // attribute. Translate the entire <img> element to a guarded {if_aff_image}
        // block so affiliates without images get a text fallback.
        $trimmed = (string) preg_replace(
            '/<img\b[^>]*?src=["\'][^"\']*\{image\}[^"\']*["\'][^>]*?\/?>/is',
            '{if_aff_image}<img src="{aff_image_url}" alt="{aff_name}">{/if_aff_image}{if_no_aff_image}{aff_name}{/if_no_aff_image}',
            $trimmed,
        );

        return str_replace(
            ['{url}', '{image}', '{subject}'],
            ['{aff_url}', '{if_aff_image}<img src="{aff_image_url}" alt="{aff_name}">{/if_aff_image}{if_no_aff_image}{aff_name}{/if_no_aff_image}', '{aff_name}'],
            $trimmed
        );
    }

    /** Returns true when $column exists on $table in $pdo (MySQL + SQLite aware). */
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
     * Where an image is looked for, in order, each only if the one before found nothing
     * (blank ones are skipped):
     *   1. the folder saved in Listing Admin's options ($imageSettings['…_dir']);
     *   2. the Server Path typed on the Options step;
     *   3. the address saved in Listing Admin's options ($imageSettings['…_url']);
     *   4. the Live Site URL typed on the Options step.
     * The typed ones are used as the image folder itself (LA has no fixed structure), and
     * are also tried when Listing Admin's own setting no longer works — an old address
     * that now redirects somewhere unusable, or a folder on another server.
     *
     * @param array<string,string> $imageSettings  From readImageSettings(); may be empty.
     */
    private function migrateImage(
        ImageMigrator $migrator, string $imageFilename, string $targetSubDir, string $prefix,
        ImportOptions $options, array $imageSettings, array &$imageMap, int &$copied,
        int &$skipped, array &$errors
    ): ?string {
        if ($options->imageMode === 'auto') {
            $dirKey = match ($targetSubDir) {
                'affiliates' => 'affiliates_dir',
                'joined'     => 'joined_dir',
                'codes'      => 'codes_dir',
                default      => 'listings_dir',
            };
            $urlKey = match ($targetSubDir) {
                'affiliates' => 'affiliates_url',
                'joined'     => 'joined_url',
                'codes'      => 'codes_url',
                default      => 'listings_url',
            };
            $configuredDir = $imageSettings[$dirKey] ?? '';
            $configuredUrl = $imageSettings[$urlKey] ?? '';

            $newFilename = null;
            $dirs = array_unique(array_filter([$configuredDir, rtrim($options->imagePath, '/\\')], 'strlen'));
            $urls = array_unique(array_filter([$configuredUrl, rtrim($options->imageUrl, '/')], 'strlen'));

            // Folders first (LA has no fixed subdir — each is the image folder itself) …
            foreach ($dirs as $dir) {
                $newFilename = $migrator->copyFromPath($dir . '/' . $imageFilename, $targetSubDir, $prefix);
                if ($newFilename !== null) {
                    break;
                }
            }

            // … then web addresses.
            if ($newFilename === null) {
                foreach ($urls as $url) {
                    $newFilename = $migrator->fetchFromUrl($url . '/' . rawurlencode($imageFilename), $targetSubDir, $prefix);
                    if ($newFilename !== null) {
                        break;
                    }
                }
            }

            if ($newFilename !== null) {
                $copied++;
            } else {
                $skipped++;
                $errors[] = "Image not copied: {$imageFilename}";
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

    // -----------------------------------------------------------------------
    // Bridge listing links (lstr_listing_external_refs)
    // -----------------------------------------------------------------------

    /**
     * Works out this LA install's source key from the Source ID the admin typed,
     * or from LA's own adm_http / my_website options. See ListingAdminSourceKey.
     *
     * Only the listings and codes steps use the key, so only they check which key
     * earlier imports of this install used (resolveFor()). Any other import skips
     * that read: nothing it writes depends on the key.
     *
     * @param  string $sp Full source prefix for non-registry tables ($tp . $laP).
     * @return array{key: ?string, origin: string}
     * @throws \RuntimeException When a typed-in Source ID isn't valid, when earlier
     *                           imports used more than one of this install's keys, or
     *                           when Luster's database couldn't be read to find out.
     */
    private function laSourceKey(PDO $source, PDO $target, string $sp, ImportOptions $options): array
    {
        $urls = ['adm_http' => '', 'my_website' => ''];
        try {
            $stmt = $source->prepare(
                "SELECT `name`, `text` FROM `{$sp}options` WHERE `name` IN ('adm_http', 'my_website')"
            );
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $name => $text) {
                $urls[(string) $name] = (string) $text;
            }
        } catch (\Throwable) {
            // No options table: fall through to "no web address" below.
        }

        // $options->laTablePrefix, not $sp: a dump's temporary table prefix changes on
        // every upload, which would give the same install a different key each time.
        if ($options->importListings || $options->importCodes) {
            return ListingAdminSourceKey::resolveFor(
                $target,
                $options->laSourceId,
                $urls['adm_http'],
                $urls['my_website'],
                $options->laTablePrefix,
            );
        }
        return ListingAdminSourceKey::resolve(
            $options->laSourceId,
            $urls['adm_http'],
            $urls['my_website'],
            $options->laTablePrefix,
        );
    }

    /** Preview note for the Owned Listings row: which bridge links will be saved. */
    private static function bridgeLinksPreviewNote(?string $key, string $origin): string
    {
        return match ($origin) {
            'override'   => "Bridge links will be saved under the Source ID you entered: {$key}.",
            'adm_http'   => "Bridge links will be saved under Source ID {$key}, worked out from this install's admin address.",
            'my_website' => "Bridge links will be saved under Source ID {$key}, worked out from this install's website address.",
            default      => "This install's options don't include its web address, so only the shared bridge links "
                . 'will be saved. That\'s fine with one Listing Admin install. If you have more than one, enter a '
                . 'Source ID on the Options step.',
        };
    }

    /**
     * Saves the links the Listing Admin bridge uses to turn LA's $listid into a
     * Luster listing, for every listing this import created or matched.
     *
     * Two links per listing:
     *   - 'listing-admin:<key>' — this install only. Always saved (replaced) when
     *     there's a key, since a key only ever belongs to one install.
     *   - 'listing-admin' — the shared one existing bridge copies look up. Only
     *     added if missing. If it already points to a different Luster listing
     *     (most likely another LA install's), it's left alone and reported, so
     *     the first install's bridge pages keep working.
     *
     * @param  array<int,int> $listingIdMap LA listing ID => Luster listing ID.
     * @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],skipped_items:array<array{label:string,reason:string}>}
     */
    private function writeBridgeRefs(PDO $target, array $listingIdMap, ?string $sourceKey): array
    {
        $imported     = 0;
        $skipped      = 0;
        $conflicts    = 0;
        $warnings     = [];
        $errors       = [];
        $skippedItems = [];

        try {
            $find = $target->prepare(
                'SELECT listing_id FROM lstr_listing_external_refs
                  WHERE source = :src AND external_id = :eid LIMIT 1'
            );
            $insert = $target->prepare(
                'INSERT INTO lstr_listing_external_refs (listing_id, source, external_id)
                 VALUES (:lid, :src, :eid)'
            );
            $replace = $sourceKey !== null
                ? $target->prepare(
                    'REPLACE INTO lstr_listing_external_refs (listing_id, source, external_id)
                     VALUES (:lid, :src, :eid)'
                )
                : null;
        } catch (\Throwable $e) {
            return [
                'type' => 'Bridge listing links', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                'errors' => ['Bridge links could not be saved (run the migration page, then import again): ' . $e->getMessage()],
                'skipped_items' => [],
            ];
        }

        foreach ($listingIdMap as $laId => $lusterId) {
            $eid = (string) $laId;
            try {
                if ($replace !== null) {
                    $replace->execute([
                        'lid' => $lusterId,
                        'src' => ListingAdminSourceKey::refSource($sourceKey),
                        'eid' => $eid,
                    ]);
                }

                $find->execute(['src' => ListingAdminSourceKey::GENERIC_SOURCE, 'eid' => $eid]);
                $existing = $find->fetchColumn();
                $find->closeCursor();

                if ($existing === false) {
                    $insert->execute([
                        'lid' => $lusterId,
                        'src' => ListingAdminSourceKey::GENERIC_SOURCE,
                        'eid' => $eid,
                    ]);
                    $imported++;
                    continue;
                }

                $skipped++;
                if ((int) $existing === (int) $lusterId) {
                    $reason = 'Already linked';
                } else {
                    $conflicts++;
                    $reason = 'Shared link already points to Luster listing #' . (int) $existing;
                }
                if (count($skippedItems) < self::SKIP_CAP) {
                    $skippedItems[] = [
                        'label'  => "Listing Admin listing #{$laId} → Luster listing #{$lusterId}",
                        'reason' => $reason,
                    ];
                }
            } catch (\Throwable $e) {
                $errors[] = "Listing Admin listing #{$laId}: " . $e->getMessage();
            }
        }

        if ($sourceKey !== null) {
            $warnings[] = "Source ID for this install: {$sourceKey}";
        } else {
            $warnings[] = 'No Source ID for this install, so only the shared bridge links were saved.';
        }

        if ($conflicts > 0) {
            $warnings[] = $sourceKey !== null
                ? "{$conflicts} listing(s) already had a shared bridge link to a different Luster listing, probably "
                    . 'from another Listing Admin install, so those were left alone. This install still got its own '
                    . "links: set LUSTER_LA_SOURCE to \"{$sourceKey}\" in this install's luster-bridge.php so its pages use them."
                : "{$conflicts} listing(s) already had a shared bridge link to a different Luster listing, probably "
                    . 'from another Listing Admin install, so those were left alone. Enter a Source ID on the Options '
                    . 'step and run the import again so this install gets its own links.';
        }

        return [
            'type'          => 'Bridge listing links',
            'imported'      => $imported,
            'skipped'       => $skipped,
            'warnings'      => $warnings,
            'errors'        => $errors,
            'skipped_items' => $skippedItems,
        ];
    }

    // -----------------------------------------------------------------------
    // Codes (fan graphics) — LA codes, codes_sizes, codes_donors, codes_categories
    // -----------------------------------------------------------------------

    /**
     * What would happen to each LA listing, using the same rules as runListings():
     * skipped without a URL or subject, matched to a Luster listing by exact URL,
     * otherwise created. Read-only. Used by the preview, and by a codes-only run
     * to find listings an earlier import brought in.
     *
     * @return array<int, array{action: string, id: ?int, title: string}>  LA listing ID =>
     *         action 'existing' (id set), 'create', or 'skip'.
     */
    private function planListings(PDO $source, PDO $target, string $targetSchema, string $sreg): array
    {
        [$regTable, $idCol] = $this->targetRegistryInfo($targetSchema);

        try {
            $srcRows = $source->query("SELECT * FROM `{$sreg}` ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
            $check   = $target->prepare("SELECT {$idCol} FROM `{$regTable}` WHERE url = :url LIMIT 1");
        } catch (\Throwable) {
            return [];
        }

        $plan = [];
        foreach ($srcRows as $row) {
            $laId    = (int) ($row['id'] ?? 0);
            $url     = trim((string) ($row['url'] ?? ''));
            $title   = trim((string) ($row['title'] ?? ($row['subject'] ?? '')));
            $subject = trim((string) ($row['subject'] ?? $title));

            if ($url === '' || $subject === '') {
                $plan[$laId] = ['action' => 'skip', 'id' => null, 'title' => $subject !== '' ? $subject : $url];
                continue;
            }

            $check->execute(['url' => $url]);
            $existing = $check->fetchColumn();
            $check->closeCursor();

            $plan[$laId] = $existing !== false
                ? ['action' => 'existing', 'id' => (int) $existing, 'title' => $subject]
                : ['action' => 'create',   'id' => null,            'title' => $subject];
        }
        return $plan;
    }

    /**
     * @param  array<int, array{action: string, id: ?int, title: string}> $plan From planListings().
     * @return array<int,int> LA listing ID => Luster listing ID, listings already in Luster only.
     */
    private static function existingListingMap(array $plan): array
    {
        $map = [];
        foreach ($plan as $laId => $step) {
            if ($step['action'] === 'existing' && $step['id'] !== null) {
                $map[$laId] = $step['id'];
            }
        }
        return $map;
    }

    /**
     * Preview row for codes, counted the same way runCodes() decides each code:
     * skipped when its listing won't be in Luster (listings this run creates
     * count when Owned listings is ticked); for codes this install already
     * imported, skipped under Keep existing and updated under Update matching;
     * new codes skipped when LA has no image file for them. Codes that will
     * only get a missing image filled in are mentioned separately.
     *
     * @return array{type:string,found:int,import:int,skip:int,warnings:string[]}
     */
    private function previewCodes(PDO $source, PDO $target, ImportOptions $options, string $sp, string $sreg, ?string $sourceKey): array
    {
        if (!self::tableExists($source, $sp . 'codes')) {
            return ['type' => 'Codes', 'found' => 0, 'import' => 0, 'skip' => 0, 'warnings' => [
                self::tableNotFoundNote($options, 'codes', 'codes'),
            ]];
        }
        try {
            $srcCodes = $source->query("SELECT cID, fNiq, cFile FROM `{$sp}codes`")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return ['type' => 'Codes', 'found' => 0, 'import' => 0, 'skip' => 0, 'warnings' => [
                "Couldn't read Listing Admin's codes ({$options->laTablePrefix}codes): " . $e->getMessage(),
            ]];
        }

        $found = count($srcCodes);
        if ($sourceKey === null) {
            return ['type' => 'Codes', 'found' => $found, 'import' => 0, 'skip' => $found, 'warnings' => [self::CODES_NEED_SOURCE_ID]];
        }

        // Codes this install already imported: import_ref => [filename, import_source_file].
        $refPrefix = ListingAdminSourceKey::refSource($sourceKey) . ':';
        try {
            $stmt = $target->prepare('SELECT import_ref, filename, import_source_file FROM lstr_codes WHERE import_ref LIKE :pattern');
            $stmt->execute(['pattern' => $refPrefix . '%']);
            $alreadyImported = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $alreadyImported[(string) $r['import_ref']] = [(string) $r['filename'], (string) ($r['import_source_file'] ?? '')];
            }
        } catch (\Throwable) {
            return ['type' => 'Codes', 'found' => $found, 'import' => 0, 'skip' => $found, 'warnings' => [
                'Codes can\'t be imported yet: run the migration page first (Luster needs Migration 104), then import again.',
            ]];
        }

        $overwrite = $options->conflictStrategy === 'overwrite';
        $plan      = $this->planListings($source, $target, $this->detectTargetSchema($target), $sreg);
        $counts    = ['new' => 0, 'update' => 0, 'updateCopy' => 0, 'kept' => 0, 'repair' => 0, 'noFile' => 0, 'noListing' => 0, 'collective' => 0];
        $missing   = [];

        foreach ($srcCodes as $code) {
            $fNiq  = (int) ($code['fNiq'] ?? 0);
            $cFile = trim((string) ($code['cFile'] ?? ''));

            if ($fNiq !== 0) {
                $action = $plan[$fNiq]['action'] ?? null;
                if ($action !== 'existing' && !($action === 'create' && $options->importListings)) {
                    $counts['noListing']++;
                    $missing[$plan[$fNiq]['title'] ?? "Listing Admin listing #{$fNiq}"] = true;
                    continue;
                }
            }

            $ref = $refPrefix . (int) $code['cID'];
            if (isset($alreadyImported[$ref])) {
                [$localFile, $sourceFile] = $alreadyImported[$ref];
                $imageMissing = !$this->codeImageIsPresent($localFile);
                if ($overwrite) {
                    $counts['update']++;
                    // Same rule importCode() uses to decide whether to copy the image again.
                    if ($cFile !== '' && ($imageMissing || $cFile !== $sourceFile)) {
                        $counts['updateCopy']++;
                    }
                } else {
                    $counts['kept']++;
                    if ($cFile !== '' && $imageMissing) {
                        $counts['repair']++;
                    }
                    continue;
                }
            } elseif ($cFile === '') {
                $counts['noFile']++;
                continue;
            } else {
                $counts['new']++;
            }

            if ($fNiq === 0) {
                $counts['collective']++;
            }
        }

        $import = $counts['new'] + $counts['update'];
        $skip   = $found - $import;

        $warnings = ["Codes will be saved under Source ID {$sourceKey}, so running the import again updates them instead of adding copies."];
        if ($counts['collective'] > 0) {
            $warnings[] = "{$counts['collective']} code(s) aren't tied to one listing and will go to your collective codes.";
        }
        if ($counts['update'] > 0) {
            $warnings[] = "{$counts['update']} code(s) were imported before and will be updated in place.";
        }
        if ($counts['kept'] > 0) {
            $warnings[] = "{$counts['kept']} code(s) were imported before and will be left as they are "
                . '(choose Update matching to refresh them).';
        }
        if ($counts['repair'] > 0) {
            $warnings[] = "{$counts['repair']} of those are missing their image, so Luster will try to copy it again.";
        }
        if ($counts['noListing'] > 0) {
            $titles = array_keys($missing);
            $warnings[] = "{$counts['noListing']} code(s) will be skipped because their listing isn't in Luster"
                . ($options->importListings ? '' : ' (tick Owned listings to bring those listings over too)')
                . ': ' . implode(', ', array_slice($titles, 0, 5)) . (count($titles) > 5 ? ', …' : '') . '.';
        }
        if ($counts['noFile'] > 0) {
            $warnings[] = "{$counts['noFile']} code(s) have no image file in Listing Admin and will be skipped.";
        }
        // Every image this run will try to copy: new codes, Keep-existing repairs, and
        // updated codes whose image is missing or changed in LA.
        $toCopy = $counts['new'] + $counts['repair'] + $counts['updateCopy'];
        if ($toCopy >= self::CODES_SLOW_COUNT && $options->imageMode !== 'zip') {
            $warnings[] = "That's {$toCopy} code images to copy. If they're on another server, each one is downloaded "
                . 'separately, which can take a while. Uploading a ZIP of your Listing Admin codes folder is quicker.';
        }

        return ['type' => 'Codes', 'found' => $found, 'import' => $import, 'skip' => $skip, 'warnings' => $warnings];
    }

    /**
     * Step 10 — imports LA codes into lstr_codes.
     *
     * Every code is saved with import_ref = 'listing-admin:<source key>:<cID>', so a
     * re-run finds it again: Keep existing leaves it alone (only filling in an image
     * that never arrived); Update matching updates it in place, keeping its ID and
     * position. Each code is saved in its own transaction, and new size/donor/
     * category IDs only go into the shared caches once that code has committed —
     * a rolled-back code can't leave the next one pointing at rows that no longer exist.
     *
     * @param  array<int,int> $listingMap LA listing ID => Luster listing ID.
     * @return array{row: array, images: array{copied:int,skipped:int,errors:string[]}}
     */
    private function runCodes(
        PDO $source, PDO $target, ImportOptions $options, string $sp,
        array $listingMap, ?string $sourceKey, ?ImageMigrator $migrator, array $imageSettings
    ): array {
        $row    = ['type' => 'Codes', 'imported' => 0, 'skipped' => 0, 'warnings' => [], 'errors' => [], 'skipped_items' => []];
        $images = ['copied' => 0, 'skipped' => 0, 'errors' => []];

        if (!self::tableExists($source, $sp . 'codes')) {
            $row['warnings'][] = self::tableNotFoundNote($options, 'codes', 'codes');
            return ['row' => $row, 'images' => $images];
        }
        try {
            $source->query("SELECT cID, fNiq, cFile FROM `{$sp}codes` LIMIT 1");
        } catch (\Throwable $e) {
            $row['errors'][] = "Couldn't read Listing Admin's codes ({$options->laTablePrefix}codes): " . $e->getMessage();
            return ['row' => $row, 'images' => $images];
        }
        if ($sourceKey === null) {
            $row['errors'][] = self::CODES_NEED_SOURCE_ID;
            return ['row' => $row, 'images' => $images];
        }
        try {
            $target->query('SELECT import_ref, import_source_file FROM lstr_codes LIMIT 1');
        } catch (\Throwable) {
            $row['errors'][] = 'Codes were not imported: run the migration page first (Luster needs Migration 104), then import again.';
            return ['row' => $row, 'images' => $images];
        }

        $lookups = [
            'sizes'  => $this->laCodeLookup($source, "SELECT sID AS k, sName AS name, sOrder AS sort FROM `{$sp}codes_sizes`"),
            'donors' => $this->laCodeLookup($source, "SELECT dID AS k, dName AS name, dEmail AS email, dURL AS url FROM `{$sp}codes_donors`"),
            'cats'   => $this->laCodeLookup($source, "SELECT catID AS k, catName AS name, catParent AS parent FROM `{$sp}codes_categories`"),
        ];
        $direction = strtoupper(trim($this->laOption($source, $sp, 'codes_order'))) === 'DESC' ? 'DESC' : 'ASC';

        // Shared across codes: committed lookups only (see the method docblock).
        $state = ['sizes' => [], 'donors' => [], 'cats' => [], 'nextSort' => [], 'catsAtRoot' => [], 'catsUnknown' => []];

        $offset = 0;
        do {
            try {
                $stmt = $source->prepare(
                    "SELECT cID, fNiq, cName, cFile, cCategory, cSize, cDonor, cPending, cAdded
                       FROM `{$sp}codes`
                      ORDER BY cAdded {$direction}, cID {$direction}
                      LIMIT :limit OFFSET :offset"
                );
                $stmt->bindValue(':limit',  self::CHUNK, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset,     PDO::PARAM_INT);
                $stmt->execute();
                $chunk = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $row['errors'][] = 'Could not read codes: ' . $e->getMessage();
                break;
            }

            foreach ($chunk as $code) {
                $fNiq = (int) ($code['fNiq'] ?? 0);
                // Only fNiq = 0 means collective. A listing that didn't match is skipped,
                // never quietly turned into a collective code.
                if ($fNiq !== 0 && !isset($listingMap[$fNiq])) {
                    $this->skipCode($row, $code, "No Luster listing for Listing Admin listing #{$fNiq}");
                    continue;
                }
                $listingId = $fNiq === 0 ? null : $listingMap[$fNiq];
                $importRef = ListingAdminSourceKey::refSource($sourceKey) . ':' . (int) $code['cID'];

                $this->importCode($target, $options, $code, $listingId, $importRef, $lookups,
                    $migrator, $imageSettings, $state, $row, $images);
            }

            $offset += self::CHUNK;
        } while (count($chunk) === self::CHUNK);

        if (!empty($state['catsAtRoot'])) {
            $row['warnings'][] = count($state['catsAtRoot']) . ' code categor' . (count($state['catsAtRoot']) === 1 ? 'y was' : 'ies were')
                . ' put at the top level because their parent category was missing or was itself inside another category.';
        }
        if (!empty($state['catsUnknown'])) {
            $row['warnings'][] = count($state['catsUnknown']) . ' code categor' . (count($state['catsUnknown']) === 1 ? 'y' : 'ies')
                . ' used by codes no longer exist in Listing Admin, so those codes were imported without them.';
        }
        if ($images['skipped'] > 0) {
            $row['warnings'][] = "{$images['skipped']} code image(s) couldn't be copied. Those codes were still imported and keep "
                . 'their approval status, so approved ones show as broken images until the image is there. Run the import '
                . 'again (a ZIP of your codes folder works) to fill them in.';
        }

        return ['row' => $row, 'images' => $images];
    }

    /**
     * Imports, updates, or repairs one LA code. See runCodes().
     *
     * @param array<string,mixed> $code   One LA codes row.
     * @param array<string,array> $state  Shared caches, updated only after a commit.
     */
    private function importCode(
        PDO $target, ImportOptions $options, array $code, ?int $listingId, string $importRef,
        array $lookups, ?ImageMigrator $migrator, array $imageSettings,
        array &$state, array &$row, array &$images
    ): void {
        $cFile     = trim((string) ($code['cFile'] ?? ''));
        $label     = 'Code #' . (int) $code['cID'] . ($cFile !== '' ? " ({$cFile})" : '');
        $overwrite = $options->conflictStrategy === 'overwrite';

        $existing = $this->findImportedCode($target, $importRef);

        // Keep existing: leave the code exactly as it is — just fill in an image that never arrived.
        if ($existing !== null && !$overwrite) {
            if ($cFile === '' || $this->codeImageIsPresent($existing['filename'])) {
                $this->skipCode($row, $code, 'Already imported');
                return;
            }
            // Counted locally: the copy only counts as copied once the code points at it.
            $repairCounts = ['copied' => 0, 'skipped' => 0, 'errors' => []];
            $copied = $this->copyCodeImage($migrator, $cFile, $options, $imageSettings, $repairCounts);
            if ($copied === null) {
                $images['skipped'] += $repairCounts['skipped'];
                $images['errors']   = array_merge($images['errors'], $repairCounts['errors']);
                $this->skipCode($row, $code, 'Already imported (image still missing)');
                return;
            }
            try {
                $target->prepare(
                    'UPDATE lstr_codes SET filename = :filename, width = :w, height = :h, import_source_file = :src WHERE id = :id'
                )->execute([
                    'filename' => $copied['filename'], 'w' => $copied['width'], 'h' => $copied['height'],
                    'src' => $copied['source'], 'id' => $existing['id'],
                ]);
            } catch (\Throwable $e) {
                $this->deleteCodeImage($copied['filename']);
                $images['skipped'] += $repairCounts['copied'] + $repairCounts['skipped'];
                $images['errors']   = array_merge($images['errors'], $repairCounts['errors']);
                $row['errors'][]    = "{$label}: " . $e->getMessage();
                return;
            }
            $images['copied']  += $repairCounts['copied'];
            $images['skipped'] += $repairCounts['skipped'];
            $images['errors']   = array_merge($images['errors'], $repairCounts['errors']);
            $this->skipCode($row, $code, 'Already imported (missing image added)');
            return;
        }

        if ($existing === null && $cFile === '') {
            $this->skipCode($row, $code, 'No image file in Listing Admin');
            return;
        }

        $pending  = ['sizes' => [], 'donors' => [], 'cats' => []];
        $newImage = null;
        $oldImage = null;
        $sort     = null;
        $imageCounts = ['copied' => 0, 'skipped' => 0, 'errors' => []];

        $target->beginTransaction();
        try {
            $sizeLabel = $this->laSizeLabel($lookups['sizes'], (int) ($code['cSize'] ?? 0));
            $sizeId    = $listingId !== null
                ? $this->codeSizeId($target, $listingId, (int) ($code['cSize'] ?? 0), $lookups['sizes'], $overwrite, $state, $pending)
                : null;
            $donor     = self::laDonor($lookups['donors'], (int) ($code['cDonor'] ?? 0));
            $donorId   = ($listingId !== null && $donor !== null)
                ? $this->codeDonorId($target, $listingId, (int) $code['cDonor'], $donor, $state, $pending)
                : null;
            $catIds    = $this->codeCategoryIds($target, $listingId, (string) ($code['cCategory'] ?? ''), $lookups['cats'], $state, $pending);

            // Image: copy for a new code; for an existing one, only when its file is
            // missing or LA now has a different file for it.
            $image = null;
            if ($existing === null) {
                $newImage = $this->copyCodeImage($migrator, $cFile, $options, $imageSettings, $imageCounts);
                $image    = $newImage ?? [
                    'filename' => mb_substr(basename($cFile), 0, 255), 'width' => 0, 'height' => 0, 'source' => null,
                ];
            } elseif ($cFile !== ''
                && (!$this->codeImageIsPresent($existing['filename']) || $cFile !== (string) $existing['import_source_file'])
            ) {
                $newImage = $this->copyCodeImage($migrator, $cFile, $options, $imageSettings, $imageCounts);
                if ($newImage !== null) {
                    $image = $newImage;
                    if ($this->codeImageIsPresent($existing['filename'])) {
                        $oldImage = $existing['filename'];
                    }
                }
            }
            if ($image !== null && ($image['width'] === 0 || $image['height'] === 0)) {
                [$image['width'], $image['height']] = self::parseCodeDims($sizeLabel);
            }

            $collective = $listingId === null;
            $fields = [
                'fanlisting_id'   => $listingId,
                'name'            => self::trimTo((string) ($code['cName'] ?? ''), 150),
                'size_id'         => $sizeId,
                'donor_id'        => $donorId,
                'status'          => (int) ($code['cPending'] ?? 0) === 1 ? 'pending' : 'approved',
                // Collective codes have no donor list, so their credit goes on the code itself
                // (the same fields the collective codes page uses).
                'submitter_name'  => $collective && $donor !== null ? self::trimTo($donor['name'], 150) : null,
                'submitter_email' => $collective && $donor !== null ? $donor['email'] : null,
                'submitter_url'   => $collective && $donor !== null ? self::trimTo((string) $donor['url'], 255) : null,
            ];
            if ($image !== null) {
                $fields['filename']           = $image['filename'];
                $fields['width']              = min(65535, max(0, $image['width']));
                $fields['height']             = min(65535, max(0, $image['height']));
                $fields['import_source_file'] = $image['source'];
            }
            $createdAt = self::normalizeImportDateTime($code['cAdded'] ?? null);
            if ($createdAt !== null) {
                $fields['created_at'] = $createdAt;
            }

            if ($existing === null) {
                $sort = $this->nextCodeSort($target, $listingId, $state);
                $fields['sort_order'] = min(65535, $sort);
                $fields['import_ref'] = $importRef;
                $columns = array_keys($fields);
                $target->prepare(
                    'INSERT INTO lstr_codes (' . implode(', ', $columns) . ')
                     VALUES (:' . implode(', :', $columns) . ')'
                )->execute($fields);
                $codeId = (int) $target->lastInsertId();
            } else {
                $codeId = (int) $existing['id'];
                $set    = implode(', ', array_map(static fn(string $c): string => "{$c} = :{$c}", array_keys($fields)));
                $target->prepare("UPDATE lstr_codes SET {$set} WHERE id = :id")->execute($fields + ['id' => $codeId]);
            }

            // Replace the code's categories, clearing them if LA has none for it now.
            $target->prepare('DELETE FROM lstr_code_category_map WHERE code_id = :code')->execute(['code' => $codeId]);
            $link = $target->prepare('INSERT INTO lstr_code_category_map (code_id, category_id) VALUES (:code, :cat)');
            foreach ($catIds as $catId) {
                $link->execute(['code' => $codeId, 'cat' => $catId]);
            }

            $target->commit();
        } catch (\Throwable $e) {
            $target->rollBack();
            if ($newImage !== null) {
                $this->deleteCodeImage($newImage['filename']);
            }
            $images['skipped'] += $imageCounts['copied'] + $imageCounts['skipped'];
            $images['errors']   = array_merge($images['errors'], $imageCounts['errors']);
            $row['errors'][]    = "{$label}: " . $e->getMessage();
            return;
        }

        // Committed: now the new IDs are safe to share with the codes that follow.
        foreach (['sizes', 'donors', 'cats'] as $kind) {
            $state[$kind] = $pending[$kind] + $state[$kind];
        }
        if ($sort !== null) {
            $state['nextSort'][$listingId ?? 'collective'] = $sort + 1;
        }
        $images['copied']  += $imageCounts['copied'];
        $images['skipped'] += $imageCounts['skipped'];
        $images['errors']   = array_merge($images['errors'], $imageCounts['errors']);
        if ($oldImage !== null) {
            $this->deleteCodeImageIfUnused($target, $oldImage);
        }
        $row['imported']++;
    }

    /** @param array<string,mixed> $code */
    private function skipCode(array &$row, array $code, string $reason): void
    {
        $row['skipped']++;
        if (count($row['skipped_items']) < self::SKIP_CAP) {
            $file = trim((string) ($code['cFile'] ?? ''));
            $row['skipped_items'][] = [
                'label'  => 'Code #' . (int) $code['cID'] . ($file !== '' ? " ({$file})" : ''),
                'reason' => $reason,
            ];
        }
    }

    /** @return array{id:int, filename:string, import_source_file:?string}|null */
    private function findImportedCode(PDO $target, string $importRef): ?array
    {
        $stmt = $target->prepare('SELECT id, filename, import_source_file FROM lstr_codes WHERE import_ref = :ref LIMIT 1');
        $stmt->execute(['ref' => $importRef]);
        $found = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($found === false) {
            return null;
        }
        return [
            'id'                 => (int) $found['id'],
            'filename'           => (string) $found['filename'],
            'import_source_file' => $found['import_source_file'] !== null ? (string) $found['import_source_file'] : null,
        ];
    }

    /**
     * One small LA lookup table keyed by its ID column (aliased 'k'). [] when the
     * table is missing — LA only creates them once the Codes add-on is turned on.
     *
     * @return array<int, array<string,mixed>>
     */
    private function laCodeLookup(PDO $source, string $sql): array
    {
        try {
            $map = [];
            foreach ($source->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $map[(int) $r['k']] = $r;
            }
            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /** One value from LA's options table, '' when missing. */
    private function laOption(PDO $source, string $sp, string $name): string
    {
        try {
            $stmt = $source->prepare("SELECT `text` FROM `{$sp}options` WHERE `name` = :name LIMIT 1");
            $stmt->execute(['name' => $name]);
            return (string) ($stmt->fetchColumn() ?: '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<int, array<string,mixed>> $laSizes */
    private function laSizeLabel(array $laSizes, int $sID): string
    {
        if ($sID <= 0 || !isset($laSizes[$sID])) {
            return '';
        }
        return self::trimTo((string) $laSizes[$sID]['name'], 100) ?? '';
    }

    /**
     * The Luster size for one LA size in one listing: reused when the listing
     * already has one with that label (or, for a label like "88x31", those
     * dimensions); created otherwise. Update matching also copies LA's sOrder.
     *
     * @param array<int, array<string,mixed>> $laSizes
     */
    private function codeSizeId(
        PDO $target, int $listingId, int $sID, array $laSizes, bool $overwrite, array $state, array &$pending
    ): ?int {
        $label = $this->laSizeLabel($laSizes, $sID);
        if ($label === '') {
            return null;
        }
        $key = "{$listingId}|{$sID}";
        if (isset($pending['sizes'][$key])) {
            return $pending['sizes'][$key];
        }
        if (isset($state['sizes'][$key])) {
            return $state['sizes'][$key];
        }

        [$w, $h] = self::parseCodeDims($label);
        $order   = min(65535, max(0, (int) ($laSizes[$sID]['sort'] ?? 0)));

        $find = $target->prepare('SELECT id FROM lstr_code_sizes WHERE fanlisting_id = :fid AND label = :label LIMIT 1');
        $find->execute(['fid' => $listingId, 'label' => $label]);
        $id = $find->fetchColumn();
        if ($id === false && $w > 0) {
            $find = $target->prepare('SELECT id FROM lstr_code_sizes WHERE fanlisting_id = :fid AND width = :w AND height = :h LIMIT 1');
            $find->execute(['fid' => $listingId, 'w' => $w, 'h' => $h]);
            $id = $find->fetchColumn();
        }

        if ($id !== false) {
            $id = (int) $id;
            if ($overwrite) {
                $target->prepare('UPDATE lstr_code_sizes SET sort_order = :sort WHERE id = :id')->execute(['sort' => $order, 'id' => $id]);
            }
        } else {
            $target->prepare(
                'INSERT INTO lstr_code_sizes (fanlisting_id, label, width, height, sort_order) VALUES (:fid, :label, :w, :h, :sort)'
            )->execute(['fid' => $listingId, 'label' => $label, 'w' => $w > 0 ? $w : null, 'h' => $h > 0 ? $h : null, 'sort' => $order]);
            $id = (int) $target->lastInsertId();
        }

        $pending['sizes'][$key] = $id;
        return $id;
    }

    /**
     * An LA donor, cleaned up: name trimmed, email lowercased, blanks as null.
     * Null when the code has no donor (cDonor 0) or the donor is gone.
     *
     * @param  array<int, array<string,mixed>> $laDonors
     * @return array{name:string, email:?string, url:?string}|null
     */
    private static function laDonor(array $laDonors, int $dID): ?array
    {
        if ($dID <= 0 || !isset($laDonors[$dID])) {
            return null;
        }
        $name = self::trimTo((string) $laDonors[$dID]['name'], 150);
        if ($name === null) {
            return null;
        }
        $email = self::trimTo(strtolower((string) ($laDonors[$dID]['email'] ?? '')), 255);
        return [
            'name'  => $name,
            'email' => $email,
            'url'   => self::trimTo((string) ($laDonors[$dID]['url'] ?? ''), 500),
        ];
    }

    /**
     * The Luster donor for one LA donor in one listing, matched on name, email,
     * and URL together — two LA donors who share a name stay separate. The URL
     * must match exactly, case included. Existing
     * donor rows are never edited: if the donor's details changed in LA, the
     * matching donor is found or created and the code is linked to that instead,
     * so other codes using the old row aren't changed.
     *
     * @param array{name:string, email:?string, url:?string} $donor
     */
    private function codeDonorId(PDO $target, int $listingId, int $dID, array $donor, array $state, array &$pending): int
    {
        $key = "{$listingId}|{$dID}";
        if (isset($pending['donors'][$key])) {
            return $pending['donors'][$key];
        }
        if (isset($state['donors'][$key])) {
            return $state['donors'][$key];
        }

        $where  = ['fanlisting_id = :fid', 'name = :name'];
        $params = ['fid' => $listingId, 'name' => $donor['name']];
        if ($donor['email'] === null) {
            $where[] = 'email IS NULL';
        } else {
            $where[]         = 'email = :email';
            $params['email'] = $donor['email'];
        }
        $find = $target->prepare('SELECT id, url FROM lstr_code_donors WHERE ' . implode(' AND ', $where) . ' ORDER BY id');
        $find->execute($params);

        // The URL is compared here, not in SQL: MySQL/MariaDB's usual collations ignore
        // case, but a URL's path is case-sensitive (/Fan and /fan can be different pages).
        $id = false;
        foreach ($find->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $candidateUrl = $candidate['url'] !== null ? (string) $candidate['url'] : null;
            if ($candidateUrl === $donor['url']) {
                $id = (int) $candidate['id'];
                break;
            }
        }

        if ($id === false) {
            $target->prepare(
                'INSERT INTO lstr_code_donors (fanlisting_id, name, email, url) VALUES (:fid, :name, :email, :url)'
            )->execute(['fid' => $listingId, 'name' => $donor['name'], 'email' => $donor['email'], 'url' => $donor['url']]);
            $id = $target->lastInsertId();
        }

        $pending['donors'][$key] = (int) $id;
        return (int) $id;
    }

    /**
     * Luster category IDs for one code's cCategory. LA stores a category ID or
     * '0' for none; digits are read out of whatever separators are there, and 0
     * is dropped. Luster has one level of categories, so a category keeps its
     * parent only when that parent exists and is a top-level category itself —
     * a missing parent, a loop, or a deeper chain puts it at the top level.
     * Categories are created under the code's own listing, even when LA filed
     * the parent under a different listing.
     *
     * @param  array<int, array<string,mixed>> $laCats
     * @return int[]
     */
    private function codeCategoryIds(PDO $target, ?int $listingId, string $raw, array $laCats, array &$state, array &$pending): array
    {
        preg_match_all('/\d+/', $raw, $m);
        $catIds = array_values(array_unique(array_filter(array_map('intval', $m[0]), static fn(int $id): bool => $id > 0)));

        $ids = [];
        foreach ($catIds as $catId) {
            if (!isset($laCats[$catId])) {
                $state['catsUnknown'][$catId] = true;
                continue;
            }
            $parentId = null;
            $p = (int) ($laCats[$catId]['parent'] ?? 0);
            if ($p > 0) {
                if ($p !== $catId && isset($laCats[$p]) && (int) ($laCats[$p]['parent'] ?? 0) === 0) {
                    $parentId = $this->codeCategoryId($target, $listingId, $p, (string) $laCats[$p]['name'], null, $state, $pending);
                } else {
                    $state['catsAtRoot'][$catId] = true;
                }
            }
            $id = $this->codeCategoryId($target, $listingId, $catId, (string) $laCats[$catId]['name'], $parentId, $state, $pending);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    /** Finds or creates one category, cached per (LA category, Luster listing). */
    private function codeCategoryId(
        PDO $target, ?int $listingId, int $catId, string $name, ?int $parentId, array $state, array &$pending
    ): ?int {
        $name = self::trimTo($name, 100);
        if ($name === null) {
            return null;
        }
        $key = $catId . '|' . ($listingId ?? 'collective');
        if (isset($pending['cats'][$key])) {
            return $pending['cats'][$key];
        }
        if (isset($state['cats'][$key])) {
            return $state['cats'][$key];
        }

        $where  = [$listingId === null ? 'fanlisting_id IS NULL' : 'fanlisting_id = :fid', 'name = :name',
                   $parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent'];
        $params = ['name' => $name];
        if ($listingId !== null) {
            $params['fid'] = $listingId;
        }
        if ($parentId !== null) {
            $params['parent'] = $parentId;
        }
        $find = $target->prepare('SELECT id FROM lstr_code_categories WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
        $find->execute($params);
        $id = $find->fetchColumn();

        if ($id === false) {
            $target->prepare(
                'INSERT INTO lstr_code_categories (fanlisting_id, name, parent_id, sort_order) VALUES (:fid, :name, :parent, 0)'
            )->execute(['fid' => $listingId, 'name' => $name, 'parent' => $parentId]);
            $id = $target->lastInsertId();
        }

        $pending['cats'][$key] = (int) $id;
        return (int) $id;
    }

    /** Next sort position for a new code: after every code the listing already has. */
    private function nextCodeSort(PDO $target, ?int $listingId, array $state): int
    {
        $key = $listingId ?? 'collective';
        if (isset($state['nextSort'][$key])) {
            return $state['nextSort'][$key];
        }
        $stmt = $listingId === null
            ? $target->prepare('SELECT MAX(sort_order) FROM lstr_codes WHERE fanlisting_id IS NULL')
            : $target->prepare('SELECT MAX(sort_order) FROM lstr_codes WHERE fanlisting_id = :fid');
        $stmt->execute($listingId === null ? [] : ['fid' => $listingId]);
        $max = $stmt->fetchColumn();
        return $max === null || $max === false ? 0 : (int) $max + 1;
    }

    /**
     * Copies one code image into uploads/codes/ and reads its real size.
     *
     * @param  array{copied:int,skipped:int,errors:string[]} $counts
     * @return array{filename:string, width:int, height:int, source:string}|null
     */
    private function copyCodeImage(?ImageMigrator $migrator, string $cFile, ImportOptions $options, array $imageSettings, array &$counts): ?array
    {
        if ($migrator === null || $cFile === '') {
            return null;
        }
        $unusedMap = [];
        $filename  = $this->migrateImage(
            $migrator, $cFile, 'codes', 'cd_', $options, $imageSettings,
            $unusedMap, $counts['copied'], $counts['skipped'], $counts['errors']
        );
        if ($filename === null) {
            return null;
        }
        // ImageMigrator has already checked by MIME type that this is a real image.
        $info = getimagesize($this->codeImagePath($filename));
        return [
            'filename' => $filename,
            'width'    => $info !== false ? (int) $info[0] : 0,
            'height'   => $info !== false ? (int) $info[1] : 0,
            'source'   => mb_substr($cFile, 0, 255),
        ];
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

    /** Removes an image an update replaced, unless some other code still uses it. */
    private function deleteCodeImageIfUnused(PDO $target, string $filename): void
    {
        try {
            $stmt = $target->prepare('SELECT COUNT(*) FROM lstr_codes WHERE filename = :filename');
            $stmt->execute(['filename' => $filename]);
            if ((int) $stmt->fetchColumn() === 0) {
                $this->deleteCodeImage($filename);
            }
        } catch (\Throwable) {
            // Keeping a spare file is harmless; deleting one that's still used isn't.
        }
    }

    /** Width and height from a label like "88x31" or "100 × 35"; [0, 0] otherwise. */
    private static function parseCodeDims(string $label): array
    {
        if (preg_match('/^\s*(\d{1,4})\s*[x×]\s*(\d{1,4})\s*$/iu', $label, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        return [0, 0];
    }

    /** Trimmed and cut to fit a column; null when nothing is left. */
    private static function trimTo(string $value, int $max): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * A full date and time from LA (e.g. cAdded), or null when it's missing or
     * not a real date. Unlike normalizeImportDate() this keeps the time, and it
     * rejects LA's "never set" placeholders (0000-00-00, 1970-01-01) and
     * impossible dates like 2026-02-31.
     */
    private static function normalizeImportDateTime(mixed $raw): ?string
    {
        $s = trim((string) ($raw ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            $s .= ' 00:00:00';
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $s);
        if ($dt === false || $dt->format('Y-m-d H:i:s') !== $s || (int) $dt->format('Y') < 1971) {
            return null;
        }
        return $s;
    }

    /**
     * Reads configured image directory paths and web URLs from the Listing Admin
     * options table (la_options or {prefix}options).
     *
     * Returns an associative array with keys:
     *   affiliates_dir, joined_dir, listings_dir, codes_dir  (absolute filesystem paths)
     *   affiliates_url, joined_url, listings_url, codes_url  (web-accessible URLs)
     *
     * codes_dir is codes_img_path, or adm_path when that's blank.
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
                      'jnd_path',  'jnd_http',
                      'codes_img_path', 'codes_img_http',
                      'adm_path'
                  )"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {
            return [];
        }

        // Listing Admin saves code images in its admin folder when codes_img_path
        // is blank, so that's where to look in that case.
        $codesDir = rtrim(trim((string) ($rows['codes_img_path'] ?? '')), '/\\');
        if ($codesDir === '') {
            $codesDir = rtrim(trim((string) ($rows['adm_path'] ?? '')), '/\\');
        }

        return [
            'affiliates_dir' => rtrim((string) ($rows['aff_path'] ?? ''), '/\\'),
            'affiliates_url' => rtrim((string) ($rows['aff_http'] ?? ''), '/'),
            'listings_dir'   => rtrim((string) ($rows['img_path'] ?? ''), '/\\'),
            'listings_url'   => rtrim((string) ($rows['img_http'] ?? ''), '/'),
            'joined_dir'     => rtrim((string) ($rows['jnd_path'] ?? ''), '/\\'),
            'joined_url'     => rtrim((string) ($rows['jnd_http'] ?? ''), '/'),
            'codes_dir'      => $codesDir,
            'codes_url'      => rtrim(trim((string) ($rows['codes_img_http'] ?? '')), '/'),
        ];
    }
}
