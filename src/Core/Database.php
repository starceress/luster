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

use DateTimeImmutable;
use DateTimeZone;
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
     * The columns of a table, each with its type, its text limits, its character set and
     * the most bytes that set can use for one character (null when the type has none).
     * A failed read throws, and so does a table with no columns: INFORMATION_SCHEMA
     * answers a missing table with no rows, and "couldn't read" must never look like
     * "that column isn't there". SQLite (the tests) has no INFORMATION_SCHEMA, so it
     * reads PRAGMA table_info.
     *
     * @return list<array{name: string, type: string, chars: ?int, bytes: ?int, charset: ?string, charset_bytes: ?int}>
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
                'SELECT c.COLUMN_NAME AS name, c.DATA_TYPE AS type,
                        c.CHARACTER_MAXIMUM_LENGTH AS chars, c.CHARACTER_OCTET_LENGTH AS bytes,
                        c.CHARACTER_SET_NAME AS charset, s.MAXLEN AS charset_bytes
                 FROM INFORMATION_SCHEMA.COLUMNS c
                 LEFT JOIN INFORMATION_SCHEMA.CHARACTER_SETS s ON s.CHARACTER_SET_NAME = c.CHARACTER_SET_NAME
                 WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = :tbl
                 ORDER BY c.ORDINAL_POSITION'
            );
            $stmt->execute(['tbl' => $table]);

            $columns = array_map(static fn(array $row): array => [
                'name'          => (string) $row['name'],
                'type'          => strtolower((string) $row['type']),
                'chars'         => $row['chars'] !== null ? (int) $row['chars'] : null,
                'bytes'         => $row['bytes'] !== null ? (int) $row['bytes'] : null,
                'charset'       => $row['charset'] !== null ? strtolower((string) $row['charset']) : null,
                'charset_bytes' => $row['charset_bytes'] !== null ? (int) $row['charset_bytes'] : null,
            ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if ($columns === []) {
            throw new RuntimeException("Couldn't find any columns for table '{$table}'.");
        }

        return $columns;
    }

    /**
     * tableColumns() on SQLite: a declared VARCHAR(64) or CHAR(64) gives a
     * character limit, and every other type has none. Everything is UTF-8 there.
     *
     * @return list<array{name: string, type: string, chars: ?int, bytes: ?int, charset: ?string, charset_bytes: ?int}>
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
                'charset'       => null,
                'charset_bytes' => null,
            ];
        }

        return $columns;
    }

    /** True on SQLite (the tests), for the few statements it needs written differently. */
    public function isSqlite(): bool
    {
        return $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    /**
     * " FOR UPDATE" for a locking read. Empty on SQLite (the tests), which has
     * no such thing.
     */
    public function forUpdate(): string
    {
        return $this->isSqlite() ? '' : ' FOR UPDATE';
    }

    /**
     * When a token that lasts this many seconds should expire, for an expires_at
     * column. Those columns are checked against NOW() in SQL, so the expiry has to
     * come from the database's clock too. PHP's can be hours away from it, because
     * the two get their zones from different places.
     *
     * The adding is done in UTC only so PHP's daylight saving can't shift the answer.
     * The result is still the database's own wall-clock time, never converted. If NOW()
     * can't be read or understood this throws, because falling back to PHP's clock
     * would bring the problem straight back.
     *
     * @throws RuntimeException A failed read throws PDOException, which is one of these.
     */
    public function expiresIn(int $seconds): string
    {
        return $this->now()->modify($seconds . ' seconds')->format('Y-m-d H:i:s');
    }

    /**
     * What the database's clock says right now, for working out times that get stored
     * and compared later. It's the same clock for every request, while PHP's zone
     * depends on how the request came in: embeds never apply the Timezone setting.
     *
     * It's marked UTC only so adding to it and comparing it is plain arithmetic. It's
     * still the database's wall-clock time, so don't convert it or show it to anyone.
     * Compare it with storedTime(), which reads stored values the same way.
     *
     * @throws RuntimeException A failed read throws PDOException, which is one of these.
     */
    public function now(): DateTimeImmutable
    {
        $stmt = $this->prepare('SELECT NOW()');
        $stmt->execute();
        $now = $stmt->fetchColumn();

        $parsed = is_string($now)
            ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $now, new DateTimeZone('UTC'))
            : false;

        // createFromFormat() turns month 13 into next January without complaining,
        // so a value only counts if it reads back exactly the same.
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $now) {
            throw new RuntimeException("Couldn't read the database's clock.");
        }

        return $parsed;
    }

    /** Reads a time that was stored from now(), so the two can be compared. */
    public function storedTime(string $stored): DateTimeImmutable
    {
        return new DateTimeImmutable($stored, new DateTimeZone('UTC'));
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
