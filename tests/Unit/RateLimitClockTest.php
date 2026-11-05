<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Database;
use Luster\Core\RateLimit;
use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use Luster\Tests\Unit\Support\SqliteDatabase;
use Luster\Tests\Unit\Support\TracingPdo;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A public form can be reached through its embed or through public/index.php, and those
 * two run in different PHP zones when the Timezone setting and php.ini disagree. When the
 * limiter used PHP's clock, going back and forth kept resetting the count and could wipe
 * out a block, which on the Update form meant unlimited password guesses.
 *
 * Changing PHP's zone between calls stands for a request coming in the other way. The two
 * real entry points are checked over HTTP on MariaDB in the release checks.
 */
final class RateLimitClockTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const DDL = <<<SQL
CREATE TABLE lstr_rate_limits (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier    TEXT    NOT NULL,
    action        TEXT    NOT NULL,
    attempts      INTEGER NOT NULL DEFAULT 0,
    last_attempt  TEXT    NOT NULL,
    blocked_until TEXT    NULL,
    UNIQUE (identifier, action)
)
SQL;

    private const IP     = '1.2.3.4';
    private const ACTION = 'public_reset';
    private const MAX    = 5;
    private const WINDOW = 3600;

    private string $zoneBefore = 'UTC';
    private string $dbNow      = '2026-06-15 12:00:00';
    private Database $db;
    private RateLimit $limiter;

    protected function setUp(): void
    {
        $this->zoneBefore = date_default_timezone_get();

        $this->db = SqliteDatabase::make(self::DDL);
        $this->db->pdo()->sqliteCreateFunction('NOW', fn(): string => $this->dbNow);
        $this->limiter = new RateLimit($this->db);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zoneBefore);
    }

    /** @return array<string, array{string, string}> */
    public static function doors(): array
    {
        return [
            'New York, then Berlin' => ['America/New_York', 'Europe/Berlin'],
            'Berlin, then New York' => ['Europe/Berlin', 'America/New_York'],
            'far ahead, far behind' => ['Pacific/Kiritimati', 'Pacific/Pago_Pago'],
            'far behind, far ahead' => ['Pacific/Pago_Pago', 'Pacific/Kiritimati'],
            'half an hour apart'    => ['Asia/Kolkata', 'Asia/Dhaka'],
            'the same zone'         => ['Europe/Berlin', 'Europe/Berlin'],
        ];
    }

    // ------------------------------------------------------------------
    // Going back and forth
    // ------------------------------------------------------------------

    #[DataProvider('doors')]
    public function testGoingBackAndForthDoesntResetTheCount(string $first, string $second): void
    {
        $allowed = 0;
        for ($i = 0; $i < 20; $i++) {
            date_default_timezone_set($i % 2 === 0 ? $first : $second);
            if (!$this->limiter->isBlocked(self::IP, self::ACTION)) {
                $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
                $allowed++;
            }
        }

        self::assertSame(self::MAX, $allowed);
    }

    #[DataProvider('doors')]
    public function testABlockHoldsThroughTheOtherDoor(string $first, string $second): void
    {
        date_default_timezone_set($first);
        for ($i = 0; $i < self::MAX; $i++) {
            $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
        }

        date_default_timezone_set($second);
        self::assertTrue($this->limiter->isBlocked(self::IP, self::ACTION));
        self::assertNotNull($this->limiter->blockedUntil(self::IP, self::ACTION));
        self::assertCount(1, $this->limiter->getActiveBlocks());

        // Looking at it must not have cleared it for the first door either.
        date_default_timezone_set($first);
        self::assertTrue($this->limiter->isBlocked(self::IP, self::ACTION));
    }

    #[DataProvider('doors')]
    public function testABlockDoesntLastLongerThroughTheOtherDoor(string $first, string $second): void
    {
        date_default_timezone_set($first);
        for ($i = 0; $i < self::MAX; $i++) {
            $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
        }

        date_default_timezone_set($second);
        $this->dbNow = '2026-06-15 13:00:00';

        self::assertFalse($this->limiter->isBlocked(self::IP, self::ACTION));
    }

    // ------------------------------------------------------------------
    // The database's clock decides
    // ------------------------------------------------------------------

    public function testTheStoredTimesAreTheDatabasesClock(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');

        for ($i = 0; $i < self::MAX; $i++) {
            $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
        }

        self::assertSame(
            ['last_attempt' => '2026-06-15 12:00:00', 'blocked_until' => '2026-06-15 13:00:00'],
            $this->row(),
        );
    }

    public function testABlockRunsOutOnTheDatabasesClock(): void
    {
        date_default_timezone_set('Pacific/Pago_Pago');
        for ($i = 0; $i < self::MAX; $i++) {
            $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
        }

        $this->dbNow = '2026-06-15 12:59:59';
        self::assertTrue($this->limiter->isBlocked(self::IP, self::ACTION), 'one second left');

        $this->dbNow = '2026-06-15 13:00:00';
        self::assertFalse($this->limiter->isBlocked(self::IP, self::ACTION), 'out of time');
        self::assertNull($this->row(), 'and the row is gone, so the count starts again');
    }

    public function testTheCountRunsOutOnTheDatabasesClock(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');
        $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);

        $this->dbNow = '2026-06-15 13:00:00';
        $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
        self::assertSame(2, $this->attempts(), 'the first attempt is exactly a window old, so it still counts');

        $this->dbNow = '2026-06-15 14:00:01';
        $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
        self::assertSame(1, $this->attempts(), 'the last attempt is over a window old');
    }

    public function testAnHourPhpSkipsDoesntShortenABlock(): void
    {
        // In Berlin 02:00-03:00 doesn't exist on 2026-03-29.
        date_default_timezone_set('Europe/Berlin');
        $this->dbNow = '2026-03-29 01:30:00';
        for ($i = 0; $i < self::MAX; $i++) {
            $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
        }

        self::assertSame('2026-03-29 02:30:00', $this->row()['blocked_until']);

        $this->dbNow = '2026-03-29 02:29:59';
        self::assertTrue($this->limiter->isBlocked(self::IP, self::ACTION));
    }

    // ------------------------------------------------------------------
    // What the rest of the app gets told
    // ------------------------------------------------------------------

    public function testBlockedUntilIsTheRightMomentOnPhpsClock(): void
    {
        date_default_timezone_set('Pacific/Kiritimati');
        for ($i = 0; $i < self::MAX; $i++) {
            $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
        }

        $this->dbNow = '2026-06-15 12:45:00';
        $until = $this->limiter->blockedUntil(self::IP, self::ACTION);

        self::assertNotNull($until);
        self::assertEqualsWithDelta(time() + 900, $until->getTimestamp(), 5, '15 minutes left, whatever the clocks say');
    }

    public function testTheTimeLeftIsRealSecondsAcrossTheAutumnClockChange(): void
    {
        $onPhpClock = new \ReflectionMethod(RateLimit::class, 'onPhpClock');
        $utc        = new \DateTimeZone('UTC');
        $newYork    = new \DateTimeZone('America/New_York');

        // 01:30 EDT, half an hour before New York's clocks go back. An hour is left.
        $phpNow = (new \DateTimeImmutable('2026-11-01 05:30:00', $utc))->setTimezone($newYork);
        self::assertSame('01:30 EDT', $phpNow->format('H:i T'));
        $until = $onPhpClock->invoke(null, new \DateTimeImmutable('2026-06-15 13:00:00', $utc), new \DateTimeImmutable('2026-06-15 12:00:00', $utc), $phpNow);

        self::assertSame(3600, $until->getTimestamp() - $phpNow->getTimestamp(), 'it used to read 7,200 seconds, an hour late');
        self::assertSame('01:30 EST', $until->format('H:i T'));
    }

    public function testTheTimeLeftUsesPhpsRealNowWhenNoneIsGiven(): void
    {
        $onPhpClock = new \ReflectionMethod(RateLimit::class, 'onPhpClock');
        $utc        = new \DateTimeZone('UTC');

        $until = $onPhpClock->invoke(null, new \DateTimeImmutable('2026-06-15 13:00:00', $utc), new \DateTimeImmutable('2026-06-15 12:00:00', $utc));

        self::assertEqualsWithDelta(time() + 3600, $until->getTimestamp(), 5);
    }

    public function testTheAdminPanelGetsTheRightTimeOnPhpsClock(): void
    {
        for ($i = 0; $i < self::MAX; $i++) {
            $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
        }

        date_default_timezone_set('Asia/Tokyo');
        $this->dbNow = '2026-06-15 12:45:00';

        $blocks = $this->limiter->getActiveBlocks();
        self::assertCount(1, $blocks);

        // The template runs this through strtotime() and date() in PHP's zone.
        self::assertEqualsWithDelta(time() + 900, (int) strtotime($blocks[0]['blocked_until']), 5, '15 minutes left');
    }

    public function testTheAdminPanelListsWhatTheDatabasesClockSaysIsBlocked(): void
    {
        for ($i = 0; $i < self::MAX; $i++) {
            $this->limiter->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
        }

        $this->dbNow = '2026-06-15 12:59:59';
        self::assertCount(1, $this->limiter->getActiveBlocks());

        $this->dbNow = '2026-06-15 13:00:00';
        self::assertSame([], $this->limiter->getActiveBlocks());
        self::assertNull($this->limiter->blockedUntil(self::IP, self::ACTION));
    }

    // ------------------------------------------------------------------
    // When the clock can't be read
    // ------------------------------------------------------------------

    public function testAnAttemptChangesNothingWhenTheClockCantBeRead(): void
    {
        $db = $this->databaseWhoseClockFails();
        $db->pdo()->exec("INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
                          VALUES ('1.2.3.4', 'public_reset', 2, '2026-06-15 12:00:00', NULL)");

        try {
            (new RateLimit($db))->hit(self::IP, self::ACTION, self::MAX, self::WINDOW);
            self::fail('A failed clock read should throw.');
        } catch (PDOException) {
            // What this test is here for.
        }

        self::assertSame(
            ['attempts' => 2, 'last_attempt' => '2026-06-15 12:00:00'],
            $db->pdo()->query('SELECT attempts, last_attempt FROM lstr_rate_limits')->fetch(),
        );
    }

    public function testABlockIsntTreatedAsOverWhenTheClockCantBeRead(): void
    {
        $db = $this->databaseWhoseClockFails();
        $db->pdo()->exec("INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
                          VALUES ('1.2.3.4', 'public_reset', 5, '2026-06-15 12:00:00', '2026-06-15 13:00:00')");

        $this->expectException(PDOException::class);

        (new RateLimit($db))->isBlocked(self::IP, self::ACTION);
    }

    public function testCheckingSomeoneWithNoBlockDoesntNeedTheClock(): void
    {
        $db      = $this->databaseWhoseClockFails();
        $limiter = new RateLimit($db);

        // Most requests are one of these two.
        self::assertFalse($limiter->isBlocked(self::IP, self::ACTION), 'no row at all');

        $db->pdo()->exec("INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
                          VALUES ('1.2.3.4', 'public_reset', 2, '2026-06-15 12:00:00', NULL)");
        self::assertFalse($limiter->isBlocked(self::IP, self::ACTION), 'a count, but no block');
    }

    // ------------------------------------------------------------------
    // Through the real sign-ins
    // ------------------------------------------------------------------

    public function testGuessingAnUpdatePasswordThroughBothDoorsStillGetsBlocked(): void
    {
        $guesses = [];
        for ($i = 0; $i < 19; $i++) {
            $guesses[] = ['do' => 'php_zone', 'zone' => $i % 2 === 0 ? 'Europe/Berlin' : 'America/New_York'];
            $guesses[] = ['do' => 'sign_in', 'password' => 'wrong-guess-' . $i];
        }
        $rightPassword = [['do' => 'php_zone', 'zone' => 'Europe/Berlin'], ['do' => 'sign_in', 'password' => 'a-password-1']];
        $scenario      = static fn(array $steps): array => [
            'layout'  => 'owned',
            'db_now'  => '2026-06-15 12:00:00',
            'members' => [['listing' => 1, 'email' => 'a@x.test', 'name' => 'Fan A', 'password' => 'a-password-1']],
            'steps'   => $steps,
        ];

        $results = $this->runScenarios([
            'no guesses first' => $scenario($rightPassword),
            'guesses first'    => $scenario([...$guesses, ...$rightPassword]),
        ]);

        $signedIn = static fn(array $r): bool => in_array('lstr_update_authed_1', $r['session_authed'], true);
        self::assertTrue($signedIn($results['no guesses first']), 'the right password does work');

        $r       = $results['guesses first'];
        $flashes = array_map(static fn(array $f): string => implode(' ', $f), $r['flashes']);
        self::assertNull($r['error']);
        self::assertCount(10, array_filter($flashes, static fn(string $f): bool => str_contains($f, 'Incorrect password')), 'ten guesses get checked');
        self::assertCount(10, array_filter($flashes, static fn(string $f): bool => str_contains($f, 'Too many attempts')), 'then everything is turned away');
        self::assertFalse($signedIn($r), 'even the right password');
    }

    public function testABlockOnKimSignInHoldsThroughTheOtherDoor(): void
    {
        $entry         = ['do' => 'kim_entry', 'as' => 'entry', 'password' => 'a-password-1'];
        $rightPassword = [['do' => 'php_zone', 'zone' => 'Europe/Berlin'], ['do' => 'kim_sign_in', 'password' => 'a-password-1']];
        $guesses       = [['do' => 'php_zone', 'zone' => 'America/New_York']];
        for ($i = 0; $i < 5; $i++) {
            $guesses[] = ['do' => 'kim_sign_in', 'password' => 'wrong-guess-' . $i];
        }
        $scenario = static fn(array $steps): array => ['layout' => 'owned', 'db_now' => '2026-06-15 12:00:00', 'steps' => [$entry, ...$steps]];

        $results = $this->runScenarios([
            'no guesses first' => $scenario($rightPassword),
            'guesses first'    => $scenario([...$guesses, ...$rightPassword]),
        ]);

        $signedIn = static fn(array $r): bool => $r['session_authed'] !== [];
        self::assertTrue($signedIn($results['no guesses first']), 'the right password does work');
        self::assertNull($results['guesses first']['error']);
        self::assertFalse($signedIn($results['guesses first']), 'even the right password');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{last_attempt: string, blocked_until: ?string}|null */
    private function row(): ?array
    {
        $row = $this->db->pdo()->query('SELECT last_attempt, blocked_until FROM lstr_rate_limits')->fetch();

        return $row === false ? null : $row;
    }

    private function attempts(): int
    {
        return (int) $this->db->pdo()->query('SELECT attempts FROM lstr_rate_limits')->fetchColumn();
    }

    private function databaseWhoseClockFails(): Database
    {
        $pdo = TracingPdo::memory();
        $pdo->failOn = '/^SELECT NOW\(\)$/';

        $db   = SqliteDatabase::make('');
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $pdo);
        $pdo->exec(self::DDL);

        return $db;
    }
}
