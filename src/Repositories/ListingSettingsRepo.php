<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages per-listing settings in lstr_fanlisting_settings. This table holds
 everything Luster needs that has no equivalent column in the Enthusiast
 schema: template overrides (stats, members, affiliates, codes, updates,
 join form, etc.), embed URL paths, per-listing DB credentials, image
 filenames, last-updated dates, TFL/TAFL approval status, join settings
 (auto-approve, confirmation email), contact form settings, adoption flags,
 affiliate application settings, and listing descriptions. Also provides
 the built-in default templates used as fallbacks when no override is stored.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Data access for the lstr_fanlisting_settings table.
 *
 * Stores Luster-specific per-fanlisting settings that have no equivalent
 * column in the Enthusiast schema (e.g. template overrides, embed settings).
 *
 * Requires lstr_fanlisting_settings to exist — run public/migrate.php first.
 * Migration 5 adds stats_template and members_template columns.
 * Migration 53 adds affiliates_section_template column.
 * Migration 65 drops the legacy affiliates_template column (superseded by
 * affiliates_section_template's {each_affiliate} loop syntax).
 */
final class ListingSettingsRepo
{
    public function __construct(private readonly Database $db) {}

    // -----------------------------------------------------------------------
    // Last Updated
    // -----------------------------------------------------------------------

    /**
     * Returns the stored last_updated DATE for a listing, or null if unset.
     *
     * Requires Migration 20.
     */
    public function getLastUpdated(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
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
     * Sets last_updated to today's date for the given listing (upsert).
     *
     * Called automatically on member approve, edit, and affiliate mutations.
     * Requires Migration 20.
     */
    public function touchLastUpdated(int $listingId): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated)
                 VALUES (:id, CURDATE())
                 ON DUPLICATE KEY UPDATE last_updated = CURDATE()'
            );
            $stmt->execute(['id' => $listingId]);
        } catch (\Exception) {
            // Column not yet present — Migration 20 not run; silently ignore
        }
    }

    /**
     * Sets last_updated to a specific date string (YYYY-MM-DD) for a listing.
     *
     * Pass null or empty string to clear the stored value.
     * Requires Migration 20.
     */
    public function setLastUpdated(int $listingId, ?string $date): void
    {
        $value = ($date !== null && $date !== '') ? $date : null;

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, last_updated)
                 VALUES (:id, :date)
                 ON DUPLICATE KEY UPDATE last_updated = :date2'
            );
            $stmt->execute(['id' => $listingId, 'date' => $value, 'date2' => $value]);
        } catch (\Exception) {
            // Column not yet present — Migration 20 not run; silently ignore
        }
    }

    /**
     * Returns listing IDs whose last_updated is older than $thresholdDays
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

    /**
     * Returns whether update-queuing is enabled for the given listing.
     * Defaults to false if no settings row exists or migration hasn't run.
     */
    public function getQueueUpdates(int $listingId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT queue_updates FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();

            return $row !== false ? (bool) $row['queue_updates'] : false;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Sets the queue-updates flag for a listing, upserting the settings row.
     */
    public function setQueueUpdates(int $listingId, bool $value): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, queue_updates)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE queue_updates = VALUES(queue_updates)'
            );
            $stmt->execute([
                'id'  => $listingId,
                'val' => (int) $value,
            ]);
        } catch (\Exception) {
            // Column not yet added — migration hasn't been run
        }
    }

    /**
     * Returns all per-listing template overrides. All values are null when no override is saved.
     *
     * @return array{stats_template: string|null, members_template: string|null, affiliates_section_template: string|null, updates_template: string|null, codes_submit_form_template: string|null, rules_intro_template: string|null, join_form_template: string|null, affiliate_apply_form_template: string|null, adopt_form_template: string|null, contact_form_template: string|null, contact_sent_template: string|null, update_form_template: string|null, reset_form_template: string|null}
     */
    public function getTemplates(int $listingId): array
    {
        $defaults = [
            'stats_template'                => null,
            'members_template'              => null,
            'affiliates_section_template'   => null,
            'updates_template'              => null,
            'codes_submit_form_template'    => null,
            'rules_intro_template'          => null,
            'join_form_template'            => null,
            'affiliate_apply_form_template' => null,
            'adopt_form_template'           => null,
            'contact_form_template'         => null,
            'contact_sent_template'         => null,
            'update_form_template'          => null,
            'reset_form_template'           => null,
        ];

        // Try the widest query first; fall back gracefully as migrations are added.
        $row = false;

        foreach ([
            'stats_template, members_template, affiliates_section_template, updates_template, codes_submit_form_template, rules_intro_template, join_form_template, affiliate_apply_form_template, adopt_form_template, contact_form_template, contact_sent_template, update_form_template, reset_form_template',
            'stats_template, members_template, affiliates_section_template, updates_template, codes_submit_form_template, rules_intro_template, join_form_template, affiliate_apply_form_template, adopt_form_template, contact_form_template, update_form_template, reset_form_template',
            'stats_template, members_template, affiliates_section_template, updates_template, codes_submit_form_template, rules_intro_template, join_form_template, affiliate_apply_form_template, adopt_form_template',
            'stats_template, members_template, affiliates_section_template, updates_template, rules_intro_template',
            'stats_template, members_template, affiliates_section_template, updates_template',
            'stats_template, members_template, affiliates_section_template',
            'stats_template, members_template',
        ] as $selectCols) {
            try {
                $stmt = $this->db->prepare(
                    "SELECT {$selectCols} FROM lstr_fanlisting_settings WHERE fanlisting_id = :id"
                );
                $stmt->execute(['id' => $listingId]);
                $row = $stmt->fetch();
                break;
            } catch (\Exception) {
                // column absent — try narrower set
            }
        }

        if ($row === false) {
            return $defaults;
        }

        $tr  = static fn(?string $v): ?string => $v !== null ? self::translateLegacyTokens($v) : null;
        $col = static fn(array $r, string $k): ?string => isset($r[$k]) && $r[$k] !== null ? (string) $r[$k] : null;

        return [
            'stats_template'                => $tr($col($row, 'stats_template')),
            'members_template'              => $tr($col($row, 'members_template')),
            'affiliates_section_template'   => $tr($col($row, 'affiliates_section_template')),
            'updates_template'              => $col($row, 'updates_template'),
            'codes_submit_form_template'    => $col($row, 'codes_submit_form_template'),
            'rules_intro_template'          => $col($row, 'rules_intro_template'),
            'join_form_template'            => $col($row, 'join_form_template'),
            'affiliate_apply_form_template' => $col($row, 'affiliate_apply_form_template'),
            'adopt_form_template'           => $col($row, 'adopt_form_template'),
            'contact_form_template'         => $col($row, 'contact_form_template'),
            'contact_sent_template'         => $col($row, 'contact_sent_template'),
            'update_form_template'          => $col($row, 'update_form_template'),
            'reset_form_template'           => $col($row, 'reset_form_template'),
        ];
    }

    /**
     * Returns the per-listing pagination template string, or null when not set /
     * when Migration 64 has not been run yet.
     */
    public function getPaginationTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT pagination_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['pagination_template'] !== null)
                ? (string) $row['pagination_template']
                : null;
        } catch (\Exception) {
            return null; // pagination_template column absent — Migration 64 not run yet
        }
    }

    /**
     * Returns the per-listing codes row template string, or null when not set /
     * when Migration 64 has not been run yet.
     */
    public function getCodesTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT codes_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['codes_template'] !== null)
                ? (string) $row['codes_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Saves (upserts) the per-listing codes display template. Pass null or an
     * empty string to clear the override and fall back to the built-in default.
     *
     * Requires Migration 64.
     */
    public function saveCodesTemplate(int $listingId, ?string $template): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, codes_template)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE codes_template = VALUES(codes_template)'
            );
            $stmt->execute(['id' => $listingId, 'val' => ($template !== null && trim($template) !== '') ? $template : null]);
        } catch (\Exception) {
            // Migration 64 not run yet
        }
    }

    /**
     * Saves (upserts) the per-listing contact-sent confirmation template.
     * Pass null/empty to clear the override (falls back to global default).
     */
    public function saveContactSentTemplate(int $listingId, ?string $template): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, contact_sent_template)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE contact_sent_template = VALUES(contact_sent_template)'
            );
            $stmt->execute(['id' => $listingId, 'val' => ($template !== null && trim($template) !== '') ? $template : null]);
        } catch (\Exception) {
            // column not present yet
        }
    }

    /**
     * Returns the per-listing field wrapper template string, or null when not set /
     * when Migration 64 has not been run yet.
     */
    public function getWrapperTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT field_wrapper_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['field_wrapper_template'] !== null)
                ? (string) $row['field_wrapper_template']
                : null;
        } catch (\Exception) {
            return null; // column absent — Migration 64 not run yet
        }
    }

    /**
     * Returns the per-listing updates row template string, or null when not set /
     * when Migration 64 has not been run yet.
     */
    public function getUpdatesTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT updates_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['updates_template'] !== null)
                ? (string) $row['updates_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Returns the per-listing Join Form template string, or null when not set /
     * when Migration 70 has not been run yet.
     */
    public function getJoinFormTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT join_form_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['join_form_template'] !== null)
                ? (string) $row['join_form_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Returns the per-listing Adoption Form template string, or null when not set /
     * when Migration 70 has not been run yet.
     */
    public function getAdoptFormTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT adopt_form_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['adopt_form_template'] !== null)
                ? (string) $row['adopt_form_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Default Adoption Application Form template used when no override is stored in the database.
     */
    public static function defaultAdoptFormTemplate(): string
    {
        return '{field_name}' . "\n"
             . '{field_email}' . "\n"
             . '{field_url}' . "\n"
             . '{field_message}' . "\n"
             . '{captcha_block}' . "\n"
             . '<div class="fl-form-actions">' . "\n"
             . '{submit_button}' . "\n"
             . '{clear_button}' . "\n"
             . '</div>';
    }

    /**
     * Returns the per-listing Contact Form template string, or null when not set /
     * when Migration 70 has not been run yet.
     */
    public function getContactFormTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT contact_form_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['contact_form_template'] !== null)
                ? (string) $row['contact_form_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Default Contact Form template used when no override is stored in the database.
     */
    public static function defaultContactFormTemplate(): string
    {
        return '<h2>Contact Form</h2>' . "\n"
             . '<div class="fl-form-fields">' . "\n"
             . '{field_name}' . "\n"
             . '{field_email}' . "\n"
             . '{field_url}' . "\n"
             . '{field_subject}' . "\n"
             . '{field_message}' . "\n"
             . '{captcha_block}' . "\n"
             . '</div>' . "\n"
             . '<div class="fl-form-actions">' . "\n"
             . '{submit_button}' . "\n"
             . '{clear_button}' . "\n"
             . '</div>';
    }

    /**
     * Returns the per-listing success/action-confirmation template string, or null
     * when not set / when Migration 64 has not been run yet.
     */
    public function getSuccessTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT success_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['success_template'] !== null)
                ? (string) $row['success_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Returns the per-listing contact-sent confirmation template string, or null
     * when not set / when Migration 64 has not been run yet.
     */
    public function getContactSentTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT contact_sent_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['contact_sent_template'] !== null)
                ? (string) $row['contact_sent_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Default contact-sent confirmation HTML used when no override is stored in the database.
     */
    public static function defaultContactSentTemplate(): string
    {
        return '<div class="fl-success"><p>Your message has been sent. Thank you for getting in touch, {sender_name}!</p></div>';
    }

    /**
     * Returns the per-listing update-form template string, or null when not set /
     * when the column has not been added yet.
     */
    public function getUpdateFormTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT update_form_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['update_form_template'] !== null)
                ? (string) $row['update_form_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Returns the per-listing reset-form template string, or null when not set /
     * when the column has not been added yet.
     */
    public function getResetFormTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT reset_form_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['reset_form_template'] !== null)
                ? (string) $row['reset_form_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Default Update (edit membership) Form template used when no override is stored.
     */
    public static function defaultUpdateFormTemplate(): string
    {
        return '{if_not_signed_in}' . "\n"
            . '<h2>Update Your Information for {listing_subject}</h2>' . "\n"
            . '<p>Enter your email address and password to continue.</p>' . "\n"
            . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_signin_email}' . "\n"
            . '{field_signin_password}' . "\n"
            . '</div>' . "\n"
            . '<p><a href="{lost_password_url}" class="fl-link">Forgot your password?</a></p>' . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '</div>' . "\n"
            . '{/if_not_signed_in}' . "\n"
            . "\n"
            . '{if_signed_in}' . "\n"
            . '<h2>Update Your Information for {listing_subject}</h2>' . "\n"
            . '<p>Signed in as <strong>{field_email}</strong>. <a href="{sign_out_url}" class="fl-link">Not you?</a></p>' . "\n"
            . '<p>Fill in only the fields you want to change. Leave a field blank to keep your current value.</p>' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_new_name}' . "\n"
            . '{field_new_email}' . "\n"
            . '{field_new_url}' . "\n"
            . '{field_new_country}' . "\n"
            . '{additional_fields}' . "\n"
            . '{field_password}' . "\n"
            . '{field_password_confirm}' . "\n"
            . '{field_remove}' . "\n"
            . '</div>' . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '{clear_button}' . "\n"
            . '</div>' . "\n"
            . '{/if_signed_in}';
    }

    /**
     * Default Password Reset Form template used when no override is stored.
     */
    public static function defaultResetFormTemplate(): string
    {
        return '{if_step_email}' . "\n"
            . '<h2>Reset Password for {listing_subject}</h2>' . "\n"
            . '<p>Enter your email address and a link will be sent to reset your password. The link expires in 1 hour.</p>' . "\n"
            . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_email}' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '</div>' . "\n"
            . '{/if_step_email}' . "\n"
            . "\n"
            . '{if_step_confirm}' . "\n"
            . '<h2>Set New Password</h2>' . "\n"
            . '{if_token_invalid}' . "\n"
            . '<p>This reset link has expired or is invalid. <a href="{reset_request_url}" class="fl-link">Request a new one.</a></p>' . "\n"
            . '{/if_token_invalid}' . "\n"
            . '{if_token_valid}' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_new_password}' . "\n"
            . '{field_new_password_confirm}' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '</div>' . "\n"
            . '{/if_token_valid}' . "\n"
            . '{/if_step_confirm}';
    }

    /**
     * Returns all listings eligible for adoption (for_adoption = 1, status != 0),
     * sorted case-insensitively by title.
     *
     * @return list<array{id: int, title: string}>
     */
    public function getAdoptableListings(\Luster\Repositories\ListingRepo $repo): array
    {
        $pdo = $this->db->pdo();
        try {
            $stmt = $pdo->prepare(
                "SELECT o.listingid AS id, o.title
                   FROM owned o
                   JOIN lstr_fanlisting_settings s ON s.fanlisting_id = o.listingid
                  WHERE s.for_adoption = 1 AND o.status != 0
                  ORDER BY LOWER(o.title)"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception) {
            return [];
        }

        return array_values(array_map(
            static fn(array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']],
            $rows,
        ));
    }

    /**
     * Returns the per-listing affiliate-apply confirmation template string, or null
     * when not set / when Migration 64 has not been run yet.
     */
    public function getAffiliateApplySentTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT affiliate_apply_sent_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['affiliate_apply_sent_template'] !== null)
                ? (string) $row['affiliate_apply_sent_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Default stats template used when no override is stored in the database.
     *
     * Shared by embed/stats.php (public fallback) and the admin per-listing
     * template editor (pre-filled textarea so admins see the canonical default).
     */
    public static function defaultStatsTemplate(): string
    {
        return '<h2>{listing_subject} Statistics</h2>' . "\n"
            . '<div class="fl-stats">' . "\n"
            . '<div class="fl-stats-grid">' . "\n"
            . '<span class="fl-stats-label">Members:</span><span class="fl-stats-value">{stat_approved} total{if_stat_pending} (+{stat_pending} pending){/if_stat_pending}</span>' . "\n"
            . '<span class="fl-stats-label">Countries:</span><span class="fl-stats-value">{stat_countries}</span>' . "\n"
            . '<span class="fl-stats-label">Newest:</span><span class="fl-stats-value">{stat_newmembers}</span>' . "\n"
            . '<span class="fl-stats-label">Opened:</span><span class="fl-stats-value">{stat_opened}</span>' . "\n"
            . '<span class="fl-stats-label">Updated:</span><span class="fl-stats-value">{stat_updated}</span>' . "\n"
            . '<span class="fl-stats-label">Growth:</span><span class="fl-stats-value">{stat_growth}</span>' . "\n"
            . '{if_approved_by}<span class="fl-stats-label">Approved by:</span><span class="fl-stats-value"><a href="{if_tfl}{tfl_url}{/if_tfl}{if_tafl}{tafl_url}{/if_tafl}">{network_name}</a></span>{/if_approved_by}' . "\n"
            . '</div>' . "\n"
            . '</div>';
    }

    /**
     * Default members row template used when no override is stored in the database.
     *
     * Shared by templates/default/members.php (public fallback) and the admin
     * per-listing template editor (pre-filled textarea).
     */
    public static function defaultMembersTemplate(): string
    {
        return '<h1>Member List</h1>' . "\n"
            . '<p>Sort by: {sort_link_all} | {sort_link_name} | {sort_link_country}</p><br>' . "\n"
            . "\n"
            . '{if_members}' . "\n"
            . '<div class="fl-member-list">' . "\n"
            . "\n"
            . '<ul>' . "\n"
            . '{each_member}' . "\n"
            . '<li class="fl-member-item">{if_fan_url}<a href="{fan_url}">{fan_name}</a>{/if_fan_url}{if_no_fan_url}{fan_name}{/if_no_fan_url}{if_fan_country} &mdash; {fan_country}{/if_fan_country}</li>' . "\n"
            . '{/each_member}' . "\n"
            . '</ul>' . "\n"
            . "\n"
            . '</div>' . "\n"
            . '{/if_members}' . "\n"
            . "\n"
            . '{if_no_members}' . "\n"
            . '<p>No members yet. <a href="{join_url}">Want to join?</a></p>' . "\n"
            . '{/if_no_members}';
    }

    /**
     * Default members table template used when no override is stored in the database.
     *
     * Alternate layout that renders members as a two-column table (Name + Country)
     * instead of the simple list produced by defaultMembersTemplate().
     */
    public static function defaultMembersTableTemplate(): string
    {
        return '<h1>Member List</h1>' . "\n"
            . '<p>Sort by: {sort_link_all} | {sort_link_name} | {sort_link_country}</p><br>' . "\n"
            . "\n"
            . '{if_members}' . "\n"
            . '<div class="fl-member-table">' . "\n"
            . '<div class="fl-member-table-head">' . "\n"
            . '<span class="fl-member-table-th">Name</span>' . "\n"
            . '<span class="fl-member-table-th">Country</span>' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '{each_member}' . "\n"
            . '<div class="fl-member-table-row">' . "\n"
            . '<span class="fl-member-table-cell">{if_fan_url}<a href="{fan_url}">{fan_name}</a>{/if_fan_url}{if_no_fan_url}{fan_name}{/if_no_fan_url}</span>' . "\n"
            . '<span class="fl-member-table-cell">{if_fan_country}{fan_country}{/if_fan_country}{if_no_fan_country}&ndash;{/if_no_fan_country}</span>' . "\n"
            . '</div>' . "\n"
            . '{/each_member}' . "\n"
            . '</div>' . "\n"
            . '{/if_members}' . "\n"
            . "\n"
            . '{if_no_members}' . "\n"
            . '<p>No members yet. <a href="{join_url}">Want to join?</a></p>' . "\n"
            . '{/if_no_members}';
    }

    /**
     * Default affiliates section template used when no override is stored in the database.
     *
     * Shared by the public affiliates embed and the admin per-listing template editor.
     * Uses the {each_affiliate} loop with {if_aff_image} / {if_no_aff_image} conditionals.
     */
    public static function defaultAffiliatesSectionTemplate(): string
    {
        return '<h2>Affiliates</h2>' . "\n"
            . '<div class="fl-affiliates-section">' . "\n"
            . '{if_affiliates}' . "\n"
            . '{each_affiliate}' . "\n"
            . '{if_aff_image}<a href="{aff_url}" title="{aff_name}" target="_blank"><img src="{aff_image_url}" alt="{aff_name}"></a>{/if_aff_image}' . "\n"
            . '{if_no_aff_image}<a href="{aff_url}" title="{aff_name}" target="_blank">{aff_name}</a>{/if_no_aff_image}' . "\n"
            . "\n"
            . '{/each_affiliate}{/if_affiliates}' . "\n"
            . "\n"
            . '{if_no_affiliates}' . "\n"
            . 'No affiliates yet.' . "\n"
            . '{/if_no_affiliates}' . "\n"
            . '{affiliate_apply_toggle}' . "\n"
            . '</div>';
    }

    /**
     * Default updates-feed template used when no override is stored in the database.
     *
     * Uses the {each_update} loop with per-entry tokens: {update_title}, {update_date},
     * {update_author}, {update_categories}, {update_body_html}, and the
     * {if_author} / {if_categories} conditionals.
     */
    public static function defaultUpdatesTemplate(): string
    {
        return '<h2>{listing_subject} Updates</h2>' . "\n"
            . '<div class="fl-updates-list">' . "\n"
            . '{if_no_updates}<p>No updates yet.</p>{/if_no_updates}' . "\n"
            . '{if_updates}' . "\n"
            . '{each_update}' . "\n"
            . "\n"
            . '<div class="fl-update-item">' . "\n"
            . '<h3 class="fl-update-title">{update_title}</h3>' . "\n"
            . '<div class="fl-update-meta">' . "\n"
            . '<span class="fl-update-date"><b>Date Posted:</b> {update_date}</span>'
            . '{if_author} by <span class="fl-update-author">{update_author}</span>{/if_author}<br>' . "\n"
            . '{if_categories}<b>Categories:</b> <span class="fl-update-categories">{update_categories}</span>{/if_categories}</div>' . "\n"
            . "\n"
            . '<div class="fl-update-body">{update_body_html}</div>' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '{/each_update}' . "\n"
            . '{/if_updates}' . "\n"
            . '</div>';
    }

    /**
     * Default codes display template used when no override is stored in the database.
     *
     * Shared by templates/default/codes.php (public fallback — only when an
     * override containing {each_size_group} or {each_code} is stored) and the
     * admin per-listing template editor (pre-filled textarea).
     */
    public static function defaultCodesTemplate(): string
    {
        return '<h2>Codes</h2>' . "\n"
            . '{if_no_codes}<p>No codes available yet.</p>{/if_no_codes}' . "\n"
            . '{if_codes}' . "\n"
            . "\n"
            . '<div class="fl-codes">' . "\n"
            . '{each_size_group}' . "\n"
            . "\n"
            . '<div class="fl-code-group">' . "\n"
            . '<h2>{group_size}</h2>' . "\n"
            . "\n"
            . '<ul class="fl-code-list">' . "\n"
            . '{each_code}' . "\n"
            . "\n"
            . '<li class="fl-code-item">' . "\n"
            . '<img src="{code_url}" alt="{code_size}" class="fl-code-image">' . "\n"
            . '{if_donor}<span class="fl-code-caption">by {if_donor_url}<a href="{code_donor_url}" target="_blank">{code_donor_name}</a>{/if_donor_url}{if_no_donor_url}{code_donor_name}{/if_no_donor_url}</span>{/if_donor}' . "\n"
            . '{if_code_name}<span class="fl-code-caption">{code_name}</span>{/if_code_name}' . "\n"
            . '</li>' . "\n"
            . "\n"
            . '{/each_code}' . "\n"
            . '</ul>' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '{/each_size_group}' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '{/if_codes}';
    }

    /**
     * Default Join Form template used when no override is stored in the database.
     *
     * Shared by the public Join controller/embed (fallback to the hardcoded
     * templates/default/join.php layout when this matches) and the admin
     * per-listing template editor (pre-filled textarea).
     */
    public static function defaultJoinFormTemplate(): string
    {
        return '<h2>Join {listing_subject}</h2>' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_name}' . "\n"
            . '{field_email}' . "\n"
            . '{field_url}' . "\n"
            . '{field_country}' . "\n"
            . '{additional_fields}' . "\n"
            . '{field_password}' . "\n"
            . '{field_password_confirm}' . "\n"
            . '{captcha_block}' . "\n"
            . '</div>' . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '{clear_button}' . "\n"
            . '</div>';
    }

    /**
     * Default Affiliate Application Form template used when no override is stored in the database.
     */
    public static function defaultAffiliateApplyFormTemplate(): string
    {
        return '<h2>Affiliate Application for {listing_subject}</h2>' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_name}' . "\n"
            . '{field_email}' . "\n"
            . '{field_url}' . "\n"
            . '{field_image}' . "\n"
            . '{field_message}' . "\n"
            . '{captcha_block}' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '{clear_button}' . "\n"
            . '</div>';
    }

    /**
     * Default Codes Submit Form template used when no override is stored in the database.
     */
    public static function defaultCodesSubmitFormTemplate(): string
    {
        return '<h2>Donate Codes to {listing_subject}</h2>' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_submitter_name}' . "\n"
            . '{field_submitter_email}' . "\n"
            . '{field_submitter_url}' . "\n"
            . '{field_code_image}' . "\n"
            . '{captcha_block}' . "\n"
            . '</div>' . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '{clear_button}' . "\n"
            . '</div>';
    }

    /**
     * Returns the per-listing Affiliate Apply Form template string, or null when not set /
     * when Migration 70 has not been run yet.
     */
    public function getAffiliateApplyFormTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT affiliate_apply_form_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['affiliate_apply_form_template'] !== null)
                ? (string) $row['affiliate_apply_form_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Returns the per-listing Codes Submit Form template string, or null when not set /
     * when Migration 70 has not been run yet.
     */
    public function getCodesSubmitFormTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT codes_submit_form_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['codes_submit_form_template'] !== null)
                ? (string) $row['codes_submit_form_template']
                : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Translates legacy Enthusiast $$token$$ placeholders to Luster's {token} format.
     * Applied transparently at read time so imported templates work without manual editing.
     */
    private static function translateLegacyTokens(string $tpl): string
    {
        if (!str_contains($tpl, '$$')) {
            return $tpl;
        }

        $tpl = (string) preg_replace(
            '/<img\b[^>]*?src=["\'][^"\']*\$\$aff_image\$\$[^"\']*["\'][^>]*?\/?>/is',
            '{if_aff_image}<img src="{aff_image_url}" alt="{aff_name}">{/if_aff_image}{if_no_aff_image}{aff_name}{/if_no_aff_image}',
            $tpl,
        );

        return str_replace(
            [
                '$$stat_opened$$',
                '$$stat_updated$$',
                '$$stat_lastupdated$$',
                '$$stat_approved$$',
                '$$stat_pending$$',
                '$$stat_newmembers$$',
                '$$stat_growth$$',
                '$$fan_email_generic$$',
                '$$fan_url_generic$$',
                '$$fan_name$$',
                '$$fan_email$$',
                '$$fan_url$$',
                '$$fan_country$$',
                '$$aff_name$$',
                '$$aff_url$$',
                '$$aff_image$$',
                '$$fanlisting_title$$',
                '$$fanlisting_url$$',
            ],
            [
                '{stat_opened}',
                '{stat_updated}',
                '{stat_updated}',
                '{stat_approved}',
                '{stat_pending}',
                '{stat_newmembers}',
                '{stat_growth}',
                '{fan_email}',
                '{fan_url}',
                '{fan_name}',
                '{fan_email}',
                '{fan_url}',
                '{fan_country}',
                '{aff_name}',
                '{aff_url}',
                '{if_aff_image}<img src="{aff_image_url}" alt="{aff_name}">{/if_aff_image}{if_no_aff_image}{aff_name}{/if_no_aff_image}',
                '{listing_title}',
                '{listing_url}',
            ],
            $tpl,
        );
    }

    /**
     * Saves (upserts) all per-listing template overrides for a listing.
     * Pass null to clear an override. Tries the widest column set first and
     * falls back gracefully when an older migration is absent.
     */
    public function saveTemplates(
        int $listingId,
        ?string $statsTemplate,
        ?string $membersTemplate,
        ?string $affiliatesSectionTemplate = null,
        ?string $updatesTemplate = null,
        ?string $codesSubmitFormTemplate = null,
        ?string $rulesIntroTemplate = null,
        ?string $joinFormTemplate = null,
        ?string $affiliateApplyFormTemplate = null,
        ?string $adoptFormTemplate = null,
        ?string $contactFormTemplate = null,
        ?string $updateFormTemplate = null,
        ?string $resetFormTemplate = null,
    ): void {
        $attempts = [
            [
                'sql' => 'INSERT INTO lstr_fanlisting_settings
                     (fanlisting_id, stats_template, members_template, affiliates_section_template,
                      updates_template, codes_submit_form_template,
                      rules_intro_template, join_form_template, affiliate_apply_form_template,
                      adopt_form_template, contact_form_template, update_form_template, reset_form_template)
                 VALUES (:id, :stats, :members, :section, :updates, :codes_form, :rules,
                         :join_form, :aff_apply_form, :adopt_form, :contact_form, :update_form, :reset_form)
                 ON DUPLICATE KEY UPDATE
                     stats_template                = VALUES(stats_template),
                     members_template              = VALUES(members_template),
                     affiliates_section_template   = VALUES(affiliates_section_template),
                     updates_template              = VALUES(updates_template),
                     codes_submit_form_template    = VALUES(codes_submit_form_template),
                     rules_intro_template          = VALUES(rules_intro_template),
                     join_form_template            = VALUES(join_form_template),
                     affiliate_apply_form_template = VALUES(affiliate_apply_form_template),
                     adopt_form_template           = VALUES(adopt_form_template),
                     contact_form_template         = VALUES(contact_form_template),
                     update_form_template          = VALUES(update_form_template),
                     reset_form_template           = VALUES(reset_form_template)',
                'bind' => ['id' => $listingId, 'stats' => $statsTemplate, 'members' => $membersTemplate,
                           'section' => $affiliatesSectionTemplate, 'updates' => $updatesTemplate,
                           'codes_form' => $codesSubmitFormTemplate, 'rules' => $rulesIntroTemplate,
                           'join_form' => $joinFormTemplate, 'aff_apply_form' => $affiliateApplyFormTemplate,
                           'adopt_form' => $adoptFormTemplate, 'contact_form' => $contactFormTemplate,
                           'update_form' => $updateFormTemplate, 'reset_form' => $resetFormTemplate],
            ],
            [
                'sql' => 'INSERT INTO lstr_fanlisting_settings
                     (fanlisting_id, stats_template, members_template, affiliates_section_template,
                      updates_template, codes_submit_form_template,
                      rules_intro_template, join_form_template, affiliate_apply_form_template,
                      adopt_form_template, contact_form_template)
                 VALUES (:id, :stats, :members, :section, :updates, :codes_form, :rules,
                         :join_form, :aff_apply_form, :adopt_form, :contact_form)
                 ON DUPLICATE KEY UPDATE
                     stats_template                = VALUES(stats_template),
                     members_template              = VALUES(members_template),
                     affiliates_section_template   = VALUES(affiliates_section_template),
                     updates_template              = VALUES(updates_template),
                     codes_submit_form_template    = VALUES(codes_submit_form_template),
                     rules_intro_template          = VALUES(rules_intro_template),
                     join_form_template            = VALUES(join_form_template),
                     affiliate_apply_form_template = VALUES(affiliate_apply_form_template),
                     adopt_form_template           = VALUES(adopt_form_template),
                     contact_form_template         = VALUES(contact_form_template)',
                'bind' => ['id' => $listingId, 'stats' => $statsTemplate, 'members' => $membersTemplate,
                           'section' => $affiliatesSectionTemplate, 'updates' => $updatesTemplate,
                           'codes_form' => $codesSubmitFormTemplate, 'rules' => $rulesIntroTemplate,
                           'join_form' => $joinFormTemplate, 'aff_apply_form' => $affiliateApplyFormTemplate,
                           'adopt_form' => $adoptFormTemplate, 'contact_form' => $contactFormTemplate],
            ],
            [
                'sql' => 'INSERT INTO lstr_fanlisting_settings
                     (fanlisting_id, stats_template, members_template, affiliates_section_template,
                      updates_template, codes_submit_form_template,
                      rules_intro_template, join_form_template, affiliate_apply_form_template, adopt_form_template)
                 VALUES (:id, :stats, :members, :section, :updates, :codes_form, :rules, :join_form, :aff_apply_form, :adopt_form)
                 ON DUPLICATE KEY UPDATE
                     stats_template                = VALUES(stats_template),
                     members_template              = VALUES(members_template),
                     affiliates_section_template   = VALUES(affiliates_section_template),
                     updates_template              = VALUES(updates_template),
                     codes_submit_form_template    = VALUES(codes_submit_form_template),
                     rules_intro_template          = VALUES(rules_intro_template),
                     join_form_template            = VALUES(join_form_template),
                     affiliate_apply_form_template = VALUES(affiliate_apply_form_template),
                     adopt_form_template           = VALUES(adopt_form_template)',
                'bind' => ['id' => $listingId, 'stats' => $statsTemplate, 'members' => $membersTemplate,
                           'section' => $affiliatesSectionTemplate, 'updates' => $updatesTemplate,
                           'codes_form' => $codesSubmitFormTemplate, 'rules' => $rulesIntroTemplate,
                           'join_form' => $joinFormTemplate, 'aff_apply_form' => $affiliateApplyFormTemplate,
                           'adopt_form' => $adoptFormTemplate],
            ],
            [
                'sql' => 'INSERT INTO lstr_fanlisting_settings
                     (fanlisting_id, stats_template, members_template, affiliates_section_template,
                      updates_template, rules_intro_template)
                 VALUES (:id, :stats, :members, :section, :updates, :rules)
                 ON DUPLICATE KEY UPDATE
                     stats_template              = VALUES(stats_template),
                     members_template            = VALUES(members_template),
                     affiliates_section_template = VALUES(affiliates_section_template),
                     updates_template            = VALUES(updates_template),
                     rules_intro_template        = VALUES(rules_intro_template)',
                'bind' => ['id' => $listingId, 'stats' => $statsTemplate, 'members' => $membersTemplate,
                           'section' => $affiliatesSectionTemplate, 'updates' => $updatesTemplate,
                           'rules' => $rulesIntroTemplate],
            ],
            [
                'sql' => 'INSERT INTO lstr_fanlisting_settings
                     (fanlisting_id, stats_template, members_template, affiliates_section_template, updates_template)
                 VALUES (:id, :stats, :members, :section, :updates)
                 ON DUPLICATE KEY UPDATE
                     stats_template              = VALUES(stats_template),
                     members_template            = VALUES(members_template),
                     affiliates_section_template = VALUES(affiliates_section_template),
                     updates_template            = VALUES(updates_template)',
                'bind' => ['id' => $listingId, 'stats' => $statsTemplate, 'members' => $membersTemplate,
                           'section' => $affiliatesSectionTemplate, 'updates' => $updatesTemplate],
            ],
            [
                'sql' => 'INSERT INTO lstr_fanlisting_settings
                     (fanlisting_id, stats_template, members_template, affiliates_section_template)
                 VALUES (:id, :stats, :members, :section)
                 ON DUPLICATE KEY UPDATE
                     stats_template              = VALUES(stats_template),
                     members_template            = VALUES(members_template),
                     affiliates_section_template = VALUES(affiliates_section_template)',
                'bind' => ['id' => $listingId, 'stats' => $statsTemplate, 'members' => $membersTemplate,
                           'section' => $affiliatesSectionTemplate],
            ],
            [
                'sql' => 'INSERT INTO lstr_fanlisting_settings (fanlisting_id, stats_template, members_template)
                 VALUES (:id, :stats, :members)
                 ON DUPLICATE KEY UPDATE
                     stats_template   = VALUES(stats_template),
                     members_template = VALUES(members_template)',
                'bind' => ['id' => $listingId, 'stats' => $statsTemplate, 'members' => $membersTemplate],
            ],
        ];

        foreach ($attempts as $attempt) {
            try {
                $stmt = $this->db->prepare($attempt['sql']);
                $stmt->execute($attempt['bind']);
                return;
            } catch (\Exception) {
                // column absent — try narrower set
            }
        }
    }

    /**
     * Returns the stored image filename for a listing, or null if none set.
     */
    public function getImage(int $listingId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT image FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
        );
        $stmt->execute(['id' => $listingId]);
        $row = $stmt->fetch();

        if ($row === false || $row['image'] === null || $row['image'] === '') {
            return null;
        }

        return (string) $row['image'];
    }

    /**
     * Saves (upserts) the image filename for a listing.
     *
     * Does not touch any other columns on existing rows.
     */
    public function saveImage(int $listingId, string $filename): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, image)
             VALUES (:id, :image)
             ON DUPLICATE KEY UPDATE image = VALUES(image)'
        );
        $stmt->execute([
            'id'    => $listingId,
            'image' => $filename,
        ]);
    }

    /**
     * Batch-fetches per-listing render settings (image, last_updated,
     * approved_by, description) for owned/random/latest listing row templates.
     *
     * Falls back to a query without `description` if that column doesn't exist
     * yet (Migration 74 not yet run), so image/last_updated/approved_by are not
     * lost on older schemas.
     *
     * @param int[] $listingIds
     * @return array<int, array<string, mixed>> [fanlisting_id => settings row]
     */
    public function getRenderSettingsBatch(array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }

        $phs = implode(',', array_fill(0, count($listingIds), '?'));
        $map = [];

        try {
            $stmt = $this->db->prepare(
                "SELECT fanlisting_id, image, last_updated, approved_by, description
                 FROM lstr_fanlisting_settings WHERE fanlisting_id IN ({$phs})"
            );
            $stmt->execute(array_values($listingIds));
            foreach ($stmt->fetchAll() as $row) {
                $map[(int) $row['fanlisting_id']] = $row;
            }
            return $map;
        } catch (\Exception) {}

        try {
            $stmt = $this->db->prepare(
                "SELECT fanlisting_id, image, last_updated, approved_by
                 FROM lstr_fanlisting_settings WHERE fanlisting_id IN ({$phs})"
            );
            $stmt->execute(array_values($listingIds));
            foreach ($stmt->fetchAll() as $row) {
                $map[(int) $row['fanlisting_id']] = $row;
            }
        } catch (\Exception) {}

        return $map;
    }

    /**
     * Single-listing convenience wrapper around getRenderSettingsBatch().
     *
     * @return array<string, mixed>
     */
    public function getRenderSettings(int $listingId): array
    {
        return $this->getRenderSettingsBatch([$listingId])[$listingId] ?? [];
    }

    /**
     * Returns the listing type for a listing.
     * Defaults to 'listing' if no settings row exists or the value is null.
     */
    public function getListingType(int $listingId): string
    {
        $stmt = $this->db->prepare(
            'SELECT listing_type FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
        );
        $stmt->execute(['id' => $listingId]);
        $row = $stmt->fetch();

        if ($row === false || $row['listing_type'] === null || $row['listing_type'] === '') {
            return 'listing';
        }

        return (string) $row['listing_type'];
    }

    /**
     * Saves (upserts) the listing type for a listing.
     *
     * Does not touch any other columns on existing rows.
     */
    public function saveListingType(int $listingId, string $type): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, listing_type)
             VALUES (:id, :type)
             ON DUPLICATE KEY UPDATE listing_type = VALUES(listing_type)'
        );
        $stmt->execute([
            'id'   => $listingId,
            'type' => $type,
        ]);
    }

    /**
     * Returns the per-page member count override for a listing.
     * Defaults to 25 if no settings row exists or the value is null.
     */
    public function getMembersPerPage(int $listingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT members_per_page FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
        );
        $stmt->execute(['id' => $listingId]);
        $row = $stmt->fetch();

        if ($row === false || $row['members_per_page'] === null) {
            return 25;
        }

        return (int) $row['members_per_page'];
    }

    /**
     * Saves (upserts) the per-page member count override for a listing.
     *
     * Does not touch any other columns on existing rows.
     */
    public function saveMembersPerPage(int $listingId, int $perPage): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, members_per_page)
             VALUES (:id, :per_page)
             ON DUPLICATE KEY UPDATE members_per_page = VALUES(members_per_page)'
        );
        $stmt->execute([
            'id'       => $listingId,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Returns contact form settings for a listing.
     *
     * Requires Migration 37 (contact_enabled, contact_recipient)
     * and Migration 75 (form_url_contact). Returns safe defaults when
     * columns are absent.
     *
     * @return array{enabled: bool, recipient: string|null, form_url_contact: string}
     */
    public function getContactSettings(int $listingId): array
    {
        $defaults = ['enabled' => true, 'recipient' => null, 'form_url_contact' => ''];

        try {
            $stmt = $this->db->prepare(
                'SELECT contact_enabled, contact_recipient, form_url_contact
                 FROM lstr_fanlisting_settings
                 WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            try {
                // Migration 75 not yet run — fall back to 2-column query
                $stmt = $this->db->prepare(
                    'SELECT contact_enabled, contact_recipient
                     FROM lstr_fanlisting_settings
                     WHERE fanlisting_id = :id'
                );
                $stmt->execute(['id' => $listingId]);
                $row = $stmt->fetch();
            } catch (\Exception) {
                return $defaults;
            }
        }

        if ($row === false) {
            return $defaults;
        }

        return [
            'enabled'          => (bool) $row['contact_enabled'],
            'recipient'        => $row['contact_recipient'] !== null && $row['contact_recipient'] !== ''
                ? (string) $row['contact_recipient']
                : null,
            'form_url_contact' => (string) ($row['form_url_contact'] ?? ''),
        ];
    }

    /**
     * Saves (upserts) the contact form URL path for a listing.
     */
    public function saveFormUrlContact(int $listingId, string $url): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, form_url_contact)
                 VALUES (:id, :url)
                 ON DUPLICATE KEY UPDATE form_url_contact = VALUES(form_url_contact)'
            );
            $stmt->execute([
                'id'  => $listingId,
                'url' => $url,
            ]);
        } catch (\Exception) {
        }
    }

    /**
     * Saves (upserts) contact form settings for a listing.
     *
     * Requires Migration 37.
     */
    public function saveContactSettings(int $listingId, bool $enabled, ?string $recipient): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, contact_enabled, contact_recipient)
                 VALUES (:id, :enabled, :recipient)
                 ON DUPLICATE KEY UPDATE
                     contact_enabled   = VALUES(contact_enabled),
                     contact_recipient = VALUES(contact_recipient)'
            );
            $stmt->execute([
                'id'        => $listingId,
                'enabled'   => (int) $enabled,
                'recipient' => ($recipient !== null && $recipient !== '') ? $recipient : null,
            ]);
        } catch (\Exception) {
            // Migration 37 not run yet
        }
    }

    /**
     * Returns the free-text description for a listing.
     * Returns '' if the row doesn't exist, the value is null, or Migration 74 hasn't run.
     *
     * Requires Migration 74.
     */
    public function getDescription(int $listingId): string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT description FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return '';
        }

        if ($row === false || $row['description'] === null) {
            return '';
        }

        return (string) $row['description'];
    }

    /**
     * Saves (upserts) the free-text description for a listing.
     *
     * Requires Migration 74.
     */
    public function saveDescription(int $listingId, string $description): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, description)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE description = VALUES(description)'
            );
            $stmt->execute(['id' => $listingId, 'val' => $description !== '' ? $description : null]);
        } catch (\Exception) {
            // Migration 74 not run yet
        }
    }

    /**
     * Returns the TFL/TAFL approval status for a listing.
     * Returns '' (not approved/N/A), 'tfl', or 'tafl'.
     * Defaults to '' if the row doesn't exist or Migration 39 hasn't run.
     *
     * Requires Migration 39.
     */
    public function getApprovedBy(int $listingId): string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT approved_by FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return '';
        }

        if ($row === false || !in_array($row['approved_by'], ['tfl', 'tafl'], true)) {
            return '';
        }

        return (string) $row['approved_by'];
    }

    /**
     * Saves (upserts) the TFL/TAFL approval status for a listing.
     * Accepts '' (none), 'tfl', or 'tafl'; anything else is coerced to ''.
     *
     * Requires Migration 39.
     */
    public function saveApprovedBy(int $listingId, string $value): void
    {
        if (!in_array($value, ['tfl', 'tafl'], true)) {
            $value = '';
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, approved_by)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE approved_by = VALUES(approved_by)'
            );
            $stmt->execute(['id' => $listingId, 'val' => $value]);
        } catch (\Exception) {
            // Migration 39 not run yet
        }
    }

    /**
     * Returns a map of [listingId => 'tfl'|'tafl'] for all listings that
     * have a settings row. Used by the listing list to show approval badges
     * without N+1 queries.
     *
     * Requires Migration 39.
     *
     * @return array<int, string>
     */
    public function getAllApprovedBy(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT fanlisting_id, approved_by FROM lstr_fanlisting_settings
                  WHERE approved_by IN ('tfl', 'tafl')"
            );
            $stmt->execute();
            $result = [];
            foreach ($stmt->fetchAll() as $row) {
                $result[(int) $row['fanlisting_id']] = (string) $row['approved_by'];
            }
            return $result;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Returns per-listing join settings.
     *
     * confirmation_email: null = inherit (follow fan's send_info preference),
     *                     true = always send optional confirmation,
     *                     false = suppress optional confirmation.
     * auto_approve: false = manual approval required (default), true = auto-approve.
     *
     * Requires Migration 50.
     *
     * @return array{confirmation_email: bool|null, auto_approve: bool}
     */
    public function getJoinSettings(int $listingId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT join_confirmation_email, auto_approve_joins
                 FROM lstr_fanlisting_settings
                 WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return ['confirmation_email' => null, 'auto_approve' => false];
        }

        if ($row === false) {
            return ['confirmation_email' => null, 'auto_approve' => false];
        }

        $confirmEmail = $row['join_confirmation_email'] !== null
            ? (bool) $row['join_confirmation_email']
            : null;

        return [
            'confirmation_email' => $confirmEmail,
            'auto_approve'       => (bool) $row['auto_approve_joins'],
        ];
    }

    /**
     * Saves (upserts) per-listing join settings.
     *
     * Pass null for $confirmEmail to inherit the fan's send_info preference.
     * Does not touch other columns on existing rows.
     *
     * Requires Migration 50.
     */
    public function saveJoinSettings(int $listingId, ?bool $confirmEmail, bool $autoApprove): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_confirmation_email, auto_approve_joins)
                 VALUES (:id, :confirm, :auto)
                 ON DUPLICATE KEY UPDATE
                     join_confirmation_email = VALUES(join_confirmation_email),
                     auto_approve_joins      = VALUES(auto_approve_joins)'
            );
            $stmt->execute([
                'id'      => $listingId,
                'confirm' => $confirmEmail !== null ? (int) $confirmEmail : null,
                'auto'    => (int) $autoApprove,
            ]);
        } catch (\Exception) {
            // Migration 50 not run yet
        }
    }

    /**
     * Returns embed-related settings for a listing.
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
     *   form_url_join:          string,
     *   form_url_update:        string,
     *   form_url_reset:         string,
     *   form_url_codes_submit:  string,
     *   db_host:                string,
     *   db_name:                string,
     *   db_user:                string,
     *   db_pass:                string,
     * }
     */
    public function getEmbedSettings(int $listingId): array
    {
        $defaults = [
            'form_url_join'         => '',
            'form_url_update'       => '',
            'form_url_reset'        => '',
            'form_url_codes_submit' => '',
            'codes_submit_enabled'  => true,
            'db_host'               => '',
            'db_name'               => '',
            'db_user'               => '',
            'db_pass'               => '',
            'country_field_enabled' => true,
        ];

        try {
            $stmt = $this->db->prepare(
                'SELECT form_url_join, form_url_update,
                        form_url_reset,
                        form_url_codes_submit, codes_submit_enabled,
                        db_host, db_name, db_user, db_pass,
                        COALESCE(country_field_enabled, 1) AS country_field_enabled
                 FROM lstr_fanlisting_settings
                 WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return $defaults;
        }

        if ($row === false) {
            return $defaults;
        }

        return [
            'form_url_join'         => (string) ($row['form_url_join']         ?? ''),
            'form_url_update'       => (string) ($row['form_url_update']       ?? ''),
            'form_url_reset'        => (string) ($row['form_url_reset']        ?? ''),
            'form_url_codes_submit' => (string) ($row['form_url_codes_submit'] ?? ''),
            'codes_submit_enabled'  => isset($row['codes_submit_enabled']) ? (bool) $row['codes_submit_enabled'] : true,
            'db_host'               => (string) ($row['db_host']               ?? ''),
            'db_name'               => (string) ($row['db_name']               ?? ''),
            'db_user'               => (string) ($row['db_user']               ?? ''),
            'db_pass'               => (string) ($row['db_pass']               ?? ''),
            'country_field_enabled' => (bool) ($row['country_field_enabled']   ?? true),
        ];
    }

    /**
     * Saves (upserts) embed-related settings for a listing.
     *
     * Pass an empty string for $dbPass to keep the existing stored password
     * (the controller must do this check before calling).
     *
     * Requires Migration 17 (redirect URLs, DB override) and Migration 19
     * (form URLs + redirect_url_reset).
     */
    public function saveEmbedSettings(
        int    $listingId,
        string $formUrlJoin,
        string $formUrlUpdate,
        string $formUrlReset,
        string $formUrlCodesSubmit,
        bool   $codesSubmitEnabled,
        string $dbHost,
        string $dbName,
        string $dbUser,
        string $dbPass,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_fanlisting_settings
                 (fanlisting_id,
                  form_url_join, form_url_update,
                  form_url_reset,
                  form_url_codes_submit, codes_submit_enabled,
                  db_host, db_name, db_user, db_pass)
             VALUES
                 (:id,
                  :form_join, :form_update, :form_reset,
                  :form_codes_submit, :codes_submit_enabled,
                  :host, :dbname, :user, :pass)
             ON DUPLICATE KEY UPDATE
                 form_url_join          = VALUES(form_url_join),
                 form_url_update        = VALUES(form_url_update),
                 form_url_reset         = VALUES(form_url_reset),
                 form_url_codes_submit  = VALUES(form_url_codes_submit),
                 codes_submit_enabled   = VALUES(codes_submit_enabled),
                 db_host                = VALUES(db_host),
                 db_name                = VALUES(db_name),
                 db_user                = VALUES(db_user),
                 db_pass                = VALUES(db_pass)'
        );
        $stmt->execute([
            'id'                   => $listingId,
            'form_join'            => $formUrlJoin,
            'form_update'          => $formUrlUpdate,
            'form_reset'           => $formUrlReset,
            'form_codes_submit'    => $formUrlCodesSubmit,
            'codes_submit_enabled' => (int) $codesSubmitEnabled,
            'host'                 => $dbHost,
            'dbname'               => $dbName,
            'user'                 => $dbUser,
            'pass'                 => $dbPass,
        ]);
    }

    /**
     * Returns whether a listing is marked available for adoption.
     * Defaults to false if the row doesn't exist or Migration 40 hasn't run.
     *
     * Requires Migration 40.
     */
    public function getForAdoption(int $listingId): bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT for_adoption FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return false;
        }

        return $row !== false && (bool) $row['for_adoption'];
    }

    /**
     * Saves (upserts) the for_adoption flag for a listing.
     *
     * Requires Migration 40.
     */
    public function saveForAdoption(int $listingId, bool $value): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, for_adoption)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE for_adoption = VALUES(for_adoption)'
            );
            $stmt->execute(['id' => $listingId, 'val' => (int) $value]);
        } catch (\Exception) {
            // Migration 40 not run yet
        }
    }

    /**
     * Returns a map of [listingId => bool] for all listings that have for_adoption = 1.
     * Used to drive the Adoptions nav badge and listing list indicators.
     *
     * Requires Migration 40.
     *
     * @return int[]  Listing IDs marked for adoption
     */
    public function getAdoptionListingIds(): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT fanlisting_id FROM lstr_fanlisting_settings WHERE for_adoption = 1'
            );
            $stmt->execute();
            return array_map(static fn(array $r) => (int) $r['fanlisting_id'], $stmt->fetchAll());
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Returns the affiliate application settings for a listing.
     * Defaults to disabled with empty URL overrides if the row doesn't exist
     * or Migration 41 hasn't run.
     *
     * Requires Migration 41.
     *
     * @return array{enabled: bool, form_url: string, redirect_url: string}
     */
    public function getAffiliateApplySettings(int $listingId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT affiliate_apply_enabled, form_url_affiliate_apply, redirect_url_affiliate_apply
                 FROM lstr_fanlisting_settings
                 WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return ['enabled' => false, 'form_url' => '', 'redirect_url' => ''];
        }

        if ($row === false) {
            return ['enabled' => false, 'form_url' => '', 'redirect_url' => ''];
        }

        return [
            'enabled'      => (bool) $row['affiliate_apply_enabled'],
            'form_url'     => (string) ($row['form_url_affiliate_apply']     ?? ''),
            'redirect_url' => (string) ($row['redirect_url_affiliate_apply'] ?? ''),
        ];
    }

    /**
     * Returns whether to send the code-approval email for this listing.
     * NULL = inherit global setting, true = always send, false = never send.
     *
     * @return bool|null
     */
    public function getCodeApprovalEmail(int $listingId): ?bool
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT code_approval_email FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if ($row === false || !array_key_exists('code_approval_email', $row)) {
            return null;
        }
        return $row['code_approval_email'] === null ? null : (bool) $row['code_approval_email'];
    }

    /**
     * Saves the per-listing code-approval email override.
     * Pass null to inherit the global setting.
     */
    public function setCodeApprovalEmail(int $listingId, ?bool $value): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, code_approval_email)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE code_approval_email = VALUES(code_approval_email)'
            );
            $stmt->execute(['id' => $listingId, 'val' => $value === null ? null : (int) $value]);
        } catch (\PDOException) {}
    }

    /**
     * Saves (upserts) affiliate application settings for a listing.
     *
     * Requires Migration 41.
     */
    public function saveAffiliateApplySettings(int $listingId, bool $enabled, string $formUrl, string $redirectUrl): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings
                    (fanlisting_id, affiliate_apply_enabled, form_url_affiliate_apply, redirect_url_affiliate_apply)
                 VALUES
                    (:id, :enabled, :form_url, :redirect_url)
                 ON DUPLICATE KEY UPDATE
                    affiliate_apply_enabled      = VALUES(affiliate_apply_enabled),
                    form_url_affiliate_apply     = VALUES(form_url_affiliate_apply),
                    redirect_url_affiliate_apply = VALUES(redirect_url_affiliate_apply)'
            );
            $stmt->execute([
                'id'           => $listingId,
                'enabled'      => (int) $enabled,
                'form_url'     => $formUrl,
                'redirect_url' => $redirectUrl,
            ]);
        } catch (\Exception) {
            // Migration 41 not run yet
        }
    }

    // -----------------------------------------------------------------------
    // Newest-members display limit (Migration 80)
    // -----------------------------------------------------------------------

    /**
     * Returns the per-listing newest-members limit for {stat_newmembers}.
     * NULL = inherit global default; 0 = show all; 1–255 = explicit cap.
     */
    public function getStatNewmembersLimit(int $listingId): ?int
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT stat_newmembers_limit FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if ($row === false || !array_key_exists('stat_newmembers_limit', $row)) {
            return null;
        }
        return $row['stat_newmembers_limit'] === null ? null : (int) $row['stat_newmembers_limit'];
    }

    /**
     * Saves the per-listing newest-members limit.
     * Pass null to inherit the global default.
     */
    public function setStatNewmembersLimit(int $listingId, ?int $value): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, stat_newmembers_limit)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE stat_newmembers_limit = VALUES(stat_newmembers_limit)'
            );
            $stmt->execute(['id' => $listingId, 'val' => $value]);
        } catch (\PDOException) {}
    }

    // -----------------------------------------------------------------------
    // Affiliate application toggle style (Migration 81)
    // -----------------------------------------------------------------------

    /**
     * Returns the affiliate-apply toggle style for this listing: 'link' or 'button'.
     */
    public function getAffiliateApplyStyle(int $listingId): string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT affiliate_apply_style FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return 'link';
        }
        if ($row === false || !array_key_exists('affiliate_apply_style', $row)) {
            return 'link';
        }
        $val = (string) $row['affiliate_apply_style'];
        return in_array($val, ['link', 'button'], true) ? $val : 'link';
    }

    /**
     * Saves the affiliate-apply toggle style ('link' or 'button').
     */
    public function setAffiliateApplyStyle(int $listingId, string $style): void
    {
        $style = in_array($style, ['link', 'button'], true) ? $style : 'link';
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, affiliate_apply_style)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE affiliate_apply_style = VALUES(affiliate_apply_style)'
            );
            $stmt->execute(['id' => $listingId, 'val' => $style]);
        } catch (\PDOException) {}
    }
}
