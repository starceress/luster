<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\SiteSettingsRepo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Collective Codes Donation Form template key in SiteSettingsRepo::collectiveDefaults().
 */
final class CollectiveCodesSubmitFormTemplateTest extends TestCase
{
    public function testCollectiveDefaultsContainsCollectiveCodesSubmitFormKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('collective_codes_submit_form_template', $defaults);
    }

    public function testCollectiveCodesSubmitFormTemplateDefaultIsNonEmpty(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertNotSame('', $defaults['collective_codes_submit_form_template']);
        self::assertStringContainsString('{site_name}', $defaults['collective_codes_submit_form_template']);
        self::assertStringContainsString('{field_code_image}', $defaults['collective_codes_submit_form_template']);
        self::assertStringContainsString('{field_submitter_name}', $defaults['collective_codes_submit_form_template']);
        self::assertStringContainsString('{field_submitter_email}', $defaults['collective_codes_submit_form_template']);
        self::assertStringContainsString('{field_submitter_url}', $defaults['collective_codes_submit_form_template']);
        self::assertStringContainsString('{captcha_block}', $defaults['collective_codes_submit_form_template']);
        self::assertStringContainsString('{submit_button}', $defaults['collective_codes_submit_form_template']);
        self::assertStringContainsString('{clear_button}', $defaults['collective_codes_submit_form_template']);
    }
}
