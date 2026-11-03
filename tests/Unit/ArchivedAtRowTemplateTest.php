<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Closed On / Adopted On row shared by the listing Add and Edit pages
 * (templates/admin/listings/_archived-at-row.php): when "Date unknown" starts
 * ticked, what the date shows, and what Reset goes back to.
 */
final class ArchivedAtRowTemplateTest extends TestCase
{
    /**
     * @param array<string,mixed> $old
     * @return array{checked: bool, original: string, date: string, dateOriginal: string, dateDisabled: bool, todayDisabled: bool, resetDisabled: bool, error: string}
     */
    private function row(string $savedDate, bool $savedUnknown, array $old = [], array $fieldErrors = []): array
    {
        $archivedAtOriginalVal     = $savedDate;
        $archivedAtUnknownOriginal = $savedUnknown;
        $fe                        = $fieldErrors;
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/templates/admin/listings/_archived-at-row.php';
        } finally {
            $html = (string) ob_get_clean();
        }

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        $box   = $doc->getElementById('fl-archived-at-unknown');
        $date  = $doc->getElementById('fl-archived-at');
        $today = $doc->getElementById('set-archived-today-btn');
        $reset = $doc->getElementById('reset-archived-date-btn');
        self::assertNotNull($box);
        $error = (new \DOMXPath($doc))->query('//p[@class="field-error"]')->item(0);

        return [
            'checked'       => $box->hasAttribute('checked'),
            'original'      => $box->getAttribute('data-original-checked'),
            'date'          => $date->getAttribute('value'),
            'dateOriginal'  => $date->getAttribute('data-original-value'),
            'dateDisabled'  => $date->hasAttribute('disabled'),
            'todayDisabled' => $today->hasAttribute('disabled'),
            'resetDisabled' => $reset->hasAttribute('disabled'),
            'error'         => $error !== null ? trim($error->textContent) : '',
        ];
    }

    public function testAKnownDateStartsUnticked(): void
    {
        $row = $this->row('2020-05-01', false);

        self::assertFalse($row['checked']);
        self::assertSame('0', $row['original']);
        self::assertSame(['2020-05-01', '2020-05-01'], [$row['date'], $row['dateOriginal']]);
        self::assertFalse($row['dateDisabled']);
        self::assertFalse($row['todayDisabled']);
    }

    public function testAnUnknownDateStartsTickedWithTheDateOff(): void
    {
        $row = $this->row('', true);

        self::assertTrue($row['checked']);
        self::assertSame('1', $row['original'], 'Reset goes back to ticked');
        self::assertTrue($row['dateDisabled']);
        self::assertTrue($row['todayDisabled']);
        self::assertFalse($row['resetDisabled'], 'Reset stays usable');
    }

    public function testAFailedSaveKeepsAJustTickedBox(): void
    {
        // Edit sends the box back explicitly.
        $row = $this->row('2020-05-01', false, ['archived_at' => '2020-05-01', 'archived_at_unknown' => '1']);

        self::assertTrue($row['checked']);
        self::assertSame('0', $row['original'], 'Reset still goes back to what is saved');
        self::assertTrue($row['dateDisabled']);
    }

    public function testAFailedSaveKeepsAJustUntickedBoxEvenWhenTheSavedDateIsUnknown(): void
    {
        $row = $this->row('', true, ['archived_at' => '', 'archived_at_unknown' => '0'],
            ['archived_at' => ['Enter the date, or tick Date unknown.']]);

        self::assertFalse($row['checked']);
        self::assertSame('1', $row['original']);
        self::assertFalse($row['dateDisabled']);
        self::assertSame('Enter the date, or tick Date unknown.', $row['error']);
    }

    public function testTheAddPageStartsUntickedAndKeepsATickAfterAFailedSave(): void
    {
        self::assertFalse($this->row('', false)['checked']);
        // On Add an unticked box isn't posted at all, so only a tick comes back.
        self::assertFalse($this->row('', false, ['archived_at' => '2021-01-01'])['checked']);
        self::assertTrue($this->row('', false, ['archived_at_unknown' => '1'])['checked']);
    }

    public function testAFailedSaveShowsTheDateThatWasTyped(): void
    {
        $row = $this->row('2020-05-01', false, ['archived_at' => '2021-02-30', 'archived_at_unknown' => '0']);

        self::assertSame(['2021-02-30', '2020-05-01'], [$row['date'], $row['dateOriginal']]);
    }
}
