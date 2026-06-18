<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\SuccessRenderer;
use Luster\Repositories\SiteSettingsRepo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Contact Sent Message template key in SiteSettingsRepo::collectiveDefaults().
 * (FieldWrapper key tests moved to CollectiveFieldWrapperRemovalTest — Phase D removed the key.)
 */
final class CollectiveFieldWrapperContactSentTemplateTest extends TestCase
{
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
