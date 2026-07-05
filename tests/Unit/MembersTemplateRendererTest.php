<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\AdditionalField;
use Luster\Models\Member;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Support\MembersTemplateRenderer;
use PHPUnit\Framework\TestCase;

final class MembersTemplateRendererTest extends TestCase
{
    // -----------------------------------------------------------------------
    // {fan_url} / {if_fan_url} respect the member's showurl preference
    // -----------------------------------------------------------------------

    public function testFanUrlIsBlankWhenMemberHasOptedOutViaShowurl(): void
    {
        $member = $this->makeMember(url: 'https://example.com/star', showurl: false);

        $html = MembersTemplateRenderer::render(
            '{each_member}[{fan_url}]{/each_member}',
            [$member],
            [],
            'https://example.com/join',
            1,
        );

        self::assertSame('[]', $html);
    }

    public function testIfFanUrlBlockIsSkippedWhenMemberHasOptedOutViaShowurl(): void
    {
        $member = $this->makeMember(name: 'Star', url: 'https://example.com/star', showurl: false);

        $html = MembersTemplateRenderer::render(
            '{each_member}{if_fan_url}<a href="{fan_url}">{fan_name}</a>{/if_fan_url}{if_no_fan_url}{fan_name}{/if_no_fan_url}{/each_member}',
            [$member],
            [],
            'https://example.com/join',
            1,
        );

        self::assertSame('Star', $html);
    }

    public function testFanUrlIsPresentWhenMemberHasNotOptedOut(): void
    {
        $member = $this->makeMember(name: 'Star', url: 'https://example.com/star', showurl: true);

        $html = MembersTemplateRenderer::render(
            '{each_member}{if_fan_url}<a href="{fan_url}">{fan_name}</a>{/if_fan_url}{/each_member}',
            [$member],
            [],
            'https://example.com/join',
            1,
        );

        self::assertSame('<a href="https://example.com/star">Star</a>', $html);
    }

    // -----------------------------------------------------------------------
    // {fan_added}
    // -----------------------------------------------------------------------

    public function testFanAddedWithBlankFormatFallsBackToOrdinalStyle(): void
    {
        // Blank format ("Automatic" in the embed wizard) must not show the raw
        // stored datetime string — it falls back to DateFormatter::format()'s
        // ordinal style, matching public/embed/stats.php's own no-override
        // default and the admin Templates-tab preview (which passes '' too).
        $member = $this->makeMember(added: '2026-11-15');

        $html = MembersTemplateRenderer::render(
            '{each_member}[{fan_added}]{/each_member}',
            [$member],
            [],
            'https://example.com/join',
            1,
        );

        self::assertSame('[November 15th 2026]', $html);
    }

    public function testFanAddedAppliesTheGivenPhpDateFormat(): void
    {
        $member = $this->makeMember(added: '2026-11-15');

        $html = MembersTemplateRenderer::render(
            '{each_member}[{fan_added}]{/each_member}',
            [$member],
            [],
            'https://example.com/join',
            1,
            dateFormat: 'F j, Y',
        );

        self::assertSame('[November 15, 2026]', $html);
    }

    public function testFanAddedIsEscaped(): void
    {
        // A malformed date string that fails strtotime() is returned as-is by
        // DateFormatter — confirm it still passes through htmlspecialchars().
        $member = $this->makeMember(added: '<script>alert(1)</script>');

        $html = MembersTemplateRenderer::render(
            '{each_member}{fan_added}{/each_member}',
            [$member],
            [],
            'https://example.com/join',
            1,
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testDefaultMembersTemplateIncludesFanAddedToken(): void
    {
        self::assertStringContainsString('{fan_added}', ListingSettingsRepo::defaultMembersTemplate());
    }

    public function testDefaultMembersTableTemplateIncludesFanAddedTokenAndJoinedHeader(): void
    {
        $tpl = ListingSettingsRepo::defaultMembersTableTemplate();
        self::assertStringContainsString('{fan_added}', $tpl);
        self::assertStringContainsString('<span class="fl-member-table-th">Joined</span>', $tpl);
    }

    public function testDefaultMembersTemplateRendersFormattedJoinDate(): void
    {
        $member = $this->makeMember(name: 'Star', added: '2026-11-15');

        $html = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTemplate(),
            [$member],
            [],
            'https://example.com/join',
            1,
            dateFormat: 'F j, Y',
            isDateSort: true,
        );

        self::assertStringContainsString('Joined <span class="fl-member-added">November 15, 2026</span>', $html);
    }

    // -----------------------------------------------------------------------
    // Built-in list default and the PHP-fragment fallback (templates/default/
    // members.php, used when nothing is stored in the DB) must look identical
    // -----------------------------------------------------------------------

    public function testDefaultMembersTemplateUsesFlMemberNameAndCountryClasses(): void
    {
        $member = $this->makeMember(name: 'Star', country: 'Canada', url: 'https://example.com/star');

        $html = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTemplate(),
            [$member],
            [],
            'https://example.com/join',
            1,
        );

        self::assertStringContainsString('<a class="fl-member-name" href="https://example.com/star">Star</a>', $html);
        self::assertStringContainsString('&mdash; <span class="fl-member-country">Canada</span>', $html);
    }

    /**
     * Locks in the exact wrapper markup/classes that templates/default/members.php's
     * PHP-fallback path (used when no template is saved) also emits, so the two
     * paths render identically. If either side's markup drifts, this test — not
     * just visual inspection — should catch it.
     */
    public function testDefaultMembersTemplateStructureMatchesThePhpFallbackFragment(): void
    {
        $member = $this->makeMember(name: 'Star', country: 'Canada');

        $html = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTemplate(),
            [$member],
            [],
            'https://example.com/join',
            1,
            '1 member(s)',
            '<strong aria-current="page" class="fl-member-sort-link">View All</strong>',
            '<a class="fl-member-sort-link" href="#">Name</a>',
            '<a class="fl-member-sort-link" href="#">Country</a>',
        );

        // Sort controls wrapper — matches members.php's <div class="fl-member-controls">
        self::assertStringContainsString('<div class="fl-member-controls">', $html);
        self::assertStringContainsString('<p class="fl-member-sort">Sort by:', $html);
        // Stats line — matches members.php's <p class="fl-member-stats">
        self::assertStringContainsString('<p class="fl-member-stats">1 member(s)</p>', $html);
        // The class belongs on the <ul> itself, not a wrapping <div> — matches members.php
        self::assertStringContainsString('<ul class="fl-member-list">', $html);
        self::assertStringNotContainsString('<div class="fl-member-list">', $html);
        // No standalone <h1> heading — members.php has none
        self::assertStringNotContainsString('<h1>', $html);
    }

    public function testDefaultMembersTemplateEmptyStateMatchesThePhpFallbackFragment(): void
    {
        $html = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTemplate(),
            [],
            [],
            'https://example.com/join',
            0,
        );

        // Matches members.php's <p class="fl-empty">No members to display yet.</p> exactly
        self::assertStringContainsString('<p class="fl-empty">No members to display yet.</p>', $html);
    }

    // -----------------------------------------------------------------------
    // {if_show_search} / {if_no_show_search}
    // -----------------------------------------------------------------------

    public function testShowSearchTrueKeepsIfShowSearchContentAndStripsIfNoShowSearch(): void
    {
        // {if_show_search} is a page-level conditional (like {if_members}), so it
        // needs an {each_member} block present to take the new-style page-level
        // path rather than the legacy per-row fallback.
        $html = MembersTemplateRenderer::render(
            '{if_show_search}visible{/if_show_search}{if_no_show_search}hidden{/if_no_show_search}{each_member}{/each_member}',
            [],
            [],
            'https://example.com/join',
            0,
            showSearch: true,
        );

        self::assertSame('visible', $html);
    }

    public function testShowSearchFalseStripsIfShowSearchAndKeepsIfNoShowSearchContent(): void
    {
        $html = MembersTemplateRenderer::render(
            '{if_show_search}visible{/if_show_search}{if_no_show_search}hidden{/if_no_show_search}{each_member}{/each_member}',
            [],
            [],
            'https://example.com/join',
            0,
            showSearch: false,
        );

        self::assertSame('hidden', $html);
    }

    public function testShowSearchDefaultsToTrueWhenNotPassed(): void
    {
        $html = MembersTemplateRenderer::render(
            '{if_show_search}visible{/if_show_search}{each_member}{/each_member}',
            [],
            [],
            'https://example.com/join',
            0,
        );

        self::assertSame('visible', $html);
    }

    public function testDefaultMembersTemplateHidesSortControlsWhenShowSearchIsFalse(): void
    {
        $html = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTemplate(),
            [],
            [],
            'https://example.com/join',
            0,
            showSearch: false,
        );

        self::assertStringNotContainsString('fl-member-controls', $html);
        self::assertStringNotContainsString('Sort by:', $html);
    }

    public function testRenderWithBodyRespectsShowSearchFalse(): void
    {
        $html = MembersTemplateRenderer::renderWithBody(
            '{if_show_search}visible{/if_show_search}{each_member}{/each_member}',
            'BODY',
            'https://example.com/join',
            0,
            showSearch: false,
        );

        self::assertStringNotContainsString('visible', $html);
    }

    // -----------------------------------------------------------------------
    // {if_sort_date} / {if_no_sort_date}
    // -----------------------------------------------------------------------

    public function testIsDateSortTrueKeepsIfSortDateContentAndStripsIfNoSortDate(): void
    {
        $html = MembersTemplateRenderer::render(
            '{if_sort_date}visible{/if_sort_date}{if_no_sort_date}hidden{/if_no_sort_date}{each_member}{/each_member}',
            [],
            [],
            'https://example.com/join',
            0,
            isDateSort: true,
        );

        self::assertSame('visible', $html);
    }

    public function testIsDateSortFalseStripsIfSortDateAndKeepsIfNoSortDateContent(): void
    {
        $html = MembersTemplateRenderer::render(
            '{if_sort_date}visible{/if_sort_date}{if_no_sort_date}hidden{/if_no_sort_date}{each_member}{/each_member}',
            [],
            [],
            'https://example.com/join',
            0,
            isDateSort: false,
        );

        self::assertSame('hidden', $html);
    }

    public function testIsDateSortDefaultsToFalseWhenNotPassed(): void
    {
        $html = MembersTemplateRenderer::render(
            '{if_no_sort_date}hidden{/if_no_sort_date}{each_member}{/each_member}',
            [],
            [],
            'https://example.com/join',
            0,
        );

        self::assertSame('hidden', $html);
    }

    public function testIsDateSortConditionalResolvesInsideAndOutsideTheEachMemberLoopInOnePass(): void
    {
        $member = $this->makeMember(name: 'Star');

        $html = MembersTemplateRenderer::render(
            '{if_sort_date}header{/if_sort_date}{each_member}{if_sort_date}[{fan_name}]{/if_sort_date}{/each_member}',
            [$member],
            [],
            'https://example.com/join',
            1,
            isDateSort: true,
        );

        self::assertSame('header[Star]', $html);
    }

    public function testDefaultMembersTemplateShowsJoinedDateOnlyWhenSortedByDate(): void
    {
        $member = $this->makeMember(name: 'Star', country: 'Canada');

        $sortedByDate = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTemplate(),
            [$member],
            [],
            'https://example.com/join',
            1,
            isDateSort: true,
        );
        $sortedByCountry = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTemplate(),
            [$member],
            [],
            'https://example.com/join',
            1,
            isDateSort: false,
        );

        self::assertStringContainsString('Joined', $sortedByDate);
        self::assertStringNotContainsString('Joined', $sortedByCountry);
    }

    public function testDefaultMembersTableTemplateShowsJoinedColumnOnlyWhenSortedByDate(): void
    {
        $member = $this->makeMember(name: 'Star', country: 'Canada');

        $sortedByDate = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTableTemplate(),
            [$member],
            [],
            'https://example.com/join',
            1,
            isDateSort: true,
        );
        $sortedByCountry = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTableTemplate(),
            [$member],
            [],
            'https://example.com/join',
            1,
            isDateSort: false,
        );

        self::assertStringContainsString('<span class="fl-member-table-th">Joined</span>', $sortedByDate);
        self::assertStringNotContainsString('Joined', $sortedByCountry);
    }

    public function testRenderWithBodyNeverShowsIfSortDateContentEvenWhenCallerRequestsIt(): void
    {
        // Country summary has no individual join dates — always non-date-sort.
        $html = MembersTemplateRenderer::renderWithBody(
            '{if_sort_date}visible{/if_sort_date}{if_no_sort_date}hidden{/if_no_sort_date}{each_member}{/each_member}',
            'BODY',
            'https://example.com/join',
            0,
        );

        self::assertSame('hiddenBODY', $html);
    }

    // -----------------------------------------------------------------------
    // {fan_additional_fields}
    // -----------------------------------------------------------------------

    public function testFanAdditionalFieldsRendersEachNonEmptyFieldAsABlock(): void
    {
        $fields = [
            new AdditionalField('fave_song', 'Favorite Song', 'text', false),
            new AdditionalField('fave_band', 'Favorite Band', 'text', false),
        ];
        $member = $this->makeMember(additionalFields: [
            'fave_song' => 'Bohemian Rhapsody',
            'fave_band' => 'Queen',
        ]);

        $html = MembersTemplateRenderer::render(
            '{each_member}{fan_additional_fields}{/each_member}',
            [$member],
            $fields,
            'https://example.com/join',
            1,
        );

        self::assertSame(
            '<span class="fl-member-field"><span class="fl-member-field-label">Favorite Song:</span> <span class="fl-member-field-value">Bohemian Rhapsody</span></span>'
            . "\n"
            . '<span class="fl-member-field"><span class="fl-member-field-label">Favorite Band:</span> <span class="fl-member-field-value">Queen</span></span>',
            $html,
        );
    }

    public function testFanAdditionalFieldsSkipsFieldsThatAreBlankForThisMember(): void
    {
        $fields = [
            new AdditionalField('fave_song', 'Favorite Song', 'text', false),
            new AdditionalField('fave_band', 'Favorite Band', 'text', false),
        ];
        $member = $this->makeMember(additionalFields: ['fave_song' => 'Bohemian Rhapsody']);

        $html = MembersTemplateRenderer::render(
            '{each_member}{fan_additional_fields}{/each_member}',
            [$member],
            $fields,
            'https://example.com/join',
            1,
        );

        self::assertStringContainsString('Favorite Song', $html);
        self::assertStringNotContainsString('Favorite Band', $html);
    }

    public function testFanAdditionalFieldsIsEmptyStringWhenListingHasNoFields(): void
    {
        $member = $this->makeMember();

        $html = MembersTemplateRenderer::render(
            '{each_member}[{fan_additional_fields}]{/each_member}',
            [$member],
            [],
            'https://example.com/join',
            1,
        );

        self::assertSame('[]', $html);
    }

    /**
     * FieldController blocks creating a *new* field named "additional_fields",
     * but a pre-existing or imported field can still carry that name (see
     * FieldControllerReservedNameTest for the creation-time guard). Confirms
     * the renderer's aggregate token still wins the key and still surfaces
     * that field's own value as one of the label:value pairs in the block —
     * it must not be silently dropped, and must not corrupt the aggregate for
     * the listing's other fields either.
     */
    public function testFanAdditionalFieldsAggregateStillIncludesAFieldNamedAdditionalFields(): void
    {
        $fields = [
            new AdditionalField('additional_fields', 'Additional Fields', 'text', false),
            new AdditionalField('fave_song', 'Favorite Song', 'text', false),
        ];
        $member = $this->makeMember(additionalFields: [
            'additional_fields' => 'Collides On Purpose',
            'fave_song'         => 'Bohemian Rhapsody',
        ]);

        $html = MembersTemplateRenderer::render(
            '{each_member}{fan_additional_fields}{/each_member}',
            [$member],
            $fields,
            'https://example.com/join',
            1,
        );

        self::assertStringContainsString('Collides On Purpose', $html);
        self::assertStringContainsString('Bohemian Rhapsody', $html);
    }

    public function testFanAdditionalFieldsEscapesLabelAndValue(): void
    {
        $fields = [new AdditionalField('fave', '<b>Fave</b>', 'text', false)];
        $member = $this->makeMember(additionalFields: ['fave' => '<script>alert(1)</script>']);

        $html = MembersTemplateRenderer::render(
            '{each_member}{fan_additional_fields}{/each_member}',
            [$member],
            $fields,
            'https://example.com/join',
            1,
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;Fave&lt;/b&gt;', $html);
    }

    public function testFanAdditionalFieldsWorksInLegacyModeWithoutEachMemberBlock(): void
    {
        $fields = [new AdditionalField('fave_song', 'Favorite Song', 'text', false)];
        $member = $this->makeMember(name: 'Star', additionalFields: ['fave_song' => 'Bohemian Rhapsody']);

        $html = MembersTemplateRenderer::render(
            '{fan_name}: {fan_additional_fields}',
            [$member],
            $fields,
            'https://example.com/join',
            1,
        );

        self::assertStringContainsString('Star:', $html);
        self::assertStringContainsString('Favorite Song', $html);
        self::assertStringContainsString('Bohemian Rhapsody', $html);
    }

    // -----------------------------------------------------------------------
    // {if_fan_additional_fields} / {if_no_fan_additional_fields}
    // -----------------------------------------------------------------------

    public function testIfFanAdditionalFieldsKeepsContentWhenMemberHasAValue(): void
    {
        $fields = [new AdditionalField('fave_song', 'Favorite Song', 'text', false)];
        $member = $this->makeMember(name: 'Star', additionalFields: ['fave_song' => 'Bohemian Rhapsody']);

        $html = MembersTemplateRenderer::render(
            '{each_member}{fan_name}{if_fan_additional_fields}, {fan_additional_fields}{/if_fan_additional_fields}{if_no_fan_additional_fields} (no fields){/if_no_fan_additional_fields}{/each_member}',
            [$member],
            $fields,
            'https://example.com/join',
            1,
        );

        self::assertStringStartsWith('Star, ', $html);
        self::assertStringContainsString('Bohemian Rhapsody', $html);
        self::assertStringNotContainsString('(no fields)', $html);
    }

    public function testIfNoFanAdditionalFieldsKeepsContentWhenMemberHasNoValues(): void
    {
        $fields = [new AdditionalField('fave_song', 'Favorite Song', 'text', false)];
        $member = $this->makeMember(name: 'Star');

        $html = MembersTemplateRenderer::render(
            '{each_member}{fan_name}{if_fan_additional_fields}, {fan_additional_fields}{/if_fan_additional_fields}{if_no_fan_additional_fields} (no fields){/if_no_fan_additional_fields}{/each_member}',
            [$member],
            $fields,
            'https://example.com/join',
            1,
        );

        self::assertSame('Star (no fields)', $html);
    }

    public function testDefaultMembersTemplateDoesNotLeaveADanglingCommaForAMemberWithNoFieldValues(): void
    {
        $fields = [new AdditionalField('fave_song', 'Favorite Song', 'text', false)];
        $member = $this->makeMember(name: 'Star', country: 'Canada');

        $html = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTemplate(),
            [$member],
            $fields,
            'https://example.com/join',
            1,
            isDateSort: true,
        );

        self::assertStringContainsString('Joined <span class="fl-member-added">January 1st 2026</span></li>', $html);
        self::assertStringNotContainsString('</span>,</li>', $html);
    }

    public function testDefaultMembersTemplateAddsCommaSeparatorBeforeFieldsWhenPresent(): void
    {
        $fields = [new AdditionalField('fave_song', 'Favorite Song', 'text', false)];
        $member = $this->makeMember(name: 'Star', country: 'Canada', additionalFields: ['fave_song' => 'Bohemian Rhapsody']);

        $html = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTemplate(),
            [$member],
            $fields,
            'https://example.com/join',
            1,
            isDateSort: true,
        );

        self::assertStringContainsString('Joined <span class="fl-member-added">January 1st 2026</span>, <span class="fl-member-field">', $html);
    }

    // -----------------------------------------------------------------------
    // {additional_field_headers} + {fan_additional_field_cells} (table layout)
    // -----------------------------------------------------------------------

    public function testAdditionalFieldHeadersRendersOnePerFieldRegardlessOfMemberValues(): void
    {
        $fields = [
            new AdditionalField('fave_song', 'Favorite Song', 'text', false),
            new AdditionalField('fave_band', 'Favorite Band', 'text', false),
        ];
        $member = $this->makeMember(additionalFields: ['fave_song' => 'Bohemian Rhapsody']);

        $html = MembersTemplateRenderer::render(
            '{each_member}{fan_name}{/each_member}{additional_field_headers}',
            [$member],
            $fields,
            'https://example.com/join',
            1,
        );

        self::assertSame(
            '<span class="fl-member-table-th">Favorite Song</span>'
            . "\n"
            . '<span class="fl-member-table-th">Favorite Band</span>',
            trim(str_replace($member->name, '', $html)),
        );
    }

    public function testAdditionalFieldCellsRendersValueOnlyWithDashForBlank(): void
    {
        $fields = [
            new AdditionalField('fave_song', 'Favorite Song', 'text', false),
            new AdditionalField('fave_band', 'Favorite Band', 'text', false),
        ];
        $member = $this->makeMember(additionalFields: ['fave_song' => 'Bohemian Rhapsody']);

        $html = MembersTemplateRenderer::render(
            '{each_member}{fan_additional_field_cells}{/each_member}',
            [$member],
            $fields,
            'https://example.com/join',
            1,
        );

        self::assertSame(
            '<span class="fl-member-table-cell">Bohemian Rhapsody</span>'
            . "\n"
            . '<span class="fl-member-table-cell">&ndash;</span>',
            $html,
        );
        // Value-only cells never repeat the field label — that belongs in the header row.
        self::assertStringNotContainsString('Favorite Song', $html);
    }

    public function testAdditionalFieldHeadersIsEmptyStringWhenListingHasNoFields(): void
    {
        $html = MembersTemplateRenderer::render(
            '{each_member}{/each_member}[{additional_field_headers}]',
            [$this->makeMember()],
            [],
            'https://example.com/join',
            1,
        );

        self::assertSame('[]', $html);
    }

    // -----------------------------------------------------------------------
    // Built-in default templates include the new token
    // -----------------------------------------------------------------------

    public function testDefaultMembersTemplateIncludesAdditionalFieldsToken(): void
    {
        self::assertStringContainsString('{fan_additional_fields}', ListingSettingsRepo::defaultMembersTemplate());
    }

    public function testDefaultMembersTableTemplateIncludesFieldHeaderAndCellTokens(): void
    {
        $tpl = ListingSettingsRepo::defaultMembersTableTemplate();
        self::assertStringContainsString('{additional_field_headers}', $tpl);
        self::assertStringContainsString('{fan_additional_field_cells}', $tpl);
    }

    public function testDefaultMembersTemplateRendersAdditionalFieldsForARealMember(): void
    {
        $fields = [new AdditionalField('fave_song', 'Favorite Song', 'text', false)];
        $member = $this->makeMember(name: 'Star', additionalFields: ['fave_song' => 'Bohemian Rhapsody']);

        $html = MembersTemplateRenderer::render(
            ListingSettingsRepo::defaultMembersTemplate(),
            [$member],
            $fields,
            'https://example.com/join',
            1,
        );

        self::assertStringContainsString('Favorite Song', $html);
        self::assertStringContainsString('Bohemian Rhapsody', $html);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @param array<string,string> $additionalFields */
    private function makeMember(string $name = 'Fan', string $country = '', array $additionalFields = [], string $url = '', bool $showurl = true, string $added = '2026-01-01'): Member
    {
        return new Member(
            id: 1,
            name: $name,
            email: 'fan@example.com',
            url: $url,
            country: $country,
            showurl: $showurl,
            password: 'hash',
            approved: true,
            rejected: false,
            added: $added,
            updated: '2026-01-01',
            additionalFields: $additionalFields,
        );
    }
}
