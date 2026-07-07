<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\ListingController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * "Show all" (limit 0) already shows every member matching its own
 * criteria (most recent join date) — nothing is truncated within that
 * group, so appending ", and more..." would be misleading even though
 * more approved members exist overall. Only Truncate mode (limit > 0)
 * should ever append it.
 */
final class ListingControllerFormatNewestMembersTest extends TestCase
{
    private ReflectionMethod $format;

    protected function setUp(): void
    {
        $this->format = new ReflectionMethod(ListingController::class, 'formatNewestMembers');
        $this->format->setAccessible(true);
    }

    public function testShowAllModeNeverAppendsAndMore(): void
    {
        $members = [['name' => 'NightcoreAndCaffeine', 'url' => '']];

        $result = $this->format->invoke(null, $members, 16, 0);

        $this->assertSame('NightcoreAndCaffeine', $result);
        $this->assertStringNotContainsString('and more', $result);
    }

    public function testTruncateModeAppendsAndMoreWhenLimitCutsMembersOff(): void
    {
        $members = [
            ['name' => 'Alice', 'url' => ''],
            ['name' => 'Bob',   'url' => ''],
        ];

        $result = $this->format->invoke(null, $members, 16, 5);

        $this->assertStringContainsString('and more', $result);
    }

    public function testTruncateModeOmitsAndMoreWhenNothingIsCutOff(): void
    {
        $members = [
            ['name' => 'Alice', 'url' => ''],
            ['name' => 'Bob',   'url' => ''],
        ];

        $result = $this->format->invoke(null, $members, 2, 5);

        $this->assertStringNotContainsString('and more', $result);
        $this->assertSame('Alice and Bob', $result);
    }
}
