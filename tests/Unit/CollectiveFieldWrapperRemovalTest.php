<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\FieldWrapper;
use Luster\Repositories\SiteSettingsRepo;
use PHPUnit\Framework\TestCase;

/**
 * Confirms that collective_field_wrapper_template is NOT present in
 * collectiveDefaults(), and that FieldWrapper still renders correctly with
 * its built-in default when no custom template is set.
 */
final class CollectiveFieldWrapperRemovalTest extends TestCase
{
    public function testCollectiveDefaultsDoesNotContainFieldWrapperKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayNotHasKey('collective_field_wrapper_template', $defaults);
    }

    public function testFieldWrapperBuiltInDefaultRendersCorrectlyWhenNoCustomTemplateProvided(): void
    {
        $wrapper = new FieldWrapper();
        $html    = $wrapper->render(
            '',
            '<input type="email" id="contact-email">',
            'Email',
            'contact-email',
            true,
        );

        self::assertStringContainsString('class="fl-field"', $html);
        self::assertStringContainsString('class="fl-label"', $html);
        self::assertStringContainsString('for="contact-email"', $html);
        self::assertStringContainsString('<input type="email" id="contact-email">', $html);
        self::assertStringContainsString('class="fl-required"', $html);
    }

    public function testFieldWrapperBuiltInDefaultRendersOptionalFieldWithNoAsterisk(): void
    {
        $wrapper = new FieldWrapper();
        $html    = $wrapper->render('', '<input type="url" id="contact-url">', 'Website URL', 'contact-url', false);

        self::assertStringContainsString('Website URL</label>', $html);
        self::assertStringNotContainsString('Website URL*', $html);
    }
}
