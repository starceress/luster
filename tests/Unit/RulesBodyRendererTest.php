<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\RulesBodyRenderer;
use PHPUnit\Framework\TestCase;

final class RulesBodyRendererTest extends TestCase
{
    public function testPlainParagraphLinesProduceParagraphWithLineBreaks(): void
    {
        $html = RulesBodyRenderer::render("Line one\nLine two", []);

        self::assertSame('<p>Line one<br>Line two</p>', $html);
    }

    public function testNumberedListProducesOrderedListWithStrippedNumbers(): void
    {
        $html = RulesBodyRenderer::render("1. Be nice\n2. Have fun", [], 'numbered');

        self::assertSame('<ol><li>Be nice</li><li>Have fun</li></ol>', $html);
    }

    public function testCircleListProducesUnorderedListWithStrippedNumbers(): void
    {
        $html = RulesBodyRenderer::render("1. Be nice\n2. Have fun", [], 'circle');

        self::assertSame('<ul class="fl-rules-circle"><li>Be nice</li><li>Have fun</li></ul>', $html);
    }

    public function testTokenSubstitutionForAllFiveTokens(): void
    {
        $body = 'Welcome to {subject}, also known as {listing_title}. '
            . 'Visit {listing_url}, join at {join_url}, or update at {update_url}.';

        $tokens = [
            'subject'       => 'My Listing',
            'listing_title' => 'My Listing Title',
            'listing_url'   => 'https://example.com',
            'join_url'      => 'https://example.com/join/1',
            'update_url'    => 'https://example.com/update/1',
        ];

        $html = RulesBodyRenderer::render($body, $tokens);

        self::assertSame(
            '<p>Welcome to My Listing, also known as My Listing Title. '
            . 'Visit https://example.com, join at https://example.com/join/1, '
            . 'or update at https://example.com/update/1.</p>',
            $html
        );
    }

    public function testEscapesBodyAndTokenValuesWithoutDoubleEscaping(): void
    {
        $body = 'Welcome to {subject} & enjoy <our> site.';

        $tokens = ['subject' => 'Tom & Jerry <Show>'];

        $html = RulesBodyRenderer::render($body, $tokens);

        self::assertSame(
            '<p>Welcome to Tom &amp; Jerry &lt;Show&gt; &amp; enjoy &lt;our&gt; site.</p>',
            $html
        );
    }

    public function testMixedParagraphsAndListsProduceInterleavedBlocks(): void
    {
        $body = "Welcome!\n\n1. Be nice\n2. Have fun\n\nThanks for reading.";

        $html = RulesBodyRenderer::render($body, [], 'numbered');

        self::assertSame(
            '<p>Welcome!</p><ol><li>Be nice</li><li>Have fun</li></ol><p>Thanks for reading.</p>',
            $html
        );
    }
}
