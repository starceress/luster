<?php

declare(strict_types=1);

namespace Luster\Addons\Kim;

use Luster\Core\Database;

/**
 * Data access for the lstr_kim_members table.
 *
 * All queries use prepared statements. The table stores one row per
 * (email, fanlisting_id) pair — the unique key prevents duplicates.
 */
final class KimMemberRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Inserts a new KIM member (always pending).
     *
     * @return int  The new row's auto-increment ID.
     */
    public function create(
        int    $fanlistingId,
        string $name,
        string $email,
        string $url,
        string $passwordHash,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_kim_members
                (fanlisting_id, name, email, url, password, pending)
             VALUES
                (:fanlisting_id, :name, :email, :url, :password, 1)'
        );

        $stmt->execute([
            'fanlisting_id' => $fanlistingId,
            'name'          => $name,
            'email'         => $email,
            'url'           => $url,
            'password'      => $passwordHash,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Approves a pending KIM member.
     */
    public function approve(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE lstr_kim_members SET pending = 0 WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Updates allowed columns on a KIM member row.
     *
     * @param array<string,mixed> $fields  Allowed keys: name, email, url, password, fanlisting_id
     */
    public function update(int $id, array $fields): void
    {
        $allowed = ['name', 'email', 'url', 'password', 'fanlisting_id'];
        $setClauses = [];
        $params = ['id' => $id];

        foreach ($fields as $col => $value) {
            if (!in_array($col, $allowed, true)) {
                continue;
            }
            $setClauses[] = "`{$col}` = :{$col}";
            $params[$col] = $value;
        }

        if (empty($setClauses)) {
            return;
        }

        $sql = 'UPDATE lstr_kim_members SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Deletes a KIM member by ID. Cascade deletes reset tokens via FK.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM lstr_kim_members WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Approves multiple KIM members by ID.
     *
     * @param int[] $ids
     */
    public function approveByIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->prepare(
            "UPDATE lstr_kim_members SET pending = 0 WHERE id IN ({$placeholders})"
        )->execute(array_values($ids));
    }

    /**
     * Deletes multiple KIM members by ID. Cascade deletes reset tokens via FK.
     *
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->prepare(
            "DELETE FROM lstr_kim_members WHERE id IN ({$placeholders})"
        )->execute(array_values($ids));
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Finds a KIM member by email + fanlisting combination.
     */
    public function findByEmailAndFanlisting(string $email, int $fanlistingId): ?KimMember
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_kim_members
             WHERE email = :email AND fanlisting_id = :fanlisting_id
             LIMIT 1'
        );
        $stmt->execute(['email' => $email, 'fanlisting_id' => $fanlistingId]);
        $row = $stmt->fetch();

        return $row !== false ? KimMember::fromRow($row) : null;
    }

    /**
     * Finds a KIM member by primary key.
     */
    public function findById(int $id): ?KimMember
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_kim_members WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row !== false ? KimMember::fromRow($row) : null;
    }

    /**
     * Returns all approved (non-pending) KIM members for a fanlisting.
     *
     * @return KimMember[]
     */
    public function getApprovedByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id AND pending = 0
             ORDER BY name ASC'
        );
        $stmt->execute(['fanlisting_id' => $fanlistingId]);

        return array_map(
            static fn(array $row) => KimMember::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns all pending members for a fanlisting (unpaginated — pending lists are small).
     *
     * @return KimMember[]
     */
    public function getPendingByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id AND pending = 1
             ORDER BY added_at ASC'
        );
        $stmt->execute(['fanlisting_id' => $fanlistingId]);

        return array_map(
            static fn(array $row) => KimMember::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns a page of approved members for a fanlisting.
     *
     * @return KimMember[]
     */
    public function getApprovedByFanlistingPaged(int $fanlistingId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(
            'SELECT * FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id AND pending = 0
             ORDER BY name ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('fanlisting_id', $fanlistingId, \PDO::PARAM_INT);
        $stmt->bindValue('limit',         $perPage,      \PDO::PARAM_INT);
        $stmt->bindValue('offset',        $offset,       \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn(array $row) => KimMember::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns all KIM members for a fanlisting — pending first, then approved.
     *
     * @return KimMember[]
     */
    public function getAllByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id
             ORDER BY pending DESC, name ASC'
        );
        $stmt->execute(['fanlisting_id' => $fanlistingId]);

        return array_map(
            static fn(array $row) => KimMember::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Returns pending counts grouped by fanlisting_id.
     *
     * @return array<int, int>  Map of fanlisting_id => pending count
     */
    public function getPendingCounts(): array
    {
        $stmt = $this->db->prepare(
            'SELECT fanlisting_id, COUNT(*) AS cnt
             FROM lstr_kim_members
             WHERE pending = 1
             GROUP BY fanlisting_id'
        );
        $stmt->execute();

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['fanlisting_id']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * Counts approved members for a given fanlisting.
     */
    public function countApprovedByFanlisting(int $fanlistingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id AND pending = 0'
        );
        $stmt->execute(['fanlisting_id' => $fanlistingId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns name + email for a specific set of member IDs.
     * Used for bulk email-selected.
     *
     * @param  int[]  $ids
     * @return array<int, array{name: string, email: string}>
     */
    public function getEmailsByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT name, email FROM lstr_kim_members WHERE id IN ({$placeholders}) ORDER BY name ASC"
        );
        $stmt->execute(array_values($ids));

        return array_map(
            static fn(array $row) => ['name' => (string) $row['name'], 'email' => (string) $row['email']],
            $stmt->fetchAll()
        );
    }

    /**
     * Returns name + email for approved members of a fanlisting.
     * Used for bulk email.
     *
     * @return array<int, array{name: string, email: string}>
     */
    public function getEmailsByFanlisting(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT name, email FROM lstr_kim_members
             WHERE fanlisting_id = :fanlisting_id AND pending = 0
             ORDER BY name ASC'
        );
        $stmt->execute(['fanlisting_id' => $fanlistingId]);

        return array_map(
            static fn(array $row) => ['name' => (string) $row['name'], 'email' => (string) $row['email']],
            $stmt->fetchAll()
        );
    }
}
