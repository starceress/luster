<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the fresh installer in step with the migration runner.
 *
 * install/index.php creates every table in its final shape, then marks each
 * migration as already applied so the runner never re-runs them on a fresh
 * install. If a new migration changes a table but the installer's CREATE
 * TABLE and seed list aren't updated to match, fresh installs end up with an
 * older schema than upgraded ones. That's what happened with Migration 103:
 * the installer's lstr_member_link_checks.link_status had no 'no_url', so
 * the Link Checker's member sync failed on fresh installs.
 *
 * These checks guard the seed list and that one column. They don't prove
 * the two schemas match in general.
 */
final class InstallerMigrationSyncTest extends TestCase
{
    /**
     * Migrations the installer deliberately leaves pending, with the reason.
     * Add to this list only when a migration must still run after install.
     */
    private const NOT_SEEDED = [
        // Alters lstr_wishlist, which only exists once the Wishlist add-on is
        // installed; the runner leaves it pending until then.
        54,
    ];

    private string $runner;
    private string $installer;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);

        $runner = file_get_contents($root . '/public/migrate/index.php');
        self::assertNotFalse($runner, 'Could not read public/migrate/index.php');
        $this->runner = $runner;

        $installer = file_get_contents($root . '/install/index.php');
        self::assertNotFalse($installer, 'Could not read install/index.php');
        $this->installer = $installer;
    }

    public function testMigrationHeadingNumbersAreUnique(): void
    {
        $numbers = $this->runnerMigrationNumbers();
        $counts  = array_count_values($numbers);
        $dupes   = array_keys(array_filter($counts, static fn(int $n): bool => $n > 1));

        self::assertSame([], $dupes, 'Migration number(s) used more than once in public/migrate/index.php: ' . implode(', ', $dupes));
    }

    public function testEveryMigrationIsSeededByTheInstaller(): void
    {
        $missing = array_values(array_diff(
            $this->runnerMigrationNumbers(),
            $this->installerSeededNumbers(),
            self::NOT_SEEDED,
        ));

        self::assertSame(
            [],
            $missing,
            'Migration(s) ' . implode(', ', $missing) . ' are not in the installer\'s lstr_migrations seed list. '
            . 'Update the matching CREATE TABLE in install/index.php and add the number to the seed list.'
        );
    }

    public function testInstallerSeedsOnlyRealMigrationsOnce(): void
    {
        $seeded = $this->installerSeededNumbers();

        $unknown = array_values(array_diff($seeded, $this->runnerMigrationNumbers()));
        self::assertSame([], $unknown, 'Installer seeds migration number(s) that don\'t exist in the runner: ' . implode(', ', $unknown));

        $dupes = array_keys(array_filter(array_count_values($seeded), static fn(int $n): bool => $n > 1));
        self::assertSame([], $dupes, 'Installer seeds migration number(s) more than once: ' . implode(', ', $dupes));

        $wronglySeeded = array_values(array_intersect($seeded, self::NOT_SEEDED));
        self::assertSame([], $wronglySeeded, 'Installer seeds migration(s) that must stay pending: ' . implode(', ', $wronglySeeded));
    }

    public function testInstallerLinkStatusMatchesMigration103(): void
    {
        preg_match(
            '/CREATE TABLE IF NOT EXISTS `lstr_member_link_checks` \((.*?)\) ENGINE=/s',
            $this->installer,
            $table,
        );
        self::assertNotEmpty($table, 'Could not find the lstr_member_link_checks CREATE TABLE in install/index.php');

        preg_match('/`link_status`\s+ENUM\(([^)]*)\)/', $table[1], $installerEnum);
        self::assertNotEmpty($installerEnum, 'Could not find link_status in the installer\'s lstr_member_link_checks');

        preg_match(
            '/MODIFY COLUMN `link_status` ENUM\(([^)]*)\)/',
            $this->runner,
            $migrationEnum,
        );
        self::assertNotEmpty($migrationEnum, 'Could not find Migration 103\'s link_status ENUM in public/migrate/index.php');

        self::assertStringContainsString("'no_url'", $installerEnum[1]);
        self::assertSame(
            $migrationEnum[1],
            $installerEnum[1],
            'install/index.php lstr_member_link_checks.link_status must match the ENUM Migration 103 creates.'
        );
    }

    public function testInstallerCodesImportColumnsMatchMigration104(): void
    {
        preg_match('/CREATE TABLE IF NOT EXISTS `lstr_codes` \((.*?)\) ENGINE=/s', $this->installer, $table);
        self::assertNotEmpty($table, 'Could not find the lstr_codes CREATE TABLE in install/index.php');

        foreach (['import_ref' => 'VARCHAR(100)', 'import_source_file' => 'VARCHAR(255)'] as $column => $type) {
            $definition = "{$type} NULL DEFAULT NULL";
            // The installer lines its columns up with extra spaces; the words must match, not the spacing.
            $pattern = implode('\s+', array_map(static fn(string $w): string => preg_quote($w, '/'), explode(' ', $definition)));

            self::assertMatchesRegularExpression(
                '/`' . $column . '`\s+' . $pattern . '\s*,/',
                $table[1],
                "install/index.php lstr_codes.{$column} must be {$definition}, as Migration 104 adds it."
            );
            self::assertMatchesRegularExpression(
                '/ADD COLUMN `' . $column . '`\s+' . $pattern . '\b/',
                $this->runner,
                "Migration 104 must add lstr_codes.{$column} as {$definition}."
            );
        }

        self::assertStringContainsString('UNIQUE KEY `uq_lstr_codes_import_ref` (`import_ref`)', $table[1]);
        self::assertStringContainsString('ADD UNIQUE KEY `uq_lstr_codes_import_ref` (`import_ref`)', $this->runner);
    }

    public function testInstallerSettingsHaveNoAutoApproveColumnsAfterMigration106(): void
    {
        preg_match('/CREATE TABLE IF NOT EXISTS `lstr_fanlisting_settings` \((.*?)\) ENGINE=/s', $this->installer, $table);
        self::assertNotEmpty($table, 'Could not find the lstr_fanlisting_settings CREATE TABLE in install/index.php');

        foreach (['auto_approve_joins', 'auto_approve'] as $column) {
            self::assertStringNotContainsString(
                '`' . $column . '`',
                $table[1],
                "install/index.php must not create lstr_fanlisting_settings.{$column}; Migration 106 removes it."
            );
            self::assertStringContainsString(
                'DROP COLUMN `' . $column . '`',
                $this->runner,
                "Migration 106 must drop lstr_fanlisting_settings.{$column}."
            );
        }
    }

    /**
     * Numbers from real migration headings only. Headings always have a colon
     * after the number ("// Migration 54: ..."); other comments in the runner
     * mention older migrations in passing ("// Migration 47 dropped ...").
     *
     * @return int[]
     */
    private function runnerMigrationNumbers(): array
    {
        preg_match_all('/^\/\/ (?:--- )?Migration (\d+):/m', $this->runner, $m);
        self::assertNotEmpty($m[1], 'Found no "// Migration N:" headings in public/migrate/index.php');

        return array_map('intval', $m[1]);
    }

    /** @return int[] */
    private function installerSeededNumbers(): array
    {
        $count = preg_match_all(
            '/INSERT IGNORE INTO `lstr_migrations` \(migration_number\) VALUES(.*?)\'/s',
            $this->installer,
            $m,
        );
        self::assertSame(1, $count, 'Expected exactly one lstr_migrations seed statement in install/index.php');

        preg_match_all('/\((\d+)\)/', $m[1][0], $numbers);
        self::assertNotEmpty($numbers[1], 'The installer\'s lstr_migrations seed list is empty');

        return array_map('intval', $numbers[1]);
    }
}
