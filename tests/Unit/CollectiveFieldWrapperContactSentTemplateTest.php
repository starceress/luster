<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\FieldWrapper;
use Luster\Support\SuccessRenderer;
use Luster\Repositories\SiteSettingsRepo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Form Field Wrapper and Contact Sent Message template keys
 * in SiteSettingsRepo::collectiveDefaults().
 */
final class CollectiveFieldWrapperContactSentTemplateTest extends TestCase
{
    public function testCollectiveDefaultsContainsFieldWrapperKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('collective_field_wrapper_template', $defaults);
    }

    public function testFieldWrapperTemplateDefaultMatchesFieldWrapper(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertNotSame('', $defaults['collective_field_wrapper_template']);
        self::assertStringContainsString('{input}', $defaults['collective_field_wrapper_template']);
        self::assertStringContainsString('{label}', $defaults['collective_field_wrapper_template']);
        self::assertStringContainsString('{if_field_id}', $defaults['collective_field_wrapper_template']);
        self::assertStringContainsString('{if_hint}', $defaults['collective_field_wrapper_template']);
        self::assertStringContainsString('{if_error}', $defaults['collective_field_wrapper_template']);
        self::assertSame(
            FieldWrapper::defaultTemplate(),
            $defaults['collective_field_wrapper_template'],
        );
    }

    public function testCollectiveDefaultsContainsContactSentKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('collective_contact_sent_template', $defaults);
    }

    public function testContactSentTemplateDefaultMatchesSuccessRenderer(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertNotSame('', $defaults['collective_contact_sent_template']);
        self::assertStringContainsString('fl-success', $defaults['collective_contact_sent_template']);
        self::assertSame(
            SuccessRenderer::defaultTemplate('contact-sent'),
            $defaults['collective_contact_sent_template'],
        );
    }
}
