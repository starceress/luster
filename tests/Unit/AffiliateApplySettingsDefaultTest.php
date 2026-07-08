<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ListingSettingsRepo::getAffiliateApplySettings()'s default-enabled
 * behavior. Migration 60 normalized affiliate_apply_enabled to DEFAULT 1 (to
 * match codes_submit_enabled's DEFAULT 1), and every import source that sets
 * the column explicitly writes 1 — but a listing with no lstr_fanlisting_settings
 * row at all (some import paths never create one) previously fell through to a
 * hardcoded 'enabled' => false, silently leaving affiliate applications closed
 * by default while codes donations stayed open. This should default to enabled
 * everywhere a row is genuinely absent, consistent with the column's own default.
 */
final class AffiliateApplySettingsDefaultTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id                  INTEGER PRIMARY KEY,
    affiliate_apply_enabled        INTEGER NOT NULL DEFAULT 1,
    form_url_affiliate_apply       TEXT NULL,
    redirect_url_affiliate_apply   TEXT NULL
)
SQL;

    public function testDefaultsToEnabledWhenNoRowExists(): void
    {
        $repo = new ListingSettingsRepo(SqliteDatabase::make(self::DDL));

        $result = $repo->getAffiliateApplySettings(99);

        self::assertTrue($result['enabled']);
        self::assertSame('', $result['form_url']);
        self::assertSame('', $result['redirect_url']);
    }

    public function testDefaultsToEnabledWhenColumnsMissingEntirely(): void
    {
        $db   = SqliteDatabase::make('CREATE TABLE lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY)');
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');

        self::assertTrue($repo->getAffiliateApplySettings(1)['enabled']);
    }

    public function testRespectsExplicitlyStoredDisabledValue(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, affiliate_apply_enabled) VALUES (1, 0)'
        );

        self::assertFalse($repo->getAffiliateApplySettings(1)['enabled']);
    }

    public function testRespectsExplicitlyStoredEnabledValueWithUrls(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new ListingSettingsRepo($db);
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, affiliate_apply_enabled, form_url_affiliate_apply, redirect_url_affiliate_apply)
             VALUES (1, 1, 'https://example.test/apply', 'https://example.test/thanks')"
        );

        $result = $repo->getAffiliateApplySettings(1);

        self::assertTrue($result['enabled']);
        self::assertSame('https://example.test/apply', $result['form_url']);
        self::assertSame('https://example.test/thanks', $result['redirect_url']);
    }
}
