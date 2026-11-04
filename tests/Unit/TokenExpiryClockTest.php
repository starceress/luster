<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Database;
use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use Luster\Tests\Unit\Support\SqliteDatabase;
use Luster\Tests\Unit\Support\TracingPdo;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Every token's expiry is checked against the database's NOW(), so it has to be written
 * from that clock too. PHP's clock can be hours away from it: a 30-minute token could
 * last 6.5 hours, or a reset link could be dead on arrival.
 *
 * The clocks really being apart is checked on MariaDB in the release checks, because
 * NOW() is scripted here.
 */
final class TokenExpiryClockTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const FAN = ['listing' => 1, 'email' => 'a@x.test', 'name' => 'Fan A', 'password' => 'a-password-1'];

    private const HALF_AN_HOUR = 1800;
    private const ONE_HOUR     = 3600;
    private const THIRTY_DAYS  = 2592000;

    /**
     * Where every place that writes an expiry puts it, and how long that token lasts.
     * reads_first: how many times it reads the clock before that, for the rate limiter.
     */
    private const PLACES = [
        'form token'           => ['steps' => [['do' => 'signed_in']], 'where' => 'form_tokens', 'life' => self::HALF_AN_HOUR, 'reads_first' => 0],
        'fan reset'            => ['steps' => [['do' => 'reset_request']], 'where' => 'fan_resets', 'life' => self::ONE_HOUR, 'reads_first' => 1],
        'collective update'    => ['steps' => [['do' => 'collective_token']], 'where' => 'collective', 'life' => self::ONE_HOUR, 'reads_first' => 0],
        'KIM reset'            => ['steps' => [['do' => 'kim_reset_request']], 'where' => 'kim_resets', 'life' => self::ONE_HOUR, 'reads_first' => 1],
        'admin reset'          => ['steps' => [['do' => 'admin_forgot']], 'where' => 'admin_resets', 'life' => self::ONE_HOUR, 'reads_first' => 1],
        'remember me'          => ['steps' => [['do' => 'admin_login']], 'where' => 'remember_tokens', 'life' => self::THIRTY_DAYS, 'reads_first' => 0],
        'remember me, rotated' => ['steps' => [['do' => 'admin_remembered']], 'where' => 'remember_tokens', 'life' => self::THIRTY_DAYS, 'reads_first' => 0],
    ];

    /** Far enough into the future that the token is still good on any scripted clock. */
    private const OLD_REMEMBER_EXPIRY = '9999-12-31 23:59:59';

    private string $zoneBefore = 'UTC';

    protected function setUp(): void
    {
        $this->zoneBefore = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zoneBefore);
    }

    // ------------------------------------------------------------------
    // Database::expiresIn()
    // ------------------------------------------------------------------

    /** @return array<string, array{string, string, int, string}> */
    public static function clocks(): array
    {
        return [
            'PHP far ahead'   => ['Pacific/Kiritimati', '2026-11-04 12:00:00', 1800, '2026-11-04 12:30:00'],
            'PHP far behind'  => ['Pacific/Pago_Pago', '2026-11-04 12:00:00', 3600, '2026-11-04 13:00:00'],
            'thirty days'     => ['UTC', '2026-11-04 12:00:00', 2592000, '2026-12-04 12:00:00'],
            'over a year end' => ['UTC', '2026-12-31 23:45:00', 1800, '2027-01-01 00:15:00'],
            'to a leap day'   => ['UTC', '2028-02-28 23:59:59', 1, '2028-02-29 00:00:00'],

            // In Berlin 02:00-03:00 doesn't exist on 2026-03-29, and happens twice on 2026-10-25.
            // In New York 01:00-02:00 happens twice on 2026-11-01.
            'to an hour PHP skips'     => ['Europe/Berlin', '2026-03-29 01:45:00', 1800, '2026-03-29 02:15:00'],
            'from an hour PHP skips'   => ['Europe/Berlin', '2026-03-29 02:30:00', 1800, '2026-03-29 03:00:00'],
            'in an hour PHP repeats'   => ['Europe/Berlin', '2026-10-25 02:30:00', 1800, '2026-10-25 03:00:00'],
            'over the hour PHP skips'  => ['Europe/Berlin', '2026-03-28 12:00:00', 86400, '2026-03-29 12:00:00'],
            'over an hour PHP repeats' => ['America/New_York', '2026-10-31 12:00:00', 86400, '2026-11-01 12:00:00'],
        ];
    }

    #[DataProvider('clocks')]
    public function testAnExpiryIsTheDatabasesClockPlusTheSeconds(string $phpZone, string $dbNow, int $seconds, string $expected): void
    {
        date_default_timezone_set($phpZone);

        self::assertSame($expected, $this->databaseWhoseClockSays($dbNow)->expiresIn($seconds));
    }

    /** @return array<string, array{mixed}> */
    public static function unreadableClocks(): array
    {
        return [
            'null'                  => [null],
            'empty'                 => [''],
            'not a date'            => ['soon'],
            'a date with no time'   => ['2026-11-04'],
            'fractions of a second' => ['2026-11-04 12:00:00.123456'],
            'month 13'              => ['2026-13-04 12:00:00'],
            'February 30'           => ['2026-02-30 12:00:00'],
            'hour 25'               => ['2026-11-04 25:00:00'],
            'zero date'             => ['0000-00-00 00:00:00'],
            'a number'              => [20261104120000],
        ];
    }

    #[DataProvider('unreadableClocks')]
    public function testAClockThatCantBeUnderstoodThrows(mixed $dbNow): void
    {
        $this->expectException(RuntimeException::class);

        $this->databaseWhoseClockSays($dbNow)->expiresIn(1800);
    }

    public function testAClockThatCantBeReadThrows(): void
    {
        $pdo = TracingPdo::memory();
        $pdo->failOn = '/^SELECT NOW\(\)$/';

        $db   = SqliteDatabase::make('');
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $pdo);

        $this->expectException(PDOException::class);

        $db->expiresIn(1800);
    }

    public function testNowAndAStoredTimeCompareAsPlainWallClockTimes(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');
        $db = $this->databaseWhoseClockSays('2026-06-15 12:00:00');

        self::assertSame('2026-06-15 12:00:00', $db->now()->format('Y-m-d H:i:s'));
        self::assertEquals($db->now(), $db->storedTime('2026-06-15 12:00:00'));
        self::assertLessThan($db->now(), $db->storedTime('2026-06-15 11:59:59'));
        self::assertGreaterThan($db->now(), $db->storedTime('2026-06-15 12:00:01'));
    }

    // ------------------------------------------------------------------
    // Every place that writes an expiry
    // ------------------------------------------------------------------

    public function testEveryPlaceWritesItsExpiryFromTheDatabasesClock(): void
    {
        $clocks = [
            'database far ahead'  => ['php_zone' => 'Pacific/Pago_Pago', 'db_now' => '2031-06-15 12:00:00'],
            'database far behind' => ['php_zone' => 'Pacific/Kiritimati', 'db_now' => '2019-06-15 12:00:00'],
            'an hour PHP skips'   => ['php_zone' => 'Europe/Berlin', 'db_now' => '2026-03-29 01:45:00'],
            'an hour PHP repeats' => ['php_zone' => 'Europe/Berlin', 'db_now' => '2026-10-25 02:30:00'],
        ];

        $scenarios = [];
        foreach ($clocks as $clock => $settings) {
            foreach (self::PLACES as $place => ['steps' => $steps]) {
                $scenarios["{$place}, {$clock}"] = $settings + $this->scenario($steps);
            }
        }
        $results = $this->runScenarios($scenarios);

        foreach ($clocks as $clock => ['db_now' => $dbNow]) {
            foreach (self::PLACES as $place => ['where' => $where, 'life' => $life]) {
                $why = "{$place}, {$clock}";
                $r   = $results[$why];

                // Every scenario starts with an old remember-me token. Rotating replaces it.
                $written = array_diff($r['expiries'][$where], [self::OLD_REMEMBER_EXPIRY]);

                self::assertNull($r['error'], $why);
                self::assertNotSame([], $written, $why . ': a token was written');
                foreach ($written as $expiry) {
                    self::assertSame(self::plus($dbNow, $life), $expiry, $why);
                }
            }
        }
    }

    public function testATokenIsntDeadOnArrivalWhenPhpIsFarBehindTheDatabase(): void
    {
        $r = $this->runOne(['php_zone' => 'Pacific/Pago_Pago', 'db_now' => '2031-06-15 12:00:00'] + $this->scenario([
            ['do' => 'signed_in'],
            ['do' => 'lose_session'],
            ['do' => 'submit', 'post' => ['new_name' => 'Chosen'], 'use_token' => true],
        ]));

        self::assertNull($r['error']);
        self::assertSame('Chosen', $r['members'][1][0]['name']);
    }

    public function testATokenRunsOutOnTimeWhenPhpIsFarAheadOfTheDatabase(): void
    {
        $scenarios = [];
        foreach (['12:29:59', '12:30:00'] as $time) {
            $scenarios[$time] = ['php_zone' => 'Pacific/Kiritimati', 'db_now' => '2026-06-15 12:00:00'] + $this->scenario([
                ['do' => 'signed_in'],
                ['do' => 'lose_session'],
                ['do' => 'db_clock', 'at' => "2026-06-15 {$time}"],
                ['do' => 'submit', 'post' => ['new_name' => 'Chosen'], 'use_token' => true],
            ]);
        }

        $results = $this->runScenarios($scenarios);

        self::assertSame('Chosen', $results['12:29:59']['members'][1][0]['name'], 'one second left');
        self::assertSame('Fan A', $results['12:30:00']['members'][1][0]['name'], 'out of time');
    }

    public function testWhenTheClockCantBeReadNoTokenIsWrittenAndNoLinkGoesOut(): void
    {
        $scenarios = [];
        foreach (self::PLACES as $place => ['steps' => $steps, 'reads_first' => $readsFirst]) {
            $scenarios[$place] = $this->scenario([
                ['do' => 'fail_on_after', 'regex' => '/^SELECT NOW\(\)$/', 'let_through' => $readsFirst],
                ...$steps,
            ]);
        }

        foreach ($this->runScenarios($scenarios) as $place => $r) {
            $where = self::PLACES[$place]['where'];

            // Only the old remember-me token every scenario starts with, not even rotated.
            $expected = $where === 'remember_tokens' ? [self::OLD_REMEMBER_EXPIRY] : [];
            self::assertSame($expected, $r['expiries'][$where], $place);
            self::assertSame('', $r['mail'], $place);

            if ($place === 'KIM reset') {
                // issueLocked() rolls back, logs it and says no link was made.
                self::assertNull($r['error'], $place);
                self::assertStringContainsString('Issuing a reset link failed', $r['log'], $place);
            } else {
                self::assertStringContainsString('Simulated failure for: SELECT NOW()', (string) $r['error'], $place);
            }
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Everything each place needs to be there already, then the given steps.
     *
     * @param list<array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    private function scenario(array $steps): array
    {
        return [
            'layout'  => 'owned',
            'members' => [self::FAN],
            'steps'   => [
                ['do' => 'kim_entry', 'as' => 'entry', 'password' => 'a-password-1'],
                ['do' => 'admin_user'],
                ['do' => 'admin_remember_token', 'expires_at' => self::OLD_REMEMBER_EXPIRY],
                ...$steps,
            ],
        ];
    }

    private function databaseWhoseClockSays(mixed $now): Database
    {
        $db = SqliteDatabase::make('');
        $db->pdo()->sqliteCreateFunction('NOW', static fn(): mixed => $now);

        return $db;
    }

    /** Wall-clock time plus seconds, worked out on its own so a test can't just agree with the code. */
    private static function plus(string $dateTime, int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', strtotime($dateTime . ' UTC') + $seconds);
    }
}
