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

    // -----------------------------------------------------------------------
    // Last Updated
    // -----------------------------------------------------------------------

    /**
     * Returns the stored last_updated DATE for a fanlisting, or null if unset.
     *
     * Requires Migration 20.
     */
    public function getLastUpdated(int $fanlistingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $fanlistingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return null;
        }

        if ($row === false || $row['last_updated'] === null || $row['last_updated'] === '') {
            return null;
        }

        return (string) $row['last_updated'];
    }

    /**
     * Sets last_updated to today's date for the given fanlisting (upsert).
     *
     * Called automatically on member approve, edit, and affiliate mutations.
     * Requires Migration 20.
     */
    public function touchLastUpdated(int $fanlistingId): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated)
                 VALUES (:id, CURDATE())
                 ON DUPLICATE KEY UPDATE last_updated = CURDATE()'
            );
            $stmt->execute(['id' => $fanlistingId]);
        } catch (\Exception) {
            // Column not yet present — Migration 20 not run; silently ignore
        }
    }

    /**
     * Sets last_updated to a specific date string (YYYY-MM-DD) for a fanlisting.
     *
     * Pass null or empty string to clear the stored value.
     * Requires Migration 20.
     */
    public function setLastUpdated(int $fanlistingId, ?string $date): void
    {
        $value = ($date !== null && $date !== '') ? $date : null;

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated)
                 VALUES (:id, :date)
                 ON DUPLICATE KEY UPDATE last_updated = :date2'
            );
            $stmt->execute(['id' => $fanlistingId, 'date' => $value, 'date2' => $value]);
        } catch (\Exception) {
            // Column not yet present — Migration 20 not run; silently ignore
        }
    }

    /**
     * Returns fanlisting IDs whose last_updated is older than $thresholdDays
     * days, or NULL (never set). Passing 0 disables the check (returns []).
     *
     * @return int[]
     */
    public function getStaleIds(int $thresholdDays): array
    {
        if ($thresholdDays <= 0) {
            return [];
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT fanlisting_id FROM lstr_fanlisting_settings
                 WHERE last_updated IS NULL
                    OR last_updated < DATE_SUB(CURDATE(), INTERVAL :days DAY)'
            );
            $stmt->execute(['days' => $thresholdDays]);

            return array_map(
                static fn(array $r) => (int) $r['fanlisting_id'],
                $stmt->fetchAll(),
            );
        } catch (\Exception) {
            // Column not yet present — Migration 20 not run
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Auto-approve
    // -----------------------------------------------------------------------

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
     * Returns whether update-queuing is enabled for the given fanlisting.
     * Defaults to false if no settings row exists or migration hasn't run.
     */
    public function getQueueUpdates(int $fanlistingId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT queue_updates FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $fanlistingId]);
            $row = $stmt->fetch();

            return $row !== false ? (bool) $row['queue_updates'] : false;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Sets the queue-updates flag for a fanlisting, upserting the settings row.
     */
    public function setQueueUpdates(int $fanlistingId, bool $value): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, queue_updates)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE queue_updates = VALUES(queue_updates)'
            );
            $stmt->execute([
                'id'  => $fanlistingId,
                'val' => (int) $value,
            ]);
        } catch (\Exception) {
            // Column not yet added — migration hasn't been run
        }
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

    /**
     * Returns the stored image filename for a fanlisting, or null if none set.
     */
    public function getImage(int $fanlistingId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT image FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
        );
        $stmt->execute(['id' => $fanlistingId]);
        $row = $stmt->fetch();

        if ($row === false || $row['image'] === null || $row['image'] === '') {
            return null;
        }

        return (string) $row['image'];
    }

    /**
     * Saves (upserts) the image filename for a fanlisting.
     *
     * Does not touch any other columns on existing rows; new rows default
     * auto_approve to 0 and all template columns to null.
     */
    public function saveImage(int $fanlistingId, string $filename): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, image)
             VALUES (:id, :image)
             ON DUPLICATE KEY UPDATE image = VALUES(image)'
        );
        $stmt->execute([
            'id'    => $fanlistingId,
            'image' => $filename,
        ]);
    }

    /**
     * Returns the listing type for a fanlisting.
     * Defaults to 'fanlisting' if no settings row exists or the value is null.
     */
    public function getListingType(int $fanlistingId): string
    {
        $stmt = $this->db->prepare(
            'SELECT listing_type FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
        );
        $stmt->execute(['id' => $fanlistingId]);
        $row = $stmt->fetch();

        if ($row === false || $row['listing_type'] === null || $row['listing_type'] === '') {
            return 'fanlisting';
        }

        return (string) $row['listing_type'];
    }

    /**
     * Saves (upserts) the listing type for a fanlisting.
     *
     * Does not touch any other columns on existing rows; new rows default
     * auto_approve to 0 and all other columns to null.
     */
    public function saveListingType(int $fanlistingId, string $type): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, listing_type)
             VALUES (:id, :type)
             ON DUPLICATE KEY UPDATE listing_type = VALUES(listing_type)'
        );
        $stmt->execute([
            'id'   => $fanlistingId,
            'type' => $type,
        ]);
    }

    /**
     * Returns the per-page member count override for a fanlisting.
     * Defaults to 25 if no settings row exists or the value is null.
     */
    public function getMembersPerPage(int $fanlistingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT members_per_page FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
        );
        $stmt->execute(['id' => $fanlistingId]);
        $row = $stmt->fetch();

        if ($row === false || $row['members_per_page'] === null) {
            return 25;
        }

        return (int) $row['members_per_page'];
    }

    /**
     * Saves (upserts) the per-page member count override for a fanlisting.
     *
     * Does not touch any other columns on existing rows; new rows default
     * auto_approve to 0 and all other columns to null.
     */
    public function saveMembersPerPage(int $fanlistingId, int $perPage): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, members_per_page)
             VALUES (:id, :per_page)
             ON DUPLICATE KEY UPDATE members_per_page = VALUES(members_per_page)'
        );
        $stmt->execute([
            'id'       => $fanlistingId,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Returns embed-related settings for a fanlisting.
     *
     * Requires Migration 17 (redirect URLs, DB override) and Migration 19
     * (form URLs + redirect_url_reset). Returns safe defaults when no settings
     * row exists or when columns are not yet present (migrations not run).
     *
     * redirect_url_*  — where to redirect AFTER a successful action
     * form_url_*      — where to redirect on validation FAILURE so the member
     *                   ends up back on the external embed page (not Luster's
     *                   internal /join/{id} etc.)
     *
     * @return array{
     *   country_field_enabled: bool,
     *   redirect_url_join:     string,
     *   redirect_url_update:   string,
     *   redirect_url_remove:   string,
     *   redirect_url_reset:    string,
     *   form_url_join:         string,
     *   form_url_update:       string,
     *   form_url_remove:       string,
     *   form_url_reset:        string,
     *   db_host:               string,
     *   db_name:               string,
     *   db_user:               string,
     *   db_pass:               string,
     * }
     */
    public function getEmbedSettings(int $fanlistingId): array
    {
        $defaults = [
            'country_field_enabled' => true,
            'redirect_url_join'     => '',
            'redirect_url_update'   => '',
            'redirect_url_remove'   => '',
            'redirect_url_reset'    => '',
            'form_url_join'         => '',
            'form_url_update'       => '',
            'form_url_remove'       => '',
            'form_url_reset'        => '',
            'db_host'               => '',
            'db_name'               => '',
            'db_user'               => '',
            'db_pass'               => '',
        ];

        try {
            $stmt = $this->db->prepare(
                'SELECT country_field_enabled,
                        redirect_url_join, redirect_url_update,
                        redirect_url_remove, redirect_url_reset,
                        form_url_join, form_url_update,
                        form_url_remove, form_url_reset,
                        db_host, db_name, db_user, db_pass
                 FROM lstr_fanlisting_settings
                 WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $fanlistingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            // Columns not yet added — Migration 17/19 not run
            return $defaults;
        }

        if ($row === false) {
            return $defaults;
        }

        return [
            'country_field_enabled' => (bool)   $row['country_field_enabled'],
            'redirect_url_join'     => (string) ($row['redirect_url_join']     ?? ''),
            'redirect_url_update'   => (string) ($row['redirect_url_update']   ?? ''),
            'redirect_url_remove'   => (string) ($row['redirect_url_remove']   ?? ''),
            'redirect_url_reset'    => (string) ($row['redirect_url_reset']    ?? ''),
            'form_url_join'         => (string) ($row['form_url_join']         ?? ''),
            'form_url_update'       => (string) ($row['form_url_update']       ?? ''),
            'form_url_remove'       => (string) ($row['form_url_remove']       ?? ''),
            'form_url_reset'        => (string) ($row['form_url_reset']        ?? ''),
            'db_host'               => (string) ($row['db_host']               ?? ''),
            'db_name'               => (string) ($row['db_name']               ?? ''),
            'db_user'               => (string) ($row['db_user']               ?? ''),
            'db_pass'               => (string) ($row['db_pass']               ?? ''),
        ];
    }

    /**
     * Saves (upserts) embed-related settings for a fanlisting.
     *
     * Pass an empty string for $dbPass to keep the existing stored password
     * (the controller must do this check before calling).
     *
     * Requires Migration 17 (redirect URLs, DB override) and Migration 19
     * (form URLs + redirect_url_reset).
     */
    public function saveEmbedSettings(
        int    $fanlistingId,
        bool   $countryFieldEnabled,
        string $redirectUrlJoin,
        string $redirectUrlUpdate,
        string $redirectUrlRemove,
        string $redirectUrlReset,
        string $formUrlJoin,
        string $formUrlUpdate,
        string $formUrlRemove,
        string $formUrlReset,
        string $dbHost,
        string $dbName,
        string $dbUser,
        string $dbPass,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fanlisting_settings
                 (fanlisting_id, country_field_enabled,
                  redirect_url_join, redirect_url_update,
                  redirect_url_remove, redirect_url_reset,
                  form_url_join, form_url_update,
                  form_url_remove, form_url_reset,
                  db_host, db_name, db_user, db_pass)
             VALUES
                 (:id, :country,
                  :redir_join, :redir_update, :redir_remove, :redir_reset,
                  :form_join, :form_update, :form_remove, :form_reset,
                  :host, :dbname, :user, :pass)
             ON DUPLICATE KEY UPDATE
                 country_field_enabled = VALUES(country_field_enabled),
                 redirect_url_join     = VALUES(redirect_url_join),
                 redirect_url_update   = VALUES(redirect_url_update),
                 redirect_url_remove   = VALUES(redirect_url_remove),
                 redirect_url_reset    = VALUES(redirect_url_reset),
                 form_url_join         = VALUES(form_url_join),
                 form_url_update       = VALUES(form_url_update),
                 form_url_remove       = VALUES(form_url_remove),
                 form_url_reset        = VALUES(form_url_reset),
                 db_host               = VALUES(db_host),
                 db_name               = VALUES(db_name),
                 db_user               = VALUES(db_user),
                 db_pass               = VALUES(db_pass)'
        );
        $stmt->execute([
            'id'          => $fanlistingId,
            'country'     => (int) $countryFieldEnabled,
            'redir_join'  => $redirectUrlJoin,
            'redir_update'=> $redirectUrlUpdate,
            'redir_remove'=> $redirectUrlRemove,
            'redir_reset' => $redirectUrlReset,
            'form_join'   => $formUrlJoin,
            'form_update' => $formUrlUpdate,
            'form_remove' => $formUrlRemove,
            'form_reset'  => $formUrlReset,
            'host'        => $dbHost,
            'dbname'      => $dbName,
            'user'        => $dbUser,
            'pass'        => $dbPass,
        ]);
    }
}
