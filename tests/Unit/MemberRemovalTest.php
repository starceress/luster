<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use PHPUnit\Framework\TestCase;

/**
 * Removing a member takes their queued requests and reset links with them, on every
 * path, so whoever gets that address next doesn't inherit them. With no crash or failure
 * anywhere, a replacement who joined the same day used to get the old request applied.
 */
final class MemberRemovalTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const FAN_A = ['listing' => 1, 'email' => 'a@x.test', 'name' => 'Fan A', 'password' => 'a-password-1'];
    private const KEEP  = ['listing' => 1, 'email' => 'keep@x.test', 'name' => 'Keeper', 'password' => 'keep-password-1'];

    /** @return array<string, array{0: string, 1: list<array<string, mixed>>, 2: array<int, array<string, mixed>>}> */
    private static function removalPaths(): array
    {
        return [
            'single Delete, unprefixed'  => ['owned', [['do' => 'admin_delete']], []],
            'single Delete, enth_'       => ['enth', [['do' => 'admin_delete']], []],
            'bulk Delete, unprefixed'    => ['owned', [['do' => 'bulk', 'action' => 'delete']], []],
            'bulk Delete, enth_'         => ['enth', [['do' => 'bulk', 'action' => 'delete']], []],
            'Merge, unprefixed'          => ['owned', [['do' => 'merge', 'primary' => 'keep@x.test']], []],
            'Merge, enth_'               => ['enth', [['do' => 'merge', 'primary' => 'keep@x.test']], []],
            'all-listings pending Reject' => ['owned', [['do' => 'pending_reject']], ['status' => 'pending']],
            'single Reject, enth_'       => ['enth', [['do' => 'admin_reject']], []],
            'bulk Reject, enth_'         => ['enth', [['do' => 'bulk', 'action' => 'reject']], []],
            'Edit Member set to Rejected, enth_' => ['enth', [['do' => 'edit', 'post' => ['status' => 'rejected']]], []],
            'public Update, unprefixed'  => ['owned', [['do' => 'signed_in'], ['do' => 'submit', 'post' => ['delete_membership' => '1']]], []],
            'public Update, enth_'       => ['enth', [['do' => 'signed_in'], ['do' => 'submit', 'post' => ['delete_membership' => '1']]], []],
            'Collective Update'          => ['owned', [['do' => 'collective_token'], ['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'delete_membership' => '1']]], []],
        ];
    }

    public function testASameDayReplacementInheritsNothingOnAnyPath(): void
    {
        $scenarios = [];
        foreach (self::removalPaths() as $path => [$layout, $steps, $member]) {
            $scenarios[$path] = [
                'layout'  => $layout,
                'members' => [self::FAN_A + $member, self::KEEP],
                'steps'   => [
                    ['do' => 'queue', 'changes' => ['name' => 'Name Fan A Asked For']],
                    ['do' => 'reset_token'],
                    ...$steps,
                    ['do' => 'insert_member', 'member' => ['email' => 'a@x.test', 'name' => 'Replacement', 'added' => date('Y-m-d')]],
                ],
            ];
        }

        foreach ($this->runScenarios($scenarios) as $path => $r) {
            self::assertNull($r['error'], $path);
            self::assertSame([], $r['queue'], $path . ': the request went with the member');
            self::assertSame([], $r['resets'], $path . ': so did the reset link');
            $names = array_column($r['members'][1], 'name', 'email');
            self::assertSame('Replacement', $names['a@x.test'], $path);
            self::assertSame('Keeper', $names['keep@x.test'] ?? 'Keeper', $path . ': nobody else was touched');
            self::assertSame([], $r['locks_held'], $path);
        }
    }

    public function testTheRequestsGoFirstSoOneCantOutliveItsMember(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'Asked For']],
            ['do' => 'trace_from_here'],
            ['do' => 'remove'],
        ]]);

        self::assertNull($r['error']);
        self::assertLessThan(self::firstAt($r, 'DELETE FROM fl_moon'), self::firstAt($r, 'DELETE FROM lstr_pending_updates'));
        self::assertLessThan(self::firstAt($r, 'DELETE FROM fl_moon'), self::firstAt($r, 'DELETE FROM lstr_fan_password_resets'));
        self::assertLessThan(self::firstAt($r, 'DELETE FROM lstr_pending_updates'), self::firstAt($r, 'GET_LOCK'));
    }

    public function testAFailedMemberDeleteLeavesTheMemberAndARetryFinishes(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'Asked For']],
            ['do' => 'fail_on', 'regex' => '/DELETE FROM fl_moon/'],
            ['do' => 'remove'],
        ]]);
        self::assertStringContainsString('Simulated failure', (string) $r['error']);
        self::assertCount(1, $r['members'][1], 'still a member');
        self::assertSame([], $r['queue'], 'without the requests the owner was removing anyway');
        self::assertSame([], $r['locks_held'], 'the lock was released');

        $retry = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'fail_on', 'regex' => '/DELETE FROM fl_moon/'],
            ['do' => 'remove'],
            ['do' => 'remove'],
        ]]);
        self::assertSame([], $retry['members'][1]);
    }

    public function testAFailedRequestDeleteStopsBeforeTheMember(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'Asked For']],
            ['do' => 'fail_on', 'regex' => '/DELETE FROM lstr_pending_updates/'],
            ['do' => 'remove'],
        ]]);

        self::assertCount(1, $r['members'][1]);
        self::assertCount(1, $r['queue']);
    }

    public function testABusyAddressRemovesNothing(): void
    {
        $results = $this->runScenarios([
            'single Delete' => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'Asked For']],
                ['do' => 'set_lock', 'answer' => 0],
                ['do' => 'admin_delete'],
            ]],
            'bulk Delete' => ['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'Asked For']],
                ['do' => 'set_lock', 'answer' => 0],
                ['do' => 'bulk', 'action' => 'delete'],
            ]],
            'Merge' => ['layout' => 'owned', 'members' => [self::FAN_A, self::KEEP], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'Asked For']],
                ['do' => 'set_lock', 'answer' => 0],
                ['do' => 'merge', 'primary' => 'keep@x.test'],
            ]],
            'all-listings Reject' => ['layout' => 'owned', 'members' => [self::FAN_A + ['status' => 'pending']], 'steps' => [
                ['do' => 'queue', 'changes' => ['name' => 'Asked For']],
                ['do' => 'set_lock', 'answer' => 0],
                ['do' => 'pending_reject'],
            ]],
        ]);

        foreach ($results as $path => $r) {
            self::assertNull($r['error'], $path);
            self::assertContains('a@x.test', array_column($r['members'][1], 'email'), $path . ': still a member');
            self::assertCount(1, $r['queue'], $path . ': the request is still there');
            self::assertNotSame([], array_filter(self::flashes($r), static fn(string $f): bool => str_contains($f, 'try again') || str_contains($f, 'Try them again') || str_contains($f, 'Please try')), $path . ': and it says so');
        }
    }

    public function testARejectedMemberWhoKeepsTheirAddressKeepsTheirRequests(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'Asked For']],
            ['do' => 'admin_reject'],
        ]]);

        self::assertNull($r['error']);
        self::assertSame(2, (int) $r['members'][1][0]['pending']);
        self::assertCount(1, $r['queue']);
    }

    public function testMergeMovesTheEarlierDateAndKeepsThePrimary(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::FAN_A + ['added' => '2019-01-01'], self::KEEP + ['added' => '2020-01-01']], 'steps' => [
            ['do' => 'merge', 'primary' => 'keep@x.test'],
        ]]);

        self::assertNull($r['error']);
        self::assertCount(1, $r['members'][1]);
        self::assertSame('2019-01-01', $r['members'][1][0]['added']);
        self::assertSame('Keeper', $r['members'][1][0]['name']);
    }

    // ------------------------------------------------------------------
    // Leftovers at an address that's getting a new member
    // ------------------------------------------------------------------

    public function testAStaleRequestIsClearedWhenANewMemberArrives(): void
    {
        $results = $this->runScenarios([
            'Join' => ['layout' => 'owned', 'steps' => [['do' => 'queue', 'email' => 'new@x.test', 'changes' => ['name' => 'Stale']], ['do' => 'join', 'email' => 'new@x.test']]],
            'Add'  => ['layout' => 'owned', 'steps' => [['do' => 'queue', 'email' => 'new@x.test', 'changes' => ['name' => 'Stale']], ['do' => 'add', 'email' => 'new@x.test']]],
        ]);

        foreach ($results as $path => $r) {
            self::assertNull($r['error'], $path);
            self::assertCount(1, $r['members'][1], $path);
            self::assertSame([], $r['queue'], $path);
        }
    }

    // ------------------------------------------------------------------
    // Everything goes through it
    // ------------------------------------------------------------------

    public function testNothingRemovesAMemberExceptMemberRemoval(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getFilename(), ['MemberRemoval.php', 'MemberRepo.php'], true)) {
                continue;
            }
            if (preg_match('/memberRepo->(deleteByEmail|reject)\(/i', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders, 'Use MemberRemoval, so the requests and reset links go too.');
    }
}
