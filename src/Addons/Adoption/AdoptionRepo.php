<?php

declare(strict_types=1);

namespace Luster\Addons\Adoption;

use Luster\Core\Database;

/**
 * Data access for lstr_adoption_applications.
 *
 * Created by AdoptionAddon::install().
 */
final class AdoptionRepo
{
    private const TABLE = 'lstr_adoption_applications';

    public function __construct(private readonly Database $db) {}

    /**
     * Returns all applications for a listing, optionally filtered by status.
     * Pass 'all' for $status to return every application regardless of status.
     *
     * @return array[]
     */
    public function getForListing(int $listingId, string $status = 'all'): array
    {
        if ($status === 'all') {
            $stmt = $this->db->prepare(
                'SELECT * FROM `' . self::TABLE . '`
                  WHERE fanlisting_id = :lid
                  ORDER BY applied_at DESC'
            );
            $stmt->execute(['lid' => $listingId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM `' . self::TABLE . '`
                  WHERE fanlisting_id = :lid AND status = :status
                  ORDER BY applied_at DESC'
            );
            $stmt->execute(['lid' => $listingId, 'status' => $status]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Returns a single application by ID, or null if not found.
     *
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `' . self::TABLE . '` WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Returns the number of applications for a listing with the given status.
     * Pass 'all' to count all regardless of status.
     */
    public function countForListing(int $listingId, string $status = 'pending'): int
    {
        if ($status === 'all') {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM `' . self::TABLE . '` WHERE fanlisting_id = :lid'
            );
            $stmt->execute(['lid' => $listingId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM `' . self::TABLE . '`
                  WHERE fanlisting_id = :lid AND status = :status'
            );
            $stmt->execute(['lid' => $listingId, 'status' => $status]);
        }
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns the total count of pending applications across all listings.
     * Used for the admin nav badge. Returns 0 safely if the table does not exist.
     */
    public function countNew(): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM `" . self::TABLE . "` WHERE status = 'pending'"
            );
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * Returns applications filtered by status, listing, and search term.
     * Pass 'all' for $status to skip status filtering.
     * Pass 0 for $listingId to include all listings.
     *
     * @return array[]
     */
    public function query(string $status, int $listingId = 0, string $search = ''): array
    {
        $conditions = [];
        $params     = [];

        if ($status === 'pending') {
            // Match both canonical 'pending' and legacy 'new' rows
            $conditions[] = "status IN ('pending', 'new')";
        } elseif ($status !== 'all') {
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($listingId > 0) {
            $conditions[] = 'fanlisting_id = :lid';
            $params['lid'] = $listingId;
        }
        if ($search !== '') {
            $conditions[] = '(name LIKE :search_n OR email LIKE :search_e)';
            $params['search_n'] = '%' . $search . '%';
            $params['search_e'] = '%' . $search . '%';
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $stmt  = $this->db->prepare(
            'SELECT * FROM `' . self::TABLE . "` {$where} ORDER BY applied_at DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Returns per-status counts, optionally scoped to a listing and/or search.
     *
     * @return array{all:int, pending:int, reviewed:int, approved:int, rejected:int, granted:int}
     */
    public function queryCounts(int $listingId = 0, string $search = ''): array
    {
        $conditions = [];
        $params     = [];

        if ($listingId > 0) {
            $conditions[] = 'fanlisting_id = :lid';
            $params['lid'] = $listingId;
        }
        if ($search !== '') {
            $conditions[] = '(name LIKE :search_n OR email LIKE :search_e)';
            $params['search_n'] = '%' . $search . '%';
            $params['search_e'] = '%' . $search . '%';
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $stmt  = $this->db->prepare(
            "SELECT
                COUNT(*)                         AS total,
                SUM(status IN ('pending','new')) AS cnt_pending,
                SUM(status = 'reviewed')         AS cnt_reviewed,
                SUM(status = 'approved')         AS cnt_approved,
                SUM(status = 'rejected')         AS cnt_rejected,
                SUM(status = 'granted')          AS cnt_granted
             FROM `" . self::TABLE . "` {$where}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'all'      => (int) ($row['total']       ?? 0),
            'pending'  => (int) ($row['cnt_pending']  ?? 0),
            'reviewed' => (int) ($row['cnt_reviewed'] ?? 0),
            'approved' => (int) ($row['cnt_approved'] ?? 0),
            'rejected' => (int) ($row['cnt_rejected'] ?? 0),
            'granted'  => (int) ($row['cnt_granted']  ?? 0),
        ];
    }

    /**
     * Returns all applications across all listings, optionally filtered by status.
     *
     * @return array[]
     */
    public function getForAllListings(string $status = 'pending'): array
    {
        if ($status === 'all') {
            $stmt = $this->db->prepare(
                'SELECT * FROM `' . self::TABLE . '` ORDER BY applied_at DESC'
            );
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM `' . self::TABLE . '`
                  WHERE status = :status ORDER BY applied_at DESC'
            );
            $stmt->execute(['status' => $status]);
        }
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Inserts a new application. $data keys: name, email, url, message.
     * Returns the new row ID.
     */
    public function insert(int $listingId, array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO `' . self::TABLE . '`
                (fanlisting_id, name, email, url, message)
             VALUES
                (:lid, :name, :email, :url, :message)'
        );
        $stmt->execute([
            'lid'     => $listingId,
            'name'    => (string) ($data['name']    ?? ''),
            'email'   => strtolower(trim((string) ($data['email'] ?? ''))),
            'url'     => (string) ($data['url']     ?? ''),
            'message' => ($data['message'] ?? '') !== '' ? (string) $data['message'] : null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Returns true if a non-rejected application already exists for this
     * email+listing combination. Used to prevent duplicate submissions.
     */
    public function hasPendingApplication(int $listingId, string $email): bool
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM `" . self::TABLE . "`
              WHERE fanlisting_id = :lid
                AND email = :email
                AND status != 'rejected'"
        );
        $stmt->execute(['lid' => $listingId, 'email' => strtolower(trim($email))]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Updates editable fields, status, and admin notes for an application.
     * Falls back gracefully if admin_notes column has not yet been added.
     */
    public function update(int $id, string $name, string $email, string $url, string $message, string $status, string $adminNotes = ''): void
    {
        if (!in_array($status, ['pending', 'reviewed', 'approved', 'rejected', 'granted'], true)) {
            $status = 'pending';
        }
        $baseParams = [
            'name'    => $name,
            'email'   => strtolower(trim($email)),
            'url'     => $url,
            'message' => $message !== '' ? $message : null,
            'status'  => $status,
            'id'      => $id,
        ];
        try {
            $stmt = $this->db->prepare(
                'UPDATE `' . self::TABLE . '`
                 SET name = :name, email = :email, url = :url, message = :message,
                     status = :status, admin_notes = :notes
                 WHERE id = :id'
            );
            $stmt->execute(array_merge($baseParams, ['notes' => $adminNotes !== '' ? $adminNotes : null]));
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'admin_notes')) {
                // Column not yet added — update without it
                $stmt = $this->db->prepare(
                    'UPDATE `' . self::TABLE . '`
                     SET name = :name, email = :email, url = :url, message = :message, status = :status
                     WHERE id = :id'
                );
                $stmt->execute($baseParams);
            } else {
                throw $e;
            }
        }
    }

    /**
     * Updates the status of an application.
     */
    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['pending', 'reviewed', 'approved', 'rejected', 'granted'], true)) {
            return;
        }
        $stmt = $this->db->prepare(
            'UPDATE `' . self::TABLE . '` SET status = :status WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /**
     * Deletes an application by ID.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM `' . self::TABLE . '` WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Returns counts for all statuses for a single listing in one query.
     *
     * @return array{all: int, pending: int, reviewed: int, approved: int, rejected: int, granted: int}
     */
    public function getCountsForListing(int $listingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*)                         AS total,
                SUM(status IN ('pending','new')) AS cnt_pending,
                SUM(status = 'reviewed')         AS cnt_reviewed,
                SUM(status = 'approved')         AS cnt_approved,
                SUM(status = 'rejected')         AS cnt_rejected,
                SUM(status = 'granted')          AS cnt_granted
             FROM `" . self::TABLE . "` WHERE fanlisting_id = :lid"
        );
        $stmt->execute(['lid' => $listingId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'all'      => (int) ($row['total']       ?? 0),
            'pending'  => (int) ($row['cnt_pending']  ?? 0),
            'reviewed' => (int) ($row['cnt_reviewed'] ?? 0),
            'approved' => (int) ($row['cnt_approved'] ?? 0),
            'rejected' => (int) ($row['cnt_rejected'] ?? 0),
            'granted'  => (int) ($row['cnt_granted']  ?? 0),
        ];
    }

    /**
     * Returns pending/approved/total counts per listing in one query.
     * Used by the admin listings tab overview.
     *
     * @return array<int, array{all: int, pending: int, approved: int}>
     */
    public function getAllListingCounts(): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                fanlisting_id,
                COUNT(*)                    AS total,
                SUM(status = 'pending')     AS cnt_pending,
                SUM(status = 'approved')    AS cnt_approved
             FROM `" . self::TABLE . "`
             GROUP BY fanlisting_id"
        );
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['fanlisting_id']] = [
                'all'      => (int) $row['total'],
                'pending'  => (int) $row['cnt_pending'],
                'approved' => (int) $row['cnt_approved'],
            ];
        }
        return $result;
    }
}
