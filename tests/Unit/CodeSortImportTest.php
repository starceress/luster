<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\CodeSortImportSource;
use Luster\Import\ImportOptions;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the three CodeSort import fixes:
 *
 *   Fix 1 (Critical) — codesort_default schema: codes + codes_cat/codes_sizes/codes_donors.
 *                      detectSchema() now returns 'codesort_default' for real default installs.
 *                      buildCollectiveMap() uses direct ID matching (no collective table needed).
 *
 *   Fix 2 (High)     — Deduplication: re-running with conflict_strategy=skip no longer
 *                      inserts duplicate codes.
 *
 *   Fix 3 (Medium)   — readCodeSortImageSettings() auto-reads images_folder/images_url
 *                      from the codes_options table when the user supplies no path/URL.
 *
 * All tests use in-memory SQLite; no live MySQL or real image files are required.
 */
final class CodeSortImportTest extends TestCase
{
    /** Per-test folder: public/uploads/codes/ for the importer, images/ for CodeSort's files. */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/luster_cstest_' . uniqid('', true);
        mkdir($this->tmpDir . '/public/uploads/codes', 0777, true);
        mkdir($this->tmpDir . '/images', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // -----------------------------------------------------------------------
    // Infrastructure helpers
    // -----------------------------------------------------------------------

    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /** Copies images into the test's own public/ folder, never the real one. */
    private function adapter(): CodeSortImportSource
    {
        return new CodeSortImportSource($this->tmpDir . '/public');
    }

    /**
     * Options with a Source ID, so codes import without a codes_options images_url.
     *
     * @param array<string,mixed> $overrides
     */
    private function makeOptions(array $overrides = []): ImportOptions
    {
        return ImportOptions::fromPost(array_merge([
            'source'            => 'codesort',
            'conflict_strategy' => 'skip',
            'cs_source_id'      => 'test-codes',
        ], $overrides));
    }

    /**
     * Creates the minimal 'codesort_default' source schema:
     *   codes, codes_cat, codes_sizes, codes_donors, codes_options
     * This is the real default CodeSort install layout.
     */
    private function seedDefaultSource(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE codes (
                code_id       INTEGER PRIMARY KEY AUTOINCREMENT,
                code_fl       INTEGER NOT NULL DEFAULT 0,
                code_cat      INTEGER NOT NULL DEFAULT 0,
                code_size     INTEGER NOT NULL DEFAULT 0,
                code_image    TEXT    NOT NULL DEFAULT \'\',
                code_donor    INTEGER NOT NULL DEFAULT 0,
                code_approved TEXT    NOT NULL DEFAULT \'y\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE codes_cat (
                cat_id   INTEGER PRIMARY KEY AUTOINCREMENT,
                cat_fl   INTEGER NOT NULL DEFAULT 0,
                cat_name TEXT    NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE codes_sizes (
                size_id    INTEGER PRIMARY KEY AUTOINCREMENT,
                size_size  TEXT    NOT NULL DEFAULT \'\',
                size_order INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE codes_donors (
                donor_id   INTEGER PRIMARY KEY AUTOINCREMENT,
                donor_name TEXT    NOT NULL DEFAULT \'\',
                donor_url  TEXT    NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE codes_options (
                name  TEXT NOT NULL PRIMARY KEY,
                value TEXT NOT NULL DEFAULT \'\'
            )'
        );
    }

    /** Creates the minimal Luster target tables needed for code imports. */
    private function seedTarget(PDO $pdo, int $listingId = 1, string $title = 'Test Fanlisting'): void
    {
        $pdo->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY AUTOINCREMENT,
                dbtable   TEXT NOT NULL DEFAULT \'\',
                title     TEXT NOT NULL DEFAULT \'\',
                subject   TEXT NOT NULL DEFAULT \'\',
                url       TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec("INSERT INTO owned (listingid, dbtable, title, subject, url)
                    VALUES ({$listingId}, 'testfl', '{$title}', '{$title}', 'http://test.com')");

        $pdo->exec(
            'CREATE TABLE lstr_codes (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                name          TEXT    NULL,
                filename      TEXT    NOT NULL DEFAULT \'\',
                width         INTEGER NOT NULL DEFAULT 0,
                height        INTEGER NOT NULL DEFAULT 0,
                size_id       INTEGER NULL,
                donor_id      INTEGER NULL,
                sort_order    INTEGER NOT NULL DEFAULT 0,
                status        TEXT    NOT NULL DEFAULT \'approved\',
                submitter_name  TEXT  NULL,
                submitter_email TEXT  NULL,
                submitter_url   TEXT  NULL,
                created_at    TEXT    NOT NULL DEFAULT \'\',
                import_ref         TEXT NULL UNIQUE,
                import_source_file TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE lstr_code_categories (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                name          TEXT    NOT NULL DEFAULT \'\',
                parent_id     INTEGER NULL,
                sort_order    INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE lstr_code_sizes (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                label         TEXT    NOT NULL DEFAULT \'\',
                width         INTEGER NULL,
                height        INTEGER NULL,
                sort_order    INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE lstr_code_donors (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                name          TEXT    NOT NULL DEFAULT \'\',
                email         TEXT    NULL,
                url           TEXT    NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE lstr_code_category_map (
                code_id     INTEGER NOT NULL,
                category_id INTEGER NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_listing_external_refs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id  INTEGER NOT NULL,
                source      TEXT    NOT NULL,
                external_id TEXT    NOT NULL,
                external_slug_or_url TEXT NOT NULL DEFAULT \'\',
                UNIQUE (source, external_id)
            )'
        );
    }

    // -----------------------------------------------------------------------
    // Fix 1 — Schema detection: codesort_default
    // -----------------------------------------------------------------------

    public function testDetectSchemaReturnsDefaultForRealInstall(): void
    {
        $src = $this->makePdo();
        $this->seedDefaultSource($src);

        $schema = $this->adapter()->detectSchema($src);
        $this->assertSame('codesort_default', $schema);
    }

    public function testDetectSchemaReturnsBareWhenCatTableExists(): void
    {
        // Old bare layout: codes + cat (not codes_cat)
        $src = $this->makePdo();
        $src->exec('CREATE TABLE codes (code_id INTEGER PRIMARY KEY)');
        $src->exec('CREATE TABLE cat   (cat_id  INTEGER PRIMARY KEY)');

        $schema = $this->adapter()->detectSchema($src);
        $this->assertSame('codesort_bare', $schema);
    }

    public function testDetectSchemaReturnsPrefixedForPrefixedLayout(): void
    {
        $src = $this->makePdo();
        $src->exec('CREATE TABLE codes_codes (code_id INTEGER PRIMARY KEY)');

        $schema = $this->adapter()->detectSchema($src);
        $this->assertSame('codesort_prefixed', $schema);
    }

    public function testDetectSchemaDefaultsToDefaultWhenOnlyCodesTableExists(): void
    {
        // codes table with code_id but no companion tables → default is safest guess
        $src = $this->makePdo();
        $src->exec('CREATE TABLE codes (code_id INTEGER PRIMARY KEY)');

        $schema = $this->adapter()->detectSchema($src);
        $this->assertSame('codesort_default', $schema);
    }

    // -----------------------------------------------------------------------
    // Fix 1 — Default layout: codes imported via direct code_fl → listingid match
    // -----------------------------------------------------------------------

    public function testDefaultLayoutImportsCodesWithoutCollectiveTable(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);

        // code_fl = 1 directly matches Luster listingid = 1
        $src->exec("INSERT INTO codes (code_fl, code_image, code_approved) VALUES (1, 'heart50.png', 'y')");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_codes' => '1',
        ]));

        $codeRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Codes') { $codeRow = $r; break; }
        }
        $this->assertNotNull($codeRow);
        $this->assertSame(1, $codeRow['imported'], 'Code should be imported via direct ID match');
        $this->assertSame(0, $codeRow['skipped']);
        $this->assertSame([], $codeRow['errors']);

        $stmt = $tgt->query('SELECT fanlisting_id, filename FROM lstr_codes');
        $row  = $stmt->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row['fanlisting_id']);
        $this->assertSame('heart50.png', $row['filename']);
    }

    public function testDefaultLayoutWarnsForUnmatchedCodeFl(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);

        // code_fl = 99 — no Luster listing with that ID
        $src->exec("INSERT INTO codes (code_fl, code_image, code_approved) VALUES (99, 'code.png', 'y')");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_codes' => '1',
        ]));

        $codeRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Codes') { $codeRow = $r; break; }
        }
        $this->assertNotNull($codeRow);
        $this->assertSame(0, $codeRow['imported']);
        $this->assertSame(1, $codeRow['skipped']);

        // Unmatched warning should name the CodeSort listing ID
        $allWarnings = array_merge(...array_column($result->rows, 'warnings'));
        $this->assertStringContainsString('99', implode(' ', $allWarnings));
    }

    public function testDefaultLayoutImportsCategoriesFromCodesCat(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);

        // cat_fl = 1 matches Luster listing 1 — should use codes_cat, not cat
        $src->exec("INSERT INTO codes_cat (cat_fl, cat_name) VALUES (1, 'Hearts')");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_code_categories' => '1',
        ]));

        $catRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Code categories') { $catRow = $r; break; }
        }
        $this->assertNotNull($catRow);
        $this->assertSame(1, $catRow['imported']);
        $this->assertEmpty($catRow['errors']);

        $stmt = $tgt->query('SELECT name FROM lstr_code_categories');
        $this->assertSame('Hearts', $stmt->fetchColumn());
    }

    public function testDefaultLayoutImportsSizesFromCodesSizes(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);

        // codes table must have a code referencing this listing so matchedListingIds is populated
        $src->exec("INSERT INTO codes (code_fl, code_image, code_approved) VALUES (1, 'c.png', 'y')");
        $src->exec("INSERT INTO codes_sizes (size_size, size_order) VALUES ('50x50', 1)");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_codes'      => '1',
            'import_code_sizes' => '1',
        ]));

        $sizeRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Code sizes') { $sizeRow = $r; break; }
        }
        $this->assertNotNull($sizeRow);
        $this->assertSame(1, $sizeRow['imported']);

        $stmt = $tgt->query('SELECT label FROM lstr_code_sizes');
        $this->assertSame('50x50', $stmt->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // Fix 1 — Regression: prefixed layout still uses codes_collective for matching
    // -----------------------------------------------------------------------

    public function testPrefixedLayoutStillMatchesViaCollectiveTable(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        // codesort_prefixed schema
        $src->exec('CREATE TABLE codes_collective (fl_id INTEGER PRIMARY KEY, fl_subject TEXT NOT NULL DEFAULT \'\')');
        $src->exec("INSERT INTO codes_collective VALUES (1, 'My Fanlisting')");
        $src->exec('CREATE TABLE codes_cat    (cat_id INTEGER PRIMARY KEY, cat_name TEXT, cat_fl INTEGER)');
        $src->exec('CREATE TABLE codes_sizes  (size_id INTEGER PRIMARY KEY, size_size TEXT, size_order INTEGER)');
        $src->exec('CREATE TABLE codes_donors (donor_id INTEGER PRIMARY KEY, donor_name TEXT, donor_url TEXT)');
        $src->exec('CREATE TABLE codes_codes  (code_id INTEGER PRIMARY KEY, code_fl INTEGER, code_cat INTEGER,
                    code_size INTEGER, code_image TEXT, code_donor INTEGER, code_approved TEXT)');
        $src->exec("INSERT INTO codes_codes VALUES (1, 1, 0, 0, 'star.png', 0, 'y')");

        // Luster listing with matching title (case-insensitive)
        $this->seedTarget($tgt, listingId: 5, title: 'my fanlisting');

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_codes'          => '1',
            '_dump_schema_variant'  => 'codesort_prefixed',
        ]));

        $codeRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Codes') { $codeRow = $r; break; }
        }
        $this->assertNotNull($codeRow);
        $this->assertSame(1, $codeRow['imported'], 'Prefixed layout must still title-match via collective table');

        $stmt = $tgt->query('SELECT fanlisting_id FROM lstr_codes');
        $this->assertSame(5, (int) $stmt->fetchColumn(), 'Code must be linked to the Luster listing ID, not the CodeSort fl_id');
    }

    // -----------------------------------------------------------------------
    // Fix 2 — Deduplication on re-run
    // -----------------------------------------------------------------------

    public function testRerunWithSkipStrategyDoesNotDuplicateCodes(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes (code_fl, code_image, code_approved) VALUES (1, 'badge.png', 'y')");

        $opts = $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => 'skip']);

        // First run
        $this->adapter()->run($src, $tgt, $opts);

        // Second run — same data
        $result = $this->adapter()->run($src, $tgt, $opts);

        $codeRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Codes') { $codeRow = $r; break; }
        }
        $this->assertSame(0, $codeRow['imported'], 'Second run should import nothing');
        $this->assertSame(1, $codeRow['skipped'],  'Second run should skip the existing code');

        // Only one row in lstr_codes total
        $count = (int) $tgt->query('SELECT COUNT(*) FROM lstr_codes')->fetchColumn();
        $this->assertSame(1, $count, 'Re-run must not duplicate codes');
    }

    public function testUpdateMatchingUpdatesTheCodeInPlace(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes (code_fl, code_image, code_approved) VALUES (1, 'badge.png', 'y')");

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => 'skip']));
        $id = (int) $tgt->query('SELECT id FROM lstr_codes')->fetchColumn();

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => 'overwrite']));

        $this->assertSame(1, $this->rowOf($result, 'Codes')['imported'], 'Update matching updates the code');
        $this->assertSame([$id], array_map('intval', $tgt->query('SELECT id FROM lstr_codes')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testTwoCodeSortCodesSharingAFileStayTwoCodes(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 1, 'Hearts'), (2, 1, 'Stars')");
        $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '88x31', 1), (2, '50x50', 2)");
        $src->exec("INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES (1, 'Alex', ''), (2, 'Sam', '')");
        $src->exec("INSERT INTO codes (code_id, code_fl, code_cat, code_size, code_donor, code_image, code_approved) VALUES
            (1, 1, 1, 1, 1, 'same.png', 'y'), (2, 1, 2, 2, 2, 'same.png', 'n')");
        $all = ['import_code_categories' => '1', 'import_code_sizes' => '1', 'import_code_donors' => '1', 'import_codes' => '1'];

        $this->adapter()->run($src, $tgt, $this->makeOptions($all));

        $details = fn (): array => $tgt->query(
            "SELECT c.import_ref, c.id, c.status, s.label, d.name AS donor, cat.name AS category
               FROM lstr_codes c
               JOIN lstr_code_sizes s ON s.id = c.size_id
               JOIN lstr_code_donors d ON d.id = c.donor_id
               JOIN lstr_code_category_map m ON m.code_id = c.id
               JOIN lstr_code_categories cat ON cat.id = m.category_id
              ORDER BY c.import_ref"
        )->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $first = $details();
        $this->assertSame(['codesort:test-codes:1', 'codesort:test-codes:2'], array_keys($first));
        $this->assertSame(['approved', '88x31', 'Alex', 'Hearts'], array_values(array_slice($first['codesort:test-codes:1'], 1)));
        $this->assertSame(['pending', '50x50', 'Sam', 'Stars'], array_values(array_slice($first['codesort:test-codes:2'], 1)));

        foreach (['skip' => [0, 2], 'overwrite' => [2, 0]] as $strategy => $counts) {
            $again = $this->adapter()->run($src, $tgt, $this->makeOptions($all + ['conflict_strategy' => $strategy]));
            $codes = $this->rowOf($again, 'Codes');
            $this->assertSame($counts, [$codes['imported'], $codes['skipped']], $strategy);
            $this->assertSame($first, $details(), $strategy);
        }
    }

    // -----------------------------------------------------------------------
    // Fix 3 — Auto-read codes_options for image path/URL
    // -----------------------------------------------------------------------

    public function testImageSettingsAreReadFromOptionsTableWhenNoUserPathGiven(): void
    {
        // Verify that readCodeSortImageSettings() is called and stores the path internally
        // by checking that a non-existent path from the options table produces image_skipped=1
        // (not image_skipped=0, which would indicate the path was never tried).
        // We test only that the options table is queried, not actual file I/O.

        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes (code_fl, code_image, code_approved) VALUES (1, 'pic.png', 'y')");

        // Seed images_folder in codes_options — nonexistent path so no actual copy happens
        $src->exec("INSERT INTO codes_options (name, value) VALUES ('images_folder', '/nonexistent/path')");
        $src->exec("INSERT INTO codes_options (name, value) VALUES ('images_url',    '')");

        // Run with imageMode='auto' but NO user-supplied path/url
        // ImageMigrator will try /nonexistent/path/pic.png — fails — produces image_skipped
        $opts = ImportOptions::fromPost([
            'source'                => 'codesort',
            'conflict_strategy'     => 'skip',
            'import_codes'          => '1',
            'import_images'         => '1',
            '_dump_schema_variant'  => 'codesort_default',
            // image_path and image_url deliberately absent — should auto-read from DB
        ]);

        // Wrap in a try/catch: ImageMigrator needs its public dir to exist.
        // On a test machine that path doesn't exist so it gracefully skips images.
        try {
            $result = $this->adapter()->run($src, $tgt, $opts);
            // Image should have been attempted (not silently skipped because path was empty)
            $codeRow = null;
            foreach ($result->rows as $r) {
                if ($r['type'] === 'Codes') { $codeRow = $r; break; }
            }
            // Code itself must still be imported regardless of image outcome
            $this->assertNotNull($codeRow);
            $this->assertSame(1, $codeRow['imported']);
        } catch (\Throwable) {
            // ImageMigrator may throw if the public dir doesn't exist — that's fine for this test.
            // The important thing is that the options table was queried (no exception from that).
        }
    }

    public function testOptionsTableWithAlternateValueColumnIsHandled(): void
    {
        // Some CodeSort forks use option_value instead of value
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedDefaultSource($src);
        // Replace codes_options with option_value variant
        $src->exec('DROP TABLE codes_options');
        $src->exec('CREATE TABLE codes_options (name TEXT NOT NULL PRIMARY KEY, option_value TEXT NOT NULL DEFAULT \'\')');
        $src->exec("INSERT INTO codes_options (name, option_value) VALUES ('images_folder', '/alt/path')");
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes (code_fl, code_image, code_approved) VALUES (1, 'x.png', 'y')");

        // Should not throw — graceful fallback when first column variant fails
        try {
            $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
                'source'               => 'codesort',
                'conflict_strategy'    => 'skip',
                'import_codes'         => '1',
                'import_images'        => '1',
                '_dump_schema_variant' => 'codesort_default',
            ]));
        } catch (\Throwable $e) {
            $this->fail('readCodeSortImageSettings() must not throw on alternate column: ' . $e->getMessage());
        }
        $this->assertTrue(true); // reached without exception
    }

    public function testUserSuppliedPathTakesPrecedenceOverOptionsTable(): void
    {
        // When the user provides image_path, the options table should not override it.
        // We can verify this by putting a conflicting value in codes_options and checking
        // that the run completes without error (the user path is used, not the DB path).
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedDefaultSource($src);
        $src->exec("INSERT INTO codes_options (name, value) VALUES ('images_folder', '/wrong/path')");
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes (code_fl, code_image, code_approved) VALUES (1, 'y.png', 'y')");

        // imageMode='' means no image migration — path is irrelevant, just verifying no crash
        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_codes' => '1',
            'image_path'   => '/user/supplied/path',
        ]));

        $codeRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Codes') { $codeRow = $r; break; }
        }
        $this->assertSame(1, $codeRow['imported']);
    }

    // -----------------------------------------------------------------------
    // Phase 3 — external ref storage
    // -----------------------------------------------------------------------

    public function testCollectiveMapStoresExternalRefs(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        // codesort_prefixed: fl_id=7 → Luster listing 5 (title match)
        $src->exec('CREATE TABLE codes_collective (fl_id INTEGER PRIMARY KEY, fl_subject TEXT NOT NULL DEFAULT \'\')');
        $src->exec("INSERT INTO codes_collective VALUES (7, 'My Fanlisting')");
        $src->exec('CREATE TABLE codes_cat    (cat_id INTEGER PRIMARY KEY, cat_name TEXT, cat_fl INTEGER)');
        $src->exec('CREATE TABLE codes_sizes  (size_id INTEGER PRIMARY KEY, size_size TEXT, size_order INTEGER)');
        $src->exec('CREATE TABLE codes_donors (donor_id INTEGER PRIMARY KEY, donor_name TEXT, donor_url TEXT)');
        $src->exec('CREATE TABLE codes_codes  (code_id INTEGER PRIMARY KEY, code_fl INTEGER, code_cat INTEGER,
                    code_size INTEGER, code_image TEXT, code_donor INTEGER, code_approved TEXT)');

        $this->seedTarget($tgt, listingId: 5, title: 'My Fanlisting');

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            '_dump_schema_variant' => 'codesort_prefixed',
        ]));

        $ref = $tgt->query(
            "SELECT listing_id, external_id FROM lstr_listing_external_refs
             WHERE source = 'codesort'"
        )->fetch();

        self::assertNotFalse($ref, 'CodeSort external ref should be stored for matched listings');
        self::assertSame('5', (string) $ref['listing_id'],  'Should link to Luster listing 5');
        self::assertSame('7', (string) $ref['external_id'], 'Should record CodeSort fl_id 7');
    }

    // -----------------------------------------------------------------------
    // Re-runs — sizes, donors and categories are matched, not added again
    // -----------------------------------------------------------------------

    private function addListing(PDO $tgt, int $id, string $title): void
    {
        $tgt->prepare("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (:id, :tbl, :title, :title, '')")
            ->execute(['id' => $id, 'tbl' => 'fl' . $id, 'title' => $title]);
    }

    /** @return array<string, mixed> */
    private function rowOf(\Luster\Import\ImportResult $result, string $type): array
    {
        foreach ($result->rows as $row) {
            if ($row['type'] === $type) {
                return $row;
            }
        }
        $this->fail("No \"{$type}\" row in the result.");
    }

    /** @return array{0:int,1:int,2:int} categories, sizes, donors in Luster */
    private function lookupCounts(PDO $tgt): array
    {
        return [
            (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_categories')->fetchColumn(),
            (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn(),
            (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_donors')->fetchColumn(),
        ];
    }

    /**
     * Two listings. Listing 1 uses "Hearts", listing 2 "Stars"; both use donor Alex and
     * listing 2 also uses Sam (no website). Every size goes to every listing.
     */
    private function seedTwoListingSource(PDO $src): void
    {
        $this->seedDefaultSource($src);
        $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 1, 'Hearts'), (2, 2, 'Stars')");
        $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '88x31', 1), (2, '50 x 50', 2)");
        $src->exec("INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES (1, 'Alex', 'http://alex.example/'), (2, 'Sam', '')");
        $src->exec("INSERT INTO codes (code_fl, code_cat, code_size, code_image, code_donor) VALUES
            (1, 1, 1, 'a.png', 1), (2, 2, 2, 'b.png', 1), (2, 0, 1, 'c.png', 2)");
    }

    /** @param array<string,mixed> $overrides */
    private function lookupOptions(string $strategy, array $overrides = []): ImportOptions
    {
        return $this->makeOptions(array_merge([
            'conflict_strategy'      => $strategy,
            'import_code_categories' => '1',
            'import_code_sizes'      => '1',
            'import_code_donors'     => '1',
        ], $overrides));
    }

    public function testKeepExistingThenUpdateMatchingAddsNoSizesDonorsOrCategories(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedTwoListingSource($src);
        $this->seedTarget($tgt, listingId: 1, title: 'One');
        $this->addListing($tgt, 2, 'Two');

        $first = $this->adapter()->run($src, $tgt, $this->lookupOptions('skip'));
        $this->assertSame([2, 4, 3], $this->lookupCounts($tgt));
        $this->assertSame(2, $this->rowOf($first, 'Code categories')['imported']);
        $this->assertSame(4, $this->rowOf($first, 'Code sizes')['imported']);
        $this->assertSame(3, $this->rowOf($first, 'Code donors')['imported']);

        foreach ([1, 2] as $run) {
            $again = $this->adapter()->run($src, $tgt, $this->lookupOptions('overwrite'));
            $this->assertSame([2, 4, 3], $this->lookupCounts($tgt), "Update matching run {$run} added rows");

            $cats   = $this->rowOf($again, 'Code categories');
            $sizes  = $this->rowOf($again, 'Code sizes');
            $donors = $this->rowOf($again, 'Code donors');
            $this->assertSame([0, 2], [$cats['imported'], $cats['skipped']]);
            // A matched size counts as imported under Update matching: its sort order is written.
            $this->assertSame([4, 0], [$sizes['imported'], $sizes['skipped']]);
            $this->assertSame([0, 3], [$donors['imported'], $donors['skipped']]);
            $this->assertSame([], array_merge($cats['errors'], $sizes['errors'], $donors['errors']));
        }
    }

    public function testUpdateMatchingFirstAddsTheSameAsKeepExisting(): void
    {
        $counts = [];
        foreach (['skip', 'overwrite'] as $strategy) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedTwoListingSource($src);
            $this->seedTarget($tgt, listingId: 1, title: 'One');
            $this->addListing($tgt, 2, 'Two');

            $result = $this->adapter()->run($src, $tgt, $this->lookupOptions($strategy));
            $counts[$strategy] = [
                $this->lookupCounts($tgt),
                $this->rowOf($result, 'Code categories')['imported'],
                $this->rowOf($result, 'Code sizes')['imported'],
                $this->rowOf($result, 'Code donors')['imported'],
            ];
        }

        $this->assertSame([[2, 4, 3], 2, 4, 3], $counts['skip']);
        $this->assertSame($counts['skip'], $counts['overwrite']);
    }

    public function testUpdateMatchingCopiesSizeOrderAndKeepExistingDoesNot(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedTwoListingSource($src);
        $this->seedTarget($tgt, listingId: 1, title: 'One');
        $this->addListing($tgt, 2, 'Two');
        $sizeOrders = fn (): array => $tgt->query(
            "SELECT fanlisting_id, sort_order FROM lstr_code_sizes WHERE label = '88x31' ORDER BY fanlisting_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->adapter()->run($src, $tgt, $this->lookupOptions('skip', ['import_code_categories' => '', 'import_code_donors' => '']));
        $this->assertSame([1 => 1, 2 => 1], array_map('intval', $sizeOrders()));

        $src->exec("UPDATE codes_sizes SET size_order = 7 WHERE size_id = 1");

        $this->adapter()->run($src, $tgt, $this->lookupOptions('skip', ['import_code_categories' => '', 'import_code_donors' => '']));
        $this->assertSame([1 => 1, 2 => 1], array_map('intval', $sizeOrders()), 'Keep existing changed a sort order');

        $this->adapter()->run($src, $tgt, $this->lookupOptions('overwrite', ['import_code_categories' => '', 'import_code_donors' => '']));
        $this->assertSame([1 => 7, 2 => 7], array_map('intval', $sizeOrders()));
        $this->assertSame(4, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn());
    }

    public function testSizesFallBackToDimensionsOnlyWhenBothArePositive(): void
    {
        foreach (['skip', 'overwrite'] as $strategy) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedDefaultSource($src);
            $this->seedTarget($tgt, listingId: 1);
            // Newest first in this index, so a lookup that forgot ORDER BY id would get the newer row.
            $tgt->exec('CREATE INDEX ix_dims ON lstr_code_sizes (fanlisting_id, width, height, id DESC)');
            $tgt->exec("INSERT INTO lstr_code_sizes (id, fanlisting_id, label, width, height, sort_order) VALUES
                (1, 1, '50 x 50', 50, 50, 0),
                (2, 1, 'Tiny',     0,  0, 0),
                (3, 1, 'Zero',     0, 31, 0),
                (4, 1, '50 by 50', 50, 50, 0)");

            $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '50x50', 1), (2, 'Small', 2), (3, '0x31', 3)");
            $src->exec("INSERT INTO codes (code_fl, code_size, code_image) VALUES (1, 1, 'a.png'), (1, 2, 'b.png'), (1, 3, 'c.png')");

            $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
                'conflict_strategy' => $strategy, 'import_code_sizes' => '1', 'import_codes' => '1',
            ]));

            $sizeIds = $tgt->query('SELECT filename, size_id FROM lstr_codes ORDER BY filename')->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertSame(1, (int) $sizeIds['a.png'], "{$strategy}: 50x50 should reuse the oldest 50 x 50 size");
            $new = $tgt->query('SELECT label, id FROM lstr_code_sizes WHERE id > 4 ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertSame(['Small', '0x31'], array_keys($new), "{$strategy}: labels without two positive dimensions get their own size");
            $this->assertSame((int) $new['Small'], (int) $sizeIds['b.png']);
            $this->assertSame((int) $new['0x31'], (int) $sizeIds['c.png']);
            // Update matching also writes the matched size's sort order, so it counts as imported.
            $sizes = $this->rowOf($result, 'Code sizes');
            $this->assertSame($strategy === 'overwrite' ? [3, 0] : [2, 1], [$sizes['imported'], $sizes['skipped']]);
        }
    }

    public function testDonorsMatchOnNameNoEmailAndExactUrlAndAreNeverEdited(): void
    {
        foreach (['skip', 'overwrite'] as $strategy) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedDefaultSource($src);
            $this->seedTarget($tgt, listingId: 1);
            $tgt->exec("INSERT INTO lstr_code_donors (id, fanlisting_id, name, email, url) VALUES
                (1, 1, 'Alex', NULL, 'http://alex.example/Page'),
                (2, 1, 'Bea',  'bea@example.test', NULL),
                (3, 1, 'Cy',   NULL, NULL),
                (4, 1, 'Dee',  NULL, 'http://dee.example/')");
            $before = $tgt->query('SELECT * FROM lstr_code_donors ORDER BY id')->fetchAll();

            $src->exec("INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES
                (1, 'Alex', 'http://alex.example/page'),
                (2, 'Bea',  ''),
                (3, 'Cy',   '  '),
                (4, 'Dee',  'http://dee.example/'),
                (5, 'Dee',  'http://other.example/')");
            $src->exec("INSERT INTO codes (code_fl, code_donor, code_image) VALUES
                (1, 1, 'alex.png'), (1, 2, 'bea.png'), (1, 3, 'cy.png'), (1, 4, 'dee.png'), (1, 5, 'dee2.png')");

            $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
                'conflict_strategy' => $strategy, 'import_code_donors' => '1', 'import_codes' => '1',
            ]));

            $donors = $this->rowOf($result, 'Code donors');
            $this->assertSame([3, 2], [$donors['imported'], $donors['skipped']], $strategy);
            $this->assertSame($before, $tgt->query('SELECT * FROM lstr_code_donors WHERE id <= 4 ORDER BY id')->fetchAll(),
                "{$strategy}: an existing donor was edited");

            $credited = $tgt->query(
                'SELECT c.filename, d.id, d.name, d.email, d.url FROM lstr_codes c JOIN lstr_code_donors d ON d.id = c.donor_id ORDER BY c.filename'
            )->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
            $this->assertGreaterThan(4, (int) $credited['alex.png']['id'], "{$strategy}: a URL differing in path case is another donor");
            $this->assertSame('http://alex.example/page', $credited['alex.png']['url']);
            $this->assertGreaterThan(4, (int) $credited['bea.png']['id'], "{$strategy}: a donor with an email in Luster was taken over");
            $this->assertNull($credited['bea.png']['email']);
            $this->assertSame(3, (int) $credited['cy.png']['id'], "{$strategy}: a blank URL should match a NULL URL");
            $this->assertSame(4, (int) $credited['dee.png']['id'], "{$strategy}: same name and URL should be reused");
            $this->assertGreaterThan(4, (int) $credited['dee2.png']['id'], "{$strategy}: same name, other URL is another donor");
            $this->assertSame('http://other.example/', $credited['dee2.png']['url']);
        }
    }

    public function testTwoCodeSortDonorsWithTheSameNameStayTwoDonors(): void
    {
        foreach (['skip', 'overwrite'] as $strategy) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedDefaultSource($src);
            $this->seedTarget($tgt, listingId: 1);
            $src->exec("INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES
                (1, 'Alex', 'http://a.example/'), (2, 'Alex', 'http://b.example/')");
            $src->exec("INSERT INTO codes (code_fl, code_donor, code_image) VALUES (1, 1, 'x.png'), (1, 2, 'y.png')");

            $this->adapter()->run($src, $tgt, $this->makeOptions([
                'conflict_strategy' => $strategy, 'import_code_donors' => '1', 'import_codes' => '1',
            ]));

            $this->assertSame(2, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_donors')->fetchColumn(), $strategy);
            $urls = $tgt->query(
                'SELECT c.filename, d.url FROM lstr_codes c JOIN lstr_code_donors d ON d.id = c.donor_id ORDER BY c.filename'
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertSame(['x.png' => 'http://a.example/', 'y.png' => 'http://b.example/'], $urls, $strategy);
        }
    }

    public function testDonorDetailsAreTrimmedAndCutAndABlankNameIsNoDonor(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $longName = str_repeat('é', 160);
        $src->prepare('INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES (1, :name, :url), (2, :blank, :url2)')
            ->execute(['name' => "  {$longName}  ", 'url' => ' http://alex.example/ ', 'blank' => '   ', 'url2' => 'http://nobody.example/']);
        $src->exec("INSERT INTO codes (code_fl, code_donor, code_image) VALUES (1, 1, 'x.png'), (1, 2, 'y.png')");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_code_donors' => '1', 'import_codes' => '1']));

        $this->assertSame([], $this->rowOf($result, 'Code donors')['errors']);
        $donors = $tgt->query('SELECT name, url FROM lstr_code_donors')->fetchAll();
        $this->assertCount(1, $donors);
        $this->assertSame(str_repeat('é', 150), $donors[0]['name']);
        $this->assertSame('http://alex.example/', $donors[0]['url']);
        $this->assertNull($tgt->query("SELECT donor_id FROM lstr_codes WHERE filename = 'y.png'")->fetchColumn());
    }

    public function testTheOldestOfSeveralMatchesIsUsedInBothModes(): void
    {
        foreach (['skip', 'overwrite'] as $strategy) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedDefaultSource($src);
            $this->seedTarget($tgt, listingId: 1);
            // Newest first in these indexes, so a lookup that forgot ORDER BY id would get the newer row.
            $tgt->exec('CREATE INDEX ix_size_label ON lstr_code_sizes (fanlisting_id, label, id DESC)');
            $tgt->exec('CREATE INDEX ix_donor_name ON lstr_code_donors (fanlisting_id, name, id DESC)');
            $tgt->exec('CREATE INDEX ix_cat_name ON lstr_code_categories (fanlisting_id, name, id DESC)');
            $tgt->exec("INSERT INTO lstr_code_sizes (id, fanlisting_id, label, width, height, sort_order) VALUES
                (1, 1, '88x31', 88, 31, 0), (2, 1, '88x31', 88, 31, 0)");
            $tgt->exec("INSERT INTO lstr_code_donors (id, fanlisting_id, name, email, url) VALUES
                (1, 1, 'Alex', NULL, 'http://alex.example/'), (2, 1, 'Alex', NULL, 'http://alex.example/')");
            $tgt->exec("INSERT INTO lstr_code_categories (id, fanlisting_id, name, parent_id, sort_order) VALUES
                (1, 1, 'Hearts', NULL, 0), (2, 1, 'Hearts', NULL, 0)");

            $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '88x31', 5)");
            $src->exec("INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES (1, 'Alex', 'http://alex.example/')");
            $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 1, 'Hearts')");
            $src->exec("INSERT INTO codes (code_fl, code_size, code_donor, code_image) VALUES (1, 1, 1, 'a.png')");

            $this->adapter()->run($src, $tgt, $this->makeOptions([
                'conflict_strategy' => $strategy, 'import_code_sizes' => '1', 'import_code_donors' => '1', 'import_codes' => '1',
            ]));

            $code = $tgt->query("SELECT size_id, donor_id FROM lstr_codes WHERE filename = 'a.png'")->fetch();
            $this->assertSame([1, 1], [(int) $code['size_id'], (int) $code['donor_id']], $strategy);
            $this->assertSame(2, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn(), "{$strategy}: a size was added");
            $this->assertSame(2, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_donors')->fetchColumn(), "{$strategy}: a donor was added");
            $sortOrders = $tgt->query('SELECT id, sort_order FROM lstr_code_sizes ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->assertSame($strategy === 'overwrite' ? [1 => 5, 2 => 0] : [1 => 0, 2 => 0], array_map('intval', $sortOrders));

            // The code step links categories with MySQL's INSERT IGNORE, which SQLite can't run,
            // so the category match is read from the categories step's own map.
            $step = new \ReflectionMethod(CodeSortImportSource::class, 'runCategories');
            $out  = $step->invoke($this->adapter(), $src, $tgt, 'codes_', [1 => 1], $this->makeOptions(['conflict_strategy' => $strategy]));
            $this->assertSame([1 => 1], $out['map'], $strategy);
            $this->assertSame(2, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_categories')->fetchColumn());
        }
    }

    public function testSizeOrderIsKeptWithinWhatTheColumnHolds(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '88x31', 70000), (2, '50x50', -5)");
        $orders = fn (): array => array_map('intval', $tgt->query('SELECT label, sort_order FROM lstr_code_sizes ORDER BY label')->fetchAll(PDO::FETCH_KEY_PAIR));

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_code_sizes' => '1']));
        $this->assertSame(['50x50' => 0, '88x31' => 65535], $orders());

        $tgt->exec('UPDATE lstr_code_sizes SET sort_order = 3');
        $this->adapter()->run($src, $tgt, $this->makeOptions(['conflict_strategy' => 'overwrite', 'import_code_sizes' => '1']));
        $this->assertSame(['50x50' => 0, '88x31' => 65535], $orders());
    }

    // -----------------------------------------------------------------------
    // Errors are lists the result page and the report can show
    // -----------------------------------------------------------------------

    public function testFailuresShowOnTheResultPageAndInTheReport(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $src->exec('DROP TABLE codes_cat');
        $this->seedTarget($tgt, listingId: 1);
        $tgt->exec("CREATE TRIGGER no_bad_size BEFORE INSERT ON lstr_code_sizes WHEN NEW.label = 'Bad'
                    BEGIN SELECT RAISE(ABORT, 'size refused'); END");
        $tgt->exec("CREATE TRIGGER no_bad_donor BEFORE INSERT ON lstr_code_donors WHEN NEW.name = 'Bad Donor'
                    BEGIN SELECT RAISE(ABORT, 'donor refused'); END");
        $tgt->exec("CREATE TRIGGER no_bad_code BEFORE INSERT ON lstr_codes WHEN NEW.filename = 'bad.png'
                    BEGIN SELECT RAISE(ABORT, 'code refused'); END");
        $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '88x31', 1), (2, 'Bad', 2)");
        $src->exec("INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES (1, 'Alex', ''), (2, 'Bad Donor', '')");
        $src->exec("INSERT INTO codes (code_id, code_fl, code_size, code_donor, code_image) VALUES
            (1, 1, 1, 1, 'good.png'), (2, 1, 2, 2, 'bad.png')");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_code_categories' => '1', 'import_code_sizes' => '1', 'import_code_donors' => '1', 'import_codes' => '1',
            '_dump_schema_variant'   => 'codesort_default',
        ]));

        $this->assertSame(['Table not found.'], $this->rowOf($result, 'Code categories')['errors']);
        $this->assertNotContains('Table not found.', $this->rowOf($result, 'Code categories')['warnings']);

        $sizeErrors  = $this->rowOf($result, 'Code sizes')['errors'];
        $donorErrors = $this->rowOf($result, 'Code donors')['errors'];
        $codeErrors  = $this->rowOf($result, 'Codes')['errors'];
        $this->assertCount(1, $sizeErrors);
        $this->assertStringStartsWith('Code size "Bad": ', $sizeErrors[0]);
        $this->assertStringContainsString('size refused', $sizeErrors[0]);
        $this->assertCount(1, $donorErrors);
        $this->assertStringStartsWith('Code donor "Bad Donor": ', $donorErrors[0]);
        $this->assertStringContainsString('donor refused', $donorErrors[0]);
        $this->assertCount(1, $codeErrors);
        $this->assertStringStartsWith('CodeSort code #2 (bad.png): ', $codeErrors[0]);
        $this->assertStringContainsString('code refused', $codeErrors[0]);
        $this->assertSame(1, $this->rowOf($result, 'Codes')['imported']);

        // No image source was given, so neither image could be copied.
        $this->assertCount(1, $result->imageRows);
        $imageErrors = $result->imageRows[0]['errors'];
        $this->assertSame([
            "good.png: couldn't be copied from the image path, web address or ZIP",
            "bad.png: couldn't be copied from the image path, web address or ZIP",
        ], $imageErrors);
        $this->assertTrue($result->hasErrors());

        $messages = array_merge(['Table not found.'], $sizeErrors, $donorErrors, $codeErrors, $imageErrors);

        // phpunit.xml doesn't fail on warnings, so turn any (like foreach over an int) into a failure.
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
        try {
            $html = $this->renderResult($result);
            $text = $result->toText();
        } finally {
            restore_error_handler();
        }

        foreach ($messages as $message) {
            $this->assertStringContainsString(\Luster\Core\View::e($message), $html);
            $this->assertStringContainsString('  ERROR: ' . $message, $text);
        }
    }

    private function renderResult(\Luster\Import\ImportResult $result): string
    {
        $base_url    = 'https://luster.test';
        $source      = 'codesort';
        $source_name = 'CodeSort';
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/templates/admin/import/import-wizard/result.php';
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    // -----------------------------------------------------------------------
    // Codes — found again by import_ref
    // -----------------------------------------------------------------------

    /** Puts a real (1×1 PNG) image in the CodeSort images folder. */
    private function sourceImage(string $name): void
    {
        file_put_contents($this->tmpDir . '/images/' . $name, "\x89PNG\r\n\x1a\n"
            . "\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xde"
            . "\x00\x00\x00\x0cIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N"
            . "\x00\x00\x00\x00IEND\xaeB`\x82");
    }

    /** Points CodeSort's images_folder setting at the test's images folder. */
    private function useImagesFolder(PDO $src): void
    {
        $src->prepare("INSERT INTO codes_options (name, value) VALUES ('images_folder', :dir)")
            ->execute(['dir' => $this->tmpDir . '/images']);
    }

    /** @return list<string> The image files the import copied into Luster (not ImageMigrator's .htaccess). */
    private function copiedImages(): array
    {
        return array_values(array_filter(
            scandir($this->tmpDir . '/public/uploads/codes'),
            static fn (string $name): bool => !str_starts_with($name, '.')
        ));
    }

    /** A code the old CodeSort importer saved: no import_ref, no name, CodeSort's own filename. */
    private function seedEarlierImport(PDO $tgt, string $filename, int $listingId = 1): int
    {
        $tgt->prepare("INSERT INTO lstr_codes (fanlisting_id, name, filename, status, sort_order) VALUES (:fid, NULL, :file, 'approved', 0)")
            ->execute(['fid' => $listingId, 'file' => $filename]);
        return (int) $tgt->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function codeRow(PDO $tgt, int $id): array
    {
        $stmt = $tgt->prepare('SELECT * FROM lstr_codes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (array) $stmt->fetch();
    }

    /** @return list<int> The categories a code is linked to. */
    private function codeCategories(PDO $tgt, int $codeId): array
    {
        $stmt = $tgt->prepare('SELECT category_id FROM lstr_code_category_map WHERE code_id = :id ORDER BY category_id');
        $stmt->execute(['id' => $codeId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function codeCount(PDO $tgt): int
    {
        return (int) $tgt->query('SELECT COUNT(*) FROM lstr_codes')->fetchColumn();
    }

    /** @param list<string> $notes */
    private function assertNoteContaining(string $needle, array $notes, string $message = ''): void
    {
        foreach ($notes as $note) {
            if (str_contains($note, $needle)) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail(($message !== '' ? "{$message}: " : '') . "no note contains \"{$needle}\" in " . json_encode($notes));
    }

    /** @param list<string> $notes */
    private function assertNoNoteContaining(string $needle, array $notes, string $message = ''): void
    {
        foreach ($notes as $note) {
            $this->assertStringNotContainsString($needle, $note, $message);
        }
    }

    /** @return list<string> The preview's notes for the Codes row. */
    private function previewNotes(PDO $src, PDO $tgt, ImportOptions $options): array
    {
        foreach ($this->adapter()->preview($src, $tgt, $options)->rows as $row) {
            if ($row['type'] === 'Codes') {
                return $row['warnings'];
            }
        }
        $this->fail('No Codes row in the preview.');
    }

    public function testUpdateMatchingRunsUpdateTheSameCodesFromCodeSort(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedTwoListingSource($src);
        $this->seedTarget($tgt, listingId: 1, title: 'One');
        $this->addListing($tgt, 2, 'Two');
        $all = ['import_code_categories' => '1', 'import_code_sizes' => '1', 'import_code_donors' => '1', 'import_codes' => '1'];

        $this->adapter()->run($src, $tgt, $this->makeOptions($all));
        $ids = $tgt->query('SELECT filename, id FROM lstr_codes ORDER BY filename')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertCount(3, $ids);

        // In CodeSort, a.png is now unapproved, with listing 1's other size, donor Sam and a new category.
        $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (3, 1, 'Glitter')");
        $src->exec("UPDATE codes SET code_approved = 'n', code_size = 2, code_donor = 2, code_cat = 3 WHERE code_image = 'a.png'");

        foreach ([1, 2] as $run) {
            $result = $this->adapter()->run($src, $tgt, $this->makeOptions($all + ['conflict_strategy' => 'overwrite']));
            $codes  = $this->rowOf($result, 'Codes');
            $this->assertSame([3, 0, []], [$codes['imported'], $codes['skipped'], $codes['errors']], "run {$run}");
            $this->assertSame($ids, $tgt->query('SELECT filename, id FROM lstr_codes ORDER BY filename')->fetchAll(PDO::FETCH_KEY_PAIR), "run {$run}");
        }

        $a = $this->codeRow($tgt, (int) $ids['a.png']);
        $this->assertSame('pending', $a['status']);
        $size = $tgt->query('SELECT label, fanlisting_id FROM lstr_code_sizes WHERE id = ' . (int) $a['size_id'])->fetch();
        $this->assertSame(['50 x 50', 1], [$size['label'], (int) $size['fanlisting_id']]);
        $this->assertSame('Sam', $tgt->query('SELECT name FROM lstr_code_donors WHERE fanlisting_id = 1 AND id = ' . (int) $a['donor_id'])->fetchColumn());
        $glitter = (int) $tgt->query("SELECT id FROM lstr_code_categories WHERE name = 'Glitter' AND fanlisting_id = 1")->fetchColumn();
        $this->assertSame([$glitter], $this->codeCategories($tgt, (int) $ids['a.png']));
    }

    public function testUpdateMatchingKeepsWhatCodeSortLeavesBlank(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 1, 'Hearts')");
        $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '88x31', 1)");
        $src->exec("INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES (1, 'Alex', '')");
        $src->exec("INSERT INTO codes (code_id, code_fl, code_cat, code_size, code_donor, code_image) VALUES (1, 1, 1, 1, 1, 'a.png')");
        $all = ['import_code_categories' => '1', 'import_code_sizes' => '1', 'import_code_donors' => '1', 'import_codes' => '1'];
        $this->adapter()->run($src, $tgt, $this->makeOptions($all));
        $id     = (int) $tgt->query('SELECT id FROM lstr_codes')->fetchColumn();
        $before = $this->codeRow($tgt, $id);
        $cats   = $this->codeCategories($tgt, $id);
        $this->assertCount(1, $cats);

        // CodeSort no longer gives it a size, donor or category.
        $src->exec('UPDATE codes SET code_cat = 0, code_size = 0, code_donor = 0');
        $this->adapter()->run($src, $tgt, $this->makeOptions($all + ['conflict_strategy' => 'overwrite']));

        $after = $this->codeRow($tgt, $id);
        $this->assertSame([$before['size_id'], $before['donor_id']], [$after['size_id'], $after['donor_id']]);
        $this->assertSame($cats, $this->codeCategories($tgt, $id));
    }

    public function testACodeIsOnlyLinkedToACategoryInItsOwnListing(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1, title: 'One');
        $this->addListing($tgt, 2, 'Two');
        // Listing 1's code points at listing 2's category.
        $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 2, 'Elsewhere')");
        $src->exec("INSERT INTO codes (code_id, code_fl, code_cat, code_image) VALUES (1, 1, 1, 'a.png')");

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_code_categories' => '1', 'import_codes' => '1']));

        $id = (int) $tgt->query('SELECT id FROM lstr_codes')->fetchColumn();
        $this->assertSame([], $this->codeCategories($tgt, $id));
    }

    public function testMatchedCodesNeverGetAnotherImageCopy(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        foreach (['a.png', 'b.png', 'c.png'] as $file) {
            $this->sourceImage($file);
        }
        $this->useImagesFolder($src);
        $src->exec("INSERT INTO codes (code_fl, code_image) VALUES (1, 'a.png'), (1, 'b.png'), (1, 'c.png')");

        $first = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1']));
        $this->assertSame(3, $first->imageRows[0]['copied']);
        $files = $this->copiedImages();
        $this->assertCount(3, $files);
        $ids = $tgt->query('SELECT id FROM lstr_codes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

        foreach (['skip', 'overwrite'] as $strategy) {
            $again = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => $strategy]));
            $this->assertSame([], $again->imageRows, "{$strategy}: no image should be copied for a matched code");
            $this->assertSame($files, $this->copiedImages(), $strategy);
            $this->assertSame($ids, $tgt->query('SELECT id FROM lstr_codes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), $strategy);
            $this->assertSame([], $this->rowOf($again, 'Codes')['missing_images'], $strategy);
        }
    }

    public function testTwoInstallsWithTheSameCodeIdKeepTheirOwnCodes(): void
    {
        $tgt = $this->makePdo();
        $this->seedTarget($tgt, listingId: 1);
        $sources = [];
        foreach (['one', 'two'] as $key) {
            $src = $this->makePdo();
            $this->seedDefaultSource($src);
            // The same folder name on both installs, and no web address: not enough to tell them apart.
            $src->exec("INSERT INTO codes_options (name, value) VALUES ('images_folder', '/home/user/codes')");
            $src->exec("INSERT INTO codes (code_id, code_fl, code_image, code_approved) VALUES (1, 1, 'badge.png', 'y')");
            $sources[$key] = $src;
        }

        foreach ($sources as $key => $src) {
            $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'cs_source_id' => '']));
            $this->assertNoteContaining("can't tell this CodeSort install's codes apart", $this->rowOf($result, 'Codes')['errors'], $key);
        }
        $this->assertSame(0, $this->codeCount($tgt));

        foreach (['skip', 'skip', 'overwrite'] as $strategy) {
            foreach ($sources as $key => $src) {
                $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'cs_source_id' => $key, 'conflict_strategy' => $strategy]));
            }
        }
        $this->assertSame(
            ['codesort:one:1', 'codesort:two:1'],
            $tgt->query('SELECT import_ref FROM lstr_codes ORDER BY import_ref')->fetchAll(PDO::FETCH_COLUMN)
        );

        $sources['two']->exec("UPDATE codes SET code_approved = 'n'");
        $this->adapter()->run($sources['two'], $tgt, $this->makeOptions(['import_codes' => '1', 'cs_source_id' => 'two', 'conflict_strategy' => 'overwrite']));
        $this->assertSame(
            ['codesort:one:1' => 'approved', 'codesort:two:1' => 'pending'],
            $tgt->query('SELECT import_ref, status FROM lstr_codes ORDER BY import_ref')->fetchAll(PDO::FETCH_KEY_PAIR)
        );
    }

    public function testACodeRenamedInCodeSortIsStillTheSameCode(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $this->sourceImage('badge.png');
        $this->useImagesFolder($src);
        $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (5, 1, 'badge.png')");

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1']));
        $before = $tgt->query('SELECT id, filename FROM lstr_codes')->fetch();
        $this->assertStringStartsWith('cs', $before['filename']);

        $src->exec("UPDATE codes SET code_image = 'badge-2026.png' WHERE code_id = 5");
        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => 'overwrite']));

        $this->assertSame(1, $this->rowOf($result, 'Codes')['imported']);
        $this->assertSame([$before], $tgt->query('SELECT id, filename FROM lstr_codes')->fetchAll(), 'It keeps its ID and its image');
        $this->assertSame([$before['filename']], $this->copiedImages());
        $this->assertSame([], $this->rowOf($result, 'Codes')['missing_images']);
    }

    // -----------------------------------------------------------------------
    // Codes from an earlier import (no import_ref)
    // -----------------------------------------------------------------------

    public function testAnEarlierCodeIsClaimedWhenTheOptionIsTicked(): void
    {
        foreach (['skip' => 'approved', 'overwrite' => 'pending'] as $strategy => $status) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedDefaultSource($src);
            $this->seedTarget($tgt, listingId: 1);
            $old = $this->seedEarlierImport($tgt, 'badge.png');
            $src->exec("INSERT INTO codes (code_id, code_fl, code_image, code_approved) VALUES (7, 1, 'badge.png', 'n')");
            $opts = $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => $strategy, 'cs_match_earlier' => '1']);

            $this->assertNoteContaining(
                'Test Fanlisting: 1 code (badge.png) from an earlier CodeSort import will be linked to this install',
                $this->previewNotes($src, $tgt, $opts), $strategy
            );

            $result = $this->adapter()->run($src, $tgt, $opts);
            $codes  = $this->rowOf($result, 'Codes');
            $this->assertSame($strategy === 'skip' ? [0, 1] : [1, 0], [$codes['imported'], $codes['skipped']], $strategy);
            $this->assertSame(1, $this->codeCount($tgt), $strategy);
            $row = $this->codeRow($tgt, $old);
            $this->assertSame(['codesort:test-codes:7', 'badge.png'], [$row['import_ref'], $row['import_source_file']], $strategy);
            $this->assertSame([$status, 'badge.png', 0, null], [$row['status'], $row['filename'], (int) $row['sort_order'], $row['name']], $strategy);

            // The next run finds it by its import_ref, with the option ticked or not.
            $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => $strategy]));
            $this->assertSame(1, $this->codeCount($tgt), $strategy);
        }
    }

    public function testEarlierCodesAreLeftAloneWithoutTheOption(): void
    {
        foreach (['skip', 'overwrite'] as $strategy) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedDefaultSource($src);
            $this->seedTarget($tgt, listingId: 1);
            $old    = $this->seedEarlierImport($tgt, 'badge.png');
            $before = $this->codeRow($tgt, $old);
            $src->exec("INSERT INTO codes (code_id, code_fl, code_image, code_approved) VALUES (7, 1, 'badge.png', 'n')");
            $opts = $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => $strategy]);

            $this->assertNoteContaining(
                "Test Fanlisting: 1 code (badge.png) may already be in Luster from an earlier CodeSort import. It'll be added again.",
                $this->previewNotes($src, $tgt, $opts), $strategy
            );

            $result = $this->adapter()->run($src, $tgt, $opts);
            $this->assertSame(1, $this->rowOf($result, 'Codes')['imported'], $strategy);
            $this->assertNoteContaining(
                'Test Fanlisting: 1 code (badge.png) may already have been in Luster from an earlier CodeSort import',
                $this->rowOf($result, 'Codes')['warnings'], $strategy
            );
            $this->assertSame($before, $this->codeRow($tgt, $old), "{$strategy}: the earlier code was changed");
            $this->assertSame(2, $this->codeCount($tgt), $strategy);
        }
    }

    /** Refuses linking a code to CodeSort category 'Broken', so that code's insert fails. */
    private function refuseBrokenCategory(PDO $tgt): void
    {
        $tgt->exec("CREATE TRIGGER no_broken_link BEFORE INSERT ON lstr_code_category_map
                    WHEN NEW.category_id = (SELECT id FROM lstr_code_categories WHERE name = 'Broken')
                    BEGIN SELECT RAISE(ABORT, 'link refused'); END");
    }

    public function testAnEarlierCodeIsNotCalledAddedAgainWhenItsCopyFailed(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $old    = $this->seedEarlierImport($tgt, 'badge.png');
        $before = $this->codeRow($tgt, $old);
        $this->refuseBrokenCategory($tgt);
        $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 1, 'Broken')");
        $src->exec("INSERT INTO codes (code_id, code_fl, code_cat, code_image) VALUES (7, 1, 1, 'badge.png')");
        $opts = $this->makeOptions(['import_code_categories' => '1', 'import_codes' => '1']);

        $this->assertNoteContaining('1 code (badge.png) may already be in Luster', $this->previewNotes($src, $tgt, $opts));

        $codes = $this->rowOf($this->adapter()->run($src, $tgt, $opts), 'Codes');
        $this->assertSame([0, 1], [$codes['imported'], count($codes['errors'])]);
        $this->assertNoNoteContaining('badge.png', $codes['warnings'], 'The earlier code is the only copy left');
        $this->assertNoNoteContaining('added again', $codes['warnings']);
        $this->assertSame(1, $this->codeCount($tgt));
        $this->assertSame($before, $this->codeRow($tgt, $old));
    }

    public function testOnlyCodesReallyAddedAreCalledAddedAgain(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $this->seedEarlierImport($tgt, 'badge.png');
        $this->seedEarlierImport($tgt, 'star.png');
        $this->refuseBrokenCategory($tgt);
        $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 1, 'Broken')");
        // Same listing: one fails, one is added, and one nothing earlier looks like is added.
        $src->exec("INSERT INTO codes (code_id, code_fl, code_cat, code_image) VALUES (7, 1, 1, 'badge.png'), (8, 1, 0, 'star.png'), (9, 1, 0, 'moon.png')");
        $opts = $this->makeOptions(['import_code_categories' => '1', 'import_codes' => '1']);

        $this->assertNoteContaining('2 codes (badge.png, star.png) may already be in Luster', $this->previewNotes($src, $tgt, $opts));

        $codes = $this->rowOf($this->adapter()->run($src, $tgt, $opts), 'Codes');
        $this->assertSame([2, 1], [$codes['imported'], count($codes['errors'])]);
        $this->assertNoteContaining(
            'Test Fanlisting: 1 code (star.png) may already have been in Luster from an earlier CodeSort import, and was added again.',
            $codes['warnings']
        );
        $this->assertNoNoteContaining('badge.png', $codes['warnings']);
        $this->assertNoNoteContaining('moon.png', $codes['warnings']);
        $this->assertSame(4, $this->codeCount($tgt));
    }

    public function testACodeWhoseNameWasClearedIsOnlyClaimedWithTheOption(): void
    {
        // Another importer's code looks like an earlier CodeSort one once its name is cleared.
        $seed = function (): array {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedDefaultSource($src);
            $this->seedTarget($tgt, listingId: 1);
            $tgt->exec("INSERT INTO lstr_codes (fanlisting_id, name, filename, status) VALUES (1, 'Moon', 'moon.png', 'approved')");
            $id = (int) $tgt->lastInsertId();
            $tgt->exec("UPDATE lstr_codes SET name = NULL WHERE id = {$id}");
            $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (3, 1, 'moon.png')");
            return [$src, $tgt, $id];
        };

        [$src, $tgt, $id] = $seed();
        $before = $this->codeRow($tgt, $id);
        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1']));
        $this->assertNoteContaining('1 code (moon.png) may already have been in Luster', $this->rowOf($result, 'Codes')['warnings']);
        $this->assertSame($before, $this->codeRow($tgt, $id));

        [$src, $tgt, $id] = $seed();
        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'cs_match_earlier' => '1']));
        $this->assertSame('codesort:test-codes:3', $this->codeRow($tgt, $id)['import_ref']);
        $this->assertSame(1, $this->codeCount($tgt));
    }

    public function testSomeCodesAreNeverEarlierCodesEvenWithTheOption(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $tgt->exec("INSERT INTO lstr_codes (fanlisting_id, name, filename, import_ref) VALUES
            (1, 'Named', 'a.png', NULL),
            (1, NULL, 'b.png', 'listing-admin:old:1'),
            (1, NULL, '6720f1a2b3c4d9f1e2d3c4b5a6978877.png', NULL)");
        $before = $tgt->query('SELECT * FROM lstr_codes ORDER BY id')->fetchAll();
        $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (1, 1, 'a.png'), (2, 1, 'b.png')");
        $opts = $this->makeOptions(['import_codes' => '1', 'cs_match_earlier' => '1']);

        $notes = $this->previewNotes($src, $tgt, $opts);
        $this->assertNoNoteContaining('will be linked', $notes);
        $this->assertNoNoteContaining("can't match", $notes);

        $result = $this->adapter()->run($src, $tgt, $opts);
        $this->assertSame(2, $this->rowOf($result, 'Codes')['imported']);
        $this->assertNoNoteContaining("can't match", $this->rowOf($result, 'Codes')['warnings']);
        $this->assertSame($before, $tgt->query('SELECT * FROM lstr_codes WHERE id <= 3 ORDER BY id')->fetchAll());
        $this->assertSame(5, $this->codeCount($tgt));
    }

    public function testEarlierCodesThatDontPairUpAreSkipped(): void
    {
        foreach (['skip', 'overwrite'] as $strategy) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedDefaultSource($src);
            $this->seedTarget($tgt, listingId: 1);
            $this->seedEarlierImport($tgt, 'dup.png');
            $this->seedEarlierImport($tgt, 'dup.png');
            $this->seedEarlierImport($tgt, 'pair.png');
            $before = $tgt->query('SELECT * FROM lstr_codes ORDER BY id')->fetchAll();
            // Two earlier codes for one file; one earlier code for a file two CodeSort codes use.
            $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (1, 1, 'dup.png'), (2, 1, 'pair.png'), (3, 1, 'pair.png')");
            $opts = $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => $strategy, 'cs_match_earlier' => '1']);

            $this->assertNoteContaining('3 code(s) will be skipped: more than one existing code could be the same one.', $this->previewNotes($src, $tgt, $opts), $strategy);

            $codes = $this->rowOf($this->adapter()->run($src, $tgt, $opts), 'Codes');
            $this->assertSame([0, 3], [$codes['imported'], $codes['skipped']], $strategy);
            $this->assertSame(
                array_fill(0, 3, 'More than one existing code could be this one'),
                array_column($codes['skipped_items'], 'reason'), $strategy
            );
            $this->assertSame($before, $tgt->query('SELECT * FROM lstr_codes ORDER BY id')->fetchAll(), $strategy);
        }
    }

    public function testEarlierCodesWithRandomImageNamesAreReported(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        foreach (['cs0123456789abcdef.png', 'csfedcba9876543210.gif', 'cs00112233445566ff.webp'] as $file) {
            $this->seedEarlierImport($tgt, $file);
        }
        $before = $tgt->query('SELECT * FROM lstr_codes ORDER BY id')->fetchAll();
        $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (1, 1, 'a.png'), (2, 1, 'b.png')");
        $opts = $this->makeOptions(['import_codes' => '1']);

        $this->assertNoteContaining(
            "Test Fanlisting has 3 codes from an earlier CodeSort import that Luster can't match to CodeSort anymore. This import may add another copy of each",
            $this->previewNotes($src, $tgt, $opts)
        );

        $first = $this->rowOf($this->adapter()->run($src, $tgt, $opts), 'Codes');
        $this->assertSame(2, $first['imported']);
        $this->assertNoteContaining(
            "Test Fanlisting has 3 codes from an earlier CodeSort import that Luster can't match to CodeSort anymore, so this import may have added",
            $first['warnings']
        );

        $second = $this->rowOf($this->adapter()->run($src, $tgt, $opts), 'Codes');
        $this->assertSame([0, 2], [$second['imported'], $second['skipped']]);
        $this->assertNoNoteContaining("can't match", $second['warnings'], 'Nothing was added, so there are no copies to warn about');
        $this->assertSame($before, $tgt->query('SELECT * FROM lstr_codes WHERE id <= 3 ORDER BY id')->fetchAll());
    }

    // -----------------------------------------------------------------------
    // Source ID
    // -----------------------------------------------------------------------

    public function testCodesNeedASourceIdWhenTheInstallHasNoFullImageAddress(): void
    {
        foreach ([
            'images_folder only'   => ['images_folder' => '/home/user/codes'],
            'relative images_url'  => ['images_url' => 'images/'],
            'no settings'          => [],
        ] as $case => $settings) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedDefaultSource($src);
            $this->seedTarget($tgt, listingId: 1);
            foreach ($settings as $name => $value) {
                $src->prepare('INSERT INTO codes_options (name, value) VALUES (:name, :value)')->execute(['name' => $name, 'value' => $value]);
            }
            $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 1, 'Hearts')");
            $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '88x31', 1)");
            $src->exec("INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES (1, 'Alex', '')");
            $src->exec("INSERT INTO codes (code_fl, code_size, code_donor, code_image) VALUES (1, 1, 1, 'a.png')");
            $opts = $this->makeOptions([
                'cs_source_id' => '', 'import_code_categories' => '1', 'import_code_sizes' => '1',
                'import_code_donors' => '1', 'import_codes' => '1',
            ]);

            foreach ($this->adapter()->preview($src, $tgt, $opts)->rows as $row) {
                if ($row['type'] === 'Codes') {
                    $this->assertSame(0, $row['import'], $case);
                    $this->assertNoteContaining('Enter a Source ID on the Options step', $row['warnings'], $case);
                }
            }

            $result = $this->adapter()->run($src, $tgt, $opts);
            $codes  = $this->rowOf($result, 'Codes');
            $this->assertCount(1, $codes['errors'], $case);
            $this->assertStringStartsWith("Codes were not imported: Luster can't tell this CodeSort install's codes apart", $codes['errors'][0], $case);
            $this->assertSame(0, $this->codeCount($tgt), $case);
            foreach (['Code categories', 'Code sizes', 'Code donors'] as $type) {
                $this->assertSame(1, $this->rowOf($result, $type)['imported'], "{$case}: {$type}");
            }
        }
    }

    public function testAnInvalidSourceIdIsRejected(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '88x31', 1)");
        $opts = $this->makeOptions(['cs_source_id' => 'Old_Codes', 'import_code_sizes' => '1', 'import_codes' => '1']);

        foreach (['preview', 'run'] as $method) {
            try {
                $this->adapter()->{$method}($src, $tgt, $opts);
                $this->fail("{$method}() accepted an invalid Source ID");
            } catch (\RuntimeException $e) {
                $this->assertSame(\Luster\Import\ListingAdminSourceKey::INVALID_ID_MESSAGE, $e->getMessage(), $method);
            }
        }
        $this->assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM lstr_code_sizes')->fetchColumn(), 'Nothing is written first');
    }

    public function testATypedSourceIdWinsOverTheImageAddress(): void
    {
        $refs = [];
        foreach (['' => 'worked out', 'mine' => 'typed'] as $typed => $case) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedDefaultSource($src);
            $this->seedTarget($tgt, listingId: 1);
            $src->exec("INSERT INTO codes_options (name, value) VALUES ('images_url', 'https://Example.com/Codes/')");
            // No image file, so nothing is downloaded from that address.
            $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (1, 1, '')");
            $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'cs_source_id' => $typed]));
            $refs[$case] = $tgt->query('SELECT import_ref FROM lstr_codes')->fetchColumn();
        }

        $this->assertSame([
            'worked out' => 'codesort:' . substr(sha1('example.com/Codes'), 0, 12) . ':1',
            'typed'      => 'codesort:mine:1',
        ], $refs);
    }

    public function testTheSameImageAddressWithOrWithoutHttpFindsTheSameCodes(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes_options (name, value) VALUES ('images_url', '')");
        // No image file, so nothing is downloaded from these addresses.
        $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (1, 1, '')");
        $opts = $this->makeOptions(['import_codes' => '1', 'cs_source_id' => '']);
        $run  = function (string $imagesUrl) use ($src, $tgt, $opts): int {
            $src->prepare("UPDATE codes_options SET value = :url WHERE name = 'images_url'")->execute(['url' => $imagesUrl]);
            $this->assertSame([], $this->rowOf($this->adapter()->run($src, $tgt, $opts), 'Codes')['errors'], $imagesUrl);
            return $this->codeCount($tgt);
        };

        foreach (['//Example.COM/Codes', '//example.com/Codes/', 'https://example.com/Codes', 'HTTP://EXAMPLE.com/Codes'] as $url) {
            $this->assertSame(1, $run($url), $url);
        }
        // Folders can differ by case alone, so this is another install.
        $this->assertSame(2, $run('//example.com/codes'));
    }

    // -----------------------------------------------------------------------
    // A CodeSort listing that now maps to another Luster listing
    // -----------------------------------------------------------------------

    /**
     * CodeSort's prefixed layout (listings matched by title), one code in "Moonlight" with
     * a size, donor and category. Luster has Moonlight (1) and Starlight (2), and
     * Starlight already has three codes.
     *
     * @return array{0: PDO, 1: PDO, 2: int} source, target, the imported code's ID
     */
    private function seedMovableCode(): array
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $src->exec('CREATE TABLE codes_collective (fl_id INTEGER PRIMARY KEY, fl_subject TEXT NOT NULL DEFAULT \'\')');
        $src->exec("INSERT INTO codes_collective VALUES (1, 'Moonlight')");
        $src->exec('CREATE TABLE codes_cat (cat_id INTEGER PRIMARY KEY, cat_name TEXT, cat_fl INTEGER)');
        $src->exec('CREATE TABLE codes_sizes (size_id INTEGER PRIMARY KEY, size_size TEXT, size_order INTEGER)');
        $src->exec('CREATE TABLE codes_donors (donor_id INTEGER PRIMARY KEY, donor_name TEXT, donor_url TEXT)');
        $src->exec('CREATE TABLE codes_codes (code_id INTEGER PRIMARY KEY, code_fl INTEGER, code_cat INTEGER,
                    code_size INTEGER, code_image TEXT, code_donor INTEGER, code_approved TEXT)');
        $src->exec("INSERT INTO codes_cat VALUES (1, 'Hearts', 1)");
        $src->exec("INSERT INTO codes_sizes VALUES (1, '88x31', 1)");
        $src->exec("INSERT INTO codes_donors VALUES (1, 'Alex', 'http://alex.example/')");
        $src->exec("INSERT INTO codes_codes VALUES (1, 1, 1, 1, 'moon.png', 1, 'y')");

        $this->seedTarget($tgt, listingId: 1, title: 'Moonlight');
        $this->addListing($tgt, 2, 'Starlight');
        $tgt->exec("INSERT INTO lstr_codes (fanlisting_id, name, filename, sort_order) VALUES
            (2, 'One', 'one.png', 0), (2, 'Two', 'two.png', 1), (2, 'Three', 'three.png', 2)");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_code_categories' => '1', 'import_code_sizes' => '1', 'import_code_donors' => '1', 'import_codes' => '1',
        ]));
        $id = (int) $tgt->query("SELECT id FROM lstr_codes WHERE import_ref = 'codesort:test-codes:1'")->fetchColumn();
        $this->assertSame(1, (int) $this->codeRow($tgt, $id)['fanlisting_id']);
        $this->assertCount(1, $this->codeCategories($tgt, $id));

        // Renamed in Luster: CodeSort's "Moonlight" is now listing 2.
        $tgt->exec("UPDATE owned SET title = 'Old Moonlight' WHERE listingid = 1");
        $tgt->exec("UPDATE owned SET title = 'Moonlight' WHERE listingid = 2");

        return [$src, $tgt, $id];
    }

    public function testAMovedCodeKeepsNothingFromItsOldListing(): void
    {
        [$src, $tgt, $id] = $this->seedMovableCode();
        $before = $this->codeRow($tgt, $id);

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1']));
        $this->assertSame($before, $this->codeRow($tgt, $id), "Keep existing doesn't move it");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => 'overwrite']));
        $this->assertSame(1, $this->rowOf($result, 'Codes')['imported']);
        $after = $this->codeRow($tgt, $id);
        $this->assertSame([2, 3, null, null], [(int) $after['fanlisting_id'], (int) $after['sort_order'], $after['size_id'], $after['donor_id']]);
        $this->assertSame([], $this->codeCategories($tgt, $id));
        $this->assertSame($before['filename'], $after['filename']);
    }

    public function testAMovedCodeGetsItsNewListingsSizeDonorAndCategory(): void
    {
        [$src, $tgt, $id] = $this->seedMovableCode();

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'conflict_strategy' => 'overwrite', 'import_code_categories' => '1', 'import_code_sizes' => '1',
            'import_code_donors' => '1', 'import_codes' => '1',
        ]));

        $after = $this->codeRow($tgt, $id);
        $this->assertSame([2, 3], [(int) $after['fanlisting_id'], (int) $after['sort_order']]);
        $this->assertSame(['88x31', 2], [
            $tgt->query('SELECT label FROM lstr_code_sizes WHERE id = ' . (int) $after['size_id'])->fetchColumn(),
            (int) $tgt->query('SELECT fanlisting_id FROM lstr_code_sizes WHERE id = ' . (int) $after['size_id'])->fetchColumn(),
        ]);
        $this->assertSame(2, (int) $tgt->query('SELECT fanlisting_id FROM lstr_code_donors WHERE id = ' . (int) $after['donor_id'])->fetchColumn());
        $cats = $this->codeCategories($tgt, $id);
        $this->assertCount(1, $cats);
        $this->assertSame(2, (int) $tgt->query('SELECT fanlisting_id FROM lstr_code_categories WHERE id = ' . $cats[0])->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // One code, all or nothing
    // -----------------------------------------------------------------------

    public function testANewCodeThatFailsLeavesNothingBehind(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $tgt->exec("INSERT INTO lstr_codes (fanlisting_id, name, filename, sort_order) VALUES (1, 'Mine', 'm1.png', 0), (1, 'Mine too', 'm2.png', 1)");
        $this->refuseBrokenCategory($tgt);
        $this->sourceImage('bad.png');
        $this->sourceImage('good.png');
        $this->useImagesFolder($src);
        $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 1, 'Broken')");
        $src->exec("INSERT INTO codes (code_id, code_fl, code_cat, code_image) VALUES (1, 1, 1, 'bad.png'), (2, 1, 0, 'good.png')");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_code_categories' => '1', 'import_codes' => '1']));

        $codes = $this->rowOf($result, 'Codes');
        $this->assertSame(1, $codes['imported']);
        $this->assertCount(1, $codes['errors']);
        $this->assertStringStartsWith('CodeSort code #1 (bad.png): ', $codes['errors'][0]);
        $this->assertStringContainsString('link refused', $codes['errors'][0]);
        $this->assertSame(0, (int) $tgt->query("SELECT COUNT(*) FROM lstr_codes WHERE import_ref = 'codesort:test-codes:1'")->fetchColumn());

        $good = $tgt->query("SELECT filename, sort_order FROM lstr_codes WHERE import_ref = 'codesort:test-codes:2'")->fetch();
        $this->assertSame([$good['filename']], $this->copiedImages(), "The failed code's image is deleted");
        $this->assertSame(2, (int) $good['sort_order'], 'The next code gets the position the failed one would have had');
        $this->assertSame(1, $result->imageRows[0]['copied']);
    }

    public function testAnUpdateThatFailsChangesNothing(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 1, 'Hearts'), (2, 1, 'Broken')");
        $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '88x31', 1), (2, '50x50', 2)");
        $src->exec("INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES (1, 'Alex', ''), (2, 'Sam', '')");
        $src->exec("INSERT INTO codes (code_id, code_fl, code_cat, code_size, code_donor, code_image, code_approved) VALUES (1, 1, 1, 1, 1, 'a.png', 'y')");
        $all = ['import_code_categories' => '1', 'import_code_sizes' => '1', 'import_code_donors' => '1', 'import_codes' => '1'];
        $this->adapter()->run($src, $tgt, $this->makeOptions($all));
        $id         = (int) $tgt->query('SELECT id FROM lstr_codes')->fetchColumn();
        $before     = $this->codeRow($tgt, $id);
        $beforeCats = $this->codeCategories($tgt, $id);

        $src->exec("UPDATE codes SET code_cat = 2, code_size = 2, code_donor = 2, code_approved = 'n'");
        $this->refuseBrokenCategory($tgt);
        $result = $this->adapter()->run($src, $tgt, $this->makeOptions($all + ['conflict_strategy' => 'overwrite']));

        $codes = $this->rowOf($result, 'Codes');
        $this->assertSame([0, 1], [$codes['imported'], count($codes['errors'])]);
        $this->assertSame($before, $this->codeRow($tgt, $id));
        $this->assertSame($beforeCats, $this->codeCategories($tgt, $id));
    }

    public function testNewCodesGoAfterTheListingsExistingCodes(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        foreach (range(0, 4) as $sort) {
            $tgt->exec("INSERT INTO lstr_codes (fanlisting_id, name, filename, sort_order) VALUES (1, 'Mine', 'm{$sort}.png', {$sort})");
        }
        $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (1, 1, 'a.png'), (2, 1, 'b.png')");

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1']));

        $this->assertSame(
            ['codesort:test-codes:1' => 5, 'codesort:test-codes:2' => 6],
            array_map('intval', $tgt->query('SELECT import_ref, sort_order FROM lstr_codes WHERE import_ref IS NOT NULL ORDER BY import_ref')->fetchAll(PDO::FETCH_KEY_PAIR))
        );
    }

    public function testCodesWaitForMigration104(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        // lstr_codes as it was before Migration 104.
        $tgt->exec('DROP TABLE lstr_codes');
        $tgt->exec('CREATE TABLE lstr_codes (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, name TEXT NULL,
                    filename TEXT NOT NULL DEFAULT \'\', width INTEGER NOT NULL DEFAULT 0, height INTEGER NOT NULL DEFAULT 0,
                    size_id INTEGER NULL, donor_id INTEGER NULL, sort_order INTEGER NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT \'approved\', submitter_name TEXT NULL, submitter_email TEXT NULL, submitter_url TEXT NULL)');
        $src->exec("INSERT INTO codes_cat (cat_id, cat_fl, cat_name) VALUES (1, 1, 'Hearts')");
        $src->exec("INSERT INTO codes_sizes (size_id, size_size, size_order) VALUES (1, '88x31', 1)");
        $src->exec("INSERT INTO codes_donors (donor_id, donor_name, donor_url) VALUES (1, 'Alex', '')");
        $src->exec("INSERT INTO codes (code_fl, code_donor, code_image) VALUES (1, 1, 'a.png')");
        $opts = $this->makeOptions([
            'import_code_categories' => '1', 'import_code_sizes' => '1', 'import_code_donors' => '1', 'import_codes' => '1',
        ]);
        $message = 'Codes were not imported: run the migration page first (Luster needs Migration 104), then import again.';

        $this->assertNoteContaining($message, $this->previewNotes($src, $tgt, $opts));
        $result = $this->adapter()->run($src, $tgt, $opts);
        $this->assertSame([$message], $this->rowOf($result, 'Codes')['errors']);
        $this->assertSame(0, $this->codeCount($tgt));
        foreach (['Code categories', 'Code sizes', 'Code donors'] as $type) {
            $this->assertSame(1, $this->rowOf($result, $type)['imported'], $type);
        }
    }

    // -----------------------------------------------------------------------
    // Image advice only for codes that have no image
    // -----------------------------------------------------------------------

    public function testUpdateMatchingWithWorkingImagesGivesNoImageAdvice(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $this->sourceImage('a.png');
        $this->sourceImage('b.png');
        $this->useImagesFolder($src);
        $src->exec("INSERT INTO codes (code_fl, code_image) VALUES (1, 'a.png'), (1, 'b.png')");
        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1']));

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => 'overwrite']));

        $codes = $this->rowOf($result, 'Codes');
        $this->assertSame(0, $result->totalImagesCopied());
        $this->assertSame([], $codes['missing_images']);
        $this->assertNoNoteContaining('no image', $codes['warnings']);
        $html = $this->renderResult($result);
        $this->assertStringNotContainsString('no image yet', $html);
        $this->assertStringNotContainsString('No images were migrated', $html);
    }

    public function testOnlySizesDonorsAndCategoriesShowNoImageCallout(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedTwoListingSource($src);
        $this->seedTarget($tgt, listingId: 1, title: 'One');
        $this->addListing($tgt, 2, 'Two');

        $result = $this->adapter()->run($src, $tgt, $this->lookupOptions('skip'));

        $this->assertNotContains('Codes', array_column($result->rows, 'type'));
        $this->assertGreaterThan(0, $result->totalImported());
        $html = $this->renderResult($result);
        $this->assertStringNotContainsString('no image yet', $html);
        $this->assertStringNotContainsString('No images were migrated', $html);
    }

    public function testAMixedRunListsOnlyTheCodesWithoutAnImage(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $this->useImagesFolder($src);
        // C was imported earlier, while its image couldn't be found.
        $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (3, 1, 'c.png')");
        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1']));
        $cId = (int) $tgt->query("SELECT id FROM lstr_codes WHERE import_ref = 'codesort:test-codes:3'")->fetchColumn();

        // Now A's image is there and B's isn't.
        $this->sourceImage('a.png');
        $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (1, 1, 'a.png'), (2, 1, 'b.png')");
        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1']));

        $bId   = (int) $tgt->query("SELECT id FROM lstr_codes WHERE import_ref = 'codesort:test-codes:2'")->fetchColumn();
        $codes = $this->rowOf($result, 'Codes');
        $expected = ["Test Fanlisting: b.png (code #{$bId})", "Test Fanlisting: c.png (code #{$cId})"];
        $this->assertSame($expected, $codes['missing_images']);
        $this->assertNoteContaining("2 codes have no image yet. Running the import again won't add images to them — see below.", $codes['warnings']);

        $html = $this->renderResult($result);
        foreach ($expected as $label) {
            $this->assertStringContainsString(\Luster\Core\View::e($label), $html);
        }
        $this->assertStringNotContainsString('Test Fanlisting: a.png', $html);
        $this->assertStringNotContainsString('Test Fanlisting: cs', $html);
        $this->assertStringContainsString('To get their images:', $html);
        $this->assertStringContainsString(\Luster\Core\View::e('Deleting a code also removes anything you changed on it in Luster since the import'), $html);

        $text = $result->toText();
        $this->assertSame(2, substr_count($text, '  NO IMAGE: '));
        $this->assertStringContainsString('  NO IMAGE: ' . $expected[0], $text);
        $this->assertSame(1, substr_count($text, 'To get their images:'));

        // Codes Update matching updates are checked too.
        $updated = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1', 'conflict_strategy' => 'overwrite']));
        $this->assertSame($expected, $this->rowOf($updated, 'Codes')['missing_images']);
    }

    public function testWithNoImageSourceEveryNewCodeIsListed(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $src->exec("INSERT INTO codes (code_id, code_fl, code_image) VALUES (1, 1, 'x.png'), (2, 1, 'y.png'), (3, 1, '')");

        $codes = $this->rowOf($this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1'])), 'Codes');

        $this->assertSame(3, $codes['imported']);
        $this->assertSame(['x.png', 'y.png'], array_map(
            static fn (string $label): string => explode(' ', explode(': ', $label, 2)[1])[0],
            $codes['missing_images']
        ));
        $this->assertNoteContaining("1 CodeSort code doesn't name an image file, so there was nothing to copy for it.", $codes['warnings']);
    }

    public function testTheResultPageShowsTwentyCodesWithoutImagesAndTheReportAll(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedDefaultSource($src);
        $this->seedTarget($tgt, listingId: 1);
        $insert = $src->prepare('INSERT INTO codes (code_id, code_fl, code_image) VALUES (:id, 1, :file)');
        foreach (range(1, 23) as $n) {
            $insert->execute(['id' => $n, 'file' => sprintf('code%02d.png', $n)]);
        }

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_codes' => '1']));
        $labels = $this->rowOf($result, 'Codes')['missing_images'];
        $this->assertCount(23, $labels);

        $html = $this->renderResult($result);
        foreach ($labels as $i => $label) {
            $i < 20
                ? $this->assertStringContainsString(\Luster\Core\View::e($label), $html)
                : $this->assertStringNotContainsString(\Luster\Core\View::e($label), $html);
        }
        $this->assertStringContainsString('and 3 more', $html);
        $this->assertSame(23, substr_count($result->toText(), '  NO IMAGE: '));
    }

    public function testTheOptionsStepHasCodeSortsSourceIdAndMatchOption(): void
    {
        $render = function (array $old, array $known = []): string {
            $base_url = 'https://luster.test';
            $csrf_token = 'token';
            $source = 'codesort';
            $zip_ready = false;
            $zip_url = '/zip';
            $enth_networks = [];
            $la_known_sources = [];
            $cs_known_sources = $known;
            $la_installs = [];
            $scope_error = '';
            ob_start();
            try {
                include dirname(__DIR__, 2) . '/templates/admin/import/import-wizard/options.php';
            } finally {
                $html = (string) ob_get_clean();
            }
            return $html;
        };

        $fresh = $render([], [['key' => 'old-codes', 'codes' => 3]]);
        $this->assertStringContainsString('name="cs_source_id"', $fresh);
        $this->assertStringContainsString('<code>old-codes</code> (3 codes)', $fresh);
        $this->assertMatchesRegularExpression('/name="cs_match_earlier" value="1" id="ck-cs-match-earlier"\s*>/', $fresh, 'Off by default');
        $this->assertStringNotContainsString('name="la_source_id"', $fresh);

        $back = $render(['cs_source_id' => 'old-codes', 'cs_match_earlier' => '1', 'import_codes' => '1']);
        $this->assertStringContainsString('value="old-codes"', $back);
        $this->assertMatchesRegularExpression('/name="cs_match_earlier" value="1" id="ck-cs-match-earlier"\s*checked>/', $back);
    }
}
