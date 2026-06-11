<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\FormTemplateRenderer;
use PHPUnit\Framework\TestCase;

final class FormTemplateRendererTest extends TestCase
{
    // -----------------------------------------------------------------------
    // expandTokens()
    // -----------------------------------------------------------------------

    public function testExpandTokensReplacesAllTokens(): void
    {
        $html = FormTemplateRenderer::expandTokens(
            '<p>{field_name} {field_email}</p>',
            [
                '{field_name}'  => '<input name="name">',
                '{field_email}' => '<input name="email">',
            ],
        );

        self::assertSame('<p><input name="name"> <input name="email"></p>', $html);
    }

    public function testExpandTokensReplacesRepeatedOccurrences(): void
    {
        $html = FormTemplateRenderer::expandTokens(
            '{btn} ... {btn}',
            ['{btn}' => '<button>Go</button>'],
        );

        self::assertSame('<button>Go</button> ... <button>Go</button>', $html);
    }

    public function testExpandTokensLeavesUnknownTokensVisible(): void
    {
        $html = FormTemplateRenderer::expandTokens(
            '<p>{field_name} {field_unknown}</p>',
            ['{field_name}' => '<input name="name">'],
        );

        self::assertSame('<p><input name="name"> {field_unknown}</p>', $html);
    }

    public function testExpandTokensWithEmptyTokenMapReturnsTemplateUnchanged(): void
    {
        $html = FormTemplateRenderer::expandTokens('<p>{field_name}</p>', []);

        self::assertSame('<p>{field_name}</p>', $html);
    }

    public function testExpandTokensDoesNotReSubstituteTokenStringsInsideReplacementValues(): void
    {
        // {field_name} expands to HTML containing the literal text "{submit_button}".
        // A naive sequential str_replace() would then replace that literal text
        // too, corrupting the field's HTML. strtr() must not do this.
        $html = FormTemplateRenderer::expandTokens(
            '{field_name}{submit_button}',
            [
                '{field_name}'    => '<input value="{submit_button}">',
                '{submit_button}' => '<button>Go</button>',
            ],
        );

        self::assertSame('<input value="{submit_button}"><button>Go</button>', $html);
    }

    // -----------------------------------------------------------------------
    // wrapForm()
    // -----------------------------------------------------------------------

    public function testWrapFormProducesPostFormWithAction(): void
    {
        $html = FormTemplateRenderer::wrapForm('<p>inner</p>', '/join/1', 'tok123', false);

        self::assertStringContainsString('<form class="fl-form" method="POST" action="/join/1">', $html);
        self::assertStringContainsString('<p>inner</p>', $html);
        self::assertStringEndsWith('</form>', $html);
    }

    public function testWrapFormIncludesCsrfHiddenField(): void
    {
        $html = FormTemplateRenderer::wrapForm('<p>inner</p>', '/join/1', 'tok123', false);

        self::assertStringContainsString('<input type="hidden" name="_csrf" value="tok123">', $html);
    }

    public function testWrapFormIncludesHoneypotField(): void
    {
        $html = FormTemplateRenderer::wrapForm('<p>inner</p>', '/join/1', 'tok123', false);

        self::assertStringContainsString('<div style="display:none" aria-hidden="true">', $html);
        self::assertStringContainsString('<input type="text" name="website_confirm" tabindex="-1" autocomplete="off">', $html);
    }

    public function testWrapFormOmitsEnctypeWhenNotMultipart(): void
    {
        $html = FormTemplateRenderer::wrapForm('<p>inner</p>', '/join/1', 'tok123', false);

        self::assertStringNotContainsString('enctype', $html);
    }

    public function testWrapFormAddsMultipartEnctypeWhenRequested(): void
    {
        $html = FormTemplateRenderer::wrapForm('<p>inner</p>', '/codes-submit/1', 'tok123', true);

        self::assertStringContainsString('<form class="fl-form" method="POST" action="/codes-submit/1" enctype="multipart/form-data">', $html);
    }

    public function testWrapFormEscapesActionUrl(): void
    {
        $html = FormTemplateRenderer::wrapForm('<p>inner</p>', '/join/1?ref=a&b', 'tok123', false);

        self::assertStringContainsString('action="/join/1?ref=a&amp;b"', $html);
    }

    public function testWrapFormEscapesCsrfToken(): void
    {
        $html = FormTemplateRenderer::wrapForm('<p>inner</p>', '/join/1', 'a"b<c>', false);

        self::assertStringContainsString('value="a&quot;b&lt;c&gt;"', $html);
    }
}
