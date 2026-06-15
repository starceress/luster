<?php

declare(strict_types=1);

namespace Luster\Repositories;

use Luster\Core\Database;
use Luster\Core\FieldWrapper;
use Luster\Support\SuccessRenderer;

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

            return $row !== false ? (string) $row['setting_value'] : $default;
        } catch (\Exception) {
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
        } catch (\Exception) {
            // Table not yet created — Migration 28 not run; silently ignore
        }
    }

    /**
     * Default HTML row/block templates used when no custom value is stored.
     *
     * Shared by the admin collective page and the embed files so
     * both always show the same fallback markup.
     *
     * @return array{owned_template: string, joined_template: string, collective_stats_template: string, collective_affiliates_section_template: string, random_listing_template: string, latest_listing_template: string, random_joined_listing_template: string, latest_joined_listing_template: string, collective_affiliate_apply_form_template: string, kim_signup_form_template: string, wishlist_template: string, collective_updates_template: string, collective_codes_template: string, collective_codes_submit_form_template: string, collective_field_wrapper_template: string, collective_contact_sent_template: string}
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
                             . '<div class="fl-listing-item">' . "\n"
                             . '{if_listing_image}<div class="fl-listing-image-wrap"><img class="fl-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}' . "\n"
                             . '<div class="fl-listing-body">' . "\n"
                             . '<a class="fl-listing-title" href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_subject}</a>' . "\n"
                             . '<span class="fl-listing-subject">{listing_title}</span>' . "\n"
                             . '<div class="fl-listing-meta">' . "\n"
                             . '{if_approved}<div class="fl-listing-network">{if_tfl}The Fanlistings Network{/if_tfl}{if_tafl}The Anime Fanlistings Network{/if_tafl}</div>{/if_approved}' . "\n"
                             . '<div class="fl-listing-category">{listing_category}</div>' . "\n"
                             . '<div class="fl-listing-member-count">{member_count} member(s){if_pending} · {member_pending_count} pending{/if_pending}</div>' . "\n"
                             . '{if_countries}<div class="fl-listing-country-count">{country_count} countries</div>{/if_countries}' . "\n"
                             . '<div class="fl-listing-date-opened">Opened: {date_opened}</div>' . "\n"
                             . '{if_date_updated}<div class="fl-listing-date-updated">Updated: {date_updated}</div>{/if_date_updated}' . "\n"
                             . '</div>' . "\n"
                             . '</div>' . "\n"
                             . '</div>' . "\n"
                             . '{/each_listing}' . "\n"
                             . '</div>',
            'joined_template' => '<div class="fl-joined-list">' . "\n"
                               . '{each_joined}' . "\n"
                               . '{if_listing_image}' . "\n"
                               . '<a href="{listing_url}" title="{listing_subject} - {listing_category}&#010;Joined on {date_joined}" target="_blank" rel="noopener noreferrer"><img src="{listing_image_url}" alt="{listing_category} {date_joined}"></a>' . "\n"
                               . '{/if_listing_image}' . "\n"
                               . '{/each_joined}' . "\n"
                               . '</div>',
            'collective_stats_template' => '<div class="fl-cstats">' . "\n"
                                         . '<div class="fl-cstats-grid">' . "\n"
                                         . '<span class="fl-cstats-label">Owned listings:</span><span class="fl-cstats-value">{owned_active} open, {owned_upcoming} upcoming</span>' . "\n"
                                         . '<span class="fl-cstats-label">Joined listings:</span><span class="fl-cstats-value">{joined_active}</span>' . "\n"
                                         . '<span class="fl-cstats-label">Total members:</span><span class="fl-cstats-value">{members_approved} approved</span>' . "\n"
                                         . '</div>' . "\n"
                                         . '<div class="fl-cstats-random">Featured: <a href="{owned_random_url}">{owned_random_name}</a></div>' . "\n"
                                         . '</div>',
            'collective_affiliates_section_template' => '<div class="fl-affiliates-section">' . "\n"
                                                    . '{if_affiliates}' . "\n"
                                                    . '<ul class="fl-affiliate-list">' . "\n"
                                                    . '{each_affiliate}' . "\n"
                                                    . '<li class="fl-affiliate-item">{if_aff_image}{aff_image}{/if_aff_image}{if_no_aff_image}<a href="{aff_url}">{aff_name}</a>{/if_no_aff_image}</li>' . "\n"
                                                    . '{/each_affiliate}' . "\n"
                                                    . '</ul>' . "\n"
                                                    . '{/if_affiliates}' . "\n"
                                                    . '{if_no_affiliates}' . "\n"
                                                    . '<p class="fl-affiliates-empty">No affiliates yet.</p>' . "\n"
                                                    . '{/if_no_affiliates}' . "\n"
                                                    . '</div>',
            // Single-listing embeds — each has its own separate template so site
            // owners can style them independently from the full owned-listings list.
            'random_listing_template' => '<div class="fl-random-listing">' . "\n"
                                      . '{each_listing}' . "\n"
                                      . '<div class="fl-random-listing-item">' . "\n"
                                      . '{if_listing_image}<div class="fl-random-listing-image-wrap"><img class="fl-random-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}' . "\n"
                                      . '<div class="fl-random-listing-body">' . "\n"
                                      . '<a class="fl-random-listing-title" href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_subject}</a>' . "\n"
                                      . '<span class="fl-random-listing-subject">{listing_title}</span>' . "\n"
                                      . '</div>' . "\n"
                                      . '</div>' . "\n"
                                      . '{/each_listing}' . "\n"
                                      . '</div>',
            'latest_listing_template' => '<div class="fl-latest-listing">' . "\n"
                                      . '{each_listing}' . "\n"
                                      . '<div class="fl-latest-listing-item">' . "\n"
                                      . '{if_listing_image}<div class="fl-latest-listing-image-wrap"><img class="fl-latest-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}' . "\n"
                                      . '<div class="fl-latest-listing-body">' . "\n"
                                      . '<a class="fl-latest-listing-title" href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_subject}</a>' . "\n"
                                      . '<span class="fl-latest-listing-subject">{listing_title}</span>' . "\n"
                                      . '<span class="fl-latest-listing-date">Opened: {date_opened}</span>' . "\n"
                                      . '</div>' . "\n"
                                      . '</div>' . "\n"
                                      . '{/each_listing}' . "\n"
                                      . '</div>',
            // Single-listing joined embeds — mirror random_listing_template /
            // latest_listing_template above, but for lstr_joined rows via
            // JoinedListingRowRenderer.
            'random_joined_listing_template' => '<div class="fl-random-joined-listing">' . "\n"
                                              . '{each_joined}' . "\n"
                                              . '<div class="fl-random-joined-listing-item">' . "\n"
                                              . '{if_listing_image}<div class="fl-random-joined-listing-image-wrap"><img class="fl-random-joined-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}' . "\n"
                                              . '<div class="fl-random-joined-listing-body">' . "\n"
                                              . '<a class="fl-random-joined-listing-title" href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_subject}</a>' . "\n"
                                              . '<span class="fl-random-joined-listing-category">{listing_category}</span>' . "\n"
                                              . '</div>' . "\n"
                                              . '</div>' . "\n"
                                              . '{/each_joined}' . "\n"
                                              . '</div>',
            'latest_joined_listing_template' => '<div class="fl-latest-joined-listing">' . "\n"
                                              . '{each_joined}' . "\n"
                                              . '<div class="fl-latest-joined-listing-item">' . "\n"
                                              . '{if_listing_image}<div class="fl-latest-joined-listing-image-wrap"><img class="fl-latest-joined-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}' . "\n"
                                              . '<div class="fl-latest-joined-listing-body">' . "\n"
                                              . '<a class="fl-latest-joined-listing-title" href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_subject}</a>' . "\n"
                                              . '<span class="fl-latest-joined-listing-category">{listing_category}</span>' . "\n"
                                              . '<span class="fl-latest-joined-listing-date">Joined: {date_joined}</span>' . "\n"
                                              . '</div>' . "\n"
                                              . '</div>' . "\n"
                                              . '{/each_joined}' . "\n"
                                              . '</div>',
            'collective_affiliate_apply_form_template' => self::defaultCollectiveAffiliateApplyFormTemplate(),
            'kim_signup_form_template'                   => self::defaultKimSignupFormTemplate(),
            'wishlist_template'                          => self::defaultWishlistTemplate(),
            'collective_updates_template'                => self::defaultCollectiveUpdatesTemplate(),
            'collective_codes_template'                  => self::defaultCollectiveCodesTemplate(),
            'collective_codes_submit_form_template'      => self::defaultCollectiveCodesSubmitFormTemplate(),
            'collective_field_wrapper_template'          => FieldWrapper::defaultTemplate(),
            'collective_contact_sent_template'           => SuccessRenderer::defaultTemplate('contact-sent'),
        ];
    }

    public static function defaultCollectiveAffiliateApplyFormTemplate(): string
    {
        return '{field_name}' . "\n"
             . '{field_url}' . "\n"
             . '{field_email}' . "\n"
             . '{field_image}' . "\n"
             . '{field_message}' . "\n"
             . '{captcha_block}' . "\n"
             . '<div class="fl-form-actions">' . "\n"
             . '{submit_button}' . "\n"
             . '{clear_button}' . "\n"
             . '</div>';
    }

    public static function defaultKimSignupFormTemplate(): string
    {
        return '<h2 class="fl-kim-heading">KIM List &mdash; Sign Up</h2>' . "\n"
             . '<p class="fl-kim-intro">KIM (Keep In Mind) is an adoption interest registry. If a fanlisting owner might someday give up their listing, you can sign up here to express interest in adopting it.</p>' . "\n"
             . '{field_name}' . "\n"
             . '{field_url}' . "\n"
             . '{field_email}' . "\n"
             . '{field_fanlisting_id}' . "\n"
             . '{field_password}' . "\n"
             . '{field_password_confirm}' . "\n"
             . '{field_send_copy}' . "\n"
             . '{captcha_block}' . "\n"
             . '<div class="fl-form-actions">' . "\n"
             . '{submit_button}' . "\n"
             . '{clear_button}' . "\n"
             . '</div>';
    }

    public static function defaultWishlistTemplate(): string
    {
        return <<<'HTML'
<div class="fl-embed fl-embed--wishlist">
<h1>Wishlist</h1>

{if_has_top}
<h2>Top Wanted</h2>
{each_wishlist_item_top}
<div class="fl-wishlist-item">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" rel="noopener noreferrer" title="{item_subject}">{if_item_image}<img src="{item_image_url}" alt="{item_subject}">{/if_item_image}</a>{/if_item_url}

<div class="fl-wishlist-info">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" rel="noopener noreferrer" title="{item_subject}">{item_subject}</a>{/if_item_url}<br>
{if_item_category}<b>Category:</b> {item_category}{/if_item_category}<br>
{if_item_description}<p class="fl-wishlist-description">{item_description}</p>{/if_item_description}
</div>
</div>
{/each_wishlist_item_top}
{/if_has_top}

{if_has_wanted}
<h2>Wanted</h2>
{each_wishlist_item_wanted}
<div class="fl-wishlist-item">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" rel="noopener noreferrer" title="{item_subject}">{if_item_image}<img src="{item_image_url}" alt="{item_subject}">{/if_item_image}</a>{/if_item_url}

<div class="fl-wishlist-info">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" rel="noopener noreferrer" title="{item_subject}">{item_subject}</a>{/if_item_url}<br>
{if_item_category}<b>Category:</b> {item_category}{/if_item_category}<br>
{if_item_description}<p class="fl-wishlist-description">{item_description}</p>{/if_item_description}
</div>
</div>
{/each_wishlist_item_wanted}
{/if_has_wanted}

{if_has_granted}
<h2>Granted</h2>
{each_wishlist_item_granted}
<div class="fl-wishlist-item">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" rel="noopener noreferrer" title="{item_subject}">{if_item_image}<img src="{item_image_url}" alt="{item_subject}">{/if_item_image}</a>{/if_item_url}

<div class="fl-wishlist-info">
{if_item_url}<a class="fl-wishlist-subject" href="{item_url}" rel="noopener noreferrer" title="{item_subject}">{item_subject}</a>{/if_item_url}<br>
{if_item_category}<b>Category:</b> {item_category}{/if_item_category}<br>
{if_item_description}<p class="fl-wishlist-description">{item_description}</p>{/if_item_description}
</div>
</div>
{/each_wishlist_item_granted}
{/if_has_granted}
</div>
HTML;
    }

    public static function defaultCollectiveUpdatesTemplate(): string
    {
        return ListingSettingsRepo::defaultUpdatesTemplate();
    }

    public static function defaultCollectiveCodesTemplate(): string
    {
        return ListingSettingsRepo::defaultCodesTemplate();
    }

    /**
     * Default Collective Codes Donation Form template used when no override is stored
     * in the database. Mirrors ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
     * but uses {site_name} in place of {listing_title} since collective code
     * submissions are not tied to a single fanlisting.
     */
    public static function defaultCollectiveCodesSubmitFormTemplate(): string
    {
        return '<h1>Submit a Code for {site_name}</h1>' . "\n"
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
                $result[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Exception) {
            // Table not yet created — silently ignore
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
            'listing_default_updates_template',
            'listing_default_join_form_template',
            'listing_default_codes_submit_form_template',
            'listing_default_affiliate_apply_form_template',
            'listing_default_adopt_form_template',
        ]);
    }
}
