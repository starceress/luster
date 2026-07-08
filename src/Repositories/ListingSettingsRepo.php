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
     * @return array{stats_template: string|null, members_template: string|null, affiliates_section_template: string|null, newsfeed_template: string|null, codes_submit_form_template: string|null, join_form_template: string|null, affiliate_apply_form_template: string|null, adopt_form_template: string|null, contact_form_template: string|null, contact_sent_template: string|null, update_form_template: string|null, reset_form_template: string|null}
     */
    public function getTemplates(int $listingId): array
    {
        $defaults = [
            'stats_template'                => null,
            'members_template'              => null,
            'affiliates_section_template'   => null,
            'newsfeed_template'              => null,
            'codes_submit_form_template'    => null,
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
            'stats_template, members_template, affiliates_section_template, newsfeed_template, codes_submit_form_template, join_form_template, affiliate_apply_form_template, adopt_form_template, contact_form_template, contact_sent_template, update_form_template, reset_form_template',
            'stats_template, members_template, affiliates_section_template, newsfeed_template, codes_submit_form_template, join_form_template, affiliate_apply_form_template, adopt_form_template, contact_form_template, update_form_template, reset_form_template',
            'stats_template, members_template, affiliates_section_template, newsfeed_template, codes_submit_form_template, join_form_template, affiliate_apply_form_template, adopt_form_template',
            'stats_template, members_template, affiliates_section_template, newsfeed_template',
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
            'newsfeed_template'              => $col($row, 'newsfeed_template'),
            'codes_submit_form_template'    => $col($row, 'codes_submit_form_template'),
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
     * Returns the current value of a single template column for a listing, using getTemplates().
     * Returns null when the column is absent or the value is null/unset.
     */
    public function getTemplateColumn(int $listingId, string $column): ?string
    {
        return $this->getTemplates($listingId)[$column] ?? null;
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
     * Returns the per-listing newsfeed row template string, or null when not set /
     * when Migration 64 has not been run yet.
     */
    public function getNewsfeedTemplate(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT newsfeed_template FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
            return ($row !== false && $row['newsfeed_template'] !== null)
                ? (string) $row['newsfeed_template']
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
    /**
     * Default Update Membership Form template used when no override is stored.
     *
     * Covers only the sign-in step (email + password to reach the editable
     * form) — the actual edit-info step is not template-controlled, so a
     * misconfigured custom template can never lock a member out of editing or
     * deleting their membership. See TemplateEngine/FormTemplateRenderer call
     * sites in public/embed/update.php: the signed-in branch always renders
     * the built-in hardcoded markup regardless of what's saved here.
     */
    public static function defaultUpdateFormTemplate(): string
    {
        return '<h2>Update Your Information for {listing_subject}</h2>' . "\n"
            . '<p>Enter your email address and password to continue.</p>' . "\n"
            . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_signin_email}' . "\n"
            . '{field_signin_password}' . "\n"
            . '</div>' . "\n"
            . '<p><a href="{lost_password_url}" class="fl-link">Forgot your password?</a></p>' . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '</div>';
    }

    /**
     * Default Password Reset Form template used when no override is stored.
     *
     * Covers only the request step (email address to receive the reset link)
     * — the "set new password" step is not template-controlled, so a
     * misconfigured custom template can never lock a member out of actually
     * resetting their password. See public/embed/reset.php: the confirm-step
     * branch always renders the built-in hardcoded markup regardless of what's
     * saved here.
     */
    public static function defaultResetFormTemplate(): string
    {
        return '<h2>Reset Password for {listing_subject}</h2>' . "\n"
            . '<p>Enter your email address and a link will be sent to reset your password. The link expires in 1 hour.</p>' . "\n"
            . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_email}' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '</div>';
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
     * Default members row template for the "List" display style. Not the
     * built-in default when nothing is customized — see defaultMembersTemplate()
     * for that (the "Table" style is the actual default). Used as the preset
     * content for the List display-style button in the per-listing and global
     * template editors.
     */
    public static function defaultMembersListTemplate(): string
    {
        return '{if_show_search}' . "\n"
            . '<div class="fl-member-controls">' . "\n"
            . '<p class="fl-member-sort">Sort by: {sort_link_date} | {sort_link_name} | {sort_link_country}</p>' . "\n"
            . '</div>' . "\n"
            . '{/if_show_search}' . "\n"
            . "\n"
            . '<p class="fl-member-stats">{stats_line}</p>' . "\n"
            . "\n"
            . '{if_members}' . "\n"
            . '<ul class="fl-member-list">' . "\n"
            . '{each_member}' . "\n"
            . '<li class="fl-member-item">{if_fan_url}<a class="fl-member-name" href="{fan_url}">{fan_name}</a>{/if_fan_url}{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url}{if_fan_country} &mdash; <span class="fl-member-country">{fan_country}</span>{/if_fan_country}{if_sort_date}, Joined <span class="fl-member-added">{fan_added}</span>{/if_sort_date}{if_fan_additional_fields}, {fan_additional_fields}{/if_fan_additional_fields}</li>' . "\n"
            . '{/each_member}' . "\n"
            . '</ul>' . "\n"
            . '{/if_members}' . "\n"
            . "\n"
            . '{if_no_members}' . "\n"
            . '<p class="fl-empty">No members to display yet.</p>' . "\n"
            . '{/if_no_members}';
    }

    /**
     * True when $v matches one of the members-template shapes that shipped as
     * the real built-in default at some point before the current one. Treating
     * these as "absent" lets a stored value that's really just an old built-in
     * default keep tracking code default changes instead of getting stuck on a
     * frozen snapshot -- and lets callers that display or badge this template
     * agree on whether it counts as a customisation.
     *
     * Shared by the per-listing (templates/admin/listings/edit.php) and global
     * (templates/admin/listings/global-templates.php) template editors, and by
     * ListingController's badge computation for both -- the exact same check
     * must gate both the displayed value and the Custom/Built-in badge, or the
     * two can disagree.
     */
    public static function isLegacyMembersTemplate(?string $v): bool
    {
        if ($v === null || trim($v) === '') {
            return false;
        }

        // The very first built-in default (exact match only, same reasoning as the
        // second legacy default below): no <h1>, no sort bar, no {if_members} wrap
        // at all — just an unconditional list.
        $originalMembersDefault = '<div class="fl-member-list">'
            . "\n" . '{each_member}'
            . "\n" . '<div class="fl-member-item">'
            . "\n" . '  {if_fan_url}<a class="fl-member-name" href="{fan_url}" rel="noopener noreferrer">{fan_name}</a>{/if_fan_url}{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url}'
            . "\n" . '  {if_fan_country}<span class="fl-member-country">{fan_country}</span>{/if_fan_country}'
            . "\n" . '</div>'
            . "\n" . '{/each_member}'
            . "\n" . '</div>';
        if (trim($v) === trim($originalMembersDefault)) {
            return true;
        }

        // The two commit-e1219457 shapes: first added <h1> + sort bar + {stats_line}
        // + {if_members} wrap (with each row in its own <ul class="fl-member-item">),
        // then a follow-up commit dropped {stats_line} again a few commits later.
        // Both are exact-matched since they briefly shipped as the real built-in
        // default before the layout settled into the shape below.
        $statsLineMembersDefault = '<h1>Member List</h1>'
            . "\n" . '<p class="fl-member-sort">Sort by: {sort_link_date} | {sort_link_name} | {sort_link_country}</p><br>'
            . "\n" . '<p class="fl-member-stats">{stats_line}</p>'
            . "\n"
            . "\n" . '{if_members}'
            . "\n" . '<div class="fl-member-list">'
            . "\n" . '{each_member}'
            . "\n" . '  <ul class="fl-member-item">'
            . "\n" . '    <li>{if_fan_url}<a class="fl-member-name" href="{fan_url}" rel="noopener noreferrer">{fan_name}</a>{/if_fan_url}{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url} - {if_fan_country}<span class="fl-member-country">{fan_country}</span>{/if_fan_country}</li>'
            . "\n" . '  </ul>'
            . "\n" . '{/each_member}'
            . "\n" . '</div>'
            . "\n" . '{/if_members}'
            . "\n" . '{if_no_members}'
            . "\n" . '  <p class="fl-empty">No members yet. <a href="{join_url}">Want to join?</a></p>'
            . "\n" . '{/if_no_members}';
        if (trim($v) === trim($statsLineMembersDefault)) {
            return true;
        }

        $noStatsLineMembersDefault = '<h1>Member List</h1>'
            . "\n" . '<p class="fl-member-sort">Sort by: {sort_link_date} | {sort_link_name} | {sort_link_country}</p><br>'
            . "\n"
            . "\n" . '{if_members}'
            . "\n" . '<div class="fl-member-list">'
            . "\n" . '{each_member}'
            . "\n" . '  <ul class="fl-member-item">'
            . "\n" . '    <li>{if_fan_url}<a class="fl-member-name" href="{fan_url}" rel="noopener noreferrer">{fan_name}</a>{/if_fan_url}{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url} - {if_fan_country}<span class="fl-member-country">{fan_country}</span>{/if_fan_country}</li>'
            . "\n" . '  </ul>'
            . "\n" . '{/each_member}'
            . "\n" . '</div>'
            . "\n" . '{/if_members}'
            . "\n" . '{if_no_members}'
            . "\n" . '  <p class="fl-empty">No members yet. <a href="{join_url}">Want to join?</a></p>'
            . "\n" . '{/if_no_members}';
        if (trim($v) === trim($noStatsLineMembersDefault)) {
            return true;
        }

        // Pre-show-search-conditional default (exact match only — a hand-customized
        // template that happens to share the old <h1> heading must NOT be mistaken
        // for this and get replaced): had its own <h1> heading and wrapped the list
        // in an extra <div class="fl-member-list"> instead of putting the class
        // directly on the <ul>, superseded when the sort bar became hideable via
        // {if_show_search} and the layout was aligned with the PHP fallback
        // (templates/default/members.php).
        $legacyMembersDefault = '<h1>Member List</h1>' . "\n"
            . '<p>Sort by: {sort_link_date} | {sort_link_name} | {sort_link_country}</p><br>' . "\n"
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
        if (trim($v) === trim($legacyMembersDefault)) {
            return true;
        }

        // The bullet-list shape that was itself the real built-in default right up
        // until the Table display style became the default instead. A stored value
        // that exactly matches it (e.g. from a "no-op" Save Global Defaults click
        // while List was still current) is treated the same as any other superseded
        // built-in default here, not as a genuine customisation — otherwise existing
        // installs would silently stay on the old default forever. Now lives on as
        // the "List" display-style preset (defaultMembersListTemplate()).
        if (trim($v) === trim(self::defaultMembersListTemplate())) {
            return true;
        }

        return false;
    }

    /**
     * Default members row template used when no override is stored in the database
     * — a table layout (Name / Country / additional fields, with Joined added
     * when sorted by date). Shared by templates/default/members.php (public
     * fallback) and the admin per-listing/global template editors (pre-filled
     * textarea, and the "Reset to Default"/"Table" preset content). The simpler
     * bullet-list alternative lives in defaultMembersListTemplate() (the "List"
     * preset button's content).
     */
    public static function defaultMembersTemplate(): string
    {
        return '<h1>Member List</h1>' . "\n"
            . '<p>Sort by: {sort_link_date} | {sort_link_name} | {sort_link_country}</p><br>' . "\n"
            . "\n"
            . '{if_members}' . "\n"
            . '<div class="fl-member-table">' . "\n"
            . '{if_no_country_sort}' . "\n"
            . '<div class="fl-member-table-head">' . "\n"
            . '<span class="fl-member-table-th">Name</span>' . "\n"
            . '<span class="fl-member-table-th">Country</span>' . "\n"
            . '{if_sort_date}<span class="fl-member-table-th">Joined</span>{/if_sort_date}' . "\n"
            . '{additional_field_headers}' . "\n"
            . '</div>' . "\n"
            . '{/if_no_country_sort}' . "\n"
            . "\n"
            . '{each_member}' . "\n"
            . '<div class="fl-member-table-row">' . "\n"
            . '<span class="fl-member-table-cell">{if_fan_url}<a href="{fan_url}">{fan_name}</a>{/if_fan_url}{if_no_fan_url}{fan_name}{/if_no_fan_url}</span>' . "\n"
            . '<span class="fl-member-table-cell">{if_fan_country}{fan_country}{/if_fan_country}{if_no_fan_country}&ndash;{/if_no_fan_country}</span>' . "\n"
            . '{if_sort_date}<span class="fl-member-table-cell">{fan_added}</span>{/if_sort_date}' . "\n"
            . '{fan_additional_field_cells}' . "\n"
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
            . '<div style="text-align:center">{affiliate_apply_toggle}</div>' . "\n"
            . '{affiliate_apply_form}' . "\n"
            . '</div>';
    }

    /**
     * Default News Feed template used when no override is stored in the database.
     *
     * Uses the {each_newsfeed_entry} loop with per-entry tokens: {newsfeed_title}, {newsfeed_date},
     * {newsfeed_author}, {newsfeed_categories}, {newsfeed_body_html}, and the
     * {if_author} / {if_categories} conditionals.
     */
    public static function defaultNewsfeedTemplate(): string
    {
        return '<h2>{listing_subject} News Feed</h2>' . "\n"
            . '<div class="fl-newsfeed-list">' . "\n"
            . '{if_no_newsfeed}<p>No entries yet.</p>{/if_no_newsfeed}' . "\n"
            . '{if_newsfeed}' . "\n"
            . '{each_newsfeed_entry}' . "\n"
            . "\n"
            . '<div class="fl-newsfeed-item">' . "\n"
            . '<h3 class="fl-newsfeed-title">{newsfeed_title}</h3>' . "\n"
            . '<div class="fl-newsfeed-meta">' . "\n"
            . '<span class="fl-newsfeed-date"><b>Date Posted:</b> {newsfeed_date}</span>'
            . '{if_author} by <span class="fl-newsfeed-author">{newsfeed_author}</span>{/if_author}<br>' . "\n"
            . '{if_categories}<b>Categories:</b> <span class="fl-newsfeed-categories">{newsfeed_categories}</span>{/if_categories}</div>' . "\n"
            . "\n"
            . '<div class="fl-newsfeed-body">{newsfeed_body_html}</div>' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '{/each_newsfeed_entry}' . "\n"
            . '{/if_newsfeed}' . "\n"
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
            . '{/if_codes}' . "\n"
            . '<div style="text-align:center">{codes_submit_toggle}</div>' . "\n"
            . '{codes_submit_form}';
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
                      newsfeed_template, codes_submit_form_template,
                      join_form_template, affiliate_apply_form_template,
                      adopt_form_template, contact_form_template, update_form_template, reset_form_template)
                 VALUES (:id, :stats, :members, :section, :updates, :codes_form,
                         :join_form, :aff_apply_form, :adopt_form, :contact_form, :update_form, :reset_form)
                 ON DUPLICATE KEY UPDATE
                     stats_template                = VALUES(stats_template),
                     members_template              = VALUES(members_template),
                     affiliates_section_template   = VALUES(affiliates_section_template),
                     newsfeed_template              = VALUES(newsfeed_template),
                     codes_submit_form_template    = VALUES(codes_submit_form_template),
                     join_form_template            = VALUES(join_form_template),
                     affiliate_apply_form_template = VALUES(affiliate_apply_form_template),
                     adopt_form_template           = VALUES(adopt_form_template),
                     contact_form_template         = VALUES(contact_form_template),
                     update_form_template          = VALUES(update_form_template),
                     reset_form_template           = VALUES(reset_form_template)',
                'bind' => ['id' => $listingId, 'stats' => $statsTemplate, 'members' => $membersTemplate,
                           'section' => $affiliatesSectionTemplate, 'updates' => $updatesTemplate,
                           'codes_form' => $codesSubmitFormTemplate,
                           'join_form' => $joinFormTemplate, 'aff_apply_form' => $affiliateApplyFormTemplate,
                           'adopt_form' => $adoptFormTemplate, 'contact_form' => $contactFormTemplate,
                           'update_form' => $updateFormTemplate, 'reset_form' => $resetFormTemplate],
            ],
            [
                'sql' => 'INSERT INTO lstr_fanlisting_settings
                     (fanlisting_id, stats_template, members_template, affiliates_section_template,
                      newsfeed_template, codes_submit_form_template,
                      join_form_template, affiliate_apply_form_template,
                      adopt_form_template, contact_form_template)
                 VALUES (:id, :stats, :members, :section, :updates, :codes_form,
                         :join_form, :aff_apply_form, :adopt_form, :contact_form)
                 ON DUPLICATE KEY UPDATE
                     stats_template                = VALUES(stats_template),
                     members_template              = VALUES(members_template),
                     affiliates_section_template   = VALUES(affiliates_section_template),
                     newsfeed_template              = VALUES(newsfeed_template),
                     codes_submit_form_template    = VALUES(codes_submit_form_template),
                     join_form_template            = VALUES(join_form_template),
                     affiliate_apply_form_template = VALUES(affiliate_apply_form_template),
                     adopt_form_template           = VALUES(adopt_form_template),
                     contact_form_template         = VALUES(contact_form_template)',
                'bind' => ['id' => $listingId, 'stats' => $statsTemplate, 'members' => $membersTemplate,
                           'section' => $affiliatesSectionTemplate, 'updates' => $updatesTemplate,
                           'codes_form' => $codesSubmitFormTemplate,
                           'join_form' => $joinFormTemplate, 'aff_apply_form' => $affiliateApplyFormTemplate,
                           'adopt_form' => $adoptFormTemplate, 'contact_form' => $contactFormTemplate],
            ],
            [
                'sql' => 'INSERT INTO lstr_fanlisting_settings
                     (fanlisting_id, stats_template, members_template, affiliates_section_template,
                      newsfeed_template, codes_submit_form_template,
                      join_form_template, affiliate_apply_form_template, adopt_form_template)
                 VALUES (:id, :stats, :members, :section, :updates, :codes_form, :join_form, :aff_apply_form, :adopt_form)
                 ON DUPLICATE KEY UPDATE
                     stats_template                = VALUES(stats_template),
                     members_template              = VALUES(members_template),
                     affiliates_section_template   = VALUES(affiliates_section_template),
                     newsfeed_template              = VALUES(newsfeed_template),
                     codes_submit_form_template    = VALUES(codes_submit_form_template),
                     join_form_template            = VALUES(join_form_template),
                     affiliate_apply_form_template = VALUES(affiliate_apply_form_template),
                     adopt_form_template           = VALUES(adopt_form_template)',
                'bind' => ['id' => $listingId, 'stats' => $statsTemplate, 'members' => $membersTemplate,
                           'section' => $affiliatesSectionTemplate, 'updates' => $updatesTemplate,
                           'codes_form' => $codesSubmitFormTemplate,
                           'join_form' => $joinFormTemplate, 'aff_apply_form' => $affiliateApplyFormTemplate,
                           'adopt_form' => $adoptFormTemplate],
            ],
            [
                'sql' => 'INSERT INTO lstr_fanlisting_settings
                     (fanlisting_id, stats_template, members_template, affiliates_section_template, newsfeed_template)
                 VALUES (:id, :stats, :members, :section, :updates)
                 ON DUPLICATE KEY UPDATE
                     stats_template              = VALUES(stats_template),
                     members_template            = VALUES(members_template),
                     affiliates_section_template = VALUES(affiliates_section_template),
                     newsfeed_template            = VALUES(newsfeed_template)',
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
                "SELECT fanlisting_id, image, last_updated, approved_by, approval_url, description, listing_type
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
                "SELECT fanlisting_id, image, last_updated, approved_by, description, listing_type
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
                "SELECT fanlisting_id, image, last_updated, approved_by, listing_type
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
     * Returns the explicitly-stored per-page override, or null if no row exists
     * or the column is unset. Callers that need to distinguish "not set" from "25"
     * (e.g. the members embed's resolution chain) should use this instead.
     */
    public function getMembersPerPageOrNull(int $listingId): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT members_per_page FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
        );
        $stmt->execute(['id' => $listingId]);
        $row = $stmt->fetch();

        if ($row === false || $row['members_per_page'] === null) {
            return null;
        }

        return (int) $row['members_per_page'];
    }

    /**
     * Saves (upserts) the per-page member count override for a listing.
     * Null clears the override so the listing falls back to the global
     * default (or the built-in 25) again.
     *
     * Does not touch any other columns on existing rows.
     */
    public function saveMembersPerPage(int $listingId, ?int $perPage): void
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
     * Returns the per-listing TFL/TAFL approval page URL, or null when not set
     * or when Migration 94 has not been run yet.
     *
     * Requires Migration 94.
     */
    public function getApprovalUrl(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT approval_url FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return null;
        }

        if ($row === false || $row['approval_url'] === null || $row['approval_url'] === '') {
            return null;
        }

        return (string) $row['approval_url'];
    }

    /**
     * Saves (upserts) the per-listing TFL/TAFL approval page URL.
     * Pass null or an empty string to clear the stored URL.
     *
     * Requires Migration 94.
     */
    public function saveApprovalUrl(int $listingId, ?string $url): void
    {
        $value = ($url !== null && trim($url) !== '') ? trim($url) : null;

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, approval_url)
                 VALUES (:id, :url)
                 ON DUPLICATE KEY UPDATE approval_url = VALUES(approval_url)'
            );
            $stmt->execute(['id' => $listingId, 'url' => $value]);
        } catch (\Exception) {
            // Migration 94 not run yet
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
     * Saves the per-listing auto-approve-joins flag. Requires Migration 50.
     */
    public function saveAutoApproveJoins(int $listingId, bool $autoApprove): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, auto_approve_joins)
                 VALUES (:id, :auto)
                 ON DUPLICATE KEY UPDATE auto_approve_joins = VALUES(auto_approve_joins)'
            );
            $stmt->execute(['id' => $listingId, 'auto' => (int) $autoApprove]);
        } catch (\Exception) {
            // Migration 50 not run yet
        }
    }

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
            $row = [];
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
     * Returns embed settings with empty form URLs resolved against global
     * defaults (and built-in fallbacks). Use this for runtime URL resolution
     * and email token building — NOT for populating edit forms.
     */
    public function getResolvedEmbedSettings(int $listingId): array
    {
        $raw        = $this->getEmbedSettings($listingId);
        $globalUrls = $this->getGlobalFormUrlDefaults();

        $raw['form_url_join']         = $raw['form_url_join']         ?: $globalUrls['form_url_join'];
        $raw['form_url_update']       = $raw['form_url_update']       ?: $globalUrls['form_url_update'];
        $raw['form_url_reset']        = $raw['form_url_reset']        ?: $globalUrls['form_url_reset'];
        $raw['form_url_codes_submit'] = $raw['form_url_codes_submit'] ?: $globalUrls['form_url_codes_submit'];

        return $raw;
    }

    /**
     * Returns the global form URL defaults from lstr_site_settings, keyed
     * by the per-listing embed field names (form_url_join, form_url_update,
     * etc.) for direct merging with getEmbedSettings() results.
     *
     * @return array<string, string>
     */
    public function getGlobalFormUrlDefaults(): array
    {
        $siteRepo = new SiteSettingsRepo($this->db);
        $globals  = $siteRepo->getGlobalFormUrls();
        $builtIn  = SiteSettingsRepo::GLOBAL_FORM_URL_KEYS;
        return [
            'form_url_join'            => ((string) ($globals['global_form_url_join']            ?? '')) ?: $builtIn['global_form_url_join'],
            'form_url_update'          => ((string) ($globals['global_form_url_update']          ?? '')) ?: $builtIn['global_form_url_update'],
            'form_url_reset'           => ((string) ($globals['global_form_url_reset']           ?? '')) ?: $builtIn['global_form_url_reset'],
            'form_url_codes_submit'    => ((string) ($globals['global_form_url_codes_submit']    ?? '')) ?: $builtIn['global_form_url_codes_submit'],
            'form_url_affiliate_apply' => ((string) ($globals['global_form_url_affiliate_apply'] ?? '')) ?: $builtIn['global_form_url_affiliate_apply'],
            'form_url_contact'         => ((string) ($globals['global_form_url_contact']         ?? '')) ?: $builtIn['global_form_url_contact'],
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
     * Defaults to enabled with empty URL overrides if the row doesn't exist
     * or Migration 41 hasn't run, matching the column's own DEFAULT 1 (Migration 60)
     * and getCodesSubmitToggleStyle()'s equivalent fallback for codes_submit_enabled.
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
            return ['enabled' => true, 'form_url' => '', 'redirect_url' => ''];
        }

        if ($row === false) {
            return ['enabled' => true, 'form_url' => '', 'redirect_url' => ''];
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
    public function getAffiliateApplyStyle(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT affiliate_apply_style FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if ($row === false || !array_key_exists('affiliate_apply_style', $row)) {
            return null;
        }
        $val = $row['affiliate_apply_style'];
        if ($val === null) {
            return null;
        }
        return in_array((string) $val, ['link', 'button'], true) ? (string) $val : null;
    }

    /**
     * Saves the affiliate-apply toggle style ('link' or 'button').
     */
    public function setAffiliateApplyStyle(int $listingId, ?string $style): void
    {
        $style = $style !== null && in_array($style, ['link', 'button'], true) ? $style : null;
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, affiliate_apply_style)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE affiliate_apply_style = VALUES(affiliate_apply_style)'
            );
            $stmt->execute(['id' => $listingId, 'val' => $style]);
        } catch (\PDOException) {}
    }

    public function getCodesSubmitToggleStyle(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT codes_submit_style FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if ($row === false || !array_key_exists('codes_submit_style', $row)) {
            return null;
        }
        $val = $row['codes_submit_style'];
        if ($val === null) {
            return null;
        }
        return in_array((string) $val, ['link', 'button'], true) ? (string) $val : null;
    }

    public function setCodesSubmitToggleStyle(int $listingId, ?string $style): void
    {
        $style = $style !== null && in_array($style, ['link', 'button'], true) ? $style : null;
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, codes_submit_style)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE codes_submit_style = VALUES(codes_submit_style)'
            );
            $stmt->execute(['id' => $listingId, 'val' => $style]);
        } catch (\PDOException) {}
    }

    public function getAffiliateApplyToggleLabel(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT affiliate_apply_toggle_label FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if ($row === false || !array_key_exists('affiliate_apply_toggle_label', $row)) {
            return null;
        }
        $val = $row['affiliate_apply_toggle_label'];
        return $val !== null && trim((string) $val) !== '' ? trim((string) $val) : null;
    }

    public function setAffiliateApplyToggleLabel(int $listingId, ?string $label): void
    {
        $label = $label !== null && trim($label) !== '' ? trim($label) : null;
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, affiliate_apply_toggle_label)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE affiliate_apply_toggle_label = VALUES(affiliate_apply_toggle_label)'
            );
            $stmt->execute(['id' => $listingId, 'val' => $label]);
        } catch (\PDOException) {}
    }

    public function getCodesSubmitToggleLabel(int $listingId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT codes_submit_toggle_label FROM lstr_fanlisting_settings WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException) {
            return null;
        }
        if ($row === false || !array_key_exists('codes_submit_toggle_label', $row)) {
            return null;
        }
        $val = $row['codes_submit_toggle_label'];
        return $val !== null && trim((string) $val) !== '' ? trim((string) $val) : null;
    }

    public function setCodesSubmitToggleLabel(int $listingId, ?string $label): void
    {
        $label = $label !== null && trim($label) !== '' ? trim($label) : null;
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_fanlisting_settings (fanlisting_id, codes_submit_toggle_label)
                 VALUES (:id, :val)
                 ON DUPLICATE KEY UPDATE codes_submit_toggle_label = VALUES(codes_submit_toggle_label)'
            );
            $stmt->execute(['id' => $listingId, 'val' => $label]);
        } catch (\PDOException) {}
    }

    /**
     * Strict single-column template write for use inside transactions.
     * Validates $column against the known template-column allowlist and propagates
     * \PDOException on failure; never silently swallows.
     *
     * @throws \InvalidArgumentException  when $column is not in the allowlist.
     * @throws \PDOException              on database failure.
     */
    public function saveTemplateColumnStrict(int $listingId, string $column, ?string $value): void
    {
        static $allowed = [
            'stats_template', 'members_template', 'affiliates_section_template',
            'newsfeed_template', 'codes_template', 'codes_submit_form_template',
            'join_form_template', 'affiliate_apply_form_template',
            'contact_form_template', 'update_form_template', 'reset_form_template',
        ];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("Unknown template column: {$column}");
        }
        $stmt = $this->db->prepare(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, {$column})
             VALUES (:id, :val)
             ON DUPLICATE KEY UPDATE {$column} = VALUES({$column})"
        );
        $stmt->execute(['id' => $listingId, 'val' => $value]);
    }
}
