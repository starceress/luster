<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use LogicException;
use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Support\MemberAddressBusy;
use Luster\Support\MemberAddressLock;
use Luster\Tests\Unit\Support\TracingPdo;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * The address lock's rules, against TracingPdo's scripted GET_LOCK. Really waiting
 * for a lock needs two connections, so that's checked on MariaDB instead.
 */
final class MemberAddressLockTest extends TestCase
{
    private TracingPdo $pdo;
    private MemberAddressLock $lock;
    private string $logFile;
    private string $previousLog;

    protected function setUp(): void
    {
        $this->logFile     = (string) tempnam(sys_get_temp_dir(), 'lstr_lock_log_');
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);

        $this->pdo  = TracingPdo::memory();
        $this->lock = $this->lockFor('install_one');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    private function lockFor(string $installId): MemberAddressLock
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_lock_cfg_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return [];');
        $db = new Database(new Config($path));
        unlink($path);
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $this->pdo);

        return new MemberAddressLock($db, $installId);
    }

    /** @return list<string> Just the lock statements, in order. */
    private function lockStatements(): array
    {
        return array_values(array_filter($this->pdo->statements, static fn(string $sql): bool => str_contains($sql, '_LOCK(')));
    }

    private function timesRun(string $function): int
    {
        return count(array_filter($this->pdo->statements, static fn(string $sql): bool => str_contains($sql, $function . '(')));
    }

    // ------------------------------------------------------------------
    // Getting and releasing
    // ------------------------------------------------------------------

    public function testItHoldsTheLocksDuringTheWorkAndReleasesThemAfter(): void
    {
        $during = null;
        $answer = $this->lock->run([[1, 'a@x.test'], [1, 'b@x.test']], function () use (&$during): string {
            $during = $this->pdo->locksHeld;
            return 'done';
        });

        self::assertSame('done', $answer);
        self::assertCount(2, $during);
        self::assertSame([], $this->pdo->locksHeld);
        self::assertSame(2, $this->timesRun('GET_LOCK'));
        self::assertSame(2, $this->timesRun('RELEASE_LOCK'));
    }

    public function testRepeatsAndCapitalsTakeOneLock(): void
    {
        $this->lock->run([[1, 'a@x.test'], [1, 'A@X.TEST'], [1, ' a@x.test ']], function (): void {
            self::assertCount(1, $this->pdo->locksHeld);
        });

        self::assertSame(1, $this->timesRun('GET_LOCK'));
    }

    public function testLocksAreTakenInTheSameOrderWhateverOrderTheyreAskedFor(): void
    {
        $first = null;
        $this->lock->run([[1, 'a@x.test'], [1, 'b@x.test']], function () use (&$first): void {
            $first = $this->pdo->locksHeld;
        });
        $second = null;
        $this->lock->run([[1, 'b@x.test'], [1, 'a@x.test']], function () use (&$second): void {
            $second = $this->pdo->locksHeld;
        });

        self::assertSame($first, $second);
        $sorted = $first;
        sort($sorted);
        self::assertSame($sorted, $first);
    }

    public function testTheNameDependsOnTheInstallTheListingAndTheAddress(): void
    {
        $name = $this->lock->nameFor(1, 'a@x.test');

        self::assertLessThanOrEqual(64, strlen($name), "MySQL's limit");
        self::assertSame(47, strlen($name));
        self::assertSame($name, $this->lock->nameFor(1, 'A@X.Test'));
        self::assertNotSame($name, $this->lock->nameFor(2, 'a@x.test'));
        self::assertNotSame($name, $this->lock->nameFor(1, 'b@x.test'));
        self::assertNotSame($name, $this->lockFor('install_two')->nameFor(1, 'a@x.test'), "GET_LOCK is server-wide");
    }

    public function testAThrowingJobStillReleases(): void
    {
        try {
            $this->lock->run([[1, 'a@x.test']], static function (): void {
                throw new RuntimeException('boom');
            });
            self::fail('The exception should come through');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame([], $this->pdo->locksHeld);
        self::assertFalse($this->lock->holds([[1, 'a@x.test']]));
    }

    public function testAFailedReleaseIsLoggedAndTheNextRunStillWorks(): void
    {
        $this->pdo->failOn = '/RELEASE_LOCK/';
        $this->lock->run([[1, 'a@x.test']], static fn(): bool => true);
        $this->pdo->failOn = null;

        self::assertStringContainsString('release failed', (string) file_get_contents($this->logFile));

        $ran = false;
        $this->pdo->locksHeld = [];
        $this->lock->run([[1, 'b@x.test']], static function () use (&$ran): void {
            $ran = true;
        });
        self::assertTrue($ran, "the failed release didn't leave this request thinking it still holds a lock");
    }

    // ------------------------------------------------------------------
    // Refusing
    // ------------------------------------------------------------------

    /** @dataProvider refusalProvider */
    public function testAnythingButAClearYesRefusesBeforeTheWorkAndReleasesWhatsHeld(?int $answer): void
    {
        // Sorted, so the second name is the one that refuses, with the first one held.
        $names = [$this->lock->nameFor(1, 'a@x.test'), $this->lock->nameFor(1, 'b@x.test')];
        sort($names);
        $this->pdo->lockAnswers[$names[1]] = $answer;

        $ran = false;
        try {
            $this->lock->run([[1, 'a@x.test'], [1, 'b@x.test']], static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('It should have refused');
        } catch (MemberAddressBusy) {
            self::assertFalse($ran);
        }

        self::assertSame([], $this->pdo->locksHeld, 'the first lock was released');
    }

    /** @return array<string, array{0: ?int}> */
    public static function refusalProvider(): array
    {
        return ['a timeout' => [0], 'an error' => [null]];
    }

    public function testAQueryErrorRefusesTheSameWay(): void
    {
        $this->pdo->failOn = '/GET_LOCK/';

        $ran = false;
        try {
            $this->lock->run([[1, 'a@x.test']], static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('It should have refused');
        } catch (MemberAddressBusy) {
            self::assertFalse($ran);
        }
        self::assertStringContainsString('Address lock failed', (string) file_get_contents($this->logFile));
    }

    public function testAnOlderServerDroppingTheFirstLockIsCaught(): void
    {
        // Both GET_LOCK calls answer 1, and the second silently releases the first.
        $this->pdo->oneLockAtATime = true;

        $ran = false;
        try {
            $this->lock->run([[1, 'a@x.test'], [1, 'b@x.test']], static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('It should have refused');
        } catch (MemberAddressBusy) {
            self::assertFalse($ran);
        }

        self::assertSame(2, $this->timesRun('GET_LOCK'), 'both answered yes');
        self::assertSame([], $this->pdo->locksHeld);
    }

    public function testOneLockStillWorksOnAnOlderServer(): void
    {
        $this->pdo->oneLockAtATime = true;

        self::assertSame('done', $this->lock->run([[1, 'a@x.test'], [1, 'A@x.test']], static fn(): string => 'done'));
    }

    public function testAFailedOwnershipCheckRefuses(): void
    {
        $this->pdo->failOn = '/IS_USED_LOCK/';

        $ran = false;
        try {
            $this->lock->run([[1, 'a@x.test']], static function () use (&$ran): void {
                $ran = true;
            });
            self::fail('It should have refused');
        } catch (MemberAddressBusy) {
            self::assertFalse($ran);
        }
        $this->pdo->failOn = null;
        self::assertSame([], $this->pdo->locksHeld);
    }

    // ------------------------------------------------------------------
    // Nesting
    // ------------------------------------------------------------------

    public function testANestedCallForHeldAddressesTakesNoSecondLock(): void
    {
        $inner = $this->lock->run([[1, 'a@x.test'], [1, 'b@x.test']], function (): string {
            return $this->lock->run([[1, 'A@x.test']], static fn(): string => 'inner');
        });

        self::assertSame('inner', $inner);
        self::assertSame(2, $this->timesRun('GET_LOCK'));
        self::assertSame(2, $this->timesRun('RELEASE_LOCK'));
    }

    public function testANestedCallForANewAddressThrowsBeforeItsWork(): void
    {
        $ran = false;
        try {
            $this->lock->run([[1, 'a@x.test']], function () use (&$ran): void {
                $this->lock->run([[1, 'new@x.test']], static function () use (&$ran): void {
                    $ran = true;
                });
            });
            self::fail('It should have thrown');
        } catch (LogicException) {
            self::assertFalse($ran);
        }

        self::assertSame(1, $this->timesRun('GET_LOCK'), 'it never tried to take the new one out of order');
        self::assertSame([], $this->pdo->locksHeld);
    }

    public function testANestedCallNeverReleasesAnOuterLockEarly(): void
    {
        $this->lock->run([[1, 'a@x.test']], function (): void {
            $this->lock->run([[1, 'a@x.test']], static fn(): bool => true);

            self::assertSame(0, $this->timesRun('RELEASE_LOCK'), 'still held after the nested call returned');
            self::assertTrue($this->lock->holds([[1, 'a@x.test']]));
            self::assertCount(1, $this->pdo->locksHeld);

            // A later step is still protected.
            $this->pdo->exec('SELECT 1');
        });

        $order = $this->pdo->statements;
        self::assertSame('SELECT 1', $order[count($order) - 2], 'the release came after the last of the work');
        self::assertStringContainsString('RELEASE_LOCK', $order[count($order) - 1]);
    }

    public function testANestedCallThatThrowsReleasesOnce(): void
    {
        try {
            $this->lock->run([[1, 'a@x.test']], function (): void {
                $this->lock->run([[1, 'a@x.test']], static function (): void {
                    throw new RuntimeException('boom');
                });
            });
            self::fail('The exception should come through');
        } catch (RuntimeException) {
            self::assertSame(1, $this->timesRun('RELEASE_LOCK'));
            self::assertSame([], $this->pdo->locksHeld);
        }
    }

    public function testHoldsAnswersForThisRequestOnly(): void
    {
        self::assertFalse($this->lock->holds([[1, 'a@x.test']]));

        $this->lock->run([[1, 'a@x.test']], function (): void {
            self::assertTrue($this->lock->holds([[1, 'A@x.test']]));
            self::assertFalse($this->lock->holds([[1, 'a@x.test'], [1, 'b@x.test']]));
            self::assertFalse($this->lock->holds([[2, 'a@x.test']]));
        });

        self::assertFalse($this->lock->holds([[1, 'a@x.test']]));
    }
}
