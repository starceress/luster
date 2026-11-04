<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Lazy PDO wrapper that only opens a database connection on the first actual
 query, so constructing it in tests or bootstrap code costs nothing. All
 database access goes through this class, and all queries must use prepared
 statements. The only interpolation ever allowed in SQL is a validated table
 prefix, checked by validatePrefix() before use.
*/

declare(strict_types=1);

namespace Luster\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Lazy PDO wrapper.
 *
 * The actual database connection is established on the first call to pdo()
 * or prepare(), so test code can construct this class without a live database.
 *
 * All queries MUST use prepared statements. The only interpolation ever
 * permitted in SQL is a validated table prefix ($prefix from enth_fanlistings).
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config) {}

    /**
     * Returns the raw PDO instance, connecting on first call.
     */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }

        return $this->pdo; // @phpstan-ignore-line (assigned by connect())
    }

    /**
     * Prepares an SQL statement and returns the PDOStatement.
     *
     * Usage:
     *   $stmt = $db->prepare("SELECT * FROM {$prefix}members WHERE email = :email");
     *   $stmt->execute(['email' => $email]);
     */
    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo()->prepare($sql);
    }

    /**
     * Validates a table prefix before it is interpolated into SQL.
     *
     * Only alphanumeric characters and underscores are allowed.
     * Throws if the prefix is invalid to prevent SQL injection.
     *
     * @throws RuntimeException
     */
    public function validatePrefix(string $prefix): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new RuntimeException(
                "Invalid table prefix: '{$prefix}'. Only [a-zA-Z0-9_] allowed."
            );
        }

        return $prefix;
    }

    /**
     * The columns of a table, each with its type and its text limits (null when
     * the type has none). A failed read throws, and so does a table with no
     * columns: INFORMATION_SCHEMA answers a missing table with no rows, and
     * "couldn't read" must never look like "that column isn't there". SQLite
     * (the tests) has no INFORMATION_SCHEMA, so it reads PRAGMA table_info.
     *
     * @return list<array{name: string, type: string, chars: ?int, bytes: ?int}>
     * @throws RuntimeException
     */
    public function tableColumns(string $table): array
    {
        $this->validatePrefix($table);

        $pdo = $this->pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $columns = self::sqliteColumns($pdo, $table);
        } else {
            $stmt = $pdo->prepare(
                'SELECT COLUMN_NAME AS name, DATA_TYPE AS type,
                        CHARACTER_MAXIMUM_LENGTH AS chars, CHARACTER_OCTET_LENGTH AS bytes
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl
                 ORDER BY ORDINAL_POSITION'
            );
            $stmt->execute(['tbl' => $table]);

            $columns = array_map(static fn(array $row): array => [
                'name'  => (string) $row['name'],
                'type'  => strtolower((string) $row['type']),
                'chars' => $row['chars'] !== null ? (int) $row['chars'] : null,
                'bytes' => $row['bytes'] !== null ? (int) $row['bytes'] : null,
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if ($columns === []) {
            throw new RuntimeException("Couldn't find any columns for table '{$table}'.");
        }

        return $columns;
    }

    /**
     * tableColumns() on SQLite: a declared VARCHAR(64) or CHAR(64) gives a
     * character limit, and every other type has none.
     *
     * @return list<array{name: string, type: string, chars: ?int, bytes: ?int}>
     */
    private static function sqliteColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");
        if ($stmt === false) {
            throw new RuntimeException("Couldn't read the columns of table '{$table}'.");
        }

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $declared  = strtolower((string) $row['type']);
            $columns[] = [
                'name'  => (string) $row['name'],
                'type'  => (string) preg_replace('/\s*\(.*$/', '', $declared),
                'chars' => preg_match('/^(?:var)?char\s*\((\d+)\)/', $declared, $width) === 1 ? (int) $width[1] : null,
                'bytes' => null,
            ];
        }

        return $columns;
    }

    /**
     * Returns the last inserted row ID.
     */
    public function lastInsertId(): string
    {
        return $this->pdo()->lastInsertId();
    }

    /**
     * Begins a transaction.
     */
    public function beginTransaction(): bool
    {
        return $this->pdo()->beginTransaction();
    }

    /** Commits the active transaction. */
    public function commit(): bool
    {
        return $this->pdo()->commit();
    }

    /** Rolls back the active transaction. */
    public function rollBack(): bool
    {
        return $this->pdo()->rollBack();
    }

    // -----------------------------------------------------------------------

    private function connect(): void
    {
        $host    = $this->config->get('db_host', 'localhost');
        $port    = (int) $this->config->get('db_port', 3306);
        $name    = $this->config->require('db_name');
        $user    = $this->config->require('db_user');
        $pass    = $this->config->get('db_pass', '');
        $charset = $this->config->get('db_charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        try {
            $this->pdo = new PDO($dsn, (string) $user, (string) $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // Do not expose credentials in the message
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
