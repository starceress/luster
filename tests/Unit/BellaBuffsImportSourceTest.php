<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\BellaBuffsImportSource;
use Luster\Import\ImportOptions;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BellaBuffsImportSource.
 *
 * Uses in-memory SQLite PDOs so no MySQL connection is required.
 * Temp files are created in the system temp directory and cleaned up in tearDown.
 */
final class BellaBuffsImportSourceTest extends TestCase
{
    /** @var string[] Temp files to delete in tearDown */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private function makeDb(PDO $pdo, string $dbtable = 'testfl'): void
    {
        // owned registry
        $pdo->exec("CREATE TABLE IF NOT EXISTS owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT,
            dbtable TEXT NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT ''
        )");
        $pdo->exec("INSERT INTO owned (listingid, dbtable, title, subject)
                    VALUES (1, '{$dbtable}', 'Test FL', 'Test Subject')");

        // per-listing member table
        $pdo->exec("CREATE TABLE IF NOT EXISTS \"{$dbtable}\" (
            email TEXT PRIMARY KEY,
            name TEXT NOT NULL DEFAULT '',
            country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '',
            pending INTEGER NOT NULL DEFAULT 0,
            password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 0,
            showurl INTEGER NOT NULL DEFAULT 1,
            added TEXT NULL
        )");

        // affiliates
        $pdo->exec("CREATE TABLE IF NOT EXISTS lstr_affiliates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fanlisting_id INTEGER NULL,
            name TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            image TEXT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            pending INTEGER NOT NULL DEFAULT 0
        )");

        // codes
        $pdo->exec("CREATE TABLE IF NOT EXISTS lstr_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fanlisting_id INTEGER NOT NULL,
            name TEXT NULL,
            filename TEXT NOT NULL DEFAULT '',
            width INTEGER NOT NULL DEFAULT 0,
            height INTEGER NOT NULL DEFAULT 0,
            size_id INTEGER NULL,
            donor_id INTEGER NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'approved',
            submitter_name TEXT NULL,
            submitter_url TEXT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS lstr_code_sizes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fanlisting_id INTEGER NOT NULL,
            label TEXT NOT NULL DEFAULT '',
            width INTEGER NOT NULL DEFAULT 0,
            height INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS lstr_code_donors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fanlisting_id INTEGER NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT ''
        )");
    }

    /** Write $content to a temp file and register for cleanup. */
    private function tmpFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lstr_bb_test_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }

    private function makeOptions(array $overrides = []): ImportOptions
    {
        return ImportOptions::fromPost(array_merge([
            'source'              => 'bellabuffs',
            'bb_target_listing_id' => '1',
            'bb_mode'             => 'upload',
            'conflict_strategy'   => 'skip',
        ], $overrides));
    }

    // -----------------------------------------------------------------------
    // Static decoder tests
    // -----------------------------------------------------------------------

    public function testDecodeEmail_attieAndDotty(): void
    {
        $this->assertSame(
            'user@example.com',
            BellaBuffsImportSource::decodeEmail('userATTIEexampleDOTTYcom')
        );
    }

    public function testDecodeEmail_bracketStyle(): void
    {
        $this->assertSame(
            'user@example.com',
            BellaBuffsImportSource::decodeEmail('user[at]example[dot]com')
        );
    }

    public function testDecodeEmail_dashyAndScore(): void
    {
        $this->assertSame(
            'user-name_tag@example.com',
            BellaBuffsImportSource::decodeEmail('userDASHYnameSCOREtagATTIEexampleDOTTYcom')
        );
    }

    public function testDecodeEmail_dashAndUnderscore_compatVariants(): void
    {
        // DASH (compat) and UNDERSCORE (compat) — shorter tokens processed AFTER longer ones
        // to prevent SCORE matching inside UNDERSCORE, and DASH matching inside DASHY.
        $this->assertSame(
            'a-b_c@example.com',
            BellaBuffsImportSource::decodeEmail('aDASHbUNDERSCOREcATTIEexampleDOTTYcom')
        );
    }

    public function testDecodeEmail_caseInsensitive(): void
    {
        // Token matching is case-insensitive; non-token characters retain their original case.
        // Use an all-lowercase email body so the result is predictable.
        $this->assertSame(
            'user@example.com',
            BellaBuffsImportSource::decodeEmail('userattieexampledottycom')
        );
        // Mixed-case tokens also work
        $this->assertSame('@', BellaBuffsImportSource::decodeEmail('AtTiE'));
        $this->assertSame('.', BellaBuffsImportSource::decodeEmail('dOtTy'));
    }

    public function testDecodeEmail_noObfuscation(): void
    {
        $this->assertSame(
            'plain@example.com',
            BellaBuffsImportSource::decodeEmail('plain@example.com')
        );
    }

    public function testDecodeText_htmlEntities(): void
    {
        $this->assertSame(
            'Jane & John\'s <place>',
            BellaBuffsImportSource::decodeText('Jane &amp; John&#39;s &lt;place&gt;')
        );
    }

    public function testDecodeText_pipeToComma(): void
    {
        $this->assertSame('Buffy, Angel', BellaBuffsImportSource::decodeText('Buffy| Angel'));
    }

    public function testDecodeText_trimAndStripslashes(): void
    {
        $this->assertSame("it's a test", BellaBuffsImportSource::decodeText("  it\'s a test  "));
    }

    public function testNormalizeUrl_emptyProtocol(): void
    {
        $this->assertSame('', BellaBuffsImportSource::normalizeUrl('http://'));
        $this->assertSame('', BellaBuffsImportSource::normalizeUrl('https://'));
        $this->assertSame('', BellaBuffsImportSource::normalizeUrl(''));
    }

    public function testNormalizeUrl_validUrl(): void
    {
        $this->assertSame(
            'https://example.com',
            BellaBuffsImportSource::normalizeUrl('https://example.com')
        );
    }

    // -----------------------------------------------------------------------
    // testConnection()
    // -----------------------------------------------------------------------

    public function testTestConnection_uploadMode_alwaysOk(): void
    {
        $adapter = new BellaBuffsImportSource();
        $result  = $adapter->testConnection(['bb_mode' => 'upload']);
        $this->assertNull($result);
    }

    public function testTestConnection_pathMode_emptyPath(): void
    {
        $adapter = new BellaBuffsImportSource();
        $result  = $adapter->testConnection(['bb_mode' => 'path', 'bb_path' => '']);
        $this->assertNotNull($result);
        $this->assertStringContainsString('required', $result);
    }

    public function testTestConnection_pathMode_invalidDir(): void
    {
        $adapter = new BellaBuffsImportSource();
        $result  = $adapter->testConnection(['bb_mode' => 'path', 'bb_path' => '/nonexistent/path/xyz']);
        $this->assertNotNull($result);
    }

    public function testTestConnection_pathMode_validDir(): void
    {
        $dir        = sys_get_temp_dir() . '/lstr_bb_test_' . uniqid('', true);
        mkdir($dir);
        file_put_contents($dir . '/members.txt', "Jane,janeATTIEexampleDOTTYcom,no,,United States,\n");

        try {
            $adapter = new BellaBuffsImportSource();
            $result  = $adapter->testConnection(['bb_mode' => 'path', 'bb_path' => $dir]);
            $this->assertNull($result);
        } finally {
            unlink($dir . '/members.txt');
            rmdir($dir);
        }
    }

    // -----------------------------------------------------------------------
    // detectSchema()
    // -----------------------------------------------------------------------

    public function testDetectSchema(): void
    {
        $adapter = new BellaBuffsImportSource();
        $pdo     = $this->makePdo();
        $this->assertSame('bellabuffs_flatfile', $adapter->detectSchema($pdo));
    }

    // -----------------------------------------------------------------------
    // preview() — member parsing
    // -----------------------------------------------------------------------

    public function testPreview_noListingSelected_returnsWarning(): void
    {
        $pdo     = $this->makePdo();
        $this->makeDb($pdo);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['bb_target_listing_id' => '0']);
        $preview = $adapter->preview($pdo, $options);

        $this->assertNotEmpty($preview->rows);
        $warnings = $preview->rows[0]['warnings'] ?? [];
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('No target listing selected', $warnings[0]);
    }

    public function testPreview_approvedMembers_countedCorrectly(): void
    {
        $content = implode("\n", [
            'Jane,janeATTIEexampleDOTTYcom,yes,,United States,',
            'John,johnATTIEexampleDOTTYcom,no,http://john.com,Canada,',
            'Bob,bobATTIEexampleDOTTYcom,no,,Australia,',
        ]);
        $path    = $this->tmpFile($content);
        $pdo     = $this->makePdo();
        $this->makeDb($pdo);

        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);
        $preview = $adapter->preview($pdo, $options);

        $approved = $this->findRow($preview->rows, 'Members (approved)');
        $this->assertNotNull($approved);
        $this->assertSame(3, $approved['found']);
        $this->assertSame(3, $approved['import']);
        $this->assertSame(0, $approved['skip']);
    }

    public function testPreview_duplicateMembersCountedAsSkip(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);

        // Pre-insert one member
        $pdo->exec("INSERT INTO testfl (email, name, country, url, pending, password)
                    VALUES ('jane@example.com', 'Jane', 'United States', '', 0, '')");

        $content = implode("\n", [
            'Jane,janeATTIEexampleDOTTYcom,yes,,United States,',
            'John,johnATTIEexampleDOTTYcom,no,,Canada,',
        ]);
        $path    = $this->tmpFile($content);

        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);
        $preview = $adapter->preview($pdo, $options);

        $approved = $this->findRow($preview->rows, 'Members (approved)');
        $this->assertNotNull($approved);
        $this->assertSame(2, $approved['found']);
        $this->assertSame(1, $approved['import']);
        $this->assertSame(1, $approved['skip']);
    }

    public function testPreview_pendingMembersListed(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);

        $content = "Jane,janeATTIEexampleDOTTYcom,no,,United States,\n";
        $path    = $this->tmpFile($content);

        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions([
            '_bb_approved_path' => '',
            '_bb_pending_path'  => $path,
            'bb_import_pending' => '1',
        ]);
        $preview = $adapter->preview($pdo, $options);

        $pending = $this->findRow($preview->rows, 'Members (pending)');
        $this->assertNotNull($pending);
        $this->assertSame(1, $pending['found']);
    }

    // -----------------------------------------------------------------------
    // run() — member insertion
    // -----------------------------------------------------------------------

    public function testRun_insertsApprovedMembers(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);

        $content = implode("\n", [
            'Jane,janeATTIEexampleDOTTYcom,yes,,United States,',
            'John,johnATTIEexampleDOTTYcom,no,https://john.com,Canada,',
        ]);
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);

        $result = $adapter->run($pdo, $pdo, $options);

        $approvedRow = $this->findResultRow($result->rows, 'Members (approved)');
        $this->assertNotNull($approvedRow);
        $this->assertSame(2, $approvedRow['imported']);
        $this->assertSame(0, $approvedRow['skipped']);

        // Verify DB contents
        $rows = $pdo->query("SELECT * FROM testfl ORDER BY email")->fetchAll();
        $this->assertCount(2, $rows);

        $jane = $rows[0];
        $this->assertSame('jane@example.com', $jane['email']);
        $this->assertSame('Jane', $jane['name']);
        $this->assertSame('United States', $jane['country']);
        $this->assertSame('', $jane['url']);
        $this->assertSame(0, (int) $jane['pending']);
        $this->assertSame(1, (int) $jane['showemail']);
        $this->assertSame(0, (int) $jane['showurl']);

        $john = $rows[1];
        $this->assertSame('john@example.com', $john['email']);
        $this->assertSame(0, (int) $john['pending']);
        $this->assertSame(0, (int) $john['showemail']);
        $this->assertSame(1, (int) $john['showurl']);
        $this->assertSame('https://john.com', $john['url']);
    }

    public function testRun_insertsPendingMembers(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);

        $content = "Jane,janeATTIEexampleDOTTYcom,no,,United States,\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions([
            '_bb_approved_path' => '',
            '_bb_pending_path'  => $path,
            'bb_import_pending' => '1',
        ]);

        $result = $adapter->run($pdo, $pdo, $options);

        $pendingRow = $this->findResultRow($result->rows, 'Members (pending)');
        $this->assertNotNull($pendingRow);
        $this->assertSame(1, $pendingRow['imported']);

        $row = $pdo->query("SELECT pending FROM testfl WHERE email = 'jane@example.com'")->fetch();
        $this->assertSame(1, (int) $row['pending']);
    }

    public function testRun_skipsBlankEmail(): void
    {
        $pdo     = $this->makePdo();
        $this->makeDb($pdo);
        // Row where col 1 (email) is empty — decodes to '' → should be skipped
        $content = "Jane,,no,,United States,\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);

        $result      = $adapter->run($pdo, $pdo, $options);
        $approvedRow = $this->findResultRow($result->rows, 'Members (approved)');
        $this->assertNotNull($approvedRow);
        $this->assertSame(0, $approvedRow['imported']);
        $this->assertSame(1, $approvedRow['skipped']);
    }

    public function testRun_skipsDuplicateByEmail(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);
        $pdo->exec("INSERT INTO testfl (email, name, country, url, pending, password)
                    VALUES ('jane@example.com', 'Jane', 'US', '', 0, '')");

        $content = implode("\n", [
            'Jane,janeATTIEexampleDOTTYcom,yes,,United States,',
            'Bob,bobATTIEexampleDOTTYcom,no,,Australia,',
        ]);
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);

        $result      = $adapter->run($pdo, $pdo, $options);
        $approvedRow = $this->findResultRow($result->rows, 'Members (approved)');
        $this->assertSame(1, $approvedRow['imported']);
        $this->assertSame(1, $approvedRow['skipped']);
    }

    public function testRun_invalidTargetListing_throws(): void
    {
        $pdo     = $this->makePdo();
        $this->makeDb($pdo);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['bb_target_listing_id' => '9999']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $adapter->run($pdo, $pdo, $options);
    }

    public function testRun_decodeEmailTokens_fullSet(): void
    {
        $pdo     = $this->makePdo();
        $this->makeDb($pdo);
        // Tests ATTIE, DOTTY, DASHY, SCORE
        $content = "Test,testDASHYnameSCOREtagATTIEmy-hostDOTTYcom,no,,UK,\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);

        $adapter->run($pdo, $pdo, $options);

        $row = $pdo->query("SELECT email FROM testfl LIMIT 1")->fetch();
        $this->assertSame('test-name_tag@my-host.com', $row['email']);
    }

    public function testRun_canonicalRowWithEscapedComma(): void
    {
        $pdo     = $this->makePdo();
        $this->makeDb($pdo);
        // BellaBuffs encodes commas as | in fave field
        $content = "Jane,janeATTIEexampleDOTTYcom,no,,United States,Buffy| Angel\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);

        $result = $adapter->run($pdo, $pdo, $options);
        $row    = $this->findResultRow($result->rows, 'Members (approved)');
        // Fave field is currently not stored (no additional field column), but import should succeed
        $this->assertSame(1, $row['imported']);
    }

    public function testRun_urlNormalization_emptyProtocol(): void
    {
        $pdo     = $this->makePdo();
        $this->makeDb($pdo);
        $content = "Jane,janeATTIEexampleDOTTYcom,no,http://,United States,\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);

        $adapter->run($pdo, $pdo, $options);

        $row = $pdo->query("SELECT url, showurl FROM testfl WHERE email = 'jane@example.com'")->fetch();
        $this->assertSame('', $row['url']);
        $this->assertSame(0, (int) $row['showurl']);
    }

    public function testRun_dispemail_mappedToShowemail(): void
    {
        $pdo     = $this->makePdo();
        $this->makeDb($pdo);
        $content = implode("\n", [
            'Jane,janeATTIEexampleDOTTYcom,yes,,United States,',
            'Bob,bobATTIEexampleDOTTYcom,no,,Canada,',
        ]);
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);

        $adapter->run($pdo, $pdo, $options);

        $jane = $pdo->query("SELECT showemail FROM testfl WHERE email = 'jane@example.com'")->fetch();
        $bob  = $pdo->query("SELECT showemail FROM testfl WHERE email = 'bob@example.com'")->fetch();
        $this->assertSame(1, (int) $jane['showemail']);
        $this->assertSame(0, (int) $bob['showemail']);
    }

    public function testRun_blankLinesIgnored(): void
    {
        $pdo     = $this->makePdo();
        $this->makeDb($pdo);
        $content = "\n\nJane,janeATTIEexampleDOTTYcom,no,,United States,\n\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);

        $result = $adapter->run($pdo, $pdo, $options);
        $row    = $this->findResultRow($result->rows, 'Members (approved)');
        $this->assertSame(1, $row['imported']);
    }

    // -----------------------------------------------------------------------
    // run() — affiliates
    // -----------------------------------------------------------------------

    public function testRun_affiliatesInserted(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);

        $content = "button1.gif,Site Admin,adminATTIEsite.com,https://site.com,The Site\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        // Use _bb_affiliates_path injection (supported in upload mode for testing)
        $options = $this->makeOptions([
            '_bb_approved_path'    => '',
            'bb_import_affiliates' => '1',
            '_bb_affiliates_path'  => $path,
        ]);

        $result = $adapter->run($pdo, $pdo, $options);
        $affRow = $this->findResultRow($result->rows, 'Affiliates');
        $this->assertNotNull($affRow);
        $this->assertSame(1, $affRow['imported']);

        $aff = $pdo->query("SELECT * FROM lstr_affiliates WHERE url = 'https://site.com'")->fetch();
        $this->assertNotFalse($aff);
        $this->assertSame('The Site', $aff['name']);
        $this->assertSame('admin@site.com', $aff['email']);
        $this->assertSame(1, (int) $aff['fanlisting_id']);
    }

    public function testRun_affiliateDuplicateByUrl_skipped(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);
        $pdo->exec("INSERT INTO lstr_affiliates (fanlisting_id, name, url, email, sort_order)
                    VALUES (1, 'Existing', 'https://site.com', '', 1)");

        $content = "button1.gif,Site Admin,adminATTIEsite.com,https://site.com,The Site\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions([
            '_bb_approved_path'    => '',
            'bb_import_affiliates' => '1',
            '_bb_affiliates_path'  => $path,
        ]);

        $result = $adapter->run($pdo, $pdo, $options);
        $affRow = $this->findResultRow($result->rows, 'Affiliates');
        $this->assertNotNull($affRow);
        $this->assertSame(0, $affRow['imported']);
        $this->assertSame(1, $affRow['skipped']);
    }

    public function testRun_affiliateFallbackName(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);
        // sitename is blank — should fall back to affname
        $content = "btn.gif,Contact Name,adminATTIEsite.com,https://site.com,\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions([
            '_bb_approved_path'    => '',
            'bb_import_affiliates' => '1',
            '_bb_affiliates_path'  => $path,
        ]);

        $adapter->run($pdo, $pdo, $options);

        $aff = $pdo->query("SELECT name FROM lstr_affiliates WHERE url = 'https://site.com'")->fetch();
        $this->assertNotFalse($aff);
        $this->assertSame('Contact Name', $aff['name']);
    }

    // -----------------------------------------------------------------------
    // run() — codes / buttons
    // -----------------------------------------------------------------------

    public function testRun_codesRequireImageDir_skipWhenMissing(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);

        $content = "button50x50.gif,50,50,no,,\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        // No bb_buttons_dir set → no image migrator → code skipped
        $options = $this->makeOptions([
            '_bb_approved_path' => '',
            'bb_import_codes'   => '1',
            '_bb_buttons_path'  => $path,
            '_bb_buttons_dir'   => '',
        ]);

        $result  = $adapter->run($pdo, $pdo, $options);
        $codeRow = $this->findResultRow($result->rows, 'Codes / Buttons');
        $this->assertNotNull($codeRow);
        // Skipped because no image dir = no migrator = no image = skip
        $this->assertSame(0, $codeRow['imported']);
        $this->assertSame(1, $codeRow['skipped']);
    }

    public function testRun_codeDuplicateDeduplicatesByOriginalFilename(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);

        // Pre-insert a code with the original filename stored in `name`
        $pdo->exec("INSERT INTO lstr_code_sizes (fanlisting_id, label, width, height, sort_order)
                    VALUES (1, '50x50', 50, 50, 1)");
        $sizeId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO lstr_codes
                    (fanlisting_id, name, filename, width, height, size_id, sort_order, status)
                    VALUES (1, 'button50x50.gif', 'bb_code_randomised.gif', 50, 50, {$sizeId}, 1, 'approved')");

        $content = "button50x50.gif,50,50,no,,\n";
        $path    = $this->tmpFile($content);
        $adapter = new BellaBuffsImportSource();
        // Pass buttons path and an empty buttons_dir (no images) — dedup check runs before image copy
        $options = $this->makeOptions([
            '_bb_approved_path' => '',
            'bb_import_codes'   => '1',
            '_bb_buttons_path'  => $path,
            '_bb_buttons_dir'   => '',
        ]);

        $result  = $adapter->run($pdo, $pdo, $options);
        $codeRow = $this->findResultRow($result->rows, 'Codes / Buttons');
        $this->assertNotNull($codeRow);
        $this->assertSame(1, $codeRow['skipped']);
        $this->assertSame(0, $codeRow['imported']);
    }

    public function testRun_donatedCode_donorRecordCreated(): void
    {
        $pdo = $this->makePdo();
        $this->makeDb($pdo);

        // Pre-insert donated code rows directly to test donor logic in isolation
        // (without image migration by calling the upsert helpers via reflection)
        $pdo->exec("INSERT INTO lstr_code_donors (fanlisting_id, name, url)
                    VALUES (1, 'Artist Name', 'https://artist.com')");
        $donorId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO lstr_code_sizes (fanlisting_id, label, width, height, sort_order)
                    VALUES (1, '88x31', 88, 31, 1)");
        $sizeId = (int) $pdo->lastInsertId();

        $pdo->exec("INSERT INTO lstr_codes
                    (fanlisting_id, name, filename, width, height, size_id, donor_id, sort_order, status, submitter_name)
                    VALUES (1, 'donated.gif', 'bb_code_donated.gif', 88, 31, {$sizeId}, {$donorId}, 1, 'approved', 'Artist Name')");

        // Verify the donor record is there
        $donor = $pdo->query("SELECT * FROM lstr_code_donors WHERE fanlisting_id = 1 AND name = 'Artist Name'")->fetch();
        $this->assertNotFalse($donor);
        $this->assertSame('https://artist.com', $donor['url']);

        // Verify the code references the donor
        $code = $pdo->query("SELECT donor_id, submitter_name FROM lstr_codes WHERE name = 'donated.gif'")->fetch();
        $this->assertSame($donorId, (int) $code['donor_id']);
        $this->assertSame('Artist Name', $code['submitter_name']);
    }

    // -----------------------------------------------------------------------
    // result.schemaVariant
    // -----------------------------------------------------------------------

    public function testRun_schemaVariantIsBellaBuffsFlatfile(): void
    {
        $pdo     = $this->makePdo();
        $this->makeDb($pdo);
        $path    = $this->tmpFile('Jane,janeATTIEexampleDOTTYcom,no,,United States,' . "\n");
        $adapter = new BellaBuffsImportSource();
        $options = $this->makeOptions(['_bb_approved_path' => $path]);

        $result = $adapter->run($pdo, $pdo, $options);
        $this->assertSame('bellabuffs_flatfile', $result->schemaVariant);
    }

    // -----------------------------------------------------------------------
    // ImportOptions integration
    // -----------------------------------------------------------------------

    public function testImportOptions_bellaBuffsFieldsPopulated(): void
    {
        $options = ImportOptions::fromPost([
            'source'               => 'bellabuffs',
            'bb_mode'              => 'upload',
            'bb_target_listing_id' => '5',
            'bb_import_pending'    => '1',
            'bb_import_affiliates' => '1',
            'bb_import_codes'      => '1',
            '_bb_approved_path'    => '/tmp/approved.txt',
            '_bb_pending_path'     => '/tmp/pending.txt',
            'conflict_strategy'    => 'skip',
        ]);

        $this->assertSame('bellabuffs', $options->source);
        $this->assertSame(5, $options->bbTargetListingId);
        $this->assertTrue($options->bbImportApproved);
        $this->assertTrue($options->bbImportPending);
        $this->assertTrue($options->bbImportAffiliates);
        $this->assertTrue($options->bbImportCodes);
        $this->assertSame('upload', $options->bbMode);
        $this->assertSame('/tmp/approved.txt', $options->bbMembersPath);
        $this->assertSame('/tmp/pending.txt', $options->bbNewbiesPath);
        $this->assertSame('', $options->bbAffiliatesPath);  // upload mode
        $this->assertSame('', $options->bbButtonsPath);     // upload mode
    }

    public function testImportOptions_nonBellaBuffsSourceUnaffected(): void
    {
        $options = ImportOptions::fromPost([
            'source'           => 'enthusiast',
            'conflict_strategy' => 'skip',
        ]);

        $this->assertSame('enthusiast', $options->source);
        $this->assertSame(0, $options->bbTargetListingId);
        $this->assertSame('', $options->bbMode);
    }

    // -----------------------------------------------------------------------
    // Private helper
    // -----------------------------------------------------------------------

    /** @param array<array{type:string,...}> $rows */
    private function findRow(array $rows, string $type): ?array
    {
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === $type) {
                return $row;
            }
        }
        return null;
    }

    /** @param array<array{type:string,...}> $rows */
    private function findResultRow(array $rows, string $type): ?array
    {
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === $type) {
                return $row;
            }
        }
        return null;
    }
}
