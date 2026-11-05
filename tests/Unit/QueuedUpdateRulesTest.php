<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\Member;
use Luster\Repositories\PendingUpdateRepo;
use Luster\Support\QueuedUpdateApplier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules an approval follows, which read and write nothing: what a queued request
 * may change, which values are acceptable, and what makes a request stop for the owner.
 */
final class QueuedUpdateRulesTest extends TestCase
{
    private const COLUMNS = ['email', 'name', 'country', 'url', 'pending', 'password', 'showemail', 'showurl', 'added', 'fave', 'additional_name'];

    // --- What a request may change

    public function testSupportedCoreKeysAreKeptAndEveryOtherCoreColumnIsDropped(): void
    {
        $allowed = QueuedUpdateApplier::allowedChanges([
            'name' => 'N', 'email' => 'e@x.test', 'url' => 'https://u.test', 'country' => 'Peru', 'showurl' => 1,
            'pending' => 0, 'password' => 'plain', 'added' => '2000-01-01', 'showemail' => 0,
        ], self::COLUMNS, []);

        self::assertSame(['name' => 'N', 'email' => 'e@x.test', 'url' => 'https://u.test', 'country' => 'Peru', 'showurl' => 1], $allowed);
    }

    public function testAQueuedNameIsAlwaysTheMembersNameNeverAField(): void
    {
        $allowed = QueuedUpdateApplier::allowedChanges(['name' => 'Real Name'], self::COLUMNS, ['name' => 'additional_name']);

        self::assertSame(['name' => 'Real Name'], $allowed);
    }

    public function testACoreWordNeverReachesAFieldColumnEvenIfADefinitionHasThatName(): void
    {
        $allowed = QueuedUpdateApplier::allowedChanges(['password' => 'x', 'pending' => '0'], self::COLUMNS, ['password' => 'additional_password', 'pending' => 'additional_pending']);

        self::assertSame([], $allowed);
    }

    public function testAFieldColumnIsMatchedIgnoringCapitalsAndGetsItsRealSpelling(): void
    {
        $allowed = QueuedUpdateApplier::allowedChanges(['FAVE' => 'x'], self::COLUMNS, ['fave' => 'fave']);

        self::assertSame(['fave' => 'x'], $allowed);
    }

    public function testAFieldColumnWithCapitalsInItsRealNameIsStillMatched(): void
    {
        $allowed = QueuedUpdateApplier::allowedChanges(['additional_fave' => 'x'], ['email', 'Additional_Fave'], ['fave' => 'Additional_Fave']);

        self::assertSame(['Additional_Fave' => 'x'], $allowed);
    }

    public function testARowQueuedUnderTheDefinitionsOwnNameKeepsItsAnswer(): void
    {
        // The request says "fave", and on this table that field's column is additional_fave.
        $allowed = QueuedUpdateApplier::allowedChanges(['fave' => 'x'], ['email', 'additional_fave'], ['fave' => 'additional_fave']);

        self::assertSame(['additional_fave' => 'x'], $allowed);
    }

    public function testAKeyNamingTheColumnBeatsOneOnlyNamingTheField(): void
    {
        $allowed = QueuedUpdateApplier::allowedChanges(['fave' => 'by name', 'additional_fave' => 'by column'], ['email', 'additional_fave'], ['fave' => 'additional_fave']);

        self::assertSame(['additional_fave' => 'by column'], $allowed);
    }

    public function testAFieldDeletedSinceItWasQueuedOrOneWithNowhereSafeIsDropped(): void
    {
        $allowed = QueuedUpdateApplier::allowedChanges(['gone' => 'x', 'nowhere' => 'y', 'fave' => 'z'], self::COLUMNS, ['nowhere' => null]);

        self::assertSame([], $allowed);
    }

    public function testACoreColumnTheTableDoesNotHaveIsDropped(): void
    {
        $allowed = QueuedUpdateApplier::allowedChanges(['showurl' => 1, 'name' => 'N'], ['email', 'name'], []);

        self::assertSame(['name' => 'N'], $allowed);
    }

    public function testValuesEqualToTheCurrentRowAreDropped(): void
    {
        $left = QueuedUpdateApplier::withoutUnchanged(
            ['name' => 'Same', 'showurl' => 1, 'url' => '', 'country' => 'Peru'],
            ['name' => 'Same', 'showurl' => '1', 'url' => null, 'country' => 'Canada'],
        );

        self::assertSame(['country' => 'Peru'], $left);
    }

    public function testChangingOnlyTheCapitalsIsARealChange(): void
    {
        self::assertSame(['email' => 'Fan@x.test'], QueuedUpdateApplier::withoutUnchanged(['email' => 'Fan@x.test'], ['email' => 'fan@x.test']));
    }

    // --- Which values are acceptable

    /** @return array<string, array{0: array<string, string|int>, 1: string}> */
    public static function badValues(): array
    {
        return [
            'blank name'         => [['name' => ''], 'name'],
            'spaces for a name'  => [['name' => '   '], 'name'],
            'blank email'        => [['email' => ''], 'email'],
            'malformed email'    => [['email' => 'not an email'], 'email'],
            'blank country'      => [['country' => ''], 'country'],
            'script address'     => [['url' => 'javascript:alert(1)'], 'website'],
            'other scheme'       => [['url' => 'ftp://x.test/'], 'website'],
            'showurl 7'          => [['showurl' => 7], 'show-website'],
            'showurl word'       => [['showurl' => 'yes'], 'show-website'],
            // It checks the exact text that gets saved, so spaces that reach it are refused.
            'a spaced email'     => [['email' => ' new@x.test '], 'email'],
            'a spaced website'   => [['url' => ' https://u.test/ '], 'website'],
            'a spaced showurl'   => [['showurl' => ' 1 '], 'show-website'],
        ];
    }

    /** @param array<string, string|int> $changes */
    #[DataProvider('badValues')]
    public function testABadValueIsRefusedAndTheReasonNamesIt(array $changes, string $named): void
    {
        self::assertStringContainsString($named, (string) QueuedUpdateApplier::invalidReason($changes));
    }

    /** @return array<string, array{0: array<string, string|int>}> */
    public static function goodValues(): array
    {
        return [
            'zero as a name'   => [['name' => '0']],
            'zero as a field'  => [['fave' => '0']],
            'blank a field'    => [['fave' => '']],
            'remove the url'   => [['url' => '', 'showurl' => 0]],
            'real url'         => [['url' => 'https://u.test/x', 'showurl' => 1]],
            'showurl as text'  => [['showurl' => '1']],
            'real email'       => [['email' => 'New@x.test']],
        ];
    }

    /** @param array<string, string|int> $changes */
    #[DataProvider('goodValues')]
    public function testAGoodValueIsAccepted(array $changes): void
    {
        self::assertNull(QueuedUpdateApplier::invalidReason($changes));
    }

    // --- What makes a request stop

    private static function row(?string $reviewState, string $submittedAt = '2026-06-15 12:00:00'): object
    {
        return (object) ['review_state' => $reviewState, 'submitted_at' => $submittedAt];
    }

    private static function memberWhoJoined(string $added): Member
    {
        return Member::fromRow(['email' => 'a@x.test', 'name' => 'A', 'pending' => 0, 'added' => $added]);
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function datesForARequestFromBeforeTheChecks(): array
    {
        $held = PendingUpdateRepo::MAY_BE_OTHER_MEMBER;

        return [
            'joined later'           => ['2026-06-20', $held],
            'joined the next day'    => ['2026-06-16', $held],
            'joined the same day'    => ['2026-06-15', $held],
            'joined the day before'  => ['2026-06-14', $held],
            'with a time as well'    => ['2026-06-14 23:59:59', $held],
            'blank'                  => ['', $held],
            'all zeros'              => ['0000-00-00', $held],
            'zeros with a time'      => ['0000-00-00 00:00:00', $held],
            'unreadable'             => ['last spring', $held],
            'two days before'        => ['2026-06-13', null],
            'weeks before'           => ['2026-05-01', null],
        ];
    }

    #[DataProvider('datesForARequestFromBeforeTheChecks')]
    public function testARequestFromBeforeTheChecksIsHeldUnlessTheMemberJoinedWellBefore(string $added, ?string $expected): void
    {
        self::assertSame($expected, QueuedUpdateApplier::holdReason(self::row(PendingUpdateRepo::QUEUED_BEFORE_CHECKS), self::memberWhoJoined($added)));
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function datesForAnOrdinaryRequest(): array
    {
        $held = PendingUpdateRepo::JOINED_AFTER_REQUEST;

        return [
            'years before'       => ['2020-01-01', null],
            'the same day'       => ['2026-06-15', null],
            'the next day'       => ['2026-06-16', null],
            'the next day, late' => ['2026-06-16 23:59:59', null],
            'two days later'     => ['2026-06-17', $held],
            'much later'         => ['2026-09-01', $held],
            'blank'              => ['', null],
            'all zeros'          => ['0000-00-00', null],
        ];
    }

    #[DataProvider('datesForAnOrdinaryRequest')]
    public function testAnOrdinaryRequestIsOnlyHeldIfTheMemberJoinedMoreThanADayAfterIt(string $added, ?string $expected): void
    {
        self::assertSame($expected, QueuedUpdateApplier::holdReason(self::row(null), self::memberWhoJoined($added)));
    }

    public function testAMonthBoundaryCountsAsOneDay(): void
    {
        $row = self::row(null, '2026-06-30 23:00:00');

        self::assertNull(QueuedUpdateApplier::holdReason($row, self::memberWhoJoined('2026-07-01')));
        self::assertSame(PendingUpdateRepo::JOINED_AFTER_REQUEST, QueuedUpdateApplier::holdReason($row, self::memberWhoJoined('2026-07-02')));
    }

    public function testAStoredHoldStaysWhateverTheDatesSay(): void
    {
        foreach (PendingUpdateRepo::HOLDS as $hold) {
            self::assertSame($hold, QueuedUpdateApplier::holdReason(self::row($hold), self::memberWhoJoined('2020-01-01')));
            self::assertSame($hold, QueuedUpdateApplier::holdReason(self::row($hold), null));
        }
    }

    public function testARowWithNoMemberIsOnlyHeldIfAnAskedForAddressIsTaken(): void
    {
        self::assertNull(QueuedUpdateApplier::holdReason(self::row(null), null));
        self::assertNull(QueuedUpdateApplier::holdReason(self::row(PendingUpdateRepo::QUEUED_BEFORE_CHECKS), null));
        self::assertSame(PendingUpdateRepo::ADDRESS_MOVED, QueuedUpdateApplier::holdReason(self::row(null), null, true));
    }

    // --- What the owner gets told about a hold

    public function testEveryHoldHasALabelAndAReason(): void
    {
        foreach (PendingUpdateRepo::HOLDS as $hold) {
            self::assertStringStartsWith('Needs review', QueuedUpdateApplier::holdLabel($hold));
            self::assertNotSame('', QueuedUpdateApplier::holdText($hold));
        }
    }

    public function testOnlyAnUnconfirmedWriteSaysItMayBePartlyApplied(): void
    {
        foreach (PendingUpdateRepo::HOLDS as $hold) {
            self::assertSame(
                $hold === PendingUpdateRepo::WRITE_UNCONFIRMED,
                str_contains(QueuedUpdateApplier::holdLabel($hold), 'may be partly applied'),
                $hold,
            );
        }
    }
}
