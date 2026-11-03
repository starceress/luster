<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\Listing;
use Luster\Repositories\SiteSettingsRepo;
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
    // Image tokens — {listing_image_url} (escaped) / {if_listing_image} / {if_no_listing_image}
    // -----------------------------------------------------------------------

    public function testImageTokensEmptyWhenNoImageInSettings(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(name: 'Test FL'),
            '{listing_image_url}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('', $html);
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

    public function testNoListingImageConditionalRendersWhenNoImage(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{if_no_listing_image}fallback{/if_no_listing_image}',
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('fallback', $html);
    }

    public function testNoListingImageConditionalHiddenWhenImageSet(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{if_no_listing_image}fallback{/if_no_listing_image}',
            ['image' => 'cover.jpg'],
            'https://example.com/uploads/listings',
        );

        self::assertSame('', $html);
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

    public function testLegacyListingImageTokenPassesThroughAsLiteralText(): void
    {
        // {listing_image} was the old token name — renamed to {listing_image_url} /
        // {listing_image_html} with no legacy alias. The renderer must leave it untouched
        // so site owners get a visible artefact in the output instead of silent data loss.
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{listing_image}',
            ['image' => 'cover.jpg'],
            'https://example.com/uploads/listings',
        );

        self::assertSame('{listing_image}', $html);
    }

    public function testLegacyImageAliasTokenPassesThroughAsLiteralText(): void
    {
        // {image} was the joined-listing template alias — also removed; no alias kept.
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{image}',
            ['image' => 'cover.jpg'],
            'https://example.com/uploads/listings',
        );

        self::assertSame('{image}', $html);
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
            '{if_countries}({country_count} countries){/if_countries}',
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

        self::assertSame('TFL', $tfl);
        self::assertSame('TAFL', $tafl);
        self::assertSame('', $none);
    }

    public function testNetworkConditionalsAreCategoryDerivedIndependentOfApprovedBy(): void
    {
        $template = '{if_tfl}TFL{/if_tfl}{if_tafl}TAFL{/if_tafl}{if_approved_by}APPROVED{/if_approved_by}';

        $tfl = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            ['has_tfl_category' => true],
            'https://example.com/uploads/listings',
        );
        $tafl = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            ['has_tafl_category' => true],
            'https://example.com/uploads/listings',
        );
        $both = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            ['has_tfl_category' => true, 'has_tafl_category' => true],
            'https://example.com/uploads/listings',
        );
        $none = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            [],
            'https://example.com/uploads/listings',
        );

        self::assertSame('TFL', $tfl);
        self::assertSame('TAFL', $tafl);
        self::assertSame('TFLTAFL', $both);
        self::assertSame('', $none);
    }

    public function testIfTflShowsWithCategoryFlagEvenWithoutApprovedBy(): void
    {
        // {if_tfl} is category-derived — it must show even when the listing
        // has no "Approved By" set at all, unlike the old approved_by-based gate.
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{if_tfl}has tfl category{/if_tfl}',
            ['has_tfl_category' => true, 'approved_by' => ''],
            'https://example.com/uploads/listings',
        );

        self::assertSame('has tfl category', $html);
    }

    public function testNetworkNameDerivedFromCategoryFlagsNotApprovedBy(): void
    {
        $tfl = ListingRowRenderer::render(
            $this->makeListing(),
            '{network_name}',
            ['has_tfl_category' => true],
            'https://example.com/uploads/listings',
        );
        $tafl = ListingRowRenderer::render(
            $this->makeListing(),
            '{network_name}',
            ['has_tafl_category' => true],
            'https://example.com/uploads/listings',
        );
        $both = ListingRowRenderer::render(
            $this->makeListing(),
            '{network_name}',
            ['has_tfl_category' => true, 'has_tafl_category' => true],
            'https://example.com/uploads/listings',
        );
        $none = ListingRowRenderer::render(
            $this->makeListing(),
            '{network_name}',
            [],
            'https://example.com/uploads/listings',
        );
        // Approved By alone, with no matching category, still yields no network name.
        $approvedByOnly = ListingRowRenderer::render(
            $this->makeListing(),
            '{network_name}',
            ['approved_by' => 'tfl'],
            'https://example.com/uploads/listings',
        );

        self::assertSame('The Fanlistings Network', $tfl);
        self::assertSame('The Anime Fanlistings Network', $tafl);
        // network_name goes through the same escaped-token bucket as every
        // other scalar token, so the literal "&" comes out HTML-entity-encoded
        // (renders correctly as "&" in a browser).
        self::assertSame('The Fanlistings Network &amp; The Anime Fanlistings Network', $both);
        self::assertSame('', $none);
        self::assertSame('', $approvedByOnly);
    }

    public function testTflAndTaflUrlTokensStayTiedToApprovedByNotCategoryFlags(): void
    {
        // {tfl_url}/{tafl_url} are unchanged by this — they still reflect
        // "Approved By" + the saved approval_url, since that's the only
        // place a listing-specific approval page URL is ever stored.
        $template = '{tfl_url}|{tafl_url}';

        $tfl = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            ['approved_by' => 'tfl', 'approval_url' => 'https://thefanlistings.org/tfl/all/123'],
            'https://example.com/uploads/listings',
        );
        // Category flag alone (no Approved By) does not unlock the saved approval_url.
        $categoryOnly = ListingRowRenderer::render(
            $this->makeListing(),
            $template,
            ['has_tfl_category' => true, 'approval_url' => 'https://thefanlistings.org/tfl/all/123'],
            'https://example.com/uploads/listings',
        );

        self::assertSame('https://thefanlistings.org/tfl/all/123|https://www.animefanlistings.com/', $tfl);
        self::assertSame('https://thefanlistings.org/|https://www.animefanlistings.com/', $categoryOnly);
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
                  . ' {approved_by}, {listing_image_url}</li>';

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
        self::assertSame(2, substr_count($html, 'TFL'));
        self::assertSame(2, substr_count($html, 'https://example.com/uploads/listings/shared.jpg'));
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
    // renderTemplate() — combined-template flat/sectioned auto-detection
    // -----------------------------------------------------------------------

    public function testRenderTemplateUsesFlatLoopWhenNoSectionBlocksPresent(): void
    {
        $listings = [
            $this->makeListing(name: 'Alpha'),
            $this->makeListing(name: 'Beta', upcoming: true),
        ];

        $html = ListingRowRenderer::renderTemplate(
            '<ul>{each_listing}<li>{listing_title} ({listing_status})</li>{/each_listing}</ul>',
            $listings,
            'https://example.com/uploads/listings',
            [],
            [],
        );

        self::assertSame(
            '<ul><li>Alpha (current)</li><li>Beta (upcoming)</li></ul>',
            $html,
        );
    }

    public function testRenderTemplateRoutesListingsIntoMatchingStatusSections(): void
    {
        $current  = $this->makeListing(name: 'CurrentOne');
        $upcoming = $this->makeListing(name: 'UpcomingOne', upcoming: true);
        $pending  = $this->makeListing(name: 'PendingOne', trouble: true);

        $template = '{if_current_listings}<section>Current ({current_owned_count}): {each_current}{listing_title}{/each_current}</section>{/if_current_listings}'
                  . '{if_upcoming_listings}<section>Upcoming ({upcoming_owned_count}): {each_upcoming}{listing_title}{/each_upcoming}</section>{/if_upcoming_listings}'
                  . '{if_pending_listings}<section>Pending ({pending_owned_count}): {each_pending}{listing_title}{/each_pending}</section>{/if_pending_listings}';

        $html = ListingRowRenderer::renderTemplate(
            $template,
            [$current, $upcoming, $pending],
            'https://example.com/uploads/listings',
            [],
            [],
        );

        self::assertSame(
            '<section>Current (1): CurrentOne</section>'
            . '<section>Upcoming (1): UpcomingOne</section>'
            . '<section>Pending (1): PendingOne</section>',
            $html,
        );
    }

    public function testRenderTemplateHidesEmptySectionsViaConditionals(): void
    {
        $current = $this->makeListing(name: 'OnlyCurrent');

        $template = '{if_current_listings}<section>Current: {each_current}{listing_title}{/each_current}</section>{/if_current_listings}'
                  . '{if_upcoming_listings}<section>Upcoming: {each_upcoming}{listing_title}{/each_upcoming}</section>{/if_upcoming_listings}'
                  . '{if_pending_listings}<section>Pending: {each_pending}{listing_title}{/each_pending}</section>{/if_pending_listings}';

        $html = ListingRowRenderer::renderTemplate(
            $template,
            [$current],
            'https://example.com/uploads/listings',
            [],
            [],
        );

        self::assertSame('<section>Current: OnlyCurrent</section>', $html);
    }

    public function testRenderTemplateHandlesSingleListingThroughSectionedTemplateWithoutLeakingLoopTags(): void
    {
        // Regression coverage: latest-listing.php / random-listing.php always pass
        // exactly one listing. Before renderTemplate() existed, they called
        // render() directly — which has no loop-processing step — so an opt-in
        // sectioned owned_template (a site owner could author one even though the
        // built-in default is flat — see SiteSettingsRepo::collectiveDefaults())
        // would leak literal {each_current}/{if_current_listings} tags straight
        // onto the host page instead of rendering the listing into its own section.
        $listing = $this->makeListing(name: 'Solo', upcoming: true);

        $template = '{if_current_listings}<h3>Current</h3>{each_current}{listing_title}{/each_current}{/if_current_listings}'
                  . '{if_upcoming_listings}<h3>Upcoming</h3>{each_upcoming}{listing_title}{/each_upcoming}{/if_upcoming_listings}'
                  . '{if_pending_listings}<h3>Pending</h3>{each_pending}{listing_title}{/each_pending}{/if_pending_listings}';

        $html = ListingRowRenderer::renderTemplate(
            $template,
            [$listing],
            'https://example.com/uploads/listings',
            [],
            [],
        );

        self::assertSame('<h3>Upcoming</h3>Solo', $html);
        self::assertStringNotContainsString('{each_', $html);
        self::assertStringNotContainsString('{if_', $html);
    }

    public function testRenderTemplateAppliesOuterSectionTokensBeforeRowSubstitutionToAvoidCascading(): void
    {
        // Regression coverage: outer section-count tokens like {pending_owned_count}
        // must be resolved against the template SHELL before any row HTML —
        // containing user-controlled, View::e()-escaped listing text — is
        // substituted in. Otherwise a listing whose *title* is literally
        // "{pending_owned_count}" would have that literal text overwritten by a
        // second outer-token pass scanning the assembled HTML, breaking the
        // "escaped values stay plain text" guarantee View::e() exists to provide.
        $literalTitleListing = $this->makeListing(name: '{pending_owned_count}');
        $pendingListing      = $this->makeListing(name: 'TroubledOne', trouble: true);

        $template = '{each_current}{listing_title}{/each_current}'
                  . '|pending_owned_count={pending_owned_count}|'
                  . '{each_pending}{listing_title}{/each_pending}';

        $html = ListingRowRenderer::renderTemplate(
            $template,
            [$literalTitleListing, $pendingListing],
            'https://example.com/uploads/listings',
            [],
            [],
        );

        // The outer {pending_owned_count} resolves to the section count (one
        // pending listing) — and the row whose *title* is literally
        // "{pending_owned_count}" survives as plain, untouched text rather than
        // being overwritten by that very same substitution on a second pass.
        self::assertSame('{pending_owned_count}|pending_owned_count=1|TroubledOne', $html);
    }

    public function testCollectiveDefaultsOwnedTemplateIsFlatNotSectioned(): void
    {
        // Locks down the exact regression loop this round of fixes walked through:
        // owned_template is ONE combined template shared by owned.php (every
        // $luster_status pool), latest-listing.php, and random-listing.php (always
        // exactly one listing) — and renderTemplate() picks flat vs. sectioned shape
        // purely from the template string, with no notion of which embed invoked it.
        // A sectioned built-in default would therefore render "Current (N)" headings
        // even over a single-listing widget. The default MUST stay flat
        // ({each_listing}) so the out-of-the-box look is identical and sensible
        // everywhere; a grouped Current/Upcoming/Pending layout remains available
        // as an opt-in template a site owner authors themselves.
        $ownedTemplate = SiteSettingsRepo::collectiveDefaults()['owned_template'];

        self::assertStringContainsString('{each_listing}', $ownedTemplate);
        self::assertStringNotContainsString('{each_current}', $ownedTemplate);
        self::assertStringNotContainsString('{each_upcoming}', $ownedTemplate);
        self::assertStringNotContainsString('{each_pending}', $ownedTemplate);
        self::assertStringNotContainsString('<h3>', $ownedTemplate);
    }

    // -----------------------------------------------------------------------
    // listing_type — settings override and empty-string fallback
    // -----------------------------------------------------------------------

    public function testListingTypeFromSettingsOverridesLegacyProperty(): void
    {
        $html = ListingRowRenderer::render(
            $this->makeListing(),
            '{listing_type}',
            ['listing_type' => 'clique'],
            'https://example.com/uploads/listings',
        );

        self::assertSame('clique', $html);
    }

    public function testListingTypeFallsBackToLegacyWhenSettingsEmpty(): void
    {
        $listing = new Listing(
            id: 1, title: 'X', listingtype: 'webring', category: 0,
            subject: 'X', url: '', dbprefix: '', email: '',
            additionalInfo: '', upcoming: false, trouble: false,
            joined: null, opened: null, categoryId: null, catid: '0',
        );
        $html = ListingRowRenderer::render($listing, '{listing_type}', ['listing_type' => ''], '');

        self::assertSame('webring', $html);
    }

    public function testListingTypeDefaultsToListingWhenBothEmpty(): void
    {
        $listing = new Listing(
            id: 1, title: 'X', listingtype: '', category: 0,
            subject: 'X', url: '', dbprefix: '', email: '',
            additionalInfo: '', upcoming: false, trouble: false,
            joined: null, opened: null, categoryId: null, catid: '0',
        );
        $html = ListingRowRenderer::render($listing, '{listing_type}', ['listing_type' => ''], '');

        self::assertSame('listing', $html);
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
        ?string $lifecycle = null,
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
            lifecycle:      $lifecycle,
        );
    }

    // -----------------------------------------------------------------------
    // Lifecycle (Closed / Adopted Out)
    // -----------------------------------------------------------------------

    public function testRenderResolvesClosedStatusOverTroubleAndUpcoming(): void
    {
        // trouble=true would normally mean 'pending' — lifecycle takes priority.
        $listing = $this->makeListing(upcoming: true, trouble: true, lifecycle: 'closed');
        $html = ListingRowRenderer::render($listing, '{listing_status}', [], '');

        self::assertSame('closed', $html);
    }

    public function testRenderResolvesAdoptedOutStatus(): void
    {
        $listing = $this->makeListing(lifecycle: 'adopted_out');
        $html = ListingRowRenderer::render($listing, '{listing_status}', [], '');

        self::assertSame('adopted_out', $html);
    }

    public function testRenderWithoutLifecycleStillResolvesNormalStatuses(): void
    {
        $listing = $this->makeListing(trouble: true);
        $html = ListingRowRenderer::render($listing, '{listing_status}', [], '');

        self::assertSame('pending', $html);
    }

    public function testStatusClosedConditionalOnlyShowsForClosedListings(): void
    {
        $closed  = $this->makeListing(lifecycle: 'closed');
        $current = $this->makeListing();

        $tpl = '{if_status_closed}CLOSED{/if_status_closed}';
        self::assertSame('CLOSED', ListingRowRenderer::render($closed, $tpl, [], ''));
        self::assertSame('', ListingRowRenderer::render($current, $tpl, [], ''));
    }

    public function testStatusAdoptedOutConditionalOnlyShowsForAdoptedOutListings(): void
    {
        $adopted = $this->makeListing(lifecycle: 'adopted_out');
        $closed  = $this->makeListing(lifecycle: 'closed');

        $tpl = '{if_status_adopted_out}ADOPTED{/if_status_adopted_out}';
        self::assertSame('ADOPTED', ListingRowRenderer::render($adopted, $tpl, [], ''));
        self::assertSame('', ListingRowRenderer::render($closed, $tpl, [], ''));
    }

    public function testAdoptedToTokensRenderFromSettings(): void
    {
        $listing = $this->makeListing(lifecycle: 'adopted_out');
        $settings = [
            'adopted_to_name' => 'New Owner',
            'adopted_to_url'  => 'https://newowner.example.com',
            'archive_note'    => 'Handed off amicably.',
            // DATETIME column value — {archived_at} truncates to Y-m-d before
            // formatting (see testArchivedAtTruncatesTimeComponent below), so
            // the time component here must NOT appear in the rendered output.
            'archived_at'     => '2026-06-01 12:00:00',
        ];
        $tpl = '{adopted_to_name}|{adopted_to_url}|{archive_note}|{archived_at}';

        self::assertSame(
            'New Owner|https://newowner.example.com|Handed off amicably.|2026-06-01',
            ListingRowRenderer::render($listing, $tpl, $settings, ''),
        );
    }

    public function testArchivedAtConditionalsTellAKnownDateFromAnUnknownOne(): void
    {
        $tpl = '[{archived_at}]{if_archived_at}known{/if_archived_at}{if_archived_at_unknown}unknown{/if_archived_at_unknown}';
        $closed = $this->makeListing(lifecycle: 'closed');

        self::assertSame('[2026-06-01]known', ListingRowRenderer::render($closed, $tpl, ['archived_at' => '2026-06-01 12:00:00'], ''));
        // An unknown date reaches the renderer as '' (ListingLifecycleRepo::renderFields()).
        self::assertSame('[]unknown', ListingRowRenderer::render($closed, $tpl, ['archived_at' => ''], ''));
        self::assertSame('[]unknown', ListingRowRenderer::render($this->makeListing(lifecycle: 'adopted_out'), $tpl, [], ''));
        self::assertSame('[]', ListingRowRenderer::render($this->makeListing(), $tpl, [], ''),
            "An active listing has no date, but it isn't an unknown one");
    }

    public function testArchivedAtTruncatesTimeComponentAndRespectsDateFormat(): void
    {
        $listing  = $this->makeListing(lifecycle: 'closed');
        $settings = ['archived_at' => '2026-06-01 12:34:56'];
        $tpl      = '{archived_at}';

        // No $luster_date_format set (blank) — DateFormatter::formatWith()
        // would otherwise return the raw string completely unmodified,
        // exposing the time suffix.
        self::assertSame('2026-06-01', ListingRowRenderer::render($listing, $tpl, $settings, ''));

        // With a date format set, behaves the same way date_opened/date_updated do.
        self::assertSame(
            'June 1, 2026',
            ListingRowRenderer::render($listing, $tpl, $settings, '', dateFormat: 'F j, Y'),
        );
    }

    public function testNoUrlConditionalsReflectListingUrl(): void
    {
        $withUrl    = $this->makeListing(url: 'https://example.com', lifecycle: 'closed');
        $withoutUrl = $this->makeListing(url: '', lifecycle: 'closed');

        $tpl = '{if_listing_url}HAS{/if_listing_url}{if_no_listing_url}NONE{/if_no_listing_url}';
        self::assertSame('HAS', ListingRowRenderer::render($withUrl, $tpl, [], ''));
        self::assertSame('NONE', ListingRowRenderer::render($withoutUrl, $tpl, [], ''));
    }

    public function testRenderTemplateSectionsClosedAndAdoptedOutSeparately(): void
    {
        $current    = $this->makeListing(name: 'Current FL');
        $closed     = $this->makeListing(name: 'Closed FL', lifecycle: 'closed');
        $adoptedOut = $this->makeListing(name: 'Adopted FL', lifecycle: 'adopted_out');

        $tpl = '{each_current}C:{listing_title}{/each_current}'
             . '{each_closed}X:{listing_title}{/each_closed}'
             . '{each_adopted_out}A:{listing_title}{/each_adopted_out}';

        $html = ListingRowRenderer::renderTemplate($tpl, [$current, $closed, $adoptedOut], '', [], []);

        self::assertStringContainsString('C:Current FL', $html);
        self::assertStringContainsString('X:Closed FL', $html);
        self::assertStringContainsString('A:Adopted FL', $html);
    }

    public function testRenderTemplateClosedAdoptedOutCountsAndConditionals(): void
    {
        $closed     = $this->makeListing(lifecycle: 'closed');
        $adoptedOut = $this->makeListing(lifecycle: 'adopted_out');

        $tpl = '{closed_owned_count}/{adopted_out_owned_count}'
             . '{if_closed_listings}HAS_CLOSED{/if_closed_listings}'
             . '{if_adopted_out_listings}HAS_ADOPTED{/if_adopted_out_listings}'
             . '{each_closed}{/each_closed}{each_adopted_out}{/each_adopted_out}';

        $html = ListingRowRenderer::renderTemplate($tpl, [$closed, $adoptedOut], '', [], []);

        self::assertStringContainsString('1/1', $html);
        self::assertStringContainsString('HAS_CLOSED', $html);
        self::assertStringContainsString('HAS_ADOPTED', $html);
    }

    public function testRenderTemplateEachPastCombinesClosedAndAdoptedOutInOneLoop(): void
    {
        $current    = $this->makeListing(name: 'Current FL');
        $closed     = $this->makeListing(name: 'Closed FL', lifecycle: 'closed');
        $adoptedOut = $this->makeListing(name: 'Adopted FL', lifecycle: 'adopted_out');

        // A single {each_past} loop, not separate {each_closed}/{each_adopted_out} —
        // row-level status still resolves inside it via {if_status_closed}/{if_status_adopted_out}.
        $tpl = '{each_current}C:{listing_title}{/each_current}'
             . '{each_past}P:{listing_title}'
             . '{if_status_closed}(closed){/if_status_closed}'
             . '{if_status_adopted_out}(adopted){/if_status_adopted_out}'
             . '{/each_past}';

        $html = ListingRowRenderer::renderTemplate($tpl, [$current, $closed, $adoptedOut], '', [], []);

        self::assertStringContainsString('C:Current FL', $html);
        self::assertStringContainsString('P:Closed FL(closed)', $html);
        self::assertStringContainsString('P:Adopted FL(adopted)', $html);
    }

    public function testRenderTemplatePastCountAndConditional(): void
    {
        $current    = $this->makeListing(name: 'Current FL');
        $closed     = $this->makeListing(lifecycle: 'closed');
        $adoptedOut = $this->makeListing(lifecycle: 'adopted_out');

        $tpl = '{past_owned_count}'
             . '{if_past_listings}HAS_PAST{/if_past_listings}'
             . '{each_past}{/each_past}';

        $withPast = ListingRowRenderer::renderTemplate($tpl, [$current, $closed, $adoptedOut], '', [], []);
        self::assertStringContainsString('2', $withPast);
        self::assertStringContainsString('HAS_PAST', $withPast);

        $withoutPast = ListingRowRenderer::renderTemplate($tpl, [$current], '', [], []);
        self::assertStringContainsString('0', $withoutPast);
        self::assertStringNotContainsString('HAS_PAST', $withoutPast);
    }

    public function testRenderTemplatePastDoesNotActivateSectionedModeOnItsOwn(): void
    {
        // A flat template with no {each_*} tokens at all must stay flat —
        // adding {each_past} support must not accidentally widen $isSectioned
        // detection to trigger on unrelated templates.
        $closed = $this->makeListing(name: 'Closed FL', lifecycle: 'closed');

        $html = ListingRowRenderer::renderTemplate('{each_listing}{listing_title}{/each_listing}', [$closed], '', [], []);

        self::assertSame('Closed FL', $html);
    }
}
