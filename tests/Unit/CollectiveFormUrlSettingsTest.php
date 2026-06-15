<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the collective Form Page URL settings:
 *  - SiteSettingsRepo::get/set('collective_form_url_codes_submit')
 *  - SiteSettingsRepo::get/set('collective_form_url_affiliate_apply')
 *  - CollectiveFormUrlResolver::resolve(), used by CodesSubmitController::submit()
 *    (collective branch), AffiliateApplyController::collectiveFormUrl(),
 *    AffiliatesController::collective(), and the affiliates embed to resolve these
 *    overrides relative to the collective's public site URL (site_url)
 */
final class CollectiveFormUrlSettingsTest extends TestCase
{
    private const SITE_SETTINGS_DDL = <<<SQL
CREATE TABLE lstr_site_settings (
    setting_key   TEXT NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL DEFAULT ''
)
SQL;

    // -----------------------------------------------------------------------
    // SiteSettingsRepo round-trip
    // -----------------------------------------------------------------------

    public function testCollectiveFormUrlCodesSubmitReturnsNullWhenNotSet(): void
    {
        $repo = new SiteSettingsRepo(SqliteDatabase::make(self::SITE_SETTINGS_DDL));

        self::assertNull($repo->get('collective_form_url_codes_submit'));
    }

    public function testCollectiveFormUrlCodesSubmitRoundTrips(): void
    {
        $db   = SqliteDatabase::make(self::SITE_SETTINGS_DDL);
        $repo = new SiteSettingsRepo($db);
        $db->pdo()->exec(
            "INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES ('collective_form_url_codes_submit', 'donate-a-code.php')"
        );

        self::assertSame('donate-a-code.php', $repo->get('collective_form_url_codes_submit'));
    }

    public function testCollectiveFormUrlAffiliateApplyReturnsNullWhenNotSet(): void
    {
        $repo = new SiteSettingsRepo(SqliteDatabase::make(self::SITE_SETTINGS_DDL));

        self::assertNull($repo->get('collective_form_url_affiliate_apply'));
    }

    public function testCollectiveFormUrlAffiliateApplyRoundTrips(): void
    {
        $db   = SqliteDatabase::make(self::SITE_SETTINGS_DDL);
        $repo = new SiteSettingsRepo($db);
        $db->pdo()->exec(
            "INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES ('collective_form_url_affiliate_apply', 'become-an-affiliate.php')"
        );

        self::assertSame('become-an-affiliate.php', $repo->get('collective_form_url_affiliate_apply'));
    }

    // -----------------------------------------------------------------------
    // CollectiveFormUrlResolver::resolve() — generic behaviour
    // -----------------------------------------------------------------------

    public function testResolveFallsBackWhenOverrideIsNull(): void
    {
        self::assertSame(
            'https://example.com/luster/public/codes/submit/0',
            CollectiveFormUrlResolver::resolve(null, 'https://mysite.com', 'https://example.com/luster/public/codes/submit/0'),
        );
    }

    public function testResolveFallsBackWhenOverrideIsBlank(): void
    {
        self::assertSame(
            'https://example.com/affiliate-apply/collective',
            CollectiveFormUrlResolver::resolve('   ', 'https://mysite.com', 'https://example.com/affiliate-apply/collective'),
        );
    }

    public function testResolveUsesOverrideRelativeToSiteUrl(): void
    {
        self::assertSame(
            'https://mysite.com/donate-a-code.php',
            CollectiveFormUrlResolver::resolve('donate-a-code.php', 'https://mysite.com', 'https://example.com/luster/public/codes/submit/0'),
        );
    }

    public function testResolveStripsLeadingSlashFromOverrideAndTrailingSlashFromSiteUrl(): void
    {
        self::assertSame(
            'https://mysite.com/become-an-affiliate.php',
            CollectiveFormUrlResolver::resolve('/become-an-affiliate.php', 'https://mysite.com/', 'https://example.com/affiliate-apply/collective'),
        );
    }

    // -----------------------------------------------------------------------
    // CodesSubmitController::submit() collective $submitUrl logic
    // -----------------------------------------------------------------------

    public function testCodesSubmitUrlFallsBackToLusterRouteWhenUnset(): void
    {
        self::assertSame(
            'https://example.com/luster/public/codes/submit/0',
            $this->codesSubmitUrl('https://mysite.com', 'https://example.com/luster/public', null),
        );
    }

    public function testCodesSubmitUrlUsesOverrideRelativeToSiteUrl(): void
    {
        self::assertSame(
            'https://mysite.com/donate-a-code.php',
            $this->codesSubmitUrl('https://mysite.com', 'https://example.com/luster/public', 'donate-a-code.php'),
        );
    }

    public function testCodesSubmitUrlStripsLeadingSlashFromOverride(): void
    {
        self::assertSame(
            'https://mysite.com/donate-a-code.php',
            $this->codesSubmitUrl('https://mysite.com', 'https://example.com/luster/public', '/donate-a-code.php'),
        );
    }

    public function testCodesSubmitUrlTreatsBlankOverrideAsUnset(): void
    {
        self::assertSame(
            'https://example.com/luster/public/codes/submit/0',
            $this->codesSubmitUrl('https://mysite.com', 'https://example.com/luster/public', '   '),
        );
    }

    // -----------------------------------------------------------------------
    // AffiliateApplyController::collectiveFormUrl() logic
    // -----------------------------------------------------------------------

    public function testAffiliateApplyCollectiveUrlFallsBackToLusterRouteWhenUnset(): void
    {
        self::assertSame(
            'https://example.com/affiliate-apply/collective',
            $this->affiliateApplyCollectiveUrl('https://mysite.com', 'https://example.com', null),
        );
    }

    public function testAffiliateApplyCollectiveUrlUsesOverrideRelativeToSiteUrl(): void
    {
        self::assertSame(
            'https://mysite.com/become-an-affiliate.php',
            $this->affiliateApplyCollectiveUrl('https://mysite.com', 'https://example.com', 'become-an-affiliate.php'),
        );
    }

    public function testAffiliateApplyCollectiveUrlStripsLeadingSlashFromOverride(): void
    {
        self::assertSame(
            'https://mysite.com/become-an-affiliate.php',
            $this->affiliateApplyCollectiveUrl('https://mysite.com', 'https://example.com', '/become-an-affiliate.php'),
        );
    }

    public function testAffiliateApplyCollectiveUrlTreatsBlankOverrideAsUnset(): void
    {
        self::assertSame(
            'https://example.com/affiliate-apply/collective',
            $this->affiliateApplyCollectiveUrl('https://mysite.com', 'https://example.com', '   '),
        );
    }

    // -----------------------------------------------------------------------
    // Helpers — mirror controller composition of CollectiveFormUrlResolver
    // -----------------------------------------------------------------------

    /** Mirrors CodesSubmitController::submit() collective branch. */
    private function codesSubmitUrl(string $siteUrl, string $baseUrl, ?string $override): string
    {
        return CollectiveFormUrlResolver::resolve($override, $siteUrl, rtrim($baseUrl, '/') . '/codes/submit/0');
    }

    /** Mirrors AffiliateApplyController::collectiveFormUrl(). */
    private function affiliateApplyCollectiveUrl(string $siteUrl, string $embedBase, ?string $override): string
    {
        return CollectiveFormUrlResolver::resolve($override, $siteUrl, rtrim($embedBase, '/') . '/affiliate-apply/collective');
    }
}
