<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\SiteSettingsRepo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Wishlist and Collective Newsfeed template keys in
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
        self::assertStringContainsString('fl-wishlist-item', $defaults['wishlist_template']);
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

    public function testCollectiveDefaultsContainsCollectiveNewsfeedKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('collective_newsfeed_template', $defaults);
    }

    public function testCollectiveNewsfeedTemplateDefaultHasExpectedStructure(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertNotSame('', $defaults['collective_newsfeed_template']);
        self::assertStringContainsString('{each_newsfeed_entry}', $defaults['collective_newsfeed_template']);
        self::assertStringContainsString('{newsfeed_title}', $defaults['collective_newsfeed_template']);
        self::assertStringContainsString('{newsfeed_body_html}', $defaults['collective_newsfeed_template']);
        self::assertStringContainsString('fl-newsfeed-list', $defaults['collective_newsfeed_template']);
        self::assertStringNotContainsString('{listing_subject}', $defaults['collective_newsfeed_template']);
        self::assertSame(
            SiteSettingsRepo::defaultCollectiveNewsfeedTemplate(),
            $defaults['collective_newsfeed_template'],
        );
    }
}
