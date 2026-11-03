<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\ListingController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * What the listing Add and Edit pages save as a Closed On / Adopted On date
 * while archiving, from the date field and the "Date unknown" box: every row
 * of the agreed table, on Add (no lifecycle row yet) and on Edit (a stored
 * date, or a stored unknown one). The value goes to
 * ListingLifecycleRepo::upsert(): null = unknown, '' = keep what's stored
 * (today on a first archive), or a date.
 */
final class ListingControllerResolveArchivedAtTest extends TestCase
{
    private const KNOWN   = ['archived_at' => '2020-05-01 00:00:00'];
    private const UNKNOWN = ['archived_at' => null];

    /**
     * @param array<string,mixed>|null $row
     * @return array{0: string|null, 1: string}
     */
    private function resolve(string $date, bool $unknown, ?array $row, bool $canStoreUnknown = true): array
    {
        return (new ReflectionMethod(ListingController::class, 'resolveArchivedAt'))
            ->invoke(null, $date, $unknown, $row, $canStoreUnknown);
    }

    /** @return array<string, array{0: array<string,mixed>|null}> */
    public static function everyStoredState(): array
    {
        return ['Add' => [null], 'Edit, date known' => [self::KNOWN], 'Edit, date unknown' => [self::UNKNOWN]];
    }

    /**
     * @dataProvider everyStoredState
     * @param array<string,mixed>|null $row
     */
    public function testTickedStoresUnknownWhateverTheDateSays(?array $row): void
    {
        self::assertSame([null, ''], $this->resolve('', true, $row));
        self::assertSame([null, ''], $this->resolve('2021-07-04', true, $row), 'A typed date is ignored');
        self::assertSame([null, ''], $this->resolve('not a date', true, $row), 'and so is a bad one');
    }

    /**
     * @dataProvider everyStoredState
     * @param array<string,mixed>|null $row
     */
    public function testUntickedWithADateStoresThatDate(?array $row): void
    {
        self::assertSame(['2021-07-04', ''], $this->resolve('2021-07-04', false, $row));
    }

    /**
     * @dataProvider everyStoredState
     * @param array<string,mixed>|null $row
     */
    public function testUntickedWithABadDateIsAnError(?array $row): void
    {
        self::assertSame(['', 'Please enter a valid date (YYYY-MM-DD).'], $this->resolve('2021-02-30', false, $row));
    }

    public function testUntickedAndBlankOnAFirstArchiveKeepsTheDefault(): void
    {
        self::assertSame(['', ''], $this->resolve('', false, null), "'' means today on a first archive");
    }

    public function testUntickedAndBlankWithAKnownDateKeepsIt(): void
    {
        self::assertSame(['', ''], $this->resolve('', false, self::KNOWN), "'' keeps the stored date");
    }

    public function testUntickedAndBlankWithAnUnknownDateAsksForOne(): void
    {
        self::assertSame(['', 'Enter the date, or tick Date unknown.'], $this->resolve('', false, self::UNKNOWN));
    }

    /**
     * @dataProvider everyStoredState
     * @param array<string,mixed>|null $row
     */
    public function testTickingBeforeMigration108IsAnError(?array $row): void
    {
        [$value, $error] = $this->resolve('', true, $row, false);

        self::assertSame('', $value);
        self::assertStringContainsString('Run the migration page first (Luster needs Migration 108)', $error);
    }

    public function testUntickedSavesDontNeedMigration108(): void
    {
        self::assertSame(['2021-07-04', ''], $this->resolve('2021-07-04', false, null, false));
        self::assertSame(['', ''], $this->resolve('', false, self::KNOWN, false));
    }
}
