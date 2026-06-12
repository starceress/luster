<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\SiteSettingsRepo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the KIM Sign-Up Form template key in SiteSettingsRepo::collectiveDefaults().
 */
final class KimSignupFormTemplateTest extends TestCase
{
    public function testCollectiveDefaultsContainsKimSignupFormKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('kim_signup_form_template', $defaults);
    }

    public function testKimSignupFormTemplateDefaultIsEmptyString(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertSame('', $defaults['kim_signup_form_template']);
    }
}
