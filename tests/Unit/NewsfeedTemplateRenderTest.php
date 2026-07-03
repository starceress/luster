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
            '{each_update}{update_title}|{/each_update}',
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
            '{each_update}{update_date}|{/each_update}',
        );

        self::assertStringContainsString('June 10, 2026', $result);
    }

    public function testSampleDataRendersBodyHtml(): void
    {
        $result = self::render(
            SampleTemplateData::updates(),
            '{each_update}{update_body_html}{/each_update}',
        );

        self::assertStringContainsString('Crystal Tokyo', $result);
        self::assertStringNotContainsString('{update_body_html}', $result);
    }

    // -----------------------------------------------------------------------
    // {if_updates} / {if_no_updates} page-level conditionals
    // -----------------------------------------------------------------------

    public function testIfUpdatesBlockKeptWhenDataPresent(): void
    {
        $result = self::render(SampleTemplateData::updates(), '{if_updates}HAS_UPDATES{/if_updates}');

        self::assertSame('HAS_UPDATES', $result);
    }

    public function testIfNoUpdatesBlockStrippedWhenDataPresent(): void
    {
        $result = self::render(SampleTemplateData::updates(), '{if_no_updates}EMPTY{/if_no_updates}');

        self::assertSame('', $result);
    }

    public function testIfNoUpdatesBlockKeptWhenEmpty(): void
    {
        $result = self::render([], '{if_no_updates}EMPTY{/if_no_updates}');

        self::assertSame('EMPTY', $result);
    }

    public function testIfUpdatesBlockStrippedWhenEmpty(): void
    {
        $result = self::render([], '{if_updates}HAS_UPDATES{/if_updates}');

        self::assertSame('', $result);
    }

    // -----------------------------------------------------------------------
    // Empty-state fallback: loop-only template returns '' for empty array
    // This is the signal that triggers the "No entries posted yet." message
    // in ltplPreviewUpdates() and gtplPreviewUpdates().
    // -----------------------------------------------------------------------

    public function testEmptyArrayWithLoopOnlyReturnsEmptyString(): void
    {
        $result = self::render([], '{each_update}{update_title}{/each_update}');

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
            '{each_update}{if_author}BY:{update_author}{/if_author}|{/each_update}',
        );

        self::assertStringContainsString('BY:Admin', $result);
    }

    public function testAuthorConditionalStrippedWhenAuthorEmpty(): void
    {
        $entries = [new NewsfeedEntry(1, null, 'T', 'B', 'News', '', '2026-01-01', '2026-01-01 00:00:00')];

        $result = self::render($entries, '{each_update}{if_author}HAS_AUTHOR{/if_author}|{/each_update}');

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
            '{each_update}{if_categories}CATS:{update_categories}|{/if_categories}{/each_update}',
        );

        self::assertStringContainsString('CATS:News, Site|', $result);
    }

    public function testCategoriesConditionalStrippedWhenEmpty(): void
    {
        $entries = [new NewsfeedEntry(1, null, 'T', 'B', '', 'Admin', '2026-01-01', '2026-01-01 00:00:00')];

        $result = self::render($entries, '{each_update}{if_categories}HAS_CATS{/if_categories}|{/each_update}');

        self::assertSame('|', $result);
    }

    // -----------------------------------------------------------------------
    // Default template — end-to-end round-trips
    // -----------------------------------------------------------------------

    public function testDefaultTemplateRendersWithSampleData(): void
    {
        $tpl    = ListingSettingsRepo::defaultNewsfeedTemplate();
        $result = self::render(SampleTemplateData::updates(), $tpl);

        self::assertStringContainsString('fl-update-item', $result);
        self::assertStringContainsString('Site Relaunch', $result);
        self::assertStringNotContainsString('{update_title}', $result);
        self::assertStringNotContainsString('{if_updates}', $result);
        self::assertStringNotContainsString('{if_no_updates}', $result);
        self::assertStringNotContainsString('{each_update}', $result);
    }

    public function testDefaultTemplateEmptyStateShowsNoUpdatesMessage(): void
    {
        $tpl    = ListingSettingsRepo::defaultNewsfeedTemplate();
        $result = self::render([], $tpl);

        self::assertStringContainsString('No updates yet.', $result);
        self::assertStringNotContainsString('fl-update-item', $result);
        self::assertStringNotContainsString('{if_updates}', $result);
        self::assertStringNotContainsString('{if_no_updates}', $result);
    }

    public function testDefaultTemplateNoUnreplacedTokensWithSampleData(): void
    {
        $tpl    = ListingSettingsRepo::defaultNewsfeedTemplate();
        $result = self::render(SampleTemplateData::updates(), $tpl);

        self::assertStringNotContainsString('{update_', $result);
        self::assertStringNotContainsString('{if_', $result);
        self::assertStringNotContainsString('{/if_', $result);
        self::assertStringNotContainsString('{each_', $result);
    }
}
