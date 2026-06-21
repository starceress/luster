<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\RulesBodyRenderer;
use PHPUnit\Framework\TestCase;

final class RulesBodyRendererTest extends TestCase
{
    public function testHtmlBodyPassedThroughVerbatim(): void
    {
        $html = RulesBodyRenderer::render('<p>Hello <strong>world</strong>.</p>');

        self::assertSame('<p>Hello <strong>world</strong>.</p>', $html);
    }

    public function testEscapedTokenValuesAreHtmlEncoded(): void
    {
        $html = RulesBodyRenderer::render('<p>Fan of {subject}.</p>', ['subject' => 'Tom & Jerry <Show>']);

        self::assertSame('<p>Fan of Tom &amp; Jerry &lt;Show&gt;.</p>', $html);
    }

    public function testRawTokensInsertedVerbatim(): void
    {
        $html = RulesBodyRenderer::render(
            '{gate_checkbox} agree {gate_button}',
            [],
            [
                'gate_checkbox' => '<input type="checkbox">',
                'gate_button'   => '<button>Go</button>',
            ]
        );

        self::assertSame('<input type="checkbox"> agree <button>Go</button>', $html);
    }

    public function testAllFiveTextTokensSubstituted(): void
    {
        $body = '<p>{subject} | {listing_title} | {listing_url} | {join_url} | {update_url}</p>';

        $html = RulesBodyRenderer::render($body, [
            'subject'       => 'My Listing',
            'listing_title' => 'My Title',
            'listing_url'   => 'https://example.com',
            'join_url'      => 'https://example.com/join/1',
            'update_url'    => 'https://example.com/update/1',
        ]);

        self::assertSame(
            '<p>My Listing | My Title | https://example.com | https://example.com/join/1 | https://example.com/update/1</p>',
            $html
        );
    }

    public function testGateCheckboxEmptyStringInButtonMode(): void
    {
        $html = RulesBodyRenderer::render(
            '{gate_checkbox}{gate_button}',
            [],
            [
                'gate_checkbox' => '',
                'gate_button'   => '<button name="agree" value="1">Join</button>',
            ]
        );

        self::assertSame('<button name="agree" value="1">Join</button>', $html);
    }

    public function testBodyContainingOnlyGateButtonTokenIsTreatedAsHtmlTemplate(): void
    {
        self::assertTrue(RulesBodyRenderer::isHtmlTemplate('{gate_button}'));
        self::assertTrue(RulesBodyRenderer::hasGateToken('{gate_button}'));

        $html = RulesBodyRenderer::render('{gate_button}', [], ['gate_button' => '<button>Go</button>']);

        self::assertSame('<button>Go</button>', $html);
    }

    public function testLegacyNumberedListBecomesOrderedList(): void
    {
        $html = RulesBodyRenderer::render("1. Be nice\n2. Have fun");

        self::assertSame('<ol><li>Be nice</li><li>Have fun</li></ol>', $html);
    }

    public function testLegacyPlainTextWithoutNumbersBecomesParagraph(): void
    {
        $html = RulesBodyRenderer::render("Welcome to the fanlisting.\nPlease follow the rules.");

        self::assertSame('<p>Welcome to the fanlisting.<br>Please follow the rules.</p>', $html);
    }

    public function testLegacyMixedParagraphsAndNumberedListInterleave(): void
    {
        $body = "Welcome!\n\n1. Be nice\n2. Have fun\n\nThanks for joining.";

        $html = RulesBodyRenderer::render($body);

        self::assertSame(
            '<p>Welcome!</p><ol><li>Be nice</li><li>Have fun</li></ol><p>Thanks for joining.</p>',
            $html
        );
    }

    public function testLegacyPlainTextWithBareAngleBracketsIsNotTreatedAsHtml(): void
    {
        self::assertFalse(RulesBodyRenderer::isHtmlTemplate('Score must be < 10 and > 0.'));

        $html = RulesBodyRenderer::render('Score must be < 10 and > 0.');

        self::assertSame('<p>Score must be &lt; 10 and &gt; 0.</p>', $html);
    }

    public function testLegacyPlainTextIsHtmlEscaped(): void
    {
        $html = RulesBodyRenderer::render("1. Don't send spam & junk mail");

        self::assertSame('<ol><li>Don&#039;t send spam &amp; junk mail</li></ol>', $html);
    }

    public function testLegacyPlainTextSubstitutesEscapedTokens(): void
    {
        $html = RulesBodyRenderer::render('1. You must be a fan of {subject}.', [
            'subject' => 'Tom & Jerry',
        ]);

        self::assertSame('<ol><li>You must be a fan of Tom &amp; Jerry.</li></ol>', $html);
    }

    public function testHasGateTokenFalseForLegacyPlainText(): void
    {
        self::assertFalse(RulesBodyRenderer::isHtmlTemplate("1. Be nice\n2. Have fun"));
        self::assertFalse(RulesBodyRenderer::hasGateToken("1. Be nice\n2. Have fun"));
    }

    public function testIfGateCheckboxBlockRetainedInCheckboxMode(): void
    {
        $html = RulesBodyRenderer::render(
            '<p>{if_gate_checkbox}<input type="checkbox">{/if_gate_checkbox}Agree {gate_button}</p>',
            [],
            [
                'gate_checkbox' => '<input type="checkbox">',
                'gate_button'   => '<button>Go</button>',
            ]
        );

        self::assertSame('<p><input type="checkbox">Agree <button>Go</button></p>', $html);
    }

    public function testIfGateCheckboxBlockRemovedInButtonMode(): void
    {
        $html = RulesBodyRenderer::render(
            '<p>{if_gate_checkbox}<input type="checkbox">{/if_gate_checkbox}Agree {gate_button}</p>',
            [],
            [
                'gate_checkbox' => '',
                'gate_button'   => '<button>Go</button>',
            ]
        );

        self::assertSame('<p>Agree <button>Go</button></p>', $html);
    }

    public function testDefaultBodyIsHtmlWithGateAndConditionalTokens(): void
    {
        $body = RulesBodyRenderer::defaultBody();

        self::assertTrue(RulesBodyRenderer::isHtmlTemplate($body));
        self::assertTrue(RulesBodyRenderer::hasGateToken($body));
        self::assertStringContainsString('{if_gate_checkbox}', $body);
        self::assertStringContainsString('{/if_gate_checkbox}', $body);
        self::assertStringContainsString('fl-rules-list', $body);
        self::assertStringContainsString('fl-rules-gate-checkbox', $body);
        self::assertStringContainsString('fl-form-actions', $body);
        self::assertStringContainsString('{subject}', $body);
        self::assertStringContainsString('{update_url}', $body);
    }

    public function testRenderPreviewShowsBothGateModesWithConditional(): void
    {
        $html = RulesBodyRenderer::renderPreview(RulesBodyRenderer::defaultBody(), [
            'subject'       => 'Test Show',
            'listing_title' => 'Test Show',
            'listing_url'   => 'https://example.com',
            'join_url'      => 'https://example.com/join/1',
            'update_url'    => 'https://example.com/update/1',
        ]);

        self::assertStringContainsString('Checkbox Gate', $html);
        self::assertStringContainsString('Button Gate', $html);
        self::assertStringContainsString('fl-rules-gate', $html);
        self::assertStringContainsString('Test Show', $html);

        // The checkbox-mode section renders an <input type="checkbox">; the
        // button-mode section must not (proves {if_gate_checkbox} works).
        self::assertSame(1, substr_count($html, 'type="checkbox"'));
    }
}
