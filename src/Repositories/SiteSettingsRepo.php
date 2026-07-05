<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Key-value store for site-wide settings backed by lstr_site_settings. Stores
 collective-level template overrides (owned, joined, stats, affiliates, codes,
 updates, contact, KIM, wishlist, adoption, etc.), import visibility flags,
 and the selected admin skin. Also holds the built-in default HTML for every
 collective template, so both the admin template editor and the public embed
 files share the same canonical fallback markup.
*/

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;

/**
 * Key/value store for site-wide (non-per-listing) settings.
 *
 * Backed by the lstr_site_settings table (Migration 28).
 * All methods degrade silently if the table does not yet exist so that
 * pages load normally before the migration has been run.
 *
 * Keys in use:
 *   owned_template            — combined block template for embed/owned.php; fully self-contained
 *                               (incl. wrapper markup) using {each_listing} (flat) or
 *                               {each_current}/{each_upcoming}/{each_pending} (sectioned) loops
 *   joined_template           — combined block template for embed/joined.php; fully self-contained
 *                               using {each_joined} (flat) or {each_current_joined} (grouped) loops
 *   collective_stats_template — HTML template for embed/collective-stats.php
 */
class SiteSettingsRepo
{
    /**
     * Global default form page URL keys and their built-in defaults.
     * Used by Owned Listings Settings and by getEmbedSettings()
     * for the per-listing → global → built-in fallback cascade.
     */
    public const GLOBAL_FORM_URL_KEYS = [
        'global_form_url_join'            => 'join.php',
        'global_form_url_update'          => 'update.php',
        'global_form_url_reset'           => 'reset.php',
        'global_form_url_affiliate_apply' => 'affiliates.php',
        'global_form_url_codes_submit'    => 'codes-submit.php',
        'global_form_url_contact'         => 'contact.php',
    ];

    public function __construct(private readonly Database $db) {}

    /**
     * Returns the stored value for $key, or $default when absent or not yet migrated.
     */
    public function get(string $key, ?string $default = null): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT setting_value FROM lstr_site_settings WHERE setting_key = :key'
            );
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch();

            // set() upserts, so clearing a previously-set key (set($key, null))
            // leaves the row in place with setting_value = SQL NULL rather than
            // deleting it. Casting that straight to (string) would silently turn
            // "cleared" into "" instead of the caller's $default (usually null) —
            // e.g. (int) '' is 0, not the built-in default a blank field is meant
            // to fall back to. Row-absent and value-is-null must both mean "unset".
            if ($row === false || $row['setting_value'] === null) {
                return $default;
            }

            return (string) $row['setting_value'];
        } catch (\PDOException) {
            return $default;
        }
    }

    /**
     * Upserts a value. Pass null to clear the stored value.
     */
    public function set(string $key, ?string $value): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO lstr_site_settings (setting_key, setting_value)
                 VALUES (:key, :value)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            $stmt->execute(['key' => $key, 'value' => $value]);
        } catch (\PDOException) {
        }
    }

    /**
     * Strict variant of set() for use inside transactions.
     * Propagates \PDOException on failure; never silently swallows.
     */
    public function setStrict(string $key, ?string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO lstr_site_settings (setting_key, setting_value)
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute(['key' => $key, 'value' => $value]);
    }

    /**
     * Default HTML row/block templates used when no custom value is stored.
     *
     * Shared by the admin collective page and the embed files so
     * both always show the same fallback markup.
     *
     * @return array{owned_template: string, joined_template: string, collective_stats_template: string, collective_affiliates_section_template: string, random_listing_template: string, latest_listing_template: string, random_joined_listing_template: string, latest_joined_listing_template: string, collective_affiliate_apply_form_template: string, kim_signup_form_template: string, wishlist_template: string, collective_newsfeed_template: string, collective_codes_template: string, collective_codes_submit_form_template: string, collective_contact_sent_template: string, collective_adoption_form_template: string, collective_kim_list_overview_template: string, collective_kim_list_subject_template: string, kim_update_form_template: string, kim_reset_form_template: string, collective_contact_form_template: string, collective_update_form_template: string}
     */
    public static function collectiveDefaults(): array
    {
        return [
            // Flat ({each_listing}) by design — not sectioned. owned_template is used
            // only by owned.php; random-listing.php and latest-listing.php each have
            // their own separate template (random_listing_template /
            // latest_listing_template). A sectioned ({each_current}/{each_upcoming}/
            // {each_pending}) shape is supported by all three templates via
            // ListingRowRenderer::renderTemplate().
            'owned_template' => '<div class="fl-listing-list">' . "\n"
                             . '{each_listing}' . "\n"
                             . "\n"
                             . '<div class="fl-listing-item">' . "\n"
                             . '{if_listing_image}<div class="fl-listing-image-wrap"><img class="fl-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}' . "\n"
                             . "\n"
                             . '<div class="fl-listing-body">' . "\n"
                             . '<a href="{listing_url}" target="_blank" title="{listing_subject}">{listing_subject}</a>' . "\n"
                             . "\n"
                             . '<span class="fl-listing-subject">{listing_title}</span>' . "\n"
                             . '<div class="fl-listing-meta">' . "\n"
                             . '{if_approved_by}<b>Approved by:</b> {if_tfl}The Fanlistings Network{/if_tfl}{if_tafl}The Anime Fanlistings Network{/if_tafl}<br>{/if_approved_by}' . "\n"
                             . '<b>Category:</b> {listing_category}<br>' . "\n"
                             . '<b>Members:</b> {member_count} member(s) & {if_pending}{member_pending_count} pending{/if_pending}<br>' . "\n"
                             . '<b>Countries:</b> {if_countries}{country_count} countries{/if_countries}<br>' . "\n"
                             . '<b>Opened:</b> {date_opened}<br>' . "\n"
                             . '{if_date_updated}<b>Updated:</b> {date_updated}{/if_date_updated}' . "\n"
                             . "\n"
                             . '</div>' . "\n"
                             . '</div>' . "\n"
                             . '</div>' . "\n"
                             . '{/each_listing}' . "\n"
                             . '</div>',
            'joined_template' => '<div class="fl-joined-list">' . "\n"
                               . '{each_joined}' . "\n"
                               . "\n"
                               . '{if_listing_image}' . "\n"
                               . '<a href="{listing_url}" target="_blank"><img src="{listing_image_url}" title="{listing_subject} - {listing_category}&#010;Joined on {date_joined}"></a>' . "\n"
                               . '{/if_listing_image}' . "\n"
                               . '{if_no_listing_image}' . "\n"
                               . '<a href="{listing_url}" target="_blank">{listing_subject}</a>' . "\n"
                               . '{/if_no_listing_image}' . "\n"
                               . "\n"
                               . '{/each_joined}' . "\n"
                               . '</div>',
            'collective_stats_template' => '<div class="fl-cstats">' . "\n"
                                         . '<div class="fl-cstats-grid">' . "\n"
                                         . '<b>Owned Listings:</b> {owned_active} open{if_owned_upcoming}, {owned_upcoming} upcoming{/if_owned_upcoming}{if_owned_pending}, {owned_pending} pending{/if_owned_pending}<br>' . "\n"
                                         . '<b>Joined listings:</b> {joined_active}<br>' . "\n"
                                         . '<b>Total members:</b> {members_approved} approved{if_pending}, {members_pending} pending{/if_pending}<br>' . "\n"
                                         . '</div>' . "\n"
                                         . '</div>',
            'collective_affiliates_section_template' => '<div class="fl-affiliates-section">' . "\n"
                                                    . '{if_affiliates}' . "\n"
                                                    . '{each_affiliate}' . "\n"
                                                    . '{if_aff_image}<a href="{aff_url}" title="{aff_name}" target="_blank"><img src="{aff_image_url}" alt="{aff_name}"></a>{/if_aff_image}' . "\n"
                                                    . '{if_no_aff_image}<a href="{aff_url}" title="{aff_name}" target="_blank">{aff_name}</a>{/if_no_aff_image}' . "\n"
                                                    . '{/each_affiliate}' . "\n"
                                                    . '{/if_affiliates}' . "\n"
                                                    . "\n"
                                                    . '{if_no_affiliates}' . "\n"
                                                    . 'No affiliates yet.' . "\n"
                                                    . '{/if_no_affiliates}' . "\n"
                                                    . '{affiliate_apply_toggle}' . "\n"
                                                    . '</div>',
            // Single-listing embeds — each has its own separate template so site
            // owners can style them independently from the full owned-listings list.
            'random_listing_template' => '<div class="fl-random-listing">' . "\n"
                                      . '{each_listing}' . "\n"
                                      . "\n"
                                      . '<div class="fl-random-listing-item">' . "\n"
                                      . '{if_listing_image}<div class="fl-random-listing-image-wrap"><img class="fl-random-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}' . "\n"
                                      . "\n"
                                      . '<div class="fl-random-listing-body">' . "\n"
                                      . '<a href="{listing_url}" target="_blank" title="{listing_subject}">{listing_subject}</a>' . "\n"
                                      . "\n"
                                      . '<span class="fl-random-listing-subject">{listing_title}</span>' . "\n"
                                      . '</div>' . "\n"
                                      . "\n"
                                      . '</div>' . "\n"
                                      . '{/each_listing}' . "\n"
                                      . '</div>',
            'latest_listing_template' => '<div class="fl-latest-listing">' . "\n"
                                      . '{each_listing}' . "\n"
                                      . "\n"
                                      . '<div class="fl-latest-listing-item">' . "\n"
                                      . '{if_listing_image}<div class="fl-latest-listing-image-wrap"><img class="fl-latest-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}' . "\n"
                                      . "\n"
                                      . '<div class="fl-latest-listing-body">' . "\n"
                                      . '<a href="{listing_url}" target="_blank" title="{listing_subject}">{listing_subject}</a>' . "\n"
                                      . "\n"
                                      . '<span class="fl-latest-listing-subject">{listing_title}</span>' . "\n"
                                      . '</div>' . "\n"
                                      . "\n"
                                      . '</div>' . "\n"
                                      . '{/each_listing}' . "\n"
                                      . '</div>',
            // Single-listing joined embeds — mirror random_listing_template /
            // latest_listing_template above, but for lstr_joined rows via
            // JoinedListingRowRenderer.
            'random_joined_listing_template' => '<div class="fl-random-joined-listing">' . "\n"
                                              . '{each_joined}' . "\n"
                                              . "\n"
                                              . '<div class="fl-random-joined-listing-item">' . "\n"
                                              . '{if_listing_image}<div class="fl-random-joined-listing-image-wrap"><img class="fl-random-joined-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}' . "\n"
                                              . "\n"
                                              . '<div class="fl-random-joined-listing-body">' . "\n"
                                              . '<a href="{listing_url}" target="_blank" title="{listing_subject} - {listing_category}&#010;Joined on {date_joined}">{listing_subject}</a>' . "\n"
                                              . "\n"
                                              . '<span class="fl-random-joined-listing-category">{listing_category}</span>' . "\n"
                                              . '</div>' . "\n"
                                              . "\n"
                                              . '</div>' . "\n"
                                              . '{/each_joined}' . "\n"
                                              . '</div>',
            'latest_joined_listing_template' => '<div class="fl-latest-joined-listing">' . "\n"
                                              . '{each_joined}' . "\n"
                                              . "\n"
                                              . '<div class="fl-latest-joined-listing-item">' . "\n"
                                              . '{if_listing_image}<div class="fl-latest-joined-listing-image-wrap"><img class="fl-latest-joined-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}' . "\n"
                                              . "\n"
                                              . '<div class="fl-latest-joined-listing-body">' . "\n"
                                              . '<a href="{listing_url}" target="_blank" title="{listing_subject} - {listing_category}&#010;Joined on {date_joined}">{listing_subject}</a>' . "\n"
                                              . "\n"
                                              . '<span class="fl-latest-joined-listing-category">{listing_category}</span>' . "\n"
                                              . '</div>' . "\n"
                                              . "\n"
                                              . '</div>' . "\n"
                                              . '{/each_joined}' . "\n"
                                              . '</div>',
            'collective_affiliate_apply_form_template' => self::defaultCollectiveAffiliateApplyFormTemplate(),
            'kim_signup_form_template'                   => self::defaultKimSignupFormTemplate(),
            'wishlist_template'                          => self::defaultWishlistTemplate(),
            'collective_newsfeed_template'                => self::defaultCollectiveNewsfeedTemplate(),
            'collective_codes_template'                  => self::defaultCollectiveCodesTemplate(),
            'collective_codes_submit_form_template'      => self::defaultCollectiveCodesSubmitFormTemplate(),
            'collective_contact_sent_template'           => '<div class="fl-success"><p>Your message has been sent. Thank you for getting in touch, {sender_name}!</p></div>',
            'collective_adoption_form_template'          => self::defaultCollectiveAdoptionFormTemplate(),
            'collective_kim_list_overview_template'      => self::defaultCollectiveKimListOverviewTemplate(),
            'collective_kim_list_subject_template'       => self::defaultCollectiveKimListSubjectTemplate(),
            'kim_update_form_template'                   => self::defaultKimUpdateFormTemplate(),
            'kim_reset_form_template'                    => self::defaultKimResetFormTemplate(),
            'collective_contact_form_template'           => self::defaultCollectiveContactFormTemplate(),
            'collective_update_form_template'            => self::defaultCollectiveUpdateFormTemplate(),
        ];
    }

    /**
     * Default Collective Affiliate Application Form template used when no override is stored
     * in the database. Mirrors ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),
     * but intended for collective-level affiliate applications.
     */
    public static function defaultCollectiveAffiliateApplyFormTemplate(): string
    {
        return '<div class="fl-form-fields">' . "\n"
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
     * Default KIM Sign-Up Form template used when no override is stored in the database.
     */
    public static function defaultKimSignupFormTemplate(): string
    {
        return '<p class="fl-form-intro">Want to be kept in mind if a listing becomes available '
             . 'for adoption? Sign up below.</p>' . "\n"
             . '<div class="fl-form-fields">' . "\n"
             . '{field_name}' . "\n"
             . '{field_url}' . "\n"
             . '{field_email}' . "\n"
             . '{field_fanlisting_id}' . "\n"
             . '{field_password}' . "\n"
             . '{field_password_confirm}' . "\n"
             . '{captcha_block}' . "\n"
             . '</div>' . "\n"
             . "\n"
             . '<div class="fl-form-actions">' . "\n"
             . '{submit_button}' . "\n"
             . '{clear_button}' . "\n"
             . '</div>';
    }

    /**
     * Default Wishlist display template used when no override is stored in the database.
     *
     * Renders three sections (Top Wanted, Wanted, Granted) using separate per-type
     * {each_wishlist_item_top/wanted/granted} loops with {if_has_*} section guards.
     */
    public static function defaultWishlistTemplate(): string
    {
        return <<<'HTML'
{if_has_top}
<h2>Top Wanted</h2>
{each_wishlist_item_top}
<div class="fl-wishlist-item">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" title="{item_subject}">{if_item_image}<img src="{item_image_url}" alt="{item_subject}">{/if_item_image}</a>{/if_item_url}

<div class="fl-wishlist-info">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" title="{item_subject}">{item_subject}</a>{/if_item_url}{if_no_item_url}{item_subject}{/if_no_item_url}<br>
{if_item_category}<b>Category:</b> {item_category}{/if_item_category}<br>
{if_item_description}<p>{item_description}</p>{/if_item_description}
</div>
</div>
{/each_wishlist_item_top}
{/if_has_top}

{if_has_wanted}
<h2>Wanted</h2>
{each_wishlist_item_wanted}
<div class="fl-wishlist-item">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" title="{item_subject}">{if_item_image}<img src="{item_image_url}" alt="{item_subject}">{/if_item_image}</a>{/if_item_url}

<div class="fl-wishlist-info">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" title="{item_subject}">{item_subject}</a>{/if_item_url}{if_no_item_url}{item_subject}{/if_no_item_url}<br>
{if_item_category}<b>Category:</b> {item_category}{/if_item_category}<br>
{if_item_description}<p>{item_description}</p>{/if_item_description}
</div>
</div>
{/each_wishlist_item_wanted}
{/if_has_wanted}

{if_has_granted}
<h2>Granted</h2>
{each_wishlist_item_granted}
<div class="fl-wishlist-item">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" title="{item_subject}">{if_item_image}<img src="{item_image_url}" alt="{item_subject}">{/if_item_image}</a>{/if_item_url}

<div class="fl-wishlist-info">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" title="{item_subject}">{item_subject}</a>{/if_item_url}{if_no_item_url}{item_subject}{/if_no_item_url}<br>
{if_item_category}<b>Category:</b> {item_category}{/if_item_category}<br>
{if_item_description}<p>{item_description}</p>{/if_item_description}
</div>
</div>
{/each_wishlist_item_granted}
{/if_has_granted}
HTML;
    }

    /**
     * Default Collective Newsfeed template used when no override is stored in the database.
     *
     * Uses the {each_update} loop with per-entry tokens; mirrors the per-listing
     * ListingSettingsRepo::defaultNewsfeedTemplate() layout.
     */
    public static function defaultCollectiveNewsfeedTemplate(): string
    {
        return '<div class="fl-updates-list">' . "\n"
            . '{if_no_updates}<p>No updates posted yet.</p>{/if_no_updates}' . "\n"
            . '{if_updates}' . "\n"
            . '{each_update}' . "\n"
            . "\n"
            . '<div class="fl-update-item">' . "\n"
            . '<h3 class="fl-update-title">{update_title}</h3>' . "\n"
            . "\n"
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
     * Default Collective Codes display template used when no override is stored in the database.
     *
     * Uses the grouped {each_size_group}/{each_code} two-tier loop layout;
     * mirrors ListingSettingsRepo::defaultCodesTemplate().
     */
    public static function defaultCollectiveCodesTemplate(): string
    {
        return '{if_no_codes}<p>No codes available yet.</p>{/if_no_codes}' . "\n"
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
            . '{codes_submit_toggle}';
    }

    /**
     * Default Collective Codes Donation Form template used when no override is stored
     * in the database. Mirrors ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
     * but uses {site_name} in place of {listing_title} since collective code
     * submissions are not tied to a single fanlisting.
     */
    public static function defaultCollectiveCodesSubmitFormTemplate(): string
    {
        return '<div class="fl-form-fields">' . "\n"
            . '{field_submitter_name}' . "\n"
            . '{field_submitter_email}' . "\n"
            . '{field_submitter_url}' . "\n"
            . '{field_code_image}' . "\n"
            . '{captcha_block}' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '{clear_button}' . "\n"
            . '</div>';
    }

    /**
     * Default Collective Adoption Application Form template.
     */
    public static function defaultCollectiveAdoptionFormTemplate(): string
    {
        return '<div class="fl-form-fields">' . "\n"
            . '{field_name}' . "\n"
            . '{field_email}' . "\n"
            . '{field_url}' . "\n"
            . '{field_listing_id}' . "\n"
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
     * Default KIM list overview template (shows all fanlistings with KIM sign-up counts).
     */
    public static function defaultCollectiveKimListOverviewTemplate(): string
    {
        return '{if_no_listings}<p>No fanlistings with sign-ups yet.</p>{/if_no_listings}' . "\n"
            . "\n"
            . '{if_has_listings}' . "\n"
            . '<ul>' . "\n"
            . "\n"
            . '{each_kim_listing}' . "\n"
            . '<li><a href="{listing_kim_url}">{listing_subject}</a> &mdash; {listing_kim_count} Interested</li>' . "\n"
            . '{/each_kim_listing}' . "\n"
            . "\n"
            . '</ul>' . "\n"
            . '{/if_has_listings}';
    }

    /**
     * Default KIM list subject template (shows approved KIM members for a fanlisting).
     */
    public static function defaultCollectiveKimListSubjectTemplate(): string
    {
        return '<h3>KIM &mdash; {listing_subject}</h3>' . "\n"
            . '{if_kim_overview_url}<p><a href="{kim_overview_url}">&larr; Back to KIM List</a></p>{/if_kim_overview_url}' . "\n"
            . "\n"
            . '{if_kim_signup_url}<p>Interested in adopting this fanlisting? <a href="{kim_signup_url}">Click here to sign-up</a></p>{/if_kim_signup_url}' . "\n"
            . "\n"
            . '{if_no_kim_members}<p>No one has signed up yet.</p>{/if_no_kim_members}' . "\n"
            . "\n"
            . '{if_has_kim_members}' . "\n"
            . '<ul>' . "\n"
            . '{each_kim_member}' . "\n"
            . "\n"
            . '<li>' . "\n"
            . '{if_member_url}<a href="{member_url}" target="_blank">{member_name}</a>{/if_member_url}{if_no_member_url}<span class="fl-kim-member-name">{member_name}</span>{/if_no_member_url}' . "\n"
            . '</li>' . "\n"
            . "\n"
            . '{/each_kim_member}' . "\n"
            . '</ul>' . "\n"
            . '{/if_has_kim_members}';
    }

    /**
     * Default KIM Update (edit membership) Form template.
     */
    public static function defaultKimUpdateFormTemplate(): string
    {
        return '<p class="fl-form-intro">Enter the email and password you signed up with to '
            . 'update your Keep In Mind info.</p>' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_email}' . "\n"
            . '{field_password}' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '{clear_button}' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '{if_lost_password_url}<p class="fl-form-hint"><a href="{lost_password_url}">Forgot your password?</a></p>{/if_lost_password_url}';
    }

    /**
     * Default KIM Password Reset Form template.
     */
    public static function defaultKimResetFormTemplate(): string
    {
        return '{if_step_email}' . "\n"
            . '<p class="fl-form-intro">Enter the email you signed up with and we\'ll send you a '
            . 'link to reset your Keep In Mind password.</p>' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_email}' . "\n"
            . '</div>' . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '{clear_button}' . "\n"
            . '</div>' . "\n"
            . '{/if_step_email}' . "\n"
            . "\n"
            . '{if_step_confirm}' . "\n"
            . '{if_token_invalid}<p class="fl-errors">This reset link has expired or is invalid. Please <a href="{reset_request_url}">request a new one</a>.</p>{/if_token_invalid}' . "\n"
            . "\n"
            . '{if_token_valid}' . "\n"
            . '<p class="fl-form-intro">Choose a new password below.</p>' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_password}' . "\n"
            . '{field_password_confirm}' . "\n"
            . '</div>' . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '{clear_button}' . "\n"
            . '</div>' . "\n"
            . '{/if_token_valid}' . "\n"
            . '{/if_step_confirm}';
    }

    /**
     * Default Collective Contact Form template.
     */
    public static function defaultCollectiveContactFormTemplate(): string
    {
        return '<div class="fl-form-fields">' . "\n"
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
     * Default Collective Update Form template (Step 1 — email-request only).
     * Step 2 (the update form with listing checkboxes) is rendered by the embed.
     */
    public static function defaultCollectiveUpdateFormTemplate(): string
    {
        return '<p class="fl-form-intro">Update your info across every {site_name} fanlisting '
            . "you've joined in one step. Enter your email below and we'll send you a secure "
            . 'link to make changes.</p>' . "\n"
            . '<div class="fl-form-fields">' . "\n"
            . '{field_email}' . "\n"
            . '</div>' . "\n"
            . "\n"
            . '<div class="fl-form-actions">' . "\n"
            . '{submit_button}' . "\n"
            . '{clear_button}' . "\n"
            . '</div>';
    }

    /**
     * Returns the six global default form page URLs from lstr_site_settings.
     * Each key returns null when not stored (meaning use the built-in default).
     *
     * @return array<string, string|null>
     */
    public function getGlobalFormUrls(): array
    {
        return $this->getMany(array_keys(self::GLOBAL_FORM_URL_KEYS));
    }

    /**
     * Returns multiple keys in one query.
     *
     * @param  string[] $keys
     * @return array<string, string|null>  key → value (null when absent)
     */
    public function getMany(array $keys): array
    {
        $result = array_fill_keys($keys, null);

        if (empty($keys)) {
            return $result;
        }

        try {
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $stmt = $this->db->prepare(
                "SELECT setting_key, setting_value FROM lstr_site_settings
                 WHERE setting_key IN ({$placeholders})"
            );
            $stmt->execute(array_values($keys));

            foreach ($stmt->fetchAll() as $row) {
                // Same distinction as get(): a row with setting_value = NULL
                // (a cleared key, still present from the set()-upsert) must stay
                // null here, not become "" — the pre-filled default above.
                if ($row['setting_value'] !== null) {
                    $result[(string) $row['setting_key']] = (string) $row['setting_value'];
                }
            }
        } catch (\PDOException) {
        }

        return $result;
    }

    /**
     * Returns all eight listing-level global template defaults from lstr_site_settings.
     * Any key that has never been saved returns null (cascade falls to built-in default).
     *
     * @return array<string, string|null>
     */
    public function getGlobalTemplates(): array
    {
        return $this->getMany([
            'listing_default_members_template',
            'listing_default_stats_template',
            'listing_default_affiliates_section_template',
            'listing_default_newsfeed_template',
            'listing_default_join_form_template',
            'listing_default_codes_submit_form_template',
            'listing_default_affiliate_apply_form_template',
            'listing_default_update_form_template',
            'listing_default_reset_form_template',
            'listing_default_codes_template',
            'listing_default_contact_form_template',
            'listing_default_contact_sent_template',
        ]);
    }
}
