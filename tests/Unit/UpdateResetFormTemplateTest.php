<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Update Information Form and Lost Password Form templates:
 *  - ListingSettingsRepo::getUpdateFormTemplate()
 *  - ListingSettingsRepo::getResetFormTemplate()
 *  - ListingSettingsRepo::defaultUpdateFormTemplate()
 *  - ListingSettingsRepo::defaultResetFormTemplate()
 *  - ListingSettingsRepo::getTemplates()['update_form_template']
 *  - ListingSettingsRepo::getTemplates()['reset_form_template']
 */
final class UpdateResetFormTemplateTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id                INTEGER PRIMARY KEY,
    stats_template                TEXT NULL,
    members_template              TEXT NULL,
    affiliates_section_template   TEXT NULL,
    updates_template              TEXT NULL,
    codes_submit_form_template    TEXT NULL,
    rules_intro_template          TEXT NULL,
    join_form_template            TEXT NULL,
    affiliate_apply_form_template TEXT NULL,
    adopt_form_template           TEXT NULL,
    contact_form_template         TEXT NULL,
    update_form_template          TEXT NULL,
    reset_form_template           TEXT NULL
)
SQL;

    // -----------------------------------------------------------------------
    // getUpdateFormTemplate()
    // -----------------------------------------------------------------------

    public function testGetUpdateFormTemplateReturnsNullWhenNoRow(): void
    {
        $repo = $this->makeRepo();

        self::assertNull($repo->getUpdateFormTemplate(99));
    }

    public function testGetUpdateFormTemplateReturnsNullWhenColumnNull(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, update_form_template) VALUES (1, NULL)'
        );

        self::assertNull($repo->getUpdateFormTemplate(1));
    }

    public function testGetUpdateFormTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, update_form_template) VALUES (1, '{if_signed_in}hello{/if_signed_in}')"
        );

        self::assertSame('{if_signed_in}hello{/if_signed_in}', $repo->getUpdateFormTemplate(1));
    }

    public function testGetUpdateFormTemplateReturnsNullWhenColumnMissing(): void
    {
        $db   = SqliteDatabase::make('CREATE TABLE lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY)');
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getUpdateFormTemplate(1));
    }

    // -----------------------------------------------------------------------
    // getResetFormTemplate()
    // -----------------------------------------------------------------------

    public function testGetResetFormTemplateReturnsNullWhenNoRow(): void
    {
        $repo = $this->makeRepo();

        self::assertNull($repo->getResetFormTemplate(99));
    }

    public function testGetResetFormTemplateReturnsNullWhenColumnNull(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, reset_form_template) VALUES (1, NULL)'
        );

        self::assertNull($repo->getResetFormTemplate(1));
    }

    public function testGetResetFormTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, reset_form_template) VALUES (1, '{if_step_email}go{/if_step_email}')"
        );

        self::assertSame('{if_step_email}go{/if_step_email}', $repo->getResetFormTemplate(1));
    }

    public function testGetResetFormTemplateReturnsNullWhenColumnMissing(): void
    {
        $db   = SqliteDatabase::make('CREATE TABLE lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY)');
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getResetFormTemplate(1));
    }

    // -----------------------------------------------------------------------
    // defaultUpdateFormTemplate()
    // -----------------------------------------------------------------------

    public function testDefaultUpdateFormTemplateIsNonEmpty(): void
    {
        self::assertNotEmpty(ListingSettingsRepo::defaultUpdateFormTemplate());
    }

    public function testDefaultUpdateFormTemplateContainsSignedInConditionals(): void
    {
        $tpl = ListingSettingsRepo::defaultUpdateFormTemplate();

        self::assertStringContainsString('{if_not_signed_in}', $tpl);
        self::assertStringContainsString('{/if_not_signed_in}', $tpl);
        self::assertStringContainsString('{if_signed_in}', $tpl);
        self::assertStringContainsString('{/if_signed_in}', $tpl);
    }

    public function testDefaultUpdateFormTemplateContainsSignInTokens(): void
    {
        $tpl = ListingSettingsRepo::defaultUpdateFormTemplate();

        self::assertStringContainsString('{field_signin_email}', $tpl);
        self::assertStringContainsString('{field_signin_password}', $tpl);
        self::assertStringContainsString('{submit_button}', $tpl);
    }

    public function testDefaultUpdateFormTemplateContainsUpdateTokens(): void
    {
        $tpl = ListingSettingsRepo::defaultUpdateFormTemplate();

        self::assertStringContainsString('{field_new_name}', $tpl);
        self::assertStringContainsString('{field_new_email}', $tpl);
        self::assertStringContainsString('{additional_fields}', $tpl);
        self::assertStringContainsString('{field_remove}', $tpl);
        self::assertStringNotContainsString('{captcha_block}', $tpl, 'Update form no longer uses captcha (password-protected)');
    }

    // -----------------------------------------------------------------------
    // defaultResetFormTemplate()
    // -----------------------------------------------------------------------

    public function testDefaultResetFormTemplateIsNonEmpty(): void
    {
        self::assertNotEmpty(ListingSettingsRepo::defaultResetFormTemplate());
    }

    public function testDefaultResetFormTemplateContainsStepConditionals(): void
    {
        $tpl = ListingSettingsRepo::defaultResetFormTemplate();

        self::assertStringContainsString('{if_step_email}', $tpl);
        self::assertStringContainsString('{/if_step_email}', $tpl);
        self::assertStringContainsString('{if_step_confirm}', $tpl);
        self::assertStringContainsString('{/if_step_confirm}', $tpl);
    }

    public function testDefaultResetFormTemplateContainsTokenValidConditionals(): void
    {
        $tpl = ListingSettingsRepo::defaultResetFormTemplate();

        self::assertStringContainsString('{if_token_valid}', $tpl);
        self::assertStringContainsString('{/if_token_valid}', $tpl);
        self::assertStringContainsString('{if_token_invalid}', $tpl);
        self::assertStringContainsString('{/if_token_invalid}', $tpl);
    }

    public function testDefaultResetFormTemplateContainsEmailStepTokens(): void
    {
        $tpl = ListingSettingsRepo::defaultResetFormTemplate();

        self::assertStringContainsString('{field_email}', $tpl);
        self::assertStringNotContainsString('{captcha_block}', $tpl, 'Reset form no longer uses captcha (rate-limited)');
        self::assertStringContainsString('{submit_button}', $tpl);
    }

    public function testDefaultResetFormTemplateContainsConfirmStepTokens(): void
    {
        $tpl = ListingSettingsRepo::defaultResetFormTemplate();

        self::assertStringContainsString('{field_new_password}', $tpl);
        self::assertStringContainsString('{field_new_password_confirm}', $tpl);
        self::assertStringNotContainsString('{hidden_token}', $tpl, 'Hidden token is auto-injected, not a user-facing template token');
        self::assertStringContainsString('{reset_request_url}', $tpl);
    }

    // -----------------------------------------------------------------------
    // getTemplates() includes new columns
    // -----------------------------------------------------------------------

    public function testGetTemplatesUpdateFormTemplateNullWhenUnset(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getTemplates(1)['update_form_template']);
    }

    public function testGetTemplatesResetFormTemplateNullWhenUnset(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getTemplates(1)['reset_form_template']);
    }

    public function testGetTemplatesUpdateFormTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, update_form_template) VALUES (1, 'custom update')"
        );

        self::assertSame('custom update', $repo->getTemplates(1)['update_form_template']);
    }

    public function testGetTemplatesResetFormTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, reset_form_template) VALUES (1, 'custom reset')"
        );

        self::assertSame('custom reset', $repo->getTemplates(1)['reset_form_template']);
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
