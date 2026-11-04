<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use PHPUnit\Framework\TestCase;

/**
 * KIM Update: a password only reaches the entries it matches, each save checks those
 * entries again with them locked, and an entry's reset links don't outlive its address.
 * KIM signups aren't checked against the mailbox, so someone signing up at a fan's address
 * with their own password used to be able to take over the fan's entries once approved.
 */
final class KimSignInFlowTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const SIGN_IN = 'Please sign in again.';
    private const CHANGED = 'Something about these entries changed, so nothing was saved. Please check them and try again.';
    private const NEW_PW  = 'chosen-by-fan-a-99';

    /** @return array<string, mixed> One entry at a@x.test in listing 1 with password one-password-1, signed in. */
    private static function signedIn(array ...$more): array
    {
        return ['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'mine', 'name' => 'Fan A', 'password' => 'one-password-1'],
            ['do' => 'kim_sign_in', 'password' => 'one-password-1'],
            ...$more,
        ]];
    }

    // ------------------------------------------------------------------
    // One password, one set of entries
    // ------------------------------------------------------------------

    public function testAPasswordOnlyReachesTheEntriesItMatches(): void
    {
        $base = [
            ['do' => 'kim_entry', 'as' => 'one', 'name' => 'Entry One', 'password' => 'one-password-1'],
            ['do' => 'kim_entry', 'as' => 'two', 'listing' => 2, 'name' => 'Entry Two', 'password' => 'two-password-2'],
            ['do' => 'kim_sign_in', 'password' => 'one-password-1'],
        ];
        $results = $this->runScenarios([
            'edit the other' => ['layout' => 'owned', 'steps' => [...$base, ['do' => 'kim_save', 'entries' => ['two'], 'post' => ['new_name' => 'Changed', 'new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW]]]],
            'remove the other' => ['layout' => 'owned', 'steps' => [...$base, ['do' => 'kim_save', 'removes' => ['two']]]],
            'both named' => ['layout' => 'owned', 'steps' => [...$base, ['do' => 'kim_save', 'entries' => ['one', 'two'], 'post' => ['new_name' => 'Changed']]]],
            'own only' => ['layout' => 'owned', 'steps' => [...$base, ['do' => 'kim_save', 'entries' => ['one'], 'post' => ['new_name' => 'Changed']]]],
        ]);

        foreach (['edit the other', 'remove the other', 'both named'] as $why) {
            $r = $results[$why];
            self::assertNull($r['error'], $why);
            self::assertSame('Entry One', $r['kim']['one']['name'], $why . ': a bad id refuses the whole save');
            self::assertSame('Entry Two', $r['kim']['two']['name'], $why);
            self::assertTrue(password_verify('two-password-2', (string) $r['kim']['two']['password']), $why);
            self::assertContains(self::CHANGED, self::flashes($r), $why);
            self::assertSame('', $r['mail'], $why);
        }

        self::assertSame('Changed', $results['own only']['kim']['one']['name']);
        self::assertSame('Entry Two', $results['own only']['kim']['two']['name']);
    }

    public function testSomeoneSigningUpAtAFansAddressCantReachTheFansEntry(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'fan', 'name' => 'Real Fan', 'password' => 'the-real-fans-password'],
            ['do' => 'kim_signup', 'listing' => 2, 'as' => 'intruder', 'post' => ['name' => 'Someone Else', 'password' => 'someone-elses-password', 'password_confirm' => 'someone-elses-password']],
            ['do' => 'kim_approve_all'],
            ['do' => 'kim_sign_in', 'password' => 'someone-elses-password'],
            ['do' => 'kim_save', 'entries' => ['fan'], 'post' => ['new_name' => 'Taken Over', 'new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW]],
            ['do' => 'kim_save', 'removes' => ['fan']],
            ['do' => 'kim_save', 'entries' => ['intruder'], 'post' => ['new_name' => 'Their Own']],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('Real Fan', $r['kim']['fan']['name']);
        self::assertTrue(password_verify('the-real-fans-password', (string) $r['kim']['fan']['password']), "the fan's own password still works");
        self::assertSame('Their Own', $r['kim']['intruder']['name'], 'their own entry is still theirs');
    }

    public function testEntriesWithTheSamePasswordAllShowEvenWithDifferentSalts(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'one', 'password' => 'same-password-1'],
            ['do' => 'kim_entry', 'as' => 'two', 'listing' => 2, 'password' => 'same-password-1'],
            ['do' => 'kim_sign_in', 'password' => 'same-password-1'],
            ['do' => 'kim_save', 'entries' => ['one', 'two'], 'post' => ['new_name' => 'Both']],
        ]]);

        self::assertSame('Both', $r['kim']['one']['name']);
        self::assertSame('Both', $r['kim']['two']['name']);
    }

    public function testAPendingEntryWithTheSamePasswordIsReachableButCantSignIn(): void
    {
        $results = $this->runScenarios([
            'with an approved one' => ['layout' => 'owned', 'steps' => [
                ['do' => 'kim_entry', 'as' => 'approved', 'password' => 'same-password-1'],
                ['do' => 'kim_entry', 'as' => 'waiting', 'listing' => 2, 'password' => 'same-password-1', 'pending' => true],
                ['do' => 'kim_sign_in', 'password' => 'same-password-1'],
                ['do' => 'kim_save', 'entries' => ['waiting'], 'post' => ['new_name' => 'Waiting Changed']],
            ]],
            'only pending' => ['layout' => 'owned', 'steps' => [
                ['do' => 'kim_entry', 'as' => 'waiting', 'password' => 'same-password-1', 'pending' => true],
                ['do' => 'kim_sign_in', 'password' => 'same-password-1'],
            ]],
        ]);

        self::assertSame('Waiting Changed', $results['with an approved one']['kim']['waiting']['name']);
        self::assertSame([], $results['only pending']['session_authed'], 'it needs an approved match, as it always has');
    }

    // ------------------------------------------------------------------
    // Entries that change hands after sign-in
    // ------------------------------------------------------------------

    public function testAReplacedEntryCantBeReached(): void
    {
        $replace = [
            ['do' => 'kim_entry_sql', 'sql' => 'DELETE FROM lstr_kim_members WHERE id = {mine}'],
            ['do' => 'kim_entry', 'as' => 'theirs', 'name' => 'Fan B', 'password' => 'b-password-11'],
        ];
        $results = $this->runScenarios([
            'edit'   => self::signedIn(...$replace, ...[['do' => 'kim_save', 'entries' => ['theirs'], 'post' => ['new_name' => 'Chosen By A', 'new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW]]]),
            'remove' => self::signedIn(...$replace, ...[['do' => 'kim_save', 'removes' => ['theirs']]]),
        ]);

        foreach ($results as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertSame('Fan B', $r['kim']['theirs']['name'], $why);
            self::assertTrue(password_verify('b-password-11', (string) $r['kim']['theirs']['password']), $why);
            self::assertContains(self::SIGN_IN, self::flashes($r), $why);
            self::assertSame([], $r['session_authed'], $why);
        }
    }

    public function testARenamedEntrysSignInCantReachWhoeverGotTheOldAddress(): void
    {
        $move = [
            ['do' => 'kim_entry', 'as' => 'theirs', 'listing' => 2, 'name' => 'Fan B', 'password' => 'b-password-11'],
            ['do' => 'kim_entry_sql', 'sql' => "UPDATE lstr_kim_members SET email = 'moved@x.test' WHERE id = {mine}"],
        ];
        $results = $this->runScenarios([
            'remove' => self::signedIn(...$move, ...[['do' => 'kim_save', 'removes' => ['theirs']]]),
            'edit the moved one' => self::signedIn(...$move, ...[['do' => 'kim_save', 'entries' => ['mine'], 'post' => ['new_name' => 'Chosen']]]),
        ]);

        foreach ($results as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertSame('Fan B', $r['kim']['theirs']['name'], $why);
            self::assertSame('Fan A', $r['kim']['mine']['name'], $why);
            self::assertContains(self::SIGN_IN, self::flashes($r), $why);
            self::assertSame([], $r['session_authed'], $why);
        }
    }

    public function testAnOwnerChangingAnEntryOrAnOldSessionGoBackToSignIn(): void
    {
        $results = $this->runScenarios([
            'hash changed' => self::signedIn(
                ['do' => 'kim_entry_sql', 'sql' => "UPDATE lstr_kim_members SET password = 'a-different-hash' WHERE id = {mine}"],
                ['do' => 'kim_save', 'entries' => ['mine'], 'post' => ['new_name' => 'Chosen']],
            ),
            'from before the upgrade' => ['layout' => 'owned', 'steps' => [
                ['do' => 'kim_entry', 'as' => 'mine', 'name' => 'Fan A', 'password' => 'one-password-1'],
                ['do' => 'kim_session_only_old_style'],
                ['do' => 'kim_save', 'entries' => ['mine'], 'post' => ['new_name' => 'Chosen']],
            ]],
        ]);

        foreach ($results as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertSame('Fan A', $r['kim']['mine']['name'], $why);
            self::assertContains(self::SIGN_IN, self::flashes($r), $why);
            self::assertSame([], $r['session_authed'], $why);
        }
    }

    public function testDifferentCapitalsAreStillTheSameAddress(): void
    {
        $r = $this->runOne(self::signedIn(
            ['do' => 'kim_entry_sql', 'sql' => "UPDATE lstr_kim_members SET email = 'A@X.Test' WHERE id = {mine}"],
            ['do' => 'kim_save', 'entries' => ['mine'], 'post' => ['new_name' => 'Still Me']],
        ));

        self::assertSame('Still Me', $r['kim']['mine']['name']);
    }

    // ------------------------------------------------------------------
    // A save holds its locks through its writes
    // ------------------------------------------------------------------

    /** The move happens at the moment the save locks its rows, as another request would. */
    private static function pausedSave(array $entries, string $movedEntry): array
    {
        return ['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'one', 'name' => 'Entry One', 'password' => 'same-password-1'],
            ['do' => 'kim_entry', 'as' => 'two', 'listing' => 2, 'name' => 'Entry Two', 'password' => 'same-password-1'],
            ['do' => 'kim_sign_in', 'password' => 'same-password-1'],
            ['do' => 'kim_before', 'match' => '/ORDER BY id ASC/', 'sql' => "UPDATE lstr_kim_members SET email = 'moved@x.test' WHERE id = {" . $movedEntry . '}'],
            ['do' => 'trace_from_here'],
            ['do' => 'kim_save', 'entries' => $entries, 'post' => ['new_name' => 'Chosen']],
        ]];
    }

    public function testAnEntryMovedAsTheSaveLocksItStopsTheWholeSave(): void
    {
        $results = $this->runScenarios([
            'the only entry'      => self::pausedSave(['one'], 'one'),
            'one of two selected' => self::pausedSave(['one', 'two'], 'two'),
        ]);

        foreach ($results as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertSame('Entry One', $r['kim']['one']['name'], $why);
            self::assertSame('Entry Two', $r['kim']['two']['name'], $why);
            self::assertContains(self::CHANGED, self::flashes($r), $why);
            self::assertSame('', $r['mail'], $why);
            self::assertSame([], array_filter($r['statements'], static fn(string $s): bool => str_starts_with($s, 'UPDATE lstr_kim_members') || str_starts_with($s, 'DELETE FROM lstr_kim_members')), $why . ': nothing was written');
        }
    }

    public function testAnEntryTheSaveDoesntTouchNeedsNoLock(): void
    {
        $r = $this->runOne(self::pausedSave(['one'], 'two'));

        self::assertSame('Chosen', $r['kim']['one']['name']);
        self::assertSame('moved@x.test', $r['kim']['two']['email']);
    }

    public function testTheSaveLocksBeforeWritingAndMailsAfter(): void
    {
        $r = $this->runOne(self::signedIn(
            ['do' => 'trace_from_here'],
            ['do' => 'kim_save', 'entries' => ['mine'], 'post' => ['new_name' => 'Chosen']],
        ));

        self::assertLessThan(self::firstAt($r, 'UPDATE lstr_kim_members'), self::firstAt($r, 'ORDER BY id ASC'));
        self::assertSame('Chosen', $r['kim']['mine']['name']);
        self::assertStringContainsString('a@x.test', $r['mail'], 'the confirmation still goes out');
    }

    public function testAFailedWriteLeavesEverythingAsItWas(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'one', 'name' => 'Entry One', 'password' => 'same-password-1'],
            ['do' => 'kim_entry', 'as' => 'two', 'listing' => 2, 'name' => 'Entry Two', 'password' => 'same-password-1'],
            ['do' => 'kim_sign_in', 'password' => 'same-password-1'],
            ['do' => 'fail_on', 'regex' => '/^UPDATE lstr_kim_members/'],
            ['do' => 'kim_save', 'entries' => ['one'], 'removes' => ['two'], 'post' => ['new_name' => 'Chosen']],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('Entry One', $r['kim']['one']['name']);
        self::assertArrayHasKey('two', $r['kim'], 'the removal was rolled back too');
        self::assertContains("Couldn't save your changes because of a database error. Nothing was changed.", self::flashes($r));
        self::assertSame('', $r['mail']);
    }

    // ------------------------------------------------------------------
    // The fan's own changes
    // ------------------------------------------------------------------

    public function testChangingAPasswordKeepsTheSignInUsingTheHashItWrote(): void
    {
        $r = $this->runOne(self::signedIn(
            ['do' => 'kim_save', 'entries' => ['mine'], 'post' => ['new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW]],
            ['do' => 'kim_save', 'entries' => ['mine'], 'post' => ['new_name' => 'Still Signed In']],
        ));

        self::assertNull($r['error']);
        self::assertTrue(password_verify(self::NEW_PW, (string) $r['kim']['mine']['password']));
        self::assertSame('Still Signed In', $r['kim']['mine']['name']);
        self::assertNotSame([], $r['session_authed']);
    }

    public function testChangingAnEmailSignsOutAndRemovingKeepsTheRest(): void
    {
        $two = [
            ['do' => 'kim_entry', 'as' => 'one', 'password' => 'same-password-1'],
            ['do' => 'kim_entry', 'as' => 'two', 'listing' => 2, 'password' => 'same-password-1'],
            ['do' => 'kim_sign_in', 'password' => 'same-password-1'],
        ];
        $results = $this->runScenarios([
            'email' => ['layout' => 'owned', 'steps' => [...$two, ['do' => 'kim_save', 'entries' => ['one'], 'post' => ['new_email' => 'new@x.test']]]],
            'remove one' => ['layout' => 'owned', 'steps' => [...$two, ['do' => 'kim_save', 'removes' => ['one']], ['do' => 'kim_save', 'entries' => ['two'], 'post' => ['new_name' => 'Still Here']]]],
            'remove both' => ['layout' => 'owned', 'steps' => [...$two, ['do' => 'kim_save', 'removes' => ['one', 'two']]]],
        ]);

        self::assertSame('new@x.test', $results['email']['kim']['one']['email']);
        self::assertSame([], $results['email']['session_authed']);

        self::assertArrayNotHasKey('one', $results['remove one']['kim']);
        self::assertSame('Still Here', $results['remove one']['kim']['two']['name']);
        self::assertNotSame([], $results['remove one']['session_authed']);

        self::assertSame([], $results['remove both']['kim']);
        self::assertSame([], $results['remove both']['session_authed']);
    }

    public function testAnEmailAlreadyTakenLeavesTheEmailAndAppliesTheRest(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'mine', 'password' => 'one-password-1'],
            ['do' => 'kim_entry', 'as' => 'taken', 'email' => 'taken@x.test', 'password' => 'other-password-1'],
            ['do' => 'kim_sign_in', 'password' => 'one-password-1'],
            ['do' => 'kim_save', 'entries' => ['mine'], 'post' => ['new_email' => 'taken@x.test', 'new_name' => 'Renamed']],
        ]]);

        self::assertNull($r['error']);
        self::assertSame('a@x.test', $r['kim']['mine']['email']);
        self::assertSame('Renamed', $r['kim']['mine']['name']);
        self::assertContains('That email address is already registered for one or more of the selected fanlistings. Email not changed.', self::flashes($r));
    }

    // ------------------------------------------------------------------
    // Reset links
    // ------------------------------------------------------------------

    public function testMovingAnEntryRevokesItsResetLink(): void
    {
        $results = $this->runScenarios([
            'admin moves it' => ['layout' => 'owned', 'steps' => [
                ['do' => 'kim_entry', 'as' => 'mine', 'password' => 'original-password-1'],
                ['do' => 'kim_reset_token', 'entry' => 'mine'],
                ['do' => 'kim_admin_edit', 'entry' => 'mine', 'post' => ['email' => 'new-owner@x.test']],
                ['do' => 'kim_reset_confirm', 'password' => self::NEW_PW],
            ]],
            'the fan moves it' => ['layout' => 'owned', 'steps' => [
                ['do' => 'kim_entry', 'as' => 'mine', 'password' => 'original-password-1'],
                ['do' => 'kim_sign_in', 'password' => 'original-password-1'],
                ['do' => 'kim_reset_token', 'entry' => 'mine'],
                ['do' => 'kim_save', 'entries' => ['mine'], 'post' => ['new_email' => 'new-owner@x.test']],
                ['do' => 'kim_reset_confirm', 'password' => self::NEW_PW],
            ]],
        ]);

        foreach ($results as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertSame('new-owner@x.test', $r['kim']['mine']['email'], $why);
            self::assertSame(0, $r['kim_tokens'], $why);
            self::assertTrue(password_verify('original-password-1', (string) $r['kim']['mine']['password']), $why . ": the old address's link did nothing");
        }
    }

    public function testAnEditThatDoesntMoveTheEntryKeepsItsLink(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'mine', 'password' => 'original-password-1'],
            ['do' => 'kim_reset_token', 'entry' => 'mine'],
            ['do' => 'kim_admin_edit', 'entry' => 'mine', 'post' => ['email' => 'A@X.Test', 'name' => 'Renamed']],
            ['do' => 'kim_reset_confirm', 'password' => self::NEW_PW],
        ]]);

        self::assertTrue(password_verify(self::NEW_PW, (string) $r['kim']['mine']['password']), 'other capitals are the same mailbox');
    }

    public function testResetConfirmationBeforeAndAfterAMove(): void
    {
        $results = $this->runScenarios([
            'used first' => ['layout' => 'owned', 'steps' => [
                ['do' => 'kim_entry', 'as' => 'mine', 'password' => 'original-password-1'],
                ['do' => 'kim_reset_token', 'entry' => 'mine'],
                ['do' => 'kim_reset_confirm', 'password' => self::NEW_PW],
                ['do' => 'kim_admin_edit', 'entry' => 'mine', 'post' => ['email' => 'new-owner@x.test']],
            ]],
            'moved first' => ['layout' => 'owned', 'steps' => [
                ['do' => 'kim_entry', 'as' => 'mine', 'password' => 'original-password-1'],
                ['do' => 'kim_reset_token', 'entry' => 'mine'],
                ['do' => 'kim_admin_edit', 'entry' => 'mine', 'post' => ['email' => 'new-owner@x.test']],
                ['do' => 'kim_reset_confirm', 'password' => self::NEW_PW],
            ]],
        ]);

        self::assertTrue(password_verify(self::NEW_PW, (string) $results['used first']['kim']['mine']['password']));
        self::assertSame('new-owner@x.test', $results['used first']['kim']['mine']['email']);

        self::assertTrue(password_verify('original-password-1', (string) $results['moved first']['kim']['mine']['password']));
        self::assertContains('This reset link has expired or is invalid. Please request a new one.', self::flashes($results['moved first']));
    }

    public function testAFailedPasswordWriteLeavesTheResetLinkUsable(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'mine', 'password' => 'original-password-1'],
            ['do' => 'kim_reset_token', 'entry' => 'mine'],
            ['do' => 'fail_on', 'regex' => '/^UPDATE lstr_kim_members/'],
            ['do' => 'kim_reset_confirm', 'password' => self::NEW_PW],
        ]]);

        self::assertNull($r['error']);
        self::assertSame(1, $r['kim_tokens'], 'the token was rolled back');
        self::assertTrue(password_verify('original-password-1', (string) $r['kim']['mine']['password']));

        $retry = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'mine', 'password' => 'original-password-1'],
            ['do' => 'kim_reset_token', 'entry' => 'mine'],
            ['do' => 'fail_on', 'regex' => '/^UPDATE lstr_kim_members/'],
            ['do' => 'kim_reset_confirm', 'password' => self::NEW_PW],
            ['do' => 'kim_reset_confirm', 'password' => self::NEW_PW],
        ]]);
        self::assertTrue(password_verify(self::NEW_PW, (string) $retry['kim']['mine']['password']));
        self::assertSame(0, $retry['kim_tokens']);
    }

    public function testConfirmationLocksTheEntryBeforeUsingTheToken(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'mine', 'password' => 'original-password-1'],
            ['do' => 'kim_reset_token', 'entry' => 'mine'],
            ['do' => 'trace_from_here'],
            ['do' => 'kim_reset_confirm', 'password' => self::NEW_PW],
        ]]);

        $locked = self::firstAt($r, 'ORDER BY id ASC');
        self::assertLessThan(self::firstAt($r, 'DELETE FROM lstr_kim_password_resets WHERE id'), $locked);
        self::assertLessThan(self::firstAt($r, 'UPDATE lstr_kim_members'), $locked);
    }

    public function testARequestRacingAMoveLeavesNoTokenForTheOldAddress(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'mine', 'password' => 'original-password-1'],
            ['do' => 'kim_before', 'match' => '/ORDER BY id ASC/', 'sql' => "UPDATE lstr_kim_members SET email = 'new-owner@x.test' WHERE id = {mine}"],
            ['do' => 'kim_reset_request'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame(0, $r['kim_tokens']);
        self::assertSame('', $r['mail'], 'no link goes to the old address');
    }

    public function testARequestStillSendsALinkForEachApprovedEntry(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'kim_entry', 'as' => 'one', 'password' => 'one-password-1'],
            ['do' => 'kim_entry', 'as' => 'two', 'listing' => 2, 'password' => 'two-password-2'],
            ['do' => 'kim_entry', 'as' => 'waiting', 'email' => 'a@x.test', 'listing' => 3, 'password' => 'three-password-3', 'pending' => true],
            ['do' => 'kim_reset_request'],
        ]]);

        self::assertSame(2, $r['kim_tokens'], 'approved entries only');
        self::assertStringContainsString('a@x.test', $r['mail']);
    }

    public function testSignupMailsNoPasswordOrLinkOnceTheEntryHasLeftThatAddress(): void
    {
        // No password is posted, so it's generated: the mail would be the first anyone saw of it.
        $results = $this->runScenarios([
            'normal' => ['layout' => 'owned', 'steps' => [
                ['do' => 'kim_signup', 'as' => 'new'],
            ]],
            'moved first' => ['layout' => 'owned', 'steps' => [
                ['do' => 'before', 'match' => '/ORDER BY id ASC/', 'sql' => "UPDATE lstr_kim_members SET email = 'moved@x.test' WHERE email = 'a@x.test'"],
                ['do' => 'kim_signup', 'as' => 'new'],
            ]],
            'removed first' => ['layout' => 'owned', 'steps' => [
                ['do' => 'before', 'match' => '/ORDER BY id ASC/', 'sql' => "DELETE FROM lstr_kim_members WHERE email = 'a@x.test'"],
                ['do' => 'kim_signup', 'as' => 'new'],
            ]],
        ]);

        self::assertNull($results['normal']['error']);
        self::assertSame(1, $results['normal']['kim_tokens']);
        self::assertStringContainsString('Password:', $results['normal']['mail']);
        self::assertStringContainsString('token=', $results['normal']['mail']);

        foreach (['moved first', 'removed first'] as $why) {
            $r = $results[$why];
            self::assertNull($r['error'], $why);
            self::assertSame(0, $r['kim_tokens'], $why . ': no link for the old address');
            self::assertStringNotContainsString('Password:', $r['mail'], $why . ': no password for the old address');
            self::assertStringNotContainsString('token=', $r['mail'], $why);
        }
    }

    // ------------------------------------------------------------------
    // The dashboard
    // ------------------------------------------------------------------

    public function testTheDashboardShowsOnlyReachableEntriesAndSaysWhy(): void
    {
        // The embed boots from the live config, so it's run for real in the MariaDB checks.
        $root  = dirname(__DIR__, 2);
        $embed = (string) file_get_contents($root . '/public/embed/kim-update.php');
        $page  = (string) file_get_contents($root . '/templates/default/addons/kim/update-dashboard.php');

        self::assertStringContainsString('KimSignIn::currentEntries($_lusterKimRepo, $_lusterSession)', $embed);
        self::assertStringNotContainsString('findAllByEmail', $embed);
        self::assertStringNotContainsString("'lstr_kim_update_authed'", $embed, 'every use goes through KimSignIn');
        self::assertStringContainsString('Only entries that use the password you signed in with are shown here.', $page);
        self::assertStringContainsString('link for each approved entry', $page);
    }
}
