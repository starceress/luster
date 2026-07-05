<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Adopt Form template:
 *  - ListingSettingsRepo::defaultAdoptFormTemplate()
 *  - ListingSettingsRepo::getAdoptFormTemplate()
 *  - ListingSettingsRepo::getTemplates()['adopt_form_template']
 */
final class AdoptFormTemplateTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id                INTEGER PRIMARY KEY,
    stats_template                TEXT NULL,
    members_template              TEXT NULL,
    affiliates_section_template   TEXT NULL,
    newsfeed_template             TEXT NULL,
    adoption_intro_template       TEXT NULL,
    codes_submit_form_template    TEXT NULL,
    join_form_template            TEXT NULL,
    affiliate_apply_form_template TEXT NULL,
    adopt_form_template           TEXT NULL
)
SQL;

    // -----------------------------------------------------------------------
    // defaultAdoptFormTemplate()
    // -----------------------------------------------------------------------

    public function testDefaultTemplateIsNonEmpty(): void
    {
        self::assertNotSame('', ListingSettingsRepo::defaultAdoptFormTemplate());
    }

    public function testDefaultTemplateContainsRequiredFieldTokens(): void
    {
        $tpl = ListingSettingsRepo::defaultAdoptFormTemplate();

        self::assertStringContainsString('{field_name}',    $tpl);
        self::assertStringContainsString('{field_email}',   $tpl);
        self::assertStringContainsString('{field_message}', $tpl);
        self::assertStringContainsString('{captcha_block}', $tpl);
        self::assertStringContainsString('{submit_button}', $tpl);
        self::assertStringContainsString('{clear_button}',  $tpl);
    }

    // -----------------------------------------------------------------------
    // getAdoptFormTemplate()
    // -----------------------------------------------------------------------

    public function testGetAdoptFormTemplateReturnsNullWhenNoRow(): void
    {
        $repo = new ListingSettingsRepo(SqliteDatabase::make(self::DDL));

        self::assertNull($repo->getAdoptFormTemplate(99));
    }

    public function testGetAdoptFormTemplateReturnsNullWhenColumnNull(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, adopt_form_template) VALUES (1, NULL)'
        );

        self::assertNull($repo->getAdoptFormTemplate(1));
    }

    public function testGetAdoptFormTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, adopt_form_template) VALUES (1, '<h1>Adopt {listing_title}</h1>')"
        );

        self::assertSame('<h1>Adopt {listing_title}</h1>', $repo->getAdoptFormTemplate(1));
    }

    public function testGetAdoptFormTemplateReturnsNullWhenColumnMissing(): void
    {
        $db   = SqliteDatabase::make('CREATE TABLE lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY)');
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getAdoptFormTemplate(1));
    }

    // -----------------------------------------------------------------------
    // getTemplates()['adopt_form_template']
    // -----------------------------------------------------------------------

    public function testGetTemplatesAdoptFormTemplateNullWhenUnset(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getTemplates(1)['adopt_form_template']);
    }

    public function testGetTemplatesAdoptFormTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, adopt_form_template) VALUES (1, '{field_name}{captcha_block}')"
        );

        self::assertSame('{field_name}{captcha_block}', $repo->getTemplates(1)['adopt_form_template']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array{0: \Luster\Core\Database, 1: ListingSettingsRepo} */
    private function makeRepoWithDb(): array
    {
        $db = SqliteDatabase::make(self::DDL);
        return [$db, new ListingSettingsRepo($db)];
    }
}
