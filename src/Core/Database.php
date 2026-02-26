<?php

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

    public function commit(): bool
    {
        return $this->pdo()->commit();
    }

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
