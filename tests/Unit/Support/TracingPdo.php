<?php

declare(strict_types=1);

namespace Luster\Tests\Unit\Support;

use PDO;
use PDOException;
use PDOStatement;

/**
 * In-memory SQLite PDO that records every statement it's given and can be told
 * to fail some of them, for testing what code reads and what it does when a
 * read fails.
 */
final class TracingPdo extends PDO
{
    /** @var list<string> Every statement prepared, queried or run, in order. */
    public array $statements = [];

    /** Regex: statements matching it throw instead of running. Null = none fail. */
    public ?string $failOn = null;

    /** How many more matching statements fail before they start working again. Null = every one. */
    public ?int $failTimes = null;

    /** Also fails a statement when this returns true; it gets the SQL. For "only the third read". */
    public ?\Closure $failWhen = null;

    /**
     * True: behave like a MyISAM table, where a transaction does nothing. Every
     * statement saves at once, and a rollback takes nothing back.
     */
    public bool $noTransactions = false;

    /**
     * SQL put back straight after the first rollback that follows a write to a member
     * table, for one table behaving like MyISAM while the rest behave like InnoDB: the
     * rollback undoes the queue, and this member change stays. A rollback before any
     * member write has nothing to keep. noTransactions is the same thing for every table.
     */
    public ?string $survivesRollback = null;

    /** True once a member table has been written to, so survivesRollback has something to keep. */
    private bool $memberWritten = false;

    /** True: the next commit() throws and commits nothing (the transaction stays open). */
    public bool $failCommit = false;

    /** True: rollBack() throws and takes nothing back, so the transaction stays open. */
    public bool $failRollBack = false;

    /**
     * Set on each simulated statement failure's errorInfo, the field a real
     * driver error fills (e.g. ['23000', 1062, 'Duplicate entry'] for MySQL's
     * duplicate key). Null leaves it unset.
     *
     * @var array{0: string, 1: int, 2: string}|null
     */
    public ?array $failErrorInfo = null;

    /**
     * Lock name => what GET_LOCK answers for it: 0 a timeout, null an error. Any
     * other name gets the lock.
     *
     * @var array<string, int|null>
     */
    public array $lockAnswers = [];

    /** True: behave like older MySQL, where taking a lock silently drops the last one. */
    public bool $oneLockAtATime = false;

    /** @var list<string> The named locks currently held. */
    public array $locksHeld = [];

    /**
     * Called with each statement just before it runs, to make something happen at an
     * exact point in the code being tested. Use quiet() for its own SQL.
     */
    public ?\Closure $beforeStatement = null;

    public static function memory(): self
    {
        $pdo = new self('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // MySQL's named locks, scripted through $lockAnswers and $oneLockAtATime.
        $pdo->sqliteCreateFunction('GET_LOCK', static function (string $name, $timeout) use ($pdo): ?int {
            if (array_key_exists($name, $pdo->lockAnswers)) {
                return $pdo->lockAnswers[$name];
            }
            $pdo->locksHeld = $pdo->oneLockAtATime ? [$name] : [...$pdo->locksHeld, $name];
            return 1;
        });
        $pdo->sqliteCreateFunction('RELEASE_LOCK', static function (string $name) use ($pdo): ?int {
            $at = array_search($name, $pdo->locksHeld, true);
            if ($at === false) {
                return null;
            }
            unset($pdo->locksHeld[$at]);
            $pdo->locksHeld = array_values($pdo->locksHeld);
            return 1;
        });
        $pdo->sqliteCreateFunction('IS_USED_LOCK', static fn(string $name): ?int => in_array($name, $pdo->locksHeld, true) ? 1 : null);
        $pdo->sqliteCreateFunction('CONNECTION_ID', static fn(): int => 1);

        return $pdo;
    }

    /** Runs SQL without recording it or triggering anything, for a hook's own changes. */
    public function quiet(string $sql): void
    {
        parent::exec($sql);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->trace($query);
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->trace($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->trace($statement);
        return parent::exec($statement);
    }

    public function beginTransaction(): bool
    {
        $this->statements[] = 'BEGIN';
        return $this->noTransactions ? true : parent::beginTransaction();
    }

    public function commit(): bool
    {
        if ($this->failCommit) {
            $this->failCommit = false;
            throw new PDOException('Simulated failure for: COMMIT');
        }
        $this->statements[] = 'COMMIT';
        return $this->noTransactions ? true : parent::commit();
    }

    public function rollBack(): bool
    {
        if ($this->failRollBack) {
            throw new PDOException('Simulated failure for: ROLLBACK');
        }
        $this->statements[] = 'ROLLBACK';
        $result = $this->noTransactions ? true : parent::rollBack();
        if ($this->survivesRollback !== null && $this->memberWritten) {
            parent::exec($this->survivesRollback);
            $this->survivesRollback = null;
        }
        return $result;
    }

    public function inTransaction(): bool
    {
        return $this->noTransactions ? false : parent::inTransaction();
    }

    /** Statements recorded so far that match $pattern. @return list<string> */
    public function statementsMatching(string $pattern): array
    {
        return array_values(array_filter($this->statements, static fn(string $sql): bool => preg_match($pattern, $sql) === 1));
    }

    private function trace(string $sql): void
    {
        $this->statements[] = $sql;
        if (preg_match('/^\s*UPDATE fl_/', $sql) === 1) {
            $this->memberWritten = true;
        }
        if ($this->beforeStatement !== null) {
            ($this->beforeStatement)($sql);
        }
        if ($this->failWhen !== null && ($this->failWhen)($sql) === true) {
            throw $this->failure($sql);
        }
        if ($this->failOn !== null && preg_match($this->failOn, $sql) === 1) {
            if ($this->failTimes !== null) {
                if ($this->failTimes <= 0) {
                    return;
                }
                $this->failTimes--;
            }
            throw $this->failure($sql);
        }
    }

    private function failure(string $sql): PDOException
    {
        $e = new PDOException('Simulated failure for: ' . $sql);
        if ($this->failErrorInfo !== null) {
            $e->errorInfo = $this->failErrorInfo;
        }
        return $e;
    }
}
