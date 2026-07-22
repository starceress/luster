<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\ListingController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * ListingController::edit() reads previous_owner_name[]/previous_owner_url[]
 * from POST and must reject rows that exceed the DB column widths
 * (VARCHAR(255)/VARCHAR(500)) even though the form's maxlength attributes
 * already guard against this in normal browser use — a crafted POST bypasses
 * those attributes entirely. This has to be tested at this layer rather than
 * via PreviousOwnersRepo: the test suite's SQLite backend doesn't enforce
 * MySQL's VARCHAR widths, so a repo-level test would stay green even if this
 * controller-side length check were removed.
 */
final class ListingControllerValidatePreviousOwnerRowsTest extends TestCase
{
    private ReflectionMethod $validate;

    protected function setUp(): void
    {
        $this->validate = new ReflectionMethod(ListingController::class, 'validatePreviousOwnerRows');
        $this->validate->setAccessible(true);
    }

    /** @return array{0: array<int, array{owner_name: string, owner_url: string}>, 1: string} */
    private function invoke(array $names, array $urls): array
    {
        return $this->validate->invoke(null, $names, $urls);
    }

    public function testAcceptsNormalRows(): void
    {
        [$rows, $error] = $this->invoke(['Alice', 'Bob'], ['https://alice.example.com', '']);

        self::assertSame('', $error);
        self::assertSame([
            ['owner_name' => 'Alice', 'owner_url' => 'https://alice.example.com'],
            ['owner_name' => 'Bob', 'owner_url' => ''],
        ], $rows);
    }

    public function testDropsRowsWhereBothFieldsAreBlank(): void
    {
        [$rows, $error] = $this->invoke(['', '  ', 'Kept'], ['', '  ', '']);

        self::assertSame('', $error);
        self::assertSame([['owner_name' => 'Kept', 'owner_url' => '']], $rows);
    }

    public function testRejectsOverlongName(): void
    {
        $overlong = str_repeat('a', 256);

        [$rows, $error] = $this->invoke([$overlong], ['']);

        self::assertSame([], $rows);
        self::assertNotSame('', $error);
        self::assertStringContainsString('too long', $error);
    }

    public function testAcceptsNameAtExactly255Chars(): void
    {
        $atLimit = str_repeat('a', 255);

        [$rows, $error] = $this->invoke([$atLimit], ['']);

        self::assertSame('', $error);
        self::assertSame([['owner_name' => $atLimit, 'owner_url' => '']], $rows);
    }

    public function testRejectsOverlongUrl(): void
    {
        // 'https://' (8) + 493 chars = 501 total, over the 500 limit.
        $overlongUrl = 'https://' . str_repeat('a', 493) . '.com';

        [$rows, $error] = $this->invoke(['Alice'], [$overlongUrl]);

        self::assertSame([], $rows);
        self::assertNotSame('', $error);
        self::assertStringContainsString('too long', $error);
    }

    public function testRejectsMalformedUrl(): void
    {
        [$rows, $error] = $this->invoke(['Alice'], ['not a url at all']);

        self::assertSame([], $rows);
        self::assertNotSame('', $error);
        self::assertStringContainsString('valid', $error);
    }

    public function testRejectsNonHttpScheme(): void
    {
        [$rows, $error] = $this->invoke(['Alice'], ['javascript:alert(1)']);

        self::assertSame([], $rows);
        self::assertNotSame('', $error);
    }

    public function testStopsAtFirstInvalidRowAndDoesNotReturnLaterValidRows(): void
    {
        $overlong = str_repeat('a', 256);

        [$rows, $error] = $this->invoke([$overlong, 'ValidLaterRow'], ['', '']);

        self::assertSame([], $rows);
        self::assertNotSame('', $error);
    }

    public function testUrlOnlyRowIsKept(): void
    {
        [$rows, $error] = $this->invoke([''], ['https://example.com']);

        self::assertSame('', $error);
        self::assertSame([['owner_name' => '', 'owner_url' => 'https://example.com']], $rows);
    }

    public function testHttpUrlIsUpgradedToHttps(): void
    {
        // Matches ListingController::edit()'s existing url/adopted_to_url
        // handling: self::httpsify() only upgrades an explicit http:// scheme,
        // it does not add a scheme to a bare domain (see next test).
        [$rows, $error] = $this->invoke(['Alice'], ['http://example.com']);

        self::assertSame('', $error);
        self::assertSame('https://example.com', $rows[0]['owner_url']);
    }

    public function testBareDomainWithoutSchemeIsRejected(): void
    {
        [$rows, $error] = $this->invoke(['Alice'], ['example.com']);

        self::assertSame([], $rows);
        self::assertNotSame('', $error);
    }
}
