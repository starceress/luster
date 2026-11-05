<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use PHPUnit\Framework\TestCase;

/**
 * Changing a member's address, from the three forms that do it directly: Edit Member,
 * public Update and Collective Update. Queued requests, link checks and reset links
 * point at a member by their address with no foreign key, so they have to move with it,
 * and a failure has to say honestly what happened.
 */
final class MemberEmailRekeyTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const FAN = ['email' => 'a@x.test', 'name' => 'Fan A', 'country' => 'Canada', 'url' => 'https://a.test/', 'added' => '2020-01-01', 'password' => 'a-password-1'];

    private const FAIL_READ_BACK = ['do' => 'fail_after', 'seen' => '/^UPDATE fl_moon/', 'regex' => '/^SELECT \* FROM fl_moon/'];

    /** The steps that change this fan's address on each form, with the new address filled in. */
    private static function forms(string $new = 'new@x.test'): array
    {
        return [
            'Edit Member'       => ['setup' => [], 'change' => [['do' => 'edit', 'post' => ['email' => $new, 'name' => 'Fan A', 'url' => 'https://a.test/']]]],
            'public Update'     => ['setup' => [['do' => 'signed_in']], 'change' => [['do' => 'submit', 'post' => ['new_email' => $new]]]],
            'Collective Update' => ['setup' => [['do' => 'collective_token']], 'change' => [['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'new_email' => $new]]]],
        ];
    }

    private static function fan(array $r, string $email): ?array
    {
        foreach ($r['members'][1] as $row) {
            if ($row['email'] === $email) {
                return $row;
            }
        }
        return null;
    }

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

    private static function sideTables(): array
    {
        return [
            ['do' => 'reset_token'],
            ['do' => 'sql', 'sql' => "INSERT INTO lstr_member_link_checks (listing_id, member_email, member_name, member_url) VALUES (1, 'a@x.test', 'Fan A', 'https://a.test/')"],
            ['do' => 'queue', 'changes' => ['country' => 'Peru']],
        ];
    }

    public function testEveryFormMovesEverythingThatPointsAtTheOldAddress(): void
    {
        foreach (self::forms() as $form => $steps) {
            foreach (['owned', 'enth'] as $layout) {
                $r = $this->runOne(['layout' => $layout, 'members' => [self::FAN], 'steps' => [...self::sideTables(), ...$steps['setup'], ...$steps['change']]]);
                $why = "{$form}, {$layout}";

                self::assertNull($r['error'], $why);
                self::assertNotNull(self::fan($r, 'new@x.test'), $why);
                self::assertNull(self::fan($r, 'a@x.test'), $why);
                self::assertSame(['new@x.test'], array_column($r['queue'], 'member_email'), "{$why}: the queued request follows");
                self::assertNull($r['queue'][0]['review_state'], $why);
                self::assertSame('new@x.test', $r['link_checks'][0]['member_email'], "{$why}: the link check follows");
                self::assertSame([], $r['resets'], "{$why}: a reset link can't be renamed");
            }
        }
    }

    public function testLinksGoFirstThenRequestsMoveThenTheMemberIsWrittenAndReadBackBeforeCommit(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['country' => 'Peru']],
            ['do' => 'signed_in'],
            ['do' => 'trace_from_here'],
            ['do' => 'submit', 'post' => ['new_email' => 'new@x.test']],
        ]]);

        $sequence = [];
        foreach ($r['statements'] as $sql) {
            $sql = ltrim((string) $sql);
            foreach (['BEGIN' => '/^BEGIN/', 'requests move' => '/^UPDATE lstr_pending_updates SET member_email/', 'links go' => '/^DELETE FROM lstr_fan_password_resets/', 'member written' => '/^UPDATE fl_moon/', 'COMMIT' => '/^COMMIT/', 'link check moves' => '/^UPDATE `lstr_member_link_checks` SET member_email/'] as $name => $pattern) {
                if (preg_match($pattern, $sql) === 1) {
                    $sequence[] = $name;
                }
            }
        }

        self::assertSame(['links go', 'links go', 'BEGIN', 'requests move', 'member written', 'COMMIT', 'link check moves'], $sequence);
    }

    public function testStaleRowsAtTheNewAddressAreClearedButAHeldOneStays(): void
    {
        foreach (self::forms() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'queue', 'as' => 'stale', 'email' => 'new@x.test', 'changes' => ['name' => 'Left Behind']],
                ['do' => 'queue', 'as' => 'held', 'email' => 'new@x.test', 'changes' => ['name' => 'Held Back'], 'review_state' => 'address_moved'],
                ...$steps['setup'],
                ...$steps['change'],
            ]]);

            self::assertNull($r['error'], $form);
            self::assertCount(1, $r['queue'], $form);
            self::assertStringContainsString('Held Back', $r['queue'][0]['changes'], $form);
        }
    }

    public function testAChangeOfCapitalsOnEditMemberKeepsTheMembersOwnRows(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'case_insensitive' => true, 'members' => [self::FAN], 'steps' => [
            ['do' => 'queue', 'changes' => ['country' => 'Peru']],
            ['do' => 'edit', 'post' => ['email' => 'A@X.test', 'name' => 'Fan A', 'url' => 'https://a.test/']],
        ]]);

        self::assertNull($r['error']);
        self::assertNotNull(self::fan($r, 'A@X.test'));
        self::assertSame(['A@X.test'], array_column($r['queue'], 'member_email'));
    }

    public function testAFailureBeforeTheMemberWriteLeavesEverythingAndEachFormSaysSo(): void
    {
        $said = [
            'Edit Member'       => "Couldn't save this member because of a database error. Nothing was changed",
            'public Update'     => "We couldn't save your changes because of a database error.",
            'Collective Update' => "We couldn't save your changes because of a database error, and nothing was changed.",
        ];

        foreach (self::forms() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ...self::sideTables(),
                ...$steps['setup'],
                ['do' => 'fail_on', 'regex' => '/UPDATE lstr_pending_updates SET member_email/'],
                ...$steps['change'],
            ]]);

            self::assertNull($r['error'], $form);
            self::assertNotNull(self::fan($r, 'a@x.test'), $form);
            self::assertNull(self::fan($r, 'new@x.test'), $form);
            self::assertSame(['a@x.test'], array_column($r['queue'], 'member_email'), $form);
            self::assertNull($r['queue'][0]['review_state'], "{$form}: nothing is held after an ordinary failure");
            self::assertSame('a@x.test', $r['link_checks'][0]['member_email'], $form);
            self::assertStringContainsString($said[$form], self::flashed($r), $form);
            self::assertStringNotContainsString('--LSTR-MAIL-END--', (string) $r['mail'], "{$form}: no confirmation for a change that didn't happen");
        }
    }

    public function testOnATransactionalTableAFailedReadBackLeavesEverythingAsItWas(): void
    {
        foreach (self::forms() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ...self::sideTables(),
                ...$steps['setup'],
                self::FAIL_READ_BACK,
                ...$steps['change'],
            ]]);

            self::assertNull($r['error'], $form);
            self::assertNotNull(self::fan($r, 'a@x.test'), $form);
            self::assertSame(['a@x.test'], array_column($r['queue'], 'member_email'), $form);
            self::assertNull($r['queue'][0]['review_state'], $form);
        }
    }

    public function testWhenTheAddressChangeMayHaveLandedEveryRequestAtTheOldAddressIsHeld(): void
    {
        $said = [
            'Edit Member'       => "Luster couldn't confirm this save finished, so the member may already have some of these changes. Their queued requests are being held for you to check.",
            'public Update'     => "We couldn't confirm your changes were saved.",
            'Collective Update' => "We couldn't confirm your changes were saved.",
        ];

        foreach (self::forms() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ...self::sideTables(),
                ['do' => 'queue', 'as' => 'name_only', 'changes' => ['name' => 'Only A Name']],
                ...$steps['setup'],
                ['do' => 'survives_rollback', 'sql' => "UPDATE fl_moon SET email = 'new@x.test' WHERE email = 'a@x.test'"],
                self::FAIL_READ_BACK,
                ...$steps['change'],
            ]]);

            self::assertNull($r['error'], $form);
            self::assertNotNull(self::fan($r, 'new@x.test'), $form);
            self::assertSame(['a@x.test', 'a@x.test'], array_column($r['queue'], 'member_email'), "{$form}: rollback put them back");
            self::assertSame(['write_unconfirmed', 'write_unconfirmed'], array_column($r['queue'], 'review_state'), "{$form}: the name-only one too, or it would be thrown away");
            self::assertStringContainsString($said[$form], self::flashed($r), $form);
            self::assertStringNotContainsString('--LSTR-MAIL-END--', (string) $r['mail'], $form);
        }
    }

    public function testACollectiveUpdateThatPartlyWorkedSaysWhichListingsDidAndDidnt(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, ['listing' => 2] + self::FAN], 'steps' => [
            ['do' => 'collective_token'],
            ['do' => 'fail_on_after', 'regex' => '/UPDATE lstr_pending_updates SET member_email/', 'let_through' => 1],
            ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'new_email' => 'new@x.test']],
        ]]);

        self::assertNull($r['error']);
        self::assertSame(['new@x.test'], array_column($r['members'][1], 'email'));
        self::assertSame(['a@x.test'], array_column($r['members'][2], 'email'));

        $page = html_entity_decode(self::flashed($r), ENT_QUOTES);
        self::assertStringContainsString('successfully updated', $page);
        self::assertStringContainsString('Moon FL', $page);
        self::assertStringContainsString("We couldn't save your changes on the following listing(s)", $page);
        self::assertStringContainsString('Sun FL', $page);
    }

    public function testEditMemberMakesNoPromiseAboutTheQueueWhenNothingCouldBeHeld(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'survives_rollback', 'sql' => "UPDATE fl_moon SET email = 'new@x.test' WHERE email = 'a@x.test'"],
            self::FAIL_READ_BACK,
            ['do' => 'edit', 'post' => ['email' => 'new@x.test', 'name' => 'Fan A', 'url' => 'https://a.test/']],
        ]]);

        self::assertStringContainsString("Luster couldn't confirm this save finished. Check this member's details", self::flashed($r));
    }

    // ------------------------------------------------------------------
    // Links can't outlive a change of address
    // ------------------------------------------------------------------

    /** The steps that change this fan's address, the three forms and an approval. */
    private static function everyRename(string $new = 'new@x.test'): array
    {
        return self::forms($new) + [
            'Approve' => ['setup' => [['do' => 'queue', 'changes' => ['email' => $new]]], 'change' => [['do' => 'approve']]],
        ];
    }

    public function testAResetLinkCantSurviveAFailedRenameAndWorkOnWhoeverGetsTheAddressNext(): void
    {
        $c = ['email' => 'c@x.test', 'name' => 'Fan C', 'country' => 'Canada', 'added' => '2020-01-01', 'password' => 'c-password-1'];

        foreach (self::everyRename() as $form => $steps) {
            foreach (['owned' => 'fl_moon', 'enth' => 'fl_moon_members'] as $layout => $table) {
                $r = $this->runOne(['layout' => $layout, 'members' => [self::FAN, $c], 'steps' => [
                    ['do' => 'reset_token'],
                    ...$steps['setup'],
                    ['do' => 'survives_rollback', 'sql' => "UPDATE {$table} SET email = 'new@x.test' WHERE email = 'a@x.test'"],
                    self::FAIL_READ_BACK,
                    ...$steps['change'],
                    ['do' => 'edit', 'email' => 'c@x.test', 'post' => ['email' => 'a@x.test', 'name' => 'Fan C']],
                    ['do' => 'reset_confirm', 'password' => 'Taken-Over-1!'],
                ]]);

                $why = "{$form}, {$layout}";
                self::assertNull($r['error'], $why);
                self::assertNotNull(self::fan($r, 'new@x.test'), "{$why}: the rename stuck");
                $now = self::fan($r, 'a@x.test');
                self::assertSame('Fan C', $now['name'] ?? null, "{$why}: C has the old address now");
                self::assertFalse(password_verify('Taken-Over-1!', $now['password']), "{$why}: the old link must not change C's password");
                self::assertTrue(password_verify('c-password-1', $now['password']), $why);
                self::assertStringContainsString('expired or is invalid', self::flashed($r), $why);
            }
        }
    }

    public function testWhenARenameFailsAndNothingChangedTheLinkIsStillGone(): void
    {
        foreach (self::everyRename() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ['do' => 'reset_token'],
                ...$steps['setup'],
                self::FAIL_READ_BACK,
                ...$steps['change'],
            ]]);

            self::assertNull($r['error'], $form);
            self::assertNotNull(self::fan($r, 'a@x.test'), $form);
            self::assertSame([], $r['resets'], "{$form}: the most a failure costs is asking for a new link");
        }
    }

    public function testLinksGoInTheirOwnStatementsBeforeAnyTransactionTouchesTheMember(): void
    {
        foreach (self::everyRename() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ...$steps['setup'],
                ['do' => 'trace_from_here'],
                ...$steps['change'],
            ]]);

            $open    = false;
            $deletes = [];
            $write   = null;
            foreach ($r['statements'] as $at => $sql) {
                $sql = ltrim((string) $sql);
                if ($sql === 'BEGIN') {
                    $open = true;
                } elseif ($sql === 'COMMIT' || $sql === 'ROLLBACK') {
                    $open = false;
                } elseif (preg_match('/^DELETE FROM (lstr_fan_password_resets|lstr_collective_update_tokens)\s+WHERE (fanlisting_id|email) /', $sql) === 1) {
                    self::assertFalse($open, "{$form}: a rollback would bring this back: {$sql}");
                    $deletes[] = $at;
                } elseif (preg_match('/^UPDATE fl_moon/', $sql) === 1) {
                    $write = $at;
                }
            }

            self::assertCount(3, $deletes, "{$form}: both addresses' reset links, and the new address's Collective Update link");
            self::assertNotNull($write, $form);
            self::assertLessThan($write, max($deletes), "{$form}: the links go before the member is written");
        }
    }

    public function testAnAddressChangingHandsTakesNoLinksWithIt(): void
    {
        $arrivals = [
            'Join'       => ['setup' => [], 'change' => [['do' => 'join', 'email' => 'new@x.test']]],
            'Add Member' => ['setup' => [], 'change' => [['do' => 'add', 'email' => 'new@x.test']]],
        ] + self::everyRename();

        foreach ($arrivals as $how => $steps) {
            foreach (['owned', 'enth'] as $layout) {
                $r = $this->runOne(['layout' => $layout, 'members' => [self::FAN], 'steps' => [
                    ['do' => 'reset_token', 'email' => 'new@x.test'],
                    ['do' => 'collective_token', 'email' => 'new@x.test'],
                    ...$steps['setup'],
                    ...$steps['change'],
                    ['do' => 'reset_confirm', 'password' => 'Taken-Over-1!'],
                ]]);

                $why = "{$how}, {$layout}";
                self::assertNull($r['error'], $why);
                $now = self::fan($r, 'new@x.test');
                self::assertNotNull($now, $why);
                self::assertFalse(password_verify('Taken-Over-1!', $now['password']), "{$why}: an old link must not work on whoever gets the address");
                self::assertSame([], $r['resets'], $why);
                self::assertNotContains('new@x.test', array_column($r['collective_tokens'], 'email'), $why);
            }
        }
    }

    public function testAChangeOfCapitalsDropsResetLinksButNotTheMailboxsOtherLink(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'case_insensitive' => true, 'members' => [self::FAN], 'steps' => [
            ['do' => 'reset_token'],
            ['do' => 'collective_token'],
            ['do' => 'edit', 'post' => ['email' => 'A@X.test', 'name' => 'Fan A', 'url' => 'https://a.test/']],
        ]]);

        self::assertNull($r['error']);
        self::assertSame([], $r['resets']);
        self::assertSame(['a@x.test'], array_column($r['collective_tokens'], 'email'), "It's the same mailbox, so nobody new has it");
    }

    public function testIfALinkCantBeClearedNothingElseHappens(): void
    {
        foreach (['reset links' => '/DELETE FROM lstr_fan_password_resets/', 'Collective Update links' => '/DELETE FROM lstr_collective_update_tokens\s+WHERE email/', 'stale requests' => '/DELETE FROM lstr_pending_updates\s+WHERE fanlisting_id/'] as $what => $regex) {
            foreach (self::everyRename() as $form => $steps) {
                $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                    ...$steps['setup'],
                    ['do' => 'trace_from_here'],
                    ['do' => 'fail_on', 'regex' => $regex],
                    ...$steps['change'],
                ]]);

                $why = "{$form}, {$what}";
                self::assertNull($r['error'], $why);
                self::assertNotNull(self::fan($r, 'a@x.test'), $why);
                self::assertNull(self::fan($r, 'new@x.test'), $why);
                self::assertSame([], array_values(array_filter($r['statements'], static fn(string $s): bool => str_starts_with(ltrim($s), 'UPDATE fl_moon'))), "{$why}: the member is never written");
                self::assertStringNotContainsString('--LSTR-MAIL-END--', (string) $r['mail'], $why);
            }
        }

        foreach (['join', 'add'] as $do) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [], 'steps' => [
                ['do' => 'fail_on', 'regex' => '/DELETE FROM lstr_fan_password_resets/'],
                ['do' => $do, 'email' => 'new@x.test'],
            ]]);

            self::assertNull(self::fan($r, 'new@x.test'), "{$do}: no member unless the address was cleared first");
        }
    }

    public function testAResetLinkIsIssuedUnderTheAddressLock(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'trace_from_here'],
            ['do' => 'reset_request'],
        ]]);

        self::assertNull($r['error']);
        self::assertCount(1, $r['resets']);
        $lock    = self::firstAt($r, 'GET_LOCK');
        $issued  = self::firstAt($r, 'INSERT INTO lstr_fan_password_resets');
        $release = self::firstAt($r, 'RELEASE_LOCK');
        self::assertLessThan($issued, $lock);
        self::assertLessThan($release, $issued);
        self::assertSame(0, $r['mail_at_release'][0] ?? null, 'Mail goes out once the lock is gone');
        self::assertStringContainsString('--LSTR-MAIL-END--', (string) $r['mail']);
    }

    public function testABusyAddressGetsTheSameAnswerAndNoLink(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'set_lock', 'answer' => 0],
            ['do' => 'reset_request'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame([], $r['resets']);
        self::assertStringNotContainsString('--LSTR-MAIL-END--', (string) $r['mail']);
        self::assertStringContainsString('fl-success', self::flashed($r), 'It must not give away that the address is in use');
    }

    public function testAResetRequestFromSomeoneWhoLeftWhileWaitingForTheLockGetsNoLink(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'before', 'match' => '/GET_LOCK/', 'sql' => "UPDATE fl_moon SET email = 'new@x.test' WHERE email = 'a@x.test'"],
            ['do' => 'reset_request'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame([], $r['resets']);
        self::assertStringNotContainsString('--LSTR-MAIL-END--', (string) $r['mail']);
    }

    // ------------------------------------------------------------------
    // A new password on the same form as a new address
    // ------------------------------------------------------------------

    private const NEW_PASSWORD = 'A-New-Password-1!';

    /** Public Update and Collective Update, the two forms that take both, with a new password. */
    private static function passwordForms(): array
    {
        $both = ['new_email' => 'new@x.test', 'new_password' => self::NEW_PASSWORD, 'new_password_confirm' => self::NEW_PASSWORD];

        return [
            'public Update'     => ['setup' => [['do' => 'signed_in']], 'change' => [['do' => 'submit', 'post' => $both]]],
            'Collective Update' => ['setup' => [['do' => 'collective_token']], 'change' => [['do' => 'collective', 'post' => ['listing_ids' => ['1']] + $both]]],
        ];
    }

    public function testANewPasswordGoesInTheSameWriteAsTheNewAddress(): void
    {
        foreach (self::passwordForms() as $form => $steps) {
            foreach (['owned', 'enth'] as $layout) {
                $r = $this->runOne(['layout' => $layout, 'members' => [self::FAN], 'steps' => [
                    ...$steps['setup'],
                    ['do' => 'trace_from_here'],
                    ...$steps['change'],
                ]]);

                $why = "{$form}, {$layout}";
                self::assertNull($r['error'], $why);
                $writes = array_values(array_filter($r['statements'], static fn(string $s): bool => str_starts_with(ltrim($s), 'UPDATE fl_moon')));
                self::assertCount(1, $writes, $why);
                self::assertStringContainsString('`password`', $writes[0], $why);
                self::assertStringContainsString('`email`', $writes[0], $why);
                self::assertTrue(password_verify(self::NEW_PASSWORD, self::fan($r, 'new@x.test')['password']), $why);
            }
        }
    }

    public function testAFailedRenameLeavesTheOldPasswordAndSaysSo(): void
    {
        foreach (self::passwordForms() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ...$steps['setup'],
                ['do' => 'fail_on', 'regex' => '/UPDATE lstr_pending_updates SET member_email/'],
                ...$steps['change'],
            ]]);

            self::assertNull($r['error'], $form);
            $fan = self::fan($r, 'a@x.test');
            self::assertTrue(password_verify('a-password-1', $fan['password']), "{$form}: nothing changed, the password included");
            self::assertFalse(password_verify(self::NEW_PASSWORD, $fan['password']), $form);
            self::assertStringContainsString('Your old password still works', html_entity_decode(self::flashed($r), ENT_QUOTES), $form);
        }
    }

    public function testARetryOnPublicUpdateWorksAfterAFailedRenameWithANewPassword(): void
    {
        $steps = self::passwordForms()['public Update'];

        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ...$steps['setup'],
            ['do' => 'fail_on', 'regex' => '/UPDATE lstr_pending_updates SET member_email/'],
            ...$steps['change'],
            ...$steps['change'],
        ]]);

        self::assertNull($r['error']);
        self::assertStringNotContainsString('Please sign in again', self::flashed($r));
        self::assertTrue(password_verify(self::NEW_PASSWORD, self::fan($r, 'new@x.test')['password']));
    }

    public function testAnUnconfirmedRenameSaysEitherPasswordMayWork(): void
    {
        foreach (self::passwordForms() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ...$steps['setup'],
                ['do' => 'survives_rollback', 'sql' => "UPDATE fl_moon SET email = 'new@x.test' WHERE email = 'a@x.test'"],
                self::FAIL_READ_BACK,
                ...$steps['change'],
            ]]);

            self::assertNull($r['error'], $form);
            self::assertStringContainsString("if your new password doesn't work, try your old one", html_entity_decode(self::flashed($r), ENT_QUOTES), $form);
        }
    }

    public function testNoNewPasswordMeansNoWordAboutOne(): void
    {
        foreach (self::forms() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ...$steps['setup'],
                ['do' => 'fail_on', 'regex' => '/UPDATE lstr_pending_updates SET member_email/'],
                ...$steps['change'],
            ]]);

            self::assertStringNotContainsString('password', self::flashed($r), $form);
        }
    }

    public function testAQueuedListingStillGetsItsPasswordAtOnceAndNotInTheQueue(): void
    {
        foreach (self::passwordForms() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'queue_updates' => true, 'members' => [self::FAN], 'steps' => [
                ...$steps['setup'],
                ...$steps['change'],
            ]]);

            self::assertNull($r['error'], $form);
            self::assertTrue(password_verify(self::NEW_PASSWORD, self::fan($r, 'a@x.test')['password']), $form);
            self::assertStringNotContainsString('password', (string) $r['queue'][0]['changes'], $form);
            self::assertStringContainsString('new@x.test', (string) $r['queue'][0]['changes'], $form);
        }
    }

    public function testACollectiveUpdateSaysWhichPasswordWorksWhereWhenItPartlyFails(): void
    {
        $both = ['new_email' => 'new@x.test', 'new_password' => self::NEW_PASSWORD, 'new_password_confirm' => self::NEW_PASSWORD];

        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, ['listing' => 2] + self::FAN], 'steps' => [
            ['do' => 'collective_token'],
            ['do' => 'fail_on_after', 'regex' => '/UPDATE lstr_pending_updates SET member_email/', 'let_through' => 1],
            ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2']] + $both],
        ]]);

        self::assertNull($r['error']);
        self::assertTrue(password_verify(self::NEW_PASSWORD, $r['members'][1][0]['password']), 'Moon went through');
        self::assertTrue(password_verify('a-password-1', $r['members'][2][0]['password']), 'Sun did not');
        self::assertStringContainsString('Your old password still works there', html_entity_decode(self::flashed($r), ENT_QUOTES));
    }

    // ------------------------------------------------------------------
    // A transaction that can't be closed
    // ------------------------------------------------------------------

    public function testWhenARollbackFailsEachFormStopsAndClaimsNothing(): void
    {
        $said = [
            'Edit Member'       => "Luster couldn't confirm this save finished. Check this member's details",
            'public Update'     => "We couldn't confirm your changes were saved.",
            'Collective Update' => "We couldn't confirm your changes were saved.",
        ];

        foreach (self::forms() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ...$steps['setup'],
                ['do' => 'trace_from_here'],
                ['do' => 'fail_rollback'],
                self::FAIL_READ_BACK,
                ...$steps['change'],
                ['do' => 'connection_ends'],
            ]]);

            self::assertNull($r['error'], $form);
            self::assertStringContainsString($said[$form], self::flashed($r), $form);
            self::assertStringNotContainsString('--LSTR-MAIL-END--', (string) $r['mail'], "{$form}: no confirmation");
            self::assertSame([], $r['activity'], "{$form}: no more database work");
            self::assertSame(['a@x.test'], array_column($r['members'][1], 'email'), "{$form}: the server rolled it back when the request ended");
        }
    }

    public function testPublicUpdateEndsTheSignInWhenARollbackFails(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'signed_in'],
            ['do' => 'fail_rollback'],
            self::FAIL_READ_BACK,
            ['do' => 'submit', 'post' => ['new_email' => 'new@x.test']],
            ['do' => 'connection_ends'],
        ]]);

        self::assertSame([], array_values(array_filter($r['session_authed'], static fn(string $k): bool => str_contains($k, 'update_authed'))));
    }

    public function testACollectiveUpdateTouchesNoMoreListingsAfterARollbackFails(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, ['listing' => 2] + self::FAN], 'steps' => [
            ['do' => 'collective_token'],
            ['do' => 'trace_from_here'],
            ['do' => 'fail_rollback'],
            self::FAIL_READ_BACK,
            ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'new_email' => 'new@x.test']],
            ['do' => 'connection_ends'],
        ]]);

        self::assertNull($r['error']);

        // After the write and its failed read-back, only the locks being let go and the connection ending.
        $after = array_slice($r['statements'], self::firstAt($r, 'UPDATE fl_moon') + 2);
        self::assertSame([], array_values(array_filter($after, static fn(string $s): bool => !str_contains($s, 'RELEASE_LOCK') && $s !== 'ROLLBACK')), 'Listing 2 is never read or written');

        self::assertSame(['a@x.test'], array_column($r['members'][2], 'email'));
        self::assertStringNotContainsString('--LSTR-MAIL-END--', (string) $r['mail']);
        self::assertStringContainsString("We couldn't confirm your changes were saved.", self::flashed($r));
    }

    public function testACollectiveUpdateThatStopsPartwaySendsNoEmailButSaysWhatWentThrough(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN, ['listing' => 2] + self::FAN], 'steps' => [
            ['do' => 'collective_token'],
            ['do' => 'fail_rollback'],
            ['do' => 'fail_after', 'seen' => '/^UPDATE fl_sun/', 'regex' => '/^SELECT \* FROM fl_sun/'],
            ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'new_email' => 'new@x.test']],
            ['do' => 'connection_ends'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame(['new@x.test'], array_column($r['members'][1], 'email'), 'Moon committed before the trouble');
        self::assertStringNotContainsString('--LSTR-MAIL-END--', (string) $r['mail'], 'Sending it would mean more database work');

        $page = html_entity_decode(self::flashed($r), ENT_QUOTES);
        self::assertStringContainsString('successfully updated', $page);
        self::assertStringContainsString("We couldn't confirm your changes were saved on the following listing(s)", $page);
    }

    public function testARenameThatStartsInsideAnOpenTransactionDoesNothing(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
            ['do' => 'signed_in'],
            ['do' => 'reset_token'],
            ['do' => 'open_transaction'],
            ['do' => 'trace_from_here'],
            ['do' => 'submit', 'post' => ['new_email' => 'new@x.test']],
            ['do' => 'connection_ends'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame([], array_values(array_filter($r['statements'], static fn(string $s): bool => (bool) preg_match('/^\s*(DELETE FROM lstr_fan_password_resets|UPDATE fl_moon|UPDATE lstr_pending_updates|BEGIN)/', $s))), 'It would land in a transaction it does not own');
        self::assertSame(['a@x.test'], array_column($r['members'][1], 'email'));
    }

    public function testALinkCheckFailureAfterCommitLeavesTheChangeStanding(): void
    {
        foreach (self::forms() as $form => $steps) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN], 'steps' => [
                ...$steps['setup'],
                ['do' => 'fail_on', 'regex' => '/UPDATE `lstr_member_link_checks` SET member_email/'],
                ...$steps['change'],
            ]]);

            self::assertNull($r['error'], $form);
            self::assertNotNull(self::fan($r, 'new@x.test'), $form);
            self::assertStringContainsString('Moving a link check', (string) $r['log'], $form);
        }
    }
}
