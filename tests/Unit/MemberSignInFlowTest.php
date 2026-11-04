<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use PHPUnit\Framework\TestCase;

/**
 * A public Update sign-in belongs to the member who signed in, not to the address they
 * used, and everything that depends on who's at an address takes its lock first. Fan A
 * signs in, A is removed, and Fan B gets that address: A's open form used to be able to
 * replace B's password, change B's details or delete B.
 */
final class MemberSignInFlowTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const LAYOUTS  = ['owned', 'enth'];
    private const NEW_PW   = 'chosen-by-fan-a-99';
    private const BUSY     = 'Please try again in a moment.';
    private const SIGN_IN  = 'Please sign in again.';

    private const FAN_A = ['listing' => 1, 'email' => 'a@x.test', 'name' => 'Fan A', 'password' => 'a-password-1'];

    /** @return list<array<string, mixed>> A is removed and B gets the address. */
    private static function replaceWithB(string $bPassword = 'b-password-1'): array
    {
        return [
            ['do' => 'remove'],
            ['do' => 'insert_member', 'member' => ['email' => 'a@x.test', 'name' => 'Fan B', 'country' => 'Peru', 'password' => $bPassword]],
        ];
    }

    /** @return array<string, array{post: array<string, string>, queue: bool}> What A's open form could send. */
    private static function attempts(): array
    {
        return [
            'password' => ['post' => ['new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW], 'queue' => false],
            'name'     => ['post' => ['new_name' => 'Chosen By A'], 'queue' => false],
            'queued'   => ['post' => ['new_name' => 'Chosen By A'], 'queue' => true],
            'delete'   => ['post' => ['delete_membership' => '1'], 'queue' => false],
        ];
    }

    private static function fanB(array $r): array
    {
        self::assertCount(1, $r['members'][1], 'B is still a member');
        return $r['members'][1][0];
    }

    // ------------------------------------------------------------------
    // A stale sign-in
    // ------------------------------------------------------------------

    public function testAStaleSignInCantTouchTheReplacementMember(): void
    {
        $scenarios = [];
        foreach (self::LAYOUTS as $layout) {
            foreach (self::attempts() as $label => $attempt) {
                foreach (['session' => false, 'token' => true] as $auth => $useToken) {
                    $steps = [['do' => 'signed_in'], ...self::replaceWithB()];
                    if ($useToken) {
                        $steps[] = ['do' => 'lose_session'];
                    }
                    $steps[] = ['do' => 'trace_from_here'];
                    $steps[] = ['do' => 'submit', 'post' => $attempt['post'], 'use_token' => $useToken];
                    $scenarios["{$layout}, {$label}, {$auth}"] = [
                        'layout' => $layout, 'members' => [self::FAN_A], 'queue_updates' => $attempt['queue'], 'steps' => $steps,
                    ];
                }
            }
        }

        foreach ($this->runScenarios($scenarios) as $why => $r) {
            self::assertNull($r['error'], $why);
            $b = self::fanB($r);
            self::assertSame('Fan B', $b['name'], $why);
            self::assertSame('Peru', $b['country'], $why);
            self::assertTrue(password_verify('b-password-1', (string) $b['password']), $why . ": B's own password still works");
            self::assertFalse(password_verify(self::NEW_PW, (string) $b['password']), $why);
            self::assertSame([], $r['queue'], $why . ': nothing queued against B');
            self::assertSame([], $r['session_authed'], $why . ': the sign-in has ended');
            self::assertContains(self::SIGN_IN, self::flashes($r), $why);
            self::assertSame('', $r['mail'], $why . ': no farewell to B');
        }
    }

    public function testTheSamePasswordDoesntMakeBTheSameMember(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'signed_in'], ...self::replaceWithB('a-password-1'),
            ['do' => 'submit', 'post' => ['new_name' => 'Chosen By A']],
        ]]);

        self::assertSame('Fan B', self::fanB($r)['name'], 'the hashes are salted, so they differ');
        self::assertContains(self::SIGN_IN, self::flashes($r));
    }

    public function testANormalSignInStillWorksForEveryAction(): void
    {
        $scenarios = [];
        foreach (self::LAYOUTS as $layout) {
            foreach (self::attempts() as $label => $attempt) {
                foreach (['session' => false, 'token' => true] as $auth => $useToken) {
                    $steps = [['do' => 'signed_in']];
                    if ($useToken) {
                        $steps[] = ['do' => 'lose_session'];
                    }
                    $steps[] = ['do' => 'submit', 'post' => $attempt['post'], 'use_token' => $useToken];
                    $scenarios["{$layout}, {$label}, {$auth}"] = [
                        'layout' => $layout, 'members' => [self::FAN_A], 'queue_updates' => $attempt['queue'], 'steps' => $steps,
                    ];
                }
            }
        }

        foreach ($this->runScenarios($scenarios) as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertNotContains(self::SIGN_IN, self::flashes($r), $why);
            self::assertSame([], $r['locks_held'], $why . ': every lock released');

            $label = explode(', ', $why)[1];
            if ($label === 'delete') {
                self::assertSame([], $r['members'][1], $why);
                self::assertStringContainsString('a@x.test', $r['mail'], $why . ': the farewell went out');
                continue;
            }
            $row = $r['members'][1][0];
            match ($label) {
                'password' => self::assertTrue(password_verify(self::NEW_PW, (string) $row['password']), $why),
                'name'     => self::assertSame('Chosen By A', $row['name'], $why),
                'queued'   => self::assertStringContainsString('Chosen By A', (string) $r['queue'][0]['changes'], $why),
            };
        }
    }

    public function testARealSignInStampsTheHashItJustUpgraded(): void
    {
        $results = $this->runScenarios([
            'argon2id' => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
                ['do' => 'sign_in'],
                ['do' => 'submit', 'post' => ['new_name' => 'Still Me']],
            ]],
            'legacy md5' => ['layout' => 'owned', 'members' => [self::FAN_A + ['md5' => true]], 'steps' => [
                ['do' => 'sign_in'],
                ['do' => 'submit', 'post' => ['new_name' => 'Still Me']],
            ]],
            'token after an upgrade' => ['layout' => 'enth', 'members' => [self::FAN_A + ['md5' => true]], 'steps' => [
                ['do' => 'sign_in'],
                ['do' => 'lose_session'],
                ['do' => 'submit', 'post' => ['new_name' => 'Still Me'], 'use_token' => true],
            ]],
        ]);

        foreach ($results as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertSame('Still Me', $r['members'][1][0]['name'], $why);
            self::assertStringStartsWith('$argon2id$', (string) $r['members'][1][0]['password'], $why);
        }
    }

    public function testAWrongPasswordDoesntSignInOrUpgrade(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A + ['md5' => true]], 'steps' => [
            ['do' => 'sign_in', 'password' => 'not-the-password'],
        ]]);

        self::assertSame([], $r['session_authed']);
        self::assertSame(md5('a-password-1'), $r['members'][1][0]['password']);
        self::assertSame([], $r['form_tokens']);
    }

    public function testASignInWithNoStampGoesBackToSignIn(): void
    {
        $results = $this->runScenarios([
            'a session from before the upgrade' => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
                ['do' => 'signed_in'],
                ['do' => 'drop_stamp'],
                ['do' => 'submit', 'post' => ['new_name' => 'Chosen']],
            ]],
            'a token from before the upgrade' => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
                ['do' => 'signed_in'],
                ['do' => 'lose_session'],
                ['do' => 'sql', 'sql' => 'UPDATE lstr_member_form_sessions SET member_stamp = NULL'],
                ['do' => 'submit', 'post' => ['new_name' => 'Chosen'], 'use_token' => true],
            ]],
            'an owner changed the password' => ['layout' => 'enth', 'members' => [self::FAN_A], 'steps' => [
                ['do' => 'signed_in'],
                ['do' => 'set_password', 'password' => 'set-by-the-owner-1'],
                ['do' => 'submit', 'post' => ['new_name' => 'Chosen']],
            ]],
        ]);

        foreach ($results as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertSame('Fan A', $r['members'][1][0]['name'], $why);
            self::assertContains(self::SIGN_IN, self::flashes($r), $why);
            self::assertSame([], $r['session_authed'], $why);
        }
    }

    public function testEndingASignInClearsItsTokensToo(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'sign_in'],
            ['do' => 'set_password', 'password' => 'set-by-the-owner-1'],
            ['do' => 'submit', 'post' => ['new_name' => 'Chosen']],
        ]]);

        self::assertContains(self::SIGN_IN, self::flashes($r));
        self::assertSame([], $r['session_authed'], 'nothing is left in the session for the next form to embed');
    }

    public function testBeforeTheMigrationSessionsWorkAndTokensArentIssued(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'no_migration' => true, 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'sign_in'],
            ['do' => 'submit', 'post' => ['new_name' => 'Still Me']],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('Still Me', $r['members'][1][0]['name']);
        self::assertSame([], $r['form_tokens']);
    }

    // ------------------------------------------------------------------
    // Sign-in holds the lock throughout
    // ------------------------------------------------------------------

    public function testSignInHoldsTheLockFromTheFirstReadToTheLastToken(): void
    {
        $results = $this->runScenarios([
            'argon2id' => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [['do' => 'trace_from_here'], ['do' => 'sign_in']]],
            'md5'      => ['layout' => 'owned', 'members' => [self::FAN_A + ['md5' => true]], 'steps' => [['do' => 'trace_from_here'], ['do' => 'sign_in']]],
        ]);

        foreach ($results as $why => $r) {
            self::assertNull($r['error'], $why);
            $locked   = self::firstAt($r, 'GET_LOCK');
            $released = self::lastAt($r, 'RELEASE_LOCK');
            self::assertLessThan(self::firstAt($r, 'SELECT * FROM fl_moon'), $locked, $why . ': locked before the first member read');
            self::assertGreaterThan(self::lastAt($r, 'SELECT * FROM fl_moon'), $released, $why . ': still locked for the read-back');
            self::assertGreaterThan(self::lastAt($r, 'INSERT INTO lstr_member_form_sessions'), $released, $why . ': still locked for the tokens');
            self::assertCount(2, $r['form_tokens'], $why);
        }
        self::assertLessThan(
            self::lastAt($results['md5'], 'RELEASE_LOCK'),
            self::firstAt($results['md5'], 'SET `password`'),
            'the upgrade happens under the lock',
        );
    }

    public function testABusyAddressAtSignInChangesNothing(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A + ['md5' => true]], 'steps' => [
            ['do' => 'set_lock', 'answer' => 0],
            ['do' => 'sign_in'],
        ]]);

        self::assertNull($r['error']);
        self::assertContains(self::BUSY, self::flashes($r));
        self::assertSame([], $r['session_authed'], 'not signed in');
        self::assertSame([], $r['form_tokens'], 'no tokens');
        self::assertSame(md5('a-password-1'), $r['members'][1][0]['password'], 'no upgrade');
    }

    // ------------------------------------------------------------------
    // Every lock before anything else
    // ------------------------------------------------------------------

    public function testABlockedNewAddressLeavesThePasswordAndTheTokenAlone(): void
    {
        $change = ['new_email' => 'new@x.test', 'new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW];

        foreach (self::LAYOUTS as $layout) {
            $results = $this->runScenarios([
                'session' => ['layout' => $layout, 'members' => [self::FAN_A], 'steps' => [
                    ['do' => 'signed_in'],
                    ['do' => 'set_lock', 'email' => 'new@x.test', 'answer' => 0],
                    ['do' => 'submit', 'post' => $change],
                ]],
                'token' => ['layout' => $layout, 'members' => [self::FAN_A], 'steps' => [
                    ['do' => 'signed_in'],
                    ['do' => 'lose_session'],
                    ['do' => 'set_lock', 'email' => 'new@x.test', 'answer' => 0],
                    ['do' => 'submit', 'post' => $change, 'use_token' => true],
                ]],
            ]);

            foreach ($results as $auth => $r) {
                $why = "{$layout}, {$auth}";
                self::assertNull($r['error'], $why);
                self::assertContains(self::BUSY, self::flashes($r), $why);
                self::assertSame('a@x.test', $r['members'][1][0]['email'], $why);
                self::assertTrue(password_verify('a-password-1', (string) $r['members'][1][0]['password']), $why . ': the password is unchanged');
                self::assertSame([], $r['queue'], $why);
                self::assertSame([], $r['locks_held'], $why . ": the address's own lock was released");
                foreach ($r['form_tokens'] as $token) {
                    self::assertNull($token['used_at'], $why . ': the token is still unused');
                }
            }

            $retry = $this->runOne(['layout' => $layout, 'members' => [self::FAN_A], 'steps' => [
                ['do' => 'signed_in'],
                ['do' => 'lose_session'],
                ['do' => 'set_lock', 'email' => 'new@x.test', 'answer' => 0],
                ['do' => 'submit', 'post' => $change, 'use_token' => true],
                ['do' => 'clear_lock_script'],
                ['do' => 'submit', 'post' => $change, 'use_token' => true],
            ]]);
            self::assertSame('new@x.test', $retry['members'][1][0]['email'], $layout . ': the same token works on the retry');
        }
    }

    public function testATokenIsOnlyUsedOnceEveryLockIsHeld(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'signed_in'],
            ['do' => 'lose_session'],
            ['do' => 'trace_from_here'],
            ['do' => 'submit', 'post' => ['new_email' => 'new@x.test', 'new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW], 'use_token' => true],
        ]]);

        self::assertNull($r['error']);
        $lastLock = self::lastAt($r, 'GET_LOCK');
        self::assertLessThan(self::firstAt($r, 'SET used_at'), $lastLock);
        self::assertLessThan(self::firstAt($r, 'UPDATE fl_moon'), $lastLock);
        self::assertGreaterThan(self::lastAt($r, 'UPDATE fl_moon'), self::lastAt($r, 'RELEASE_LOCK'));
        self::assertSame(2, count(array_filter($r['statements'], static fn(string $s): bool => str_contains($s, 'GET_LOCK'))), 'the old address and the new one');
    }

    public function testAnOlderServerThatDropsLocksRefusesARename(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'signed_in'],
            ['do' => 'lose_session'],
            ['do' => 'one_lock_at_a_time'],
            ['do' => 'submit', 'post' => ['new_email' => 'new@x.test', 'new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW], 'use_token' => true],
        ]]);

        self::assertNull($r['error']);
        self::assertContains(self::BUSY, self::flashes($r));
        self::assertSame('a@x.test', $r['members'][1][0]['email']);
        self::assertTrue(password_verify('a-password-1', (string) $r['members'][1][0]['password']));
        foreach ($r['form_tokens'] as $token) {
            self::assertNull($token['used_at'], 'no credential used');
        }
    }

    // ------------------------------------------------------------------
    // Renames
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> Every way a member's address changes, as scenario steps. */
    private static function renamePaths(): array
    {
        $queued = ['do' => 'queue', 'changes' => ['email' => 'new@x.test']];
        return [
            'Edit Member'       => [['do' => 'edit', 'post' => ['email' => 'new@x.test']]],
            'public Update'     => [['do' => 'signed_in'], ['do' => 'submit', 'post' => ['new_email' => 'new@x.test']]],
            'Collective Update' => [['do' => 'collective_token'], ['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'new_email' => 'new@x.test']]],
            'Approve'           => [$queued, ['do' => 'approve']],
            'Approve All'       => [$queued, ['do' => 'approve_all']],
            'bulk approve'      => [$queued, ['do' => 'bulk_approve', 'ids' => ['queued']]],
            'overview'          => [$queued, ['do' => 'approve_overview']],
        ];
    }

    public function testEveryRenameLocksBothAddressesBeforeItsFirstWrite(): void
    {
        $scenarios = [];
        foreach (self::renamePaths() as $path => $steps) {
            $last = array_pop($steps);
            $scenarios[$path] = ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [...$steps, ['do' => 'trace_from_here'], $last]];
        }

        foreach ($this->runScenarios($scenarios) as $path => $r) {
            self::assertNull($r['error'], $path);
            self::assertSame('new@x.test', $r['members'][1][0]['email'] ?? null, $path);
            $gets = array_filter($r['statements'], static fn(string $s): bool => str_contains($s, 'GET_LOCK'));
            self::assertCount(2, $gets, $path . ': the old address and the new one');
            self::assertLessThan(self::firstAt($r, 'UPDATE fl_moon'), self::lastAt($r, 'GET_LOCK'), $path);
            self::assertGreaterThan(self::lastAt($r, 'UPDATE fl_moon'), self::firstAt($r, 'RELEASE_LOCK'), $path);
            self::assertSame([], $r['locks_held'], $path);
        }
    }

    public function testABlockedNewAddressStopsEveryRename(): void
    {
        $scenarios = [];
        foreach (self::renamePaths() as $path => $steps) {
            $last = array_pop($steps);
            $scenarios[$path] = ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
                ...$steps, ['do' => 'set_lock', 'email' => 'new@x.test', 'answer' => 0], $last,
            ]];
        }

        foreach ($this->runScenarios($scenarios) as $path => $r) {
            self::assertNull($r['error'], $path);
            self::assertSame('a@x.test', $r['members'][1][0]['email'], $path . ': nothing moved');
            self::assertSame([], $r['locks_held'], $path);
            if (in_array($path, ['Approve', 'Approve All', 'bulk approve', 'overview'], true)) {
                self::assertCount(1, $r['queue'], $path . ': the request stays in the queue');
            }
        }
    }

    public function testARowWhoseAddressMovedBeforeItsLockIsRefusedNotApplied(): void
    {
        $moved = "UPDATE lstr_pending_updates SET member_email = 'moved@x.test'";
        $paths = [
            'Approve'      => ['do' => 'approve'],
            'Approve All'  => ['do' => 'approve_all'],
            'bulk approve' => ['do' => 'bulk_approve', 'ids' => ['queued']],
            'overview'     => ['do' => 'approve_overview'],
        ];

        $scenarios = [];
        foreach ($paths as $path => $step) {
            $scenarios[$path] = ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'Asked For']],
                ['do' => 'before', 'match' => '/GET_LOCK/', 'sql' => $moved],
                $step,
            ]];
        }

        foreach ($this->runScenarios($scenarios) as $path => $r) {
            self::assertNull($r['error'], $path);
            self::assertSame('Fan A', $r['members'][1][0]['name'], $path . ': not applied');
            self::assertCount(1, $r['queue'], $path . ': still queued');
            self::assertNotSame([], array_filter(self::flashes($r), static fn(string $f): bool => str_contains($f, 'changed while') || str_contains($f, "couldn't be applied")), $path . ': and it says so');
        }
    }

    public function testApprovalsGuardTheOverviewPathTheSameWay(): void
    {
        // It used to have no guards: a taken address threw, and later rows were lost.
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A, ['email' => 'taken@x.test', 'name' => 'Taken'], ['email' => 'c@x.test', 'name' => 'Fan C']], 'steps' => [
            ['do' => 'queue', 'changes' => ['email' => 'taken@x.test']],
            ['do' => 'queue', 'email' => 'c@x.test', 'changes' => ['name' => 'Later Row']],
            ['do' => 'approve_overview'],
        ]]);

        self::assertNull($r['error']);
        $names = array_column($r['members'][1], 'name', 'email');
        self::assertSame('Fan A', $names['a@x.test']);
        self::assertSame('Later Row', $names['c@x.test']);
    }

    // ------------------------------------------------------------------
    // Password resets
    // ------------------------------------------------------------------

    public function testAResetLocksBeforeLookingAgainAndWriting(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'reset_token'],
            ['do' => 'trace_from_here'],
            ['do' => 'reset_confirm', 'password' => self::NEW_PW],
        ]]);

        self::assertNull($r['error']);
        self::assertTrue(password_verify(self::NEW_PW, (string) $r['members'][1][0]['password']));
        self::assertSame([], $r['resets']);

        $locked = self::firstAt($r, 'GET_LOCK');
        self::assertLessThan(self::firstAt($r, 'SELECT * FROM fl_moon'), $locked, 'a fresh member read');
        self::assertLessThan(self::firstAt($r, 'DELETE FROM lstr_fan_password_resets WHERE id'), $locked, 'the token is used');
        self::assertLessThan(self::firstAt($r, 'UPDATE fl_moon'), $locked, 'the password is written');
        self::assertGreaterThan(self::lastAt($r, 'UPDATE fl_moon'), self::lastAt($r, 'RELEASE_LOCK'));
        self::assertLessThan(self::lastAt($r, 'FROM lstr_fan_password_resets'), $locked, 'the token is read again under the lock');
    }

    public function testABusyAddressLeavesTheResetTokenAndPasswordAlone(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'reset_token'],
            ['do' => 'set_lock', 'answer' => 0],
            ['do' => 'reset_confirm', 'password' => self::NEW_PW],
            ['do' => 'clear_lock_script'],
        ]]);

        self::assertContains(self::BUSY, self::flashes($r));
        self::assertTrue(password_verify('a-password-1', (string) $r['members'][1][0]['password']));
        self::assertCount(1, $r['resets'], 'the link still works');
    }

    public function testAResetLinkDoesntSurviveItsMembersRemoval(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'reset_token'], ...self::replaceWithB(),
            ['do' => 'reset_confirm', 'password' => self::NEW_PW],
        ]]);

        self::assertTrue(password_verify('b-password-1', (string) self::fanB($r)['password']));
        self::assertContains('This reset link has expired or is invalid. Please request a new one.', self::flashes($r));
    }

    // ------------------------------------------------------------------
    // Collective Update
    // ------------------------------------------------------------------

    public function testABlockedAddressInAnySelectedListingChangesNothingInAnyOfThem(): void
    {
        $members = [self::FAN_A, ['listing' => 2, 'email' => 'a@x.test', 'name' => 'Fan A Too']];
        $change  = ['listing_ids' => ['1', '2'], 'name' => 'Everywhere', 'new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW];

        $r = $this->runOne(['layout' => 'owned', 'members' => $members, 'steps' => [
            ['do' => 'collective_token'],
            ['do' => 'set_lock', 'listing' => 2, 'answer' => 0],
            ['do' => 'collective', 'post' => $change],
        ]]);

        self::assertNull($r['error']);
        self::assertContains(self::BUSY, self::flashes($r));
        self::assertSame('Fan A', $r['members'][1][0]['name']);
        self::assertSame('Fan A Too', $r['members'][2][0]['name']);
        self::assertTrue(password_verify('a-password-1', (string) $r['members'][1][0]['password']));
        self::assertNull($r['collective_tokens'][0]['used_at'], 'the link still works');
        self::assertSame('', $r['mail']);
    }

    public function testCollectiveUpdateRereadsTheMembershipOnceLocked(): void
    {
        // The membership was removed while this waited for the lock.
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'collective_token'],
            ['do' => 'before', 'match' => '/GET_LOCK/', 'sql' => "DELETE FROM fl_moon WHERE email = 'a@x.test'"],
            ['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'name' => 'Chosen']],
        ]]);

        self::assertNull($r['error']);
        self::assertSame([], $r['members'][1]);
        self::assertSame([], array_filter($r['statements'], static fn(string $s): bool => str_contains($s, 'UPDATE fl_moon')), 'nothing written');
    }

    // ------------------------------------------------------------------
    // Mail waits for the locks
    // ------------------------------------------------------------------

    public function testNoMailGoesOutWhileALockIsHeld(): void
    {
        $queued  = ['do' => 'queue', 'changes' => ['name' => 'Asked For']];
        $results = $this->runScenarios([
            'Join'              => ['layout' => 'owned', 'steps' => [['do' => 'join', 'email' => 'new@x.test']]],
            'Add Member'        => ['layout' => 'owned', 'steps' => [['do' => 'add', 'email' => 'new@x.test', 'post' => ['email_fan' => '1']]]],
            'Update, delete'    => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [['do' => 'signed_in'], ['do' => 'submit', 'post' => ['delete_membership' => '1']]]],
            'Approve'           => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [$queued, ['do' => 'approve']]],
            'Approve All'       => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [$queued, ['do' => 'approve_all']]],
            'overview'          => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [$queued, ['do' => 'approve_overview']]],
            'Collective'        => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [['do' => 'collective_token'], ['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'name' => 'Chosen']]]],
            'Collective delete' => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [['do' => 'collective_token'], ['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'delete_membership' => '1']]]],
        ]);

        foreach ($results as $path => $r) {
            self::assertNull($r['error'], $path);
            self::assertNotSame('', $r['mail'], $path . ': mail did go out');
            self::assertNotSame([], $r['mail_at_release'], $path);
            foreach ($r['mail_at_release'] as $sizeWhenReleased) {
                self::assertSame(0, $sizeWhenReleased, $path . ': nothing was sent before the lock was released');
            }
        }
    }

    // ------------------------------------------------------------------
    // Join and Add Member
    // ------------------------------------------------------------------

    public function testJoinAndAddMemberCheckAgainUnderTheLock(): void
    {
        // On the enth_ layout nothing else would stop this: its email column has no unique key.
        $arrives = static fn(string $table): string => "INSERT INTO {$table} (email, name) VALUES ('new@x.test', 'Got There First')";
        $results = $this->runScenarios([
            'Join, unprefixed' => ['layout' => 'owned', 'steps' => [['do' => 'before', 'match' => '/GET_LOCK/', 'sql' => $arrives('fl_moon')], ['do' => 'join', 'email' => 'new@x.test']]],
            'Add, unprefixed'  => ['layout' => 'owned', 'steps' => [['do' => 'before', 'match' => '/GET_LOCK/', 'sql' => $arrives('fl_moon')], ['do' => 'add', 'email' => 'new@x.test']]],
            'Join, enth_'      => ['layout' => 'enth', 'steps' => [['do' => 'before', 'match' => '/GET_LOCK/', 'sql' => $arrives('fl_moon_members')], ['do' => 'join', 'email' => 'new@x.test']]],
            'Add, enth_'       => ['layout' => 'enth', 'steps' => [['do' => 'before', 'match' => '/GET_LOCK/', 'sql' => $arrives('fl_moon_members')], ['do' => 'add', 'email' => 'new@x.test']]],
        ]);

        foreach ($results as $path => $r) {
            self::assertNull($r['error'], $path);
            self::assertCount(1, $r['members'][1], $path);
            self::assertSame('Got There First', $r['members'][1][0]['name'], $path);
            self::assertSame('', $r['mail'], $path);
        }
    }

    public function testABusyAddressStopsAJoin(): void
    {
        $results = $this->runScenarios([
            'Join' => ['layout' => 'owned', 'steps' => [['do' => 'set_lock', 'email' => 'new@x.test', 'answer' => 0], ['do' => 'join', 'email' => 'new@x.test']]],
            'Add'  => ['layout' => 'owned', 'steps' => [['do' => 'set_lock', 'email' => 'new@x.test', 'answer' => 0], ['do' => 'add', 'email' => 'new@x.test']]],
        ]);

        foreach ($results as $path => $r) {
            self::assertNull($r['error'], $path);
            self::assertSame([], $r['members'][1], $path);
            self::assertSame('', $r['mail'], $path);
        }
        self::assertContains(self::BUSY, self::flashes($results['Join']));
    }

    // ------------------------------------------------------------------
    // The embed
    // ------------------------------------------------------------------

    public function testTheUpdateEmbedChecksTheStampBeforeShowingStepTwo(): void
    {
        // It boots from the live config, so it's run for real in the MariaDB checks.
        $embed = (string) file_get_contents(dirname(__DIR__, 2) . '/public/embed/update.php');

        self::assertStringContainsString('MemberSignIn::matches(MemberSignIn::sessionStamp($_lusterSession, $_lusterListingId), $_lusterMember)', $embed);
        self::assertStringContainsString('MemberSignIn::forget($_lusterApp->getSession(), $_lusterListingId)', $embed);
        self::assertStringNotContainsString("'lstr_update_authed_", $embed, 'every use goes through MemberSignIn');
    }
}
