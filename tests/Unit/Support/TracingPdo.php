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

    /** Statements recorded so far that match $pattern. @return list<string> */
    public function statementsMatching(string $pattern): array
    {
        return array_values(array_filter($this->statements, static fn(string $sql): bool => preg_match($pattern, $sql) === 1));
    }

    private function trace(string $sql): void
    {
        $this->statements[] = $sql;
        if ($this->failOn !== null && preg_match($this->failOn, $sql) === 1) {
            throw new PDOException('Simulated failure for: ' . $sql);
        }
    }
}
