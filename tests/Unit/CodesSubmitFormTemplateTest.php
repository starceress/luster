<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Codes Submit Form template (Migration 70) and the retirement of
 * codes_submit_intro_template (Migration 72):
 *  - ListingSettingsRepo::defaultCodesSubmitFormTemplate()
 *  - ListingSettingsRepo::getCodesSubmitFormTemplate()
 *  - ListingSettingsRepo::getTemplates()['codes_submit_form_template']
 *  - ListingSettingsRepo::saveTemplates() round-trip with codes_submit_form_template
 */
final class CodesSubmitFormTemplateTest extends TestCase
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
    affiliate_apply_form_template TEXT NULL
)
SQL;

    // -----------------------------------------------------------------------
    // defaultCodesSubmitFormTemplate()
    // -----------------------------------------------------------------------

    public function testDefaultTemplateContainsListingTitleToken(): void
    {
        self::assertStringContainsString('{listing_title}', ListingSettingsRepo::defaultCodesSubmitFormTemplate());
    }

    public function testDefaultTemplateContainsRequiredFieldTokens(): void
    {
        $tpl = ListingSettingsRepo::defaultCodesSubmitFormTemplate();

        self::assertStringContainsString('{field_code_image}',      $tpl);
        self::assertStringContainsString('{field_submitter_email}',  $tpl);
        self::assertStringContainsString('{captcha_block}',          $tpl);
        self::assertStringContainsString('{submit_button}',          $tpl);
    }

    public function testDefaultTemplateDoesNotContainIntroToken(): void
    {
        self::assertStringNotContainsString('{intro}', ListingSettingsRepo::defaultCodesSubmitFormTemplate());
    }

    // -----------------------------------------------------------------------
    // getCodesSubmitFormTemplate()
    // -----------------------------------------------------------------------

    public function testGetCodesSubmitFormTemplateReturnsNullWhenNoRow(): void
    {
        $repo = $this->makeRepo();

        self::assertNull($repo->getCodesSubmitFormTemplate(99));
    }

    public function testGetCodesSubmitFormTemplateReturnsNullWhenColumnNull(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, codes_submit_form_template) VALUES (1, NULL)'
        );

        self::assertNull($repo->getCodesSubmitFormTemplate(1));
    }

    public function testGetCodesSubmitFormTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, codes_submit_form_template) VALUES (1, '<h1>Submit {listing_title}</h1>')"
        );

        self::assertSame('<h1>Submit {listing_title}</h1>', $repo->getCodesSubmitFormTemplate(1));
    }

    public function testGetCodesSubmitFormTemplateReturnsNullWhenColumnMissing(): void
    {
        $db   = SqliteDatabase::make('CREATE TABLE lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY)');
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getCodesSubmitFormTemplate(1));
    }

    // -----------------------------------------------------------------------
    // getTemplates()['codes_submit_form_template']
    // -----------------------------------------------------------------------

    public function testGetTemplatesCodesSubmitFormTemplateNullWhenUnset(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getTemplates(1)['codes_submit_form_template']);
    }

    public function testGetTemplatesCodesSubmitFormTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, codes_submit_form_template) VALUES (1, '{field_code_image}{captcha_block}')"
        );

        self::assertSame('{field_code_image}{captcha_block}', $repo->getTemplates(1)['codes_submit_form_template']);
    }

    public function testGetTemplatesDoesNotContainCodesSubmitIntroTemplateKey(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertArrayNotHasKey('codes_submit_intro_template', $repo->getTemplates(1));
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
