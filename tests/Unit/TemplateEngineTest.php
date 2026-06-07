<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use InvalidArgumentException;
use Luster\Core\TemplateEngine;
use PHPUnit\Framework\TestCase;

final class TemplateEngineTest extends TestCase
{
    public function testTokensEscapesScalarValues(): void
    {
        $result = TemplateEngine::tokens('Hello {name}!', ['name' => '<b>Bob</b> & "Alice"']);

        self::assertSame('Hello &lt;b&gt;Bob&lt;/b&gt; &amp; &quot;Alice&quot;!', $result);
    }

    public function testTokensInsertsRawHtmlVerbatim(): void
    {
        $result = TemplateEngine::tokens('{listing_image_html}', [], ['listing_image_html' => '<img src="x.png" alt="">']);

        self::assertSame('<img src="x.png" alt="">', $result);
    }

    public function testTokensAllowsDocumentedAllowListKeyWithoutHtmlSuffix(): void
    {
        $result = TemplateEngine::tokens('{input}', [], ['input' => '<input type="text">']);

        self::assertSame('<input type="text">', $result);
    }

    public function testTokensRejectsRawKeyMissingHtmlSuffixAndNotInAllowList(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TemplateEngine::tokens('{listing_image}', [], ['listing_image' => '<img>']);
    }

    public function testTokensDoesNotCascadeWhenEscapedValueContainsRawTokenSyntax(): void
    {
        // An escaped value of literal text "{input}" must surface as plain,
        // escaped text — it must NOT be re-interpreted as a placeholder and
        // pulled through to the trusted-raw substitution in the same pass.
        $result = TemplateEngine::tokens(
            '{label} / {input}',
            ['label' => '{input}'],
            ['input' => '<input type="text">'],
        );

        self::assertSame('{input} / <input type="text">', $result);
    }

    public function testTokensDoesNotCascadeWhenEscapedValueContainsHtmlSuffixedRawTokenSyntax(): void
    {
        $result = TemplateEngine::tokens(
            '{listing_title} | {listing_image_html}',
            ['listing_title' => '{listing_image_html}'],
            ['listing_image_html' => '<img src="x.png" alt="">'],
        );

        self::assertSame('{listing_image_html} | <img src="x.png" alt="">', $result);
    }

    public function testTokensDoesNotCascadeWhenEscapedValueNamesAnotherEscapedToken(): void
    {
        // A fan-supplied name of literal "{site_name}" must not be expanded
        // into the site name's value — each placeholder is matched against
        // the original template exactly once, in a single simultaneous pass.
        $result = TemplateEngine::tokens(
            '{fan_name} writes to {site_name}',
            ['fan_name' => '{site_name}', 'site_name' => 'Luster Collective'],
        );

        self::assertSame('{site_name} writes to Luster Collective', $result);
    }

    public function testTokensReplacesNullScalarsWithEmptyString(): void
    {
        $result = TemplateEngine::tokens('[{value}]', ['value' => null]);

        self::assertSame('[]', $result);
    }

    public function testConditionalsKeepsBlockContentWhenTrue(): void
    {
        $result = TemplateEngine::conditionals('a{if_x}YES{/if_x}b', ['x' => true]);

        self::assertSame('aYESb', $result);
    }

    public function testConditionalsRemovesBlockEntirelyWhenFalse(): void
    {
        $result = TemplateEngine::conditionals('a{if_x}YES{/if_x}b', ['x' => false]);

        self::assertSame('ab', $result);
    }

    public function testConditionalsTreatsIfAndIfNoAsIndependentNames(): void
    {
        $tpl = '{if_affiliates}has{/if_affiliates}{if_no_affiliates}none{/if_no_affiliates}';

        self::assertSame('has', TemplateEngine::conditionals($tpl, ['affiliates' => true, 'no_affiliates' => false]));
        self::assertSame('none', TemplateEngine::conditionals($tpl, ['affiliates' => false, 'no_affiliates' => true]));
    }

    public function testConditionalsSupportMultilineBlockContent(): void
    {
        $tpl = "{if_x}line one\nline two{/if_x}";

        self::assertSame("line one\nline two", TemplateEngine::conditionals($tpl, ['x' => true]));
    }

    public function testLoopRendersOneIterationPerRow(): void
    {
        $tpl = 'before{each_item}[{n}]{/each_item}after';

        $result = TemplateEngine::loop(
            $tpl,
            'item',
            [1, 2, 3],
            static fn(string $inner, $row): string => str_replace('{n}', (string) $row, $inner),
        );

        self::assertSame('before[1][2][3]after', $result);
    }

    public function testLoopProducesEmptyStringForEmptyRowSet(): void
    {
        $tpl = 'before{each_item}[{n}]{/each_item}after';

        $result = TemplateEngine::loop(
            $tpl,
            'item',
            [],
            static fn(string $inner, $row): string => $inner,
        );

        self::assertSame('beforeafter', $result);
    }

    public function testLoopSupportsNestedLoopsByLeavingInnerTagsForSubsequentPass(): void
    {
        $tpl = '{each_outer}[{each_inner}<{x}>{/each_inner}]{/each_outer}';

        $outerRowFn = static function (string $inner, $row): string {
            return TemplateEngine::loop(
                $inner,
                'inner',
                $row,
                static fn(string $innerTpl, $x): string => str_replace('{x}', (string) $x, $innerTpl),
            );
        };

        $result = TemplateEngine::loop($tpl, 'outer', [['a', 'b'], ['c']], $outerRowFn);

        self::assertSame('[<a><b>][<c>]', $result);
    }

    public function testRenderProcessesLoopsThenConditionalsThenTokens(): void
    {
        $tpl = '{if_has}{each_row}<{name}>{/each_row}{/if_has}{if_no_has}empty{/if_no_has}';

        $result = TemplateEngine::render(
            $tpl,
            escaped: ['name' => 'shared'],
            conditions: ['has' => true, 'no_has' => false],
            loops: [
                'row' => [
                    'rows'  => [1, 2],
                    'rowFn' => static fn(string $inner, $row): string => $inner,
                ],
            ],
        );

        self::assertSame('<shared><shared>', $result);
    }

    public function testRenderHidesLoopOutputWhenWrappingConditionIsFalse(): void
    {
        $tpl = '{if_has}{each_row}<{name}>{/each_row}{/if_has}{if_no_has}empty{/if_no_has}';

        $result = TemplateEngine::render(
            $tpl,
            escaped: ['name' => 'shared'],
            conditions: ['has' => false, 'no_has' => true],
            loops: [
                'row' => [
                    'rows'  => [1, 2],
                    'rowFn' => static fn(string $inner, $row): string => $inner,
                ],
            ],
        );

        self::assertSame('empty', $result);
    }
}
