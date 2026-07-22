<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\ListingController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * archived_at writes to a DATETIME NOT NULL column, so unlike fl_last_updated
 * (nullable, tolerant of a loose regex-only check), a bad value here can't
 * just be silently dropped — it has to be rejected with a real error before
 * ever reaching ListingLifecycleRepo::upsert().
 */
final class ListingControllerValidateArchivedAtTest extends TestCase
{
    private ReflectionMethod $validate;

    protected function setUp(): void
    {
        $this->validate = new ReflectionMethod(ListingController::class, 'validateArchivedAt');
        $this->validate->setAccessible(true);
    }

    private function invoke(?string $input): string
    {
        return $this->validate->invoke(null, $input);
    }

    public function testBlankIsValid(): void
    {
        self::assertSame('', $this->invoke(''));
        self::assertSame('', $this->invoke(null));
    }

    public function testWellFormedDateIsValid(): void
    {
        self::assertSame('', $this->invoke('2020-06-15'));
    }

    public function testMalformedFormatIsRejected(): void
    {
        self::assertNotSame('', $this->invoke('06/15/2020'));
        self::assertNotSame('', $this->invoke('not a date'));
        self::assertNotSame('', $this->invoke('2020-6-15'));
    }

    public function testImpossibleCalendarDateIsRejectedDespiteMatchingFormat(): void
    {
        // Matches \d{4}-\d{2}-\d{2} but isn't a real date — checkdate() must catch this.
        self::assertNotSame('', $this->invoke('2026-02-30'));
        self::assertNotSame('', $this->invoke('2026-99-99'));
        self::assertNotSame('', $this->invoke('2026-13-01'));
    }

    public function testYearOutOfMySqlRangeIsRejectedEvenThoughCheckdateAllowsIt(): void
    {
        // checkdate() alone accepts years 0001-0999; MySQL strict mode can reject them.
        self::assertNotSame('', $this->invoke('0999-12-31'));
        self::assertNotSame('', $this->invoke('0001-01-01'));
    }

    public function testYearAtBoundariesIsAccepted(): void
    {
        self::assertSame('', $this->invoke('1000-01-01'));
        self::assertSame('', $this->invoke('9999-12-31'));
    }
}
