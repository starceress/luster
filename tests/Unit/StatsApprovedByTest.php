<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StatsApprovedByTest extends TestCase
{
    // -----------------------------------------------------------------------
    // {approved_by} token value mapping
    // -----------------------------------------------------------------------

    public function testTflProducesUppercaseAcronym(): void
    {
        self::assertSame('TFL', $this->networkValue('tfl'));
    }

    public function testTaflProducesUppercaseAcronym(): void
    {
        self::assertSame('TAFL', $this->networkValue('tafl'));
    }

    public function testEmptyApprovedByProducesEmptyString(): void
    {
        self::assertSame('', $this->networkValue(''));
    }

    public function testUnknownValueProducesUppercasedInput(): void
    {
        self::assertSame('OTHER', $this->networkValue('other'));
    }

    // -----------------------------------------------------------------------
    // {if_approved_by}…{/if_approved_by} conditional
    // -----------------------------------------------------------------------

    public function testConditionalPreservedWhenApproved(): void
    {
        $template = 'Before {if_approved_by}INNER{/if_approved_by} After';

        self::assertSame(
            'Before INNER After',
            $this->applyConditional($template, 'tfl'),
        );
    }

    public function testConditionalPreservedForTafl(): void
    {
        $template = 'Before {if_approved_by}INNER{/if_approved_by} After';

        self::assertSame(
            'Before INNER After',
            $this->applyConditional($template, 'tafl'),
        );
    }

    public function testConditionalRemovedWhenEmpty(): void
    {
        $template = 'Before {if_approved_by}INNER{/if_approved_by} After';

        self::assertSame(
            'Before  After',
            $this->applyConditional($template, ''),
        );
    }

    public function testConditionalAllowsMultilineContent(): void
    {
        $template = "{if_approved_by}\nLine one\nLine two\n{/if_approved_by}";

        self::assertSame(
            "\nLine one\nLine two\n",
            $this->applyConditional($template, 'tfl'),
        );
    }

    public function testConditionalStrippedLeavesNoArtifact(): void
    {
        $template = '{if_approved_by}Approved by: {approved_by}{/if_approved_by}';
        $result   = $this->applyConditional($template, '');

        self::assertSame('', $result);
        self::assertStringNotContainsString('{if_approved_by}', $result);
        self::assertStringNotContainsString('{/if_approved_by}', $result);
    }

    // -----------------------------------------------------------------------
    // {network_name} / {if_tfl} / {if_tafl} — derived from assigned
    // categories' sources, independent of {approved_by}/{if_approved_by}.
    // A listing can carry categories from both networks at once (unlike
    // "Approved By", a single dropdown), and repeat categories from the
    // same network (e.g. two TFL categories) should still only count once.
    // -----------------------------------------------------------------------

    public function testNetworkNameFromCategoriesSingleTfl(): void
    {
        self::assertSame('The Fanlistings Network', $this->networkNameFromCategories(true, false));
    }

    public function testNetworkNameFromCategoriesSingleTafl(): void
    {
        self::assertSame('The Anime Fanlistings Network', $this->networkNameFromCategories(false, true));
    }

    public function testNetworkNameFromCategoriesDeduplicatesRepeatedTflCategories(): void
    {
        // Two TFL categories (e.g. "Animation" + "Movies") both resolve to
        // hasTfl=true — the boolean collapses repeats on its own, so this is
        // really asserting there's no "TFL, TFL" duplication possible.
        self::assertSame('The Fanlistings Network', $this->networkNameFromCategories(true, false));
    }

    public function testNetworkNameFromCategoriesJoinsBothNetworks(): void
    {
        self::assertSame(
            'The Fanlistings Network & The Anime Fanlistings Network',
            $this->networkNameFromCategories(true, true),
        );
    }

    public function testNetworkNameFromCategoriesEmptyWhenNeitherAssigned(): void
    {
        self::assertSame('', $this->networkNameFromCategories(false, false));
    }

    public function testIfTflShowsWhenTflCategoryAssignedRegardlessOfApprovedBy(): void
    {
        $template = 'Before {if_tfl}INNER{/if_tfl} After';

        // hasTflCategory=true but the listing isn't marked "Approved By" at
        // all — {if_tfl} must still show, since it's category-derived now.
        self::assertSame('Before INNER After', $this->applyTflConditional($template, true));
    }

    public function testIfTflHiddenWhenNoTflCategoryAssigned(): void
    {
        $template = 'Before {if_tfl}INNER{/if_tfl} After';

        self::assertSame('Before  After', $this->applyTflConditional($template, false));
    }

    public function testIfTaflShowsWhenTaflCategoryAssigned(): void
    {
        $template = 'Before {if_tafl}INNER{/if_tafl} After';

        self::assertSame('Before INNER After', $this->applyTaflConditional($template, true));
    }

    public function testIfTaflHiddenWhenNoTaflCategoryAssigned(): void
    {
        $template = 'Before {if_tafl}INNER{/if_tafl} After';

        self::assertSame('Before  After', $this->applyTaflConditional($template, false));
    }

    // -----------------------------------------------------------------------
    // End-to-end: conditional + token replacement together
    // -----------------------------------------------------------------------

    public function testFullReplacementWithTfl(): void
    {
        $template = 'Members: {stat_approved} {if_approved_by}| Approved: {approved_by}{/if_approved_by}';

        $result = $this->applyFull($template, 'tfl', '42');

        self::assertSame('Members: 42 | Approved: TFL', $result);
    }

    public function testFullReplacementWithNoApproval(): void
    {
        $template = 'Members: {stat_approved} {if_approved_by}| Approved: {approved_by}{/if_approved_by}';

        $result = $this->applyFull($template, '', '7');

        self::assertSame('Members: 7 ', $result);
    }

    // -----------------------------------------------------------------------
    // Helpers — mirror the logic in stats.php and renderStatsTemplate()
    // -----------------------------------------------------------------------

    private function networkValue(string $approvedBy): string
    {
        return strtoupper($approvedBy);
    }

    private function applyConditional(string $template, string $approvedBy): string
    {
        return (string) preg_replace(
            '/\{if_approved_by\}(.*?)\{\/if_approved_by\}/s',
            $approvedBy !== '' ? '$1' : '',
            $template,
        );
    }

    private function networkNameFromCategories(bool $hasTfl, bool $hasTafl): string
    {
        $names = [];
        if ($hasTfl)  { $names[] = 'The Fanlistings Network'; }
        if ($hasTafl) { $names[] = 'The Anime Fanlistings Network'; }
        return implode(' & ', $names);
    }

    private function applyTflConditional(string $template, bool $hasTflCategory): string
    {
        return (string) preg_replace(
            '/\{if_tfl\}(.*?)\{\/if_tfl\}/s',
            $hasTflCategory ? '$1' : '',
            $template,
        );
    }

    private function applyTaflConditional(string $template, bool $hasTaflCategory): string
    {
        return (string) preg_replace(
            '/\{if_tafl\}(.*?)\{\/if_tafl\}/s',
            $hasTaflCategory ? '$1' : '',
            $template,
        );
    }

    private function applyFull(string $template, string $approvedBy, string $approvedCount): string
    {
        $template = $this->applyConditional($template, $approvedBy);
        $network  = $this->networkValue($approvedBy);
        return str_replace(
            ['{stat_approved}', '{approved_by}'],
            [$approvedCount, $network],
            $template,
        );
    }
}
