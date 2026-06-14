<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Wishlist and Collective Updates Feed template keys in
 * SiteSettingsRepo::collectiveDefaults().
 */
final class CollectiveWishlistUpdatesTemplateTest extends TestCase
{
    public function testCollectiveDefaultsContainsWishlistKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('wishlist_template', $defaults);
    }

    public function testWishlistTemplateDefaultIsSelfContainedAndNonEmpty(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertNotSame('', $defaults['wishlist_template']);
        self::assertStringContainsString('fl-embed--wishlist', $defaults['wishlist_template']);
        self::assertStringContainsString('{item_subject}', $defaults['wishlist_template']);
        self::assertStringContainsString('{if_has_top}', $defaults['wishlist_template']);
        self::assertStringContainsString('{each_wishlist_item_top}', $defaults['wishlist_template']);
        self::assertStringContainsString('{/each_wishlist_item_top}', $defaults['wishlist_template']);
        self::assertStringContainsString('{/if_has_top}', $defaults['wishlist_template']);
        self::assertStringContainsString('{if_has_wanted}', $defaults['wishlist_template']);
        self::assertStringContainsString('{each_wishlist_item_wanted}', $defaults['wishlist_template']);
        self::assertStringContainsString('{if_has_granted}', $defaults['wishlist_template']);
        self::assertStringContainsString('{each_wishlist_item_granted}', $defaults['wishlist_template']);
    }

    public function testCollectiveDefaultsContainsCollectiveUpdatesKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('collective_updates_template', $defaults);
    }

    public function testCollectiveUpdatesTemplateDefaultMatchesListingUpdatesTemplate(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertNotSame('', $defaults['collective_updates_template']);
        self::assertStringContainsString('{each_update}', $defaults['collective_updates_template']);
        self::assertStringContainsString('{update_title}', $defaults['collective_updates_template']);
        self::assertStringContainsString('{update_body_html}', $defaults['collective_updates_template']);
        self::assertSame(
            ListingSettingsRepo::defaultUpdatesTemplate(),
            $defaults['collective_updates_template'],
        );
    }
}
