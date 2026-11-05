<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use PHPUnit\Framework\TestCase;

/**
 * Width checks come before any credential is used and any password is written, so a
 * value that won't fit can't leave half a change behind: Collective Update across
 * listings, and public Update's single-use form token.
 */
final class CollectiveColumnWidthsTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const MOON = ['listing' => 1, 'email' => 'a@x.test', 'name' => 'Fan A', 'added' => '2020-01-01'];
    private const SUN  = ['listing' => 2, 'email' => 'a@x.test', 'name' => 'Fan A', 'added' => '2020-01-01'];

    private const PASSWORD = 'A-Long-Enough-Password-1';
    private const REFUSAL  = "We couldn't save this because of a problem with the form. Nothing was changed. Please let the listing's owner know.";

    private static function address(int $length): string
    {
        return str_repeat('a', $length - strlen('@x.test')) . '@x.test';
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

    private static function assertCollectiveChangedNothing(array $before, array $r, string $why): void
    {
        self::assertNull($r['error'], $why);
        self::assertSame($before, array_map(static fn(array $rows): array => array_map(static fn(array $row): array => [$row['email'], $row['name']], $rows), $r['members']), $why);
        self::assertNull($r['collective_tokens'][0]['used_at'], $why . ': the link still works');
        self::assertSame([], $r['queue'], $why);
    }

    public function testOneNarrowListingOfTwoMeansNeitherChangesAndTheLinkStillWorks(): void
    {
        $unchanged = [1 => [['a@x.test', 'Fan A']], 2 => [['a@x.test', 'Fan A']]];
        $token     = ['do' => 'collective_token'];
        $post      = ['listing_ids' => ['1', '2'], 'new_email' => self::address(65), 'name' => 'New Name'];

        foreach (['owned', 'enth'] as $layout) {
            $over    = ['layout' => $layout, 'members' => [self::MOON, self::SUN]];
            $narrow  = $over + ['column_types' => [2 => ['email' => 'VARCHAR(64)']]];
            $results = $this->runScenarios([
                'email'   => $narrow + ['steps' => [$token, ['do' => 'collective', 'post' => $post]]],
                'queued'  => $narrow + ['queue_updates' => true, 'steps' => [$token, ['do' => 'collective', 'post' => $post]]],
                'name'    => $over + ['column_types' => [2 => ['name' => 'VARCHAR(5)']], 'steps' => [$token, ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'name' => 'New Name']]]],
                'retried' => $narrow + ['steps' => [$token, ['do' => 'collective', 'post' => $post], ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'new_email' => self::address(64)]]]],
            ]);

            foreach (['email', 'queued', 'name'] as $name) {
                self::assertCollectiveChangedNothing($unchanged, $results[$name], "{$layout}, {$name}");
                self::assertStringContainsString('too long for Sun FL', self::flashed($results[$name]), "{$layout}, {$name}: it names the listing");
            }

            $retried = $results['retried'];
            self::assertSame(self::address(64), $retried['members'][1][0]['email'], "{$layout}: the same link worked the second time");
            self::assertSame(self::address(64), $retried['members'][2][0]['email'], $layout);
            self::assertNotNull($retried['collective_tokens'][0]['used_at'], $layout);
        }
    }

    public function testAListingNotSelectedOrAlreadyMatchingDoesNotGetInTheWay(): void
    {
        $over = ['layout' => 'owned', 'column_types' => [2 => ['name' => 'VARCHAR(5)']]];

        $results = $this->runScenarios([
            'not selected' => $over + ['members' => [self::MOON, self::SUN], 'steps' => [['do' => 'collective_token'], ['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'name' => 'New Name']]]],
            'no change'    => $over + ['members' => [self::MOON, ['name' => 'Ann'] + self::SUN], 'steps' => [['do' => 'collective_token'], ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'country' => 'Peru']]]],
        ]);

        self::assertSame('New Name', $results['not selected']['members'][1][0]['name']);
        self::assertSame('Peru', $results['no change']['members'][1][0]['country']);
        self::assertSame('Peru', $results['no change']['members'][2][0]['country']);
    }

    public function testAFailedReadOnOneListingStopsEverythingBeforeTheLinkIsUsed(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::MOON, self::SUN], 'steps' => [
            ['do' => 'collective_token'],
            ['do' => 'fail_on', 'regex' => '/PRAGMA table_info\(`fl_sun`\)/'],
            ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'name' => 'New Name', 'new_password' => self::PASSWORD, 'new_password_confirm' => self::PASSWORD]],
        ]]);

        self::assertCollectiveChangedNothing([1 => [['a@x.test', 'Fan A']], 2 => [['a@x.test', 'Fan A']]], $r, 'failed read');
        self::assertStringContainsString(self::REFUSAL, self::flashed($r));
        self::assertSame([], array_filter($r['statements'], static fn(string $sql): bool => preg_match('/^\s*UPDATE fl_/', $sql) === 1), 'no password written');
    }

    public function testANarrowPasswordColumnStopsCollectiveBeforeAnyPasswordIsWritten(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::MOON, self::SUN], 'column_types' => [2 => ['password' => 'VARCHAR(32)']], 'steps' => [
            ['do' => 'collective_token'],
            ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'new_password' => self::PASSWORD, 'new_password_confirm' => self::PASSWORD]],
        ]]);

        self::assertCollectiveChangedNothing([1 => [['a@x.test', 'Fan A']], 2 => [['a@x.test', 'Fan A']]], $r, 'narrow password');
        self::assertStringContainsString(self::REFUSAL, self::flashed($r));
        self::assertSame([], array_filter($r['statements'], static fn(string $sql): bool => preg_match('/^\s*UPDATE fl_/', $sql) === 1), 'listing 1 keeps its old password too');
    }

    public function testCollectiveChecksEveryListingBeforeTheTokenAndEveryWriteAfterIt(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::MOON, self::SUN], 'steps' => [
            ['do' => 'collective_token'],
            ['do' => 'trace_from_here'],
            ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'name' => 'New Name']],
        ]]);

        $firstAt = static function (string $needle) use ($r): int {
            foreach ($r['statements'] as $i => $sql) {
                if (str_contains($sql, $needle)) {
                    return $i;
                }
            }
            self::fail('Never ran: ' . $needle);
        };

        $token = $firstAt('UPDATE lstr_collective_update_tokens');
        self::assertLessThan($token, $firstAt('PRAGMA table_info(`fl_moon`)'));
        self::assertLessThan($token, $firstAt('PRAGMA table_info(`fl_sun`)'));
        self::assertLessThan($firstAt('UPDATE fl_moon'), $token);
    }

    // ------------------------------------------------------------------
    // Public Update's form token
    // ------------------------------------------------------------------

    public function testARefusedUpdateLeavesTheFormTokenUsable(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $over    = ['layout' => $layout, 'members' => [self::MOON], 'column_types' => [1 => ['name' => 'VARCHAR(5)']]];
            $tokened = [['do' => 'signed_in'], ['do' => 'lose_session']];
            $results = $this->runScenarios([
                'too long'  => $over + ['steps' => [...$tokened, ['do' => 'submit', 'use_token' => true, 'post' => ['new_name' => 'Far too long']]]],
                'invalid'   => $over + ['steps' => [...$tokened, ['do' => 'submit', 'use_token' => true, 'post' => ['new_email' => 'not an email']]]],
                'mismatch'  => $over + ['steps' => [...$tokened, ['do' => 'submit', 'use_token' => true, 'post' => ['new_password' => self::PASSWORD, 'new_password_confirm' => 'Different-Password-1']]]],
                'retried'   => $over + ['steps' => [...$tokened, ['do' => 'submit', 'use_token' => true, 'post' => ['new_name' => 'Far too long']], ['do' => 'submit', 'use_token' => true, 'post' => ['new_name' => 'Bob']]]],
            ]);

            foreach (['too long', 'invalid', 'mismatch'] as $name) {
                $r = $results[$name];
                self::assertNull($r['error'], "{$layout}, {$name}");
                self::assertSame('Fan A', $r['members'][1][0]['name'], "{$layout}, {$name}");
                self::assertNull($r['form_tokens'][0]['used_at'], "{$layout}, {$name}: it wasn't used");
            }

            self::assertSame('Bob', $results['retried']['members'][1][0]['name'], $layout);
            self::assertNotNull($results['retried']['form_tokens'][0]['used_at'], "{$layout}: used by the save that worked");
        }
    }

    public function testAFormTokenStillOnlyWorksOnce(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::MOON], 'steps' => [
            ['do' => 'signed_in'], ['do' => 'lose_session'],
            ['do' => 'submit', 'use_token' => true, 'post' => ['new_name' => 'First']],
            ['do' => 'submit', 'use_token' => true, 'post' => ['new_name' => 'Second']],
        ]]);

        self::assertSame('First', $r['members'][1][0]['name']);
    }

    public function testAFormTokenUsedElsewhereAtTheLastMomentStopsTheSave(): void
    {
        foreach (['update' => ['new_name' => 'Too Late'], 'delete' => ['delete_membership' => '1']] as $name => $post) {
            $r = $this->runOne(['layout' => 'owned', 'members' => [self::MOON], 'steps' => [
                ['do' => 'signed_in'], ['do' => 'lose_session'],
                ['do' => 'before', 'match' => '/^\s*UPDATE lstr_member_form_sessions/', 'sql' => "UPDATE lstr_member_form_sessions SET used_at = '2020-01-01'"],
                ['do' => 'submit', 'use_token' => true, 'post' => $post],
            ]]);

            self::assertNull($r['error'], $name);
            self::assertSame('Fan A', $r['members'][1][0]['name'] ?? null, $name . ': no save without the token');
            self::assertSame([], array_filter($r['statements'], static fn(string $sql): bool => preg_match('/^\s*(UPDATE|DELETE FROM) fl_/', $sql) === 1), $name);
        }
    }

    public function testAPasswordOnlyUpdateChecksTheColumnBeforeUsingTheToken(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::MOON], 'column_types' => [1 => ['password' => 'VARCHAR(32)']], 'steps' => [
            ['do' => 'signed_in'], ['do' => 'lose_session'],
            ['do' => 'submit', 'use_token' => true, 'post' => ['new_password' => self::PASSWORD, 'new_password_confirm' => self::PASSWORD]],
        ]]);

        self::assertNull($r['error']);
        self::assertStringContainsString(self::REFUSAL, self::flashed($r));
        self::assertNull($r['form_tokens'][0]['used_at']);
        self::assertSame([], array_filter($r['statements'], static fn(string $sql): bool => preg_match('/^\s*UPDATE fl_/', $sql) === 1));
    }
}
