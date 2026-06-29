<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\SiteSettingsRepo;
use Luster\Tests\Unit\Support\InMemorySettings;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the global listing form URL settings stored in lstr_site_settings.
 * Covers all six GLOBAL_FORM_URL_KEYS: round-trip set/get, null for blanks,
 * and the getGlobalFormUrls() bulk accessor.
 */
final class GlobalFormUrlSettingsTest extends TestCase
{
    private const SITE_SETTINGS_DDL = <<<SQL
CREATE TABLE lstr_site_settings (
    setting_key   TEXT NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL DEFAULT ''
)
SQL;

    // -----------------------------------------------------------------------
    // Individual key round-trips
    // -----------------------------------------------------------------------

    public function testAllSixKeysReturnNullWhenNotSet(): void
    {
        $repo = new SiteSettingsRepo(SqliteDatabase::make(self::SITE_SETTINGS_DDL));

        foreach (SiteSettingsRepo::GLOBAL_FORM_URL_KEYS as $key => $_default) {
            self::assertNull($repo->get($key), "Expected null for unset key: {$key}");
        }
    }

    public function testGetReturnsStoredValueForEachKey(): void
    {
        $db   = SqliteDatabase::make(self::SITE_SETTINGS_DDL);
        $repo = new SiteSettingsRepo($db);

        $values = [
            'global_form_url_join'            => 'my-join.php',
            'global_form_url_update'          => 'my-update.php',
            'global_form_url_reset'           => 'my-reset.php',
            'global_form_url_affiliate_apply' => 'my-affiliates.php',
            'global_form_url_codes_submit'    => 'my-codes.php',
            'global_form_url_contact'         => 'my-contact.php',
        ];

        foreach ($values as $key => $val) {
            $db->pdo()->exec(
                "INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES ('{$key}', '{$val}')"
            );
        }

        foreach ($values as $key => $expected) {
            self::assertSame($expected, $repo->get($key), "Round-trip failed for: {$key}");
        }
    }

    public function testGetReturnsNullAfterRowDeleted(): void
    {
        $db   = SqliteDatabase::make(self::SITE_SETTINGS_DDL);
        $repo = new SiteSettingsRepo($db);

        $db->pdo()->exec(
            "INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES ('global_form_url_join', 'custom-join.php')"
        );
        self::assertSame('custom-join.php', $repo->get('global_form_url_join'));

        $db->pdo()->exec("DELETE FROM lstr_site_settings WHERE setting_key = 'global_form_url_join'");
        self::assertNull($repo->get('global_form_url_join'));
    }

    // -----------------------------------------------------------------------
    // getGlobalFormUrls() bulk accessor
    // -----------------------------------------------------------------------

    public function testGetGlobalFormUrlsReturnsAllSixKeys(): void
    {
        $repo = new SiteSettingsRepo(SqliteDatabase::make(self::SITE_SETTINGS_DDL));
        $urls = $repo->getGlobalFormUrls();

        self::assertCount(6, $urls);
        foreach (SiteSettingsRepo::GLOBAL_FORM_URL_KEYS as $key => $_default) {
            self::assertArrayHasKey($key, $urls, "Missing key in getGlobalFormUrls(): {$key}");
        }
    }

    public function testGetGlobalFormUrlsReturnsNullForUnsetKeys(): void
    {
        $repo = new SiteSettingsRepo(SqliteDatabase::make(self::SITE_SETTINGS_DDL));
        $urls = $repo->getGlobalFormUrls();

        foreach ($urls as $key => $value) {
            self::assertNull($value, "Expected null for unset key: {$key}");
        }
    }

    public function testGetGlobalFormUrlsReturnsStoredValues(): void
    {
        $db   = SqliteDatabase::make(self::SITE_SETTINGS_DDL);
        $repo = new SiteSettingsRepo($db);

        $db->pdo()->exec("INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES ('global_form_url_join', 'join-page.php')");
        $db->pdo()->exec("INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES ('global_form_url_contact', 'contact-me.php')");

        $urls = $repo->getGlobalFormUrls();

        self::assertSame('join-page.php', $urls['global_form_url_join']);
        self::assertSame('contact-me.php', $urls['global_form_url_contact']);
        self::assertNull($urls['global_form_url_update']);
        self::assertNull($urls['global_form_url_reset']);
        self::assertNull($urls['global_form_url_affiliate_apply']);
        self::assertNull($urls['global_form_url_codes_submit']);
    }

    // -----------------------------------------------------------------------
    // Controller save-path simulation (via InMemorySettings::set)
    // Mirrors ListingController::saveSettings() lines 3094–3100
    // -----------------------------------------------------------------------

    public function testControllerSavePathStoresNonBlankValues(): void
    {
        $repo = new InMemorySettings();
        $post = [
            'global_form_url_join'            => 'my-join.php',
            'global_form_url_update'          => 'custom-update.php',
            'global_form_url_reset'           => '',
            'global_form_url_affiliate_apply' => '  ',
            'global_form_url_codes_submit'    => 'donate.php',
            'global_form_url_contact'         => '',
        ];

        foreach (SiteSettingsRepo::GLOBAL_FORM_URL_KEYS as $key => $_default) {
            $submitted = ltrim(trim((string) ($post[$key] ?? '')), '/');
            $repo->set($key, $submitted !== '' ? $submitted : null);
        }

        self::assertSame('my-join.php', $repo->get('global_form_url_join'));
        self::assertSame('custom-update.php', $repo->get('global_form_url_update'));
        self::assertNull($repo->get('global_form_url_reset'));
        self::assertNull($repo->get('global_form_url_affiliate_apply'));
        self::assertSame('donate.php', $repo->get('global_form_url_codes_submit'));
        self::assertNull($repo->get('global_form_url_contact'));
    }

    public function testControllerSavePathStripsLeadingSlash(): void
    {
        $repo = new InMemorySettings();

        $submitted = ltrim(trim('/my-join.php'), '/');
        $repo->set('global_form_url_join', $submitted !== '' ? $submitted : null);

        self::assertSame('my-join.php', $repo->get('global_form_url_join'));
    }

    public function testControllerSavePathClearsValueWhenBlank(): void
    {
        $repo = new InMemorySettings();
        $repo->set('global_form_url_join', 'old-join.php');
        self::assertSame('old-join.php', $repo->get('global_form_url_join'));

        $submitted = ltrim(trim(''), '/');
        $repo->set('global_form_url_join', $submitted !== '' ? $submitted : null);

        self::assertNull($repo->get('global_form_url_join'));
    }

    // -----------------------------------------------------------------------
    // Default keys constant
    // -----------------------------------------------------------------------

    public function testGlobalFormUrlKeysHasExpectedDefaults(): void
    {
        $keys = SiteSettingsRepo::GLOBAL_FORM_URL_KEYS;

        self::assertSame('join.php', $keys['global_form_url_join']);
        self::assertSame('update.php', $keys['global_form_url_update']);
        self::assertSame('reset.php', $keys['global_form_url_reset']);
        self::assertSame('affiliates.php', $keys['global_form_url_affiliate_apply']);
        self::assertSame('codes-submit.php', $keys['global_form_url_codes_submit']);
        self::assertSame('contact.php', $keys['global_form_url_contact']);
    }
}
