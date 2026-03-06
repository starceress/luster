<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Models\Fanlisting;
use RuntimeException;

/**
 * Data access for the fanlisting registry table.
 *
 * The exact table name and column layout are determined by the
 * 'enth_table_prefix' config key:
 *
 *   ''       → table 'owned'  (Enthusiast 3 unprefixed install)
 *              PK column:   listingid
 *              Status col:  status (int: 2=active, 1=upcoming, 0=inactive)
 *
 *   'enth_'  → table 'enth_fanlistings'  (standard prefixed install)
 *              PK column:   id
 *              Status cols: upcoming (bool), trouble (bool)
 *
 * The Fanlisting model's fromRow() transparently handles both layouts.
 */
final class FanlistingRepo
{
    private string $table;
    private string $pkCol;
    private bool   $isOwnedSchema;

    public function __construct(
        private readonly Database $db,
        private readonly Config   $config,
    ) {
        $prefix = (string) $this->config->get('enth_table_prefix', '');

        if ($prefix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new RuntimeException(
                "Invalid enth_table_prefix '{$prefix}': only [a-zA-Z0-9_] allowed."
            );
        }

        $this->isOwnedSchema = ($prefix === '');

        // Build and validate the table name
        $rawTable    = $this->isOwnedSchema ? 'owned' : $prefix . 'fanlistings';
        $this->table = $this->db->validatePrefix($rawTable);
        $this->pkCol = $this->isOwnedSchema ? 'listingid' : 'id';
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns every fanlisting ordered by subject/name.
     *
     * @return Fanlisting[]
     */
    public function getAll(): array
    {
        $orderCol = $this->isOwnedSchema ? 'subject' : 'name';

        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} ORDER BY {$orderCol} ASC"
        );
        $stmt->execute();

        return array_map(
            static fn(array $row) => Fanlisting::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns a single fanlisting by primary key, or null if not found.
     */
    public function getById(int $id): ?Fanlisting
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE {$this->pkCol} = :id"
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row !== false ? Fanlisting::fromRow($row) : null;
    }

    /**
     * Returns aggregate status counts across all fanlistings.
     *
     * @return array{total: int, upcoming: int, trouble: int}
     */
    public function getStats(): array
    {
        if ($this->isOwnedSchema) {
            // 'owned' table: status int (2=active, 1=upcoming, 0=inactive)
            $stmt = $this->db->prepare(
                "SELECT
                    COUNT(*)          AS total,
                    SUM(status = 1)   AS upcoming,
                    SUM(status = 0)   AS trouble
                 FROM {$this->table}"
            );
        } else {
            // Standard enth_fanlistings: separate bool cols
            $stmt = $this->db->prepare(
                "SELECT
                    COUNT(*)          AS total,
                    SUM(upcoming = 1) AS upcoming,
                    SUM(trouble  = 1) AS trouble
                 FROM {$this->table}"
            );
        }

        $stmt->execute();
        $row = $stmt->fetch();

        return [
            'total'    => (int) ($row['total']    ?? 0),
            'upcoming' => (int) ($row['upcoming'] ?? 0),
            'trouble'  => (int) ($row['trouble']  ?? 0),
        ];
    }

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Updates the user-editable metadata on a fanlisting.
     *
     * Only touches columns that Luster is allowed to change (title/name, url,
     * email, status). Subject, dbtable, catid, and listingtype are managed by
     * Enthusiast and must not be overwritten.
     */
    public function updateMeta(
        int    $id,
        string $title,
        string $url,
        string $email,
        bool   $upcoming,
        bool   $trouble,
    ): bool {
        if ($this->isOwnedSchema) {
            $status = $upcoming ? 1 : ($trouble ? 0 : 2);

            $stmt = $this->db->prepare(
                "UPDATE {$this->table}
                 SET title = :title, url = :url, email = :email, status = :status
                 WHERE {$this->pkCol} = :id"
            );

            return $stmt->execute([
                'title'  => $title,
                'url'    => $url,
                'email'  => $email,
                'status' => $status,
                'id'     => $id,
            ]);
        }

        $stmt = $this->db->prepare(
            "UPDATE {$this->table}
             SET name = :name, url = :url, email = :email,
                 upcoming = :upcoming, trouble = :trouble
             WHERE {$this->pkCol} = :id"
        );

        return $stmt->execute([
            'name'     => $title,
            'url'      => $url,
            'email'    => $email,
            'upcoming' => (int) $upcoming,
            'trouble'  => (int) $trouble,
            'id'       => $id,
        ]);
    }

    /**
     * Updates the Luster category assignment for a fanlisting.
     *
     * Writes to the category_id column added by Migration 8.
     * Pass null to clear the assignment.
     */
    public function update(int $id, ?int $categoryId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE {$this->table}
             SET category_id = :category_id
             WHERE {$this->pkCol} = :id"
        );
        $stmt->execute([
            'category_id' => $categoryId,
            'id'          => $id,
        ]);
    }

    /**
     * Deletes a fanlisting record from the registry table.
     *
     * Note: this removes only the registry row. The member table referenced
     * by dbtable/dbprefix is left intact and must be dropped separately if
     * desired. Enthusiast manages member table lifecycle.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM {$this->table} WHERE {$this->pkCol} = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Renames the member table and updates the registry row to match.
     *
     * Validates $newPrefix before touching anything. If the registry UPDATE
     * fails after the RENAME, attempts to RENAME the table back before
     * re-throwing the original exception.
     *
     * @throws \InvalidArgumentException if $newPrefix contains invalid characters
     * @throws \PDOException             on database failure
     */
    public function updateDbPrefix(int $id, string $oldPrefix, string $newPrefix): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $newPrefix)) {
            throw new \InvalidArgumentException(
                "Invalid dbprefix '{$newPrefix}': only [a-zA-Z0-9_] allowed."
            );
        }

        $validatedOld = $this->db->validatePrefix($oldPrefix);
        $validatedNew = $this->db->validatePrefix($newPrefix);

        // Step 1: rename the member table
        $this->db->pdo()->exec("RENAME TABLE `{$validatedOld}` TO `{$validatedNew}`");

        // Step 2: update the registry row — roll back the rename on failure
        $dbCol = $this->isOwnedSchema ? 'dbtable' : 'dbprefix';

        try {
            $stmt = $this->db->prepare(
                "UPDATE {$this->table} SET `{$dbCol}` = :new WHERE {$this->pkCol} = :id"
            );
            $stmt->execute(['new' => $newPrefix, 'id' => $id]);
        } catch (\PDOException $e) {
            try {
                $this->db->pdo()->exec("RENAME TABLE `{$validatedNew}` TO `{$validatedOld}`");
            } catch (\PDOException) {
                // Rollback failed — swallow and fall through to re-throw original
            }
            throw $e;
        }
    }

    /**
     * Updates the status flags for a fanlisting.
     *
     * Standard schema: sets the upcoming and trouble boolean columns.
     * Owned schema: maps the booleans back to the status int
     *   (upcoming=true → 1, trouble=true → 0, neither → 2).
     */
    public function updateStatus(int $id, bool $upcoming, bool $trouble): bool
    {
        if ($this->isOwnedSchema) {
            $status = $upcoming ? 1 : ($trouble ? 0 : 2);

            $stmt = $this->db->prepare(
                "UPDATE {$this->table} SET status = :status WHERE {$this->pkCol} = :id"
            );

            return $stmt->execute(['status' => $status, 'id' => $id]);
        }

        $stmt = $this->db->prepare(
            "UPDATE {$this->table}
             SET upcoming = :upcoming, trouble = :trouble
             WHERE {$this->pkCol} = :id"
        );

        return $stmt->execute([
            'upcoming' => (int) $upcoming,
            'trouble'  => (int) $trouble,
            'id'       => $id,
        ]);
    }
}
