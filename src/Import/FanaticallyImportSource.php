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
use Luster\Models\AdditionalField;
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

    /**
     * Names a custom field can't keep (see AdditionalField::RESERVED_NAMES).
     * A clashing field gets a _2/_3 suffix instead.
     */
    private const RESERVED_FIELD_NAMES = AdditionalField::RESERVED_NAMES;

    /** Same limit as the admin Fields form, so an imported field can be edited there. */
    private const FIELD_NAME_MAX = 30;

    /**
     * Shown when the Luster database uses the standard Enthusiast layout (no
     * `owned` table). Luster's own installer always creates the unprefixed
     * layout, and this importer's enth_fanlistings branches have never been
     * tested end to end, so the import is refused before anything is written
     * until they are.
     */
    private const ENTH_LAYOUT_UNSUPPORTED =
        "This Luster database uses the older Enthusiast table layout (enth_ tables), and the Fanatically import can't write to that layout yet.";

    /**
     * Shown when the source has custom fields but Luster can't remember which
     * field got which name (no metadata_json, added by Migration 91). Without
     * that, a repeat import can't tell its own fields from the listing's and
     * would give them new names every time, so the import waits for it.
     */
    private const FIELD_NAMES_NEED_MIGRATION =
        "Your Fanatically listings have custom fields, and Luster needs a database update to keep track of them between imports. Run the migration page first (Luster needs Migration 91), then import again.";

    /**
     * Shown when the field names Luster already has (stored by earlier imports,
     * or a listing's own fields) couldn't be read. Naming fields without them
     * could hand an existing column to a different field, so nothing is imported.
     */
    private const FIELD_NAMES_UNREADABLE =
        "Luster couldn't read the custom field names it already has (from earlier imports, or your listings' own fields), so it can't tell which columns belong to which field. Try the import again.";

    /**
     * Shown when Luster couldn't read part of its own database that decides where
     * listings go: its listings, its import records, or which tables exist (or
     * whether import records can hold field names, the lifecycle table an
     * unknown date, or the previous-owners table is there). Read as "nothing
     * there", that could put a listing into another one, hand it someone else's
     * table, or write a Closed listing or previous owners Luster can't hold.
     */
    private const TARGET_UNREADABLE =
        "Luster couldn't read part of its own database (its listings, its import records, its list of tables, its Closed and Adopted Out listings or its previous owners), so it can't tell where your Fanatically listings belong. Try the import again; if it keeps happening, run the migration page first.";

    /**
     * Shown when some Fanatically listings have previous owners but Luster's
     * table for them (Migration 102) isn't there yet. Nothing is written first.
     */
    private const PREVIOUS_OWNERS_NEED_MIGRATION =
        "Some of your Fanatically listings have previous owners, and Luster needs a database update before it can bring them over. Run the migration page first (Luster needs Migration 102), then import again.";

    /**
     * Shown when a failed save couldn't be undone and the database is still in
     * the middle of it. Anything run after it (member tables, the SQL dump
     * clean-up) would save it half-done, so the import stops right there. The
     * error above it says what to check first (a listing it may have left).
     */
    private const TRANSACTION_LEFT_OPEN =
        "Luster couldn't finish undoing a save that failed, so the import stopped right there, before changing anything else. Nothing after that point was imported. Reload the page and do what the errors above say, then import again with Overwrite.";

    /**
     * Shown when some Fanatically listings are Closed or Adopted Out but Luster's
     * lifecycle table can't hold an unknown date yet (Migration 108, or the table
     * itself from Migration 93). Fanatically keeps no closing date, so those
     * listings need one, and writing them anyway would fail partway through.
     */
    private const LIFECYCLE_NEEDS_MIGRATION =
        "Some of your Fanatically listings are Closed or Adopted Out, and Luster needs a database update before it can bring them over that way. Run the migration page first (Luster needs Migration 108), then import again.";

    /** Shown when the Fanatically listings themselves couldn't be read. */
    private const SOURCE_LISTINGS_UNREADABLE =
        "Luster couldn't read your Fanatically listings, so it couldn't work out their custom fields. Check the connection details and try the import again.";

    /** Shown when Migration 104's column couldn't be checked, which isn't the same as it being missing. */
    private const CODES_CHECK_FAILED = "Couldn't check how Luster keeps track of imported codes, so no codes were imported.";

    /** lstr_code_sizes.label, in characters. */
    private const CODE_SIZE_LABEL_LIMIT = 100;

    /** The largest width or height the code tables can hold (SMALLINT UNSIGNED). */
    private const CODE_DIMENSION_LIMIT = 65535;

    /** How a code's size is told apart: see planListingCodes(). */
    private const CODE_NO_SIZE     = 'none';
    private const CODE_STORED_SIZE = 'id:';
    private const CODE_NEW_SIZE    = 'new:';

    private ?\ZipArchive $activeZip = null;

    /** See refsHaveMetadata(). */
    private ?bool $refsHaveMetadata = null;

    /** Set by finishFailedTransaction() when a rollback left a transaction open; run() stops. */
    private bool $transactionLeftOpen = false;

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

        if ($this->detectTargetSchema($target) !== 'enthusiast_unprefixed') {
            return new ImportPreview([[
                'type' => 'Database layout', 'found' => 0, 'import' => 0, 'skip' => 0,
                'warnings' => ["Can't import — " . self::ENTH_LAYOUT_UNSUPPORTED],
            ]], 'fanatically_1x', false);
        }

        // Same custom-field names (and renames) the import will use, from the same
        // up-front reads of Luster's own database.
        $fieldPlans = [];
        $fieldNotes = [];
        $fieldError = null;
        $state      = null;
        if ($options->importListings) {
            $state = $this->readListingTargetState($target, $this->detectTargetSchema($target), false);
            if ($state === null) {
                return new ImportPreview([[
                    'type' => 'Database', 'found' => 0, 'import' => 0, 'skip' => 0,
                    'warnings' => ["Can't import — " . self::TARGET_UNREADABLE],
                ]], 'fanatically_1x', false);
            }
            [$fieldPlans, $fieldNotes, $fieldError] = $this->readCustomFieldPlans($source, $target, $p, $options, $state['index']);
        }
        if ($fieldError !== null) {
            return new ImportPreview([[
                'type' => 'Custom fields', 'found' => 0, 'import' => 0, 'skip' => 0,
                'warnings' => ["Can't import — " . $fieldError],
            ]], 'fanatically_1x', false);
        }
        if ($this->fieldNamesCantBeKept($target, $fieldPlans)) {
            return new ImportPreview([[
                'type' => 'Database update', 'found' => 0, 'import' => 0, 'skip' => 0,
                'warnings' => ["Can't import — " . self::FIELD_NAMES_NEED_MIGRATION],
            ]], 'fanatically_1x', false);
        }
        if ($state !== null && $state['previousOwners'] === 'missing' && $this->plansHavePreviousOwners($fieldPlans)) {
            return new ImportPreview([[
                'type' => 'Database update', 'found' => 0, 'import' => 0, 'skip' => 0,
                'warnings' => ["Can't import — " . self::PREVIOUS_OWNERS_NEED_MIGRATION],
            ]], 'fanatically_1x', false);
        }
        if ($state !== null && $state['lifecycle'] !== 'nullable' && $this->sourceHasArchivedListings($source, $p)) {
            return new ImportPreview([[
                'type' => 'Database update', 'found' => 0, 'import' => 0, 'skip' => 0,
                'warnings' => ["Can't import — " . self::LIFECYCLE_NEEDS_MIGRATION],
            ]], 'fanatically_1x', false);
        }

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

        if ($options->importListings) {
            $joinNote = $this->joinApprovalNote($this->readGlobalSettings($source, $p));
            if ($joinNote !== null) {
                $transformedNotes[] = 'Data transformed — ' . $joinNote;
            }
            // Same custom-field renames the import will report.
            foreach ($fieldNotes as $fieldNote) {
                $transformedNotes[] = 'Data transformed — ' . $fieldNote;
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
            if (in_array('Codes', $dependentWithoutListings, true)) {
                $conditionalNotes[] = "Collective codes (Fanatically's \"Collective\") don't belong to a listing, so they still import without Listings.";
            }
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
            $ownersRow = $this->previewPreviousOwners($options, $state ?? [], $fieldPlans);
            if ($ownersRow !== null) {
                $rows[] = $ownersRow;
            }
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
            $affRow = $this->previewTable($source, "{$p}affiliates", 'Affiliates', [
                'Multi-listing affiliates (listing_ids JSON array) will produce one Luster row per matched listing.',
            ]);
            // Same reading of listing_ids the import uses.
            $malformed = 0;
            try {
                $st = $source->query("SELECT listing_ids FROM `{$p}affiliates`");
                foreach ($st ? $st->fetchAll(PDO::FETCH_COLUMN) : [] as $rawIds) {
                    if ($this->parseAffiliateListingIds($rawIds)[0] === 'malformed') {
                        $malformed++;
                    }
                }
            } catch (\Throwable) {}
            if ($malformed > 0) {
                $affRow['import']    -= $malformed;
                $affRow['skip']      += $malformed;
                $affRow['warnings'][] = $this->malformedAffiliatesNote($malformed, true);
            }
            $rows[] = $affRow;
        }
        if ($options->importCodes) {
            $rows[] = $this->previewCodes($source, $target, $p, $options, $fieldPlans);
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
                $kimWarns = array_merge($kimWarns, $this->previewKimWarnings($source, $p, $options));
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
        $this->transactionLeftOpen = false;
        $caps   = $this->detectCapabilities($source, $p);

        $targetSchema = $this->detectTargetSchema($target);

        // Refused before anything is written: see ENTH_LAYOUT_UNSUPPORTED.
        if ($targetSchema !== 'enthusiast_unprefixed') {
            if ($options->zipPath !== '' && is_file($options->zipPath)) {
                @unlink($options->zipPath);
            }
            return new ImportResult([[
                'type' => 'Database layout', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                'errors' => [self::ENTH_LAYOUT_UNSUPPORTED . ' Nothing was imported.'], 'skipped_items' => [],
            ]], [], microtime(true) - $start, 'fanatically_1x');
        }

        // Where listings go is read from Luster's own database once, strictly, and
        // every listing's custom-field names are worked out from that (reserved
        // names and clashes renamed), all before anything is written. The import
        // waits when any of it couldn't be read, and for Migration 91 when Luster
        // couldn't remember the names: see TARGET_UNREADABLE and FIELD_NAMES_NEED_MIGRATION.
        $listingState = null;
        $fieldPlans   = [];
        $fieldNotes   = [];
        $fieldError   = null;
        if ($options->importListings) {
            $listingState = $this->readListingTargetState($target, $targetSchema, true);
            if ($listingState !== null) {
                [$fieldPlans, $fieldNotes, $fieldError] = $this->readCustomFieldPlans($source, $target, $p, $options, $listingState['index']);
            }
        }
        $refusal = match (true) {
            $options->importListings && $listingState === null => ['Database', self::TARGET_UNREADABLE],
            $fieldError !== null                               => ['Custom fields', $fieldError],
            $this->fieldNamesCantBeKept($target, $fieldPlans)  => ['Database update', self::FIELD_NAMES_NEED_MIGRATION],
            $options->importListings && $listingState['lifecycle'] !== 'nullable' && $this->sourceHasArchivedListings($source, $p)
                                                               => ['Database update', self::LIFECYCLE_NEEDS_MIGRATION],
            $options->importListings && $listingState['previousOwners'] === 'missing' && $this->plansHavePreviousOwners($fieldPlans)
                                                               => ['Database update', self::PREVIOUS_OWNERS_NEED_MIGRATION],
            default                                            => null,
        };
        if ($refusal !== null) {
            if ($options->zipPath !== '' && is_file($options->zipPath)) {
                @unlink($options->zipPath);
            }
            return new ImportResult([[
                'type' => $refusal[0], 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                'errors' => [$refusal[1] . ' Nothing was imported.'], 'skipped_items' => [],
            ]], [], microtime(true) - $start, 'fanatically_1x');
        }

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
        $memberStorage   = []; // fanatic listing_id → member table, its columns, field plan
        if ($options->importListings) {
            [$listRow, $listingIdMap, $listingDbtables, $imgList, $listingOutcomes] =
                $this->runListings($source, $target, $targetSchema, $options, $p, $caps, $categoryIdMap, $migrator, $listingState);

            // Previous owners next, while every listing's save is finished and
            // before any member-table DDL, which would commit an open transaction.
            $ownersRow = $this->transactionLeftOpen
                ? null
                : $this->importPreviousOwners($target, $options, $listingState, $fieldPlans, $listingIdMap, $listingOutcomes);
            if ($this->transactionLeftOpen) {
                $rows[] = $listRow;
                if ($ownersRow !== null && self::hasSomethingToReport($ownersRow)) {
                    $rows[] = $ownersRow;
                }
                $rows[] = ['type' => 'Import stopped', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                           'errors' => [self::TRANSACTION_LEFT_OPEN], 'skipped_items' => []];
                $this->closeActiveZip($options);
                return new ImportResult($rows, $imgRows, microtime(true) - $start, 'fanatically_1x');
            }

            // Member table + field definitions for every imported listing, even one
            // with no members or when Members isn't ticked.
            $provisioned   = $this->provisionMemberStorage($target, $targetSchema, $listingIdMap, $listingDbtables, $listingOutcomes, $fieldPlans);
            $memberStorage = $provisioned['storage'];
            $listRow['warnings'] = array_merge($listRow['warnings'], $fieldNotes, $provisioned['warnings']);
            $listRow['errors']   = array_merge($listRow['errors'], $provisioned['errors']);

            $rows[] = $listRow;
            if (self::hasSomethingToReport($ownersRow)) {
                $rows[] = $ownersRow;
            }
            if ($imgList['copied'] + $imgList['skipped'] > 0) {
                $imgRows[] = array_merge(['type' => 'Images (listings)'], $imgList);
            }
        }

        // ── Phase 4: Members ──────────────────────────────────────────────
        if ($options->importMembers && !empty($listingIdMap)) {
            $rows[] = $this->runMembers($source, $target, $targetSchema, $options, $p, $caps, $listingIdMap, $memberStorage);
        }

        // ── Phase 11: Pending Member Updates ─────────────────────────────
        if ($options->importMembers && $caps['has_pending_update'] && !empty($listingIdMap)) {
            $rows[] = $this->runPendingUpdates($source, $target, $targetSchema, $p, $listingIdMap, $memberStorage, $options);
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

        $this->closeActiveZip($options);

        return new ImportResult($rows, $imgRows, microtime(true) - $start, 'fanatically_1x');
    }

    /** Whether a result row has anything to show (it did something, or has a note or error). @param array<string,mixed> $row */
    private static function hasSomethingToReport(array $row): bool
    {
        return $row['imported'] + $row['skipped'] > 0 || $row['warnings'] !== [] || $row['errors'] !== [];
    }

    /** Closes and removes the image ZIP once the import is done with it. */
    private function closeActiveZip(ImportOptions $options): void
    {
        if ($this->activeZip !== null) {
            $this->activeZip->close();
            $this->activeZip = null;
            if ($options->zipPath !== '') {
                @unlink($options->zipPath);
            }
        }
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
    /**
     * @param array{index: array{urls: array<string,int>, byExternal: array<string,int>, dbtables: array<int,string>}, tables: list<string>, lifecycle: string} $state
     *        From readListingTargetState(): the same reads the field plans used.
     */
    private function runListings(
        PDO $source, PDO $target, string $targetSchema,
        ImportOptions $options, string $p, array $caps,
        array $categoryIdMap, ?ImageMigrator $migrator, array $state
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $listingIdMap    = [];
        $listingDbtables = [];
        $listingOutcomes = []; // fanatic listing_id → 'created' | 'updated' | 'kept' (Skip)
        $listingNotes    = []; // "Title: ..." notes for the results page
        $imgCopied       = 0;
        $imgSkipped      = 0;
        $imgErrors       = [];

        [$regTable, $pkCol, $isOwned] = $this->targetRegistryInfo($targetSchema);

        // Existing listings by address and by earlier import, with each one's member
        // table. Overwrite reuses that table instead of generating a new one (which
        // would leave members in an orphaned table). readCustomFieldPlans() matched
        // listings with this same copy, so the field plans and this step agree.
        $index = $state['index'];

        $usedDbtables = []; // lower(dbtable/dbprefix) → true
        foreach ($index['dbtables'] as $dt) {
            $usedDbtables[strtolower($dt)] = true;
        }

        // Every table already in the database is off limits for a new listing's
        // member table, not only the ones other listings use. Otherwise a listing
        // called "owned" or "lstr_codes" would be handed Luster's own table.
        foreach ($state['tables'] as $tableName) {
            $usedDbtables[strtolower($tableName)] = true;
        }

        try {
            $srcListings = $source->query("SELECT * FROM `{$p}listings` ORDER BY id ASC")->fetchAll();
        } catch (\Throwable $e) {
            return [
                ['type' => 'Owned Listings', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                 'errors' => ['Could not read listings: ' . $e->getMessage()], 'skipped_items' => [],
                 'image_copied' => 0, 'image_skipped' => 0, 'image_errors' => []],
                [], [], ['copied' => 0, 'skipped' => 0, 'errors' => []], [],
            ];
        }

        // Load global Fanatically settings that apply to all listings.
        // Fanatically does not store these per-listing; they are site-wide.
        $globalSettings = $this->readGlobalSettings($source, $p);
        $joinNote       = $this->joinApprovalNote($globalSettings);

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

        foreach (array_values($srcListings) as $position => $lst) {
            $srcId = (int) $lst['id'];
            $url   = rtrim(trim((string) ($lst['url'] ?? '')), '/');

            // Status mapping — whitelist-only: only 'current' and 'upcoming' get their
            // corresponding Luster states; closed, adopted_out, pending, and any future
            // unknown status all map to inactive (trouble=1) to fail safe. Closed and
            // adopted_out also get a lifecycle row (importLifecycle()), and inactive is
            // the registry state an archived listing has anyway.
            $status   = (string) ($lst['status'] ?? 'current');
            $upcoming = $status === 'upcoming' ? 1 : 0;
            $trouble  = ($status !== 'current' && $status !== 'upcoming') ? 1 : 0;

            // Blank-URL listings are imported as inactive regardless of their source status;
            // they can still be matched on re-import via the external-ref map.
            if ($url === '') {
                $trouble  = 1;
                $upcoming = 0;
            }

            // Keep the source status on the import record when the mapping above loses
            // it (Migration 93 made lifecycle rows from this for imports done before
            // the table existed). Only written when the status or blank-URL state
            // produced a lossy mapping.
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

            $existingId = $this->matchExistingListing($index, $url, $srcId);

            if ($existingId !== null) {
                if ($options->conflictStrategy === 'skip') {
                    $listingIdMap[$srcId]    = $existingId;
                    $listingOutcomes[$srcId] = 'kept';
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
                    if (!$this->upsertExternalRef($target, $existingId, 'fanatically', (string) $srcId, $sourceStatusMeta)) {
                        $errors[] = "Listing '" . (string) ($lst['title'] ?? '') . "': Luster couldn't update its record of which Fanatically listing it came from.";
                    }
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
            if ($existingIdForOverwrite !== null && isset($index['dbtables'][$existingIdForOverwrite])) {
                $dbtable = $index['dbtables'][$existingIdForOverwrite];
            } else {
                $dbtable = $this->generateDbtable($subject ?: $title, $usedDbtables);
                $usedDbtables[$dbtable] = true;
            }

            // Every source category Luster has, in order, saved the way the listing
            // edit page saves them: catid '|1|2|' ('' for none) plus the first one
            // in category_id.
            $mappedCatIds = [];
            foreach ($lcJunction[$srcId] ?? [] as $srcCatId) {
                $lusterCatId = $categoryIdMap[$srcCatId] ?? null;
                if ($lusterCatId !== null && !in_array($lusterCatId, $mappedCatIds, true)) {
                    $mappedCatIds[] = $lusterCatId;
                }
            }
            $primaryCatId = $mappedCatIds[0] ?? null;
            [$catidList, $inCatidList] = self::legacyCatidList($mappedCatIds);

            $registryWritten = false; // saved for good (committed)
            $registryTouched = false; // the registry statement ran, so it may need undoing
            $before          = null;  // an overwritten row's old values, for undoRegistryWrite()
            try {
                // The registry row and its Closed / Adopted Out record change together
                // or not at all (importLifecycle()). An active row with the archive
                // record left behind shows in both Current and Past; a Closed listing
                // without one is only inactive, and open to joins. Nothing else goes
                // in here, and member tables are set up later, well outside it. A
                // MyISAM `owned` can't roll back, so the catch below checks and puts
                // the row back by hand.
                $target->beginTransaction();
                if ($existingId !== null) {
                    $before = $this->registryRow($target, $regTable, $pkCol, $isOwned, $existingId);
                }
                if ($isOwned) {
                    if ($existingId !== null) {
                        $target->prepare(
                            "UPDATE `owned` SET title=:title, subject=:subject, url=:url,
                             catid=:catid, category_id=:category_id, status=:status, opened=:opened
                             WHERE listingid=:id"
                        )->execute([
                            'title' => $title, 'subject' => $subject, 'url' => $url,
                            'catid' => $catidList, 'category_id' => $primaryCatId,
                            'status' => ($upcoming ? 1 : ($trouble ? 0 : 2)),
                            'opened' => $openedDate ?: null,
                            'id' => $existingId,
                        ]);
                        $newId = $existingId;
                    } else {
                        $target->prepare(
                            "INSERT INTO `owned` (dbtable, title, subject, url, catid, category_id, status, opened, additional)
                             VALUES (:dbtable, :title, :subject, :url, :catid, :category_id, :status, :opened, '')"
                        )->execute([
                            'dbtable' => $dbtable, 'title' => $title, 'subject' => $subject,
                            'url' => $url, 'catid' => $catidList, 'category_id' => $primaryCatId,
                            'status' => ($upcoming ? 1 : ($trouble ? 0 : 2)),
                            'opened' => $openedDate ?: null,
                        ]);
                        $newId = (int) $target->lastInsertId();
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
                    }
                }
                $registryTouched = true;

                // Closed / Adopted Out come over as real archived listings, and a
                // listing archived in Luster but active in Fanatically is reopened.
                // Before Migration 93 there's nowhere to keep it.
                $lifecycleNote = $state['lifecycle'] !== 'missing'
                    ? $this->importLifecycle($target, (int) $newId, $status, $title, $url !== '')
                    : '';
                $target->commit();

                // Only now, with both saved, does this listing count and get mapped.
                $registryWritten = true;
                if ($existingId === null) {
                    $imported++;
                }
                if ($lifecycleNote !== '') {
                    $listingNotes[] = $lifecycleNote;
                }

                $listingIdMap[$srcId]    = $newId;
                $listingDbtables[$srcId] = $dbtable;
                $listingOutcomes[$srcId] = $existingId !== null ? 'updated' : 'created';
                // The listing now has this row's address (new, or written by
                // Overwrite), so later rows match it by that address and not by an
                // old one. A later row with the same address finds this listing, so
                // it has to find its member table too, not get a new one.
                $this->rememberListingAddress($index, $newId, $url);
                $index['dbtables'][$newId] = $dbtable;

                // Record external refs. REPLACE INTO repairs a stale mapping when
                // the same external ID re-imports to a different Luster listing.
                // When it can't, the record still names another listing, and the
                // field names can't be saved with it (saveFieldMap() checks), so
                // this listing's fields and members are held back and reported.
                if (!$this->upsertExternalRef($target, $newId, 'fanatically', (string) $srcId, $sourceStatusMeta)) {
                    $errors[] = "Listing '{$title}': Luster couldn't update its record of which Fanatically listing it came from.";
                }
                $index['byExternal'][(string) $srcId] = $newId;
                $fansFansId = trim((string) ($lst['fansfansfans_id'] ?? ''));
                if ($fansFansId !== '') {
                    $this->upsertExternalRef($target, $newId, 'fansfansfans', $fansFansId);
                }

                // lstr_listing_categories is where Luster reads a listing's categories,
                // and the only place that has all of them once catid is full.
                $categoryError = $this->addListingCategories($target, (int) $newId, $mappedCatIds);
                if ($categoryError !== '') {
                    $errors[] = "Listing '{$title}': {$categoryError}";
                }
                if ($inCatidList < count($mappedCatIds)) {
                    $listingNotes[] = "{$title}: has " . count($mappedCatIds) . ' categories. '
                        . ($categoryError === '' ? 'All of them are saved, but the' : 'The')
                        . " older category field that Enthusiast-style pages read only has room for the first {$inCatidList}.";
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
                // Only a save that didn't finish is undone, never one already
                // committed, and nothing more is written while one is still open.
                $undone = '';
                if (!$this->finishFailedTransaction($target)) {
                    // Nothing more may run, not even a check, so a listing the
                    // save may have left behind (a MyISAM `owned` keeps it) is
                    // named with what to do before importing again.
                    $undone = ", and Luster couldn't finish undoing it"
                        . ($registryTouched && !$registryWritten
                            ? ', so it ' . self::uncheckedSaveAdvice($existingId === null, (int) $newId, $url, '')
                            : '.');
                } elseif ($registryTouched && !$registryWritten) {
                    $undone = $this->unfinishedSaveOutcome(
                        $target, $regTable, $pkCol, $isOwned, (int) $newId, $before,
                        $url, (string) $srcId, $sourceStatusMeta
                    );
                }
                $errors[] = "Listing '{$title}': " . $e->getMessage() . $undone;
                if (!$registryWritten) {
                    // The field plans were worked out expecting this listing to be
                    // saved (a new listing, or its new address), and later rows were
                    // matched with that in mind. Carrying on could put a later
                    // listing, and its members' answers, into the wrong listing.
                    $left = count($srcListings) - $position - 1;
                    if ($left > 0) {
                        $errors[] = "The {$left} listing(s) after it weren't imported, because where they belong depended on this one being saved. Fix the problem above and import again.";
                    }
                    break;
                }
            }
        }

        return [
            ['type' => 'Owned Listings', 'imported' => $imported, 'skipped' => $skipped,
             'warnings' => array_merge($joinNote !== null ? [$joinNote] : [], $listingNotes), 'errors' => $errors, 'skipped_items' => $skippedItems,
             'image_copied' => $imgCopied, 'image_skipped' => $imgSkipped, 'image_errors' => $imgErrors],
            $listingIdMap,
            $listingDbtables,
            ['copied' => $imgCopied, 'skipped' => $imgSkipped, 'errors' => $imgErrors],
            $listingOutcomes,
        ];
    }

    // -----------------------------------------------------------------------
    // Phase 4 — Members
    // -----------------------------------------------------------------------

    /**
     * @param array<int,int>                 $listingIdMap
     * @param array<int,array<string,mixed>> $memberStorage From provisionMemberStorage(), keyed by source listing id
     */
    private function runMembers(
        PDO $source, PDO $target, string $targetSchema,
        ImportOptions $options, string $p, array $caps,
        array $listingIdMap, array $memberStorage
    ): array {
        $imported      = 0;
        $skipped       = 0;
        $errors        = [];
        $warnings      = [];
        $skippedItems  = [];
        $droppedValues = 0;

        [, , $isOwned] = $this->targetRegistryInfo($targetSchema);

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
                if (!isset($listingIdMap[$srcListingId])) {
                    $skippedItems[] = ['label' => $mem['email'] ?? '?', 'reason' => 'Listing not imported'];
                    $skipped++;
                    continue;
                }

                // The member table was set up once per listing, before any member was
                // written (provisionMemberStorage), whether or not it had members.
                $storage = $memberStorage[$srcListingId] ?? null;
                if ($storage === null || !$storage['usable']) {
                    $skippedItems[] = [
                        'label'  => $mem['email'] ?? '?',
                        'reason' => (string) ($storage['reason'] ?? 'The listing has no member table'),
                    ];
                    $skipped++;
                    continue;
                }
                $memberTable = (string) $storage['table'];
                $columns     = $storage['columns'];

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

                $approved = (int) ($mem['is_approved'] ?? 1) === 1;

                // Pending vs approved lives in a different column on each schema.
                $statusColumn = $isOwned ? 'pending' : 'approved';
                if (!isset($columns[$statusColumn])) {
                    $skippedItems[] = [
                        'label'  => $email,
                        'reason' => "The listing's member table has no {$statusColumn} column, so this member's approval couldn't be saved",
                    ];
                    $skipped++;
                    continue;
                }

                // Custom field values go to the column each source field was given by
                // the field plan. A value for a field the table doesn't have (a listing
                // that was already in Luster and left alone under Skip) is dropped.
                $fieldValues = []; // column => value
                $cfJson      = trim((string) ($mem['custom_field_values'] ?? ''));
                if ($cfJson !== '' && $cfJson !== 'null') {
                    $cf = json_decode($cfJson, true);
                    if (is_array($cf)) {
                        foreach ($cf as $origName => $val) {
                            $column = $this->plannedFieldColumn($storage['plan'], (string) $origName, $isOwned);
                            if ($column === null || !isset($columns[$column])) {
                                $droppedValues++;
                                continue;
                            }
                            $fieldValues[$column] = is_scalar($val) ? (string) $val : '';
                        }
                    }
                }

                $data = [
                    'email'     => $email,
                    'name'      => (string) ($mem['name']    ?? ''),
                    'country'   => (string) ($mem['country'] ?? ''),
                    'url'       => (string) ($mem['url']     ?? ''),
                    'password'  => (string) ($mem['password_hash'] ?? ''),
                    'showemail' => 1,
                    'showurl'   => 1,
                    'added'     => (string) ($mem['joined_at'] ?? date('Y-m-d')),
                ];
                if ($isOwned) {
                    $data['pending'] = $approved ? 0 : 1;
                } else {
                    $data['approved'] = $approved ? 1 : 0;
                    $data['updated']  = date('Y-m-d H:i:s');
                }
                // Only columns the table really has: an existing table may be older.
                $data = array_filter($data, static fn(string $col): bool => isset($columns[$col]), ARRAY_FILTER_USE_KEY);
                $data = array_merge($data, $fieldValues);

                try {
                    if ($exists) {
                        // Overwrite keeps the member's own showemail/showurl choices.
                        $update = $data;
                        unset($update['email'], $update['showemail'], $update['showurl']);
                        $set = implode(', ', array_map(static fn(string $col): string => "`{$col}`=:{$col}", array_keys($update)));
                        $target->prepare("UPDATE `{$memberTable}` SET {$set} WHERE email=:email")
                               ->execute($update + ['email' => $email]);
                    } else {
                        $cols = implode(', ', array_map(static fn(string $col): string => "`{$col}`", array_keys($data)));
                        $vals = implode(', ', array_map(static fn(string $col): string => ':' . $col, array_keys($data)));
                        $target->prepare("INSERT INTO `{$memberTable}` ({$cols}) VALUES ({$vals})")
                               ->execute($data);
                        $imported++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Member '{$email}': " . $e->getMessage();
                }
            }

            $offset += self::CHUNK;
        } while (count($chunk) === self::CHUNK);

        if ($droppedValues > 0) {
            $warnings[] = "{$droppedValues} custom field value(s) weren't imported because their listing in Luster has no matching field. "
                . '(A listing that was already in Luster keeps its own fields when you pick Skip.)';
        }

        return [
            'type' => 'Members', 'imported' => $imported, 'skipped' => $skipped,
            'warnings' => $warnings, 'errors' => $errors, 'skipped_items' => $skippedItems,
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
        $skippedItems   = [];
        $imgCopied      = 0;
        $imgSkipped     = 0;
        $imgErrors      = [];
        $malformedCount = 0;

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

            // Which fanlistings this affiliate belongs to. A list Luster can't read
            // is skipped and reported, never turned into a site-wide affiliate.
            [$scope, $decoded] = $this->parseAffiliateListingIds($aff['listing_ids'] ?? null);
            if ($scope === 'malformed') {
                $skippedItems[] = [
                    'label'  => $url !== '' ? $url : (string) ($aff['name'] ?? '?'),
                    'reason' => "Its list of fanlistings (listing_ids) couldn't be read, so it wasn't imported: "
                        . mb_substr(trim((string) ($aff['listing_ids'] ?? '')), 0, 80),
                ];
                $skipped++;
                $malformedCount++;
                continue;
            }

            $targets = [];
            if ($scope === 'collective') {
                $targets[] = null; // fanlisting_id = NULL
            } else {
                foreach ($decoded as $lid) {
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
             'warnings' => $malformedCount > 0 ? [$this->malformedAffiliatesNote($malformedCount, false)] : [],
             'errors' => $errors, 'skipped_items' => $skippedItems],
            ['copied' => $imgCopied, 'skipped' => $imgSkipped, 'errors' => $imgErrors],
        ];
    }

    /**
     * Reads a Fanatically affiliate's listing_ids. Empty, null and [] mean the
     * whole collective (Fanatically's own convention), as does [0]. A JSON list
     * of whole numbers means those fanlistings (0 in the list adds a collective
     * copy). Anything else (broken JSON, a JSON object even if empty, a bare
     * number, text or nested values) is 'malformed': the caller skips and
     * reports it rather than guessing,
     * because guessing "collective" would show a corrupt affiliate site-wide.
     *
     * @return array{0: 'collective'|'listings'|'malformed', 1: list<int>}
     */
    private function parseAffiliateListingIds(mixed $raw): array
    {
        $text = trim((string) ($raw ?? ''));
        if ($text === '' || $text === 'null' || $text === '[]') {
            return ['collective', []];
        }

        // Decoded as objects (not associative arrays), so a JSON object such as
        // {} or {"0":1} stays an object and is rejected: only a real JSON list counts.
        $decoded = json_decode($text);
        if (!is_array($decoded)) {
            return ['malformed', []];
        }

        $ids = [];
        foreach ($decoded as $value) {
            if (is_int($value) && $value >= 0) {
                $ids[] = $value;
            } elseif (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
                $ids[] = (int) $value;
            } else {
                return ['malformed', []];
            }
        }

        if ($ids === [] || $ids === [0]) {
            return ['collective', []];
        }
        return ['listings', array_values(array_unique($ids))];
    }

    private function malformedAffiliatesNote(int $count, bool $forPreview): string
    {
        return $forPreview
            ? "Data dropped — {$count} affiliate(s) have a list of fanlistings (listing_ids) Luster can't read. They'll be skipped and listed on the results page, so you can add them by hand."
            : "{$count} affiliate(s) were skipped because their list of fanlistings (listing_ids) couldn't be read. They're listed below, so you can add them by hand.";
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

        // Codes are tracked by their full filename (Migration 104's column). If that
        // can't be checked, or the source can't be read up front, the step stops rather
        // than falling back to weaker matching that could add copies.
        try {
            $hasCodeIdentity = $this->tableHasColumn($target, 'lstr_codes', 'import_source_file', true);
        } catch (\Throwable $e) {
            return $this->codesStepStopped(self::CODES_CHECK_FAILED . ' ' . $e->getMessage());
        }
        try {
            $codesByDestination = $this->codesByDestination($this->readSourceCodes($source, $p), $listingIdMap);
        } catch (\Throwable $e) {
            return $this->codesStepStopped('Could not read codes: ' . $e->getMessage());
        }

        // How each listing's codes line up with its sizes, worked out when first needed.
        $listingPlans = [];

        // Each listing and size's stored codes, read once when first needed, and kept
        // up to date as this import writes. Keyed [listing][size identity].
        $knownCodes = [];

        // Luster's collective codes are loaded once and matched in PHP, byte for
        // byte (the database compares filenames ignoring case); codes this import
        // adds are appended as it goes.
        $storedCollective = $hasCodeIdentity ? $this->storedCollectiveCodes($target) : [];
        // Collective codes (Fanatically listing 0) whose name comes under more than
        // one size label that isn't WxH can't be told apart once stored. Worked out
        // before anything is written: name => labels, and whether Luster already had one.
        $ambiguousCollective = $this->ambiguousCollectiveCodes($source, $p, $storedCollective);

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
                $sizeLabel    = trim((string) ($code['size_label'] ?? ''));
                $fileName     = trim((string) ($code['file_name']  ?? ''));

                // Fanatically's "Collective" is listing 0. It becomes a Luster
                // collective code (no listing). Any other listing has to have come
                // over: an unknown listing never turns into a collective code.
                if ($srcListingId === 0) {
                    if ($fileName === '') {
                        $skipped++;
                        continue;
                    }
                    $outcome = $this->importCollectiveCode(
                        $target, $options, $code, $sizeLabel, $fileName, $ambiguousCollective, $hasCodeIdentity,
                        $storedCollective, $migrator, $imgCopied, $imgSkipped,
                    );
                    if ($outcome['imported']) {
                        $imported++;
                    } else {
                        $skipped++;
                        if ($outcome['reason'] !== '') {
                            $skippedItems[] = ['label' => $fileName, 'reason' => $outcome['reason']];
                        }
                    }
                    if ($outcome['error'] !== '') {
                        $errors[] = $outcome['error'];
                    }
                    continue;
                }

                $newListingId = $listingIdMap[$srcListingId] ?? null;
                if ($newListingId === null) {
                    $skippedItems[] = ['label' => (string) ($code['file_name'] ?? '?'), 'reason' => 'Listing not imported'];
                    $skipped++;
                    continue;
                }

                if ($fileName === '') {
                    $skipped++;
                    continue;
                }

                $cantImport = CodeIdentity::cantTrack($fileName, $hasCodeIdentity) ?? $this->codeSizeProblem($sizeLabel);
                if ($cantImport !== null) {
                    $skippedItems[] = ['label' => $fileName, 'reason' => $cantImport];
                    $skipped++;
                    continue;
                }

                // The same filename under a different size is a different code. A failed
                // read here costs this code and nothing else, and it never counts as
                // "not found", which would add a copy.
                try {
                    $listingPlans[$newListingId] ??= $this->planListingCodes($target, $newListingId, $codesByDestination[$newListingId] ?? []);
                    $sizeKey = $listingPlans[$newListingId]['sizes'][$sizeLabel]
                        ?? throw new \RuntimeException('This code was added to Fanatically during the import.');
                    $sizeId = $this->upsertCodeSize($target, $newListingId, $sizeLabel, $codeSizeIdMap);
                    $knownCodes[$newListingId][$sizeKey] ??= $this->codesForSize($target, $newListingId, $sizeId, $hasCodeIdentity);
                } catch (\Throwable $e) {
                    $errors[] = "Code '{$fileName}': " . $e->getMessage();
                    continue;
                }

                $decision = CodeIdentity::decide(
                    $fileName,
                    $hasCodeIdentity,
                    isset($listingPlans[$newListingId]['colliding'][$sizeKey][$fileName]),
                    $knownCodes[$newListingId][$sizeKey],
                );
                if ($decision['action'] === CodeIdentity::SKIP) {
                    $skippedItems[] = ['label' => $fileName, 'reason' => (string) $decision['reason']];
                    $skipped++;
                    continue;
                }

                $existingCodeId = $decision['id'];
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
                $copiedFile   = null;
                if ($migrator !== null) {
                    $resolved    = $this->resolveCodeImagePath($fileName, $sizeLabel, $srcListingId, $options);
                    $copiedFile  = $this->migrateImage($migrator, $resolved, 'codes', $options);
                    if ($copiedFile !== null) {
                        $newImageFile = $copiedFile;
                        $imgCopied++;
                    } else {
                        $imgSkipped++;
                    }
                }

                $codeStatus = (int) ($code['is_approved'] ?? 1) === 1 ? 'approved' : 'pending';
                [$codeW, $codeH] = $this->parseSize($sizeLabel);

                try {
                    if ($existingCodeId !== null) {
                        // overwrite: update the existing row. Its image file only changes
                        // when a new one was actually copied; otherwise a working, already
                        // migrated file would be swapped for the bare source name.
                        $update = [
                            'size_id' => $sizeId, 'donor_id' => $donorId,
                            'status'  => $codeStatus, 'width' => $codeW, 'height' => $codeH,
                        ];
                        if ($copiedFile !== null) {
                            $update['filename'] = $copiedFile;
                        }
                        // A code that wasn't tracked yet is from now on.
                        if ($decision['tag']) {
                            $update['import_source_file'] = $fileName;
                        }
                        $set = implode(', ', array_map(static fn(string $col): string => "{$col}=:{$col}", array_keys($update)));
                        $target->prepare("UPDATE lstr_codes SET {$set} WHERE id=:id")->execute($update + ['id' => $existingCodeId]);
                        if ($decision['tag']) {
                            $knownCodes[$newListingId][$sizeKey] = CodeIdentity::afterTagging($knownCodes[$newListingId][$sizeKey], $existingCodeId, $fileName);
                        }
                    } else {
                        $fields = [
                            'fanlisting_id' => $newListingId, 'size_id' => $sizeId, 'donor_id' => $donorId,
                            'name'          => CodeIdentity::cappedName($fileName), 'filename' => $newImageFile,
                            'status'        => $codeStatus, 'width' => $codeW, 'height' => $codeH,
                        ];
                        if ($hasCodeIdentity) {
                            $fields['import_source_file'] = $fileName;
                        }
                        $cols = implode(', ', array_keys($fields));
                        $vals = implode(', ', array_map(static fn(string $col): string => ':' . $col, array_keys($fields)));
                        $target->prepare("INSERT INTO lstr_codes ({$cols}) VALUES ({$vals})")->execute($fields);
                        $knownCodes[$newListingId][$sizeKey] = CodeIdentity::afterInsert($knownCodes[$newListingId][$sizeKey], (int) $target->lastInsertId(), $fileName, $hasCodeIdentity);
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

    /** runCodes()'s result when the step can't start, with nothing written. */
    private function codesStepStopped(string $error): array
    {
        return [
            ['type' => 'Codes', 'imported' => 0, 'skipped' => 0, 'warnings' => [], 'errors' => [$error], 'skipped_items' => []],
            ['copied' => 0, 'skipped' => 0, 'errors' => []],
        ];
    }

    /**
     * The preview's Codes row. Collective and listing codes are each counted with the
     * same rules the import applies.
     *
     * @param array<int,array<string,mixed>> $fieldPlans From readCustomFieldPlans().
     * @return array<string,mixed>
     */
    private function previewCodes(PDO $source, PDO $target, string $p, ImportOptions $options, array $fieldPlans): array
    {
        $codesRow = $this->previewTable($source, "{$p}codes", 'Codes', [
            'Fanatically stores codes in a flat table. Size labels and donor fields will be normalized into lstr_code_sizes and lstr_code_donors.',
        ]);

        // A failed check isn't a missing column, so it doesn't get that advice.
        try {
            $hasCodeIdentity = $this->tableHasColumn($target, 'lstr_codes', 'import_source_file', true);
        } catch (\Throwable) {
            $codesRow['warnings'][] = self::CODES_CHECK_FAILED . ' These numbers may be off, and the import will stop at this step.';
            return $codesRow;
        }

        // Collective codes: counted with the same skip rule the import applies.
        $storedCollective = $hasCodeIdentity ? $this->storedCollectiveCodes($target) : [];
        $ambiguous        = $this->ambiguousCollectiveCodes($source, $p, $storedCollective);
        $collectiveCodes = 0;
        $collectiveSkips = 0;
        try {
            $st = $source->query("SELECT file_name, size_label FROM `{$p}codes` WHERE listing_id = 0");
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $collectiveCodes++;
                $fileName = trim((string) ($row['file_name'] ?? ''));
                if ($fileName === ''
                    || $this->collectiveCodeSkipReason($fileName, trim((string) ($row['size_label'] ?? '')), $ambiguous, $hasCodeIdentity, $storedCollective) !== null
                ) {
                    $collectiveSkips++;
                }
            }
        } catch (\Throwable) {}

        if ($collectiveCodes > 0) {
            $codesRow['warnings'][] = "{$collectiveCodes} collective code(s) (Fanatically's \"Collective\") will be imported as collective codes, "
                . 'with any donor credited on the code itself.';
        }
        if ($collectiveCodes > 0 && !$hasCodeIdentity) {
            $codesRow['warnings'][] = "Needs decision — collective codes can't be imported yet: run the migration page first (Luster needs Migration 104).";
        }
        if (!$options->importListings) {
            // Without Listings only collective codes have somewhere to go.
            $codesRow['skip']   = $codesRow['found'] - $collectiveCodes;
            $codesRow['import'] = $collectiveCodes;
        } else {
            try {
                $listingCodes = $this->previewListingCodes($source, $target, $p, $options, $fieldPlans, $hasCodeIdentity);
                $codesRow['import'] -= $listingCodes['skipped'];
                $codesRow['skip']   += $listingCodes['skipped'];
                array_push($codesRow['warnings'], ...$listingCodes['warnings']);
            } catch (\Throwable) {
                $codesRow['warnings'][] = "Couldn't read the codes to compare them with Luster's, so these numbers may be off.";
            }
        }
        $codesRow['import'] -= $collectiveSkips;
        $codesRow['skip']   += $collectiveSkips;

        foreach ($ambiguous as $name => $info) {
            $codesRow['warnings'][] = $info['existing']
                ? "Needs decision — collective code \"{$name}\" comes in sizes Luster can't read as width x height ("
                    . implode(', ', $info['labels']) . ') and is already in Luster, so those copies will be skipped. Update them on the collective codes page.'
                : "Collective code \"{$name}\" comes in sizes Luster can't read as width x height (" . implode(', ', $info['labels'])
                    . '). Every copy is imported now, but importing again later will skip those copies, because Luster can\'t tell them apart.';
        }

        return $codesRow;
    }

    /**
     * How many listing codes the import will skip, walking them the same way runCodes()
     * does but writing nothing. Listings and sizes that don't exist yet have no stored
     * codes. What an earlier code in this import would add or tag is tracked as it goes,
     * so a repeated file counts the way the import counts it.
     *
     * @param array<int,array<string,mixed>> $plans From readCustomFieldPlans().
     * @return array{skipped: int, warnings: list<string>}
     * @throws \Throwable If the source or Luster's codes can't be read.
     */
    private function previewListingCodes(PDO $source, PDO $target, string $p, ImportOptions $options, array $plans, bool $hasCodeIdentity): array
    {
        $destinations = [];
        foreach ($plans as $srcId => $plan) {
            $destinations[(int) $srcId] = (int) ($plan['destination'] ?? 0);
        }

        $sourceCodes   = $this->readSourceCodes($source, $p);
        $byDestination = $this->codesByDestination($sourceCodes, $destinations);

        $plans    = [];
        $known    = [];
        $skipped  = 0;
        $orphans  = 0;
        $newId    = 0;
        $warnings = [];
        foreach ($sourceCodes as $code) {
            if ($code['listing'] === 0) {
                continue;
            }
            $destination = $destinations[$code['listing']] ?? null;
            if ($destination === null) {
                $orphans++;
                continue;
            }
            $file = $code['file'];
            if ($file === '') {
                $skipped++;
                continue;
            }

            $cantImport = CodeIdentity::cantTrack($file, $hasCodeIdentity) ?? $this->codeSizeProblem($code['label']);
            if ($cantImport !== null) {
                $skipped++;
                $warnings[] = "Code \"{$file}\" will be skipped. {$cantImport}";
                continue;
            }

            $plans[$destination] ??= $this->planListingCodes($target, $destination, $byDestination[$destination] ?? []);
            $sizeKey = $plans[$destination]['sizes'][$code['label']];
            $known[$destination][$sizeKey] ??= $this->storedCodesForPreview($target, $destination, $sizeKey, $hasCodeIdentity);

            $decision = CodeIdentity::decide($file, $hasCodeIdentity, isset($plans[$destination]['colliding'][$sizeKey][$file]), $known[$destination][$sizeKey]);
            if ($decision['action'] === CodeIdentity::SKIP) {
                $skipped++;
                $warnings[] = "Code \"{$file}\" will be skipped. {$decision['reason']}";
            } elseif ($decision['action'] === CodeIdentity::INSERT) {
                $known[$destination][$sizeKey] = CodeIdentity::afterInsert($known[$destination][$sizeKey], --$newId, $file, $hasCodeIdentity);
            } elseif ($options->conflictStrategy === 'skip') {
                $skipped++;
            } elseif ($decision['tag']) {
                $known[$destination][$sizeKey] = CodeIdentity::afterTagging($known[$destination][$sizeKey], (int) $decision['id'], $file);
            }
        }

        if ($orphans > 0) {
            $skipped += $orphans;
            $warnings[] = "{$orphans} code(s) belong to a listing that isn't being imported, so they'll be skipped.";
        }

        return ['skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * The stored codes for a listing and size identity, without creating anything. A
     * destination below 0 is a listing this import will create, and a size that doesn't
     * exist yet has no codes.
     *
     * @return list<array{id: int, name: ?string, src: ?string}>
     * @throws \Throwable If they can't be read.
     */
    private function storedCodesForPreview(PDO $target, int $destination, string $sizeKey, bool $hasCodeIdentity): array
    {
        if ($destination <= 0) {
            return [];
        }
        if ($sizeKey === self::CODE_NO_SIZE) {
            return $this->codesForSize($target, $destination, null, $hasCodeIdentity);
        }
        if (str_starts_with($sizeKey, self::CODE_STORED_SIZE)) {
            return $this->codesForSize($target, $destination, (int) substr($sizeKey, strlen(self::CODE_STORED_SIZE)), $hasCodeIdentity);
        }

        return [];
    }

    /**
     * Every code in the source, read up front so a preview and a run see the same list.
     *
     * @return list<array{listing: int, label: string, file: string}>
     * @throws \Throwable If it can't be read.
     */
    private function readSourceCodes(PDO $source, string $p): array
    {
        $st = $source->query("SELECT listing_id, file_name, size_label FROM `{$p}codes`");
        if ($st === false) {
            throw new \RuntimeException("Couldn't read {$p}codes.");
        }

        $codes = [];
        foreach ($st->fetchAll() as $row) {
            $codes[] = [
                'listing' => (int) ($row['listing_id'] ?? 0),
                'label'   => trim((string) ($row['size_label'] ?? '')),
                'file'    => trim((string) ($row['file_name'] ?? '')),
            ];
        }

        return $codes;
    }

    /**
     * The source's listing codes, grouped by the Luster listing they go into. Source
     * listings that share a Luster listing go together, because their codes end up together.
     *
     * @param list<array{listing: int, label: string, file: string}> $sourceCodes
     * @param array<int,int>                                         $destinations Source listing id => Luster listing id.
     * @return array<int, list<array{listing: int, label: string, file: string}>>
     */
    private function codesByDestination(array $sourceCodes, array $destinations): array
    {
        $grouped = [];
        foreach ($sourceCodes as $code) {
            $destination = $destinations[$code['listing']] ?? null;
            if ($code['listing'] !== 0 && $destination !== null) {
                $grouped[$destination][] = $code;
            }
        }

        return $grouped;
    }

    /**
     * How one Luster listing's incoming codes line up with its sizes, for the run and the
     * preview alike. Different labels can mean the same stored size: "Small" by its name
     * and "88x31" by its measurements can both be one row. So everything about a code's
     * size goes by which size it really is, never by the label it arrived with.
     *
     * @param list<array{listing: int, label: string, file: string}> $codes
     * @return array{sizes: array<string, string>, colliding: array<string, array<string, true>>}
     *         sizes is label => size identity, and colliding is [size identity][file] => true.
     * @throws \Throwable If the listing's sizes can't be read.
     */
    private function planListingCodes(PDO $target, int $destination, array $codes): array
    {
        $stored = $destination > 0 ? $this->codeSizesOf($target, $destination) : [];

        $sizes = [];
        $files = [];
        foreach ($codes as $code) {
            $sizes[$code['label']] ??= $this->codeSizeIdentity($stored, $code['label']);
            if ($code['file'] !== '') {
                $files[$sizes[$code['label']]][] = $code['file'];
            }
        }

        $colliding = [];
        foreach ($files as $sizeKey => $names) {
            $colliding[$sizeKey] = CodeIdentity::colliding($names);
        }

        return ['sizes' => $sizes, 'colliding' => $colliding];
    }

    /**
     * Which size a label really is: no size for a blank, the stored row if it has one, or
     * the size this import will add. Adding one can't change the answer for another label,
     * because a new row is only ever found again by the same measurements or exact label.
     *
     * @param list<array{id: int, label: string, width: ?int, height: ?int}> $stored
     */
    private function codeSizeIdentity(array $stored, string $label): string
    {
        if ($label === '') {
            return self::CODE_NO_SIZE;
        }

        $id = $this->resolveCodeSize($stored, $label);

        return $id !== null ? self::CODE_STORED_SIZE . $id : self::CODE_NEW_SIZE . $this->newCodeSizeKey($label);
    }

    /** What a size that doesn't exist yet goes by. WxH labels are one size whatever their capitals. */
    private function newCodeSizeKey(string $label): string
    {
        [$w, $h] = $this->parseSize($label);

        return $w > 0 || $h > 0 ? "{$w}x{$h}" : 'label:' . $label;
    }

    /**
     * Why a size label can't be stored as it is, or null if it can. A value that doesn't
     * fit is cut short on a server that isn't strict, so it would never match again and
     * every repeat import would add a new size and a copy of the code.
     */
    private function codeSizeProblem(string $label): ?string
    {
        if (mb_strlen($label) > self::CODE_SIZE_LABEL_LIMIT) {
            return "Its size label is longer than 100 characters, so Luster can't keep track of it between imports.";
        }
        [$w, $h] = $this->parseSize($label);
        if ($w > self::CODE_DIMENSION_LIMIT || $h > self::CODE_DIMENSION_LIMIT) {
            return "Its size is too big for Luster to store, so it can't keep track of it between imports.";
        }

        return null;
    }

    /**
     * The stored codes for a listing and size. A null size means codes with no size.
     *
     * @return list<array{id: int, name: ?string, src: ?string}>
     * @throws \Throwable If they can't be read.
     */
    private function codesForSize(PDO $target, int $listingId, ?int $sizeId, bool $hasCodeIdentity): array
    {
        $columns = $hasCodeIdentity ? 'id, name, import_source_file' : 'id, name';
        $size    = $sizeId !== null ? 'size_id = :sid' : 'size_id IS NULL';
        $params  = ['fid' => $listingId] + ($sizeId !== null ? ['sid' => $sizeId] : []);

        $st = $target->prepare("SELECT {$columns} FROM lstr_codes WHERE fanlisting_id = :fid AND {$size} ORDER BY id");
        $st->execute($params);

        $rows = [];
        foreach ($st->fetchAll() as $row) {
            $rows[] = [
                'id'   => (int) $row['id'],
                'name' => $row['name'] !== null ? (string) $row['name'] : null,
                'src'  => isset($row['import_source_file']) ? (string) $row['import_source_file'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Collective code names that come under more than one size label that isn't
     * WxH (e.g. "small" and "big"). Collective codes have no size row, only a
     * width and height, so two such copies look identical once stored. A first
     * import keeps them all; a repeat import can't tell which stored copy is
     * which, so it skips those copies. A copy of the same name under a readable
     * size (e.g. 88x31) isn't affected. 'existing' is whether Luster already had
     * an unreadable-size copy before this import started; it's worked out once,
     * from $stored, and doesn't change while the import adds copies.
     *
     * @param list<array{id: int, name: string, src: ?string, w: int, h: int}> $stored
     * @return array<string, array{labels: list<string>, existing: bool}>
     */
    private function ambiguousCollectiveCodes(PDO $source, string $p, array $stored): array
    {
        $labelsByName = [];
        try {
            $st = $source->query("SELECT file_name, size_label FROM `{$p}codes` WHERE listing_id = 0");
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $label = trim((string) ($row['size_label'] ?? ''));
                if ($this->parseSize($label) !== [0, 0]) {
                    continue;
                }
                $labelsByName[trim((string) ($row['file_name'] ?? ''))][$label] = true;
            }
        } catch (\Throwable) {
            return [];
        }

        $ambiguous = [];
        foreach ($labelsByName as $name => $labels) {
            $name = (string) $name;
            if ($name === '' || count($labels) < 2) {
                continue;
            }
            $ambiguous[$name] = [
                'labels'   => array_map('strval', array_keys($labels)),
                'existing' => $this->collectiveCodeMatches($stored, $name, 0, 0) !== [],
            ];
        }
        return $ambiguous;
    }

    /**
     * Luster's collective codes (no listing, no size row), for matching a
     * Fanatically collective code in PHP. The database compares filenames
     * ignoring case, so it can't be asked which one is "code.png".
     *
     * @return list<array{id: int, name: string, src: ?string, w: int, h: int}>
     */
    private function storedCollectiveCodes(PDO $target): array
    {
        $stored = [];
        try {
            $st = $target->query(
                'SELECT id, name, import_source_file, width, height FROM lstr_codes
                  WHERE fanlisting_id IS NULL AND size_id IS NULL ORDER BY id'
            );
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $stored[] = [
                    'id'   => (int) $row['id'],
                    'name' => (string) ($row['name'] ?? ''),
                    'src'  => $row['import_source_file'] !== null ? (string) $row['import_source_file'] : null,
                    'w'    => (int) $row['width'],
                    'h'    => (int) $row['height'],
                ];
            }
        } catch (\Throwable) {}
        return $stored;
    }

    /**
     * The stored collective codes a Fanatically collective code matches, by id:
     * those imported from exactly this filename at this width and height, or, only
     * when there are none, codes added in Luster (no import_source_file) with
     * exactly this name and size. Filenames are compared byte for byte.
     *
     * @param list<array{id: int, name: string, src: ?string, w: int, h: int}> $stored
     * @return list<int>
     */
    private function collectiveCodeMatches(array $stored, string $fileName, int $w, int $h): array
    {
        $imported = [];
        $manual   = [];
        foreach ($stored as $code) {
            if ($code['w'] !== $w || $code['h'] !== $h) {
                continue;
            }
            if ($code['src'] !== null) {
                if ($code['src'] === $fileName) {
                    $imported[] = $code['id'];
                }
            } elseif ($code['name'] === $fileName) {
                $manual[] = $code['id'];
            }
        }
        return $imported !== [] ? $imported : $manual;
    }

    /**
     * Why a Fanatically collective code will be skipped, or null when it can be
     * imported. The preview counts and the import both use this, so they agree.
     *
     * @param array<string, array{labels: list<string>, existing: bool}>         $ambiguous
     * @param list<array{id: int, name: string, src: ?string, w: int, h: int}> $stored
     */
    private function collectiveCodeSkipReason(
        string $fileName, string $sizeLabel, array $ambiguous, bool $hasIdentityColumn, array $stored
    ): ?string {
        if (!$hasIdentityColumn) {
            return "Collective codes can't be imported yet: run the migration page first (Luster needs Migration 104), then import again.";
        }
        if (strlen($fileName) > 255) {
            return "Its filename is longer than 255 characters, so Luster can't keep track of it between imports.";
        }
        [$w, $h] = $this->parseSize($sizeLabel);
        $unreadable = $w === 0 && $h === 0 && isset($ambiguous[$fileName]);
        if ($unreadable && $ambiguous[$fileName]['existing']) {
            return "This collective code comes in sizes Luster can't read as width x height ("
                . implode(', ', array_map(static fn(string $l): string => $l === '' ? '(none)' : $l, $ambiguous[$fileName]['labels']))
                . "), so it can't tell which copy already in Luster is which. Add or update it on the collective codes page.";
        }
        // A first import's unreadable-size copies are each added without a lookup.
        // Anything else is looked up, and has to find at most one copy.
        if (!$unreadable) {
            $copies = count($this->collectiveCodeMatches($stored, $fileName, $w, $h));
            if ($copies > 1) {
                return "Luster already has {$copies} copies of this collective code that can't be told apart, "
                    . 'so it was left alone. Update it on the collective codes page.';
            }
        }
        return null;
    }

    /**
     * Imports one Fanatically collective code (listing 0) as a Luster collective
     * code, the same way the Listing Admin import does: no listing, no size or
     * donor row, the real width/height on the code, and the donor credited on
     * the code itself (submitter_*), which is what the collective codes pages
     * read. The image is looked up under Fanatically's own listing-0 folder.
     *
     * The full source filename is kept in import_source_file (the name column
     * only holds 150 characters), and a repeat import finds the code by that
     * plus width + height (collectiveCodeMatches()). A collective code added in
     * Luster (no import_source_file) with exactly the same name and size also
     * matches. A code this adds goes into $stored, so later rows find it.
     * Overwrite keeps the code's current image file unless a new one was copied.
     *
     * @param array<string,mixed>                                                $code
     * @param array<string, array{labels: list<string>, existing: bool}>         $ambiguous
     * @param list<array{id: int, name: string, src: ?string, w: int, h: int}> $stored
     * @return array{imported: bool, reason: string, error: string}
     */
    private function importCollectiveCode(
        PDO $target, ImportOptions $options, array $code, string $sizeLabel, string $fileName,
        array $ambiguous, bool $hasIdentityColumn, array &$stored, ?ImageMigrator $migrator, int &$imgCopied, int &$imgSkipped
    ): array {
        $reason = $this->collectiveCodeSkipReason($fileName, $sizeLabel, $ambiguous, $hasIdentityColumn, $stored);
        if ($reason !== null) {
            return ['imported' => false, 'reason' => $reason, 'error' => ''];
        }

        [$w, $h] = $this->parseSize($sizeLabel);
        // Copies under unreadable sizes can't be matched, so a first import adds each.
        $unmatchable    = $w === 0 && $h === 0 && isset($ambiguous[$fileName]);
        $existingCodeId = $unmatchable ? null : ($this->collectiveCodeMatches($stored, $fileName, $w, $h)[0] ?? null);

        if ($existingCodeId !== null && $options->conflictStrategy === 'skip') {
            return ['imported' => false, 'reason' => '', 'error' => ''];
        }

        // Fanatically keeps collective images under its listing-0 folder.
        $copiedFile = null;
        if ($migrator !== null) {
            $resolved   = $this->resolveCodeImagePath($fileName, $sizeLabel, 0, $options);
            $copiedFile = $this->migrateImage($migrator, $resolved, 'codes', $options);
            if ($copiedFile !== null) {
                $imgCopied++;
            } else {
                $imgSkipped++;
            }
        }

        $donorName  = trim((string) ($code['donor_name']  ?? ''));
        $donorUrl   = trim((string) ($code['donor_url']   ?? ''));
        $donorEmail = trim((string) ($code['donor_email'] ?? ''));
        $fields = [
            'status'             => (int) ($code['is_approved'] ?? 1) === 1 ? 'approved' : 'pending',
            'width'              => $w,
            'height'             => $h,
            'submitter_name'     => $donorName !== '' ? mb_substr($donorName, 0, 150) : null,
            'submitter_email'    => $donorName !== '' && $donorEmail !== '' ? mb_substr($donorEmail, 0, 255) : null,
            'submitter_url'      => $donorName !== '' && $donorUrl !== '' ? mb_substr($donorUrl, 0, 255) : null,
            'import_source_file' => $fileName,
        ];

        try {
            if ($existingCodeId !== null) {
                // Only a successfully copied image replaces the one already there.
                if ($copiedFile !== null) {
                    $fields['filename'] = $copiedFile;
                }
                $set = implode(', ', array_map(static fn(string $col): string => "{$col}=:{$col}", array_keys($fields)));
                $target->prepare("UPDATE lstr_codes SET {$set} WHERE id=:id")->execute($fields + ['id' => $existingCodeId]);
                // A code added in Luster is now tracked by this filename.
                foreach ($stored as $i => $storedCode) {
                    if ($storedCode['id'] === $existingCodeId) {
                        $stored[$i]['src'] = $fileName;
                    }
                }
            } else {
                $fields = [
                    'fanlisting_id' => null, 'size_id' => null, 'donor_id' => null,
                    'name'          => mb_substr($fileName, 0, 150),
                    'filename'      => $copiedFile ?? $fileName,
                ] + $fields;
                $cols = implode(', ', array_keys($fields));
                $vals = implode(', ', array_map(static fn(string $col): string => ':' . $col, array_keys($fields)));
                $target->prepare("INSERT INTO lstr_codes ({$cols}) VALUES ({$vals})")->execute($fields);
                $stored[] = ['id' => (int) $target->lastInsertId(), 'name' => $fields['name'], 'src' => $fileName, 'w' => $w, 'h' => $h];
            }
        } catch (\Throwable $e) {
            return ['imported' => false, 'reason' => '', 'error' => "Collective code '{$fileName}': " . $e->getMessage()];
        }

        return ['imported' => true, 'reason' => '', 'error' => ''];
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
            // Luster's joined statuses are 'approved' and 'pending' (Migration 38). The
            // Random/Latest Joined embeds only read 'approved' rows.
            $lstrStatus = ($status === 'pending' || $status === 'inactive') ? 'pending' : 'approved';

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
                    // Category sources are lowercase ('tfl'); the joined edit form
                    // only recognises its own labels ('TFL'), see networkLabel().
                    $jsonEntries[]  = [
                        'name'    => $resolvedName,
                        'network' => \Luster\Support\JoinedCategories::networkLabel($resolvedSource),
                    ];
                    if ($i === 0) {
                        $catId   = $lusterCatId;
                        $catName = $resolvedName;
                    }
                }
                $categoriesJson = \Luster\Support\JoinedCategories::encode($jsonEntries);
            } elseif ($caps['has_joined_categories'] === false) {
                // No junction table — fall back to legacy category_id column
                $legacyCatId = (int) ($jl['category_id'] ?? 0);
                if ($legacyCatId > 0) {
                    $lusterCatId = $categoryIdMap[$legacyCatId] ?? null;
                    if ($lusterCatId !== null) {
                        $catId   = $lusterCatId;
                        $catName = $catNameMap[$lusterCatId] ?? '';
                        $source2 = $catSourceMap[$lusterCatId] ?? 'custom';
                        $categoriesJson = \Luster\Support\JoinedCategories::encode(
                            [['name' => $catName, 'network' => \Luster\Support\JoinedCategories::networkLabel($source2)]]
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

        try {
            $st   = $source->query("SELECT * FROM `{$p}kim` ORDER BY id ASC");
            $rows = $st ? $st->fetchAll() : [];
        } catch (\Throwable $e) {
            return [
                'type' => 'KIM List', 'imported' => 0, 'skipped' => 0, 'warnings' => [],
                'errors' => ['Could not read KIM entries: ' . $e->getMessage()], 'skipped_items' => [],
            ];
        }

        // Luster's KIM list has no notes field. Every entry's note is listed on the
        // results page (and in its downloadable report) instead, whether or not the
        // entry itself came over, so nothing is lost and nothing becomes public.
        $warnings = $this->kimNoteWarnings($rows, $this->sourceListingTitles($source, $p));

        foreach ($rows as $kim) {
            $kimPending   = $this->kimPendingValue($kim, $options);
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
            'warnings' => $warnings, 'errors' => $errors, 'skipped_items' => $skippedItems,
        ];
    }

    /**
     * The pending value (1 = waiting for approval) for one KIM entry. By default
     * each entry keeps its own Fanatically approval; a missing is_approved column
     * (older Fanatically) counts as approved, which is what Fanatically assumed.
     *
     * @param array<string,mixed> $kim
     */
    private function kimPendingValue(array $kim, ImportOptions $options): int
    {
        return match ($options->fnKimStatus) {
            'pending' => 1,
            'active'  => 0,
            default   => (int) ($kim['is_approved'] ?? 1) === 1 ? 0 : 1,
        };
    }

    /**
     * One results-page line per KIM entry that has a note, since Luster's KIM
     * list has nowhere to keep them.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<int,string>         $listingTitles source listing id => title
     * @return list<string>
     */
    private function kimNoteWarnings(array $rows, array $listingTitles): array
    {
        $lines = [];
        foreach ($rows as $kim) {
            $note = trim((string) ($kim['notes'] ?? ''));
            if ($note === '') {
                continue;
            }
            $srcListingId = (int) ($kim['listing_id'] ?? 0);
            $listing      = $listingTitles[$srcListingId] ?? ($srcListingId === 0 ? 'no fanlisting' : "listing #{$srcListingId}");
            $lines[] = 'KIM note for ' . strtolower(trim((string) ($kim['email'] ?? '?'))) . " ({$listing}): {$note}";
        }

        if ($lines === []) {
            return [];
        }
        array_unshift($lines, count($lines) . " KIM note(s) weren't imported, because Luster's KIM list has no notes field. "
            . "They're listed here so you can copy them, and only you can see this page. They aren't saved anywhere in Luster, "
            . 'so download the import report before you leave this page or start another import.');
        return $lines;
    }

    /** @return array<int,string> source listing id => title (subject when untitled) */
    private function sourceListingTitles(PDO $source, string $p): array
    {
        $titles = [];
        try {
            $st = $source->query("SELECT * FROM `{$p}listings`");
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $titles[(int) $row['id']] = trim((string) ($row['title'] ?? '')) ?: trim((string) ($row['subject'] ?? ''));
            }
        } catch (\Throwable) {}
        return $titles;
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
    /**
     * @param array<int,int>                 $listingIdMap
     * @param array<int,array<string,mixed>> $memberStorage From provisionMemberStorage(), keyed by source listing id
     */
    private function runPendingUpdates(
        PDO $source, PDO $target, string $targetSchema,
        string $p, array $listingIdMap, array $memberStorage, ImportOptions $options
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

                $storage = $memberStorage[$srcListingId] ?? null;
                if ($storage === null || !$storage['usable']) {
                    $skippedItems[] = [
                        'label'  => (string) ($mem['email'] ?? '?'),
                        'reason' => (string) ($storage['reason'] ?? 'The listing has no member table'),
                    ];
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
                // Keys use the same field plan as member import, so they name real
                // columns; a field the member table doesn't have is left out.
                $cfJson = $raw['custom_field_values'] ?? '';
                if (is_string($cfJson) && $cfJson !== '') {
                    $cfValues = json_decode($cfJson, true);
                    if (is_array($cfValues)) {
                        foreach ($cfValues as $fieldName => $value) {
                            $key = $this->plannedFieldColumn($storage['plan'], (string) $fieldName, $isOwned);
                            if ($key === null || !isset($storage['columns'][$key])) {
                                continue;
                            }
                            $changes[$key] = is_scalar($value) ? (string) $value : '';
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
     * Finds or adds the lstr_code_sizes row for a listing and label, and returns its id.
     * A blank label means the code has no size, so it returns null with no row: a size's
     * label is required, so a blank one can't be stored. Anything else that fails throws,
     * because a code landing with no size would be a different code on the next import.
     *
     * @param array<int,array<string,int>> $idMap In/out cache: [listing][label] => id
     * @throws \Throwable If the size can't be read or added.
     */
    private function upsertCodeSize(PDO $target, int $listingId, string $label, array &$idMap): ?int
    {
        if ($label === '') {
            return null;
        }
        if (isset($idMap[$listingId][$label])) {
            return $idMap[$listingId][$label];
        }

        $id = $this->resolveCodeSize($this->codeSizesOf($target, $listingId), $label);
        if ($id === null) {
            [$w, $h] = $this->parseSize($label);
            $target->prepare(
                'INSERT INTO lstr_code_sizes (fanlisting_id, label, width, height)
                 VALUES (:fid, :label, :w, :h)'
            )->execute(['fid' => $listingId, 'label' => $label, 'w' => $w, 'h' => $h]);
            $id = (int) $target->lastInsertId();
        }

        $idMap[$listingId][$label] = $id;

        return $id;
    }

    /**
     * A listing's stored sizes, oldest first.
     *
     * @return list<array{id: int, label: string, width: ?int, height: ?int}>
     * @throws \Throwable If they can't be read.
     */
    private function codeSizesOf(PDO $target, int $listingId): array
    {
        $st = $target->prepare('SELECT id, label, width, height FROM lstr_code_sizes WHERE fanlisting_id = :fid ORDER BY id');
        $st->execute(['fid' => $listingId]);

        $sizes = [];
        foreach ($st->fetchAll() as $row) {
            $sizes[] = [
                'id'     => (int) $row['id'],
                'label'  => (string) $row['label'],
                'width'  => $row['width'] !== null ? (int) $row['width'] : null,
                'height' => $row['height'] !== null ? (int) $row['height'] : null,
            ];
        }

        return $sizes;
    }

    /**
     * The stored size a non-blank label means, or null if there isn't one yet. WxH labels
     * match by their numbers, so 100x35 and 100X35 are one size on purpose, and they take
     * the oldest row with those measurements. Any other label has to match exactly here,
     * because Small and small are two sizes.
     *
     * @param list<array{id: int, label: string, width: ?int, height: ?int}> $stored
     */
    private function resolveCodeSize(array $stored, string $label): ?int
    {
        [$w, $h] = $this->parseSize($label);
        foreach ($stored as $size) {
            $matches = $w > 0 || $h > 0
                ? $size['width'] === $w && $size['height'] === $h
                : $size['label'] === $label;
            if ($matches) {
                return $size['id'];
            }
        }

        return null;
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

    // -----------------------------------------------------------------------
    // Custom fields and member storage
    // -----------------------------------------------------------------------

    /**
     * Works out every source listing's custom-field names before anything is
     * written (see planCustomFields()).
     *
     * First, each source listing is matched to the Luster listing it will go into,
     * row by row, the same way runListings() matches it (existingListingIndex()),
     * including the address changes runListings() makes as it goes: a listing an
     * earlier row creates, and (Overwrite only) an existing listing's new address.
     * The names earlier imports gave (storedFieldMaps()) only count while their
     * import record still points at that Luster listing.
     *
     * Then each listing's fields are named. A field can't take a name that Luster
     * listing already has, a name kept for a field that's gone from Fanatically,
     * or a name that belongs to another source listing going into the same Luster
     * listing: everything those listings have on record is reserved up front, so
     * the order of the rows doesn't matter, plus whatever earlier rows were given.
     *
     * The third value is an error (FIELD_NAMES_UNREADABLE) when the names Luster
     * already has couldn't be read; there are no plans then, and nothing may be
     * imported, because names chosen without them could take another field's column.
     *
     * $index is the same existingListingIndex() copy runListings() gets.
     *
     * @param array{urls: array<string,int>, byExternal: array<string,int>, dbtables: array<int,string>} $index
     * @return array{0: array<int, array<string,mixed>>, 1: list<string>, 2: string|null} plans keyed by source listing id, notes, error
     */
    private function readCustomFieldPlans(PDO $source, PDO $target, string $p, ImportOptions $options, array $index): array
    {
        // Without the listings there's nothing to plan from; carrying on would
        // import them later without their fields and drop their members' answers.
        try {
            $st = $source->query("SELECT * FROM `{$p}listings` ORDER BY id ASC");
            if ($st === false) {
                return [[], [], self::SOURCE_LISTINGS_UNREADABLE];
            }
            $rows = $st->fetchAll();
        } catch (\Throwable) {
            return [[], [], self::SOURCE_LISTINGS_UNREADABLE];
        }

        [$regTable, $pkCol, $isOwned] = $this->targetRegistryInfo($this->detectTargetSchema($target));
        $storedMaps = $this->storedFieldMaps($target);
        $overwrite  = $options->conflictStrategy !== 'skip';
        if ($storedMaps === null) {
            return [[], [], self::FIELD_NAMES_UNREADABLE];
        }

        // 1. Where each source listing goes, and what it has on record there.
        $listings  = [];
        $destNames = []; // Luster listing id => field names it already has
        $onRecord  = []; // Luster listing id => stored name => source listing ids holding it
        $nextNew   = -1; // stands in for the id of a listing runListings() will create
        foreach ($rows as $row) {
            $srcId = (int) ($row['id'] ?? 0);
            $label = trim((string) ($row['title'] ?? '')) ?: trim((string) ($row['subject'] ?? '')) ?: "listing #{$srcId}";

            $url    = rtrim(trim((string) ($row['url'] ?? '')), '/');
            $destId = $this->matchExistingListing($index, $url, $srcId);
            if ($destId === null) {
                // runListings() creates a listing here, and later rows find it the same way.
                $destId = $nextNew--;
                $this->rememberListingAddress($index, $destId, $url);
                $index['byExternal'][(string) $srcId] = $destId;
            } elseif ($overwrite) {
                // Overwrite gives the listing this row's address; Skip leaves it.
                $this->rememberListingAddress($index, $destId, $url);
                $index['byExternal'][(string) $srcId] = $destId;
            }
            if ($destId > 0 && !isset($destNames[$destId])) {
                $destNames[$destId] = $this->destinationFieldNames(
                    $target, $destId, $index['dbtables'][$destId] ?? '', $regTable, $pkCol, $isOwned
                );
                if ($destNames[$destId] === null) {
                    return [[], [], self::FIELD_NAMES_UNREADABLE];
                }
            }

            $stored = $storedMaps[$srcId] ?? null;
            $map    = $stored !== null && $stored['listing_id'] === $destId ? $stored['map'] : [];
            foreach ($map as $name) {
                $onRecord[$destId][$name][] = $srcId;
            }

            $defs = [];
            $note = null;
            $json = trim((string) ($row['custom_fields'] ?? ''));
            if ($json !== '' && $json !== 'null') {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $defs = $decoded;
                } else {
                    $note = "{$label}: its list of custom fields couldn't be read, so none of its fields were imported.";
                }
            }

            $listings[] = ['srcId' => $srcId, 'label' => $label, 'destId' => $destId, 'map' => $map, 'defs' => $defs, 'note' => $note,
                           'owners' => $this->parsePreviousOwners($row, $label)];
        }

        // 2. Name each listing's fields.
        $plans   = [];
        $notes   = [];
        $claimed = []; // Luster listing id => names earlier rows in this import were given
        foreach ($listings as $listing) {
            $destId = $listing['destId'];
            $others = $claimed[$destId] ?? [];
            foreach ($onRecord[$destId] ?? [] as $name => $holders) {
                if (array_diff($holders, [$listing['srcId']]) !== []) {
                    $others[$name] = true;
                }
            }

            [$plan, $planNotes] = $this->planCustomFields(
                $listing['defs'], $listing['label'], $listing['map'], $destNames[$destId] ?? [], $others
            );
            foreach ([$plan['bySource'], $plan['retained']] as $names) {
                foreach ($names as $name) {
                    $claimed[$destId][$name] = true;
                }
            }
            // Where it goes (an existing id, or a placeholder below 0 for a listing
            // the import creates) and its previous owners, for importPreviousOwners().
            $plans[$listing['srcId']] = $plan + ['destination' => $destId, 'previousOwners' => $listing['owners']];
            if ($listing['note'] !== null) {
                $notes[] = $listing['note'];
            }
            $notes = array_merge($notes, $planNotes);
        }

        return [$plans, $notes, null];
    }

    /**
     * The source-field → Luster-field names each earlier import gave a listing's
     * custom fields, keyed by Fanatically listing id, with the Luster listing the
     * import record points at. Kept with the listing's import record
     * (lstr_listing_external_refs.metadata_json, 'field_map'), so a repeat import
     * gives every field the same name again even if the fields were reordered or
     * a new one now clashes. Source names come back as strings. Empty when
     * there's nothing stored (or nowhere to store it: no metadata_json before
     * Migration 91). Null when the names are there but couldn't be read: planning
     * without them would hand their columns to other fields.
     *
     * @return array<int, array{listing_id: int, map: array<string,string>}>|null
     */
    private function storedFieldMaps(PDO $target): ?array
    {
        if (!$this->refsHaveMetadata($target)) {
            return [];
        }

        $maps = [];
        try {
            $st = $target->prepare(
                "SELECT external_id, listing_id, metadata_json FROM lstr_listing_external_refs
                  WHERE source = 'fanatically' AND metadata_json IS NOT NULL"
            );
            $st->execute();
            foreach ($st->fetchAll() as $row) {
                $meta = json_decode((string) $row['metadata_json'], true);
                if (!is_array($meta) || !is_array($meta['field_map'] ?? null)) {
                    continue;
                }
                $map = [];
                foreach ($meta['field_map'] as $sourceName => $name) {
                    if (is_string($name)) {
                        $map[(string) $sourceName] = $name;
                    }
                }
                $maps[(int) $row['external_id']] = ['listing_id' => (int) $row['listing_id'], 'map' => $map];
            }
        } catch (\Throwable) {
            return null;
        }
        return $maps;
    }

    /**
     * The field names a Luster listing already has: its field definitions, and
     * its member table's field columns when that table is a real member table.
     * Null when its field definitions or its table's columns couldn't be read.
     *
     * @return array<string,true>|null
     */
    private function destinationFieldNames(
        PDO $target, int $listingId, string $dbtable, string $regTable, string $pkCol, bool $isOwned
    ): ?array {
        $names = [];
        try {
            $infoCol = $isOwned ? 'additional' : 'additional_info';
            $st      = $target->prepare("SELECT {$infoCol} FROM `{$regTable}` WHERE {$pkCol} = :id");
            $st->execute(['id' => $listingId]);
            foreach (array_keys($this->parseFieldDefinitions((string) ($st->fetchColumn() ?: ''), $isOwned)) as $name) {
                $names[strtolower((string) $name)] = true;
            }
        } catch (\Throwable) {
            return null;
        }

        $table = $isOwned ? $dbtable : $dbtable . 'members';
        try {
            $columns = $dbtable !== '' && !$this->isProtectedTableName($table) ? $this->memberTableColumns($target, $table) : null;
        } catch (\Throwable) {
            return null;
        }
        if ($columns !== null && $this->looksLikeMemberTable($columns, $isOwned)) {
            foreach (array_keys($columns) as $column) {
                $column = (string) $column;
                if (!$isOwned) {
                    if (!str_starts_with($column, 'additional_')) {
                        continue;
                    }
                    $column = substr($column, strlen('additional_'));
                }
                $names[$column] = true;
            }
        }
        return $names;
    }

    /**
     * Gives each of one listing's Fanatically custom fields a Luster field name,
     * once, so the member table, the field definitions, member values and queued
     * updates all agree.
     *
     * A field an earlier import already named ($stored) keeps that name, so
     * reordering fields or adding one that clashes can't move values into
     * another field's column. A stored name whose field is gone from Fanatically
     * is kept as well ('retained'), with its column and values, so a new field
     * can never take it over. Every other field is named in source order: its
     * name cleaned to letters/numbers/underscores, a leading "additional_"
     * dropped (Luster reads that prefix as its own and would lose the value), cut
     * to FIELD_NAME_MAX, and given a _2, _3... suffix when the name is reserved
     * (RESERVED_FIELD_NAMES), already in the Luster listing ($destinationNames),
     * kept for a removed field, or taken by another field here or by another
     * source listing going into the same Luster listing ($claimedElsewhere).
     * Each rename, and every entry that couldn't be used, is reported.
     *
     * Source names travel in a list, never as array keys to iterate: PHP turns
     * a numeric key such as "123" into an integer.
     *
     * @param array<int|string, mixed> $defs             Fanatically custom_fields entries
     * @param array<string,string>     $stored           source field name => name an earlier import gave it
     * @param array<string,true>       $destinationNames names the Luster listing already has
     * @param array<string,true>       $claimedElsewhere names other source listings going into it were given
     * @return array{0: array{label: string, fields: list<array{source: string, name: string, def: array<string,mixed>}>, bySource: array<string,string>, retained: array<string,string>}, 1: list<string>}
     */
    private function planCustomFields(
        array $defs, string $label, array $stored = [], array $destinationNames = [], array $claimedElsewhere = []
    ): array {
        $reserved = array_flip(self::RESERVED_FIELD_NAMES);
        $notes    = [];

        // 1. The usable entries, in source order.
        $entries = []; // list<array{source: string, def: array<string,mixed>, base: string}>
        $seen    = []; // source name => true
        foreach (array_values($defs) as $i => $def) {
            if (!is_array($def)) {
                $notes[] = "{$label}: custom field entry #" . ($i + 1) . " couldn't be read, so it was left out.";
                continue;
            }
            $sourceName = trim((string) ($def['name'] ?? ''));
            if ($sourceName === '') {
                $notes[] = "{$label}: custom field entry #" . ($i + 1) . " has no name, so it was left out.";
                continue;
            }
            if (isset($seen[$sourceName])) {
                $notes[] = "{$label}: there are two fields called \"{$sourceName}\", so only the first one was kept.";
                continue;
            }

            $base = $this->sanitizeFieldName($sourceName);
            while (str_starts_with($base, 'additional_')) {
                $base = substr($base, strlen('additional_'));
            }
            $base = trim(substr($base, 0, self::FIELD_NAME_MAX), '_');
            if ($base === '') {
                $notes[] = "{$label}: the field \"{$sourceName}\" has no letters or numbers to name it by, so it was left out.";
                continue;
            }
            $seen[$sourceName] = true;
            $entries[]         = ['source' => $sourceName, 'def' => $def, 'base' => $base];
        }

        // 2. Names an earlier import gave these fields come first, so they stay put
        //    (unless another source listing going into the same listing has it).
        $taken = $claimedElsewhere; // name => true
        $names = [];                // entry index => name
        foreach ($entries as $i => $entry) {
            $name = $stored[$entry['source']] ?? null;
            if (is_string($name) && $this->isUsableFieldName($name) && !isset($taken[$name])) {
                $taken[$name] = true;
                $names[$i]    = $name;
            }
        }

        // 3. A field that's gone from Fanatically keeps its name, so its column and
        //    values are never handed to a new field.
        $retained = [];
        foreach ($stored as $sourceName => $name) {
            $sourceName = (string) $sourceName;
            if (!isset($seen[$sourceName]) && is_string($name) && $this->isUsableFieldName($name)) {
                $retained[$sourceName] = $name;
                $taken[$name]          = true;
            }
        }

        // 4. Everything else gets its cleaned name, or the next free suffix.
        foreach ($entries as $i => $entry) {
            if (isset($names[$i])) {
                continue;
            }
            $base = $entry['base'];
            $name = $base;
            $n    = 2;
            while (isset($reserved[$name]) || isset($taken[$name]) || isset($destinationNames[$name])) {
                $suffix = '_' . $n++;
                $name   = rtrim(substr($base, 0, self::FIELD_NAME_MAX - strlen($suffix)), '_') . $suffix;
            }
            $taken[$name] = true;
            $names[$i]    = $name;
        }

        $byName   = array_flip($names); // name => entry index
        $fields   = [];
        $bySource = [];
        foreach ($entries as $i => $entry) {
            $sourceName = $entry['source'];
            $name       = $names[$i];
            $base       = $entry['base'];
            $fields[]   = ['source' => $sourceName, 'name' => $name, 'def' => $entry['def']];
            $bySource[$sourceName] = $name;

            $plain = $this->sanitizeFieldName($sourceName);
            if ($name === $plain) {
                continue;
            }
            $why = match (true) {
                ($stored[$sourceName] ?? null) === $name
                    => "that's the name an earlier import gave it",
                $name === $base && str_starts_with($plain, 'additional_')
                    => 'Luster keeps "additional_" for its own use',
                $name === $base
                    => 'Luster field names can be at most ' . self::FIELD_NAME_MAX . ' characters',
                isset($reserved[$base])
                    => "\"{$base}\" is already part of every member's details",
                isset($byName[$base])
                    => "another field already uses \"{$base}\"",
                in_array($base, $retained, true)
                    => "an earlier import used \"{$base}\" for a field that's no longer in Fanatically",
                isset($claimedElsewhere[$base])
                    => "another Fanatically listing going into the same Luster listing already uses \"{$base}\"",
                isset($destinationNames[$base])
                    => "the listing in Luster already has a field called \"{$base}\"",
                default
                    => "another field already uses \"{$base}\"",
            };
            $notes[] = "{$label}: the field \"{$sourceName}\" is saved as \"{$name}\" because {$why}.";
        }

        return [['label' => $label, 'fields' => $fields, 'bySource' => $bySource, 'retained' => $retained], $notes];
    }

    /** A name planCustomFields() could have produced: safe to reuse from storage. */
    private function isUsableFieldName(string $name): bool
    {
        return preg_match('/^[a-z0-9_]+$/', $name) === 1
            && strlen($name) <= self::FIELD_NAME_MAX
            && !str_starts_with($name, 'additional_')
            && !in_array($name, self::RESERVED_FIELD_NAMES, true);
    }

    /**
     * The member-table column for a source field name, using the listing's field
     * plan: an exact match first (a current field, then one kept for a field
     * that's gone from Fanatically), then a field whose cleaned name matches when
     * exactly one of all of them does (stored values sometimes differ from the
     * field definition in spacing or capitals). Null when there's no such field.
     *
     * @param array<string,mixed> $plan
     */
    private function plannedFieldColumn(array $plan, string $sourceName, bool $isOwned): ?string
    {
        // An answer saved under a field that's gone from Fanatically still belongs
        // to that field: Fanatically renames a field's definition without touching
        // the answers members already gave. It goes to the field's own kept column
        // (or is left out and counted when that column is gone), never to the new
        // field whose name happens to clean up the same.
        $retained = $plan['retained'] ?? [];
        $name     = $plan['bySource'][$sourceName] ?? $plan['bySource'][trim($sourceName)]
            ?? $retained[$sourceName] ?? $retained[trim($sourceName)]
            ?? null;
        if ($name === null) {
            $wanted     = $this->sanitizeFieldName($sourceName);
            $candidates = array_map(static fn(array $f): array => [$f['source'], $f['name']], $plan['fields'] ?? []);
            foreach ($retained as $retainedSource => $retainedName) {
                $candidates[] = [(string) $retainedSource, $retainedName];
            }
            $matches = array_values(array_filter(
                $candidates,
                fn(array $c): bool => $wanted !== '' && $this->sanitizeFieldName($c[0]) === $wanted,
            ));
            $name = count($matches) === 1 ? $matches[0][1] : null;
        }

        if ($name === null) {
            return null;
        }
        return $isOwned ? $name : 'additional_' . $name;
    }

    /**
     * Makes sure every imported listing has somewhere to keep members before any
     * member is written, whether or not it has members (or Members is ticked):
     *  - a listing created by this import gets a member table with its planned
     *    field columns, plus its field definitions;
     *  - a listing updated by Overwrite gets any missing table or field columns
     *    added, and any missing field definitions;
     *  - a listing that was already in Luster and kept under Skip isn't changed
     *    at all; its existing table and columns are just looked up.
     * Fanatically listings that share an address go into one Luster listing, so
     * they're set up together with all of their fields. A Luster listing this
     * import created gets every one of their fields, even when the later source
     * listings were kept (Skip) because the first one had just created it.
     * A failure only affects that one Luster listing and is reported.
     *
     * @param array<int,int>                 $listingIdMap
     * @param array<int,string>              $listingDbtables
     * @param array<int,string>              $listingOutcomes 'created' | 'updated' | 'kept'
     * @param array<int,array<string,mixed>> $plans
     * @return array{storage: array<int,array<string,mixed>>, warnings: list<string>, errors: list<string>}
     */
    private function provisionMemberStorage(
        PDO $target, string $targetSchema,
        array $listingIdMap, array $listingDbtables, array $listingOutcomes, array $plans
    ): array {
        [$regTable, $pkCol, $isOwned] = $this->targetRegistryInfo($targetSchema);

        $storage  = [];
        $warnings = [];
        $errors   = [];

        // Source listings by the Luster listing they went into.
        $groups = [];
        foreach ($listingIdMap as $srcId => $newId) {
            $groups[$newId][] = (int) $srcId;
        }

        foreach ($groups as $newId => $srcIds) {
            $plansHere = [];
            $labels    = [];
            $outcomes  = [];
            $dbtable   = null;
            foreach ($srcIds as $srcId) {
                $plansHere[$srcId] = $plans[$srcId] ?? ['label' => "listing #{$srcId}", 'fields' => [], 'bySource' => [], 'retained' => []];
                $labels[]          = (string) $plansHere[$srcId]['label'];
                $outcomes[]        = $listingOutcomes[$srcId] ?? 'kept';
                $dbtable         ??= $listingDbtables[$srcId] ?? null;
            }
            $outcome = in_array('created', $outcomes, true) ? 'created' : (in_array('updated', $outcomes, true) ? 'updated' : 'kept');

            $result = $this->provisionListingStorage(
                $target, $isOwned, $regTable, $pkCol, (int) $newId, $dbtable, $outcome,
                implode(' / ', array_unique($labels)), $plansHere
            );
            $warnings = array_merge($warnings, $result['warnings']);
            $errors   = array_merge($errors, $result['errors']);

            foreach ($srcIds as $srcId) {
                $storage[$srcId] = $result['entry'] + ['plan' => $plansHere[$srcId]];
            }
        }

        return ['storage' => $storage, 'warnings' => $warnings, 'errors' => $errors];
    }

    /**
     * provisionMemberStorage() for one Luster listing and the source listings
     * going into it ($plans, keyed by source listing id).
     *
     * Their field names are saved with their import records (saveFieldMap())
     * before any column is created, and the listing is only set up once that
     * worked. Otherwise a repeat import would find columns it has no record of,
     * treat them as the listing's own and give the same fields new names.
     *
     * @param array<int,array<string,mixed>> $plans
     * @return array{entry: array{table: string, columns: array<string,true>, usable: bool, reason: string}, warnings: list<string>, errors: list<string>}
     */
    private function provisionListingStorage(
        PDO $target, bool $isOwned, string $regTable, string $pkCol,
        int $newId, ?string $dbtable, string $outcome, string $label, array $plans
    ): array {
        $entry = ['table' => '', 'columns' => [], 'usable' => false, 'reason' => ''];

        if ($dbtable === null) {
            $dtCol = $isOwned ? 'dbtable' : 'dbprefix';
            try {
                $st = $target->prepare("SELECT {$dtCol} FROM `{$regTable}` WHERE {$pkCol}=:id");
                $st->execute(['id' => $newId]);
                $dbtable = (string) ($st->fetchColumn() ?: '');
            } catch (\Throwable) {
                $dbtable = '';
            }
        }
        if ($dbtable === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $dbtable)) {
            $entry['reason'] = "The listing has no usable member table name in Luster";
            return ['entry' => $entry, 'errors' => [],
                    'warnings' => ["{$label}: it has no usable member table name in Luster, so its members weren't imported."]];
        }

        $fields = [];
        foreach ($plans as $plan) {
            $fields = array_merge($fields, $plan['fields']);
        }

        // enth_ schema: MemberRepo reads {dbprefix}members, with additional_ columns.
        $table          = $isOwned ? $dbtable : $dbtable . 'members';
        $entry['table'] = $table;
        $wanted         = array_map(
            static fn(array $f): string => $isOwned ? $f['name'] : 'additional_' . $f['name'],
            $fields,
        );

        // Never alter (or write members into) a table that isn't this listing's
        // member table: Luster's own tables, a table that already existed for a
        // brand-new listing, or an existing table that isn't shaped like one.
        if ($this->isProtectedTableName($table)) {
            $entry['reason'] = "The listing points at {$table}, which is one of Luster's own tables";
            return ['entry' => $entry, 'warnings' => [],
                    'errors' => ["{$label}: it points at {$table}, which is one of Luster's own tables, so nothing was changed and its members weren't imported."]];
        }

        $warnings = [];
        try {
            $columns = $this->memberTableColumns($target, $table);

            $refusal = null;
            if ($columns !== null && $outcome === 'created') {
                $refusal = "a table called {$table} already exists";
            } elseif ($columns !== null && !$this->looksLikeMemberTable($columns, $isOwned)) {
                $refusal = "its table {$table} doesn't look like a member table";
            }
            if ($refusal !== null) {
                $entry['reason'] = ucfirst($refusal);
                return ['entry' => $entry, 'warnings' => [],
                        'errors' => ["{$label}: {$refusal}, so nothing was changed and its members weren't imported."]];
            }

            if ($outcome === 'kept' && $columns === null) {
                $entry['reason'] = 'The listing was already in Luster and was left as it is (Skip), but it has no member table';
                return ['entry' => $entry, 'errors' => [],
                        'warnings' => ["{$label}: it was already in Luster and was left as it is (Skip), but it has no member table, so its members weren't imported."]];
            }

            // The names first: nothing is created (or written) that the next import
            // couldn't recognise as this import's own.
            foreach ($plans as $srcId => $plan) {
                if (!$this->saveFieldMap($target, (int) $srcId, $newId, $plan)) {
                    $entry['reason'] = "Luster couldn't save which of the listing's fields is which";
                    return ['entry' => $entry, 'warnings' => [],
                            'errors' => ["{$label}: Luster couldn't save which of its custom fields is which, so nothing was changed and its members weren't imported. Import again to try once more."]];
                }
            }

            if ($outcome === 'kept') {
                // Nothing in the listing changes; its names are remembered (above),
                // so a later Overwrite gives its fields the same ones.
                $entry['columns'] = $columns;
                $entry['usable']  = true;
                return ['entry' => $entry, 'warnings' => [], 'errors' => []];
            }

            if ($columns === null) {
                $this->createMemberTable($target, $table, $wanted, $isOwned);
            } else {
                $this->addMemberColumns($target, $table, array_values(array_diff($wanted, array_keys($columns))));
            }
            $entry['columns'] = $this->memberTableColumns($target, $table) ?? [];
            $entry['usable']  = $entry['columns'] !== [];
            if (!$entry['usable']) {
                $entry['reason'] = "The listing's member table couldn't be read after it was set up";
            }
        } catch (\Throwable $e) {
            $entry['reason'] = "The listing's member table couldn't be set up";
            return ['entry' => $entry, 'warnings' => [],
                    'errors' => ["{$label}: its member table couldn't be set up ({$e->getMessage()}), so its members weren't imported."]];
        }

        if (!$this->persistCustomFieldDefinitions($target, $newId, $isOwned, $fields)) {
            $warnings[] = "{$label}: its custom field definitions couldn't be saved. The fields' columns are there; add them on the listing's Fields page.";
        }

        return ['entry' => $entry, 'warnings' => $warnings, 'errors' => []];
    }

    /**
     * True when some source listing has custom fields but Luster has nowhere to
     * remember their names: no metadata_json, which Migration 91 adds.
     *
     * @param array<int,array<string,mixed>> $plans
     */
    private function fieldNamesCantBeKept(PDO $target, array $plans): bool
    {
        foreach ($plans as $plan) {
            if ($plan['fields'] !== []) {
                return !$this->refsHaveMetadata($target);
            }
        }
        return false;
    }

    /**
     * Stores the names this import gave a source listing's custom fields with
     * its import record (lstr_listing_external_refs.metadata_json 'field_map'),
     * so the next import reuses them: the plan's fields plus the names kept for
     * fields that are gone from Fanatically. It replaces what was there, so a map
     * an earlier import left for another Luster listing is cleared, even when the
     * listing has no fields now.
     *
     * The names are only saved on a record that points at $listingId, the Luster
     * listing they're for. A record still naming another listing (moving it
     * failed) keeps that listing's names untouched.
     *
     * Returns whether the names are safely stored on this listing's record: it
     * reads them back after writing. With nothing to store it's true; with names
     * to store and no import record, a record for another listing, or no
     * metadata_json column (Migration 91) it's false.
     *
     * @param array<string,mixed> $plan
     */
    private function saveFieldMap(PDO $target, int $srcId, int $listingId, array $plan): bool
    {
        $map = [];
        foreach ($plan['retained'] ?? [] as $sourceName => $name) {
            $map[(string) $sourceName] = $name;
        }
        foreach ($plan['fields'] as $field) {
            $map[$field['source']] = $field['name'];
        }

        if (!$this->refsHaveMetadata($target)) {
            return $map === [];
        }

        $read = static function () use ($target, $srcId): array|false {
            $st = $target->prepare(
                "SELECT listing_id, metadata_json FROM lstr_listing_external_refs WHERE source = 'fanatically' AND external_id = :eid"
            );
            $st->execute(['eid' => (string) $srcId]);
            return $st->fetch(PDO::FETCH_ASSOC);
        };

        try {
            $record = $read();
            if ($record === false || (int) $record['listing_id'] !== $listingId) {
                return $map === []; // no record for this listing to keep the names with
            }
            $meta = json_decode((string) ($record['metadata_json'] ?? ''), true);
            $meta = is_array($meta) ? $meta : [];
            if ($map === [] && !array_key_exists('field_map', $meta)) {
                return true;
            }
            unset($meta['field_map']);
            if ($map !== []) {
                $meta['field_map'] = $map;
            }

            $target->prepare(
                "UPDATE lstr_listing_external_refs SET metadata_json = :meta
                  WHERE source = 'fanatically' AND external_id = :eid AND listing_id = :lid"
            )->execute(['meta' => $this->encodeRefMetadata($meta), 'eid' => (string) $srcId, 'lid' => $listingId]);

            $saved = $read();
            if ($saved === false || (int) $saved['listing_id'] !== $listingId) {
                return false;
            }
            $savedMeta = json_decode((string) ($saved['metadata_json'] ?? ''), true);
            $savedMap  = is_array($savedMeta) && is_array($savedMeta['field_map'] ?? null) ? $savedMeta['field_map'] : [];
            return $this->sameFieldMap($savedMap, $map);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether two source → Luster field-name maps hold the same names (keys that
     * look like numbers come back from JSON as integers).
     *
     * @param array<int|string,mixed> $a
     * @param array<int|string,mixed> $b
     */
    private function sameFieldMap(array $a, array $b): bool
    {
        $normalize = static function (array $map): array {
            $out = [];
            foreach ($map as $sourceName => $name) {
                $out[(string) $sourceName] = $name;
            }
            ksort($out, SORT_STRING);
            return $out;
        };
        return $normalize($a) === $normalize($b);
    }

    /**
     * metadata_json for an import record, or null when there's nothing to keep.
     * field_map is always written as an object, so a source field called "0"
     * stays a name.
     *
     * @param array<string,mixed> $meta
     */
    private function encodeRefMetadata(array $meta): ?string
    {
        if ($meta === []) {
            return null;
        }
        if (is_array($meta['field_map'] ?? null)) {
            $meta['field_map'] = (object) $meta['field_map'];
        }
        return json_encode($meta);
    }

    /**
     * Luster listings a Fanatically listing can match: by address (lowercase,
     * no trailing slash), and by the Fanatically id an earlier import recorded
     * (only for listings still in the registry, so a deleted listing doesn't
     * absorb a re-imported one), plus every listing's member table name.
     * It's read once per preview or import, before anything is written, and
     * runListings() and readCustomFieldPlans() both match with that copy, each
     * adding the listings it creates, so they always agree. Throws when either
     * read fails: an empty index would make every listing look new.
     *
     * @return array{urls: array<string,int>, byExternal: array<string,int>, dbtables: array<int,string>}
     */
    private function existingListingIndex(PDO $target, string $regTable, string $pkCol, bool $isOwned): array
    {
        $urls     = [];
        $dbtables = [];
        $dtCol    = $isOwned ? 'dbtable' : 'dbprefix';
        $stmt     = $target->query("SELECT {$pkCol}, url, {$dtCol} FROM `{$regTable}`");
        if ($stmt === false) {
            throw new \RuntimeException("Couldn't read {$regTable}.");
        }
        foreach ($stmt->fetchAll() as $row) {
            $uKey = strtolower(rtrim((string) $row['url'], '/'));
            $rid  = (int) $row[$pkCol];
            if ($uKey !== '') {
                $urls[$uKey] = $rid;
            }
            $dbtables[$rid] = (string) $row[$dtCol];
        }

        $byExternal = [];
        $stmt       = $target->prepare(
            "SELECT listing_id, external_id FROM lstr_listing_external_refs WHERE source = 'fanatically'"
        );
        if ($stmt === false || !$stmt->execute()) {
            throw new \RuntimeException("Couldn't read lstr_listing_external_refs.");
        }
        foreach ($stmt->fetchAll() as $row) {
            if (isset($dbtables[(int) $row['listing_id']])) {
                $byExternal[(string) $row['external_id']] = (int) $row['listing_id'];
            }
        }

        return ['urls' => $urls, 'byExternal' => $byExternal, 'dbtables' => $dbtables];
    }

    /**
     * The parts of Luster's database that decide where listings go, read once,
     * strictly, before anything is written: whether import records can hold field
     * names (refsHaveMetadata(), then remembered for the rest of the run), the
     * existing-listing index, what the lifecycle table can hold
     * (lifecycleTableState()), and for an import every table name. Null when any of
     * them couldn't be read: the import then stops (TARGET_UNREADABLE), because
     * each of them read as empty would put something in the wrong place.
     *
     * @return array{index: array{urls: array<string,int>, byExternal: array<string,int>, dbtables: array<int,string>}, tables: list<string>, lifecycle: string}|null
     */
    private function readListingTargetState(PDO $target, string $targetSchema, bool $withTables): ?array
    {
        [$regTable, $pkCol, $isOwned] = $this->targetRegistryInfo($targetSchema);
        $this->refsHaveMetadata = null;
        try {
            $this->refsHaveMetadata($target);
            return [
                'index'     => $this->existingListingIndex($target, $regTable, $pkCol, $isOwned),
                'tables'    => $withTables ? $this->physicalTableNames($target) : [],
                'lifecycle' => $this->lifecycleTableState($target),
                'previousOwners' => $this->previousOwnersTableState($target),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether lstr_listing_previous_owners is there (Migration 102): 'missing' or
     * 'present'. Throws when it can't be checked.
     */
    private function previousOwnersTableState(PDO $target): string
    {
        if ($target->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $st = $target->query('PRAGMA table_info(`lstr_listing_previous_owners`)');
            if ($st === false) {
                throw new \RuntimeException("Couldn't read lstr_listing_previous_owners.");
            }
            return $st->fetchAll(PDO::FETCH_ASSOC) === [] ? 'missing' : 'present';
        }

        $st = $target->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lstr_listing_previous_owners'"
        );
        if ($st === false || !$st->execute()) {
            throw new \RuntimeException("Couldn't read lstr_listing_previous_owners.");
        }
        return (int) $st->fetchColumn() > 0 ? 'present' : 'missing';
    }

    /**
     * Whether any Fanatically listing has previous owners Luster would have to
     * keep (the Migration 102 guard). An explicit [] or a list with nothing
     * usable doesn't count: without the table there's nothing to clear.
     *
     * @param array<int, array<string,mixed>> $plans readCustomFieldPlans()
     */
    private function plansHavePreviousOwners(array $plans): bool
    {
        foreach ($plans as $plan) {
            if (($plan['previousOwners']['state'] ?? '') === 'list') {
                return true;
            }
        }
        return false;
    }

    /**
     * A Fanatically listing's previous owners (listings.previous_owners, a JSON
     * list of {name, url}), and what Luster may do with them:
     *
     *  - 'no_column' / 'no_value': Fanatically has no information (an install
     *    from before 1.1.10, or a listing not saved since), so Luster's are
     *    left alone;
     *  - 'malformed': not a JSON list (an object such as {} included); left
     *    alone, with a note;
     *  - 'clear': exactly [], so Fanatically says there are none;
     *  - 'list': at least one usable entry, in order, repeats kept;
     *  - 'unusable': a list where none of the entries can be used; left alone,
     *    with a note, and never taken for [].
     *
     * An entry is used when it's an object whose name and url are strings (or
     * missing). Names are kept as stored, trimmed: Fanatically shows them escaped,
     * as Luster does. A link that isn't http(s) (in any case: HTTPS:// is kept
     * as written) or is over 500 characters is dropped and the name kept; an
     * entry with only a bad link is left out; a name over 255 characters is cut
     * at a character. 'key' is the entry as
     * Fanatically has it, so a repeat is spotted before anything was cut.
     *
     * @param array<string,mixed> $row A source listings row
     * @return array{state: string, owners: list<array{name: string, url: string, key: string}>, notes: list<string>}
     */
    private function parsePreviousOwners(array $row, string $label): array
    {
        $result = static fn(string $state, array $owners = [], array $notes = []): array
            => ['state' => $state, 'owners' => $owners, 'notes' => $notes];

        if (!array_key_exists('previous_owners', $row)) {
            return $result('no_column');
        }
        $raw = $row['previous_owners'];
        if ($raw === null || trim((string) $raw) === '') {
            return $result('no_value');
        }
        // Without assoc, a JSON object stays an object, so {} is never mistaken for [].
        $decoded = json_decode((string) $raw);
        if (!is_array($decoded)) {
            return $result('malformed', [], ["{$label}: its previous owners couldn't be read, so Luster left them alone."]);
        }
        if ($decoded === []) {
            return $result('clear');
        }

        $owners   = [];
        $unusable = 0; // not an object, a field that isn't text, or only a bad link
        $linkless = 0;
        $cut      = 0;
        foreach ($decoded as $entry) {
            if (!$entry instanceof \stdClass) {
                $unusable++;
                continue;
            }
            $name = $entry->name ?? null;
            $url  = $entry->url ?? null;
            if (($name !== null && !is_string($name)) || ($url !== null && !is_string($url))) {
                $unusable++;
                continue;
            }
            $name = trim((string) $name);
            $url  = trim((string) $url);
            if ($name === '' && $url === '') {
                continue; // a blank row, which Fanatically doesn't show either
            }
            $key = $name . "\x00" . $url;
            if ($url !== '' && (mb_strlen($url) > 500
                || filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                if ($name === '') {
                    $unusable++;
                    continue;
                }
                $url = '';
                $linkless++;
            }
            if (mb_strlen($name) > 255) {
                $name = mb_substr($name, 0, 255);
                $cut++;
            }
            $owners[] = ['name' => $name, 'url' => $url, 'key' => $key];
        }

        if ($owners === []) {
            return $result('unusable', [], ["{$label}: none of its previous owners could be used, so Luster left them alone."]);
        }
        $notes = [];
        if ($unusable > 0) {
            $notes[] = "{$label}: {$unusable} of its previous owners couldn't be read and were left out.";
        }
        if ($linkless > 0) {
            $notes[] = "{$label}: {$linkless} previous owner link(s) aren't web addresses Luster can use (or are over 500 characters), so those previous owners are saved without a link.";
        }
        if ($cut > 0) {
            $notes[] = "{$label}: {$cut} previous owner name(s) were over 255 characters and were shortened.";
        }
        return $result('list', $owners, $notes);
    }

    /**
     * What happens to each Luster listing's previous owners, worked out from
     * every Fanatically listing planned to go into it (readCustomFieldPlans()'s
     * 'destination'), in source order. The preview and the import both use it,
     * so they combine, count and hold back previous owners the same way:
     *
     *  - created by this import: the previous owners of each of them that was
     *    saved or kept, an exact repeat from a later one dropped. Nothing was
     *    there to lose, so one that wasn't imported costs nothing, and the next
     *    Overwrite brings the full set.
     *  - already in Luster, Skip: left alone ('kept').
     *  - already in Luster, Overwrite: replaced with theirs (an explicit [] clears
     *    them), but only when every one of them with previous owners, or with
     *    broken ones, was saved and readable. Otherwise they're left alone
     *    ('held') with a note saying why, so a sibling that's missing can't have
     *    its previous owners erased.
     *
     * $listingOutcomes is what runListings() did with each Fanatically listing
     * (created | updated | kept). The preview passes null: there, every listing
     * is expected to be saved, as the Owned Listings row expects.
     *
     * Each group has its 'action' ('write', 'kept', 'held', 'unsaved' when none
     * of its listings was saved, or 'none' for a new listing with none to
     * write), a 'label', the source listings in it that were 'saved' (the first
     * one's Luster id is the listing), the 'owners' to write ([] = clear), how
     * many previous owners its listings have in all ('found') and how many of
     * those aren't written ('skipped'). The notes are the parser's, then why any
     * were held, then how many Skip kept.
     *
     * @param array<int, array<string,mixed>> $plans           readCustomFieldPlans(), keyed by source listing id
     * @param array<int,string>|null          $listingOutcomes Source listing id → created | updated | kept
     * @return array{groups: list<array<string,mixed>>, notes: list<string>}
     */
    private function planPreviousOwners(array $plans, bool $overwrite, ?array $listingOutcomes): array
    {
        $bySource = []; // destination → its source listing ids, in source order
        $notes    = [];
        foreach ($plans as $srcId => $plan) {
            $notes = array_merge($notes, $plan['previousOwners']['notes'] ?? []);
            $bySource[(int) ($plan['destination'] ?? 0)][] = (int) $srcId;
        }
        $outcomeOf = static fn(int $srcId, int $destination): ?string => $listingOutcomes === null
            ? ($destination < 0 ? 'created' : ($overwrite ? 'updated' : 'kept'))
            : ($listingOutcomes[$srcId] ?? null);

        $groups = [];
        $kept   = 0; // listings already in Luster that Skip leaves with their own
        foreach ($bySource as $destination => $srcIds) {
            if ($destination === 0) {
                continue;
            }
            $states = [];
            $found  = 0;
            foreach ($srcIds as $srcId) {
                $states[$srcId] = (string) ($plans[$srcId]['previousOwners']['state'] ?? 'no_column');
                $found         += count($plans[$srcId]['previousOwners']['owners'] ?? []);
            }
            $relevant = array_keys(array_filter($states, static fn(string $s): bool => in_array($s, ['clear', 'list', 'malformed', 'unusable'], true)));
            if ($relevant === []) {
                continue;
            }
            $saved = array_values(array_filter($srcIds, static fn(int $s): bool => $outcomeOf($s, $destination) !== null));
            $group = [
                'destination' => $destination,
                'label'       => implode(' / ', array_unique(array_map(static fn(int $s): string => (string) $plans[$s]['label'], $srcIds))),
                'saved'       => $saved,
                'owners'      => [],
                'found'       => $found,
                'skipped'     => $found,
            ];

            if ($saved === []) {
                $groups[] = ['action' => 'unsaved'] + $group; // the listing itself wasn't imported (reported there)
                continue;
            }
            if ($destination > 0 && !$overwrite) {
                $kept    += $found > 0 ? 1 : 0;
                $groups[] = ['action' => 'kept'] + $group;
                continue;
            }
            if ($destination > 0) {
                $why = null;
                foreach ($relevant as $srcId) {
                    $other = (string) $plans[$srcId]['label'];
                    if ($outcomeOf($srcId, $destination) !== 'updated') {
                        $why = "\"{$other}\", which has the same address in Fanatically, wasn't imported. Import again with Overwrite to bring them over.";
                        break;
                    }
                    if ($states[$srcId] === 'malformed' || $states[$srcId] === 'unusable') {
                        // On its own, its note already says they were left alone.
                        $why = count($relevant) > 1
                            ? "the previous owners of \"{$other}\", which has the same address in Fanatically, couldn't be used. Fix them in Fanatically, then import again with Overwrite to bring them over."
                            : '';
                        break;
                    }
                }
                if ($why !== null) {
                    if ($why !== '') {
                        $notes[] = "{$group['label']}: previous owners weren't changed, because {$why}";
                    }
                    $groups[] = ['action' => 'held'] + $group;
                    continue;
                }
            }

            $owners  = [];
            $skipped = 0;
            $earlier = []; // entries from earlier source listings, as Fanatically has them
            foreach ($srcIds as $srcId) {
                $mine = $plans[$srcId]['previousOwners']['owners'] ?? [];
                if (!in_array($srcId, $saved, true)) {
                    $skipped += count($mine);
                    continue;
                }
                $added = [];
                foreach ($mine as $owner) {
                    if (isset($earlier[$owner['key']])) {
                        $skipped++;
                        continue;
                    }
                    $owners[]             = $owner;
                    $added[$owner['key']] = true;
                }
                $earlier += $added;
            }
            // A new listing has none to clear.
            $action   = $destination < 0 && $owners === [] ? 'none' : 'write';
            $groups[] = ['action' => $action, 'owners' => $owners, 'skipped' => $skipped] + $group;
        }
        if ($kept > 0) {
            $notes[] = "{$kept} listing(s) already in Luster keep their own previous owners, because you chose Skip.";
        }
        return ['groups' => $groups, 'notes' => $notes];
    }

    /**
     * The preview's "Previous Owners" row, from planPreviousOwners(), so it
     * counts and holds back exactly what the import will: how many there are,
     * how many get written, how many don't (repeats, ones Skip leaves, ones held
     * back), and every note, with each listing an Overwrite will clear named.
     * Like the Owned Listings row, it doesn't read what Luster has now, so one
     * that already matches still counts as written. Null when no listing has any
     * previous-owner information.
     *
     * @param array<string,mixed>             $state readListingTargetState()
     * @param array<int, array<string,mixed>> $plans readCustomFieldPlans()
     * @return array<string,mixed>|null
     */
    private function previewPreviousOwners(ImportOptions $options, array $state, array $plans): ?array
    {
        $notes = [];
        $any   = false;
        foreach ($plans as $plan) {
            $notes = array_merge($notes, $plan['previousOwners']['notes'] ?? []);
            $any   = $any || in_array($plan['previousOwners']['state'] ?? '', ['clear', 'list', 'malformed', 'unusable'], true);
        }
        if (!$any) {
            return null;
        }
        // Before Migration 102 the guard refused anything with previous owners,
        // so what's left is [] or nothing usable, and nowhere to clear them.
        if (($state['previousOwners'] ?? 'missing') !== 'present') {
            return ['type' => 'Previous Owners', 'found' => 0, 'import' => 0, 'skip' => 0, 'warnings' => $notes];
        }

        $plan   = $this->planPreviousOwners($plans, $options->conflictStrategy !== 'skip', null);
        $notes  = $plan['notes'];
        $found  = 0;
        $import = 0;
        $skip   = 0;
        foreach ($plan['groups'] as $group) {
            $found += $group['found'];
            $skip  += $group['skipped'];
            if ($group['action'] === 'write') {
                $import += count($group['owners']);
                if ($group['owners'] === []) {
                    $notes[] = "{$group['label']}: Fanatically has no previous owners for it, so Overwrite clears the ones it has in Luster.";
                }
            }
        }
        return ['type' => 'Previous Owners', 'found' => $found, 'import' => $import, 'skip' => $skip, 'warnings' => $notes];
    }

    /**
     * Brings the previous owners over, after the Listings step and before any
     * member-table DDL, as planPreviousOwners() works them out from what the
     * Listings step really saved.
     *
     * Each write is checked (writePreviousOwners()). A failure is that listing's
     * error and the import goes on, unless its transaction couldn't be finished:
     * then nothing more is written (transactionLeftOpen).
     *
     * @param array<string,mixed>             $state           readListingTargetState()
     * @param array<int, array<string,mixed>> $plans           readCustomFieldPlans(), keyed by source listing id
     * @param array<int,int>                  $listingIdMap    Source listing id → the Luster listing it's in
     * @param array<int,string>               $listingOutcomes Source listing id → created | updated | kept
     * @return array<string,mixed> The "Previous Owners" result row
     */
    private function importPreviousOwners(
        PDO $target, ImportOptions $options, array $state, array $plans, array $listingIdMap, array $listingOutcomes
    ): array {
        $result = ['type' => 'Previous Owners', 'imported' => 0, 'skipped' => 0, 'warnings' => [], 'errors' => [], 'skipped_items' => []];
        // Before Migration 102 the guard refused anything with previous owners,
        // so what's left is [] or nothing usable: there's nothing to clear.
        if (($state['previousOwners'] ?? 'missing') !== 'present') {
            foreach ($plans as $plan) {
                $result['warnings'] = array_merge($result['warnings'], $plan['previousOwners']['notes'] ?? []);
            }
            return $result;
        }

        // Saved means it has a Luster listing to write to.
        $plan   = $this->planPreviousOwners($plans, $options->conflictStrategy !== 'skip', array_intersect_key($listingOutcomes, $listingIdMap));
        $groups = $plan['groups'];
        $result['warnings'] = $plan['notes'];
        foreach ($groups as $i => $group) {
            $result['skipped'] += $group['skipped'];
            if ($group['action'] !== 'write') {
                continue;
            }
            $label  = (string) $group['label'];
            $owners = $group['owners'];
            $write  = $this->writePreviousOwners($target, (int) $listingIdMap[$group['saved'][0]], $owners);
            $count  = count($owners);
            $error  = $write['error'];
            switch ($write['outcome']) {
                case 'unchanged':
                    $result['skipped'] += $count;
                    break;
                case 'saved':
                    $result['imported'] += $count;
                    if ($count === 0) {
                        $result['warnings'][] = "{$label}: previous owners cleared, to match Fanatically.";
                    }
                    break;
                case 'unread':
                    $result['skipped'] += $count;
                    $result['errors'][]  = "Listing '{$label}': Luster couldn't read the previous owners it already has ({$error}), so it left them alone. Import again with Overwrite to try once more.";
                    break;
                case 'restored':
                    $result['skipped'] += $count;
                    $result['errors'][]  = "Listing '{$label}': Luster couldn't save its previous owners ({$error}), so they're as they were. Import again with Overwrite to try once more.";
                    break;
                case 'changed':
                    $now  = (array) $write['now'];
                    $have = count($now);
                    $what = $have > 0 && $have < $count && array_slice($write['want'], 0, $have) === $now
                        ? "only the first {$have} of its {$count} previous owners were saved"
                        : "Luster now has {$have} previous owner(s) for it, which isn't what was there before or what Fanatically has";
                    $result['skipped'] += $count;
                    $result['errors'][]  = "Listing '{$label}': "
                        . ($error !== '' ? "Luster couldn't save its previous owners ({$error}), and they're not as they were either: " : 'its previous owners came out different from what was saved: ')
                        . "{$what}. Import again with Overwrite to finish it.";
                    break;
                case 'unverified':
                    $result['skipped'] += $count;
                    $result['errors'][]  = "Listing '{$label}': "
                        . ($error !== ''
                            ? "Luster couldn't save its previous owners ({$error}) and couldn't check what's there now ({$write['checkError']}). Check them on the listing's edit page, then import again with Overwrite."
                            : "Luster saved its previous owners but couldn't check them afterwards ({$write['checkError']}). Check them on the listing's edit page.");
                    break;
                default: // left_open
                    $result['skipped'] += $count;
                    $result['errors'][]  = "Listing '{$label}': Luster couldn't save its previous owners ({$error}) or finish undoing that.";
                    $left = count(array_filter(array_slice($groups, $i + 1), static fn(array $g): bool => $g['action'] === 'write'));
                    if ($left > 0) {
                        $result['errors'][] = "The previous owners of the {$left} listing(s) after it weren't imported.";
                    }
                    break 2;
            }
        }
        return $result;
    }

    /**
     * Replaces a listing's previous owners with $owners and says, from what is
     * really there afterwards, how it went:
     *
     *  - 'unchanged': they already matched, so nothing was written;
     *  - 'saved': they match now;
     *  - 'restored': the write failed and they're exactly as they were;
     *  - 'changed': they match neither (described from 'now');
     *  - 'unverified': they couldn't be read back;
     *  - 'unread': the ones there couldn't be read first, so nothing was written;
     *  - 'left_open': the write failed and couldn't be undone (finishFailedTransaction()).
     *
     * Its own transaction (the listing's save committed before it), written
     * straight to the table, never through PreviousOwnersRepo::replaceForListing(),
     * which hides failures. Compared as ordered (name, link) pairs.
     *
     * @param list<array{name: string, url: string}> $owners
     * @return array{outcome: string, error: string, checkError: string, now: list<array{0: string, 1: string}>|null, want: list<array{0: string, 1: string}>}
     */
    private function writePreviousOwners(PDO $target, int $listingId, array $owners): array
    {
        $want = array_map(static fn(array $o): array => [$o['name'], $o['url']], $owners);
        $out  = static fn(string $outcome, string $error = '', string $checkError = '', ?array $now = null): array
            => ['outcome' => $outcome, 'error' => $error, 'checkError' => $checkError, 'now' => $now, 'want' => $want];

        try {
            $before = $this->readPreviousOwnerRows($target, $listingId);
        } catch (\Throwable $e) {
            return $out('unread', $e->getMessage());
        }
        if ($before === $want) {
            return $out('unchanged');
        }

        $error = '';
        try {
            if (!$target->beginTransaction()) {
                throw new \RuntimeException("the database didn't start the save");
            }
            $st = $target->prepare('DELETE FROM lstr_listing_previous_owners WHERE fanlisting_id = :fid');
            if ($st === false || !$st->execute(['fid' => $listingId])) {
                throw new \RuntimeException("the database didn't accept it");
            }
            foreach ($want as $i => [$name, $url]) {
                $st = $target->prepare(
                    'INSERT INTO lstr_listing_previous_owners (fanlisting_id, owner_name, owner_url, sort_order)
                     VALUES (:fid, :name, :url, :sort)'
                );
                if ($st === false || !$st->execute(['fid' => $listingId, 'name' => $name, 'url' => $url, 'sort' => $i])) {
                    throw new \RuntimeException("the database didn't accept it");
                }
            }
            $target->commit();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if (!$this->finishFailedTransaction($target)) {
                return $out('left_open', $error);
            }
        }

        try {
            $now = $this->readPreviousOwnerRows($target, $listingId);
        } catch (\Throwable $e) {
            return $out('unverified', $error, $e->getMessage());
        }
        return match (true) {
            $now === $want   => $out('saved', $error),
            $now === $before => $out('restored', $error !== '' ? $error : "it didn't take"),
            default          => $out('changed', $error, '', $now),
        };
    }

    /**
     * A listing's previous owners as (name, link) pairs, in the order they show.
     * Throws when they can't be read.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function readPreviousOwnerRows(PDO $target, int $listingId): array
    {
        $st = $target->prepare(
            'SELECT owner_name, owner_url FROM lstr_listing_previous_owners
              WHERE fanlisting_id = :fid ORDER BY sort_order ASC, id ASC'
        );
        if ($st === false || !$st->execute(['fid' => $listingId])) {
            throw new \RuntimeException("the database didn't answer");
        }
        return array_map(
            static fn(array $r): array => [(string) $r['owner_name'], (string) $r['owner_url']],
            $st->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * After a failed write, rolls back the transaction it was in. False when one
     * is still open afterwards: anything run next (member-table DDL, the SQL dump
     * clean-up's DROPs) would commit it half-done, so transactionLeftOpen is set
     * and run() stops before writing anything else.
     */
    private function finishFailedTransaction(PDO $target): bool
    {
        if ($target->inTransaction()) {
            try {
                $target->rollBack();
            } catch (\Throwable) {}
        }
        if ($target->inTransaction()) {
            $this->transactionLeftOpen = true;
            return false;
        }
        return true;
    }

    /**
     * What lstr_listing_lifecycle can hold: 'missing' (before Migration 93),
     * 'not_null' (before Migration 108, no unknown dates), or 'nullable'. Throws
     * when it can't be checked.
     */
    private function lifecycleTableState(PDO $target): string
    {
        if ($target->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $st = $target->query('PRAGMA table_info(`lstr_listing_lifecycle`)');
            if ($st === false) {
                throw new \RuntimeException("Couldn't read lstr_listing_lifecycle.");
            }
            $columns = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($columns === []) {
                return 'missing';
            }
            foreach ($columns as $column) {
                if (($column['name'] ?? '') === 'archived_at') {
                    return (int) $column['notnull'] === 0 ? 'nullable' : 'not_null';
                }
            }
            return 'not_null';
        }

        $st = $target->prepare(
            "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lstr_listing_lifecycle' AND COLUMN_NAME = 'archived_at'"
        );
        if ($st === false || !$st->execute()) {
            throw new \RuntimeException("Couldn't read lstr_listing_lifecycle.");
        }
        $nullable = $st->fetchColumn();
        return match (true) {
            $nullable === false => 'missing',
            $nullable === 'YES' => 'nullable',
            default             => 'not_null',
        };
    }

    /**
     * Whether any Fanatically listing is Closed or Adopted Out. True when that
     * can't be read, so the Migration 108 check errs on the side of asking.
     */
    private function sourceHasArchivedListings(PDO $source, string $p): bool
    {
        try {
            $st = $source->query("SELECT COUNT(*) FROM `{$p}listings` WHERE status IN ('closed', 'adopted_out')");
            return $st === false || (int) $st->fetchColumn() > 0;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Brings a listing's Closed / Adopted Out state over from Fanatically, in the
     * same transaction as its registry row (runListings()), so the two never
     * disagree. Only called for a listing this import just created or overwrote
     * (Skip leaves Luster's state alone), right after its registry row was written
     * from the source status. For Closed / Adopted Out, and for any status Luster
     * doesn't know, that's the inactive state an archived listing has anyway.
     *
     *  - Archived in Fanatically, not in Luster: a new lifecycle row. Fanatically
     *    keeps no closing date or new owner, so the date is unknown (run() made
     *    sure Migration 108 is in), and reopening gives what an active import of
     *    this listing would: Current, or inactive when it has no address.
     *  - Archived in both: the owner's date, note and reopen state are kept and
     *    only a changed status is written. One that's now Closed loses its
     *    adopted-to details, the same as the edit form.
     *  - Current, Upcoming or Pending in Fanatically, archived in Luster: the row
     *    is removed, which reopens it (the registry already has that status).
     *  - Any other status, or none, while archived in Luster: nothing changes,
     *    and the note says why. An unknown status is no reason to throw away the
     *    owner's archive details.
     *
     * Written straight to the table, never through ListingLifecycleService::archive(),
     * whose snapshot would capture the inactive state this import just wrote.
     *
     * @return string A note for the results page, or ''
     * @throws \RuntimeException When it can't be read or written, so the caller
     *         rolls the registry change back with it
     */
    private function importLifecycle(PDO $target, int $listingId, string $sourceStatus, string $title, bool $hasAddress): string
    {
        $labels     = ['closed' => 'Closed', 'adopted_out' => 'Adopted Out'];
        $dateLabels = ['closed' => 'Closed On', 'adopted_out' => 'Adopted On'];
        $archived   = isset($labels[$sourceStatus]);
        $active     = in_array($sourceStatus, ['current', 'upcoming', 'pending'], true);
        $run        = static function (string $sql, array $params) use ($target): \PDOStatement {
            $st = $target->prepare($sql);
            if ($st === false || !$st->execute($params)) {
                throw new \RuntimeException("the database didn't accept it");
            }
            return $st;
        };

        try {
            $stored = $run(
                'SELECT lifecycle_status, archived_at FROM `lstr_listing_lifecycle` WHERE fanlisting_id = :id',
                ['id' => $listingId]
            )->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Luster couldn't check whether it's closed or adopted out (" . $e->getMessage() . ")", 0, $e);
        }

        if ($stored === false) {
            if (!$archived) {
                return '';
            }
            try {
                $run(
                    'INSERT INTO `lstr_listing_lifecycle`
                        (fanlisting_id, lifecycle_status, archived_at, legacy_upcoming, legacy_trouble)
                     VALUES (:id, :status, NULL, 0, :trouble)',
                    ['id' => $listingId, 'status' => $sourceStatus, 'trouble' => $hasAddress ? 0 : 1]
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException("Luster couldn't mark it {$labels[$sourceStatus]} (" . $e->getMessage() . ")", 0, $e);
            }
            return '';
        }

        $storedStatus = (string) $stored['lifecycle_status'];
        $storedLabel  = $labels[$storedStatus] ?? $storedStatus;

        if ($archived) {
            if ($storedStatus === $sourceStatus) {
                return '';
            }
            try {
                $run(
                    $sourceStatus === 'closed'
                        ? 'UPDATE `lstr_listing_lifecycle` SET lifecycle_status = :status, adopted_to_name = NULL, adopted_to_url = NULL WHERE fanlisting_id = :id'
                        : 'UPDATE `lstr_listing_lifecycle` SET lifecycle_status = :status WHERE fanlisting_id = :id',
                    ['id' => $listingId, 'status' => $sourceStatus]
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException("Luster couldn't change it to {$labels[$sourceStatus]} (" . $e->getMessage() . ")", 0, $e);
            }
            $date = (string) ($stored['archived_at'] ?? '');
            return "{$title}: now {$labels[$sourceStatus]} instead of {$storedLabel}, to match Fanatically. "
                . ($date !== ''
                    ? 'Its ' . ($dateLabels[$storedStatus] ?? 'archive') . ' date (' . substr($date, 0, 10) . ") is kept as its {$dateLabels[$sourceStatus]} date; you can change it on the listing's edit page."
                    : 'Its date is still unknown.');
        }

        if ($active) {
            try {
                $run('DELETE FROM `lstr_listing_lifecycle` WHERE fanlisting_id = :id', ['id' => $listingId]);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Luster couldn't reopen it (" . $e->getMessage() . ")", 0, $e);
            }
            return "{$title}: reopened, because it isn't closed or adopted out in Fanatically.";
        }

        $shown = trim($sourceStatus) === '' ? 'is blank' : "is \"{$sourceStatus}\"";
        return "{$title}: stays {$storedLabel}, with its date and note as they were. Its status in Fanatically {$shown}, which Luster doesn't know.";
    }

    /** What owned.catid / enth_fanlistings.catid can hold (VARCHAR(255) in Enthusiast and Luster's installer). */
    private const CATID_MAX_LENGTH = 255;

    /**
     * The listing's categories as the old '|1|2|' list Enthusiast-style pages read
     * ('' for none), cut at a whole id so it fits CATID_MAX_LENGTH. Luster itself
     * reads lstr_listing_categories, which keeps every one.
     *
     * @param list<int> $categoryIds
     * @return array{0: string, 1: int} The list, and how many ids it holds
     */
    private static function legacyCatidList(array $categoryIds): array
    {
        $list = '';
        $held = 0;
        foreach ($categoryIds as $categoryId) {
            $next = ($list === '' ? '|' : $list) . $categoryId . '|';
            if (strlen($next) > self::CATID_MAX_LENGTH) {
                break;
            }
            $list = $next;
            $held++;
        }
        return [$list, $held];
    }

    /** @return list<string> The registry columns a Fanatically import writes on Overwrite */
    private function registryColumns(bool $isOwned): array
    {
        return $isOwned
            ? ['title', 'subject', 'url', 'catid', 'category_id', 'status', 'opened']
            : ['name', 'subject', 'url', 'catid', 'upcoming', 'trouble', 'opened'];
    }

    /**
     * A listing's registry columns as they are now, or null when it has no row.
     * Throws when they can't be read.
     *
     * @return array<string, mixed>|null
     */
    private function registryRow(PDO $target, string $regTable, string $pkCol, bool $isOwned, int $listingId): ?array
    {
        $cols = implode(', ', array_map(static fn(string $c): string => "`{$c}`", $this->registryColumns($isOwned)));
        $st   = $target->prepare("SELECT {$cols} FROM `{$regTable}` WHERE `{$pkCol}` = :id");
        if ($st === false || !$st->execute(['id' => $listingId])) {
            throw new \RuntimeException("couldn't read the listing back");
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * After a listing save that didn't finish was rolled back, makes sure its
     * registry row really is back the way it was. InnoDB's rollback already did
     * it. A MyISAM `owned` (installs that began on Enthusiast) can't roll back, so
     * the row is put back by hand: a new listing removed, an overwritten one given
     * its old values ($before, read in the same save before it wrote anything).
     * The row is read back afterwards, so the outcome is what's really there:
     * 'restored', 'survived' (with the row as it is now), or 'unknown' when it
     * couldn't be read.
     *
     * @param array<string, mixed>|null $before Null for a new listing
     * @return array{outcome: string, row: array<string, mixed>|null, error: string}
     */
    private function undoRegistryWrite(PDO $target, string $regTable, string $pkCol, bool $isOwned, int $listingId, ?array $before): array
    {
        $isBack = static fn(?array $row): bool => $before === null ? $row === null : $row === $before;
        try {
            $now = $this->registryRow($target, $regTable, $pkCol, $isOwned, $listingId);
        } catch (\Throwable $e) {
            return ['outcome' => 'unknown', 'row' => null, 'error' => $e->getMessage()];
        }
        if ($isBack($now)) {
            return ['outcome' => 'restored', 'row' => null, 'error' => ''];
        }

        $error = '';
        try {
            if ($before === null) {
                $st     = $target->prepare("DELETE FROM `{$regTable}` WHERE `{$pkCol}` = :id");
                $params = ['id' => $listingId];
            } else {
                $set    = [];
                $params = ['id' => $listingId];
                foreach ($this->registryColumns($isOwned) as $i => $column) {
                    $set[]           = "`{$column}` = :c{$i}";
                    $params["c{$i}"] = $before[$column];
                }
                $st = $target->prepare("UPDATE `{$regTable}` SET " . implode(', ', $set) . " WHERE `{$pkCol}` = :id");
            }
            if ($st === false || !$st->execute($params)) {
                throw new \RuntimeException("the database didn't accept it");
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        try {
            $now = $this->registryRow($target, $regTable, $pkCol, $isOwned, $listingId);
        } catch (\Throwable $e) {
            return ['outcome' => 'unknown', 'row' => null, 'error' => $error !== '' ? $error : $e->getMessage()];
        }
        if ($isBack($now)) {
            return ['outcome' => 'restored', 'row' => null, 'error' => ''];
        }
        return ['outcome' => 'survived', 'row' => $now, 'error' => $error !== '' ? $error : "it didn't change back"];
    }

    /**
     * What's left of a listing whose save didn't finish, worded for the end of
     * its error, from undoRegistryWrite()'s checked outcome (never assumed).
     *
     * A new listing that's still there gets its import record now, so the next
     * import finds it instead of adding a second copy: a listing with no address
     * can't be found any other way. The record is read back before the message
     * relies on it. An overwritten listing can always be found again (by its new
     * address, or by the record it was matched with).
     *
     * @param array<string, mixed>|null $before    Null for a new listing
     * @param array<string, mixed>|null $refMeta   The import record's metadata, as runListings() writes it
     */
    private function unfinishedSaveOutcome(
        PDO $target, string $regTable, string $pkCol, bool $isOwned, int $listingId, ?array $before,
        string $url, string $externalId, ?array $refMeta
    ): string {
        $undo  = $this->undoRegistryWrite($target, $regTable, $pkCol, $isOwned, $listingId, $before);
        $isNew = $before === null;

        if ($undo['outcome'] === 'restored') {
            return ', so nothing about this listing was saved.';
        }
        if ($undo['outcome'] === 'unknown') {
            return ', and Luster ' . self::uncheckedSaveAdvice($isNew, $listingId, $url, " ({$undo['error']})");
        }

        $shows = $this->registryStateLabel($target, $isOwned, $listingId, (array) $undo['row']);
        if (!$isNew) {
            return ", and Luster couldn't put listing #{$listingId} back the way it was ({$undo['error']}). It has its new details from Fanatically and {$shows}. Import again with Overwrite to finish it.";
        }

        $recorded = $this->upsertExternalRef($target, $listingId, 'fanatically', $externalId, $refMeta)
            && $this->externalRefPointsAt($target, $externalId, $listingId);
        return ", and Luster couldn't remove the new listing again ({$undo['error']}). It's saved as listing #{$listingId}, which {$shows}."
            . ($recorded || $url !== ''
                ? ' Import again with Overwrite to finish it.'
                : " Luster couldn't record which Fanatically listing it came from either, so importing again would add it a second time: delete listing #{$listingId} first, then import again.");
    }

    /**
     * The end of a listing's error when Luster couldn't see whether its save was
     * undone, starting at "couldn't check whether": what to look at before
     * importing again. A listing that was already in Luster is always found
     * again (by its new address, or by the record it was matched with), and so
     * is a new one with an address. A new one with no address can only be found
     * by an import record it may not have, so it has to go first, or the next
     * import adds it a second time. $why goes in brackets after the check.
     */
    private static function uncheckedSaveAdvice(bool $isNew, int $listingId, string $url, string $why): string
    {
        if (!$isNew) {
            return "couldn't check whether listing #{$listingId} was put back the way it was{$why}. Check it on your Listings page, then import again with Overwrite to finish it.";
        }
        return "couldn't check whether the new listing (#{$listingId}) was removed again{$why}."
            . ($url !== ''
                ? ' Import again with Overwrite to finish it.'
                : " If listing #{$listingId} is on your Listings page, delete it before importing again, or the import will add it a second time.");
    }

    /** Whether the Fanatically import record for $externalId now names $listingId. */
    private function externalRefPointsAt(PDO $target, string $externalId, int $listingId): bool
    {
        try {
            $st = $target->prepare("SELECT listing_id FROM lstr_listing_external_refs WHERE source = 'fanatically' AND external_id = :eid");
            if ($st === false || !$st->execute(['eid' => $externalId])) {
                return false;
            }
            $found = $st->fetchColumn();
            return $found !== false && (int) $found === $listingId;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Where a listing shows up now, from its registry row and its lifecycle row:
     * "shows as Current", "shows as Closed", "shows as both Current and Closed".
     *
     * @param array<string, mixed> $row registryRow()
     */
    private function registryStateLabel(PDO $target, bool $isOwned, int $listingId, array $row): string
    {
        $main = $isOwned
            ? ([0 => 'Pending', 1 => 'Upcoming', 2 => 'Current'][(int) ($row['status'] ?? 0)] ?? 'Pending')
            : ((int) ($row['upcoming'] ?? 0) === 1 ? 'Upcoming' : ((int) ($row['trouble'] ?? 0) === 1 ? 'Pending' : 'Current'));
        try {
            $st = $target->prepare('SELECT lifecycle_status FROM `lstr_listing_lifecycle` WHERE fanlisting_id = :id');
            if ($st === false || !$st->execute(['id' => $listingId])) {
                throw new \RuntimeException("couldn't read it");
            }
            $found = $st->fetchColumn();
        } catch (\Throwable) {
            return "shows as {$main} (Luster couldn't check whether it's also marked Closed or Adopted Out)";
        }
        if ($found === false) {
            return "shows as {$main}";
        }
        $archived = ['closed' => 'Closed', 'adopted_out' => 'Adopted Out'][(string) $found] ?? (string) $found;
        return $main === 'Pending' ? "shows as {$archived}" : "shows as both {$main} and {$archived}";
    }

    /**
     * Adds a listing's categories to lstr_listing_categories, keeping the ones it
     * already has (an import never takes one away). Plain inserts of only the
     * missing ones, so a failure is a failure, not a skipped duplicate.
     *
     * @param list<int> $categoryIds
     * @return string '' when every one is there, or what went wrong
     */
    private function addListingCategories(PDO $target, int $listingId, array $categoryIds): string
    {
        if ($categoryIds === []) {
            return '';
        }
        $total = count($categoryIds);
        try {
            $st = $target->prepare('SELECT category_id FROM lstr_listing_categories WHERE fanlisting_id = :id');
            if ($st === false || !$st->execute(['id' => $listingId])) {
                throw new \RuntimeException("the database didn't answer");
            }
            $have = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            return "Luster couldn't check which categories it already has (" . $e->getMessage() . "), so none of its {$total} categories were added. Import again with Overwrite to add them.";
        }

        $failed = 0;
        $first  = '';
        foreach ($categoryIds as $categoryId) {
            if (in_array($categoryId, $have, true)) {
                continue;
            }
            try {
                $st = $target->prepare('INSERT INTO lstr_listing_categories (fanlisting_id, category_id) VALUES (:fid, :cid)');
                if ($st === false || !$st->execute(['fid' => $listingId, 'cid' => $categoryId])) {
                    throw new \RuntimeException("the database didn't accept it");
                }
            } catch (\Throwable $e) {
                $failed++;
                $first = $first !== '' ? $first : $e->getMessage();
            }
        }
        return $failed === 0
            ? ''
            : "Luster couldn't save {$failed} of its {$total} categories ({$first}). Import again with Overwrite to add them.";
    }

    /**
     * The Luster listing a Fanatically listing goes into, or null for a new one.
     * The address is primary (authoritative Luster state); the recorded
     * Fanatically id is the fallback, which covers a listing with no address yet.
     *
     * @param array{urls: array<string,int>, byExternal: array<string,int>} $index
     */
    private function matchExistingListing(array $index, string $url, int $srcId): ?int
    {
        return ($url !== '' ? ($index['urls'][strtolower($url)] ?? null) : null)
            ?? $index['byExternal'][(string) $srcId]
            ?? null;
    }

    /**
     * Records the address a listing now has in the index: the one it was just
     * created or overwritten with. Any address it had before is dropped, so a
     * later row at that old address can't land in this listing.
     *
     * @param array{urls: array<string,int>, byExternal: array<string,int>, dbtables: array<int,string>} $index
     */
    private function rememberListingAddress(array &$index, int $listingId, string $url): void
    {
        foreach ($index['urls'] as $key => $id) {
            if ($id === $listingId) {
                unset($index['urls'][$key]);
            }
        }
        if ($url !== '') {
            $index['urls'][strtolower($url)] = $listingId;
        }
    }

    /**
     * Every table in the target database. Throws when they can't be listed: an
     * empty list would let a new listing be handed a table that's already there.
     *
     * @return list<string>
     */
    private function physicalTableNames(PDO $target): array
    {
        $st = $target->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? $target->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            : $target->query('SHOW TABLES');
        if ($st === false) {
            throw new \RuntimeException("Couldn't list the database's tables.");
        }
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Luster's own tables and the listing registries: never a member table. */
    private function isProtectedTableName(string $table): bool
    {
        $lower = strtolower($table);
        return str_starts_with($lower, 'lstr_')
            || in_array($lower, ['owned', 'enth_fanlistings', 'enth_listings'], true);
    }

    /**
     * Whether an existing table has the columns MemberRepo needs for this
     * schema, so it's safe to add field columns to and write members into.
     *
     * @param array<string,true> $columns
     */
    private function looksLikeMemberTable(array $columns, bool $isOwned): bool
    {
        $required = ['email', 'name', 'country', 'url', 'password', $isOwned ? 'pending' : 'approved'];
        foreach ($required as $column) {
            if (!isset($columns[$column])) {
                return false;
            }
        }
        return true;
    }

    /**
     * The columns a member table has, as lowercase name => true, or null when the
     * table doesn't exist. Throws when the columns couldn't be read: treating that
     * as "no table" would hide a listing's own columns from the field plan.
     *
     * @return array<string,true>|null
     */
    private function memberTableColumns(PDO $target, string $table): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return null;
        }

        if ($target->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $st = $target->query("PRAGMA table_info(`{$table}`)");
            if ($st === false) {
                throw new \RuntimeException("Couldn't read the columns of {$table}.");
            }
            $names = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'name');
        } else {
            $st = $target->prepare(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
            );
            if ($st === false || !$st->execute(['t' => $table])) {
                throw new \RuntimeException("Couldn't read the columns of {$table}.");
            }
            $names = $st->fetchAll(PDO::FETCH_COLUMN);
        }

        if ($names === []) {
            return null;
        }
        $columns = [];
        foreach ($names as $name) {
            $columns[strtolower((string) $name)] = true;
        }
        return $columns;
    }

    /**
     * Creates a per-listing member table in the shape MemberRepo reads for this
     * schema. Unprefixed: email is the key and pending=1 means queued. Standard
     * enth_: an id key, approved=1 means approved, and an updated timestamp that
     * approvals and edits stamp. Custom field columns are TEXT NULL, the same as
     * the admin Fields page adds them.
     *
     * @param list<string> $fieldColumns Final column names (already prefixed for enth_)
     */
    private function createMemberTable(PDO $target, string $table, array $fieldColumns, bool $isOwned): void
    {
        $sqlite = $target->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

        $extra = [];
        foreach ($fieldColumns as $column) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                $extra[] = "`{$column}` TEXT NULL";
            }
        }

        if ($isOwned) {
            $cols = $sqlite
                ? ["`email` TEXT NOT NULL", "`name` TEXT NOT NULL DEFAULT ''", "`country` TEXT NOT NULL DEFAULT ''",
                   "`url` TEXT NOT NULL DEFAULT ''", '`pending` INTEGER NOT NULL DEFAULT 0', "`password` TEXT NOT NULL DEFAULT ''",
                   '`showemail` INTEGER NOT NULL DEFAULT 1', '`showurl` INTEGER NOT NULL DEFAULT 1', '`added` TEXT NULL']
                : ['`email` VARCHAR(255) NOT NULL', "`name` VARCHAR(100) NOT NULL DEFAULT ''", "`country` VARCHAR(100) NOT NULL DEFAULT ''",
                   "`url` VARCHAR(500) NOT NULL DEFAULT ''", '`pending` TINYINT(1) NOT NULL DEFAULT 0', "`password` VARCHAR(255) NOT NULL DEFAULT ''",
                   '`showemail` TINYINT(1) NOT NULL DEFAULT 1', '`showurl` TINYINT(1) NOT NULL DEFAULT 1', '`added` DATE NULL'];
            $keys = ['PRIMARY KEY (`email`)'];
        } else {
            $cols = $sqlite
                ? ['`id` INTEGER PRIMARY KEY AUTOINCREMENT', '`email` TEXT NOT NULL UNIQUE', "`name` TEXT NOT NULL DEFAULT ''",
                   "`country` TEXT NOT NULL DEFAULT ''", "`url` TEXT NOT NULL DEFAULT ''", '`approved` INTEGER NOT NULL DEFAULT 0',
                   "`password` TEXT NOT NULL DEFAULT ''", '`showemail` INTEGER NOT NULL DEFAULT 1', '`showurl` INTEGER NOT NULL DEFAULT 1',
                   '`added` TEXT NULL', '`updated` TEXT NULL']
                : ['`id` INT UNSIGNED NOT NULL AUTO_INCREMENT', '`email` VARCHAR(255) NOT NULL', "`name` VARCHAR(100) NOT NULL DEFAULT ''",
                   "`country` VARCHAR(100) NOT NULL DEFAULT ''", "`url` VARCHAR(500) NOT NULL DEFAULT ''", '`approved` TINYINT(1) NOT NULL DEFAULT 0',
                   "`password` VARCHAR(255) NOT NULL DEFAULT ''", '`showemail` TINYINT(1) NOT NULL DEFAULT 1', '`showurl` TINYINT(1) NOT NULL DEFAULT 1',
                   '`added` DATE NULL', '`updated` DATETIME NULL'];
            $keys = $sqlite ? [] : ['PRIMARY KEY (`id`)', 'UNIQUE KEY `uq_email` (`email`)'];
        }

        $body   = implode(",\n  ", array_merge($cols, $extra, $keys));
        $suffix = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $target->exec("CREATE TABLE IF NOT EXISTS `{$table}` (\n  {$body}\n){$suffix}");
    }

    /**
     * Adds missing custom field columns to an existing member table (Overwrite
     * only), as TEXT NULL like the admin Fields page does.
     *
     * @param list<string> $columns Final column names
     */
    private function addMemberColumns(PDO $target, string $table, array $columns): void
    {
        foreach ($columns as $column) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
                $target->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` TEXT NULL");
            }
        }
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

    /**
     * A new member table name from the listing subject. $existing holds every
     * name already taken (lowercase): other listings' tables and every table in
     * the database. Names starting with lstr_ are Luster's own (an add-on not
     * installed yet may still create one), so those get an fl_ prefix.
     *
     * @param array<string,true> $existing
     */
    private function generateDbtable(string $subject, array $existing): string
    {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $subject));
        $base = trim($base, '_') ?: 'listing';
        if (str_starts_with($base, 'lstr_')) {
            $base = 'fl_' . $base;
        }
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
     * Fanatically's site-wide settings ({p}settings key/value rows). Empty when the
     * table can't be read.
     *
     * @return array<string,string>
     */
    private function readGlobalSettings(PDO $source, string $p): array
    {
        $globalSettings = [];
        try {
            $st = $source->query("SELECT setting_key, setting_value FROM `{$p}settings`");
            foreach ($st ? $st->fetchAll() : [] as $row) {
                $globalSettings[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Throwable) {}

        return $globalSettings;
    }

    /**
     * Fanatically approves new joins automatically unless hold_for_approval is on
     * (it's off by default). Luster always holds new joins for approval, so the
     * owner is told when that changes for them. Members brought over keep their
     * own approval status either way.
     *
     * @param array<string,string> $globalSettings
     */
    private function joinApprovalNote(array $globalSettings): ?string
    {
        $holdsJoins = isset($globalSettings['hold_for_approval'])
            && (int) $globalSettings['hold_for_approval'] !== 0;

        return $holdsJoins
            ? null
            : 'Fanatically approved new joins automatically. Luster holds every new join for approval, '
                . 'so new members will wait in the pending queue. Imported members keep their current approval status.';
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
        // hold_for_approval isn't copied: Luster holds every new join for approval.
        // joinApprovalNote() tells the owner when Fanatically was approving them
        // automatically.

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
                         join_confirmation_email=:je,
                         description=COALESCE(NULLIF(:description, \'\'), description)
                     WHERE fanlisting_id=:id'
                )->execute([
                    'lu' => $lu,  'ab' => $lstrApprovedBy,
                    'qu' => $queueUpdates,
                    'je' => $joinEmail,   'id' => $listingId,
                    'description' => $description,
                ]);
            } else {
                $hasAffApply = $this->tableHasColumn($target, 'lstr_fanlisting_settings', 'affiliate_apply_enabled');
                $affCol = $hasAffApply ? ', affiliate_apply_enabled' : '';
                $affVal = $hasAffApply ? ', 1'                       : '';
                $target->prepare(
                    "INSERT INTO lstr_fanlisting_settings
                       (fanlisting_id, last_updated, approved_by, queue_updates, join_confirmation_email, description{$affCol})
                     VALUES (:id, :lu, :ab, :qu, :je, :description{$affVal})"
                )->execute([
                    'id' => $listingId, 'lu' => $lu,    'ab' => $lstrApprovedBy,
                    'qu' => $queueUpdates, 'je' => $joinEmail,
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
     * Persists field definitions to the listing registry's additional-fields
     * column, merging with whatever is already there. Existing entries (by
     * name) are never overwritten, so this is safe to call on every import
     * pass without clobbering a field an admin has since re-typed via the
     * Fields UI — it only ever adds genuinely new names.
     *
     * Preserves label/required/options from Fanatically's own custom_fields
     * JSON when available (Fanatically has no explicit "type" concept — a
     * field is a dropdown purely by having a non-empty options list, same
     * convention its own join-form renderer uses) rather than collapsing
     * every imported field to a bare untyped name.
     *
     * Field names come from planCustomFields(), so they match the member
     * table's columns. Returns false when the registry couldn't be read or
     * written, so the caller can report it.
     *
     * @param list<array{source: string, name: string, def: array<string,mixed>}> $plannedFields
     */
    private function persistCustomFieldDefinitions(PDO $target, int $listingId, bool $isOwned, array $plannedFields): bool
    {
        if (empty($plannedFields)) {
            return true;
        }

        $table   = $isOwned ? 'owned'      : 'enth_fanlistings';
        $idCol   = $isOwned ? 'listingid'  : 'id';
        $infoCol = $isOwned ? 'additional' : 'additional_info';

        try {
            $st = $target->prepare("SELECT {$infoCol} FROM `{$table}` WHERE {$idCol}=:id");
            $st->execute(['id' => $listingId]);
            $raw      = (string) ($st->fetchColumn() ?: '');
            $existing = $this->parseFieldDefinitions($raw, $isOwned);

            $added = false;
            foreach ($plannedFields as $field) {
                $name = $field['name'];
                $def  = $field['def'];
                if (isset($existing[$name])) {
                    continue;
                }
                $options = array_values(array_filter(array_map('trim', (array) ($def['options'] ?? []))));
                $existing[$name] = [
                    'name'     => $name,
                    'label'    => trim((string) ($def['label'] ?? '')) ?: ucwords(str_replace('_', ' ', $name)),
                    'type'     => $options !== [] ? 'dropdown' : 'text',
                    'required' => (bool) ($def['required'] ?? false),
                    // Comma-separated, matching FormFieldBuilder/FieldRenderer's
                    // explode(',', ...) convention and the admin Fields UI's own
                    // "Option A, Option B" placeholder — not pipe-delimited.
                    'options'  => implode(', ', $options),
                ];
                $added = true;
            }

            if ($added || ($raw === '' && !empty($existing))) {
                $target->prepare("UPDATE `{$table}` SET {$infoCol}=:info WHERE {$idCol}=:id")
                    ->execute(['info' => serialize(array_values($existing)), 'id' => $listingId]);
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * A listing's stored field definitions as a name-keyed map. owned.additional
     * supports the same serialized-array format additional_info does (both are
     * read through FieldRepo::getFieldsForListing(), which tries unserialize()
     * first regardless of schema), so a listing that already has rich field
     * metadata (from a prior Enthusiast import, or a manual admin edit) keeps it
     * rather than being downgraded back to bare names on every re-import.
     *
     * @return array<string, array<int|string,mixed>>
     */
    private function parseFieldDefinitions(string $raw, bool $isOwned): array
    {
        $existing = [];
        if ($raw === '') {
            return $existing;
        }

        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                $n = (string) ($item['name'] ?? $item[0] ?? '');
                if ($n !== '') {
                    $existing[$n] = $item;
                }
            }
        } elseif ($isOwned) {
            // Legacy owned schema: bare comma-separated names, no metadata.
            foreach (array_filter(array_map('trim', explode(',', $raw))) as $n) {
                $existing[$n] = ['name' => $n, 'label' => ucwords(str_replace('_', ' ', $n)), 'type' => 'text', 'required' => false];
            }
        } else {
            // Legacy enth_ schema: pipe-delimited name,Label,type,required.
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
        return $existing;
    }

    // -----------------------------------------------------------------------
    // External-ref helpers
    /**
     * Writes or replaces a listing→external-ID mapping. When $metadata is provided
     * and the lstr_listing_external_refs table has a metadata_json column (added by
     * Migration 91), the JSON-encoded payload is stored alongside the row.
     * Returns false when the record couldn't be written.
     *
     * @param array<string,mixed>|null $metadata
     */
    private function upsertExternalRef(
        PDO    $target,
        int    $listingId,
        string $source,
        string $externalId,
        ?array $metadata = null,
    ): bool {
        try {
            if ($this->refsHaveMetadata($target)) {
                // REPLACE rewrites the whole row, so carry over the field names
                // (field_map) earlier imports stored, but only while the record
                // stays with the same Luster listing: names that belong to another
                // listing's columns must never come along. $metadata replaces the rest.
                $st = $target->prepare(
                    'SELECT listing_id, metadata_json FROM lstr_listing_external_refs WHERE source = :src AND external_id = :eid'
                );
                $st->execute(['src' => $source, 'eid' => $externalId]);
                $oldRow = $st->fetch();
                $old    = $oldRow !== false && (int) $oldRow['listing_id'] === $listingId
                    ? json_decode((string) ($oldRow['metadata_json'] ?? ''), true)
                    : null;
                $keep = is_array($old) && isset($old['field_map']) ? ['field_map' => $old['field_map']] : [];
                $meta = array_merge($keep, $metadata ?? []);

                $target->prepare(
                    'REPLACE INTO lstr_listing_external_refs (listing_id, source, external_id, metadata_json)
                     VALUES (:lid, :src, :eid, :meta)'
                )->execute([
                    'lid'  => $listingId,
                    'src'  => $source,
                    'eid'  => $externalId,
                    'meta' => $this->encodeRefMetadata($meta),
                ]);
            } else {
                $target->prepare(
                    'REPLACE INTO lstr_listing_external_refs (listing_id, source, external_id)
                     VALUES (:lid, :src, :eid)'
                )->execute(['lid' => $listingId, 'src' => $source, 'eid' => $externalId]);
            }
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    // -----------------------------------------------------------------------

    /**
     * Whether import records have metadata_json (Migration 91), where the field
     * names are kept. Worked out once per preview or import, before anything is
     * written (an import resets it first), and strictly: a failed check throws
     * rather than reading as "no column", which would skip the Migration 91 guard
     * and rewrite import records without their saved names.
     */
    private function refsHaveMetadata(PDO $target): bool
    {
        return $this->refsHaveMetadata ??= $this->tableHasColumn($target, 'lstr_listing_external_refs', 'metadata_json', true);
    }

    /** $strict: throw when the check itself fails, instead of answering false. */
    private function tableHasColumn(PDO $pdo, string $table, string $column, bool $strict = false): bool
    {
        if ($strict) {
            return $this->tableHasColumnOrFail($pdo, $table, $column);
        }
        try {
            return $this->tableHasColumnOrFail($pdo, $table, $column);
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableHasColumnOrFail(PDO $pdo, string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");
            if ($stmt === false) {
                throw new \RuntimeException("Couldn't read the columns of {$table}.");
            }
            foreach ($stmt->fetchAll() as $row) {
                if (($row['name'] ?? '') === $column) {
                    return true;
                }
            }
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
        if ($stmt === false || !$stmt->execute(['table' => $table, 'column' => $column])) {
            throw new \RuntimeException("Couldn't read the columns of {$table}.");
        }
        return $stmt->fetchColumn() !== false;
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
                    SUM(CASE WHEN status = 'adopted_out'  THEN 1 ELSE 0 END) AS adopted_out,
                    SUM(CASE WHEN status IN ('closed', 'adopted_out') AND TRIM(COALESCE(url, '')) = '' THEN 1 ELSE 0 END) AS archived_blank_url
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
                    $warns[] = "Data transformed — {$closed} closed listing(s) will come over as Closed. Fanatically doesn't record when a listing closed, so the date is marked unknown; reopening one in Luster makes it Current.";
                }
                if ($adoptedOut > 0) {
                    $warns[] = "Data transformed — {$adoptedOut} adopted-out listing(s) will come over as Adopted Out. Fanatically doesn't record when a listing was adopted out or who took it, so the date is marked unknown and there's no new owner; reopening one in Luster makes it Current.";
                }
                $archivedBlankUrl = (int) ($row['archived_blank_url'] ?? 0);
                if ($archivedBlankUrl > 0) {
                    $warns[] = "Data transformed — {$archivedBlankUrl} of the closed or adopted-out listing(s) have no URL, so reopening one in Luster makes it inactive instead of Current.";
                }
            }
        } catch (\Throwable) {}
        return $warns;
    }

    /**
     * What the KIM import will do with approval and notes, using the same
     * kimPendingValue() rule the import uses.
     *
     * @return list<string>
     */
    private function previewKimWarnings(PDO $source, string $p, ImportOptions $options): array
    {
        try {
            $st   = $source->query("SELECT * FROM `{$p}kim`");
            $rows = $st ? $st->fetchAll() : [];
        } catch (\Throwable) {
            return [];
        }
        if ($rows === []) {
            return [];
        }

        $waiting = 0;
        $notes   = 0;
        foreach ($rows as $kim) {
            $waiting += $this->kimPendingValue($kim, $options);
            if (trim((string) ($kim['notes'] ?? '')) !== '') {
                $notes++;
            }
        }
        $total   = count($rows);
        $visible = $total - $waiting;

        $warns   = [];
        $warns[] = match ($options->fnKimStatus) {
            'pending' => "All {$total} KIM entr(ies) will wait for your approval.",
            'active'  => "All {$total} KIM entr(ies) will show on the public KIM list, including any you hadn't approved in Fanatically.",
            default   => "{$visible} KIM entr(ies) will show on the public KIM list and {$waiting} will wait for approval, the same as in Fanatically.",
        };
        if ($notes > 0) {
            $warns[] = "Data dropped — {$notes} KIM note(s) can't be imported, because Luster's KIM list has no notes field. "
                . "The results page will list them so you can copy them, but they aren't saved anywhere in Luster: "
                . 'download the import report before you leave that page.';
        }
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
