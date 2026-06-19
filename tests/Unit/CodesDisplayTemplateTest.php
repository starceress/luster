<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Codes Display template (Migration 64 column codes_template,
 * lstr_site_settings key collective_codes_template):
 *  - ListingSettingsRepo::defaultCodesTemplate()
 *  - ListingSettingsRepo::getCodesTemplate()
 *  - SiteSettingsRepo::defaultCollectiveCodesTemplate() / collectiveDefaults()
 *  - SiteSettingsRepo::get('collective_codes_template')
 */
final class CodesDisplayTemplateTest extends TestCase
{
    private const FANLISTING_SETTINGS_DDL = <<<SQL
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id INTEGER PRIMARY KEY,
    codes_template TEXT NULL
)
SQL;

    private const SITE_SETTINGS_DDL = <<<SQL
CREATE TABLE lstr_site_settings (
    setting_key   TEXT NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL DEFAULT ''
)
SQL;

    // -----------------------------------------------------------------------
    // ListingSettingsRepo::defaultCodesTemplate()
    // -----------------------------------------------------------------------

    public function testDefaultCodesTemplateContainsGroupedAndFlatLoopTokens(): void
    {
        $tpl = ListingSettingsRepo::defaultCodesTemplate();

        self::assertStringContainsString('{each_size_group}', $tpl);
        self::assertStringContainsString('{/each_size_group}', $tpl);
        self::assertStringContainsString('{each_code}', $tpl);
        self::assertStringContainsString('{/each_code}', $tpl);
    }

    public function testDefaultCodesTemplateContainsPageLevelConditionals(): void
    {
        $tpl = ListingSettingsRepo::defaultCodesTemplate();

        self::assertStringContainsString('{if_codes}', $tpl);
        self::assertStringContainsString('{/if_codes}', $tpl);
        self::assertStringContainsString('{if_no_codes}', $tpl);
        self::assertStringContainsString('{/if_no_codes}', $tpl);
        self::assertStringContainsString('No codes available yet.', $tpl);
    }

    public function testDefaultCodesTemplateContainsPerCodeTokens(): void
    {
        $tpl = ListingSettingsRepo::defaultCodesTemplate();

        self::assertStringContainsString('{code_url}', $tpl);
        self::assertStringContainsString('{code_name}', $tpl);
        self::assertStringContainsString('{group_size}', $tpl);
    }

    // -----------------------------------------------------------------------
    // ListingSettingsRepo::getCodesTemplate()
    // -----------------------------------------------------------------------

    public function testGetCodesTemplateReturnsNullWhenNoRow(): void
    {
        $repo = new ListingSettingsRepo(SqliteDatabase::make(self::FANLISTING_SETTINGS_DDL));

        self::assertNull($repo->getCodesTemplate(99));
    }

    public function testGetCodesTemplateReturnsNullWhenColumnNull(): void
    {
        $db   = SqliteDatabase::make(self::FANLISTING_SETTINGS_DDL);
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id, codes_template) VALUES (1, NULL)');

        self::assertNull($repo->getCodesTemplate(1));
    }

    public function testGetCodesTemplateReturnsStoredValue(): void
    {
        $db   = SqliteDatabase::make(self::FANLISTING_SETTINGS_DDL);
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, codes_template) VALUES (1, '{each_code}{code_url}{/each_code}')"
        );

        self::assertSame('{each_code}{code_url}{/each_code}', $repo->getCodesTemplate(1));
    }

    public function testGetCodesTemplateReturnsNullWhenColumnMissing(): void
    {
        $db   = SqliteDatabase::make('CREATE TABLE lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY)');
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getCodesTemplate(1));
    }

    // -----------------------------------------------------------------------
    // SiteSettingsRepo collective_codes_template
    // -----------------------------------------------------------------------

    public function testDefaultCollectiveCodesTemplateHasExpectedStructure(): void
    {
        $tpl = SiteSettingsRepo::defaultCollectiveCodesTemplate();

        self::assertNotSame('', $tpl);
        self::assertStringContainsString('fl-embed--codes', $tpl);
        self::assertStringContainsString('{each_size_group}', $tpl);
        self::assertStringContainsString('{each_code}', $tpl);
        self::assertStringContainsString('{code_url}', $tpl);
        self::assertStringNotContainsString('{listing_subject}', $tpl);
    }

    public function testCollectiveDefaultsIncludesCollectiveCodesTemplate(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('collective_codes_template', $defaults);
        self::assertSame(SiteSettingsRepo::defaultCollectiveCodesTemplate(), $defaults['collective_codes_template']);
    }

    public function testGetCollectiveCodesTemplateReturnsNullWhenNotSet(): void
    {
        $repo = new SiteSettingsRepo(SqliteDatabase::make(self::SITE_SETTINGS_DDL));

        self::assertNull($repo->get('collective_codes_template'));
    }

    public function testGetCollectiveCodesTemplateReturnsStoredValue(): void
    {
        $db   = SqliteDatabase::make(self::SITE_SETTINGS_DDL);
        $repo = new SiteSettingsRepo($db);
        $db->pdo()->exec(
            "INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES ('collective_codes_template', '{each_code}{code_url}{/each_code}')"
        );

        self::assertSame('{each_code}{code_url}{/each_code}', $repo->get('collective_codes_template'));
    }
}
