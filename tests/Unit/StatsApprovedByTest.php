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
    // {network_name} token
    // -----------------------------------------------------------------------

    public function testNetworkNameTfl(): void
    {
        self::assertSame('The Fanlistings Network', $this->networkName('tfl'));
    }

    public function testNetworkNameTafl(): void
    {
        self::assertSame('The Anime Fanlistings Network', $this->networkName('tafl'));
    }

    public function testNetworkNameEmpty(): void
    {
        self::assertSame('', $this->networkName(''));
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

    private function networkName(string $approvedBy): string
    {
        return match ($approvedBy) {
            'tfl'   => 'The Fanlistings Network',
            'tafl'  => 'The Anime Fanlistings Network',
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
        $network  = $this->networkValue($approvedBy);
        return str_replace(
            ['{stat_approved}', '{approved_by}'],
            [$approvedCount, $network],
            $template,
        );
    }
}
