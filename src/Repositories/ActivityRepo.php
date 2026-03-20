<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use PDO;

/**
 * Data access for the lstr_activity_log table.
 *
 * Used to write audit trail entries and to display the paginated
 * activity log in the admin panel.
 */
final class ActivityRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------------

    /**
     * Appends a new log entry.
     *
     * @param int|null $adminUserId  Null for system-generated entries.
     * @param string   $action       Short machine-readable action key, e.g. 'member.approve'.
     * @param string   $description  Human-readable description.
     * @param string   $ipAddress    Client IP; empty string if unavailable.
     */
    public function log(
        ?int   $adminUserId,
        string $action,
        string $description,
        string $ipAddress = '',
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_activity_log (admin_user_id, action, description, ip_address)
             VALUES (:admin_user_id, :action, :description, :ip_address)'
        );

        $stmt->execute([
            'admin_user_id' => $adminUserId,
            'action'        => $action,
            'description'   => $description,
            'ip_address'    => $ipAddress !== '' ? $ipAddress : null,
        ]);
    }

    // -----------------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------------

    /**
     * Returns paginated log entries, newest first.
     *
     * @return array{entries: list<array>, total: int, pages: int}
     */
    public function getPaginated(int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM lstr_activity_log');
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $this->db->prepare(
            'SELECT l.description, l.ip_address, l.created_at, u.username
             FROM lstr_activity_log l
             LEFT JOIN lstr_admin_users u ON u.id = l.admin_user_id
             ORDER BY l.created_at DESC
             LIMIT :lim OFFSET :off'
        );
        $listStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $listStmt->bindValue(':off', $offset,  PDO::PARAM_INT);
        $listStmt->execute();

        return [
            'entries' => $listStmt->fetchAll(),
            'total'   => $total,
            'pages'   => $total > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Returns the N most recent log entries for the dashboard.
     *
     * @return list<array>
     */
    public function getRecent(int $limit = 10): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.id, l.action, l.description, l.created_at, u.username
             FROM lstr_activity_log l
             LEFT JOIN lstr_admin_users u ON u.id = l.admin_user_id
             ORDER BY l.created_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Returns all log entries, newest first (for CSV export).
     *
     * @return list<array>
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.created_at, l.description, u.username, l.ip_address
             FROM lstr_activity_log l
             LEFT JOIN lstr_admin_users u ON u.id = l.admin_user_id
             ORDER BY l.created_at DESC'
        );
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Deletes every row from the activity log table.
     */
    public function flush(): void
    {
        $this->db->prepare('DELETE FROM lstr_activity_log')->execute();
    }
}
