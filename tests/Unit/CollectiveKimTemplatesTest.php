<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\SiteSettingsRepo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the four KIM collective template defaults added in Phase C
 * (PLAN-collective-templates-selector.md):
 *   - collective_kim_list_overview_template
 *   - collective_kim_list_subject_template
 *   - kim_update_form_template
 *   - kim_reset_form_template
 */
final class CollectiveKimTemplatesTest extends TestCase
{
    // -----------------------------------------------------------------------
    // collective_kim_list_overview_template
    // -----------------------------------------------------------------------

    public function testCollectiveDefaultsContainsKimListOverviewKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('collective_kim_list_overview_template', $defaults);
    }

    public function testKimListOverviewTemplateDefaultContainsExpectedTokens(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();
        $tpl      = $defaults['collective_kim_list_overview_template'];

        self::assertNotSame('', $tpl);
        self::assertStringContainsString('{each_kim_listing}', $tpl);
        self::assertStringContainsString('{/each_kim_listing}', $tpl);
        self::assertStringContainsString('{listing_kim_url}', $tpl);
        self::assertStringContainsString('{listing_kim_count}', $tpl);
        self::assertStringContainsString('{if_no_listings}', $tpl);
        self::assertStringContainsString('{if_has_listings}', $tpl);
    }

    // -----------------------------------------------------------------------
    // collective_kim_list_subject_template
    // -----------------------------------------------------------------------

    public function testCollectiveDefaultsContainsKimListSubjectKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('collective_kim_list_subject_template', $defaults);
    }

    public function testKimListSubjectTemplateDefaultContainsExpectedTokens(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();
        $tpl      = $defaults['collective_kim_list_subject_template'];

        self::assertNotSame('', $tpl);
        self::assertStringContainsString('{each_kim_member}', $tpl);
        self::assertStringContainsString('{/each_kim_member}', $tpl);
        self::assertStringContainsString('{member_name}', $tpl);
        self::assertStringContainsString('{if_member_url}', $tpl);
        self::assertStringContainsString('{if_no_member_url}', $tpl);
        self::assertStringContainsString('{if_no_kim_members}', $tpl);
        self::assertStringContainsString('{if_has_kim_members}', $tpl);
        self::assertStringContainsString('{listing_subject}', $tpl);
    }

    // -----------------------------------------------------------------------
    // kim_update_form_template
    // -----------------------------------------------------------------------

    public function testCollectiveDefaultsContainsKimUpdateFormKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('kim_update_form_template', $defaults);
    }

    public function testKimUpdateFormTemplateDefaultContainsExpectedTokens(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();
        $tpl      = $defaults['kim_update_form_template'];

        self::assertNotSame('', $tpl);
        self::assertStringContainsString('{field_email}', $tpl);
        self::assertStringContainsString('{field_password}', $tpl);
        self::assertStringContainsString('{submit_button}', $tpl);
        self::assertStringContainsString('{if_lost_password_url}', $tpl);
        self::assertStringContainsString('{lost_password_url}', $tpl);
        // Old all-in-one fields are no longer in the default template (two-step flow)
        self::assertStringNotContainsString('{field_name}', $tpl);
        self::assertStringNotContainsString('{field_current_email}', $tpl);
        self::assertStringNotContainsString('{field_fanlisting_id}', $tpl);
    }

    // -----------------------------------------------------------------------
    // kim_reset_form_template
    // -----------------------------------------------------------------------

    public function testCollectiveDefaultsContainsKimResetFormKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('kim_reset_form_template', $defaults);
    }

    public function testKimResetFormTemplateDefaultContainsBothStepBlocks(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();
        $tpl      = $defaults['kim_reset_form_template'];

        self::assertNotSame('', $tpl);
        self::assertStringContainsString('{if_step_email}', $tpl);
        self::assertStringContainsString('{/if_step_email}', $tpl);
        self::assertStringContainsString('{if_step_confirm}', $tpl);
        self::assertStringContainsString('{/if_step_confirm}', $tpl);
        // Email step tokens — fanlisting selector and captcha removed from runtime and default
        self::assertStringContainsString('{field_email}', $tpl);
        self::assertStringNotContainsString('{field_fanlisting_id}', $tpl);
        self::assertStringNotContainsString('{captcha_block}', $tpl);
        // Confirm step tokens
        self::assertStringContainsString('{field_password}', $tpl);
        self::assertStringContainsString('{field_password_confirm}', $tpl);
        self::assertStringContainsString('{submit_button}', $tpl);
    }

    public function testKimResetFormTemplateDefaultContainsConfirmStepValidityConditionals(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();
        $tpl      = $defaults['kim_reset_form_template'];

        self::assertStringContainsString('{if_token_invalid}', $tpl);
        self::assertStringContainsString('{if_token_valid}', $tpl);
        self::assertStringNotContainsString('{hidden_token}', $tpl, 'Hidden token is auto-injected, not a user-facing template token');
        self::assertStringContainsString('{reset_request_url}', $tpl);
    }
}
