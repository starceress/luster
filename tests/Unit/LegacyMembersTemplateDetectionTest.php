<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ListingSettingsRepo::isLegacyMembersTemplate() -- the shared check
 * that decides whether a stored members_template value is really just an old
 * built-in default rather than a genuine customisation.
 *
 * This check is used in two places that must never disagree with each other:
 * the displayed textarea value (templates/admin/listings/edit.php's
 * $tplMembersVal, templates/admin/listings/global-templates.php's $val()) and
 * the Custom/Built-in badge computed in ListingController::edit() /
 * ::globalTemplates(). A review caught them disagreeing before this was
 * centralized -- a stored legacy default displayed the current built-in text
 * but the badge (computed from raw non-empty storage) still said "Custom".
 */
final class LegacyMembersTemplateDetectionTest extends TestCase
{
    public function testNullIsNotLegacy(): void
    {
        self::assertFalse(ListingSettingsRepo::isLegacyMembersTemplate(null));
    }

    public function testEmptyStringIsNotLegacy(): void
    {
        self::assertFalse(ListingSettingsRepo::isLegacyMembersTemplate(''));
        self::assertFalse(ListingSettingsRepo::isLegacyMembersTemplate('   '));
    }

    public function testCurrentBuiltInDefaultIsNotLegacy(): void
    {
        self::assertFalse(ListingSettingsRepo::isLegacyMembersTemplate(ListingSettingsRepo::defaultMembersTemplate()));
    }

    public function testGenuineCustomTemplateIsNotLegacy(): void
    {
        self::assertFalse(ListingSettingsRepo::isLegacyMembersTemplate('<div class="my-custom-members">{each_member}{fan_name}{/each_member}</div>'));
    }

    public function testOriginalBareListDefaultIsLegacy(): void
    {
        $v = '<div class="fl-member-list">'
            . "\n" . '{each_member}'
            . "\n" . '<div class="fl-member-item">'
            . "\n" . '  {if_fan_url}<a class="fl-member-name" href="{fan_url}" rel="noopener noreferrer">{fan_name}</a>{/if_fan_url}{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url}'
            . "\n" . '  {if_fan_country}<span class="fl-member-country">{fan_country}</span>{/if_fan_country}'
            . "\n" . '</div>'
            . "\n" . '{/each_member}'
            . "\n" . '</div>';

        self::assertTrue(ListingSettingsRepo::isLegacyMembersTemplate($v));
    }

    public function testStatsLineShapeIsLegacy(): void
    {
        $v = '<h1>Member List</h1>'
            . "\n" . '<p class="fl-member-sort">Sort by: {sort_link_date} | {sort_link_name} | {sort_link_country}</p><br>'
            . "\n" . '<p class="fl-member-stats">{stats_line}</p>'
            . "\n"
            . "\n" . '{if_members}'
            . "\n" . '<div class="fl-member-list">'
            . "\n" . '{each_member}'
            . "\n" . '  <ul class="fl-member-item">'
            . "\n" . '    <li>{if_fan_url}<a class="fl-member-name" href="{fan_url}" rel="noopener noreferrer">{fan_name}</a>{/if_fan_url}{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url} - {if_fan_country}<span class="fl-member-country">{fan_country}</span>{/if_fan_country}</li>'
            . "\n" . '  </ul>'
            . "\n" . '{/each_member}'
            . "\n" . '</div>'
            . "\n" . '{/if_members}'
            . "\n" . '{if_no_members}'
            . "\n" . '  <p class="fl-empty">No members yet. <a href="{join_url}">Want to join?</a></p>'
            . "\n" . '{/if_no_members}';

        self::assertTrue(ListingSettingsRepo::isLegacyMembersTemplate($v));
    }

    public function testNoStatsLineShapeIsLegacy(): void
    {
        // Same as the stats-line shape, but from the follow-up commit that
        // dropped {stats_line} again before the layout settled into the
        // current shape.
        $v = '<h1>Member List</h1>'
            . "\n" . '<p class="fl-member-sort">Sort by: {sort_link_date} | {sort_link_name} | {sort_link_country}</p><br>'
            . "\n"
            . "\n" . '{if_members}'
            . "\n" . '<div class="fl-member-list">'
            . "\n" . '{each_member}'
            . "\n" . '  <ul class="fl-member-item">'
            . "\n" . '    <li>{if_fan_url}<a class="fl-member-name" href="{fan_url}" rel="noopener noreferrer">{fan_name}</a>{/if_fan_url}{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url} - {if_fan_country}<span class="fl-member-country">{fan_country}</span>{/if_fan_country}</li>'
            . "\n" . '  </ul>'
            . "\n" . '{/each_member}'
            . "\n" . '</div>'
            . "\n" . '{/if_members}'
            . "\n" . '{if_no_members}'
            . "\n" . '  <p class="fl-empty">No members yet. <a href="{join_url}">Want to join?</a></p>'
            . "\n" . '{/if_no_members}';

        self::assertTrue(ListingSettingsRepo::isLegacyMembersTemplate($v));
    }

    public function testLegacyHeadingShapeIsLegacy(): void
    {
        $v = '<h1>Member List</h1>' . "\n"
            . '<p>Sort by: {sort_link_date} | {sort_link_name} | {sort_link_country}</p><br>' . "\n"
            . "\n"
            . '{if_members}' . "\n"
            . '<div class="fl-member-list">' . "\n"
            . "\n"
            . '<ul>' . "\n"
            . '{each_member}' . "\n"
            . '<li class="fl-member-item">{if_fan_url}<a href="{fan_url}">{fan_name}</a>{/if_fan_url}{if_no_fan_url}{fan_name}{/if_no_fan_url}{if_fan_country} &mdash; {fan_country}{/if_fan_country}</li>' . "\n"
            . '{/each_member}' . "\n"
            . '</ul>' . "\n"
            . "\n"
            . '</div>' . "\n"
            . '{/if_members}' . "\n"
            . "\n"
            . '{if_no_members}' . "\n"
            . '<p>No members yet. <a href="{join_url}">Want to join?</a></p>' . "\n"
            . '{/if_no_members}';

        self::assertTrue(ListingSettingsRepo::isLegacyMembersTemplate($v));
    }

    public function testPreTableSwapListShapeIsLegacy(): void
    {
        // The List display style was itself the real built-in default until Table
        // became the default instead. A stored value matching it exactly (e.g. a
        // "no-op" Save Global Defaults click from before the swap, and before the
        // docs/TEMPLATES_global-and-listing.md canonicalization pass changed the
        // List preset's own content too) must be treated as an old built-in
        // default, not a genuine customisation.
        $v = '{if_show_search}'
            . "\n" . '<div class="fl-member-controls">'
            . "\n" . '<p class="fl-member-sort">Sort by: {sort_link_date} | {sort_link_name} | {sort_link_country}</p>'
            . "\n" . '</div>'
            . "\n" . '{/if_show_search}'
            . "\n"
            . "\n" . '<p class="fl-member-stats">{stats_line}</p>'
            . "\n"
            . "\n" . '{if_members}'
            . "\n" . '<ul class="fl-member-list">'
            . "\n" . '{each_member}'
            . "\n" . '<li class="fl-member-item">{if_fan_url}<a class="fl-member-name" href="{fan_url}">{fan_name}</a>{/if_fan_url}{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url}{if_fan_country} &mdash; <span class="fl-member-country">{fan_country}</span>{/if_fan_country}{if_sort_date}, Joined <span class="fl-member-added">{fan_added}</span>{/if_sort_date}{if_fan_additional_fields}, {fan_additional_fields}{/if_fan_additional_fields}</li>'
            . "\n" . '{/each_member}'
            . "\n" . '</ul>'
            . "\n" . '{/if_members}'
            . "\n"
            . "\n" . '{if_no_members}'
            . "\n" . '<p class="fl-empty">No members to display yet.</p>'
            . "\n" . '{/if_no_members}';

        self::assertTrue(ListingSettingsRepo::isLegacyMembersTemplate($v));
    }

    public function testPreDocCanonicalizationTableShapeIsLegacy(): void
    {
        // The <h1>Member List</h1> table shape was the real built-in "Table"
        // default before the docs/TEMPLATES_global-and-listing.md
        // canonicalization pass replaced it with the {if_show_search}/
        // {stats_line} shape shared with the List style.
        $v = '<h1>Member List</h1>'
            . "\n" . '<p>Sort by: {sort_link_date} | {sort_link_name} | {sort_link_country}</p><br>'
            . "\n"
            . "\n" . '{if_members}'
            . "\n" . '<div class="fl-member-table">'
            . "\n" . '{if_no_country_sort}'
            . "\n" . '<div class="fl-member-table-head">'
            . "\n" . '<span class="fl-member-table-th">Name</span>'
            . "\n" . '<span class="fl-member-table-th">Country</span>'
            . "\n" . '{if_sort_date}<span class="fl-member-table-th">Joined</span>{/if_sort_date}'
            . "\n" . '{additional_field_headers}'
            . "\n" . '</div>'
            . "\n" . '{/if_no_country_sort}'
            . "\n"
            . "\n" . '{each_member}'
            . "\n" . '<div class="fl-member-table-row">'
            . "\n" . '<span class="fl-member-table-cell">{if_fan_url}<a href="{fan_url}">{fan_name}</a>{/if_fan_url}{if_no_fan_url}{fan_name}{/if_no_fan_url}</span>'
            . "\n" . '<span class="fl-member-table-cell">{if_fan_country}{fan_country}{/if_fan_country}{if_no_fan_country}&ndash;{/if_no_fan_country}</span>'
            . "\n" . '{if_sort_date}<span class="fl-member-table-cell">{fan_added}</span>{/if_sort_date}'
            . "\n" . '{fan_additional_field_cells}'
            . "\n" . '</div>'
            . "\n" . '{/each_member}'
            . "\n" . '</div>'
            . "\n" . '{/if_members}'
            . "\n"
            . "\n" . '{if_no_members}'
            . "\n" . '<p>No members yet. <a href="{join_url}">Want to join?</a></p>'
            . "\n" . '{/if_no_members}';

        self::assertTrue(ListingSettingsRepo::isLegacyMembersTemplate($v));
    }

    public function testHandCustomizedTemplateSharingTheOldHeadingIsNotMistakenForLegacy(): void
    {
        // Same <h1>Member List</h1> heading as the legacy shapes, but genuinely
        // different content beneath it -- must not be swapped out.
        $v = '<h1>Member List</h1>' . "\n" . '<p>My totally custom member list goes here.</p>';

        self::assertFalse(ListingSettingsRepo::isLegacyMembersTemplate($v));
    }
}
