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
    public function countForListing(int $listingId, string $status = 'new'): int
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
     * Returns the total count of new applications across all listings.
     * Used for the admin nav badge. Returns 0 safely if the table does not exist.
     */
    public function countNew(): int
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM `" . self::TABLE . "` WHERE status = 'new'"
            );
            $stmt->execute();
            return (int) $stmt->fetchColumn();
        } catch (\Exception) {
            return 0;
        }
    }

    /**
     * Returns all applications across all listings, optionally filtered by status.
     *
     * @return array[]
     */
    public function getForAllListings(string $status = 'new'): array
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
     * Updates the status of an application.
     * $status must be one of: new, reviewed, approved, rejected.
     */
    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['new', 'reviewed', 'approved', 'rejected'], true)) {
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
     * @return array{all: int, new: int, reviewed: int, approved: int, rejected: int}
     */
    public function getCountsForListing(int $listingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*)               AS total,
                SUM(status = 'new')      AS cnt_new,
                SUM(status = 'reviewed') AS cnt_reviewed,
                SUM(status = 'approved') AS cnt_approved,
                SUM(status = 'rejected') AS cnt_rejected
             FROM `" . self::TABLE . "` WHERE fanlisting_id = :lid"
        );
        $stmt->execute(['lid' => $listingId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return [
            'all'      => (int) ($row['total']         ?? 0),
            'new'      => (int) ($row['cnt_new']        ?? 0),
            'reviewed' => (int) ($row['cnt_reviewed']   ?? 0),
            'approved' => (int) ($row['cnt_approved']   ?? 0),
            'rejected' => (int) ($row['cnt_rejected']   ?? 0),
        ];
    }

    /**
     * Returns new/approved/total counts per listing in one query.
     * Used by the admin listings tab overview.
     *
     * @return array<int, array{all: int, new: int, approved: int}>
     */
    public function getAllListingCounts(): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                fanlisting_id,
                COUNT(*)               AS total,
                SUM(status = 'new')      AS cnt_new,
                SUM(status = 'approved') AS cnt_approved
             FROM `" . self::TABLE . "`
             GROUP BY fanlisting_id"
        );
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['fanlisting_id']] = [
                'all'      => (int) $row['total'],
                'new'      => (int) $row['cnt_new'],
                'approved' => (int) $row['cnt_approved'],
            ];
        }
        return $result;
    }
}
