<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for Migrations 89 & 90 (public/migrate/index.php), which
 * reset `lstr_site_settings` rows back to NULL when their stored value is
 * an exact, unmodified copy of the old pre-toggle collective template
 * defaults.
 *
 * Migration 89's original release had two bugs, both fixed directly in
 * Migration 89 itself (Migration 90 exists only to retroactively repair
 * installs that already recorded the old, unsafe version of 89):
 *  1. lstr_site_settings uses utf8mb4_unicode_ci, so a plain
 *     `setting_value = :old_value` comparison is case-insensitive. A genuine
 *     customization differing only by case (or trailing whitespace under a
 *     "ci" collation) could be incorrectly matched and erased.
 *  2. It only compared against LF line endings. HTML <textarea> submissions
 *     normalize line endings to CRLF, so a truly-unmodified default saved
 *     through the Collective Templates form would NOT match an LF-only
 *     comparison and would be left stale.
 *
 * The migrate/index.php script itself is a standalone procedural file with
 * no autoloader, so it can't be required/executed directly in a unit test
 * (running it would require a real MySQL connection, login session, etc.).
 * To still genuinely guard the production code rather than a hand-copied
 * reimplementation of it, these tests do two things:
 *  - Load the OLD default template strings from
 *    public/migrate/legacy-collective-template-defaults.php — the same
 *    file Migrations 89 & 90 require() — so the "old default" used in
 *    these tests can never silently drift from what production compares
 *    against.
 *  - Read the literal source text of public/migrate/index.php and assert
 *    that both migrations' UPDATE statements use the BINARY comparison.
 *    If that SQL is ever reverted to a plain `=` comparison, this test
 *    fails immediately — independent of the SQLite simulation tests below.
 */
final class CollectiveTemplateMigrationSafetyTest extends TestCase
{
    private const MIGRATE_INDEX_PATH    = __DIR__ . '/../../public/migrate/index.php';
    private const LEGACY_DEFAULTS_PATH  = __DIR__ . '/../../public/migrate/legacy-collective-template-defaults.php';

    private string $oldAffiliatesDefaultLf;
    private string $oldCodesDefaultLf;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertFileExists(self::LEGACY_DEFAULTS_PATH);
        $defaults = require self::LEGACY_DEFAULTS_PATH;

        self::assertArrayHasKey('collective_affiliates_section_template', $defaults);
        self::assertArrayHasKey('collective_codes_template', $defaults);

        $this->oldAffiliatesDefaultLf = $defaults['collective_affiliates_section_template'];
        $this->oldCodesDefaultLf      = $defaults['collective_codes_template'];
    }

    private function makeSettingsTable(string $collateSuffix = ''): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE lstr_site_settings (
            setting_key   TEXT NOT NULL PRIMARY KEY,
            setting_value TEXT {$collateSuffix}
        )");

        return $pdo;
    }

    // -----------------------------------------------------------------------
    // Shared defaults file — sanity checks
    // -----------------------------------------------------------------------

    /**
     * The shared file must hold the OLD (pre-toggle) defaults, not the
     * current live defaults — otherwise the migrations would never match
     * any real legacy row, silently disabling the reset entirely.
     */
    public function testLegacyDefaultsFileHoldsPreToggleVersions(): void
    {
        self::assertStringNotContainsString('{affiliate_apply_toggle}', $this->oldAffiliatesDefaultLf);
        self::assertStringNotContainsString('{codes_submit_toggle}', $this->oldCodesDefaultLf);

        // And they must match the actual old, buggy markup that the toggle fix replaced.
        self::assertStringContainsString('<a href="{affiliate_apply_url}">Apply to become an affiliate?</a>', $this->oldAffiliatesDefaultLf);
    }

    // -----------------------------------------------------------------------
    // Guards the actual production SQL text
    // -----------------------------------------------------------------------

    /**
     * Reads the real migrate/index.php source and isolates the Migration 89
     * and Migration 90 blocks (each delimited by their `// --- Migration N:`
     * marker comment) so each can be asserted on independently.
     *
     * @return array{0: string, 1: string} [migration89Source, migration90Source]
     */
    private function extractMigrationBlocks(): array
    {
        self::assertFileExists(self::MIGRATE_INDEX_PATH);
        $source = (string) file_get_contents(self::MIGRATE_INDEX_PATH);

        $start89 = strpos($source, '// --- Migration 89:');
        $start90 = strpos($source, '// --- Migration 90:');
        $endMarker = strpos($source, '// Build results HTML');

        self::assertNotFalse($start89, 'Migration 89 marker comment not found in migrate/index.php — has it been renumbered or removed?');
        self::assertNotFalse($start90, 'Migration 90 marker comment not found in migrate/index.php — has it been renumbered or removed?');
        self::assertNotFalse($endMarker, 'Could not find the end-of-migrations marker in migrate/index.php.');

        $migration89Source = substr($source, $start89, $start90 - $start89);
        $migration90Source = substr($source, $start90, $endMarker - $start90);

        return [$migration89Source, $migration90Source];
    }

    public function testMigration89ProductionSqlUsesBinaryComparison(): void
    {
        [$migration89Source] = $this->extractMigrationBlocks();

        self::assertStringContainsString(
            'BINARY `setting_value` = BINARY :old_value',
            $migration89Source,
            'Migration 89 must compare setting_value using BINARY on both sides. '
            . 'If this assertion fails, the unsafe, case-insensitive comparison has been reintroduced.'
        );
    }

    public function testMigration90ProductionSqlUsesBinaryComparison(): void
    {
        [, $migration90Source] = $this->extractMigrationBlocks();

        self::assertStringContainsString(
            'BINARY `setting_value` = BINARY :old_value',
            $migration90Source,
            'Migration 90 must compare setting_value using BINARY on both sides. '
            . 'If this assertion fails, the unsafe, case-insensitive comparison has been reintroduced.'
        );
    }

    public function testMigration89AndMigration90BothLoadSharedLegacyDefaultsFile(): void
    {
        [$migration89Source, $migration90Source] = $this->extractMigrationBlocks();

        // Both migrations must source their "old default" strings from the
        // shared file rather than inlining their own copies — inlining
        // would reintroduce the exact drift risk these tests exist to catch.
        self::assertStringContainsString("require __DIR__ . '/legacy-collective-template-defaults.php'", $migration89Source);
        self::assertStringContainsString("require __DIR__ . '/legacy-collective-template-defaults.php'", $migration90Source);
    }

    public function testMigration89AndMigration90BothCheckCrlfVariant(): void
    {
        [$migration89Source, $migration90Source] = $this->extractMigrationBlocks();

        self::assertStringContainsString('str_replace("\n", "\r\n",', $migration89Source);
        self::assertStringContainsString('str_replace("\n", "\r\n",', $migration90Source);
    }

    // -----------------------------------------------------------------------
    // Bug reproduction: case-insensitive comparison incorrectly matches
    // -----------------------------------------------------------------------

    /**
     * Simulates utf8mb4_unicode_ci's case-insensitive comparison (Migration
     * 89's original bug) using SQLite's COLLATE NOCASE. A customization that
     * differs from the old default ONLY by case must NOT be treated as
     * identical — but under a case-insensitive comparison, it incorrectly is.
     */
    public function testCaseInsensitiveComparisonIncorrectlyMatchesCustomization(): void
    {
        $pdo = $this->makeSettingsTable('COLLATE NOCASE');

        $oldDefault = $this->oldAffiliatesDefaultLf;
        // Genuine customization: same text, but the admin capitalized "APPLY".
        $customized = str_replace('Apply to become', 'APPLY TO BECOME', $oldDefault);

        $pdo->prepare('INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES (:k, :v)')
            ->execute(['k' => 'collective_affiliates_section_template', 'v' => $customized]);

        // The buggy comparison style: no BINARY keyword, relies on column collation.
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM lstr_site_settings WHERE setting_key = :k AND setting_value = :old'
        );
        $stmt->execute(['k' => 'collective_affiliates_section_template', 'old' => $oldDefault]);

        self::assertSame(
            1,
            (int) $stmt->fetchColumn(),
            'Demonstrates the bug: a case-insensitive column collation makes a genuinely '
            . 'customized template (different only by case) match the old default, which '
            . 'would cause it to be incorrectly erased.'
        );
    }

    // -----------------------------------------------------------------------
    // Fix verification: binary comparison correctly distinguishes
    // -----------------------------------------------------------------------

    /**
     * The fixed comparison (BINARY column = BINARY value in MySQL, plain
     * byte comparison here since SQLite text columns are binary by default)
     * must NOT match a case-different customization.
     */
    public function testBinaryComparisonDoesNotMatchCaseDifferentCustomization(): void
    {
        $pdo = $this->makeSettingsTable(); // default SQLite TEXT — byte-for-byte comparison

        $oldDefault = $this->oldAffiliatesDefaultLf;
        $customized = str_replace('Apply to become', 'APPLY TO BECOME', $oldDefault);

        $pdo->prepare('INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES (:k, :v)')
            ->execute(['k' => 'collective_affiliates_section_template', 'v' => $customized]);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM lstr_site_settings WHERE setting_key = :k AND setting_value = :old'
        );
        $stmt->execute(['k' => 'collective_affiliates_section_template', 'old' => $oldDefault]);

        self::assertSame(
            0,
            (int) $stmt->fetchColumn(),
            'A byte-for-byte (binary) comparison must not match a customization that '
            . 'differs from the old default by case — this is the fix Migrations 89 & 90 apply.'
        );
    }

    /**
     * The fixed comparison must still correctly match (and therefore reset)
     * a row whose value is an EXACT, unmodified copy of the old default.
     */
    public function testBinaryComparisonMatchesExactUnmodifiedDefault(): void
    {
        $pdo = $this->makeSettingsTable();

        $oldDefault = $this->oldAffiliatesDefaultLf;

        $pdo->prepare('INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES (:k, :v)')
            ->execute(['k' => 'collective_affiliates_section_template', 'v' => $oldDefault]);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM lstr_site_settings WHERE setting_key = :k AND setting_value = :old'
        );
        $stmt->execute(['k' => 'collective_affiliates_section_template', 'old' => $oldDefault]);

        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // CRLF awareness
    // -----------------------------------------------------------------------

    /**
     * HTML <textarea> form submissions normalize line endings to CRLF.
     * A value saved unmodified through the Collective Templates form would
     * therefore be stored as the CRLF variant of the default, not the LF
     * variant the PHP source code literal uses. The migration must check
     * both variants.
     */
    public function testCrlfVariantOfDefaultIsDistinctFromLfVariant(): void
    {
        $lf   = $this->oldAffiliatesDefaultLf;
        $crlf = str_replace("\n", "\r\n", $lf);

        self::assertNotSame($lf, $crlf, 'Sanity check: the two variants must actually differ.');
        self::assertStringContainsString("\r\n", $crlf);
        self::assertStringNotContainsString("\r\n", $lf);
    }

    public function testLfOnlyComparisonMissesGenuinelyUnmodifiedCrlfSave(): void
    {
        $pdo = $this->makeSettingsTable();

        $lf   = $this->oldAffiliatesDefaultLf;
        $crlf = str_replace("\n", "\r\n", $lf);

        // Simulates a textarea round-trip: the admin opened the page (which
        // pre-filled the default) and clicked Save without changing anything.
        $pdo->prepare('INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES (:k, :v)')
            ->execute(['k' => 'collective_affiliates_section_template', 'v' => $crlf]);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM lstr_site_settings WHERE setting_key = :k AND setting_value = :old'
        );

        // Comparing only against the LF variant misses the row entirely —
        // this is Migration 89's original second bug.
        $stmt->execute(['k' => 'collective_affiliates_section_template', 'old' => $lf]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'LF-only comparison must miss a CRLF-saved unmodified default.');

        // Checking both variants (the fix applied to both 89 & 90) correctly finds it.
        $stmt->execute(['k' => 'collective_affiliates_section_template', 'old' => $crlf]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'CRLF variant comparison must find the unmodified default.');
    }

    // -----------------------------------------------------------------------
    // Sequencing: why Migration 89 itself had to be fixed, not just
    // followed up by Migration 90.
    // -----------------------------------------------------------------------

    /**
     * Proves the audit's core point: once a row has been incorrectly erased
     * to NULL by an unsafe comparison, a later, correctly-written migration
     * CANNOT recover it — there is nothing left to match against. This is
     * why Migration 89 had to be corrected in place (preventing the erasure
     * from happening at all on fresh installs) rather than relying solely
     * on Migration 90 to clean up after it.
     */
    public function testUnsafeMigrationFollowedBySafeMigrationCannotRecoverErasedCustomization(): void
    {
        // Case-insensitive column, mirroring utf8mb4_unicode_ci.
        $pdo = $this->makeSettingsTable('COLLATE NOCASE');

        $oldDefault = $this->oldAffiliatesDefaultLf;
        $customized = str_replace('Apply to become', 'APPLY TO BECOME', $oldDefault);

        $pdo->prepare('INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES (:k, :v)')
            ->execute(['k' => 'collective_affiliates_section_template', 'v' => $customized]);

        // Step 1 — simulate the ORIGINAL unsafe Migration 89: no BINARY keyword.
        $unsafeReset = $pdo->prepare(
            'UPDATE lstr_site_settings SET setting_value = NULL
              WHERE setting_key = :k AND setting_value = :old'
        );
        $unsafeReset->execute(['k' => 'collective_affiliates_section_template', 'old' => $oldDefault]);

        $valueAfterMigration89 = $pdo->query(
            "SELECT setting_value FROM lstr_site_settings WHERE setting_key = 'collective_affiliates_section_template'"
        )->fetchColumn();

        self::assertNull(
            $valueAfterMigration89,
            'Bug reproduction: the unsafe comparison erases the customization, exactly as the audit describes.'
        );

        // Step 2 — Migration 90 runs afterward, using CAST(...AS BLOB) to force a
        // byte-exact comparison even though the column itself is case-insensitive
        // (standing in for MySQL's BINARY keyword). It doesn't matter how correct
        // this comparison is: the row is already NULL, so there's nothing left to
        // match against — the customization cannot come back.
        $safeReset = $pdo->prepare(
            'UPDATE lstr_site_settings SET setting_value = NULL
              WHERE setting_key = :k AND CAST(setting_value AS BLOB) = CAST(:old AS BLOB)'
        );
        $safeReset->execute(['k' => 'collective_affiliates_section_template', 'old' => $oldDefault]);

        $valueAfterMigration90 = $pdo->query(
            "SELECT setting_value FROM lstr_site_settings WHERE setting_key = 'collective_affiliates_section_template'"
        )->fetchColumn();

        self::assertNull(
            $valueAfterMigration90,
            'Once erased, a later correct migration has nothing left to recover — the customization is gone. '
            . 'This is exactly why the fix had to be applied to Migration 89 itself, not just added as Migration 90.'
        );
    }

    /**
     * Confirms the actual fix: when Migration 89's comparison is corrected
     * in place (binary, not case-insensitive), the same customization that
     * was erased in the previous test now survives untouched — there is no
     * data loss for Migration 90 to ever need to clean up after on a fresh
     * install.
     */
    public function testFixedMigration89PreservesCustomizationThatWouldHaveBeenErased(): void
    {
        // Case-insensitive column, mirroring utf8mb4_unicode_ci — same setup
        // as the bug-reproduction test above.
        $pdo = $this->makeSettingsTable('COLLATE NOCASE');

        $oldDefault = $this->oldAffiliatesDefaultLf;
        $customized = str_replace('Apply to become', 'APPLY TO BECOME', $oldDefault);

        $pdo->prepare('INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES (:k, :v)')
            ->execute(['k' => 'collective_affiliates_section_template', 'v' => $customized]);

        // Corrected Migration 89: force a binary comparison even though the
        // column itself is declared case-insensitive (CAST forces a strict
        // byte comparison in SQLite, standing in for MySQL's BINARY keyword).
        $fixedReset = $pdo->prepare(
            'UPDATE lstr_site_settings SET setting_value = NULL
              WHERE setting_key = :k AND CAST(setting_value AS BLOB) = CAST(:old AS BLOB)'
        );
        $fixedReset->execute(['k' => 'collective_affiliates_section_template', 'old' => $oldDefault]);

        $value = $pdo->query(
            "SELECT setting_value FROM lstr_site_settings WHERE setting_key = 'collective_affiliates_section_template'"
        )->fetchColumn();

        self::assertSame(
            $customized,
            $value,
            'With the corrected binary comparison, the customization survives Migration 89 untouched.'
        );
    }
}
