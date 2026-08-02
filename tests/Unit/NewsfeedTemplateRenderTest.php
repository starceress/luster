<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Addons\Newsfeed\NewsfeedEntry;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Support\SampleTemplateData;
use Luster\Support\NewsfeedTemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NewsfeedTemplateRenderer::render(), which powers admin template
 * previews (per-listing and global) and the newsfeed embed.
 */
final class NewsfeedTemplateRenderTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private static function render(array $entries, string $tpl): string
    {
        return NewsfeedTemplateRenderer::render($entries, $tpl);
    }

    // -----------------------------------------------------------------------
    // Token substitution — sample data
    // -----------------------------------------------------------------------

    public function testSampleDataRendersAllTitles(): void
    {
        $result = self::render(
            SampleTemplateData::updates(),
            '{each_newsfeed_entry}{newsfeed_title}|{/each_newsfeed_entry}',
        );

        self::assertStringContainsString('Site Relaunch', $result);
        self::assertStringContainsString('New Members Welcome', $result);
        self::assertStringContainsString('Affiliates Updated', $result);
    }

    public function testSampleDataRendersDateToken(): void
    {
        // SampleTemplateData::updates() posts 2026-06-10 — formatted "June 10, 2026"
        $result = self::render(
            SampleTemplateData::updates(),
            '{each_newsfeed_entry}{newsfeed_date}|{/each_newsfeed_entry}',
        );

        self::assertStringContainsString('June 10, 2026', $result);
    }

    public function testSampleDataRendersBodyHtml(): void
    {
        $result = self::render(
            SampleTemplateData::updates(),
            '{each_newsfeed_entry}{newsfeed_body_html}{/each_newsfeed_entry}',
        );

        self::assertStringContainsString('Crystal Tokyo', $result);
        self::assertStringNotContainsString('{newsfeed_body_html}', $result);
    }

    // -----------------------------------------------------------------------
    // {if_newsfeed} / {if_no_newsfeed} page-level conditionals
    // -----------------------------------------------------------------------

    public function testIfNewsfeedBlockKeptWhenDataPresent(): void
    {
        $result = self::render(SampleTemplateData::updates(), '{if_newsfeed}HAS_ENTRIES{/if_newsfeed}');

        self::assertSame('HAS_ENTRIES', $result);
    }

    public function testIfNoNewsfeedBlockStrippedWhenDataPresent(): void
    {
        $result = self::render(SampleTemplateData::updates(), '{if_no_newsfeed}EMPTY{/if_no_newsfeed}');

        self::assertSame('', $result);
    }

    public function testIfNoNewsfeedBlockKeptWhenEmpty(): void
    {
        $result = self::render([], '{if_no_newsfeed}EMPTY{/if_no_newsfeed}');

        self::assertSame('EMPTY', $result);
    }

    public function testIfNewsfeedBlockStrippedWhenEmpty(): void
    {
        $result = self::render([], '{if_newsfeed}HAS_ENTRIES{/if_newsfeed}');

        self::assertSame('', $result);
    }

    // -----------------------------------------------------------------------
    // Empty-state fallback: loop-only template returns '' for empty array
    // This is the signal that triggers the "No entries posted yet." message
    // in ltplPreviewUpdates() and gtplPreviewUpdates().
    // -----------------------------------------------------------------------

    public function testEmptyArrayWithLoopOnlyReturnsEmptyString(): void
    {
        $result = self::render([], '{each_newsfeed_entry}{newsfeed_title}{/each_newsfeed_entry}');

        self::assertSame('', $result);
    }

    // -----------------------------------------------------------------------
    // {if_author} per-row conditional
    // -----------------------------------------------------------------------

    public function testAuthorConditionalPresentWhenAuthorSet(): void
    {
        // SampleTemplateData Update 1 and 3 both have author='Admin'
        $result = self::render(
            SampleTemplateData::updates(),
            '{each_newsfeed_entry}{if_author}BY:{newsfeed_author}{/if_author}|{/each_newsfeed_entry}',
        );

        self::assertStringContainsString('BY:Admin', $result);
    }

    public function testAuthorConditionalStrippedWhenAuthorEmpty(): void
    {
        $entries = [new NewsfeedEntry(1, null, 'T', 'B', 'News', '', '2026-01-01', '2026-01-01 00:00:00')];

        $result = self::render($entries, '{each_newsfeed_entry}{if_author}HAS_AUTHOR{/if_author}|{/each_newsfeed_entry}');

        self::assertSame('|', $result);
    }

    // -----------------------------------------------------------------------
    // {if_categories} per-row conditional
    // -----------------------------------------------------------------------

    public function testCategoriesConditionalPresentWhenSet(): void
    {
        // SampleTemplateData Update 1 has categories 'News, Site'
        $result = self::render(
            SampleTemplateData::updates(),
            '{each_newsfeed_entry}{if_categories}CATS:{newsfeed_categories}|{/if_categories}{/each_newsfeed_entry}',
        );

        self::assertStringContainsString('CATS:News, Site|', $result);
    }

    public function testCategoriesConditionalStrippedWhenEmpty(): void
    {
        $entries = [new NewsfeedEntry(1, null, 'T', 'B', '', 'Admin', '2026-01-01', '2026-01-01 00:00:00')];

        $result = self::render($entries, '{each_newsfeed_entry}{if_categories}HAS_CATS{/if_categories}|{/each_newsfeed_entry}');

        self::assertSame('|', $result);
    }

    // -----------------------------------------------------------------------
    // Default template — end-to-end round-trips
    // -----------------------------------------------------------------------

    public function testDefaultTemplateRendersWithSampleData(): void
    {
        $tpl    = ListingSettingsRepo::defaultNewsfeedTemplate();
        $result = self::render(SampleTemplateData::updates(), $tpl);

        self::assertStringContainsString('fl-newsfeed-item', $result);
        self::assertStringContainsString('Site Relaunch', $result);
        self::assertStringNotContainsString('{newsfeed_title}', $result);
        self::assertStringNotContainsString('{if_newsfeed}', $result);
        self::assertStringNotContainsString('{if_no_newsfeed}', $result);
        self::assertStringNotContainsString('{each_newsfeed_entry}', $result);
    }

    public function testDefaultTemplateEmptyStateShowsNoEntriesMessage(): void
    {
        $tpl    = ListingSettingsRepo::defaultNewsfeedTemplate();
        $result = self::render([], $tpl);

        self::assertStringContainsString('No entries yet.', $result);
        self::assertStringNotContainsString('fl-newsfeed-item', $result);
        self::assertStringNotContainsString('{if_newsfeed}', $result);
        self::assertStringNotContainsString('{if_no_newsfeed}', $result);
    }

    public function testDefaultTemplateNoUnreplacedTokensWithSampleData(): void
    {
        $tpl    = ListingSettingsRepo::defaultNewsfeedTemplate();
        $result = self::render(SampleTemplateData::updates(), $tpl);

        self::assertStringNotContainsString('{newsfeed_', $result);
        self::assertStringNotContainsString('{if_', $result);
        self::assertStringNotContainsString('{/if_', $result);
        self::assertStringNotContainsString('{each_', $result);
    }

    // -----------------------------------------------------------------------
    // Allowed inline HTML formatting (NewsfeedBodySanitizer integration)
    // -----------------------------------------------------------------------

    public function testAllowedTagsRenderThroughBodyHtmlToken(): void
    {
        $entries = [new NewsfeedEntry(
            1,
            null,
            'Formatting test',
            'We are <b>thrilled</b> to announce a <a href="https://example.com">new affiliate</a>!',
            '',
            'Admin',
            '2026-01-01',
            '2026-01-01 00:00:00',
        )];

        $result = self::render($entries, '{each_newsfeed_entry}{newsfeed_body_html}{/each_newsfeed_entry}');

        self::assertStringContainsString('<b>thrilled</b>', $result);
        self::assertStringContainsString(
            '<a href="https://example.com" target="_blank" rel="noopener noreferrer">new affiliate</a>',
            $result,
        );
    }

    public function testTemplateTokenImmunityThroughFullRendererPipeline(): void
    {
        // A body containing literal template-syntax-shaped text must render
        // as inert literal text, never as a live substitution/conditional -
        // conditionals() runs before loop() in NewsfeedTemplateRenderer, so
        // the entry body can never influence the surrounding template's own
        // {if_x}/{each_x} syntax.
        $entries = [new NewsfeedEntry(
            1,
            null,
            'Injection attempt',
            'Contains literal {newsfeed_title} and {if_author}text{/if_author} as plain text.',
            '',
            '',
            '2026-01-01',
            '2026-01-01 00:00:00',
        )];

        $result = self::render(
            $entries,
            '{each_newsfeed_entry}{if_author}SHOULD_NOT_APPEAR{/if_author}{newsfeed_body_html}{/each_newsfeed_entry}',
        );

        self::assertStringNotContainsString('SHOULD_NOT_APPEAR', $result);
        self::assertStringContainsString('{newsfeed_title}', $result);
        self::assertStringContainsString('{if_author}text{/if_author}', $result);
    }

    public function testLegacyContentWithRawScriptBodyIsNeutralized(): void
    {
        // Simulates a row that existed before this feature shipped, or was
        // written by one of the importers that bypass the admin controller -
        // both cases pass raw, never-sanitized text straight into NewsfeedEntry.
        $entries = [new NewsfeedEntry(
            1,
            null,
            'Legacy row',
            '<script>alert(document.cookie)</script>',
            '',
            '',
            '2026-01-01',
            '2026-01-01 00:00:00',
        )];

        $result = self::render($entries, '{each_newsfeed_entry}{newsfeed_body_html}{/each_newsfeed_entry}');

        self::assertStringNotContainsString('<script>', $result);
    }
}
