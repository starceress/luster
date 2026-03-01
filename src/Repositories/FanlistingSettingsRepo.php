<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_fanlisting_settings table.
 *
 * Stores Luster-specific per-fanlisting settings that have no equivalent
 * column in the Enthusiast schema (e.g. auto_approve, template overrides).
 *
 * Requires lstr_fanlisting_settings to exist — run public/migrate.php first.
 * Migration 5 adds stats_template and members_template columns.
 * Migration 6 adds affiliates_template column.
 */
final class FanlistingSettingsRepo
{
    public function __construct(private readonly Database $db) {}

    /**
     * Returns whether auto-approve is enabled for the given fanlisting.
     * Defaults to false if no settings row exists.
     */
    public function getAutoApprove(int $fanlistingId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT auto_approve FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
        );
        $stmt->execute(['id' => $fanlistingId]);
        $row = $stmt->fetch();

        return $row !== false ? (bool) $row['auto_approve'] : false;
    }

    /**
     * Sets the auto-approve flag for a fanlisting, upserting the settings row.
     */
    public function setAutoApprove(int $fanlistingId, bool $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, auto_approve)
             VALUES (:id, :val)
             ON DUPLICATE KEY UPDATE auto_approve = VALUES(auto_approve)'
        );
        $stmt->execute([
            'id'  => $fanlistingId,
            'val' => (int) $value,
        ]);
    }

    /**
     * Returns the stats, members, and affiliates template overrides for a fanlisting.
     *
     * All values are null when no override has been saved.
     *
     * @return array{stats_template: string|null, members_template: string|null, affiliates_template: string|null}
     */
    public function getTemplates(int $fanlistingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT stats_template, members_template, affiliates_template
             FROM lstr_fanlisting_settings
             WHERE fanlisting_id = :id'
        );
        $stmt->execute(['id' => $fanlistingId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return ['stats_template' => null, 'members_template' => null, 'affiliates_template' => null];
        }

        return [
            'stats_template'      => $row['stats_template']      !== null ? (string) $row['stats_template']      : null,
            'members_template'    => $row['members_template']    !== null ? (string) $row['members_template']    : null,
            'affiliates_template' => $row['affiliates_template'] !== null ? (string) $row['affiliates_template'] : null,
        ];
    }

    /**
     * Saves (upserts) the stats, members, and affiliates template overrides for a fanlisting.
     *
     * Pass null to clear an override. Does not touch the auto_approve column
     * on existing rows; new rows default auto_approve to 0.
     */
    public function saveTemplates(
        int $fanlistingId,
        ?string $statsTemplate,
        ?string $membersTemplate,
        ?string $affiliatesTemplate,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, stats_template, members_template, affiliates_template)
             VALUES (:id, :stats, :members, :affiliates)
             ON DUPLICATE KEY UPDATE
                 stats_template      = VALUES(stats_template),
                 members_template    = VALUES(members_template),
                 affiliates_template = VALUES(affiliates_template)'
        );
        $stmt->execute([
            'id'         => $fanlistingId,
            'stats'      => $statsTemplate,
            'members'    => $membersTemplate,
            'affiliates' => $affiliatesTemplate,
        ]);
    }
}
