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

    public static function memory(): self
    {
        $pdo = new self('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
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
        return $this->noTransactions ? true : parent::beginTransaction();
    }

    public function commit(): bool
    {
        if ($this->failCommit) {
            $this->failCommit = false;
            throw new PDOException('Simulated failure for: COMMIT');
        }
        return $this->noTransactions ? true : parent::commit();
    }

    public function rollBack(): bool
    {
        if ($this->failRollBack) {
            throw new PDOException('Simulated failure for: ROLLBACK');
        }
        return $this->noTransactions ? true : parent::rollBack();
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
