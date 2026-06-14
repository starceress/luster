<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Join Form template (Migration 70) and the country_field_enabled
 * wiring (N1):
 *  - ListingSettingsRepo::getJoinFormTemplate()
 *  - ListingSettingsRepo::getTemplates()['join_form_template']
 *  - ListingSettingsRepo::getEmbedSettings()['country_field_enabled']
 */
final class JoinFormTemplateTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id                INTEGER PRIMARY KEY,
    stats_template                TEXT NULL,
    members_template              TEXT NULL,
    affiliates_section_template   TEXT NULL,
    updates_template              TEXT NULL,
    adoption_intro_template       TEXT NULL,
    codes_submit_form_template    TEXT NULL,
    rules_intro_template          TEXT NULL,
    join_form_template            TEXT NULL,
    affiliate_apply_form_template TEXT NULL,
    adopt_form_template           TEXT NULL,
    form_url_join                 TEXT NULL,
    form_url_update               TEXT NULL,
    form_url_reset                TEXT NULL,
    form_url_codes_submit         TEXT NULL,
    codes_submit_enabled          INTEGER NOT NULL DEFAULT 1,
    db_host                        TEXT NULL,
    db_name                        TEXT NULL,
    db_user                        TEXT NULL,
    db_pass                        TEXT NULL,
    country_field_enabled         INTEGER NULL DEFAULT NULL
)
SQL;

    // -----------------------------------------------------------------------
    // getJoinFormTemplate()
    // -----------------------------------------------------------------------

    public function testGetJoinFormTemplateReturnsNullWhenNoRow(): void
    {
        $repo = $this->makeRepo();

        self::assertNull($repo->getJoinFormTemplate(99));
    }

    public function testGetJoinFormTemplateReturnsNullWhenColumnNull(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_form_template) VALUES (1, NULL)'
        );

        self::assertNull($repo->getJoinFormTemplate(1));
    }

    public function testGetJoinFormTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_form_template) VALUES (1, '<p>Custom {field_name}</p>')"
        );

        self::assertSame('<p>Custom {field_name}</p>', $repo->getJoinFormTemplate(1));
    }

    public function testGetJoinFormTemplateReturnsNullWhenColumnMissing(): void
    {
        $db   = SqliteDatabase::make('CREATE TABLE lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY)');
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getJoinFormTemplate(1));
    }

    // -----------------------------------------------------------------------
    // getTemplates()['join_form_template']
    // -----------------------------------------------------------------------

    public function testGetTemplatesJoinFormTemplateNullWhenUnset(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getTemplates(1)['join_form_template']);
    }

    public function testGetTemplatesJoinFormTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_form_template) VALUES (1, '{field_name}{field_email}')"
        );

        self::assertSame('{field_name}{field_email}', $repo->getTemplates(1)['join_form_template']);
    }

    // -----------------------------------------------------------------------
    // getEmbedSettings()['country_field_enabled']
    // -----------------------------------------------------------------------

    public function testCountryFieldEnabledDefaultsTrueWhenNoRow(): void
    {
        $repo = $this->makeRepo();

        self::assertTrue($repo->getEmbedSettings(99)['country_field_enabled']);
    }

    public function testCountryFieldEnabledDefaultsTrueWhenColumnNull(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id, country_field_enabled) VALUES (1, NULL)');

        self::assertTrue($repo->getEmbedSettings(1)['country_field_enabled']);
    }

    public function testCountryFieldEnabledFalseWhenExplicitlyZero(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id, country_field_enabled) VALUES (1, 0)');

        self::assertFalse($repo->getEmbedSettings(1)['country_field_enabled']);
    }

    public function testCountryFieldEnabledTrueWhenExplicitlyOne(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id, country_field_enabled) VALUES (1, 1)');

        self::assertTrue($repo->getEmbedSettings(1)['country_field_enabled']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeRepo(): ListingSettingsRepo
    {
        return new ListingSettingsRepo(SqliteDatabase::make(self::DDL));
    }

    /** @return array{0: \Luster\Core\Database, 1: ListingSettingsRepo} */
    private function makeRepoWithDb(): array
    {
        $db = SqliteDatabase::make(self::DDL);
        return [$db, new ListingSettingsRepo($db)];
    }
}
