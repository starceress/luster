<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\MemberChangeList;
use PHPUnit\Framework\TestCase;

final class MemberChangeListTest extends TestCase
{
    public function testEachChangeIsALineWithItsLabel(): void
    {
        self::assertSame(
            ['Name: Ann -> Anne', 'Email: a@x.test -> b@x.test', 'Website: https://a.test/ -> https://b.test/', 'Country: Canada -> France'],
            MemberChangeList::lines(
                ['name' => 'Anne', 'email' => 'b@x.test', 'url' => 'https://b.test/', 'country' => 'France'],
                ['name' => 'Ann', 'email' => 'a@x.test', 'url' => 'https://a.test/', 'country' => 'Canada'],
            ),
        );
    }

    public function testBlankValuesAreSpelledOut(): void
    {
        self::assertSame(
            ['Website: (blank) -> https://b.test/', 'Name: Ann -> (blank)', 'Country: (blank) -> France'],
            MemberChangeList::lines(
                ['url' => 'https://b.test/', 'name' => '', 'country' => 'France'],
                ['url' => '', 'name' => 'Ann'],
            ),
        );
    }

    public function testAnAdditionalFieldUsesItsLabelOrItsColumnWithoutOne(): void
    {
        self::assertSame(
            ['Favourite Pet: Dog -> Cat', 'additional_colour: (blank) -> Blue'],
            MemberChangeList::lines(
                ['additional_pet' => 'Cat', 'additional_colour' => 'Blue'],
                ['additional_pet' => 'Dog'],
                ['additional_pet' => 'Favourite Pet'],
            ),
        );
    }

    public function testAPasswordIsNeverWrittenOutEvenIfItsInTheChanges(): void
    {
        $lines = MemberChangeList::lines(['password' => '$argon2id$secret', 'name' => 'Anne'], ['password' => 'old-hash', 'name' => 'Ann'], [], true);

        self::assertSame(['Name: Ann -> Anne', 'Password: changed'], $lines);
        self::assertStringNotContainsString('argon', implode("\n", $lines));
        self::assertStringNotContainsString('old-hash', implode("\n", $lines));
    }

    public function testASwitchThatFollowsFromAnotherChangeIsLeftOut(): void
    {
        self::assertSame([], MemberChangeList::lines(['showurl' => 0, 'showemail' => 1], ['showurl' => 1, 'showemail' => 0]));
    }

    public function testOnlyQueuedChangesSayTheyreWaiting(): void
    {
        self::assertSame(
            ['Name: Ann -> Anne (waiting for your approval)', 'Password: changed'],
            MemberChangeList::lines(['name' => 'Anne'], ['name' => 'Ann'], [], true, true),
        );
    }

    public function testNothingChangedIsNoLines(): void
    {
        self::assertSame([], MemberChangeList::lines([], ['name' => 'Ann']));
    }

    public function testTextForOneListing(): void
    {
        self::assertSame("  Name: Ann -> Anne\n  Password: changed", MemberChangeList::forOneListing(['Name: Ann -> Anne', 'Password: changed']));
    }

    public function testTextForSeveralListings(): void
    {
        self::assertSame(
            "- Moon\n    Name: Ann -> Anne\n- Sun\n    Removed their membership",
            MemberChangeList::forSeveralListings([
                ['heading' => 'Moon', 'lines' => ['Name: Ann -> Anne']],
                ['heading' => 'Sun', 'lines' => [MemberChangeList::REMOVED_MEMBERSHIP]],
            ]),
        );
    }
}
