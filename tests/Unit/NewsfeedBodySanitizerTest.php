<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\NewsfeedBodySanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Luster\Support\NewsfeedBodySanitizer — the read-time HTML
 * formatting allowlist for News Feed entry bodies (escape-first, then
 * selectively convert back a fixed set of understood tag/attribute
 * patterns; malformed or unrecognized input is always left inert).
 */
final class NewsfeedBodySanitizerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Every allowed tag converts
    // -----------------------------------------------------------------------

    public function testEachAllowedTagConverts(): void
    {
        foreach (['b', 'strong', 'i', 'em', 'u', 's', 'p', 'blockquote'] as $tag) {
            $this->assertSame(
                "<{$tag}>text</{$tag}>",
                NewsfeedBodySanitizer::sanitize("<{$tag}>text</{$tag}>"),
                "Tag <{$tag}> should convert",
            );
        }
    }

    public function testCaseInsensitiveTagNames(): void
    {
        $this->assertSame('<b>text</b>', NewsfeedBodySanitizer::sanitize('<B>text</B>'));
    }

    public function testUppercaseOnBothEndsOfALink(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<A HREF="https://example.com">text</A>');
        $this->assertSame(
            '<a href="https://example.com" target="_blank" rel="noopener noreferrer">text</a>',
            $result,
        );
    }

    // -----------------------------------------------------------------------
    // <br> normalization
    // -----------------------------------------------------------------------

    public function testBrVariantsNormalizeToBareBr(): void
    {
        $this->assertSame('a<br>b', NewsfeedBodySanitizer::sanitize('a<br>b'));
        $this->assertSame('a<br>b', NewsfeedBodySanitizer::sanitize('a<br/>b'));
        $this->assertSame('a<br>b', NewsfeedBodySanitizer::sanitize('a<br />b'));
    }

    public function testBrAttributeDroppedTagStillConverts(): void
    {
        $this->assertSame('<br>', NewsfeedBodySanitizer::sanitize('<br class="x">'));
    }

    // -----------------------------------------------------------------------
    // Plain text stays escaped
    // -----------------------------------------------------------------------

    public function testPlainTextWithSpecialCharsStaysEscaped(): void
    {
        $this->assertSame(
            '5 &lt; 10 &amp; 10 &gt; 5, she said &quot;hi&quot; and it&#039;s fine',
            NewsfeedBodySanitizer::sanitize('5 < 10 & 10 > 5, she said "hi" and it\'s fine'),
        );
    }

    // -----------------------------------------------------------------------
    // Attribute handling
    // -----------------------------------------------------------------------

    public function testUnrecognizedAttributeIsDroppedNotFatal(): void
    {
        $this->assertSame(
            '<b class="fl-newsfeed-x">text</b>',
            NewsfeedBodySanitizer::sanitize('<b class="fl-newsfeed-x" onmouseover="alert(1)">text</b>'),
        );
        $this->assertSame(
            '<b>text</b>',
            NewsfeedBodySanitizer::sanitize('<b class="x" onmouseover="alert(1)">text</b>'),
        );
    }

    public function testDataAttributeAndUppercaseAttributeName(): void
    {
        $this->assertSame(
            '<b class="fl-newsfeed-y">text</b>',
            NewsfeedBodySanitizer::sanitize('<b data-x="1" CLASS="fl-newsfeed-y">text</b>'),
        );
    }

    public function testWhitespaceAroundEquals(): void
    {
        $this->assertSame(
            NewsfeedBodySanitizer::sanitize('<b class="fl-newsfeed-x">text</b>'),
            NewsfeedBodySanitizer::sanitize('<b class = "fl-newsfeed-x">text</b>'),
        );
    }

    public function testMalformedBooleanUnquotedAttributesDoNotFailTheTag(): void
    {
        $this->assertSame('<b>text</b>', NewsfeedBodySanitizer::sanitize('<b disabled>text</b>'));
        $this->assertSame('<b>text</b>', NewsfeedBodySanitizer::sanitize('<b class=fl-newsfeed-x>text</b>'));
    }

    // -----------------------------------------------------------------------
    // Orphaned-closing-tag regressions
    // -----------------------------------------------------------------------

    public function testNoOrphanedClosingTagFromMalformedOpening(): void
    {
        foreach (['b', 'blockquote'] as $tag) {
            $result = NewsfeedBodySanitizer::sanitize("<{$tag} disabled>text</{$tag}>");
            $this->assertSame(1, substr_count($result, "<{$tag}>"), "exactly one real <{$tag}> for $tag");
            $this->assertSame(1, substr_count($result, "</{$tag}>"), "exactly one real </{$tag}> for $tag");
        }
    }

    public function testNoOrphanedClosingTagWhenOpeningHasNoSafeTagEnd(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<b title="oops</b>');
        $this->assertStringNotContainsString('<b>', $result);
        $this->assertStringNotContainsString('</b>', $result);

        $result = NewsfeedBodySanitizer::sanitize('<blockquote title="oops</blockquote>');
        $this->assertDoesNotMatchRegularExpression('/<blockquote[ >]/', $result);
        $this->assertStringNotContainsString('</blockquote>', $result);
    }

    public function testMalformedOpeningWithNoClosingTagAtAllStaysInert(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<b title="oops forever');
        $this->assertStringNotContainsString('<b>', $result);
        // Must not throw / must not emit a PHP warning - reaching this assertion at all proves that.
        $this->assertIsString($result);
    }

    public function testMalformedOpeningDoesNotBlockALaterValidPairOfTheSameTag(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<b title="oops</b> then <b>valid</b>');
        $this->assertStringContainsString('<b>valid</b>', $result);
        $this->assertSame(1, substr_count($result, '<b>'));
    }

    public function testWellFormedOpeningWithNoMatchingClosingAnywhereStaysFullyInert(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<b>bold with no closing anywhere in the body');
        $this->assertStringNotContainsString('<b>', $result);

        $result = NewsfeedBodySanitizer::sanitize('<a href="https://example.com">no closing tag');
        $this->assertDoesNotMatchRegularExpression('/<a[ >]/', $result);
    }

    // -----------------------------------------------------------------------
    // Prefix collisions and look-alike tag names
    // -----------------------------------------------------------------------

    public function testPrefixCollisionRejection(): void
    {
        $this->assertSame('<blockquote>text</blockquote>', NewsfeedBodySanitizer::sanitize('<blockquote>text</blockquote>'));
        $this->assertSame('<strong>text</strong>', NewsfeedBodySanitizer::sanitize('<strong>text</strong>'));
        $this->assertSame(
            '<img src="https://example.com/x.png" class="fl-newsfeed-img">',
            NewsfeedBodySanitizer::sanitize('<img src="https://example.com/x.png">'),
        );
        $this->assertSame('<br>', NewsfeedBodySanitizer::sanitize('<br>'));
    }

    public function testUnsupportedLookAlikeTagNamesStayFullyInert(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<b-thing>text</b-thing>');
        $this->assertStringNotContainsString('<b>', $result);
        $this->assertStringNotContainsString('<b-thing>', $result);

        $result = NewsfeedBodySanitizer::sanitize('<img-widget alt="x">');
        $this->assertDoesNotMatchRegularExpression('/<img[ >]/', $result);

        $result = NewsfeedBodySanitizer::sanitize('<br-custom>');
        $this->assertStringNotContainsString('<br>', $result);
    }

    // -----------------------------------------------------------------------
    // Quote-aware scanning
    // -----------------------------------------------------------------------

    public function testLiteralGreaterThanInsideQuotedAttributeSurvivesIntact(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<b title="2 > 1">text</b>');
        $this->assertSame('<b title="2 &gt; 1">text</b>', $result);

        $result = NewsfeedBodySanitizer::sanitize('<img src="https://example.com/x.png" alt="a > b > c">');
        $this->assertStringContainsString('alt="a &gt; b &gt; c"', $result);

        // rebuildOpeningTag() always emits recognized attributes in a fixed
        // order (class, then title) regardless of the order they appeared in
        // the source - this just confirms both are still reached/extracted
        // correctly after the first one contained an embedded >.
        $result = NewsfeedBodySanitizer::sanitize('<b title="a > b" class="fl-newsfeed-x">text</b>');
        $this->assertSame('<b class="fl-newsfeed-x" title="a &gt; b">text</b>', $result);
    }

    public function testFindTagEndNeverThrowsRegardlessOfInput(): void
    {
        foreach ([50, 1000, 10000] as $n) {
            $raw = '<b ' . str_repeat('"', $n) . 'title="oops</b>';
            $start = microtime(true);
            $result = NewsfeedBodySanitizer::sanitize($raw);
            $elapsed = microtime(true) - $start;

            $this->assertLessThan(0.1, $elapsed, "n={$n} should resolve quickly");
            $this->assertStringNotContainsString('<b>', $result);
        }

        // Same shape inside <a ...>'s attribute area specifically.
        $raw = '<a href="' . str_repeat('"', 1000) . 'text</a>';
        $start = microtime(true);
        NewsfeedBodySanitizer::sanitize($raw);
        $this->assertLessThan(0.1, microtime(true) - $start);
    }

    // -----------------------------------------------------------------------
    // class token filtering
    // -----------------------------------------------------------------------

    public function testClassTokenFiltering(): void
    {
        $this->assertSame(
            '<b class="fl-newsfeed-highlight">t</b>',
            NewsfeedBodySanitizer::sanitize('<b class="fl-newsfeed-highlight">t</b>'),
        );
        $this->assertSame(
            '<b class="fl-newsfeed-highlight">t</b>',
            NewsfeedBodySanitizer::sanitize('<b class="hidden fl-newsfeed-highlight">t</b>'),
        );
        $this->assertSame(
            '<b>t</b>',
            NewsfeedBodySanitizer::sanitize('<b class="hidden">t</b>'),
        );
        $this->assertSame(
            '<b class="nf-highlight">t</b>',
            NewsfeedBodySanitizer::sanitize('<b class="nf-highlight">t</b>'),
        );
    }

    public function testTitleRoundTripsWithNoTokenFiltering(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<b title="a & b < c">t</b>');
        $this->assertSame('<b title="a &amp; b &lt; c">t</b>', $result);
    }

    // -----------------------------------------------------------------------
    // <a> — required href, forced target/rel
    // -----------------------------------------------------------------------

    public function testValidLinkAlwaysForcesTargetAndRel(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<a href="https://example.com" target="_self" rel="foo">text</a>');
        $this->assertSame(
            '<a href="https://example.com" target="_blank" rel="noopener noreferrer">text</a>',
            $result,
        );
    }

    public function testAIsNeverOrphanedOnFailure(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<a href="javascript:alert(1)">text</a>');
        $this->assertDoesNotMatchRegularExpression('/<a[ >]/', $result);
        $this->assertStringNotContainsString('</a>', $result);
    }

    public function testNestedFormattingSurvivesAFailedLink(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<a href="javascript:alert(1)"><b>text</b></a>');
        $this->assertDoesNotMatchRegularExpression('/<a[ >]/', $result);
        $this->assertStringContainsString('<b>text</b>', $result);
    }

    public function testInvalidHrefSchemesStayInert(): void
    {
        foreach (['data:text/html,x', 'ftp://example.com', 'not a url', ''] as $href) {
            $raw = '<a href="' . $href . '">text</a>';
            $result = NewsfeedBodySanitizer::sanitize($raw);
            $this->assertDoesNotMatchRegularExpression('/<a[ >]/', $result, "href={$href}");
        }

        $result = NewsfeedBodySanitizer::sanitize('<a>no href</a>');
        $this->assertDoesNotMatchRegularExpression('/<a[ >]/', $result);
    }

    public function testPlainHttpSchemeIsAcceptedNotJustHttps(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<a href="http://example.com">text</a>');
        $this->assertStringContainsString('<a href="http://example.com"', $result);

        $result = NewsfeedBodySanitizer::sanitize('<img src="http://example.com/x.png">');
        $this->assertStringContainsString('<img src="http://example.com/x.png"', $result);
    }

    public function testUppercaseUrlSchemeIsAccepted(): void
    {
        // parse_url() preserves the scheme's original case; a naive
        // in_array(['http', 'https']) check without strtolower() would
        // reject a perfectly valid uppercase-scheme URL.
        $result = NewsfeedBodySanitizer::sanitize('<a href="HTTP://example.com">text</a>');
        $this->assertDoesNotMatchRegularExpression('/&lt;a /', $result, 'HTTP:// (uppercase) must still be accepted');
        $this->assertStringContainsString('<a href="HTTP://example.com"', $result);

        $result = NewsfeedBodySanitizer::sanitize('<a href="HtTpS://example.com">text</a>');
        $this->assertStringContainsString('<a href="HtTpS://example.com"', $result);
    }

    // -----------------------------------------------------------------------
    // <img>
    // -----------------------------------------------------------------------

    public function testImgConvertsAndSelfClosingVariantWorks(): void
    {
        $expected = '<img src="https://example.com/x.png" class="fl-newsfeed-img">';
        $this->assertSame($expected, NewsfeedBodySanitizer::sanitize('<img src="https://example.com/x.png">'));
        $this->assertSame($expected, NewsfeedBodySanitizer::sanitize('<img src="https://example.com/x.png" />'));
    }

    public function testImgClassAlwaysIncludesForcedClass(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<img src="https://example.com/x.png" class="fl-newsfeed-inline">');
        $this->assertSame('<img src="https://example.com/x.png" class="fl-newsfeed-img fl-newsfeed-inline">', $result);
    }

    public function testImgAltIsOptional(): void
    {
        $this->assertStringNotContainsString('alt=', NewsfeedBodySanitizer::sanitize('<img src="https://example.com/x.png">'));
        $this->assertStringContainsString('alt="hi"', NewsfeedBodySanitizer::sanitize('<img src="https://example.com/x.png" alt="hi">'));
    }

    // -----------------------------------------------------------------------
    // Single-quoted values
    // -----------------------------------------------------------------------

    public function testSingleQuotedHrefStaysInert(): void
    {
        $result = NewsfeedBodySanitizer::sanitize("<a href='https://example.com'>text</a>");
        $this->assertDoesNotMatchRegularExpression('/<a[ >]/', $result);
    }

    public function testSingleQuotedClassOnBareTagStillConvertsTag(): void
    {
        $result = NewsfeedBodySanitizer::sanitize("<b class='fl-newsfeed-x'>text</b>");
        $this->assertSame('<b>text</b>', $result);
    }

    // -----------------------------------------------------------------------
    // Mixed allowed/disallowed tags
    // -----------------------------------------------------------------------

    public function testMixedAllowedAndDisallowedTagsNoInterference(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<b>bold</b> <script>alert(1)</script> <i>italic</i> <iframe src="x"></iframe>');
        $this->assertStringContainsString('<b>bold</b>', $result);
        $this->assertStringContainsString('<i>italic</i>', $result);
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('<iframe', $result);
    }

    // -----------------------------------------------------------------------
    // Unclosed href quote swallowing a later unrelated quote
    // -----------------------------------------------------------------------

    public function testUnclosedHrefSwallowingLaterQuoteResolvesSafely(): void
    {
        $raw = '<a href="never closed, she said "hi" and left a real link <a href="https://example.com">real</a>';
        $result = NewsfeedBodySanitizer::sanitize($raw);
        $this->assertIsString($result);
        // Whatever happens, no unsafe/invalid href can ever reach a real <a> tag.
        $this->assertDoesNotMatchRegularExpression('/href="(?!https?:\/\/)/', $result);
    }

    // -----------------------------------------------------------------------
    // Determinism, not idempotency
    // -----------------------------------------------------------------------

    public function testDeterminism(): void
    {
        $raw = '<b>bold</b> <a href="https://example.com">link</a> plain & text';
        $this->assertSame(NewsfeedBodySanitizer::sanitize($raw), NewsfeedBodySanitizer::sanitize($raw));
    }

    // -----------------------------------------------------------------------
    // toDisplayHtml() <br> spelling consistency
    // -----------------------------------------------------------------------

    public function testToDisplayHtmlNeverMixesBrSpellings(): void
    {
        $result = NewsfeedBodySanitizer::toDisplayHtml("line one<br>\nline two");
        $this->assertStringNotContainsString('<br />', $result);
        $this->assertSame(2, substr_count($result, '<br>'));
    }

    // -----------------------------------------------------------------------
    // Same-tag nesting (documented limitation - see convertPairedTag()'s
    // docblock: pairing isn't stack-aware, so nesting the same tag inside
    // itself is not supported)
    // -----------------------------------------------------------------------

    public function testSameTagNestingIsNotDepthMatched(): void
    {
        // First </b> pairs with the first <b>, leaving the inner <b> and the
        // outer closing </b> both unconverted - documented, not a crash.
        $result = NewsfeedBodySanitizer::sanitize('<b>outer <b>inner</b> tail</b>');
        $this->assertStringContainsString('<b>outer ', $result);
        $this->assertStringContainsString('</b>', $result);
    }

    public function testCrossTagNestingWorksNormally(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<b>bold <i>and italic</i> text</b>');
        $this->assertSame('<b>bold <i>and italic</i> text</b>', $result);
    }

    // -----------------------------------------------------------------------
    // Legacy/pre-existing-row regression
    // -----------------------------------------------------------------------

    public function testLegacyRowWithRawScriptTagIsNeutralized(): void
    {
        $result = NewsfeedBodySanitizer::sanitize('<script>alert(document.cookie)</script>');
        $this->assertStringNotContainsString('<script>', $result);

        // Unquoted src="x" isn't extractable, so the required src is missing
        // and the whole tag stays inert - onerror is discarded as "not a
        // recognized attribute" the same way, but the literal word can still
        // appear as harmless escaped text. What matters is there's no real,
        // live <img> tag for it to be an attribute OF.
        $result = NewsfeedBodySanitizer::sanitize('<img src=x onerror="alert(1)">');
        $this->assertDoesNotMatchRegularExpression('/<img[ >]/', $result);
        $this->assertStringContainsString('&quot;', $result);
    }
}
