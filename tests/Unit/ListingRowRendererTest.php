<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\Listing;
use Luster\Support\ListingRowRenderer;
use PHPUnit\Framework\TestCase;

final class ListingRowRendererTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Status label
    // -----------------------------------------------------------------------

    public function testStatusCurrentWhenNeitherTroubleNorUpcoming(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(upcoming: false, trouble: false),
            '{listing_status}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('Current', $html);
    }

    public function testStatusUpcomingWhenUpcomingTrue(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(upcoming: true, trouble: false),
            '{listing_status}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('Upcoming', $html);
    }

    public function testStatusPendingWhenTroubleTrue(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(upcoming: false, trouble: true),
            '{listing_status}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('Pending', $html);
    }

    public function testTroubleTakesPriorityOverUpcoming(): void
    {
        // trouble wins even when upcoming is also set
        $html = ListingRowRenderer::render(
            $this->makeListing(upcoming: true, trouble: true),
            '{listing_status}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('Pending', $html);
    }

    // -----------------------------------------------------------------------
    // Category token
    // -----------------------------------------------------------------------

    public function testCategoryTokenReplacedWithProvidedName(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{listing_category}',
            [],
            'https://example.com/uploads/listings',
            'Anime & Manga',
        );

        self::assertSame('Anime &amp; Manga', $html);
    }

    public function testCategoryTokenEmptyStringWhenNoNameProvided(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{listing_category}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('', $html);
    }

    // -----------------------------------------------------------------------
    // Image token
    // -----------------------------------------------------------------------

    public function testImageTokenEmptyWhenNoImageInSettings(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(name: 'Test FL'),
            '{listing_image}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('', $html);
    }

    public function testImageTokenRendersImgTagWhenImageSet(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(name: 'My Fanlisting', subject: 'The Subject'),
            '{listing_image}',
            ['image' => 'cover.jpg'],
            'https://example.com/uploads/listings',
        );

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('src="https://example.com/uploads/listings/cover.jpg"', $html);
        self::assertStringContainsString('alt="My Fanlisting"', $html);
        self::assertStringContainsString('title="The Subject"', $html);
    }

    public function testImageTokenEscapesSpecialCharsInPath(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(name: '<script>', subject: '"xss"'),
            '{listing_image}',
            ['image' => 'a&b.jpg'],
            'https://example.com/uploads/listings',
        );

        self::assertStringContainsString('src="https://example.com/uploads/listings/a&amp;b.jpg"', $html);
        self::assertStringContainsString('alt="&lt;script&gt;"', $html);
        self::assertStringContainsString('title="&quot;xss&quot;"', $html);
    }

    // -----------------------------------------------------------------------
    // Member stat tokens
    // -----------------------------------------------------------------------

    public function testMemberCountToken(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{member_count}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 42, 'country_count' => 7],
        );

        self::assertSame('42', $html);
    }

    public function testCountryCountToken(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{country_count}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 42, 'country_count' => 7],
        );

        self::assertSame('7', $html);
    }

    public function testMemberStatsDefaultToZeroWhenOmitted(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{member_count}/{country_count}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('0/0', $html);
    }

    public function testCountriesCountAliasMatchesCountryCount(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{countries_count}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 42, 'country_count' => 7],
        );

        self::assertSame('7', $html);
    }

    public function testCountriesCountAliasPresentAlongsideOriginal(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{country_count}/{countries_count}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 0, 'country_count' => 12],
        );

        self::assertSame('12/12', $html);
    }

    public function testIfCountriesConditionalRendersWhenCountAboveZero(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{if_countries}has countries{/if_countries}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 5, 'country_count' => 3],
        );

        self::assertSame('has countries', $html);
    }

    public function testIfCountriesConditionalRemovedWhenCountIsZero(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{if_countries}has countries{/if_countries}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 5, 'country_count' => 0],
        );

        self::assertSame('', $html);
    }

    public function testIfCountriesConditionalDefaultsToRemovedWhenStatsOmitted(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{if_countries}has countries{/if_countries}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('', $html);
    }

    public function testIfCountriesConditionalCanContainTokens(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{if_countries}({countries_count} countries){/if_countries}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 10, 'country_count' => 4],
        );

        self::assertSame('(4 countries)', $html);
    }

    // -----------------------------------------------------------------------
    // Scalar token escaping
    // -----------------------------------------------------------------------

    public function testListingNameEscapedInOutput(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(name: '<b>Alert</b>'),
            '{listing_name}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('&lt;b&gt;Alert&lt;/b&gt;', $html);
    }

    public function testAllScalarTokensReplaced(): void
    {
        $listing = $this->makeListing(
            name:    'My FL',
            url:     'https://fl.example.com',
            subject: 'The Subject',
            email:   'owner@example.com',
            opened:  '2024-01-15',
        );

        $template = '{listing_name}|{listing_url}|{listing_subject}|{listing_email}'
                  . '|{date_opened}|{date_updated}|{listing_type}';

        $html = ListingRowRenderer::render(
            $listing,
            $template,
            ['last_updated' => '2024-06-01'],
            'https://example.com/uploads/listings',
        );

        self::assertSame(
            'My FL|https://fl.example.com|The Subject|owner@example.com|2024-01-15|2024-06-01|listing',
            $html,
        );
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function makeListing(
        string  $name    = 'Test Listing',
        string  $url     = 'https://example.com',
        string  $subject = 'Test Subject',
        string  $email   = 'test@example.com',
        ?string $opened  = null,
        bool    $upcoming = false,
        bool    $trouble  = false,
    ): Listing {
        return new Listing(
            id:             1,
            name:           $name,
            listingtype:    'listing',
            category:       0,
            subject:        $subject,
            url:            $url,
            dbprefix:       'test_',
            email:          $email,
            additionalInfo: '',
            upcoming:       $upcoming,
            trouble:        $trouble,
            joined:         $opened,
            opened:         $opened,
            categoryId:     null,
            catid:          '0',
        );
    }
}
