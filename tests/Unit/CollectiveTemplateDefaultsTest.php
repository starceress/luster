<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\SiteSettingsRepo;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the collective affiliates/codes template defaults.
 *
 * Covers a prior bug where the built-in
 * `collective_affiliates_section_template` default hardcoded a raw
 * <a href="{affiliate_apply_url}"> link instead of using
 * {affiliate_apply_toggle}. The hardcoded link bypassed the
 * collective_affiliate_apply_enabled toggle entirely — disabling collective
 * affiliate applications in Settings did not remove the link from the
 * default template.
 */
final class CollectiveTemplateDefaultsTest extends TestCase
{
    private function collectiveAffiliatesDefault(): string
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('collective_affiliates_section_template', $defaults);

        return (string) $defaults['collective_affiliates_section_template'];
    }

    // -----------------------------------------------------------------------
    // collective_affiliates_section_template default
    // -----------------------------------------------------------------------

    public function testDefaultUsesAffiliateApplyToggleToken(): void
    {
        $tpl = $this->collectiveAffiliatesDefault();

        self::assertStringContainsString('{affiliate_apply_toggle}', $tpl);
    }

    public function testDefaultDoesNotHardcodeApplyUrlLink(): void
    {
        $tpl = $this->collectiveAffiliatesDefault();

        // The old buggy default rendered a literal <a href="{affiliate_apply_url}">
        // link that always showed regardless of the enable setting. Only the
        // {affiliate_apply_toggle} token (which respects the toggle) should remain.
        self::assertStringNotContainsString('<a href="{affiliate_apply_url}">', $tpl);
    }

    public function testDefaultStillContainsCoreAffiliateLoopTokens(): void
    {
        $tpl = $this->collectiveAffiliatesDefault();

        self::assertStringContainsString('{if_affiliates}', $tpl);
        self::assertStringContainsString('{/if_affiliates}', $tpl);
        self::assertStringContainsString('{if_no_affiliates}', $tpl);
        self::assertStringContainsString('{/if_no_affiliates}', $tpl);
        self::assertStringContainsString('{each_affiliate}', $tpl);
        self::assertStringContainsString('{/each_affiliate}', $tpl);
    }

    // -----------------------------------------------------------------------
    // collective_codes_template default
    // -----------------------------------------------------------------------

    public function testCodesDefaultUsesCodesSubmitToggleToken(): void
    {
        $tpl = SiteSettingsRepo::defaultCollectiveCodesTemplate();

        self::assertStringContainsString('{codes_submit_toggle}', $tpl);
    }
}
