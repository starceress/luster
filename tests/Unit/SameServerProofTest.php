<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\EnthusiastImportSource;
use Luster\Import\ImportOptions;
use Luster\Import\SameServerProof;
use Luster\Tests\Unit\Support\EnthusiastImportFixture;
use Luster\Tests\Unit\Support\TracingPdo;
use PHPUnit\Framework\TestCase;

/**
 * Two connections only count as one server when a named lock taken on one is seen on
 * the other, with the owner's real connection id. Anything else means not proven.
 */
final class SameServerProofTest extends TestCase
{
    use EnthusiastImportFixture;

    /**
     * Two connections that behave like one server: the source really holds the lock,
     * and the target sees it as owned by $ownerSeen, or the source's id by default.
     *
     * @return array{0: TracingPdo, 1: TracingPdo}
     */
    private function connections(int|null|false $ownerSeen = false, int $sourceId = 77): array
    {
        $source = TracingPdo::memory();
        $target = TracingPdo::memory();
        $source->sqliteCreateFunction('CONNECTION_ID', static fn(): int => $sourceId);
        $target->sqliteCreateFunction('CONNECTION_ID', static fn(): int => 88);
        $target->sqliteCreateFunction('IS_USED_LOCK', static function (string $name) use ($source, $sourceId, $ownerSeen): ?int {
            if ($ownerSeen !== false) {
                return $ownerSeen;
            }
            return in_array($name, $source->locksHeld, true) ? $sourceId : null;
        });
        return [$source, $target];
    }

    public function testALockSeenWithTheOwnersRealIdIsProof(): void
    {
        [$source, $target] = $this->connections();

        self::assertTrue(SameServerProof::holds($source, $target));
        self::assertSame([], $source->locksHeld, 'released');
    }

    public function testTheLockIsTakenOnTheSourceConnectionAndAskedAboutOnTheTarget(): void
    {
        [$source, $target] = $this->connections();

        SameServerProof::holds($source, $target);

        self::assertCount(1, $source->statementsMatching('/GET_LOCK/'));
        self::assertSame([], $target->statementsMatching('/GET_LOCK/'));
        self::assertCount(1, $target->statementsMatching('/IS_USED_LOCK/'));
    }

    public function testTheLockNameIsUniqueAndFitsMySqlsLimit(): void
    {
        [$source, $target] = $this->connections();
        $names = [];
        $source->sqliteCreateFunction('GET_LOCK', static function (string $name) use (&$names, $source): int {
            $names[] = $name;
            $source->locksHeld[] = $name;
            return 1;
        });

        SameServerProof::holds($source, $target);
        SameServerProof::holds($source, $target);

        self::assertCount(2, $names);
        self::assertNotSame($names[0], $names[1]);
        self::assertLessThanOrEqual(64, strlen($names[0]));
    }

    /** @return array<string, array{0: int|null}> */
    public static function ownersThatProveNothing(): array
    {
        return [
            'nobody has it, so another server' => [null],
            'someone else has it'              => [55],
            'a literal 1 is not a yes'         => [1],
        ];
    }

    /** @dataProvider ownersThatProveNothing */
    public function testAnyOtherOwnerIsNotProof(?int $owner): void
    {
        [$source, $target] = $this->connections($owner);

        self::assertFalse(SameServerProof::holds($source, $target));
        self::assertSame([], $source->locksHeld, 'released anyway');
    }

    public function testALiteralOneIsProofOnlyWhenThatIsTheOwnersRealId(): void
    {
        [$source, $target] = $this->connections(1, 1);

        self::assertTrue(SameServerProof::holds($source, $target));
    }

    /** @return array<string, array{0: int|null}> */
    public static function failedAcquires(): array
    {
        return ['timed out' => [0], 'an error' => [null]];
    }

    /** @dataProvider failedAcquires */
    public function testNotGettingTheLockIsNotProof(?int $answer): void
    {
        [$source, $target] = $this->connections();
        $source->sqliteCreateFunction('GET_LOCK', static fn(string $name): ?int => $answer);

        self::assertFalse(SameServerProof::holds($source, $target));
        self::assertSame([], $target->statementsMatching('/IS_USED_LOCK/'));
    }

    /** @return array<string, array{0: 'source'|'target', 1: string}> */
    public static function failingStatements(): array
    {
        return [
            'the id read'  => ['source', '/CONNECTION_ID/'],
            'taking it'    => ['source', '/GET_LOCK/'],
            'asking'       => ['target', '/IS_USED_LOCK/'],
        ];
    }

    /** @dataProvider failingStatements */
    public function testAFailedStepIsNotProofAndNeverThrows(string $side, string $pattern): void
    {
        [$source, $target] = $this->connections();
        $failing = $side === 'source' ? $source : $target;
        $failing->failOn = $pattern;

        self::assertFalse(SameServerProof::holds($source, $target));
        self::assertSame([], $source->locksHeld);
    }

    public function testAFailedReleaseDoesNotChangeTheAnswer(): void
    {
        [$source, $target] = $this->connections();
        $source->failOn = '/RELEASE_LOCK/';

        self::assertTrue(SameServerProof::holds($source, $target));
        self::assertCount(1, $source->statementsMatching('/RELEASE_LOCK/'), 'it did try');
    }

    public function testADatabaseWithNoLocksIsNotProof(): void
    {
        $plain = new \PDO('sqlite::memory:');
        $plain->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        self::assertFalse(SameServerProof::holds($plain, TracingPdo::memory()));
    }

    // -----------------------------------------------------------------------
    // Through the importer
    // -----------------------------------------------------------------------

    /** @return array{0: TracingPdo, 1: TracingPdo} A second connection to one shared database, or looking like one. */
    private function twoConnectionsNamed(bool $sameServer): array
    {
        [$source, $target] = $this->connections($sameServer ? false : null);
        $this->connectedAs($source, 'enth');
        $this->connectedAs($target, 'enth');
        return [$source, $target];
    }

    public function testTheSameDatabaseNameOnTwoServersStillCopiesMembers(): void
    {
        [$source, $target] = $this->twoConnectionsNamed(false);
        $source->exec("CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT, parent INTEGER DEFAULT 0)");
        $source->exec(self::ownedSourceDdl());
        $this->addSourceListing($source, 1, 'moon', 'http://a.test/moon');
        $this->addFan($source, 'moon', ['email' => 'one@example.test']);
        $target->exec(self::ownedSourceDdl());
        $target->exec("INSERT INTO owned (listingid, dbtable, url) VALUES (1, 'moon', 'http://a.test/moon')");
        $this->createTargetMemberTable($target, 'moon');

        $result = (new EnthusiastImportSource())->run($source, $target, ImportOptions::fromPost([
            'source' => 'enthusiast', 'conflict_strategy' => 'skip', 'import_members' => '1', '_source_host' => 'localhost',
        ]));

        self::assertSame(1, $this->row($result, 'Members')['imported']);
        self::assertSame(1, $this->rowCount($target, 'moon'));
    }

    public function testTwoConnectionsToOneServerAreTheSameRegistry(): void
    {
        [$source, $target] = $this->twoConnectionsNamed(true);
        foreach ([$source, $target] as $pdo) {
            $pdo->exec("CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT, parent INTEGER DEFAULT 0)");
            $pdo->exec(self::ownedSourceDdl());
            $pdo->exec("INSERT INTO owned (listingid, dbtable, url, title) VALUES (4, 'moon', '', 'Moon')");
        }
        $this->createSourceMemberTable($source, 'moon');
        $this->addFan($source, 'moon', ['email' => 'one@example.test']);
        $this->createSourceMemberTable($target, 'moon');

        $result = (new EnthusiastImportSource())->run($source, $target, ImportOptions::fromPost([
            'source' => 'enthusiast', 'conflict_strategy' => 'skip', 'import_members' => '1', '_source_host' => 'localhost',
        ]));

        self::assertSame(0, $this->row($result, 'Members')['imported']);
        self::assertSame(0, $this->rowCount($target, 'moon'), 'nothing copied: it is the same table');
        self::assertStringContainsString('keep this database', $this->allText($result));
    }

    public function testADifferentDatabaseNameNeverTakesALock(): void
    {
        [$source, $target] = $this->connections();
        $this->connectedAs($source, 'one');
        $this->connectedAs($target, 'two');
        $source->exec("CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT, parent INTEGER DEFAULT 0)");
        $source->exec(self::ownedSourceDdl());
        $target->exec(self::ownedSourceDdl());

        (new EnthusiastImportSource())->run($source, $target, ImportOptions::fromPost([
            'source' => 'enthusiast', 'conflict_strategy' => 'skip', 'import_members' => '1', '_source_host' => 'localhost',
        ]));

        self::assertSame([], $source->statementsMatching('/GET_LOCK/'));
    }
}
