<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Translates Fanatically 1.1.9 template tokens to their Luster equivalents.
 Token mappings are derived from auditing the Fanatically 1.1.9 shipped template
 files in fanatically/templates/display/ and fanatically/templates/email/.

 Never executes content. Never performs I/O. Pure transformation only.
*/

declare(strict_types=1);

namespace Luster\Import;

/**
 * Translates Fanatically template tokens to Luster equivalents.
 *
 * Each display template file has its own token map. A token valid in one source
 * type is not assumed to be valid in another. The source type is resolved from an
 * explicit path-to-type allowlist; the broad ZipTemplateReader category ('display',
 * 'email', 'per-listing') is not sufficient.
 *
 * An entry is importable only when:
 *  - unmappedTokens is empty
 *  - containsPhp is false
 *  - unsupportedStructure is false
 *  - targetTemplateKey is not null
 *  - translated output is within MAX_TEMPLATE_BYTES
 *
 * Entries failing any condition are preview_only: they may be viewed and downloaded
 * but must never be written to Luster template storage.
 */
final class FanaticallyTemplateTranslator
{
    /** MySQL TEXT column limit in bytes. Templates exceeding this cannot be imported. */
    public const MAX_TEMPLATE_BYTES = 65_535;

    /**
     * Maps a Fanatically source file path to its template type key.
     * Paths are relative to the detected ZIP root (after root-prefix stripping).
     * For per-listing entries, only the basename is significant.
     *
     * @var array<string, string>  normalizedPath → type
     */
    private const PATH_TO_TYPE = [
        'templates/display/stats.php'             => 'stats',
        'templates/display/members.php'           => 'members_row',
        'templates/display/join.php'              => 'join_form',
        'templates/display/update.php'            => 'update_form',
        'templates/display/lostpass.php'          => 'reset_form',
        'templates/display/affiliates.php'        => 'affiliates',
        'templates/display/codes.php'             => 'codes',
        'templates/display/collective-stats.php'  => 'collective_stats',
        'templates/display/owned-listings.php'    => 'owned_listings',
        'templates/display/owned-listing-item.php'=> 'owned_listing_item',
        'templates/display/joined.php'            => 'joined_listings',
        'templates/display/affiliate-item.php'    => 'affiliate_item',
        'templates/display/wishlist.php'          => 'wishlist',
        'templates/display/wishlist-item.php'     => 'wishlist_item',
        'templates/display/kim.php'               => 'kim_signup',
        'templates/display/kim-item.php'          => 'kim_item',
        'templates/display/adopt.php'             => 'adopt_form',
        'templates/display/rules.php'             => 'rules',
        'templates/display/delete.php'            => 'delete_form',
        'templates/display/head.php'              => 'head',
        'templates/email/join.txt'                => 'email_join',
        'templates/email/approved.txt'            => 'email_approve',
        'templates/email/lostpassword.txt'        => 'email_reset',
        'templates/email/removal.txt'             => 'email_remove',
        'templates/email/update.txt'              => 'email_update',
        'templates/email/update-approved.txt'     => 'email_update_approved',
        'templates/email/update-pending.txt'      => 'email_update_pending',
        'templates/email/admin-new-member.txt'    => 'email_admin_new_member',
        'templates/email/affiliate-approved.txt'  => 'email_affiliate_approved',
        'templates/email/admin-new-affiliate.txt' => 'email_admin_new_affiliate',
        'templates/email/reset-password.txt'      => 'email_reset_password',
        'templates/email/donor-code-approved.txt' => 'email_code_approved',
        'templates/email/admin-new-code-donation.txt' => 'email_admin_code_donation',
        'templates/email/kim-approved.txt'        => 'email_kim_approved',
        'templates/email/kim-suggestion.txt'      => 'email_kim_suggestion',
        'templates/email/admin-overdue-listings.txt' => 'email_admin_overdue',
    ];

    /**
     * Maps each template type to the Luster template key it targets.
     * null = no Luster equivalent; these are always preview_only.
     *
     * @var array<string, string|null>
     */
    private const TYPE_TO_TARGET_KEY = [
        'stats'              => 'stats_template',
        'members_row'        => null,            // Row template; structurally incompatible
        'join_form'          => 'join_form_template',
        'update_form'        => 'update_form_template',
        'reset_form'         => 'reset_form_template',
        'affiliates'         => 'affiliates_section_template',
        'codes'              => 'codes_template',
        'collective_stats'   => 'collective_stats_template',
        'owned_listings'     => 'owned_template',
        'owned_listing_item' => null,            // Row template; structurally incompatible
        'joined_listings'    => 'joined_template',
        'affiliate_item'     => null,            // Row template; structurally incompatible
        'wishlist'           => 'wishlist_template',
        'wishlist_item'      => null,            // Row template; structurally incompatible
        'kim_signup'         => null,            // KIM templates: collective scope only; no direct per-listing key
        'kim_item'           => null,            // Row template; structurally incompatible
        'adopt_form'         => null,            // Adoption form: collective scope only
        'rules'              => null,            // Rules body managed by RulesAddon, not a template key
        'delete_form'        => null,            // No delete form template in Luster
        'head'               => null,            // CSS/head include; not a Luster template
        'email_join'         => 'email:join',
        'email_approve'      => 'email:approve',
        'email_reset'        => 'email:reset',
        'email_remove'       => 'email:remove',
        'email_update'       => 'email:update',
        'email_update_approved' => null,         // Luster merges approve + approve_pending into one type
        'email_update_pending'  => null,         // No direct equivalent
        'email_admin_new_member'      => 'email:notify_join',
        'email_affiliate_approved'    => null,   // No affiliate-approved email in Luster
        'email_admin_new_affiliate'   => null,
        'email_reset_password'        => null,   // Luster uses reset_form for token-based reset; no "new password" email
        'email_code_approved'         => 'email:code_approved',
        'email_admin_code_donation'   => null,
        'email_kim_approved'          => null,
        'email_kim_suggestion'        => null,
        'email_admin_overdue'         => null,
    ];

    /**
     * Token maps per template type: Fanatically token → Luster token.
     * Only keys present in this map are translated; all others are unmapped.
     * Conditional blocks `{if_X}...{/if_X}` are listed separately but follow the same pattern.
     *
     * @var array<string, array<string, string>>
     */
    private const TOKEN_MAPS = [
        'stats' => [
            '{title}'           => '{listing_title}',
            '{subject}'         => '{listing_subject}',
            '{member_count}'    => '{stat_approved}',
            '{pending_count}'   => '{stat_pending}',
            '{countries_count}' => '{stat_countries}',
            '{newest_member}'   => '{stat_newmembers}',
            '{last_updated}'    => '{stat_updated}',
            '{opened_date}'     => '{stat_opened}',
            '{owner_name}'      => '{owner_name}',
            '{collective_name}' => '{site_name}',
            '{approved_by}'     => '{network_status}',
            // Conditional blocks that map cleanly:
            '{if_approved_by}'   => '{if_approved_by}',
            '{/if_approved_by}'  => '{/if_approved_by}',
            // Conditionals with no Luster stats equivalent — callers get these as unmapped tokens:
            // {if_description}, {/if_description}, {if_last_updated}, {/if_last_updated},
            // {if_opened_date}, {/if_opened_date}, {if_newest_member}, {/if_newest_member},
            // {if_affiliates}, {/if_affiliates}, {if_collective}, {/if_collective},
            // {if_fff_badge}, {/if_fff_badge}
        ],

        'collective_stats' => [
            '{owned_count}'          => '{owned_active}',
            '{owned_upcoming}'       => '{owned_upcoming}',
            '{joined_count}'         => '{joined_active}',
            '{member_count}'         => '{members_approved}',
            '{pending_members}'      => '{members_pending}',
            '{affiliate_count}'      => '{affiliates_sitewide}',
            '{total_affiliate_count}'=> '{affiliates_total}',
            '{owner_name}'           => '{owner_name}',
            '{collective_name}'      => '{site_name}',
            '{newest_owned_name}'    => '{owned_newest_name}',
            '{newest_owned_url}'     => '{owned_newest_url}',
            '{random_owned_name}'    => '{owned_random_name}',
            '{random_owned_url}'     => '{owned_random_url}',
            '{if_upcoming}'          => '{if_upcoming}',
            '{/if_upcoming}'         => '{/if_upcoming}',
            '{if_affiliates}'        => '{if_affiliates_sitewide}',
            '{/if_affiliates}'       => '{/if_affiliates_sitewide}',
            '{if_total_affiliates}'  => '{if_affiliates_total}',
            '{/if_total_affiliates}' => '{/if_affiliates_total}',
            '{if_pending_members}'   => '{if_members_pending}',
            '{/if_pending_members}'  => '{/if_members_pending}',
            '{if_newest_owned}'      => '{if_newest_owned}',
            '{/if_newest_owned}'     => '{/if_newest_owned}',
            '{if_random_owned}'      => '{if_random_owned}',
            '{/if_random_owned}'     => '{/if_random_owned}',
            // No Luster equivalent: {wishlist_count}, {collective_url}, {newest_owned_image},
            // {random_owned_image}, {random_joined_*}, {latest_joined_*}, {fff_collective_badge},
            // {if_wishlist}, {if_collective}, {if_random_joined}, {if_latest_joined}, {if_fff_collective_badge}
        ],

        'owned_listings' => [
            '{content}'              => '{current_content}',
            '{count}'                => '{current_count}',
            '{current_content}'      => '{current_content}',
            '{pending_content}'      => '{pending_content}',
            '{upcoming_content}'     => '{upcoming_content}',
            '{closed_content}'       => '{closed_content}',
            '{adopted_out_content}'  => '{adopted_out_content}',
            '{current_count}'        => '{current_count}',
            '{pending_count}'        => '{pending_count}',
            '{upcoming_count}'       => '{upcoming_count}',
            '{closed_count}'         => '{closed_owned_count}',
            '{adopted_out_count}'    => '{adopted_out_owned_count}',
            '{affiliate_count}'      => '{affiliates_sitewide}',
            '{total_affiliate_count}'=> '{affiliates_total}',
            '{if_listings}'          => '{if_current_listings}',
            '{/if_listings}'         => '{/if_current_listings}',
            '{if_current_listings}'  => '{if_current_listings}',
            '{/if_current_listings}' => '{/if_current_listings}',
            '{if_pending_listings}'  => '{if_pending_listings}',
            '{/if_pending_listings}' => '{/if_pending_listings}',
            '{if_upcoming_listings}' => '{if_upcoming_listings}',
            '{/if_upcoming_listings}'=> '{/if_upcoming_listings}',
            '{if_closed_listings}'   => '{if_closed_listings}',
            '{/if_closed_listings}'  => '{/if_closed_listings}',
            '{if_adopted_out_listings}'  => '{if_adopted_out_listings}',
            '{/if_adopted_out_listings}' => '{/if_adopted_out_listings}',
            '{if_no_listings}'       => '{if_no_current_listings}',
            '{/if_no_listings}'      => '{/if_no_current_listings}',
            '{if_affiliates}'        => '{if_affiliates_sitewide}',
            '{/if_affiliates}'       => '{/if_affiliates_sitewide}',
            '{if_total_affiliates}'  => '{if_affiliates_total}',
            '{/if_total_affiliates}' => '{/if_affiliates_total}',
            // No Luster equivalent: {category_nav}, {category_dropdown}, {no_listings},
            // {fff_collective_badge}, {if_category_nav}, {if_category_dropdown},
            // {if_fff_collective_badge}, {content_list} (Fanatically alias not in Luster)
        ],

        'joined_listings' => [
            '{content}'             => '{current_content}',
            '{current_content}'     => '{current_content}',
            '{pending_content}'     => '{pending_content}',
            '{current_count}'       => '{current_count}',
            '{pending_count}'       => '{pending_count}',
            '{if_listings}'         => '{if_current_listings}',
            '{/if_listings}'        => '{/if_current_listings}',
            '{if_current_listings}' => '{if_current_listings}',
            '{/if_current_listings}'=> '{/if_current_listings}',
            '{if_pending_listings}' => '{if_pending_listings}',
            '{/if_pending_listings}'=> '{/if_pending_listings}',
            '{if_no_listings}'      => '{if_no_current_listings}',
            '{/if_no_listings}'     => '{/if_no_current_listings}',
            // No Luster equivalent: {category_nav}, {category_dropdown}, {no_listings},
            // {content_list}, {current_content_list}, {pending_content_list},
            // {if_category_nav}, {if_category_dropdown}
        ],

        'join_form' => [
            '{title}'       => '{listing_title}',
            '{listing_url}' => '{listing_url}',
            '{if_success}'  => '{if_success}',
            '{/if_success}' => '{/if_success}',
            // {form} is a pre-rendered HTML blob — no Luster equivalent; marks unsupportedStructure.
            // {subject} has no join-form token in Luster; {if_form}/{/if_form} unmapped.
        ],

        'update_form' => [
            '{title}'       => '{listing_title}',
            '{if_success}'  => '{if_success}',
            '{/if_success}' => '{/if_success}',
            '{if_pending}'  => '{if_pending}',
            '{/if_pending}' => '{/if_pending}',
            // {form} is a pre-rendered HTML blob — marks unsupportedStructure.
            // {subject} unmapped.
        ],

        'reset_form' => [
            '{title}'       => '{listing_title}',
            '{if_success}'  => '{if_success}',
            '{/if_success}' => '{/if_success}',
            '{if_form}'     => '{if_form}',
            '{/if_form}'    => '{/if_form}',
            // {form} is a pre-rendered HTML blob — marks unsupportedStructure.
            // {subject} unmapped.
        ],

        'affiliates' => [
            '{affiliates_count}' => '{affiliates_count}',
            '{apply_button}'     => '{affiliate_apply_toggle}',
            '{if_affiliates}'    => '{if_affiliates}',
            '{/if_affiliates}'   => '{/if_affiliates}',
            '{if_no_affiliates}' => '{if_no_affiliates}',
            '{/if_no_affiliates}'=> '{/if_no_affiliates}',
            // {affiliates}, {affiliates_list}, {no_affiliates}, {apply_form}:
            // pre-rendered blocks or form wrappers — marks unsupportedStructure.
        ],

        'codes' => [
            '{title}'       => '{listing_title}',
            '{listing_url}' => '{listing_url}',
            '{pagination}'  => '{pagination}',
            // {size_nav}, {codes_list}, {no_codes}, {donation_button}, {donation_form}:
            // pre-rendered blocks — marks unsupportedStructure.
        ],

        'wishlist' => [
            // Wishlist template: most structure is pre-rendered in Fanatically.
            // Luster's wishlist_template uses {each_wishlist_item} loops.
            // No safe flat-token mapping exists; any Fanatically wishlist with {wishlist_items}
            // or similar block tokens will be unsupportedStructure.
        ],

        'email_join' => [
            '{name}'            => '{fan_name}',
            '{listing_title}'   => '{listing_title}',
            '{password}'        => '{fan_password}',
            '{update_link}'     => '{form_url_update}',
            '{collective_name}' => '{site_name}',
            '{owner_name}'      => '{owner_name}',
        ],

        'email_approve' => [
            '{name}'            => '{fan_name}',
            '{listing_title}'   => '{listing_title}',
            '{update_link}'     => '{form_url_update}',
            '{collective_name}' => '{site_name}',
            '{owner_name}'      => '{owner_name}',
        ],

        'email_reset' => [
            '{name}'            => '{fan_name}',
            '{listing_title}'   => '{listing_title}',
            '{password}'        => '{fan_password}',
            '{update_link}'     => '{form_url_update}',
            '{collective_name}' => '{site_name}',
            '{owner_name}'      => '{owner_name}',
        ],

        'email_remove' => [
            '{name}'            => '{fan_name}',
            '{listing_title}'   => '{listing_title}',
            '{collective_name}' => '{site_name}',
            '{owner_name}'      => '{owner_name}',
        ],

        'email_update' => [
            '{name}'            => '{fan_name}',
            '{listing_title}'   => '{listing_title}',
            '{collective_name}' => '{site_name}',
            '{owner_name}'      => '{owner_name}',
        ],

        'email_notify_join' => [
            '{name}'            => '{fan_name}',
            '{listing_title}'   => '{listing_title}',
            '{collective_name}' => '{site_name}',
            '{owner_name}'      => '{owner_name}',
        ],

        'email_code_approved' => [
            '{name}'            => '{fan_name}',
            '{listing_title}'   => '{listing_title}',
            '{collective_name}' => '{site_name}',
            '{owner_name}'      => '{owner_name}',
        ],

        // Types with no safe mapping (null in TYPE_TO_TARGET_KEY) have no entry here —
        // they hit the null-target branch and become preview_only before token scanning.
    ];

    /**
     * Tokens that indicate an unsupported structure when present in a template body.
     * Keyed by template type.
     *
     * @var array<string, list<string>>
     */
    private const UNSUPPORTED_STRUCTURE_SIGNALS = [
        'join_form'   => ['{form}'],
        'update_form' => ['{form}'],
        'reset_form'  => ['{form}'],
        'affiliates'  => ['{affiliates}', '{affiliates_list}', '{apply_form}'],
        'codes'       => ['{codes_list}', '{size_nav}', '{donation_form}', '{donation_button}'],
        'members_row' => ['{member_name_cell}', '{custom_fields_cells}', '{member_country_cell}'],
        'wishlist'    => ['{wishlist_items}'],
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Translates a Fanatically template entry to its Luster equivalent.
     *
     * The source type is resolved from the source path via PATH_TO_TYPE. For
     * per-listing entries, classification is by basename only.
     *
     * @return TranslationResult
     */
    public function translate(string $sourcePath, string $category, string $content): TranslationResult
    {
        // Resolve type from the explicit allowlist.
        $type = $this->resolveType($sourcePath, $category);
        if ($type === null) {
            return new TranslationResult(
                translatedContent:    $content,
                unmappedTokens:       [],
                containsPhp:          $this->detectPhp($content),
                unsupportedStructure: true,
                targetTemplateKey:    null,
                importable:           false,
            );
        }

        // Resolve the Luster target key for this type.
        $targetKey = self::TYPE_TO_TARGET_KEY[$type] ?? null;
        if ($targetKey === null) {
            return new TranslationResult(
                translatedContent:    $content,
                unmappedTokens:       [],
                containsPhp:          $this->detectPhp($content),
                unsupportedStructure: true,
                targetTemplateKey:    null,
                importable:           false,
            );
        }

        $containsPhp = $this->detectPhp($content);
        $unsupported = $this->detectUnsupportedStructure($type, $content);

        if ($containsPhp || $unsupported) {
            return new TranslationResult(
                translatedContent:    $content,
                unmappedTokens:       $this->scanUnmapped($type, $content),
                containsPhp:          $containsPhp,
                unsupportedStructure: $unsupported,
                targetTemplateKey:    $targetKey,
                importable:           false,
            );
        }

        $tokenMap   = self::TOKEN_MAPS[$type] ?? [];
        $translated = $content;

        if (!empty($tokenMap)) {
            $translated = str_replace(
                array_keys($tokenMap),
                array_values($tokenMap),
                $content
            );
        }

        $unmapped = $this->scanUnmapped($type, $translated);

        $overLimit = strlen($translated) > self::MAX_TEMPLATE_BYTES;

        $importable = empty($unmapped) && !$containsPhp && !$unsupported && $targetKey !== null && !$overLimit;

        return new TranslationResult(
            translatedContent:    $translated,
            unmappedTokens:       $unmapped,
            containsPhp:          $containsPhp,
            unsupportedStructure: $unsupported,
            targetTemplateKey:    $targetKey,
            importable:           $importable,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves a source path to a template type string.
     * For per-listing entries, classifies by basename.
     */
    private function resolveType(string $sourcePath, string $category): ?string
    {
        // Direct lookup from the global path map.
        if (isset(self::PATH_TO_TYPE[$sourcePath])) {
            return self::PATH_TO_TYPE[$sourcePath];
        }

        // Per-listing: match by basename against the display/* map.
        if ($category === 'per-listing') {
            $basename = basename($sourcePath);
            $synth    = 'templates/display/' . $basename;
            return self::PATH_TO_TYPE[$synth] ?? null;
        }

        return null;
    }

    /**
     * Returns true when the content contains any PHP opening tag.
     * Checks <?php, <?=, and legacy <? (any character after <? other than xml).
     */
    private function detectPhp(string $content): bool
    {
        // `<?php` and `<?=` are caught by the first pattern.
        // Legacy short-open `<?` not followed by `xml` is caught by the second.
        if (stripos($content, '<?php') !== false) {
            return true;
        }
        if (str_contains($content, '<?=')) {
            return true;
        }
        // Match `<?` not immediately followed by `x` (to exclude `<?xml`).
        if (preg_match('/<\?(?!xml)/i', $content)) {
            return true;
        }
        return false;
    }

    /**
     * Returns true when the content contains a structural token that indicates
     * the template uses pre-rendered HTML blocks incompatible with Luster's system.
     */
    private function detectUnsupportedStructure(string $type, string $content): bool
    {
        $signals = self::UNSUPPORTED_STRUCTURE_SIGNALS[$type] ?? [];
        foreach ($signals as $signal) {
            if (str_contains($content, $signal)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scans the translated content for remaining `{token}` placeholders that have
     * no Luster equivalent. Only matches `{word}` and `{/word}` patterns.
     * CSS/JS braces are excluded by the word-character requirement.
     *
     * @return list<string>
     */
    private function scanUnmapped(string $type, string $content): array
    {
        // Extract all {token} and {/token} patterns. The regex requires at least one
        // word character after { or {/ to avoid matching CSS selector blocks.
        preg_match_all('/\{\/?\w[\w_-]*\}/', $content, $matches);

        $found = array_unique($matches[0]);

        // Anything that was produced BY the translation (i.e., is a known Luster token)
        // is not unmapped. Build a set of known Luster output tokens for this type.
        $knownLusterTokens = $this->knownLusterOutputTokens($type);

        $unmapped = [];
        foreach ($found as $token) {
            if (!in_array($token, $knownLusterTokens, true)) {
                $unmapped[] = $token;
            }
        }

        sort($unmapped);
        return $unmapped;
    }

    /**
     * Returns all Luster tokens that may legitimately appear in the output
     * for a given template type (values from the token map + known Luster primitives).
     *
     * @return list<string>
     */
    private function knownLusterOutputTokens(string $type): array
    {
        $map    = self::TOKEN_MAPS[$type] ?? [];
        $values = array_values($map);

        // Email templates have no structural tokens; return just the map values.
        if (str_starts_with($type, 'email_')) {
            return $values;
        }

        // For display types, also accept the full set of Luster structural tokens
        // that a well-formed template may use even if not in the translation map —
        // e.g. {each_member}, {fan_name} inside a members section the user wrote.
        // We don't want to flag tokens the user intentionally left as Luster-native.
        // Keep this list minimal: only tokens whose presence after translation is safe.
        $alwaysOk = [
            // Common safe Luster tokens across many template types:
            '{listing_title}', '{listing_url}', '{listing_subject}', '{site_name}',
            '{owner_name}', '{owner_email}', '{pagination}',
        ];

        return array_unique(array_merge($values, $alwaysOk));
    }
}
