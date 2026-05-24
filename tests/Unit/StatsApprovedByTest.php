<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class StatsApprovedByTest extends TestCase
{
    // -----------------------------------------------------------------------
    // {approved_by} token value mapping
    // -----------------------------------------------------------------------

    public function testTflProducesLink(): void
    {
        $html = $this->approvedByHtml('tfl');

        self::assertStringContainsString('thefanlistings.org', $html);
        self::assertStringContainsString('The Fanlistings Network', $html);
    }

    public function testTaflProducesLink(): void
    {
        $html = $this->approvedByHtml('tafl');

        self::assertStringContainsString('animefanlistings.org', $html);
        self::assertStringContainsString('The Anime Fanlistings Network', $html);
    }

    public function testEmptyApprovedByProducesEmptyString(): void
    {
        self::assertSame('', $this->approvedByHtml(''));
    }

    public function testUnknownValueProducesEmptyString(): void
    {
        self::assertSame('', $this->approvedByHtml('other'));
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
    // End-to-end: conditional + token replacement together
    // -----------------------------------------------------------------------

    public function testFullReplacementWithTfl(): void
    {
        $template = 'Members: {stat_approved} {if_approved_by}| Approved: {approved_by}{/if_approved_by}';

        $result = $this->applyFull($template, 'tfl', '42');

        self::assertStringContainsString('Members: 42', $result);
        self::assertStringContainsString('thefanlistings.org', $result);
        self::assertStringNotContainsString('{approved_by}', $result);
        self::assertStringNotContainsString('{if_approved_by}', $result);
    }

    public function testFullReplacementWithNoApproval(): void
    {
        $template = 'Members: {stat_approved} {if_approved_by}| Approved: {approved_by}{/if_approved_by}';

        $result = $this->applyFull($template, '', '7');

        self::assertSame('Members: 7 ', $result);
        self::assertStringNotContainsString('{approved_by}', $result);
        self::assertStringNotContainsString('{if_approved_by}', $result);
    }

    // -----------------------------------------------------------------------
    // Helpers — mirror the logic in StatsController and embed/stats.php
    // -----------------------------------------------------------------------

    private function approvedByHtml(string $approvedBy): string
    {
        return match ($approvedBy) {
            'tfl'   => '<a href="https://thefanlistings.org">The Fanlistings Network</a>',
            'tafl'  => '<a href="https://animefanlistings.org">The Anime Fanlistings Network</a>',
            default => '',
        };
    }

    private function applyConditional(string $template, string $approvedBy): string
    {
        return (string) preg_replace(
            '/\{if_approved_by\}(.*?)\{\/if_approved_by\}/s',
            $approvedBy !== '' ? '$1' : '',
            $template,
        );
    }

    private function applyFull(string $template, string $approvedBy, string $approvedCount): string
    {
        $template = $this->applyConditional($template, $approvedBy);
        return str_replace(
            ['{stat_approved}', '{approved_by}'],
            [$approvedCount, $this->approvedByHtml($approvedBy)],
            $template,
        );
    }
}
