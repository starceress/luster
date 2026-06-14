<?php

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
     * @return array{stats_template: string|null, members_template: string|null, affiliates_section_template: string|null, updates_template: string|null, codes_submit_form_template: string|null, rules_intro_template: string|null, join_form_template: string|null, affiliate_apply_form_template: string|null, adopt_form_template: string|null}
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
        ];

        // Try the widest query first; fall back gracefully as migrations are added.
        // Tier 1 uses codes_submit_form_template and affiliate_apply_form_template
        // (both added by Migration 70) instead of the retired intro columns.
        $row = false;

        foreach ([
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
        return '<h1>Listing Statistics</h1>'
            . "\n" . '<p><b>Members:</b> {stat_approved} total{if_stat_pending} (+{stat_pending} pending){/if_stat_pending} across {stat_countries} countries<br>'
            . "\n" . '<b>Newest Members:</b> {stat_newmembers_oxford}<br>'
            . "\n" . '<b>Opened On:</b> {stat_opened}<br>'
            . "\n" . '<b>Last Updated:</b> {stat_last_updated}{if_approved_by}<br>'
            . "\n" . '<b>Approved by:</b> {approved_by}{/if_approved_by}</p>';
    }

    /**
     * Default members row template used when no override is stored in the database.
     *
     * Shared by templates/default/members.php (public fallback) and the admin
     * per-listing template editor (pre-filled textarea).
     */
    public static function defaultMembersTemplate(): string
    {
        return '<h1>Member List</h1>'
            . "\n" . '<p class="fl-member-sort">Sort by: {sort_link_all} | {sort_link_name} | {sort_link_country}</p><br>'
            . "\n"
            . "\n" . '{if_members}'
            . "\n" . '<div class="fl-member-list">'
            . "\n" . '{each_member}'
            . "\n" . '<ul class="fl-member-item">'
            . "\n" . '<li>{if_fan_url}<a class="fl-member-name" href="{fan_url}" rel="noopener noreferrer">{fan_name}</a>{/if_fan_url}{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url} - {if_fan_country}<span class="fl-member-country">{fan_country}</span>{/if_fan_country}</li>'
            . "\n" . '</ul>'
            . "\n" . '{/each_member}'
            . "\n" . '</div>'
            . "\n" . '{/if_members}'
            . "\n" . '{if_no_members}'
            . "\n" . '<p class="fl-empty">No members yet. <a href="{join_url}">Want to join?</a></p>'
            . "\n" . '{/if_no_members}';
    }

    /**
     * Default updates-feed entry template used when no override is stored.
     */
    public static function defaultAffiliatesSectionTemplate(): string
    {
        return '<h1>Affiliates</h1>' . "\n\n"
            . '{if_affiliates}' . "\n"
            . '<ul class="fl-affiliate-list">' . "\n"
            . '<p><a href="{affiliate_apply_url}">Apply to become an affiliate?</a></p>' . "\n\n"
            . '{each_affiliate}' . "\n"
            . '<li class="fl-affiliate-item">' . "\n"
            . '<a href="{aff_url}" target="_blank" rel="noopener noreferrer">{aff_image_tag}</a></li>' . "\n"
            . '{/each_affiliate}' . "\n\n"
            . '</ul>' . "\n"
            . '{/if_affiliates}' . "\n\n"
            . '{if_no_affiliates}' . "\n"
            . '<p class="fl-affiliates-empty">No affiliates yet. <a href="{affiliate_apply_url}">Apply to become an affiliate?</a></p>' . "\n"
            . '{/if_no_affiliates}';
    }

    public static function defaultUpdatesTemplate(): string
    {
        return '<div class="fl-updates-list">' . "\n"
            . '{each_update}' . "\n\n"
            . '<div class="fl-update-item">' . "\n"
            . '<h3 class="fl-update-title">{update_title}</h3>' . "\n"
            . '<div class="fl-update-meta">' . "\n"
            . '<span class="fl-update-date"><b>Date Posted:</b> {update_date}</span>'
            . '{if_author} by <span class="fl-update-author">{update_author}</span>{/if_author}<br>' . "\n"
            . '{if_categories}<b>Categories:</b> <span class="fl-update-categories">{update_categories}</span>{/if_categories}</div>' . "\n\n"
            . '<div class="fl-update-body">{update_body_html}</div>' . "\n"
            . '</div>' . "\n\n"
            . '{/each_update}' . "\n"
            . '</div>';
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
        return '<h1>Join {listing_title}</h1>' . "\n"
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
        return '<h1>Affiliate Application — {listing_title}</h1>' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_name}' . "\n"
            . '{field_url}' . "\n"
            . '{field_email}' . "\n"
            . '{field_image}' . "\n"
            . '{field_message}' . "\n"
            . '{captcha_block}' . "\n"
            . '</div>' . "\n"
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
        return '<h1>Submit a Code for {listing_title}</h1>' . "\n"
            . '<p>All submissions are reviewed before being published. Accepted: JPEG, PNG, GIF, WebP. Max 200 KB.</p>' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_code_image}' . "\n"
            . '{field_submitter_name}' . "\n"
            . '{field_submitter_email}' . "\n"
            . '{field_submitter_url}' . "\n"
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
                '{stat_last_updated}',
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
                '{aff_image_tag}',
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
    ): void {
        $attempts = [
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
     * Requires Migration 37.
     *
     * @return array{enabled: bool, recipient: string|null}
     */
    public function getContactSettings(int $listingId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT contact_enabled, contact_recipient
                 FROM lstr_fanlisting_settings
                 WHERE fanlisting_id = :id'
            );
            $stmt->execute(['id' => $listingId]);
            $row = $stmt->fetch();
        } catch (\Exception) {
            return ['enabled' => true, 'recipient' => null];
        }

        if ($row === false) {
            return ['enabled' => true, 'recipient' => null];
        }

        return [
            'enabled'   => (bool) $row['contact_enabled'],
            'recipient' => $row['contact_recipient'] !== null && $row['contact_recipient'] !== ''
                ? (string) $row['contact_recipient']
                : null,
        ];
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
     * Saves (upserts) affiliate application settings for a listing.
     *
     * Requires Migration 41.
     */
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
        } catch (\Throwable) {
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
                 ON DUPLICATE KEY UPDATE code_approval_email = :val'
            );
            $stmt->execute(['id' => $listingId, 'val' => $value === null ? null : (int) $value]);
        } catch (\Throwable) {}
    }

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
}
