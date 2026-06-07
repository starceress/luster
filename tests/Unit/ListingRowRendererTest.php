<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\Listing;
use Luster\Support\ListingRowRenderer;
use PHPUnit\Framework\TestCase;

final class ListingRowRendererTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Status token + status conditionals
    // -----------------------------------------------------------------------

    public function testStatusCurrentWhenNeitherTroubleNorUpcoming(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(upcoming: false, trouble: false),
            '{listing_status}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('current', $html);
    }

    public function testStatusUpcomingWhenUpcomingTrue(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(upcoming: true, trouble: false),
            '{listing_status}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('upcoming', $html);
    }

    public function testStatusPendingWhenTroubleTrue(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(upcoming: false, trouble: true),
            '{listing_status}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('pending', $html);
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

        self::assertSame('pending', $html);
    }

    public function testIfStatusConditionalsMatchCurrentStatus(): void
    {
        $template = '{if_status_current}C{/if_status_current}'
                  . '{if_status_upcoming}U{/if_status_upcoming}'
                  . '{if_status_pending}P{/if_status_pending}';

        $current = ListingRowRenderer::render(
            $this->makeListing(upcoming: false, trouble: false),
            $template,
            [],
            'https://example.com/uploads/listings',
        );
        $upcoming = ListingRowRenderer::render(
            $this->makeListing(upcoming: true, trouble: false),
            $template,
            [],
            'https://example.com/uploads/listings',
        );
        $pending = ListingRowRenderer::render(
            $this->makeListing(upcoming: false, trouble: true),
            $template,
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('C', $current);
        self::assertSame('U', $upcoming);
        self::assertSame('P', $pending);
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
    // Image tokens — {listing_image_url} (escaped) / {listing_image_html} (raw) /
    // {if_listing_image} — replace the old {listing_image} token outright (no alias)
    // -----------------------------------------------------------------------

    public function testImageTokensEmptyWhenNoImageInSettings(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(name: 'Test FL'),
            '{listing_image_url}|{listing_image_html}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('|', $html);
    }

    public function testImageUrlTokenIsEscapedBareUrl(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{listing_image_url}',
            ['image' => 'a&b.jpg'],
            'https://example.com/uploads/listings',
        );

        self::assertSame('https://example.com/uploads/listings/a&amp;b.jpg', $html);
    }

    public function testImageHtmlTokenRendersImgTagWhenImageSet(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(name: 'My Fanlisting', subject: 'The Subject'),
            '{listing_image_html}',
            ['image' => 'cover.jpg'],
            'https://example.com/uploads/listings',
        );

        self::assertStringContainsString('<img', $html);
        self::assertStringContainsString('src="https://example.com/uploads/listings/cover.jpg"', $html);
        self::assertStringContainsString('alt="My Fanlisting"', $html);
        self::assertStringContainsString('title="The Subject"', $html);
    }

    public function testImageHtmlTokenEscapesSpecialCharsInAttributes(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(name: '<script>', subject: '"xss"'),
            '{listing_image_html}',
            ['image' => 'a&b.jpg'],
            'https://example.com/uploads/listings',
        );

        self::assertStringContainsString('src="https://example.com/uploads/listings/a&amp;b.jpg"', $html);
        self::assertStringContainsString('alt="&lt;script&gt;"', $html);
        self::assertStringContainsString('title="&quot;xss&quot;"', $html);
    }

    public function testIfListingImageConditionalRendersOnlyWhenImageSet(): void
    {
        $template = '{if_listing_image}has image{/if_listing_image}';

        $withImage = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            ['image' => 'cover.jpg'],
            'https://example.com/uploads/listings',
        );
        $withoutImage = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('has image', $withImage);
        self::assertSame('', $withoutImage);
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
            ['approved' => 42, 'pending' => 3, 'country_count' => 7],
        );

        self::assertSame('42', $html);
    }

    public function testMemberPendingCountToken(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{member_pending_count}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 42, 'pending' => 3, 'country_count' => 7],
        );

        self::assertSame('3', $html);
    }

    public function testRowLevelPendingCountAliasMatchesMemberPendingCount(): void
    {
        // {pending_count} at the row level is a deliberate alias for
        // {member_pending_count} — the section-level {pending_count} token
        // (used in {each_current}/{each_upcoming}/{each_pending} sectioned
        // templates) is applied separately, after every loop body has been
        // fully resolved to plain numeric strings, so there is no collision.
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{pending_count}/{member_pending_count}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 0, 'pending' => 5, 'country_count' => 0],
        );

        self::assertSame('5/5', $html);
    }

    public function testCountryCountToken(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{country_count}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 42, 'pending' => 0, 'country_count' => 7],
        );

        self::assertSame('7', $html);
    }

    public function testMemberStatsDefaultToZeroWhenOmitted(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{member_count}/{member_pending_count}/{country_count}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('0/0/0', $html);
    }

    public function testCountriesCountAliasMatchesCountryCount(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{countries_count}',
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 42, 'pending' => 0, 'country_count' => 7],
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
            ['approved' => 0, 'pending' => 0, 'country_count' => 12],
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
            ['approved' => 5, 'pending' => 0, 'country_count' => 3],
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
            ['approved' => 5, 'pending' => 0, 'country_count' => 0],
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
            ['approved' => 10, 'pending' => 0, 'country_count' => 4],
        );

        self::assertSame('(4 countries)', $html);
    }

    public function testIfPendingConditionalRendersOnlyWhenPendingAboveZero(): void
    {
        $template = '{if_pending}has pending{/if_pending}';

        $withPending = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 0, 'pending' => 1, 'country_count' => 0],
        );
        $withoutPending = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            [],
            'https://example.com/uploads/listings',
            '',
            ['approved' => 0, 'pending' => 0, 'country_count' => 0],
        );

        self::assertSame('has pending', $withPending);
        self::assertSame('', $withoutPending);
    }

    // -----------------------------------------------------------------------
    // approved_by token + network conditionals
    // -----------------------------------------------------------------------

    public function testApprovedByTokenOutputsRawEnumValue(): void
    {
        $tfl = ListingRowRenderer::render(
            $this->makeListing(),
            '{approved_by}',
            ['approved_by' => 'tfl'],
            'https://example.com/uploads/listings',
        );
        $tafl = ListingRowRenderer::render(
            $this->makeListing(),
            '{approved_by}',
            ['approved_by' => 'tafl'],
            'https://example.com/uploads/listings',
        );
        $none = ListingRowRenderer::render(
            $this->makeListing(),
            '{approved_by}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('tfl', $tfl);
        self::assertSame('tafl', $tafl);
        self::assertSame('', $none);
    }

    public function testNetworkConditionalsMatchApprovedBy(): void
    {
        $template = '{if_tfl}TFL{/if_tfl}{if_tafl}TAFL{/if_tafl}{if_approved}APPROVED{/if_approved}';

        $tfl = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            ['approved_by' => 'tfl'],
            'https://example.com/uploads/listings',
        );
        $tafl = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            ['approved_by' => 'tafl'],
            'https://example.com/uploads/listings',
        );
        $none = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('TFLAPPROVED', $tfl);
        self::assertSame('TAFLAPPROVED', $tafl);
        self::assertSame('', $none);
    }

    // -----------------------------------------------------------------------
    // Scalar token escaping
    // -----------------------------------------------------------------------

    public function testListingNameEscapedInOutput(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(name: '<b>Alert</b>'),
            '{listing_title}',
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

        $template = '{listing_title}|{listing_url}|{listing_subject}|{listing_email}'
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
    // renderGroup() — {each_*} loop processing
    // -----------------------------------------------------------------------

    public function testRenderGroupRepeatsTemplateOncePerListing(): void
    {
        $listings = [
            $this->makeListing(name: 'Alpha'),
            $this->makeListing(name: 'Beta'),
            $this->makeListing(name: 'Gamma'),
        ];

        $html = ListingRowRenderer::renderGroup(
            'listing',
            '<ul>{each_listing}<li>{listing_title}</li>{/each_listing}</ul>',
            $listings,
            'https://example.com/uploads/listings',
            [],
            [],
        );

        self::assertSame('<ul><li>Alpha</li><li>Beta</li><li>Gamma</li></ul>', $html);
    }

    public function testRenderGroupOnlyTouchesItsOwnNamedLoopBlock(): void
    {
        $current = [$this->makeListing(name: 'CurrentOne')];

        $template = '{each_current}<li>{listing_title}</li>{/each_current}'
                  . '{each_upcoming}<li>{listing_title}</li>{/each_upcoming}';

        $html = ListingRowRenderer::renderGroup(
            'current',
            $template,
            $current,
            'https://example.com/uploads/listings',
            [],
            [],
        );

        self::assertSame(
            '<li>CurrentOne</li>{each_upcoming}<li>{listing_title}</li>{/each_upcoming}',
            $html,
        );
    }

    public function testRenderGroupProducesEmptyStringForEmptyGroup(): void
    {
        $html = ListingRowRenderer::renderGroup(
            'pending',
            '{each_pending}<li>{listing_title}</li>{/each_pending}',
            [],
            'https://example.com/uploads/listings',
            [],
            [],
        );

        self::assertSame('', $html);
    }

    public function testRenderGroupPassesPerListingSettingsStatsAndCategoryMaps(): void
    {
        $listingA = $this->makeListing(name: 'Listing A');
        $listingB = $this->makeListing(name: 'Listing B');

        // Force distinct ids via reflection-free approach: makeListing always
        // returns id=1, so use category/stats maps keyed by that shared id to
        // confirm the maps are consulted (both rows draw from the same entry).
        $settingsMap = [1 => ['image' => 'shared.jpg', 'approved_by' => 'tfl']];
        $statsMap    = [1 => ['approved' => 9, 'pending' => 2, 'country_count' => 4]];
        $categoryMap = [1 => 'Games'];

        $template = '<li>{listing_title}: {listing_category} — {member_count} members'
                  . ' (+{member_pending_count} pending), {country_count} countries,'
                  . ' {approved_by}, {listing_image_html}</li>';

        $html = ListingRowRenderer::renderGroup(
            'listing',
            '{each_listing}' . $template . '{/each_listing}',
            [$listingA, $listingB],
            'https://example.com/uploads/listings',
            $settingsMap,
            $statsMap,
            $categoryMap,
        );

        self::assertSame(2, substr_count($html, 'Games'));
        self::assertSame(2, substr_count($html, '9 members'));
        self::assertSame(2, substr_count($html, '+2 pending'));
        self::assertSame(2, substr_count($html, '4 countries'));
        self::assertSame(2, substr_count($html, 'tfl'));
        self::assertSame(2, substr_count($html, 'src="https://example.com/uploads/listings/shared.jpg"'));
        self::assertStringContainsString('Listing A:', $html);
        self::assertStringContainsString('Listing B:', $html);
    }

    public function testRenderGroupDefaultsToEmptyStatsWhenListingMissingFromMaps(): void
    {
        $listing = $this->makeListing(name: 'Solo');

        $html = ListingRowRenderer::renderGroup(
            'listing',
            '{each_listing}{member_count}/{member_pending_count}/{country_count}/{listing_category}/{approved_by}/{listing_image_url}{/each_listing}',
            [$listing],
            'https://example.com/uploads/listings',
            [],
            [],
            [],
        );

        self::assertSame('0/0/0///', $html);
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
            title:          $name,
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
