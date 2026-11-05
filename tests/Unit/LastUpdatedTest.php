<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\ListingSettingsRepo;
use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use PHPUnit\Framework\TestCase;

/**
 * A listing's Last Updated date, read from the real row. A fan's change moves it when
 * the change really lands: on approval, or straight away on a listing with no queue.
 * Nothing else about a queued request moves it.
 */
final class LastUpdatedTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const OLD = '2020-01-01';

    private const MOON = ['listing' => 1, 'email' => 'a@x.test', 'name' => 'Fan A', 'url' => 'https://a.test/', 'added' => '2020-01-01'];
    private const SUN  = ['listing' => 2, 'email' => 'a@x.test', 'name' => 'Fan A', 'url' => 'https://a.test/', 'added' => '2020-01-01'];

    private const THIRD_LISTING = ['do' => 'sql', 'sql' => "INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated) VALUES (3, '2020-01-01')"];

    private const PASSWORD = 'A-Long-Enough-Password-1';

    /** How many times the date was written. */
    private static function touches(array $r): int
    {
        return count(array_filter($r['statements'], static fn(string $sql): bool => str_contains($sql, 'last_updated = CURDATE()')));
    }

    private static function dates(array $r): array
    {
        return array_map('strval', $r['last_updated']);
    }

    // ------------------------------------------------------------------
    // The date itself
    // ------------------------------------------------------------------

    public function testTouchingMovesAnExistingDateAndAddsAMissingRow(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'steps' => [
            ['do' => 'touch', 'listing' => 1],
            ['do' => 'touch', 'listing' => 9],
        ]]);

        self::assertNull($r['error']);
        self::assertSame($r['today'], $r['last_updated'][1], 'it used to fail silently on SQLite, so no test could see this');
        self::assertSame(self::OLD, $r['last_updated'][2]);
        self::assertSame($r['today'], $r['last_updated'][9]);
    }

    public function testAJoinDoesNotMoveTheDateUntilTheMemberIsApproved(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $r = $this->runOne(['layout' => $layout, 'steps' => [['do' => 'join', 'email' => 'new@x.test']]]);

            self::assertNull($r['error'], $layout);
            self::assertSame('new@x.test', $r['members'][1][0]['email'] ?? null, $layout);
            self::assertSame(self::OLD, $r['last_updated'][1], $layout);
        }
    }

    // ------------------------------------------------------------------
    // Approving
    // ------------------------------------------------------------------

    public function testEveryApprovalPathMovesTheDateOfTheListingsItChanged(): void
    {
        $queue = [
            self::THIRD_LISTING,
            ['do' => 'queue', 'listing' => 1, 'changes' => ['name' => 'Older'], 'as' => 'moon1', 'submitted_at' => '2026-01-01 00:00:00'],
            ['do' => 'queue', 'listing' => 1, 'changes' => ['name' => 'Newer'], 'as' => 'moon2', 'submitted_at' => '2026-01-02 00:00:00'],
            ['do' => 'queue', 'listing' => 2, 'changes' => ['name' => 'Sunny'], 'as' => 'sun', 'submitted_at' => '2026-01-03 00:00:00'],
            ['do' => 'trace_from_here'],
        ];
        $paths = [
            'Approve'     => [[['do' => 'approve', 'id' => 'moon1']], 1, false],
            'Approve All' => [[['do' => 'approve_all', 'listing' => 0]], 2, true],
            'Approve All, one listing' => [[['do' => 'approve_all', 'listing' => 1]], 1, false],
            'bulk'        => [[['do' => 'bulk_approve', 'ids' => ['moon1', 'moon2', 'sun']]], 2, true],
            'overview'    => [[['do' => 'approve_overview']], 2, true],
        ];

        foreach (['owned', 'enth'] as $layout) {
            $scenarios = [];
            foreach ($paths as $name => [$steps]) {
                $scenarios[$name] = ['layout' => $layout, 'members' => [self::MOON, self::SUN], 'steps' => [...$queue, ...$steps]];
            }
            foreach ($this->runScenarios($scenarios) as $name => $r) {
                [, $touches, $sunMoves] = $paths[$name];
                $why = "{$layout}, {$name}";
                self::assertNull($r['error'], $why);
                self::assertSame($r['today'], $r['last_updated'][1], $why . ': the listing it changed');
                self::assertSame($sunMoves ? $r['today'] : self::OLD, $r['last_updated'][2], $why);
                self::assertSame(self::OLD, $r['last_updated'][3], $why . ': a listing with nothing queued');
                self::assertSame($touches, self::touches($r), $why . ': once per listing, however many requests');
            }
        }
    }

    /** @return array<string, array{0: array, 1: list<array>}> */
    private static function outcomesThatChangeNothing(): array
    {
        $approve = ['do' => 'approve'];

        return [
            'invalid'      => [[], [['do' => 'queue', 'changes' => ['email' => '']], $approve]],
            'nothing'      => [[], [['do' => 'queue', 'changes' => ['name' => 'Fan A']], $approve]],
            'conflict'     => [[], [['do' => 'insert_member', 'member' => ['listing' => 1, 'email' => 'b@x.test']], ['do' => 'queue', 'changes' => ['email' => 'b@x.test']], $approve]],
            'member gone'  => [[], [['do' => 'queue', 'email' => 'nobody@x.test', 'changes' => ['name' => 'Ghost']], $approve]],
            'already used' => [[], [['do' => 'queue', 'changes' => ['name' => 'X']], ['do' => 'sql', 'sql' => 'DELETE FROM lstr_pending_updates'], $approve]],
            'too long'     => [['column_types' => [1 => ['name' => 'VARCHAR(5)']]], [['do' => 'queue', 'changes' => ['name' => 'Far too long a name']], $approve]],
            'needs review' => [[], [['do' => 'queue', 'changes' => ['name' => 'X'], 'review_state' => 'write_unconfirmed'], $approve]],
            'failed'       => [[], [['do' => 'queue', 'changes' => ['name' => 'X']], ['do' => 'fail_on', 'regex' => '/^\s*DELETE FROM lstr_pending_updates/'], $approve]],
            'unconfirmed'  => [[], [['do' => 'queue', 'changes' => ['name' => 'X']], ['do' => 'no_transactions'], ['do' => 'fail_after', 'seen' => '/^UPDATE fl_moon/', 'regex' => '/^SELECT \* FROM fl_moon/'], $approve]],
            'unresolved'   => [[], [['do' => 'queue', 'changes' => ['name' => 'X']], ['do' => 'open_transaction'], ['do' => 'fail_rollback'], $approve, ['do' => 'connection_ends']]],
            'rejected'     => [[], [['do' => 'queue', 'changes' => ['name' => 'X']], ['do' => 'reject']]],
            'bulk reject'  => [[], [['do' => 'queue', 'changes' => ['name' => 'X']], ['do' => 'bulk_reject', 'ids' => ['queued']]]],
        ];
    }

    public function testNoOtherOutcomeMovesTheDate(): void
    {
        $scenarios = [];
        foreach (self::outcomesThatChangeNothing() as $name => [$over, $steps]) {
            $scenarios[$name] = $over + ['layout' => 'owned', 'members' => [self::MOON], 'steps' => $steps];
        }

        foreach ($this->runScenarios($scenarios) as $name => $r) {
            self::assertSame(self::OLD, $r['last_updated'][1], $name);
        }
    }

    public function testASuccessfulNamelessBatchRowStillMovesTheDateOnlyWhenOneApplies(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::MOON], 'steps' => [
            ['do' => 'queue', 'changes' => ['email' => ''], 'as' => 'bad', 'submitted_at' => '2026-01-01 00:00:00'],
            ['do' => 'queue', 'changes' => ['name' => 'Good'], 'as' => 'good', 'submitted_at' => '2026-01-02 00:00:00'],
            ['do' => 'trace_from_here'],
            ['do' => 'approve_all'],
        ]]);

        self::assertSame($r['today'], $r['last_updated'][1]);
        self::assertSame(1, self::touches($r));
    }

    public function testARetryAfterAFailureMovesTheDateOnce(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::MOON], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'Retried']],
            ['do' => 'fail_on', 'regex' => '/^\s*DELETE FROM lstr_pending_updates/'],
            ['do' => 'trace_from_here'],
            ['do' => 'approve'],
            ['do' => 'approve'],
        ]]);

        self::assertSame('Retried', $r['members'][1][0]['name']);
        self::assertSame($r['today'], $r['last_updated'][1]);
        self::assertSame(1, self::touches($r), 'the failed try wrote no date');
    }

    // ------------------------------------------------------------------
    // Applied straight away
    // ------------------------------------------------------------------

    public function testADirectUpdateMovesTheDateAndAPasswordOnlyOneDoesNot(): void
    {
        $signedIn = ['do' => 'signed_in'];
        $common   = ['members' => [self::MOON]];
        foreach (['owned', 'enth'] as $layout) {
            $results = $this->runScenarios([
                'name'      => $common + ['layout' => $layout, 'steps' => [$signedIn, ['do' => 'submit', 'post' => ['new_name' => 'New Name']]]],
                'rename'    => $common + ['layout' => $layout, 'steps' => [$signedIn, ['do' => 'submit', 'post' => ['new_email' => 'new@x.test']]]],
                'password'  => $common + ['layout' => $layout, 'steps' => [$signedIn, ['do' => 'submit', 'post' => ['new_password' => self::PASSWORD, 'new_password_confirm' => self::PASSWORD]]]],
                'no change' => $common + ['layout' => $layout, 'steps' => [$signedIn, ['do' => 'submit', 'post' => []]]],
                'refused'   => $common + ['layout' => $layout, 'steps' => [$signedIn, ['do' => 'submit', 'post' => ['new_email' => 'not an email']]]],
                'queued'    => $common + ['layout' => $layout, 'queue_updates' => true, 'steps' => [$signedIn, ['do' => 'submit', 'post' => ['new_name' => 'New Name']]]],
                'failed'    => $common + ['layout' => $layout, 'steps' => [$signedIn, ['do' => 'fail_on', 'regex' => '/^\s*UPDATE fl_moon/'], ['do' => 'submit', 'post' => ['new_email' => 'new@x.test']]]],
            ]);

            foreach (['name', 'rename'] as $moves) {
                self::assertSame($results[$moves]['today'], $results[$moves]['last_updated'][1], "{$layout}, {$moves}");
            }
            foreach (['password', 'no change', 'refused', 'queued', 'failed'] as $stays) {
                self::assertSame(self::OLD, $results[$stays]['last_updated'][1], "{$layout}, {$stays}");
            }
        }
    }

    public function testShowurlSettlingForAMemberWithNoWebsiteDoesNotCount(): void
    {
        // Older scripts left showurl at 1 with no website. This form sets it to 0 on save.
        $noWebsite = ['url' => ''] + self::MOON;
        $r = $this->runOne(['layout' => 'owned', 'members' => [$noWebsite], 'steps' => [
            ['do' => 'signed_in'],
            ['do' => 'submit', 'post' => ['new_password' => self::PASSWORD, 'new_password_confirm' => self::PASSWORD]],
        ]]);

        self::assertSame('0', (string) $r['members'][1][0]['showurl']);
        self::assertSame(self::OLD, $r['last_updated'][1]);
    }

    public function testShowurlAloneDoesNotCountWhenItIsApprovedEither(): void
    {
        // The same password-only save as above, on a listing with a queue: what's queued
        // is only showurl, and a queue setting shouldn't change whether that counts.
        $noWebsite = ['url' => ''] + self::MOON;
        foreach (['owned', 'enth'] as $layout) {
            $r = $this->runOne(['layout' => $layout, 'queue_updates' => true, 'members' => [$noWebsite], 'steps' => [
                ['do' => 'signed_in'],
                ['do' => 'submit', 'post' => ['new_password' => self::PASSWORD, 'new_password_confirm' => self::PASSWORD]],
                ['do' => 'approve_all'],
            ]]);

            self::assertNull($r['error'], $layout);
            self::assertSame('0', (string) $r['members'][1][0]['showurl'], "{$layout}: the request really was applied");
            self::assertSame(self::OLD, $r['last_updated'][1], $layout);
        }

        $queued = ['do' => 'queue', 'changes' => ['showurl' => 0]];
        $paths  = [
            'Approve'     => ['do' => 'approve'],
            'Approve All' => ['do' => 'approve_all'],
            'bulk'        => ['do' => 'bulk_approve', 'ids' => ['queued']],
            'overview'    => ['do' => 'approve_overview'],
        ];
        $scenarios = [];
        foreach ($paths as $name => $step) {
            $scenarios[$name] = ['layout' => 'owned', 'members' => [self::MOON], 'steps' => [$queued, $step]];
        }
        foreach ($this->runScenarios($scenarios) as $name => $r) {
            self::assertNull($r['error'], $name);
            self::assertSame('0', (string) $r['members'][1][0]['showurl'], "{$name}: the request really was applied");
            self::assertSame(self::OLD, $r['last_updated'][1], $name);
        }
    }

    public function testShowurlAloneDoesNotUseUpABatchsOneMoveOfTheDate(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::MOON], 'steps' => [
            ['do' => 'queue', 'changes' => ['showurl' => 0], 'as' => 'hide', 'submitted_at' => '2026-01-01 00:00:00'],
            ['do' => 'queue', 'changes' => ['name' => 'Renamed'], 'as' => 'rename', 'submitted_at' => '2026-01-02 00:00:00'],
            ['do' => 'trace_from_here'],
            ['do' => 'approve_all'],
        ]]);

        self::assertSame('Renamed', $r['members'][1][0]['name']);
        self::assertSame($r['today'], $r['last_updated'][1]);
        self::assertSame(1, self::touches($r));
    }

    public function testShowurlAlongsideARealChangeCounts(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'members' => [self::MOON], 'steps' => [
            ['do' => 'queue', 'changes' => ['name' => 'Renamed', 'showurl' => 0]],
            ['do' => 'approve'],
        ]]);

        self::assertSame($r['today'], $r['last_updated'][1]);
    }

    public function testShowurlAloneIsRecognizedWhateverTheColumnsCapitals(): void
    {
        self::assertFalse(ListingSettingsRepo::changesCountAsUpdate([]));
        self::assertFalse(ListingSettingsRepo::changesCountAsUpdate(['showurl' => 0]));
        self::assertFalse(ListingSettingsRepo::changesCountAsUpdate(['ShowURL' => 1]), 'the applier hands over a column\'s real spelling');
        self::assertTrue(ListingSettingsRepo::changesCountAsUpdate(['name' => 'X']));
        self::assertTrue(ListingSettingsRepo::changesCountAsUpdate(['showurl' => 0, 'name' => 'X']));
        self::assertTrue(ListingSettingsRepo::changesCountAsUpdate(['additional_fave' => 'Cats']));
    }

    public function testCollectiveUpdateMovesTheDateOfEachListingItChanged(): void
    {
        $token = ['do' => 'collective_token'];
        $both  = ['listing_ids' => ['1', '2']];

        foreach (['owned', 'enth'] as $layout) {
            $results = $this->runScenarios([
                'both'      => ['layout' => $layout, 'members' => [self::MOON, self::SUN], 'steps' => [$token, ['do' => 'collective', 'post' => $both + ['name' => 'New Name']]]],
                'one'       => ['layout' => $layout, 'members' => [self::MOON, self::SUN], 'steps' => [$token, ['do' => 'collective', 'post' => ['listing_ids' => ['2'], 'name' => 'New Name']]]],
                'matches'   => ['layout' => $layout, 'members' => [self::MOON, ['name' => 'New Name'] + self::SUN], 'steps' => [$token, ['do' => 'collective', 'post' => $both + ['name' => 'New Name']]]],
                'rename'    => ['layout' => $layout, 'members' => [self::MOON, self::SUN], 'steps' => [$token, ['do' => 'collective', 'post' => $both + ['new_email' => 'new@x.test']]]],
                'password'  => ['layout' => $layout, 'members' => [self::MOON, self::SUN], 'steps' => [$token, ['do' => 'collective', 'post' => $both + ['new_password' => self::PASSWORD, 'new_password_confirm' => self::PASSWORD]]]],
                'queued'    => ['layout' => $layout, 'queue_updates' => true, 'members' => [self::MOON, self::SUN], 'steps' => [$token, ['do' => 'collective', 'post' => $both + ['name' => 'New Name']]]],
            ]);

            foreach (['both', 'rename'] as $name) {
                self::assertSame($results[$name]['today'], $results[$name]['last_updated'][1], "{$layout}, {$name}");
                self::assertSame($results[$name]['today'], $results[$name]['last_updated'][2], "{$layout}, {$name}");
            }
            self::assertSame(self::OLD, $results['one']['last_updated'][1], $layout);
            self::assertSame($results['one']['today'], $results['one']['last_updated'][2], $layout);
            self::assertSame($results['matches']['today'], $results['matches']['last_updated'][1], $layout);
            self::assertSame(self::OLD, $results['matches']['last_updated'][2], "{$layout}: its details already matched");
            foreach (['password', 'queued'] as $name) {
                self::assertSame([1 => self::OLD, 2 => self::OLD], $results[$name]['last_updated'], "{$layout}, {$name}");
            }
        }
    }

    public function testCollectiveRemovingAWebsiteCountsOnlyWhenThereWasOne(): void
    {
        $remove = [['do' => 'collective_token'], ['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'delete_url' => '1']]];
        $noSite = ['url' => ''] + self::MOON;

        foreach (['owned', 'enth'] as $layout) {
            $common  = ['layout' => $layout, 'steps' => $remove];
            $results = $this->runScenarios([
                'already gone' => $common + ['members' => [['showurl' => 0] + $noSite]],
                'showurl only' => $common + ['members' => [$noSite]],
                'a website'    => $common + ['members' => [self::MOON]],
                'a hidden one' => $common + ['members' => [['showurl' => 0] + self::MOON]],
            ]);

            foreach ($results as $name => $r) {
                self::assertNull($r['error'], "{$layout}, {$name}");
                self::assertSame('', (string) $r['members'][1][0]['url'], "{$layout}, {$name}");
                self::assertSame('0', (string) $r['members'][1][0]['showurl'], "{$layout}, {$name}");
            }

            foreach (['already gone', 'showurl only'] as $stays) {
                self::assertSame(self::OLD, $results[$stays]['last_updated'][1], "{$layout}, {$stays}");
            }
            foreach (['a website', 'a hidden one'] as $moves) {
                self::assertSame($results[$moves]['today'], $results[$moves]['last_updated'][1], "{$layout}, {$moves}");
            }

            $nothing = $results['already gone'];
            self::assertSame([], array_values(array_filter($nothing['statements'], static fn(string $sql): bool => preg_match('/^\s*UPDATE fl_moon/', $sql) === 1)), "{$layout}: nothing to write");
            self::assertStringContainsString('No changes were needed', implode(' ', array_map('strval', $nothing['flashes'][1])), "{$layout}: and the fan is told so");
        }
    }

    public function testCollectiveRemovingAWebsiteThroughTheQueueMatches(): void
    {
        $remove = [['do' => 'collective_token'], ['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'delete_url' => '1']]];
        $noSite = ['url' => ''] + self::MOON;
        $members = [
            'already gone' => ['showurl' => 0] + $noSite,
            'showurl only' => $noSite,
            'a website'    => self::MOON,
        ];
        $asks = [
            'already gone' => null,
            'showurl only' => ['showurl' => 0],
            'a website'    => ['url' => '', 'showurl' => 0],
        ];

        foreach (['owned', 'enth'] as $layout) {
            $scenarios = [];
            foreach ($members as $name => $member) {
                $common = ['layout' => $layout, 'queue_updates' => true, 'members' => [$member]];
                $scenarios["{$name}, asked"]    = $common + ['steps' => $remove];
                $scenarios["{$name}, approved"] = $common + ['steps' => [...$remove, ['do' => 'approve_all']]];
            }
            $results = $this->runScenarios($scenarios);

            foreach ($asks as $name => $wanted) {
                $queue = $results["{$name}, asked"]['queue'];
                if ($wanted === null) {
                    self::assertSame([], $queue, "{$layout}, {$name}: no request for nothing");
                } else {
                    self::assertCount(1, $queue, "{$layout}, {$name}");
                    self::assertEquals($wanted, json_decode((string) $queue[0]['changes'], true), "{$layout}, {$name}");
                }
            }

            foreach (['already gone', 'showurl only'] as $stays) {
                self::assertSame(self::OLD, $results["{$stays}, approved"]['last_updated'][1], "{$layout}, {$stays}");
            }
            $approved = $results['a website, approved'];
            self::assertSame($approved['today'], $approved['last_updated'][1], $layout);
            self::assertSame('', (string) $approved['members'][1][0]['url'], $layout);
        }
    }

    public function testCollectiveANewWebsiteOnlyAsksForWhatChanges(): void
    {
        $newSite = [['do' => 'collective_token'], ['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'url' => 'https://new.test/']]];

        foreach (['owned', 'enth'] as $layout) {
            $results = $this->runScenarios([
                'showing' => ['layout' => $layout, 'queue_updates' => true, 'members' => [self::MOON], 'steps' => $newSite],
                'hidden'  => ['layout' => $layout, 'queue_updates' => true, 'members' => [['showurl' => 0] + self::MOON], 'steps' => $newSite],
                'direct'  => ['layout' => $layout, 'members' => [['showurl' => 0] + self::MOON], 'steps' => $newSite],
            ]);

            self::assertEquals(['url' => 'https://new.test/'], json_decode((string) $results['showing']['queue'][0]['changes'], true), $layout);
            self::assertEquals(['url' => 'https://new.test/', 'showurl' => 1], json_decode((string) $results['hidden']['queue'][0]['changes'], true), $layout);

            $direct = $results['direct'];
            self::assertSame('https://new.test/', $direct['members'][1][0]['url'], $layout);
            self::assertSame('1', (string) $direct['members'][1][0]['showurl'], "{$layout}: a new website is shown, as it always has been");
            self::assertSame($direct['today'], $direct['last_updated'][1], $layout);
        }
    }
}
