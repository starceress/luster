<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\JoinedListing;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\JoinedListingRowRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JoinedListingRowRenderer — token substitution for joined-listing
 * rows shared by joined.php, random-joined-listing.php, and
 * latest-joined-listing.php.
 */
final class JoinedListingRowRendererTest extends TestCase
{
    private const UPLOADS_URL = 'https://example.com/uploads/joined';

    // -----------------------------------------------------------------------
    // render() — scalar tokens
    // -----------------------------------------------------------------------

    public function testScalarTokensAreReplaced(): void
    {
        $listing = $this->makeJoined(
            name: 'Sailor Moon',
            url: 'https://sailormoon.example.com',
            category: 'Anime',
            dateJoined: '2024-03-01',
            status: 'approved',
        );

        $html = JoinedListingRowRenderer::render(
            $listing,
            '{listing_title}|{listing_url}|{listing_category}|{date_joined}|{listing_status}',
            self::UPLOADS_URL,
        );

        self::assertSame('Sailor Moon|https://sailormoon.example.com|Anime|2024-03-01|approved', $html);
    }

    public function testListingTitleIsEscaped(): void
    {
        $listing = $this->makeJoined(name: '<b>Alert</b>');

        $html = JoinedListingRowRenderer::render($listing, '{listing_title}', self::UPLOADS_URL);

        self::assertSame('&lt;b&gt;Alert&lt;/b&gt;', $html);
    }

    public function testDateJoinedIsEmptyStringWhenNull(): void
    {
        $listing = $this->makeJoined(dateJoined: null);

        $html = JoinedListingRowRenderer::render($listing, '{date_joined}', self::UPLOADS_URL);

        self::assertSame('', $html);
    }

    // -----------------------------------------------------------------------
    // Image tokens — {listing_image_url} / {listing_image_html} / {if_listing_image}
    // -----------------------------------------------------------------------

    public function testImageTokensAreEmptyWhenNoImage(): void
    {
        $listing = $this->makeJoined(image: null);

        $html = JoinedListingRowRenderer::render($listing, '{listing_image_url}|{listing_image_html}', self::UPLOADS_URL);

        self::assertSame('|', $html);
    }

    public function testImageUrlTokenIsBuiltFromUploadsUrl(): void
    {
        $listing = $this->makeJoined(image: 'cover.jpg');

        $html = JoinedListingRowRenderer::render($listing, '{listing_image_url}', self::UPLOADS_URL);

        self::assertSame(self::UPLOADS_URL . '/cover.jpg', $html);
    }

    public function testImageHtmlTokenRendersImgTagWhenImageSet(): void
    {
        $listing = $this->makeJoined(name: 'Sailor Moon', image: 'cover.jpg');

        $html = JoinedListingRowRenderer::render($listing, '{listing_image_html}', self::UPLOADS_URL);

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('src="' . self::UPLOADS_URL . '/cover.jpg"', $html);
        self::assertStringContainsString('alt="Sailor Moon"', $html);
        self::assertStringContainsString('title="Sailor Moon"', $html);
    }

    public function testIfListingImageConditionalOnlyRendersWhenImageSet(): void
    {
        $template = '{if_listing_image}has image{/if_listing_image}';

        $withImage    = JoinedListingRowRenderer::render($this->makeJoined(image: 'cover.jpg'), $template, self::UPLOADS_URL);
        $withoutImage = JoinedListingRowRenderer::render($this->makeJoined(image: null), $template, self::UPLOADS_URL);

        self::assertSame('has image', $withImage);
        self::assertSame('', $withoutImage);
    }

    // -----------------------------------------------------------------------
    // renderGroup() — {each_*} loop processing
    // -----------------------------------------------------------------------

    public function testRenderGroupRepeatsTemplateOncePerListing(): void
    {
        $listings = [
            $this->makeJoined(name: 'Alpha'),
            $this->makeJoined(name: 'Beta'),
            $this->makeJoined(name: 'Gamma'),
        ];

        $html = JoinedListingRowRenderer::renderGroup(
            'joined',
            '<ul>{each_joined}<li>{listing_title}</li>{/each_joined}</ul>',
            $listings,
            self::UPLOADS_URL,
        );

        self::assertSame('<ul><li>Alpha</li><li>Beta</li><li>Gamma</li></ul>', $html);
    }

    public function testRenderGroupProducesEmptyStringForEmptyList(): void
    {
        $html = JoinedListingRowRenderer::renderGroup(
            'joined',
            '{each_joined}<li>{listing_title}</li>{/each_joined}',
            [],
            self::UPLOADS_URL,
        );

        self::assertSame('', $html);
    }

    // -----------------------------------------------------------------------
    // renderTemplate() — flat vs. grouped auto-detection
    // -----------------------------------------------------------------------

    public function testRenderTemplateUsesFlatLoopWhenNoSectionBlockPresent(): void
    {
        $listings = [
            $this->makeJoined(name: 'Alpha', category: 'Anime'),
            $this->makeJoined(name: 'Beta', category: 'Music'),
        ];

        $html = JoinedListingRowRenderer::renderTemplate(
            '<ul>{each_joined}<li>{listing_title} ({listing_category})</li>{/each_joined}</ul>',
            $listings,
            self::UPLOADS_URL,
        );

        self::assertSame('<ul><li>Alpha (Anime)</li><li>Beta (Music)</li></ul>', $html);
    }

    public function testRenderTemplateRoutesIntoCurrentJoinedSection(): void
    {
        $listings = [
            $this->makeJoined(name: 'Alpha'),
            $this->makeJoined(name: 'Beta'),
        ];

        $template = '{if_current_listings}<section>Joined ({current_count}/{total_count}): '
                   . '{each_current_joined}{listing_title}{/each_current_joined}</section>{/if_current_listings}';

        $html = JoinedListingRowRenderer::renderTemplate($template, $listings, self::UPLOADS_URL);

        self::assertSame('<section>Joined (2/2): AlphaBeta</section>', $html);
    }

    public function testRenderTemplateSectionedConditionalHiddenWhenEmpty(): void
    {
        $template = '{if_current_listings}<section>{each_current_joined}{listing_title}{/each_current_joined}</section>{/if_current_listings}';

        $html = JoinedListingRowRenderer::renderTemplate($template, [], self::UPLOADS_URL);

        self::assertSame('', $html);
    }

    // -----------------------------------------------------------------------
    // collectiveDefaults() — random/latest joined listing templates
    // -----------------------------------------------------------------------

    public function testCollectiveDefaultsContainsRandomJoinedListingKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('random_joined_listing_template', $defaults);
        self::assertStringContainsString('{each_joined}', $defaults['random_joined_listing_template']);
        self::assertStringContainsString('fl-random-joined-listing', $defaults['random_joined_listing_template']);
        self::assertStringContainsString('{listing_title}', $defaults['random_joined_listing_template']);
        self::assertStringContainsString('{listing_url}', $defaults['random_joined_listing_template']);
        self::assertStringContainsString('{listing_category}', $defaults['random_joined_listing_template']);
        self::assertStringContainsString('{if_listing_image}', $defaults['random_joined_listing_template']);
    }

    public function testCollectiveDefaultsContainsLatestJoinedListingKey(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('latest_joined_listing_template', $defaults);
        self::assertStringContainsString('{each_joined}', $defaults['latest_joined_listing_template']);
        self::assertStringContainsString('fl-latest-joined-listing', $defaults['latest_joined_listing_template']);
        self::assertStringContainsString('{date_joined}', $defaults['latest_joined_listing_template']);
    }

    public function testRandomJoinedListingDefaultTemplateRendersThroughRenderTemplate(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();
        $listing  = $this->makeJoined(name: 'Sailor Moon', url: 'https://sm.example.com', category: 'Anime', image: 'cover.jpg');

        $html = JoinedListingRowRenderer::renderTemplate($defaults['random_joined_listing_template'], [$listing], self::UPLOADS_URL);

        self::assertStringContainsString('Sailor Moon', $html);
        self::assertStringContainsString('href="https://sm.example.com"', $html);
        self::assertStringContainsString('Anime', $html);
        self::assertStringContainsString('<img', $html);
        self::assertStringNotContainsString('{each_joined}', $html);
        self::assertStringNotContainsString('{if_listing_image}', $html);
    }

    public function testLatestJoinedListingDefaultTemplateRendersDateJoined(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();
        $listing  = $this->makeJoined(name: 'Sailor Moon', dateJoined: '2024-03-01');

        $html = JoinedListingRowRenderer::renderTemplate($defaults['latest_joined_listing_template'], [$listing], self::UPLOADS_URL);

        self::assertStringContainsString('Joined: 2024-03-01', $html);
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function makeJoined(
        string  $name       = 'Test Joined',
        string  $url        = 'https://example.com',
        ?string $image      = null,
        string  $category   = '',
        ?string $dateJoined = null,
        string  $status     = 'approved',
    ): JoinedListing {
        return new JoinedListing(
            id:              1,
            name:            $name,
            url:             $url,
            image:           $image,
            category:        $category,
            categoriesJson:  null,
            dateJoined:      $dateJoined,
            active:          true,
            status:          $status,
            createdAt:       '2024-01-01 00:00:00',
            linkStatus:      'unchecked',
            linkCheckedAt:   null,
            linkRedirectUrl: null,
        );
    }
}
