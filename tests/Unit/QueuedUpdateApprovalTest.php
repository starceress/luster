<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use PHPUnit\Framework\TestCase;

/**
 * Approving a queued update, through the real controllers on all four paths: Approve,
 * Approve All, bulk Approve and the overview's Approve All. They used to disagree about
 * what was safe, what order things went in and what a failure left behind.
 *
 * Which table keeps a change after a rollback is simulated here. The same failures run
 * on real MariaDB with MyISAM and InnoDB member tables in the release checks.
 */
final class QueuedUpdateApprovalTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const FAN = ['email' => 'a@x.test', 'name' => 'Fan A', 'country' => 'Canada', 'url' => 'https://a.test/', 'added' => '2020-01-01'];

    /** Fails the member's read-back, the last thing before COMMIT. */
    private const FAIL_READ_BACK = ['do' => 'fail_after', 'seen' => '/^UPDATE fl_moon/', 'regex' => '/^SELECT \* FROM fl_moon/'];

    private const FAILED_MESSAGE      = "Couldn't apply this update because of a database error. Nothing was changed, so you can try again.";
    private const UNCONFIRMED_HELD    = "Luster couldn't confirm this update finished, so the member may already have some of these changes. It's being held in the queue for you to check.";
    private const UNCONFIRMED_NOTHING = "Luster couldn't confirm this update finished. Check this member's details, and make any changes you still want by hand.";

    /** @return array<string, array{steps: list<array<string, mixed>>, emails: bool}> */
    private static function paths(): array
    {
        return [
            'Approve'              => ['steps' => [['do' => 'approve']], 'emails' => true],
            'Approve All'          => ['steps' => [['do' => 'approve_all']], 'emails' => true],
            'bulk Approve'         => ['steps' => [['do' => 'bulk_approve', 'ids' => ['queued']]], 'emails' => false],
            'overview Approve All' => ['steps' => [['do' => 'approve_overview']], 'emails' => true],
        ];
    }

    /** Every path and layout for the same setup steps. */
    private function onEveryPath(array $setup, array $over = [], array $members = [self::FAN]): array
    {
        $scenarios = [];
        foreach (self::paths() as $name => $path) {
            foreach (['owned', 'enth'] as $layout) {
                $scenarios["{$name}, {$layout}"] = $over + [
                    'layout'  => $layout,
                    'members' => $members,
                    'steps'   => [...$setup, ...$path['steps']],
                ];
            }
        }

        return $this->runScenarios($scenarios);
    }

    private static function emails(string $label): bool
    {
        return !str_starts_with($label, 'bulk');
    }

    private static function fan(array $r, string $email = 'a@x.test'): ?array
    {
        foreach ($r['members'][1] as $row) {
            if ($row['email'] === $email) {
                return $row;
            }
        }
        return null;
    }

    private static function mailCount(array $r): int
    {
        return substr_count((string) $r['mail'], '--LSTR-MAIL-END--');
    }

    /** Every flash the steps left, as one string. */
    private static function flashed(array $r): string
    {
        $all = [];
        foreach ($r['flashes'] as $step) {
            foreach ($step as $value) {
                $all[] = (string) $value;
            }
        }
        return implode(' | ', $all);
    }

    private static function assertMemberUnchanged(array $r, string $why): void
    {
        $fan = self::fan($r);
        self::assertNotNull($fan, $why);
        foreach (['name' => 'Fan A', 'country' => 'Canada', 'url' => 'https://a.test/', 'added' => '2020-01-01'] as $column => $value) {
            self::assertSame($value, $fan[$column], "{$why}: {$column}");
        }
    }

    // ------------------------------------------------------------------
    // What a request may change
    // ------------------------------------------------------------------

    public function testAForbiddenKeyIsDroppedWhileTheNameApplies(): void
    {
        $results = $this->onEveryPath([['do' => 'queue', 'changes' => ['name' => 'New Name', 'password' => 'plain-text', 'added' => '1999-01-01', 'showemail' => 0, 'pending' => 1, 'approved' => 0]]]);

        foreach ($results as $label => $r) {
            self::assertNull($r['error'], $label);
            $fan = self::fan($r);
            self::assertSame('New Name', $fan['name'], $label);
            self::assertNotSame('plain-text', $fan['password'], $label);
            self::assertSame('2020-01-01', $fan['added'], $label);
            self::assertSame(1, (int) $fan['showemail'], $label);
            self::assertSame(str_contains($label, 'owned') ? 0 : 1, (int) ($fan['pending'] ?? $fan['approved']), $label);
            self::assertSame([], $r['queue'], $label);
            self::assertSame(self::emails($label) ? 1 : 0, self::mailCount($r), $label);
        }
    }

    public function testARowWithOnlyForbiddenKeysIsDiscardedWithoutError(): void
    {
        $results = $this->onEveryPath([['do' => 'queue', 'changes' => ['password' => 'plain-text', 'added' => '1999-01-01']]]);

        foreach ($results as $label => $r) {
            self::assertNull($r['error'], $label);
            self::assertMemberUnchanged($r, $label);
            self::assertSame([], $r['queue'], $label);
            self::assertSame(0, self::mailCount($r), $label);
            self::assertStringNotContainsString('UPDATE fl_moon', implode("\n", $r['statements']), $label);
        }
    }

    public function testAFieldAnswerGoesToItsOwnColumnAndADeletedFieldIsDropped(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'sql', 'sql' => "ALTER TABLE fl_moon ADD COLUMN fave TEXT NOT NULL DEFAULT ''"],
            ['do' => 'sql', 'sql' => "UPDATE owned SET additional = 'fave' WHERE listingid = 1"],
            ['do' => 'queue', 'changes' => ['FAVE' => 'Blue', 'deleted_field' => 'gone']],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('Blue', self::fan($r)['fave']);
        self::assertSame([], $r['queue']);
    }

    public function testARequestQueuedUnderTheFieldsOwnNameKeepsItsAnswerOnTheEnthLayout(): void
    {
        $r = $this->runOne(['layout' => 'enth', 'members' => [self::FAN], 'steps' => [
            ['do' => 'sql', 'sql' => "ALTER TABLE fl_moon_members ADD COLUMN additional_fave TEXT NOT NULL DEFAULT ''"],
            ['do' => 'sql', 'sql' => "UPDATE enth_fanlistings SET additional_info = 'fave' WHERE id = 1"],
            ['do' => 'queue', 'changes' => ['fave' => 'Blue']],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('Blue', self::fan($r)['additional_fave']);
    }

    // ------------------------------------------------------------------
    // Values that can't be applied stay for the owner
    // ------------------------------------------------------------------

    public function testAPoisonedRowStaysQueuedAndChangesNothing(): void
    {
        $poison = [
            'blank email'     => ['changes' => ['email' => '']],
            'malformed email' => ['changes' => ['email' => 'not an email']],
            'blank name'      => ['changes' => ['name' => '']],
            'blank country'   => ['changes' => ['country' => '']],
            'script address'  => ['changes' => ['url' => 'javascript:alert(1)']],
            'showurl 7'       => ['changes' => ['showurl' => 7]],
            'a list value'    => ['changes' => ['name' => ['nested']]],
            'bad JSON'        => ['changes_raw' => '{not json'],
            'JSON, not a map' => ['changes_raw' => '"just text"'],
        ];

        foreach ($poison as $what => $queue) {
            $results = $this->onEveryPath([
                ['do' => 'reset_token'],
                ['do' => 'queue', ...$queue],
            ]);

            foreach ($results as $label => $r) {
                $why = "{$what}, {$label}";
                self::assertNull($r['error'], $why);
                self::assertMemberUnchanged($r, $why);
                self::assertCount(1, $r['queue'], $why);
                self::assertSame('a@x.test', $r['queue'][0]['member_email'], "{$why}: no re-key");
                self::assertCount(1, $r['resets'], "{$why}: the reset link stays");
                self::assertSame(0, self::mailCount($r), $why);
                self::assertStringNotContainsString('UPDATE fl_moon', implode("\n", $r['statements']), $why);
            }
        }
    }

    public function testAnAddressWithExtraSpacesIsSavedCleanAndTheFanCanSignInWithIt(): void
    {
        foreach (self::paths() as $label => $path) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN + ['password' => 'a-password-1']], 'steps' => [
                ['do' => 'queue', 'changes' => ['email' => ' new@x.test ']],
                ...$path['steps'],
                ['do' => 'sign_in', 'email' => 'new@x.test', 'password' => 'a-password-1'],
            ]]);

            self::assertNull($r['error'], $label);
            self::assertSame(['new@x.test'], array_column($r['members'][1], 'email'), "{$label}: saved without the spaces");
            self::assertSame([], $r['queue'], $label);
            self::assertStringNotContainsString('No membership found', self::flashed($r), $label);
        }
    }

    public function testOtherCoreValuesAreSavedCleanToo(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => '  New Name ', 'country' => ' Peru ', 'url' => ' https://new.test/ ', 'showurl' => ' 0 ']],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        $fan = self::fan($r);
        self::assertSame('New Name', $fan['name']);
        self::assertSame('Peru', $fan['country']);
        self::assertSame('https://new.test/', $fan['url']);
        self::assertSame(0, (int) $fan['showurl']);
        self::assertSame([], $r['queue']);
    }

    public function testASpacedAddressThatIsReallyTheirOwnChangesNothing(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'reset_token'],
            ['do' => 'queue', 'changes' => ['email' => ' a@x.test ']],
            ['do' => 'trace_from_here'],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame(['a@x.test'], array_column($r['members'][1], 'email'));
        self::assertSame([], $r['queue']);
        self::assertCount(1, $r['resets'], 'Nothing was changing hands');
        self::assertSame(0, self::mailCount($r));
    }

    public function testSpacesInsideAnAddressOrOnlySpacesStayInvalid(): void
    {
        foreach (['spaces inside' => 'new @x.test', 'only spaces' => '   ', 'a non-breaking space' => "new@x.test\u{00A0}"] as $what => $email) {
            $results = $this->onEveryPath([['do' => 'queue', 'changes' => ['email' => $email]]]);

            foreach ($results as $label => $r) {
                self::assertNull($r['error'], "{$what}, {$label}");
                self::assertMemberUnchanged($r, "{$what}, {$label}");
                self::assertCount(1, $r['queue'], "{$what}, {$label}");
                self::assertSame(0, self::mailCount($r), "{$what}, {$label}");
            }
        }
    }

    public function testACapitalisedEmailKeyLocksTheAddressItAsksFor(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'names_for' => [[1, 'new@x.test']], 'steps' => [
            ['do' => 'set_lock', 'email' => 'new@x.test', 'answer' => 0],
            ['do' => 'queue', 'changes' => ['EMAIL' => 'new@x.test']],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame(['a@x.test'], array_column($r['members'][1], 'email'), "The asked-for address is busy, so this can't go ahead");
        self::assertCount(1, $r['queue']);
    }

    public function testAnInvalidOrHeldRenameLeavesTheLinksAlone(): void
    {
        $cases = [
            'invalid' => ['changes' => ['email' => 'not an email']],
            'held'    => ['changes' => ['email' => 'new@x.test'], 'review_state' => 'address_moved'],
            'too long' => ['changes' => ['email' => str_repeat('x', 300) . '@x.test']],
        ];

        foreach ($cases as $what => $queue) {
            $r = $this->runOne(['layout' => 'owned', 'email_column_type' => 'VARCHAR(64)', 'members' => [self::FAN], 'steps' => [
                ['do' => 'reset_token'],
                ['do' => 'collective_token', 'email' => 'new@x.test'],
                ['do' => 'queue', ...$queue],
                ['do' => 'approve'],
            ]]);

            self::assertNull($r['error'], $what);
            self::assertCount(1, $r['resets'], "{$what}: nothing is changing hands");
            self::assertSame(['new@x.test'], array_column($r['collective_tokens'], 'email'), $what);
        }
    }

    public function testApprovingAnInvalidRowSaysWhyAndThatItIsStillThere(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['email' => 'not an email']],
            ['do' => 'approve'],
        ]]);

        self::assertStringContainsString("Couldn't apply this: the requested email isn't valid. It's still in the queue.", self::flashed($r));
    }

    public function testValuesThatLookOddButAreFineApply(): void
    {
        $scenarios = [
            'zero as a name' => ['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => '0']], ['do' => 'approve'],
            ]],
            'zero as a field' => ['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'sql', 'sql' => "ALTER TABLE fl_moon ADD COLUMN fave TEXT NOT NULL DEFAULT ''"],
                ['do' => 'sql', 'sql' => "UPDATE owned SET additional = 'fave' WHERE listingid = 1"],
                ['do' => 'queue', 'changes' => ['fave' => '0']], ['do' => 'approve'],
            ]],
            'removing the url' => ['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['url' => '', 'showurl' => 0]], ['do' => 'approve'],
            ]],
            'a blank country, changing something else' => ['layout' => 'owned', 'members' => [['country' => ''] + self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['country' => '', 'name' => 'New Name']], ['do' => 'approve'],
            ]],
        ];

        $results = $this->runScenarios($scenarios);

        self::assertSame('0', self::fan($results['zero as a name'])['name']);
        self::assertSame('0', self::fan($results['zero as a field'])['fave']);
        self::assertSame('', self::fan($results['removing the url'])['url']);
        self::assertSame(0, (int) self::fan($results['removing the url'])['showurl']);
        self::assertSame('New Name', self::fan($results['a blank country, changing something else'])['name']);
        foreach ($results as $label => $r) {
            self::assertSame([], $r['queue'], $label);
        }
    }

    // ------------------------------------------------------------------
    // Requests with nothing left to do
    // ------------------------------------------------------------------

    public function testARequestThatChangesNothingIsDiscardedWithNoMail(): void
    {
        $results = $this->onEveryPath([['do' => 'queue', 'changes' => ['name' => 'Fan A', 'country' => 'Canada']]]);

        foreach ($results as $label => $r) {
            self::assertNull($r['error'], $label);
            self::assertSame([], $r['queue'], $label);
            self::assertSame(0, self::mailCount($r), $label);
            self::assertSame([], array_values(array_filter($r['statements'], static fn(string $s): bool => str_starts_with($s, 'UPDATE fl_moon'))), $label);
        }
    }

    public function testOnlyTheRealChangeIsWritten(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'Fan A', 'country' => 'Peru']],
            ['do' => 'trace_from_here'],
            ['do' => 'approve'],
        ]]);

        $writes = array_values(array_filter($r['statements'], static fn(string $s): bool => str_starts_with($s, 'UPDATE fl_moon')));
        self::assertCount(1, $writes);
        self::assertStringContainsString('`country`', $writes[0]);
        self::assertStringNotContainsString('`name`', $writes[0]);
        self::assertSame('Peru', self::fan($r)['country']);
    }

    public function testARequestAtAnAddressNobodyHoldsIsDiscarded(): void
    {
        $results = $this->onEveryPath([['do' => 'queue', 'email' => 'nobody@x.test', 'changes' => ['name' => 'Someone']]]);

        foreach ($results as $label => $r) {
            self::assertNull($r['error'], $label);
            self::assertSame([], $r['queue'], $label);
            self::assertSame(0, self::mailCount($r), $label);
        }
    }

    public function testARowAnotherApprovalUsedUpIsGoneWithNoWriteOrMail(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'Too Late']],
            ['do' => 'before', 'match' => '/GET_LOCK/', 'sql' => 'DELETE FROM lstr_pending_updates'],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertMemberUnchanged($r, 'the row was gone');
        self::assertSame(0, self::mailCount($r));
        self::assertStringContainsString('already handled', self::flashed($r));
    }

    // ------------------------------------------------------------------
    // Order
    // ------------------------------------------------------------------

    /** The newer request is queued first, so only the dates can put these in the right order. */
    private static function twoRequests(array $newest = ['name' => 'Newer Name']): array
    {
        return [
            ['do' => 'queue', 'as' => 'new', 'changes' => $newest, 'submitted_at' => '2026-06-20 12:00:00'],
            ['do' => 'queue', 'as' => 'old', 'changes' => ['name' => 'Older Name'], 'submitted_at' => '2026-06-10 12:00:00'],
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private static function batchPaths(): array
    {
        return [
            'Approve All'            => [['do' => 'approve_all']],
            'overview Approve All'   => [['do' => 'approve_overview']],
            'bulk, newest ticked first' => [['do' => 'bulk_approve', 'ids' => ['new', 'old']]],
            'bulk, oldest ticked first' => [['do' => 'bulk_approve', 'ids' => ['old', 'new']]],
        ];
    }

    public function testTheNewestRequestWinsOnEveryBatchPath(): void
    {
        foreach (self::batchPaths() as $label => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [...self::twoRequests(), ...$steps]]);

            self::assertNull($r['error'], $label);
            self::assertSame('Newer Name', self::fan($r)['name'], $label);
            self::assertSame([], $r['queue'], $label);
        }
    }

    public function testIfTheNewestIsInvalidTheOlderAppliesAndTheNewestStays(): void
    {
        foreach (self::batchPaths() as $label => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [...self::twoRequests(['name' => '']), ...$steps]]);

            self::assertNull($r['error'], $label);
            self::assertSame('Older Name', self::fan($r)['name'], $label);
            self::assertCount(1, $r['queue'], $label);
            self::assertSame('{"name":""}', $r['queue'][0]['changes'], $label);
        }
    }

    // ------------------------------------------------------------------
    // Addresses
    // ------------------------------------------------------------------

    public function testTheMembersOwnAddressInOtherCapitalsGoesThroughWithTheRest(): void
    {
        foreach (self::paths() as $label => $path) {
            $r = $this->runOne(['layout' => 'owned', 'case_insensitive' => true, 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['email' => 'A@X.test', 'name' => 'New Name']],
                ...$path['steps'],
            ]]);

            self::assertNull($r['error'], $label);
            self::assertNotNull(self::fan($r, 'A@X.test'), $label);
            self::assertSame('New Name', self::fan($r, 'A@X.test')['name'], $label);
            self::assertSame([], $r['queue'], $label);
        }
    }

    public function testAChangeOfCapitalsKeepsTheMembersOwnRowsAndMovesThemToTheNewSpelling(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'case_insensitive' => true, 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'as' => 'capitals', 'changes' => ['email' => 'A@X.test'], 'submitted_at' => '2026-06-10 12:00:00'],
            ['do' => 'queue', 'as' => 'later', 'changes' => ['country' => 'Peru'], 'submitted_at' => '2026-06-11 12:00:00'],
            ['do' => 'approve', 'id' => 'capitals'],
        ]]);

        self::assertCount(1, $r['queue']);
        self::assertSame('A@X.test', $r['queue'][0]['member_email']);
    }

    public function testATakenAddressIsAConflictAndTheBatchCarriesOn(): void
    {
        foreach (self::paths() as $label => $path) {
            if ($label === 'Approve' || str_starts_with($label, 'bulk')) {
                continue;
            }
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, ['email' => 'taken@x.test', 'name' => 'Taken', 'added' => '2020-01-01'], ['email' => 'c@x.test', 'name' => 'Fan C', 'added' => '2020-01-01']], 'steps' => [
                ['do' => 'queue', 'changes' => ['email' => 'taken@x.test'], 'submitted_at' => '2026-06-10 12:00:00'],
                ['do' => 'queue', 'email' => 'c@x.test', 'changes' => ['name' => 'Later Row'], 'submitted_at' => '2026-06-11 12:00:00'],
                ...$path['steps'],
            ]]);

            self::assertNull($r['error'], $label);
            self::assertSame('Fan A', self::fan($r)['name'], $label);
            self::assertSame('Later Row', self::fan($r, 'c@x.test')['name'], $label);
            self::assertSame([], $r['queue'], $label);
            self::assertStringContainsString('Skipped 1 because the requested email was already taken.', self::flashed($r), $label);
        }
    }

    public function testARenameTakesEverythingWithItOnEveryPath(): void
    {
        foreach (self::paths() as $label => $path) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'reset_token'],
                ['do' => 'sql', 'sql' => "INSERT INTO lstr_member_link_checks (listing_id, member_email, member_name, member_url) VALUES (1, 'a@x.test', 'Fan A', 'https://a.test/')"],
                ['do' => 'queue', 'changes' => ['email' => 'new@x.test'], 'submitted_at' => '2026-06-10 12:00:00'],
                ...$path['steps'],
            ]]);

            self::assertNull($r['error'], $label);
            self::assertNotNull(self::fan($r, 'new@x.test'), $label);
            self::assertSame([], $r['resets'], "{$label}: the reset link went with the old address");
            self::assertCount(1, $r['link_checks'], $label);
            self::assertSame('new@x.test', $r['link_checks'][0]['member_email'], $label);
            if (self::paths()[$label]['emails']) {
                self::assertStringContainsString('new@x.test', (string) $r['mail'], "{$label}: the email goes to the final address");
            } else {
                self::assertSame('', (string) $r['mail'], $label);
            }
        }
    }

    public function testALaterRowForARenamedMemberIsAppliedInTheSameBatch(): void
    {
        foreach (['Approve All' => [['do' => 'approve_all']], 'overview Approve All' => [['do' => 'approve_overview']]] as $label => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['email' => 'new@x.test'], 'submitted_at' => '2026-06-10 12:00:00'],
                ['do' => 'queue', 'changes' => ['name' => 'Later Name'], 'submitted_at' => '2026-06-11 12:00:00'],
                ...$steps,
            ]]);

            self::assertNull($r['error'], $label);
            self::assertSame('Later Name', self::fan($r, 'new@x.test')['name'], $label);
            self::assertSame([], $r['queue'], $label);
        }
    }

    public function testStaleRowsAtTheNewAddressAreClearedButHeldOnesStay(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'as' => 'stale', 'email' => 'new@x.test', 'changes' => ['name' => 'Left Behind']],
            ['do' => 'queue', 'as' => 'held', 'email' => 'new@x.test', 'changes' => ['name' => 'Held Back'], 'review_state' => 'address_moved'],
            ['do' => 'queue', 'as' => 'rename', 'changes' => ['email' => 'new@x.test']],
            ['do' => 'approve', 'id' => 'rename'],
        ]]);

        self::assertNull($r['error']);
        self::assertCount(1, $r['queue']);
        self::assertStringContainsString('Held Back', $r['queue'][0]['changes']);
        self::assertSame('address_moved', $r['queue'][0]['review_state']);
        self::assertSame('Fan A', self::fan($r, 'new@x.test')['name']);
    }

    // ------------------------------------------------------------------
    // Widths
    // ------------------------------------------------------------------

    public function testAnAddressTooLongForTheColumnStaysQueuedAndNothingMoves(): void
    {
        $long = str_repeat('x', 60) . '@x.test';

        $results = $this->onEveryPath([
            ['do' => 'queue', 'changes' => ['email' => $long]],
        ], ['email_column_type' => 'VARCHAR(64)']);

        foreach ($results as $label => $r) {
            self::assertNull($r['error'], $label);
            self::assertMemberUnchanged($r, $label);
            self::assertCount(1, $r['queue'], $label);
            self::assertSame('a@x.test', $r['queue'][0]['member_email'], "{$label}: not re-keyed");
            self::assertSame(0, self::mailCount($r), $label);
        }
    }

    public function testATooLongSiblingIsNotMovedEither(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'email_column_type' => 'VARCHAR(64)', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'as' => 'too_long', 'changes' => ['email' => str_repeat('x', 60) . '@x.test']],
            ['do' => 'queue', 'as' => 'sibling', 'changes' => ['country' => 'Peru']],
            ['do' => 'approve', 'id' => 'too_long'],
        ]]);

        self::assertSame(['a@x.test', 'a@x.test'], array_column($r['queue'], 'member_email'));
        self::assertStringContainsString('too long', self::flashed($r));
    }

    public function testAnAddressThatFitsAppliesAtExactlyTheWidth(): void
    {
        $fits = str_repeat('x', 57) . '@x.test';
        self::assertSame(64, strlen($fits));

        $r = $this->runOne(['layout' => 'owned', 'email_column_type' => 'VARCHAR(64)', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['email' => $fits]],
            ['do' => 'approve'],
        ]]);

        self::assertNotNull(self::fan($r, $fits));
    }

    public function testIfTheColumnsCantBeReadTheRowStaysAndNothingIsWritten(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name']],
            ['do' => 'fail_on', 'regex' => '/PRAGMA table_info\(`fl_moon`\)/'],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertMemberUnchanged($r, 'unreadable');
        self::assertCount(1, $r['queue']);
        self::assertNull($r['queue'][0]['review_state']);
        self::assertSame(0, self::mailCount($r));
        self::assertStringContainsString("Couldn't check this listing's member table", self::flashed($r));
    }

    // ------------------------------------------------------------------
    // Failures before the member write
    // ------------------------------------------------------------------

    public function testAFailureBeforeTheMemberWriteChangesNothingAndARetryIsNormal(): void
    {
        // Regex, and whether the reset link is still there: it goes once the checks have
        // passed, because rollback could bring it back if it went inside the transaction.
        $statements = [
            'locking this request'       => ['/SELECT \* FROM lstr_pending_updates WHERE id = :id$/', 1],
            'locking the other requests' => ['/SELECT \* FROM lstr_pending_updates\s+WHERE fanlisting_id = :fid AND member_email = :email$/', 1],
            'deleting the reset links'   => ['/DELETE FROM lstr_fan_password_resets/', 1],
            'moving the other requests'  => ['/UPDATE lstr_pending_updates SET member_email/', 0],
            'deleting this request'      => ['/DELETE FROM lstr_pending_updates WHERE id/', 0],
        ];

        foreach ($statements as $what => [$regex, $linksLeft]) {
            $let = $what === 'locking this request' ? 1 : 0; // getById() is the same SQL here, and runs first.
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'reset_token'],
                ['do' => 'queue', 'as' => 'sibling', 'changes' => ['country' => 'Peru'], 'submitted_at' => '2026-06-11 12:00:00'],
                ['do' => 'queue', 'changes' => ['email' => 'new@x.test', 'name' => 'New Name'], 'submitted_at' => '2026-06-10 12:00:00'],
                ['do' => 'trace_from_here'],
                ['do' => 'fail_on_after', 'regex' => $regex, 'let_through' => $let],
                ['do' => 'approve'],
            ]]);

            self::assertNull($r['error'], $what);
            self::assertMemberUnchanged($r, $what);
            self::assertSame(['a@x.test', 'a@x.test'], array_column($r['queue'], 'member_email'), $what);
            self::assertSame([null, null], array_column($r['queue'], 'review_state'), "{$what}: not held");
            self::assertCount($linksLeft, $r['resets'], $what);
            self::assertSame(0, self::mailCount($r), $what);
            self::assertStringContainsString(self::FAILED_MESSAGE, self::flashed($r), $what);
            self::assertNotContains('COMMIT', $r['statements'], "{$what}: nothing goes on to commit");
            self::assertSame([], array_values(array_filter($r['statements'], static fn(string $s): bool => str_starts_with($s, 'UPDATE fl_moon'))), $what);

            $retry = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'reset_token'],
                ['do' => 'queue', 'as' => 'sibling', 'changes' => ['country' => 'Peru'], 'submitted_at' => '2026-06-11 12:00:00'],
                ['do' => 'queue', 'changes' => ['email' => 'new@x.test', 'name' => 'New Name'], 'submitted_at' => '2026-06-10 12:00:00'],
                ['do' => 'fail_on_after', 'regex' => $regex, 'let_through' => $let],
                ['do' => 'approve'],
                ['do' => 'trace_from_here'],
                ['do' => 'approve'],
            ]]);
            self::assertSame('New Name', self::fan($retry, 'new@x.test')['name'], "{$what}: the retry applies");
            self::assertSame(1, self::mailCount($retry), "{$what}: the retry sends one email");
        }
    }

    public function testNothingRunsAfterAFailureInsideTheTransaction(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['email' => 'new@x.test']],
            ['do' => 'trace_from_here'],
            ['do' => 'fail_on', 'regex' => '/UPDATE lstr_pending_updates SET member_email/'],
            ['do' => 'approve'],
        ]]);

        $at   = array_search(true, array_map(static fn(string $s): bool => str_contains($s, 'UPDATE lstr_pending_updates SET member_email'), $r['statements']), true);
        $next = array_slice($r['statements'], (int) $at + 1);

        self::assertNotFalse($at);
        self::assertSame('ROLLBACK', $next[0], 'The very next thing after the failure is the rollback');
        self::assertNotContains('COMMIT', $r['statements']);
        self::assertSame([], array_values(array_filter($next, static fn(string $s): bool => (bool) preg_match('/^(INSERT|DELETE|UPDATE) /', ltrim($s)))));
    }

    // ------------------------------------------------------------------
    // Failures at or after the member write
    // ------------------------------------------------------------------

    public function testOnATransactionalTableAFailedReadBackRollsBackAndARetryIsNormal(): void
    {
        foreach (['a name-only update' => ['name' => 'New Name'], 'an email change' => ['email' => 'new@x.test', 'name' => 'New Name']] as $what => $changes) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => $changes],
                self::FAIL_READ_BACK,
                ['do' => 'approve'],
            ]]);

            self::assertNull($r['error'], $what);
            self::assertMemberUnchanged($r, $what);
            self::assertCount(1, $r['queue'], $what);
            self::assertNull($r['queue'][0]['review_state'], $what);
            self::assertSame(0, self::mailCount($r), $what);
            self::assertStringContainsString(self::FAILED_MESSAGE, self::flashed($r), $what);
        }
    }

    public function testAMismatchedReadBackCountsAsAFailure(): void
    {
        // A non-strict server cutting a value short: the statement "worked", and the value is wrong.
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name']],
            ['do' => 'sql', 'sql' => "CREATE TRIGGER cut AFTER UPDATE ON fl_moon BEGIN UPDATE fl_moon SET name = substr(new.name, 1, 3) WHERE email = new.email; END"],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('Fan A', self::fan($r)['name'], 'Rolled back');
        self::assertCount(1, $r['queue']);
        self::assertSame(0, self::mailCount($r));
        self::assertStringContainsString(self::FAILED_MESSAGE, self::flashed($r));
    }

    public function testWhenTheMemberKeepsANameChangeOnlyThatRowIsHeld(): void
    {
        foreach (['read-back failure' => [self::FAIL_READ_BACK], 'failed COMMIT' => [['do' => 'fail_commit']]] as $what => $fail) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'as' => 'sibling', 'changes' => ['country' => 'Peru']],
                ['do' => 'queue', 'changes' => ['name' => 'New Name']],
                ['do' => 'survives_rollback', 'sql' => "UPDATE fl_moon SET name = 'New Name' WHERE email = 'a@x.test'"],
                ...$fail,
                ['do' => 'approve'],
            ]]);

            self::assertNull($r['error'], $what);
            self::assertSame('New Name', self::fan($r)['name'], $what);
            self::assertSame([null, 'write_unconfirmed'], array_column($r['queue'], 'review_state'), "{$what}: only this row");
            self::assertSame(0, self::mailCount($r), $what);
            self::assertStringContainsString(self::UNCONFIRMED_HELD, self::flashed($r), $what);
        }
    }

    public function testWhenTheMemberKeepsAnEmailChangeEveryRequestAtTheOldAddressIsHeld(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'as' => 'sibling', 'changes' => ['country' => 'Peru']],
            ['do' => 'queue', 'changes' => ['email' => 'new@x.test']],
            ['do' => 'survives_rollback', 'sql' => "UPDATE fl_moon SET email = 'new@x.test' WHERE email = 'a@x.test'"],
            self::FAIL_READ_BACK,
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertNotNull(self::fan($r, 'new@x.test'));
        self::assertSame(['a@x.test', 'a@x.test'], array_column($r['queue'], 'member_email'));
        self::assertSame(['write_unconfirmed', 'write_unconfirmed'], array_column($r['queue'], 'review_state'));
        self::assertSame(0, self::mailCount($r));
        self::assertStringContainsString(self::UNCONFIRMED_HELD, self::flashed($r));
    }

    public function testAHeldRowStaysStoppedEvenIfItsMemberOrListingIsGone(): void
    {
        // Without a stored hold, both of these would be thrown away as "gone".
        $cases = [
            'the member has gone'  => ['do' => 'sql', 'sql' => 'DELETE FROM fl_moon'],
            'the listing has gone' => ['do' => 'sql', 'sql' => 'DELETE FROM owned WHERE listingid = 1'],
        ];

        foreach ($cases as $what => $gone) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'Held Back'], 'review_state' => 'write_unconfirmed'],
                $gone,
                ['do' => 'approve'],
            ]]);

            self::assertNull($r['error'], $what);
            self::assertCount(1, $r['queue'], $what);
            self::assertSame('write_unconfirmed', $r['queue'][0]['review_state'], $what);
            self::assertStringContainsString('needs your review', self::flashed($r), $what);
        }
    }

    public function testApprovingAHeldRowRefusesIt(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name']],
            ['do' => 'survives_rollback', 'sql' => "UPDATE fl_moon SET name = 'New Name' WHERE email = 'a@x.test'"],
            self::FAIL_READ_BACK,
            ['do' => 'approve'],
            ['do' => 'trace_from_here'],
            ['do' => 'approve'],
        ]]);

        self::assertCount(1, $r['queue']);
        self::assertSame(0, self::mailCount($r));
        self::assertStringContainsString('needs your review', self::flashed($r));
        self::assertSame([], array_values(array_filter($r['statements'], static fn(string $s): bool => str_starts_with($s, 'UPDATE fl_moon'))));
    }

    public function testACommitThatFinishedWithItsAcknowledgementLostIsNeverRecreated(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name']],
            ['do' => 'no_transactions'],
            ['do' => 'fail_commit'],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('New Name', self::fan($r)['name']);
        self::assertSame([], $r['queue'], 'The request is gone, and stays gone');
        self::assertSame(0, self::mailCount($r));
        self::assertStringContainsString(self::UNCONFIRMED_NOTHING, self::flashed($r));
    }

    public function testACommitThatDidNotFinishIsAnOrdinaryFailureAndARetryIsNormal(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name']],
            ['do' => 'fail_commit'],
            ['do' => 'approve'],
        ]]);

        self::assertMemberUnchanged($r, 'not committed');
        self::assertCount(1, $r['queue']);
        self::assertStringContainsString(self::FAILED_MESSAGE, self::flashed($r));

        $retry = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name']],
            ['do' => 'fail_commit'],
            ['do' => 'approve'],
            ['do' => 'trace_from_here'],
            ['do' => 'approve'],
        ]]);
        self::assertSame('New Name', self::fan($retry)['name']);
        self::assertSame(1, self::mailCount($retry));
    }

    public function testIfTheMemberCantBeReadAgainThatsUnconfirmed(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name']],
            ['do' => 'fail_after', 'seen' => '/^UPDATE fl_moon/', 'regex' => '/^SELECT \* FROM fl_moon/', 'keep_failing' => true],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('write_unconfirmed', $r['queue'][0]['review_state']);
        self::assertStringContainsString(self::UNCONFIRMED_HELD, self::flashed($r));
        self::assertSame(0, self::mailCount($r));
    }

    public function testAMemberWriteThatErrorsBeforeChangingAnythingIsAFailure(): void
    {
        // A BEFORE UPDATE trigger error, as far as the code can tell.
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name']],
            ['do' => 'fail_on', 'regex' => '/^UPDATE fl_moon/'],
            ['do' => 'approve'],
        ]]);

        self::assertMemberUnchanged($r, 'the write errored');
        self::assertNull($r['queue'][0]['review_state']);
        self::assertStringContainsString(self::FAILED_MESSAGE, self::flashed($r));
    }

    public function testAMemberWhoVanishesMidWriteIsNotCountedAsApplied(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name']],
            ['do' => 'before', 'match' => '/^UPDATE fl_moon/', 'sql' => 'DELETE FROM fl_moon'],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertStringNotContainsString('approved and applied', self::flashed($r));
        self::assertSame(0, self::mailCount($r));
    }

    public function testWithNowhereToStoreAHoldTheMessagePromisesNothingAboutTheQueue(): void
    {
        $scenarios = [
            'the hold write fails' => ['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'New Name']],
                ['do' => 'survives_rollback', 'sql' => "UPDATE fl_moon SET name = 'New Name' WHERE email = 'a@x.test'"],
                ['do' => 'fail_on', 'regex' => '/SET review_state/'],
                self::FAIL_READ_BACK,
                ['do' => 'approve'],
            ]],
            'no Migration 110 yet' => ['layout' => 'owned', 'no_review_state' => true, 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'New Name']],
                ['do' => 'survives_rollback', 'sql' => "UPDATE fl_moon SET name = 'New Name' WHERE email = 'a@x.test'"],
                self::FAIL_READ_BACK,
                ['do' => 'approve'],
            ]],
        ];

        foreach ($this->runScenarios($scenarios) as $what => $r) {
            self::assertNull($r['error'], $what);
            self::assertStringContainsString(self::UNCONFIRMED_NOTHING, self::flashed($r), $what);
            self::assertStringContainsString('Could not hold a queued update', (string) $r['log'], $what);
            self::assertSame(0, self::mailCount($r), $what);
        }
    }

    public function testALinkCheckFailureAfterCommitLeavesTheApprovalStanding(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['email' => 'new@x.test']],
            ['do' => 'fail_on', 'regex' => '/lstr_member_link_checks/'],
            ['do' => 'approve'],
        ]]);

        self::assertNull($r['error']);
        self::assertNotNull(self::fan($r, 'new@x.test'));
        self::assertSame([], $r['queue']);
        self::assertSame(1, self::mailCount($r));
        self::assertStringContainsString('approved and applied', self::flashed($r));
        self::assertStringContainsString('link check', (string) $r['log']);
    }

    public function testOneBadRowNeverStopsABatch(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, ['email' => 'c@x.test', 'name' => 'Fan C', 'added' => '2020-01-01'], ['email' => 'd@x.test', 'name' => 'Fan D', 'added' => '2020-01-01']], 'steps' => [
            ['do' => 'queue', 'email' => 'c@x.test', 'changes' => ['name' => ''], 'submitted_at' => '2026-06-01 12:00:00'],
            ['do' => 'queue', 'email' => 'a@x.test', 'changes' => ['name' => 'A Applied'], 'submitted_at' => '2026-06-02 12:00:00'],
            ['do' => 'queue', 'email' => 'd@x.test', 'changes' => ['name' => 'D Applied'], 'submitted_at' => '2026-06-03 12:00:00'],
            ['do' => 'approve_all'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('A Applied', self::fan($r)['name']);
        self::assertSame('D Applied', self::fan($r, 'd@x.test')['name']);
        self::assertSame('Fan C', self::fan($r, 'c@x.test')['name']);
        self::assertCount(1, $r['queue']);
        self::assertStringContainsString('Approved 2 pending updates.', self::flashed($r));
        self::assertStringContainsString("1 couldn't be applied and stayed in the queue.", self::flashed($r));
    }

    // ------------------------------------------------------------------
    // A transaction that can't be closed
    // ------------------------------------------------------------------

    /** Three requests, and the first one's read-back fails with the rollback failing too. */
    private static function batchWithAStuckTransaction(array $batchStep): array
    {
        return [
            ['do' => 'queue', 'as' => 'first', 'changes' => ['name' => 'First'], 'submitted_at' => '2026-06-01 12:00:00'],
            ['do' => 'queue', 'as' => 'second', 'email' => 'c@x.test', 'changes' => ['name' => 'Second'], 'submitted_at' => '2026-06-02 12:00:00'],
            ['do' => 'queue', 'as' => 'third', 'email' => 'c@x.test', 'changes' => ['country' => 'Peru'], 'submitted_at' => '2026-06-03 12:00:00'],
            ['do' => 'trace_from_here'],
            ['do' => 'fail_rollback'],
            self::FAIL_READ_BACK,
            $batchStep,
            ['do' => 'connection_ends'],
        ];
    }

    private const FAN_C = ['email' => 'c@x.test', 'name' => 'Fan C', 'country' => 'Canada', 'added' => '2020-01-01'];

    public function testABatchStopsWhenATransactionCantBeClosed(): void
    {
        $batches = [
            'Approve All'          => ['do' => 'approve_all'],
            'bulk Approve'         => ['do' => 'bulk_approve', 'ids' => ['first', 'second', 'third']],
            'overview Approve All' => ['do' => 'approve_overview'],
        ];

        foreach ($batches as $label => $step) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, self::FAN_C], 'steps' => self::batchWithAStuckTransaction($step)]);

            self::assertNull($r['error'], $label);
            self::assertSame('Fan C', self::fan($r, 'c@x.test')['name'], "{$label}: the rest are never tried");
            self::assertSame(0, self::mailCount($r), $label);
            self::assertCount(3, $r['queue'], "{$label}: the server rolled the first back when the request ended");
            self::assertStringContainsString("Luster stopped here because a database problem left its work unfinished", self::flashed($r), $label);
            self::assertSame([], $r['activity'], "{$label}: no more database work");

            // After the write and its failed read-back, only the locks being let go and the connection ending.
            $after = array_slice($r['statements'], self::firstAt($r, 'UPDATE fl_moon') + 2);
            self::assertSame([], array_values(array_filter($after, static fn(string $s): bool => !str_contains($s, 'RELEASE_LOCK') && $s !== 'ROLLBACK')), $label);
        }
    }

    public function testABatchCountsOneStopAndNothingAfterIt(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, self::FAN_C], 'steps' => self::batchWithAStuckTransaction(['do' => 'apply_all', 'ids' => ['first', 'second', 'third']])]);

        self::assertNull($r['error']);
        self::assertSame([['unresolved' => 1]], $r['tallies']);
    }

    public function testAStuckTransactionShowsAsAnErrorEvenAfterOthersWentThrough(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, ['listing' => 2] + self::FAN_C], 'steps' => [
            ['do' => 'queue', 'as' => 'ok', 'listing' => 2, 'email' => 'c@x.test', 'changes' => ['name' => 'Went Through'], 'submitted_at' => '2026-06-01 12:00:00'],
            ['do' => 'queue', 'as' => 'stuck', 'changes' => ['name' => 'Stuck'], 'submitted_at' => '2026-06-02 12:00:00'],
            ['do' => 'fail_rollback'],
            self::FAIL_READ_BACK,
            ['do' => 'approve_all', 'listing' => 0],
            ['do' => 'connection_ends'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('Went Through', $r['members'][2][0]['name']);
        self::assertSame(1, self::mailCount($r), 'The one that finished still got its email');

        $step = $r['flashes'][4];
        self::assertArrayHasKey('lstr_flash_error', $step);
        self::assertStringContainsString('Approved 1 pending update.', $step['lstr_flash_error']);
        self::assertStringContainsString('Luster stopped here', $step['lstr_flash_error']);
    }

    public function testASingleApprovalSaysSoAndALaterOneInTheSameRequestWontRead(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, self::FAN_C], 'steps' => [
            ['do' => 'queue', 'as' => 'first', 'changes' => ['name' => 'First']],
            ['do' => 'queue', 'as' => 'second', 'email' => 'c@x.test', 'changes' => ['name' => 'Second']],
            ['do' => 'fail_rollback'],
            self::FAIL_READ_BACK,
            ['do' => 'approve', 'id' => 'first'],
            ['do' => 'trace_from_here'],
            ['do' => 'approve', 'id' => 'second'],
            ['do' => 'connection_ends'],
        ]]);

        self::assertNull($r['error']);
        self::assertStringContainsString("A database problem stopped this approval partway, and Luster can't tell what was saved.", self::flashed($r));
        self::assertStringNotContainsString('already handled', self::flashed($r), 'It must not read its own uncommitted work');
        self::assertSame('Fan C', self::fan($r, 'c@x.test')['name']);
        self::assertSame(['ROLLBACK'], $r['statements'], 'The second approval does nothing at all, and the rollback is the connection ending');
    }

    public function testARollbackThatFailsAtAnyStageCountsTheSame(): void
    {
        $stages = [
            'a stopped request' => [['do' => 'queue', 'changes' => ['name' => 'Held'], 'review_state' => 'address_moved']],
            'an invalid value'  => [['do' => 'queue', 'changes' => ['name' => '']]],
            'a stored hold'     => [['do' => 'queue', 'changes' => ['name' => 'New'], 'submitted_at' => '2019-01-01 00:00:00']],
            'clearing the way'  => [['do' => 'queue', 'changes' => ['email' => 'new@x.test']]],
        ];

        foreach ($stages as $what => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ...$steps,
                ['do' => 'fail_rollback'],
                ['do' => 'approve'],
                ['do' => 'connection_ends'],
            ]]);

            self::assertNull($r['error'], $what);
            self::assertStringContainsString('A database problem stopped this approval partway', self::flashed($r), $what);
            self::assertSame(['a@x.test'], array_column($r['members'][1], 'email'), $what);
        }
    }

    public function testAnOrdinaryFailureStillLetsABatchCarryOn(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, self::FAN_C], 'steps' => [
            ['do' => 'queue', 'as' => 'first', 'changes' => ['name' => 'First'], 'submitted_at' => '2026-06-01 12:00:00'],
            ['do' => 'queue', 'as' => 'second', 'email' => 'c@x.test', 'changes' => ['name' => 'Second'], 'submitted_at' => '2026-06-02 12:00:00'],
            self::FAIL_READ_BACK,
            ['do' => 'approve_all'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('Second', self::fan($r, 'c@x.test')['name']);
        self::assertStringContainsString('1 failed because of a database error', self::flashed($r));
        self::assertStringNotContainsString('Luster stopped here', self::flashed($r));
    }

    // ------------------------------------------------------------------
    // After a crash: the member already changed and the rows are as they were
    // ------------------------------------------------------------------

    public function testAfterACrashAnEmailChangeAndItsSiblingAreBothHeldWhicheverComesFirst(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [['email' => 'new@x.test'] + self::FAN], 'steps' => [
            ['do' => 'queue', 'as' => 'sibling', 'changes' => ['name' => 'Name Only']],
            ['do' => 'queue', 'as' => 'rename', 'changes' => ['email' => 'new@x.test']],
            ['do' => 'trace_from_here'],
            ['do' => 'approve', 'id' => 'sibling'],
            ['do' => 'approve', 'id' => 'rename'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame(['address_moved', 'address_moved'], array_column($r['queue'], 'review_state'));
        self::assertSame('Fan A', self::fan($r, 'new@x.test')['name']);
        self::assertSame(0, self::mailCount($r));
        self::assertStringContainsString('needs your review', self::flashed($r));
    }

    public function testRejectingAHeldRowWritesNothingElseAndTheSiblingCanBeRejectedToo(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [['email' => 'new@x.test'] + self::FAN], 'steps' => [
            ['do' => 'queue', 'as' => 'sibling', 'changes' => ['name' => 'Name Only']],
            ['do' => 'queue', 'as' => 'rename', 'changes' => ['email' => 'new@x.test']],
            ['do' => 'approve', 'id' => 'rename'],
            ['do' => 'approve', 'id' => 'sibling'],
            ['do' => 'trace_from_here'],
            ['do' => 'reject', 'id' => 'rename'],
            ['do' => 'reject', 'id' => 'sibling'],
        ]]);

        self::assertSame([], $r['queue']);
        self::assertSame('Fan A', self::fan($r, 'new@x.test')['name']);
        self::assertSame([], array_values(array_filter($r['statements'], static fn(string $s): bool => str_starts_with($s, 'UPDATE '))));
    }

    public function testAfterACrashANameOnlyRetryIsNothingToDoWithNoMail(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [['name' => 'Already Changed'] + self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'Already Changed']],
            ['do' => 'approve'],
        ]]);

        self::assertSame([], $r['queue']);
        self::assertSame(0, self::mailCount($r));
    }

    // ------------------------------------------------------------------
    // The dates, through the real approval
    // ------------------------------------------------------------------

    public function testABatchSkipsAndReportsAHeldRow(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, ['email' => 'c@x.test', 'name' => 'Fan C', 'added' => '2020-01-01']], 'steps' => [
            ['do' => 'queue', 'as' => 'held', 'changes' => ['name' => 'Stopped'], 'review_state' => 'joined_after_request'],
            ['do' => 'queue', 'email' => 'c@x.test', 'changes' => ['name' => 'Applied']],
            ['do' => 'approve_all'],
        ]]);

        self::assertSame('Fan A', self::fan($r)['name']);
        self::assertSame('Applied', self::fan($r, 'c@x.test')['name']);
        self::assertCount(1, $r['queue']);
        self::assertStringContainsString('Approved 1 pending update. 1 needs review and stayed in the queue.', self::flashed($r));
    }

    public function testASameAddressReplacementFromBeforeTheChecksIsStoppedNotApplied(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [['added' => '2026-06-15'] + self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'Name The Old Fan Asked For'], 'submitted_at' => '2026-06-15 12:00:00', 'review_state' => 'queued_before_checks'],
            ['do' => 'approve'],
        ]]);

        self::assertSame('Fan A', self::fan($r)['name']);
        self::assertSame('may_be_other_member', $r['queue'][0]['review_state']);
        self::assertSame(0, self::mailCount($r));
    }

    public function testAMemberWhoJoinedLongBeforeARequestFromBeforeTheChecksAppliesAsNormal(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name'], 'submitted_at' => '2026-06-15 12:00:00', 'review_state' => 'queued_before_checks'],
            ['do' => 'approve'],
        ]]);

        self::assertSame('New Name', self::fan($r)['name']);
        self::assertSame([], $r['queue']);
    }

    public function testAnOrdinaryRowIsHeldOnlyIfTheMemberJoinedMoreThanADayAfter(): void
    {
        $cases = [
            'the same day'   => ['2026-06-15', false],
            'the next day'   => ['2026-06-16', false],
            'two days later' => ['2026-06-17', true],
            'blank date'     => ['', false],
        ];

        foreach ($cases as $label => [$added, $held]) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [['added' => $added] + self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'New Name'], 'submitted_at' => '2026-06-15 12:00:00'],
                ['do' => 'approve'],
            ]]);

            self::assertSame($held ? 'Fan A' : 'New Name', self::fan($r)['name'], $label);
            self::assertSame($held ? ['joined_after_request'] : [], array_column($r['queue'], 'review_state'), $label);
        }
    }

    public function testBeforeTheMigrationARowGetsTheStrictCheckAndNothingThrows(): void
    {
        $scenarios = [
            'joined long before' => ['layout' => 'owned', 'no_review_state' => true, 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'New Name'], 'submitted_at' => '2026-06-15 12:00:00'],
                ['do' => 'approve'],
            ]],
            'joined the same day' => ['layout' => 'owned', 'no_review_state' => true, 'members' => [['added' => '2026-06-15'] + self::FAN], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'New Name'], 'submitted_at' => '2026-06-15 12:00:00'],
                ['do' => 'approve'],
            ]],
            'clearing stale rows' => ['layout' => 'owned', 'no_review_state' => true, 'members' => [], 'steps' => [
                ['do' => 'queue', 'email' => 'new@x.test', 'changes' => ['name' => 'Left Behind']],
                ['do' => 'add', 'email' => 'new@x.test'],
            ]],
        ];

        $results = $this->runScenarios($scenarios);

        self::assertNull($results['joined long before']['error']);
        self::assertSame('New Name', self::fan($results['joined long before'])['name']);
        self::assertNull($results['joined the same day']['error']);
        self::assertSame('Fan A', self::fan($results['joined the same day'])['name']);
        self::assertCount(1, $results['joined the same day']['queue'], 'Stopped, with nowhere to store the hold');
        self::assertStringContainsString('needs your review', self::flashed($results['joined the same day']));
        self::assertNull($results['clearing stale rows']['error']);
        self::assertSame([], $results['clearing stale rows']['queue']);
    }

    public function testAHeldRowSurvivesAJoinAndAnAddAtItsAddress(): void
    {
        foreach (['join' => ['do' => 'join', 'email' => 'new@x.test'], 'add' => ['do' => 'add', 'email' => 'new@x.test']] as $path => $step) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [], 'steps' => [
                ['do' => 'queue', 'as' => 'stale', 'email' => 'new@x.test', 'changes' => ['name' => 'Left Behind']],
                ['do' => 'queue', 'as' => 'held', 'email' => 'new@x.test', 'changes' => ['name' => 'Held Back'], 'review_state' => 'address_moved'],
                $step,
            ]]);

            self::assertNull($r['error'], $path);
            self::assertCount(1, $r['queue'], $path);
            self::assertSame('address_moved', $r['queue'][0]['review_state'], $path);
        }
    }

    // ------------------------------------------------------------------
    // Rejecting
    // ------------------------------------------------------------------

    public function testARejectionThatLostSaysItWasAlreadyHandled(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'New Name']],
            ['do' => 'before', 'match' => '/DELETE FROM lstr_pending_updates WHERE id/', 'sql' => 'DELETE FROM lstr_pending_updates'],
            ['do' => 'reject'],
        ]]);

        self::assertStringContainsString('already handled', self::flashed($r));
        self::assertStringNotContainsString('rejected', self::flashed($r));
        self::assertMemberUnchanged($r, 'nothing applied');
    }

    public function testBulkRejectCountsOnlyWhatItRemoved(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'as' => 'one', 'changes' => ['name' => 'One']],
            ['do' => 'queue', 'as' => 'two', 'changes' => ['name' => 'Two']],
            ['do' => 'before', 'match' => '/DELETE FROM lstr_pending_updates WHERE id/', 'sql' => 'DELETE FROM lstr_pending_updates WHERE id = 1'],
            ['do' => 'bulk_reject', 'ids' => ['one', 'two']],
        ]]);

        self::assertStringContainsString('Rejected 1 pending update.', self::flashed($r));
        self::assertSame([], $r['queue']);
    }
}
