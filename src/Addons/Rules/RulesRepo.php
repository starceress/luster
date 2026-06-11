<?php

declare(strict_types=1);

namespace Luster\Addons\Rules;

use Luster\Core\Database;

/**
 * Data access for the Rules add-on.
 *
 * One row per listing in lstr_rules (UNIQUE on fanlisting_id).
 */
final class RulesRepo
{
    public function __construct(private readonly Database $db) {}

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    public function getForListing(int $listingId): ?RuleSet
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM `lstr_rules` WHERE `fanlisting_id` = :id'
        );
        $stmt->execute(['id' => $listingId]);
        $row = $stmt->fetch();

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Returns all listings joined with their rules status.
     * Rows without rules have null rule fields.
     *
     * @return array<int,array{listing_id:int,listing_title:string,listing_subject:string,rules_id:int|null,rules_enabled:bool|null,rules_version:int|null,rules_updated_at:string|null}>
     */
    public function getAllWithListings(): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                o.listingid            AS listing_id,
                o.title                AS listing_title,
                o.subject              AS listing_subject,
                r.id                   AS rules_id,
                r.enabled              AS rules_enabled,
                r.version              AS rules_version,
                r.updated_at           AS rules_updated_at
             FROM `owned` o
             LEFT JOIN `lstr_rules` r ON r.fanlisting_id = o.listingid
             ORDER BY o.subject ASC'
        );
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'listing_id'       => (int) $row['listing_id'],
                'listing_title'    => (string) $row['listing_title'],
                'listing_subject'  => (string) $row['listing_subject'],
                'rules_id'         => $row['rules_id'] !== null ? (int) $row['rules_id'] : null,
                'rules_enabled'    => $row['rules_enabled'] !== null ? (bool) $row['rules_enabled'] : null,
                'rules_version'    => $row['rules_version'] !== null ? (int) $row['rules_version'] : null,
                'rules_updated_at' => $row['rules_updated_at'] !== null ? (string) $row['rules_updated_at'] : null,
            ];
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Create or update a rule set for a listing.
     * Bumps the version if the body has changed.
     */
    public function upsert(
        int $listingId,
        string $body,
        bool $enabled,
        string $listStyle = 'numbered',
        string $gateType = 'checkbox',
        string $gateLabel = ''
    ): void {
        $existing = $this->getForListing($listingId);

        if ($existing === null) {
            $stmt = $this->db->prepare(
                'INSERT INTO `lstr_rules`
                    (`fanlisting_id`, `body`, `enabled`, `list_style`, `gate_type`, `gate_label`, `version`, `updated_at`)
                 VALUES
                    (:fid, :body, :enabled, :list_style, :gate_type, :gate_label, 1, NOW())'
            );
            $stmt->execute([
                'fid'        => $listingId,
                'body'       => $body,
                'enabled'    => $enabled ? 1 : 0,
                'list_style' => $listStyle,
                'gate_type'  => $gateType,
                'gate_label' => $gateLabel,
            ]);
            return;
        }

        // Bump version only when body content changes — enabled state and
        // display/gate settings do not invalidate existing fan agreements.
        $bumpVersion = trim($body) !== trim($existing->body);

        // Two static statements (rather than a CASE WHEN keyed off a bound
        // parameter) avoid PDO's string-vs-integer comparison pitfalls across
        // MySQL and SQLite — both bind the same set of named parameters.
        $versionExpr = $bumpVersion ? '`version` + 1' : '`version`';

        $stmt = $this->db->prepare(
            'UPDATE `lstr_rules`
             SET `body`       = :body,
                 `enabled`    = :enabled,
                 `list_style` = :list_style,
                 `gate_type`  = :gate_type,
                 `gate_label` = :gate_label,
                 `version`    = ' . $versionExpr . ',
                 `updated_at` = NOW()
             WHERE `fanlisting_id` = :fid'
        );
        $stmt->execute([
            'body'       => $body,
            'enabled'    => $enabled ? 1 : 0,
            'list_style' => $listStyle,
            'gate_type'  => $gateType,
            'gate_label' => $gateLabel,
            'fid'        => $listingId,
        ]);
    }

    public function setEnabled(int $listingId, bool $enabled): void
    {
        $stmt = $this->db->prepare(
            'UPDATE `lstr_rules` SET `enabled` = :enabled WHERE `fanlisting_id` = :fid'
        );
        $stmt->execute(['enabled' => $enabled ? 1 : 0, 'fid' => $listingId]);
    }

    public function delete(int $listingId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM `lstr_rules` WHERE `fanlisting_id` = :fid'
        );
        $stmt->execute(['fid' => $listingId]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): RuleSet
    {
        return new RuleSet(
            id:          (int)  $row['id'],
            fanlistingId:(int)  $row['fanlisting_id'],
            body:        (string) $row['body'],
            enabled:     (bool) $row['enabled'],
            version:     (int)  $row['version'],
            updatedAt:   (string) $row['updated_at'],
            listStyle:   (string) ($row['list_style'] ?? 'numbered'),
            gateType:    (string) ($row['gate_type']  ?? 'checkbox'),
            gateLabel:   (string) ($row['gate_label'] ?? ''),
        );
    }
}
