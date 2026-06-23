<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Provides read and write access to the lstr_error_log table, which stores
 application error entries with a severity level, message, and optional
 source identifier. Supports paginated retrieval for the admin error log
 viewer and a flush operation to clear all entries at once.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_error_log table.
 *
 * Requires lstr_error_log to exist — run public/migrate.php (Migration 14) first.
 */
final class ErrorLogRepo
{
    public function __construct(private readonly Database $db) {}

    /**
     * Returns a paginated slice of error log rows, newest first.
     *
     * @return array{rows: array<int,array<string,mixed>>, total: int, pages: int}
     */
    public function getPaginated(int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM `lstr_error_log`');
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT id, level, message, source, created_at
             FROM `lstr_error_log`
             ORDER BY created_at DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,  \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'rows'  => $stmt->fetchAll(),
            'total' => $total,
            'pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Returns the total number of rows in lstr_error_log.
     */
    public function count(): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM `lstr_error_log`');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns all error log rows, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, level, message, source, created_at
             FROM `lstr_error_log`
             ORDER BY created_at DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Deletes all rows from lstr_error_log.
     */
    public function flush(): void
    {
        $this->db->prepare('DELETE FROM `lstr_error_log`')->execute();
    }

    /**
     * Inserts a new error log entry.
     */
    public function log(string $message, string $level = 'error', ?string $source = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `lstr_error_log` (level, message, source)
             VALUES (:level, :message, :source)'
        );
        $stmt->execute([
            'level'   => $level,
            'message' => $message,
            'source'  => $source,
        ]);
    }
}
