<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\DateFormatter;
use PHPUnit\Framework\TestCase;

final class DateFormatterTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Ordinal suffixes
    // -----------------------------------------------------------------------

    public function testFirstDayGetsStSuffix(): void
    {
        self::assertSame('March 1st 2026', DateFormatter::format('2026-03-01'));
    }

    public function testSecondDayGetsNdSuffix(): void
    {
        self::assertSame('March 2nd 2026', DateFormatter::format('2026-03-02'));
    }

    public function testThirdDayGetsRdSuffix(): void
    {
        self::assertSame('March 3rd 2026', DateFormatter::format('2026-03-03'));
    }

    public function testFourthDayGetsThSuffix(): void
    {
        self::assertSame('March 4th 2026', DateFormatter::format('2026-03-04'));
    }

    public function testEleventhTwelfthThirteenthGetThSuffix(): void
    {
        self::assertSame('March 11th 2026', DateFormatter::format('2026-03-11'));
        self::assertSame('March 12th 2026', DateFormatter::format('2026-03-12'));
        self::assertSame('March 13th 2026', DateFormatter::format('2026-03-13'));
    }

    public function testTwentyFirstGetsStSuffix(): void
    {
        self::assertSame('March 21st 2026', DateFormatter::format('2026-03-21'));
    }

    public function testThirtyFirstGetsStSuffix(): void
    {
        self::assertSame('January 31st 2026', DateFormatter::format('2026-01-31'));
    }

    // -----------------------------------------------------------------------
    // Datetime input
    // -----------------------------------------------------------------------

    public function testDatetimeStringIsFormatted(): void
    {
        self::assertSame('June 15th 2026', DateFormatter::format('2026-06-15 14:30:00'));
    }

    // -----------------------------------------------------------------------
    // Unparseable input
    // -----------------------------------------------------------------------

    public function testUnparseableStringIsReturnedUnchanged(): void
    {
        self::assertSame('not a date', DateFormatter::format('not a date'));
    }

    public function testEmptyStringIsReturnedUnchanged(): void
    {
        self::assertSame('', DateFormatter::format(''));
    }
}
