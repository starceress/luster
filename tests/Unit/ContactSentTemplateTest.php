<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Support\SuccessRenderer;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the per-listing Contact Sent Confirmation template:
 *  - ListingSettingsRepo::defaultContactSentTemplate()
 *  - ListingSettingsRepo::getContactSentTemplate()
 *  - ListingSettingsRepo::getTemplates()['contact_sent_template']
 */
final class ContactSentTemplateTest extends TestCase
{
    private const DDL_FULL = <<<SQL
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
    contact_sent_template         TEXT NULL,
    update_form_template          TEXT NULL,
    reset_form_template           TEXT NULL
)
SQL;

    private const DDL_NO_CONTACT_SENT = <<<SQL
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

    private const DDL_MINIMAL = <<<SQL
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id INTEGER PRIMARY KEY,
    stats_template TEXT NULL,
    members_template TEXT NULL
)
SQL;

    // -----------------------------------------------------------------------
    // defaultContactSentTemplate()
    // -----------------------------------------------------------------------

    public function testDefaultContactSentTemplateIsNonEmpty(): void
    {
        self::assertNotSame('', ListingSettingsRepo::defaultContactSentTemplate());
    }

    public function testDefaultContactSentTemplateDelegatesToSuccessRenderer(): void
    {
        self::assertStringContainsString('fl-success', ListingSettingsRepo::defaultContactSentTemplate());
        self::assertStringContainsString('{sender_name}', ListingSettingsRepo::defaultContactSentTemplate());
    }

    // -----------------------------------------------------------------------
    // getContactSentTemplate()
    // -----------------------------------------------------------------------

    public function testGetContactSentTemplateReturnsNullWhenNoRow(): void
    {
        $repo = $this->makeRepo(self::DDL_FULL);

        self::assertNull($repo->getContactSentTemplate(99));
    }

    public function testGetContactSentTemplateReturnsNullWhenColumnNull(): void
    {
        [$db, $repo] = $this->makeRepoWithDb(self::DDL_FULL);
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, contact_sent_template) VALUES (1, NULL)'
        );

        self::assertNull($repo->getContactSentTemplate(1));
    }

    public function testGetContactSentTemplateReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb(self::DDL_FULL);
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, contact_sent_template) VALUES (1, '<div>Thanks!</div>')"
        );

        self::assertSame('<div>Thanks!</div>', $repo->getContactSentTemplate(1));
    }

    public function testGetContactSentTemplateReturnsNullWhenColumnMissing(): void
    {
        [$db, $repo] = $this->makeRepoWithDb(self::DDL_MINIMAL);
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getContactSentTemplate(1));
    }

    // -----------------------------------------------------------------------
    // getTemplates()['contact_sent_template']
    // -----------------------------------------------------------------------

    public function testGetTemplatesContactSentNullWhenUnset(): void
    {
        [$db, $repo] = $this->makeRepoWithDb(self::DDL_FULL);
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertNull($repo->getTemplates(1)['contact_sent_template']);
    }

    public function testGetTemplatesContactSentReturnsStoredValue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb(self::DDL_FULL);
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, contact_sent_template) VALUES (1, 'custom sent')"
        );

        self::assertSame('custom sent', $repo->getTemplates(1)['contact_sent_template']);
    }

    public function testGetTemplatesContactSentNullWhenColumnMissing(): void
    {
        [$db, $repo] = $this->makeRepoWithDb(self::DDL_NO_CONTACT_SENT);
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, contact_form_template) VALUES (1, 'form tpl')"
        );

        self::assertNull($repo->getTemplates(1)['contact_sent_template']);
    }

    public function testGetTemplatesStillReturnsOtherColumnsWhenContactSentMissing(): void
    {
        [$db, $repo] = $this->makeRepoWithDb(self::DDL_NO_CONTACT_SENT);
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, update_form_template, reset_form_template)
             VALUES (1, 'upd', 'rst')"
        );
        $tpls = $repo->getTemplates(1);

        self::assertSame('upd', $tpls['update_form_template']);
        self::assertSame('rst', $tpls['reset_form_template']);
        self::assertNull($tpls['contact_sent_template']);
    }

    // -----------------------------------------------------------------------
    // saveTemplates() parameter wiring (verified via direct read after SQL insert)
    //
    // saveTemplates() uses ON DUPLICATE KEY UPDATE (MySQL-only) so it cannot be
    // round-trip tested against SQLite. These tests verify the read side: that
    // contact_sent_template stored by any means comes back correctly via the
    // same getTemplates() path that saveTemplates() writes to.
    // -----------------------------------------------------------------------

    public function testGetTemplatesReturnsContactSentTemplateAlongsideOtherColumns(): void
    {
        [$db, $repo] = $this->makeRepoWithDb(self::DDL_FULL);
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings
               (fanlisting_id, contact_sent_template, update_form_template, reset_form_template)
             VALUES (1, '<div>Thanks!</div>', 'upd', 'rst')"
        );

        $tpls = $repo->getTemplates(1);
        self::assertSame('<div>Thanks!</div>', $tpls['contact_sent_template']);
        self::assertSame('upd', $tpls['update_form_template']);
        self::assertSame('rst', $tpls['reset_form_template']);
    }

    public function testGetTemplatesContactSentNullDoesNotBlockOtherColumns(): void
    {
        [$db, $repo] = $this->makeRepoWithDb(self::DDL_FULL);
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings
               (fanlisting_id, contact_sent_template, contact_form_template)
             VALUES (1, NULL, 'form tpl')"
        );

        $tpls = $repo->getTemplates(1);
        self::assertNull($tpls['contact_sent_template']);
        self::assertSame('form tpl', $tpls['contact_form_template']);
    }

    /**
     * Covers the saveTemplates() fallback that writes contact_form + update_form + reset_form
     * WITHOUT contact_sent_template (the "DDL_NO_CONTACT_SENT" schema shape).  saveTemplates()
     * cannot be tested with SQLite (ON DUPLICATE KEY UPDATE), so this verifies the read side:
     * all three columns survive a round-trip through getTemplates() on that schema.
     */
    public function testGetTemplatesReadsContactFormUpdateResetWhenNoContactSentColumn(): void
    {
        [$db, $repo] = $this->makeRepoWithDb(self::DDL_NO_CONTACT_SENT);
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings
               (fanlisting_id, contact_form_template, update_form_template, reset_form_template)
             VALUES (1, 'contact form', 'update form', 'reset form')"
        );

        $tpls = $repo->getTemplates(1);
        self::assertSame('contact form', $tpls['contact_form_template']);
        self::assertSame('update form', $tpls['update_form_template']);
        self::assertSame('reset form', $tpls['reset_form_template']);
        self::assertNull($tpls['contact_sent_template']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeRepo(string $ddl): ListingSettingsRepo
    {
        return new ListingSettingsRepo(SqliteDatabase::make($ddl));
    }

    /** @return array{0: \Luster\Core\Database, 1: ListingSettingsRepo} */
    private function makeRepoWithDb(string $ddl): array
    {
        $db = SqliteDatabase::make($ddl);
        return [$db, new ListingSettingsRepo($db)];
    }
}
