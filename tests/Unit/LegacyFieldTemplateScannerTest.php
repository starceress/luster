<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\LegacyFieldTemplateScanner;
use PHPUnit\Framework\TestCase;

/**
 * Enthusiast 3's unprefixed schema never stored an additional field's real
 * type or option list in any database — only in a hand-written per-listing
 * PHP include (the addform.inc.php convention) the site owner wrote
 * themselves. This scanner reads that file as inert text (never executing
 * it) and looks for <select>/<option>, radio, and checkbox markup so an
 * admin can quickly re-type an imported field instead of retyping options
 * by hand from scratch.
 */
final class LegacyFieldTemplateScannerTest extends TestCase
{
    private function scanner(): LegacyFieldTemplateScanner
    {
        return new LegacyFieldTemplateScanner();
    }

    public function testDetectsDropdownFromRealAddformFixture(): void
    {
        // Mirrors the actual addform.inc.php this feature exists for.
        $raw = <<<'FIXTURE'
Which movie is your favorite?
<select name="enth_<?= $field ?>">
        <option value="">Choose ---</option>
    <option value="John Wick">John Wick</option>
    <option value="John Wick: Chapter 2">John Wick: Chapter 2</option>
    <option value="John Wick: Chapter 3 - Parabellum">John Wick: Chapter 3 - Parabellum</option>
    <option value="John Wick: Chapter 4">John Wick: Chapter 4</option>
    <option value="All of them">All of them</option>
</select>
FIXTURE;

        $candidates = $this->scanner()->scan($raw);

        self::assertCount(1, $candidates);
        self::assertSame('dropdown', $candidates[0]['type']);
        self::assertSame('Which movie is your favorite?', $candidates[0]['label']);
        self::assertSame([
            'John Wick',
            'John Wick: Chapter 2',
            'John Wick: Chapter 3 - Parabellum',
            'John Wick: Chapter 4',
            'All of them',
        ], $candidates[0]['options'], 'The blank placeholder option must be excluded.');
    }

    public function testDetectsRadioGroup(): void
    {
        $raw = <<<'FIXTURE'
Pick your favorite era:
<input type="radio" name="era" value="Classic"> Classic
<input type="radio" name="era" value="Modern"> Modern
<input type="radio" name="era" value="Future"> Future
FIXTURE;

        $candidates = $this->scanner()->scan($raw);

        self::assertCount(1, $candidates);
        self::assertSame('radio', $candidates[0]['type']);
        self::assertSame(['Classic', 'Modern', 'Future'], $candidates[0]['options']);
    }

    public function testDetectsCheckbox(): void
    {
        $raw = <<<'FIXTURE'
<input type="checkbox" name="newsletter" value="1"> Sign me up for updates
FIXTURE;

        $candidates = $this->scanner()->scan($raw);

        self::assertCount(1, $candidates);
        self::assertSame('checkbox', $candidates[0]['type']);
        self::assertSame([], $candidates[0]['options']);
    }

    public function testPlainTextFileWithNoFormMarkupReturnsEmptyArray(): void
    {
        self::assertSame([], $this->scanner()->scan('Just a plain welcome message, no form fields here at all.'));
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], $this->scanner()->scan(''));
    }

    public function testMalformedUnclosedHtmlDoesNotThrow(): void
    {
        $raw = '<select name="x"><option value="A">A<option value="B">B';

        $candidates = $this->scanner()->scan($raw);

        self::assertNotEmpty($candidates);
        self::assertSame('dropdown', $candidates[0]['type']);
    }

    /**
     * The uploaded file is never executed, included, or eval'd — only ever
     * scanned as inert text. This proves an embedded PHP block (even one
     * attempting something clearly hostile) never runs and never leaks into
     * the returned label/options: the whole block is discarded as plain text
     * before any parsing happens. The fixture below uses an inert, made-up
     * function name rather than a real dangerous builtin, since a literal
     * shell-execution one-liner is itself a malware signature that can get
     * this very test file flagged/quarantined by antivirus software — this
     * happened to an earlier draft of the accompanying plan document.
     */
    public function testEmbeddedPhpBlockIsNeverExecutedOrLeaked(): void
    {
        $raw = <<<'FIXTURE'
Pick a color:
<?php totally_made_up_dangerous_sounding_call($_GET['x']); ?>
<select name="color">
    <option value="Red">Red</option>
    <option value="Blue">Blue</option>
</select>
FIXTURE;

        $candidates = $this->scanner()->scan($raw);

        self::assertCount(1, $candidates);
        self::assertSame('dropdown', $candidates[0]['type']);
        self::assertSame(['Red', 'Blue'], $candidates[0]['options']);

        $flat = $candidates[0]['label'] . ' ' . implode(' ', $candidates[0]['options']) . ' ' . $candidates[0]['snippet'];
        self::assertStringNotContainsString('totally_made_up_dangerous_sounding_call', $flat);
        self::assertStringNotContainsString('<?php', $flat);
        self::assertStringNotContainsString('_GET', $flat);
    }
}
