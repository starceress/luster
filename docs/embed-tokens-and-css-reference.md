# Luster Embed Tokens and CSS Reference

Last audited: June 22, 2026.

This guide is the public embed reference for Luster. It covers:

- The PHP embed files in `public/embed/`
- The public default fragments in `templates/default/`
- The editable public template strings saved in Luster settings
- The baseline embed stylesheet at `public/assets/css/default/style.css`

It does not cover admin dashboard CSS or email-only template tokens. Those are
different surfaces.

## Quick Mental Model

An embed is the PHP file you include on another page. A token is a little
placeholder inside a template, like `{fan_name}`. A CSS class is the name you
style, like `.fl-member-name`.

Think of it like this:

| Thing | What it means | Example |
| --- | --- | --- |
| Embed file | The PHP file that prints a public Luster feature on a page. | `public/embed/members.php` |
| Snippet variable | A PHP variable you set before including the embed. | `$luster_per_page = 25;` |
| Template token | A word in braces that Luster replaces with real data. | `{listing_title}` |
| Loop block | A repeat area. Luster repeats the inside once per item. | `{each_listing}...{/each_listing}` |
| Conditional block | A show/hide area. Luster keeps it only when the condition is true. | `{if_listing_image}...{/if_listing_image}` |
| CSS class | A styling hook in the generated HTML. | `.fl-listing-item` |

Important rule: token names are local to their template. `{listing_title}` in a
stats template and `{listing_title}` in an owned-listing template both mean a
listing title, but they are supplied by different code paths.

Raw HTML tokens are intentionally rare. Most tokens are escaped as plain text.
Tokens ending in `_html`, plus the form-wrapper token `{input}`, are the main
HTML-ready exceptions.

## Embed Overview

Use this table to find the embed, the main classes it prints, and which token
family applies to it.

| Embed | Include file | What it does | Main CSS classes | Main token or template surface |
| --- | --- | --- | --- | --- |
| Join form | `public/embed/join.php` | Lets someone join one fanlisting. | `.fl-embed`, `.fl-embed--join`, form classes | Form field wrapper tokens, success template tokens |
| Members | `public/embed/members.php` | Shows approved members, country summary, sorting, filtering, and pagination. | `.fl-embed--members`, `.fl-member-*`, `.fl-pagination-*` | Member row tokens, pagination tokens |
| Update form | `public/embed/update.php` | Lets an existing member update their details. | `.fl-embed--update`, form classes | Form field wrapper tokens, success template tokens |
| Remove form | `public/embed/remove.php` | Lets a member remove themselves. | `.fl-embed--remove`, form classes | Form field wrapper tokens, success template tokens |
| Password reset | `public/embed/reset.php` | Shows the reset request form or the new-password confirmation form. | `.fl-embed--reset`, form classes | Form field wrapper tokens, success template tokens |
| Listing stats | `public/embed/stats.php` | Prints stats for one current fanlisting. | `.fl-stats`, `.fl-stat-*` | Listing stats tokens |
| Affiliates | `public/embed/affiliates.php` | Shows listing affiliates or collective affiliates, with optional pagination. | `.fl-embed--affiliates`, `.fl-affiliate-*` | Affiliate section tokens, pagination tokens |
| Affiliate application | `public/embed/affiliate-apply.php` | Shows a form to apply as an affiliate for a listing or the collective. | `.fl-embed--affiliate-apply`, form classes | Form field wrapper tokens, success template tokens |
| Codes | `public/embed/codes.php` | Shows approved codes for a listing or collective code gallery. Can append the code submission form. | `.fl-embed--codes`, `.fl-code-*` | Codes template tokens |
| Code submission | `public/embed/codes-submit.php` | Lets visitors donate/submit codes. | `.fl-embed--codes-submit`, form classes | Form field wrapper tokens, success template tokens |
| Contact | `public/embed/contact.php` | Contact form for one listing. | `.fl-embed--contact`, form classes | Form field wrapper tokens, contact success tokens |
| Collective contact | `public/embed/collective-contact.php` | Contact form for the whole site/collective. | `.fl-embed--contact`, form classes | Form field wrapper tokens, contact success tokens |
| Owned listings | `public/embed/owned.php` | Shows listings you own, with status and category options. | `.fl-embed--owned`, `.fl-category-*`, `.fl-listing-*` | Owned listing tokens |
| Joined listings | `public/embed/joined.php` | Shows fanlistings you have joined, with category options. | `.fl-embed--joined`, `.fl-category-*`, `.fl-joined-*` | Joined listing tokens |
| Latest owned listing | `public/embed/latest-listing.php` | Shows one newest owned listing. | Default uses `.fl-latest-listing-*`; no outer `.fl-embed--latest` wrapper | Latest listing tokens |
| Random owned listing | `public/embed/random-listing.php` | Shows one random owned listing. | Default uses `.fl-random-listing-*`; no outer `.fl-embed--random` wrapper | Random listing tokens |
| Wishlist | `public/embed/wishlist.php` | Shows collective wishlist entries. | `.fl-embed--wishlist`, `.fl-wishlist-*` | Wishlist tokens |
| Per-listing updates | `public/embed/updates.php` | Shows updates for one fanlisting. | `.fl-embed--updates`, `.fl-update-*`, pagination classes | Updates tokens, pagination tokens |
| Collective updates | `public/embed/collective-updates.php` | Shows site-wide updates. | `.fl-embed--collective-updates`, `.fl-update-*`, pagination classes | Updates tokens, pagination tokens |
| KIM overview/subject list | `public/embed/kim-list.php` | Shows the KIM overview (all subjects) or the KIM member list for one subject, depending on `$luster_kim_subject_id`. | `.fl-embed--kim-list`, `.fl-kim-*` | KIM List Overview tokens (overview mode), KIM List Subject Page tokens (subject mode), or `kim_member_template` (legacy row-only fallback in subject mode) |
| KIM signup | `public/embed/kim-signup.php` | Lets someone sign up for KIM notifications. | `.fl-embed--kim-signup`, `.fl-kim`, form classes | Form field wrapper tokens, success template tokens |
| KIM update | `public/embed/kim-update.php` | Lets someone update their KIM entry. | `.fl-embed--kim-update`, `.fl-kim`, form classes | KIM Update Form tokens (when template is set), or form field wrapper tokens |
| KIM reset | `public/embed/kim-reset.php` | Lets someone reset their KIM password. | `.fl-embed--kim-reset`, `.fl-kim`, form classes | KIM Reset Form tokens (when template is set), or form field wrapper tokens |
| Adoption application | `public/embed/adopt.php` | Adoption form where visitors pick which listing to apply for from a dropdown. No `$luster_fanlisting_id` needed. Requires the Adoption add-on. | `.fl-embed--adopt`, form classes | Collective Adoption Form tokens, form field wrapper tokens, success template tokens |
| Join Rules gate | `public/embed/rules-gate.php` | Shows joining rules and an agreement checkbox. Returns an empty string once the fan has agreed this session. Place it before the join embed; stop rendering if the return value is non-empty. | `.fl-embed--rules-gate`, `.fl-rules-gate`, form classes | Join Rules intro template tokens |
| Collective stats | `public/embed/collective-stats.php` | Exposes site-wide stats as PHP variables, and can return/render an HTML stats template. | `.fl-cstats`, `.fl-cstats-*` (used by both the default template and the built-in fallback) | Collective stats tokens and exposed PHP variables |

Notes:

- `stats.php`, `latest-listing.php`, `random-listing.php`, and
  `collective-stats.php` do not currently add their own `.fl-embed--...` root
  wrapper around the rendered template output.
- The default full-page public routes and the server-side embeds share many of
  these fragments, but their include paths are not the same thing.

## Snippet PHP Variables

These are not template tokens. They are PHP variables you set above the
`require` line in an embed snippet.

| Variable | Used by | What it does |
| --- | --- | --- |
| `$luster_fanlisting_id` | Per-listing embeds | Chooses which fanlisting the embed belongs to. Use `0` for collective affiliates/codes/affiliate application. |
| `$luster_return` | Most embeds | When `true`, the embed returns an HTML string instead of echoing it immediately. |
| `$luster_redirect_url` | Public form embeds | Advanced override for where a successful form action redirects. Used by join, update, remove, reset, adopt, affiliate apply, code submit, collective contact, KIM forms, and the rules gate. |
| `$luster_per_page` | Members, affiliates, updates | Chooses how many rows/items to show per page. |
| `$luster_sort_by` | Members | Forces the starting member sort. Allowed values are `name`, `country`, and `added`. |
| `$luster_show_search` | Members | Shows or hides member sorting/filter controls. |
| `$luster_members_base` | Members | Sets the URL used for member sort, filter, and pagination links. |
| `$luster_lost_password_url` | Update, remove | Overrides the forgot-password link URL. |
| `$luster_stats_template` | Stats | Inline template override for one stats embed. |
| `$luster_affiliates_base` | Affiliates | Sets the URL used for affiliate pagination links. |
| `$luster_page` | Affiliates | Chooses which affiliate page number to show first. |
| `$luster_append_submit` | Codes | Set to `false` to stop `codes.php` from appending the code submission form after the gallery. |
| `$luster_show_cats` | Updates | Shows or hides category labels on updates. |
| `$luster_contact_action` | Contact embeds | Overrides the form POST URL. Useful when embedding on an external page. |
| `$luster_status` | Owned, random listing | Chooses owned-listing status pool. Owned allows `current`, `upcoming`, `pending`, `all`; random allows `current`, `upcoming`, `all`. |
| `$luster_category_id` | Owned | Shows owned listings in one category. If omitted, URL query parameters can choose the category. |
| `$luster_hide_dropdown` | Owned | Hides the owned category dropdown. |
| `$luster_browse_by_category` | Owned, joined | Shows a category index first, then listings after a visitor chooses a category. |
| `$luster_exclude_pending` | Owned | When status is `all`, keeps pending/inactive listings hidden unless set to `false`. |
| `$luster_category` | Joined | Chooses joined category. Use `''` for selector mode, `'all'` for all active listings grouped by category, or an exact category name. |
| `$luster_show_all_by_default` | Joined | Shows all joined listings immediately while still showing the selector. |
| `$luster_wishlist_type` | Wishlist | Filters wishlist entries. Allowed values: `all`, `wanted`, `top`, `granted`. |
| `$luster_kim_signup_url` | KIM embeds | Overrides the public KIM signup page link. |
| `$luster_kim_update_url` | KIM form embeds | Overrides the public KIM update page link. |
| `$luster_kim_reset_url` | KIM form embeds | Overrides the public KIM reset page link. |
| `$luster_kim_list_url` | KIM form embeds | Overrides the public KIM list page link. |
| `$luster_kim_list_base` | `kim-list.php` | In overview mode, subject links become this URL plus `?id=N`. In subject mode, this is the back-to-list URL. |
| `$luster_kim_subject_id` | `kim-list.php` | When greater than `0`, shows approved KIM members for that fanlisting instead of the overview. |
| `$luster_kim_only_with_signups` | `kim-list.php` | Overview mode only. When `true` (default), only shows fanlistings that have at least one approved KIM member. Set to `false` to list all fanlistings regardless. |
| `$luster_count` | Collective stats | How many newest listings are fetched for newest arrays. Default is `3`. |
| `$luster_cache_ttl` | Collective stats | Cache lifetime in seconds for scalar/category collective stats. Use `0` for no cache. |

## Template Token Rules

| Pattern | Meaning | Example |
| --- | --- | --- |
| `{token}` | Replace with one value. | `{fan_name}` |
| `{each_name}...{/each_name}` | Repeat the inside once per row. | `{each_listing}<li>{listing_title}</li>{/each_listing}` |
| `{if_name}...{/if_name}` | Keep the inside only when true. | `{if_listing_image}<img src="{listing_image_url}">{/if_listing_image}` |
| `{if_no_name}...{/if_no_name}` | Usually the opposite of `{if_name}` when that inverse condition is supplied. | `{if_no_affiliates}No affiliates yet.{/if_no_affiliates}` |
| `{input}` | Raw form input HTML inside the field wrapper template. | `{input}` |

## Listing Stats Tokens

Used by `stats_template`, `$luster_stats_template`, and `public/embed/stats.php`.

Related classes: `.fl-stats`, `.fl-stats-grid`,
`.fl-stats-label`, `.fl-stats-value`, `.fl-stat-newmembers`,
`.fl-stat-approved-by`.

| Token | What it means |
| --- | --- |
| `{stat_opened}` | Date the listing opened, formatted for display. |
| `{stat_updated}` | Most recent activity date — whichever is later: the newest approved member's join date or the admin-set last-updated date. Shows `N/A` when neither is set. |
| `{stat_approved}` | Number of approved members. |
| `{stat_pending}` | Number of pending members. |
| `{stat_countries}` | Number of distinct countries represented by approved members. |
| `{stat_newmembers}` | Newest approved members, hyperlinked to their websites when available (respects the member's show-URL preference). Count is configurable per listing or globally (default 5). Appends ", and more..." when total approved members exceeds the limit. Set limit to 0 to show all. Raw HTML. |
| `{stat_growth}` | Average approved members per day since the listing opened, with unit (e.g. `0.15 members/day`). |
| `{stat_growth_rate}` | Numeric growth rate only (e.g. `0.15`). Use to build your own formatting. |
| `{listing_title}` | Listing title. |
| `{listing_url}` | Listing URL. |
| `{approved_by}` | Uppercase network acronym: `TFL`, `TAFL`, or empty string. Plain text. |
| `{network_name}` | Full network name: `The Fanlistings Network`, `The Anime Fanlistings Network`, or empty. |
| `{tfl_url}` | TFL website URL: `https://thefanlistings.org/` |
| `{tafl_url}` | TAFL website URL: `https://www.animefanlistings.com/` |
| `{if_approved_by}...{/if_approved_by}` | Shows the inside only when `{approved_by}` has a value. |
| `{if_tfl}...{/if_tfl}` | Shows the inside only when the listing is TFL-approved. |
| `{if_tafl}...{/if_tafl}` | Shows the inside only when the listing is TAFL-approved. |
| `{if_stat_pending}...{/if_stat_pending}` | Shows the inside only when the pending member count is greater than zero. |

## Member Template Tokens

Used by `members_template` on the public members route and members embed.

Related classes: `.fl-member-list`, `.fl-member-item`, `.fl-member-name`,
`.fl-member-country`, `.fl-member-email`, `.fl-member-field`,
`.fl-member-field-label`, `.fl-member-field-value`.

Two render modes are supported, shared by the full public members route,
`public/embed/members.php`, and the admin Templates-tab preview:

- **New-style** — the saved template contains `{each_member}...{/each_member}`.
  That block is extracted and repeated once per member; everything outside it
  renders once as a page-level wrapper. Page-level conditionals
  (`{if_members}...{/if_members}`, `{if_no_members}...{/if_no_members}`) and
  page-level tokens (`{join_url}`, `{member_count}`, `{stats_line}`,
  `{sort_link_all}`, `{sort_link_name}`, `{sort_link_country}`) are only
  processed in this mode.
- **Legacy** — no `{each_member}` block. The entire saved template is treated
  as a per-member row and concatenated once per member (rows joined by
  newlines). Page-level tokens/conditionals above are not processed in this
  mode.

Both modes support per-member conditionals:

- `{if_fan_url}...{/if_fan_url}` / `{if_no_fan_url}...{/if_no_fan_url}`
- `{if_fan_country}...{/if_fan_country}` / `{if_no_fan_country}...{/if_no_fan_country}`

| Token | What it means |
| --- | --- |
| `{fan_name}` | Member display name. |
| `{fan_url}` | Member website URL. |
| `{fan_email}` | Member email address. Use carefully because it prints publicly. |
| `{fan_country}` | Member country. |
| `{fan_FIELDNAME}` | A custom additional field value. Replace `FIELDNAME` with the field's machine name, such as `{fan_fave_song}`. Supported everywhere: full public route, members embed, and admin preview. |
| `{join_url}` | New-style only. URL to the join page. |
| `{member_count}` | New-style only. Total member count. |
| `{stats_line}` | New-style only. Summary line text, or blank. |
| `{sort_link_all}` / `{sort_link_name}` / `{sort_link_country}` | New-style only. Pre-built sort links, or blank. |

## Owned Listing Tokens

Used by `owned_template` and `public/embed/owned.php`.

Related classes: `.fl-listing-list`, `.fl-listing-item`, `.fl-listing-*`,
`.fl-listing-section`.

### Owned Loops

| Token | What it means |
| --- | --- |
| `{each_listing}...{/each_listing}` | Flat mode. Repeats the inside once for every owned listing shown. |
| `{each_current}...{/each_current}` | Sectioned mode. Repeats the inside for current owned listings. |
| `{each_upcoming}...{/each_upcoming}` | Sectioned mode. Repeats the inside for upcoming owned listings. |
| `{each_pending}...{/each_pending}` | Sectioned mode. Repeats the inside for pending/inactive owned listings. |

### Owned Row Tokens

| Token | What it means |
| --- | --- |
| `{listing_title}` | Listing title/name. |
| `{listing_url}` | Listing URL. |
| `{listing_subject}` | Listing subject/topic. |
| `{listing_status}` | `current`, `upcoming`, or `pending`. |
| `{listing_email}` | Listing contact email. |
| `{listing_type}` | Listing type value. |
| `{listing_category}` | Comma-separated category names for the listing. |
| `{listing_image_url}` | Direct URL to the listing image, or blank. |
| `{date_opened}` | Listing opened date. |
| `{date_updated}` | Most recent of: newest approved member date or admin-set last-updated date. |
| `{member_count}` | Approved member count. |
| `{member_pending_count}` | Pending member count for this listing. |
| `{country_count}` | Number of distinct countries represented by approved members. |
| `{approved_by}` | Uppercase network acronym: `TFL`, `TAFL`, or empty string. Plain text. |
| `{network_name}` | Full network name: `The Fanlistings Network`, `The Anime Fanlistings Network`, or empty. |
| `{tfl_url}` | TFL website URL: `https://thefanlistings.org/` |
| `{tafl_url}` | TAFL website URL: `https://www.animefanlistings.com/` |
| `{listing_description}` | Free-text listing description, or blank when none is set. Requires Migration 74. |

### Owned Row Conditionals

| Conditional | What it means |
| --- | --- |
| `{if_listing_image}...{/if_listing_image}` | Shows only when the listing has an image. |
| `{if_no_listing_image}...{/if_no_listing_image}` | Shows only when the listing has no image. |
| `{if_description}...{/if_description}` | Shows only when `{listing_description}` is non-empty. Requires Migration 74. |
| `{if_countries}...{/if_countries}` | Shows only when country count is greater than zero. |
| `{if_pending}...{/if_pending}` | Shows only when pending member count is greater than zero. |
| `{if_status_current}...{/if_status_current}` | Shows only for current listings. |
| `{if_status_upcoming}...{/if_status_upcoming}` | Shows only for upcoming listings. |
| `{if_status_pending}...{/if_status_pending}` | Shows only for pending listings. |
| `{if_date_updated}...{/if_date_updated}` | Shows only when the last-updated date is set. |
| `{if_approved_by}...{/if_approved_by}` | Shows only when `{approved_by}` is non-empty (listing is approved by a network). |

### Owned Section Tokens

These apply to the outer shell of a sectioned owned template.

| Token | What it means |
| --- | --- |
| `{current_owned_count}` | Number of current listings in the rendered set. |
| `{upcoming_owned_count}` | Number of upcoming listings in the rendered set. |
| `{pending_owned_count}` | Number of pending listings in the rendered set. |
| `{total_count}` | Total number of listings in the rendered set. |
| `{if_current_listings}...{/if_current_listings}` | Shows only when current listings exist. |
| `{if_upcoming_listings}...{/if_upcoming_listings}` | Shows only when upcoming listings exist. |
| `{if_pending_listings}...{/if_pending_listings}` | Shows only when pending listings exist. |

## Random Listing Tokens

Used by `random_listing_template` and `public/embed/random-listing.php`. Always
renders exactly one listing chosen at random from the active pool.

Default CSS classes: `.fl-random-listing`, `.fl-random-listing-item`,
`.fl-random-listing-image-wrap`, `.fl-random-listing-image`,
`.fl-random-listing-body`, `.fl-random-listing-title`, `.fl-random-listing-subject`.

Supports the same row tokens, row conditionals, and loops as owned listing tokens.
The `{each_listing}...{/each_listing}` loop is required around your row design
for token replacement to work. Sectioned mode (`{each_current}` etc.) is also
supported — the single listing lands in its matching section.

## Latest Listing Tokens

Used by `latest_listing_template` and `public/embed/latest-listing.php`. Always
renders exactly one listing — the one with the most recent open date.

Default CSS classes: `.fl-latest-listing`, `.fl-latest-listing-item`,
`.fl-latest-listing-image-wrap`, `.fl-latest-listing-image`,
`.fl-latest-listing-body`, `.fl-latest-listing-title`,
`.fl-latest-listing-subject`, `.fl-latest-listing-date`.

Supports the same row tokens, row conditionals, and loops as owned listing tokens.
The `{each_listing}...{/each_listing}` loop is required around your row design
for token replacement to work.

## Joined Listing Tokens

Used by `joined_template` and `public/embed/joined.php`.

Related classes: `.fl-joined-list`, `.fl-joined-*`, `.fl-category-*`.

Joined templates use `{listing_image_url}` for the image URL and
`{if_listing_image}` / `{if_no_listing_image}` conditionals for image presence.

### Joined Loops

| Token | What it means |
| --- | --- |
| `{each_joined}...{/each_joined}` | Flat mode. Repeats once for each joined listing shown. |
| `{each_current_joined}...{/each_current_joined}` | Section-style mode for active joined listings. The data is ordered by category, but the loop does not create category headers by itself. |

### Joined Row Tokens

| Token | What it means |
| --- | --- |
| `{listing_subject}` | What the joined fanlisting is about, such as Archeology or Marilyn Monroe. |
| `{listing_url}` | Joined listing URL. |
| `{listing_category}` | Joined listing category. |
| `{listing_image_url}` | Direct URL to the joined listing image, or blank. |
| `{listing_status}` | Joined listing status text. |
| `{date_joined}` | Date you joined the listing. |
| `{if_listing_image}...{/if_listing_image}` | Shows only when the joined listing has an image. |
| `{if_no_listing_image}...{/if_no_listing_image}` | Shows only when the joined listing has no image. |

### Joined Section Tokens

| Token | What it means |
| --- | --- |
| `{current_count}` | Number of active joined listings in the rendered set. |
| `{total_count}` | Total number of joined listings in the rendered set. |
| `{if_current_listings}...{/if_current_listings}` | Shows only when active joined listings exist. |

## Affiliate Section Tokens

Used by per-listing `affiliates_section_template` and collective
`collective_affiliates_section_template`.

Related classes: `.fl-affiliate-list`, `.fl-affiliate-item`,
`.fl-affiliate-image`, `.fl-affiliate-name`, `.fl-affiliates-section`,
`.fl-affiliates-heading`, `.fl-affiliates-empty`,
`.fl-affiliate-apply-collapsible`, `.fl-affiliate-apply-toggle`,
`.fl-affiliate-apply-toggle--link`, `.fl-affiliate-apply-toggle--button`,
`.fl-affiliate-apply-form-wrap`.

| Token | What it means |
| --- | --- |
| `{each_affiliate}...{/each_affiliate}` | Repeats the inside once per affiliate on the current page. |
| `{aff_name}` | Affiliate site name. |
| `{aff_url}` | Affiliate site URL. |
| `{aff_image_url}` | Full affiliate image URL, or empty string when the affiliate has no image. Use inside `{if_aff_image}` to avoid broken `<img>` tags. |
| `{affiliate_apply_url}` | URL to the affiliate application form for this listing. |
| `{affiliate_apply_toggle}` | Collapsible inline affiliate application form. Renders a `<details>/<summary>` element wrapping the full application form. Toggle style (text link or button) is set per listing in Preferences. Empty when affiliate applications are disabled. |
| `{affiliates_count}` | Total active affiliate count for this embed. |
| `{if_affiliates}...{/if_affiliates}` | Shows only when at least one affiliate exists. |
| `{if_no_affiliates}...{/if_no_affiliates}` | Shows only when no affiliates exist. |
| `{if_aff_image}...{/if_aff_image}` | Inside `{each_affiliate}`: shows only when the affiliate has an image. |
| `{if_no_aff_image}...{/if_no_aff_image}` | Inside `{each_affiliate}`: shows only when the affiliate has no image. Use to render a fallback text link. |

## Collective Stats Tokens

Used by `collective_stats_template` and `public/embed/collective-stats.php`.

Related classes: `.fl-cstats`, `.fl-cstats-grid`, `.fl-cstats-label`,
`.fl-cstats-value`, `.fl-cstats-newest`, `.fl-cstats-random`,
`.fl-cstats-categories`.

| Token | What it means |
| --- | --- |
| `{owned_active}` | Number of current/open owned listings. |
| `{owned_upcoming}` | Number of upcoming owned listings. |
| `{owned_pending}` | Number of pending/inactive owned listings. |
| `{owned_tfl_count}` | Number of active owned listings approved by TFL. |
| `{owned_tafl_count}` | Number of active owned listings approved by TAFL. |
| `{joined_active}` | Number of active joined listings. |
| `{joined_tfl_count}` | Number of active joined listings in TFL categories. |
| `{joined_tafl_count}` | Number of active joined listings in TAFL categories. |
| `{members_total}` | Total members across all listings, including pending. |
| `{members_approved}` | Approved members across all listings. |
| `{members_pending}` | Pending members across all listings. |
| `{countries_count}` | Number of distinct countries across approved members. |
| `{owned_newest_title}` | Title of the newest current owned listing, or blank. |
| `{owned_newest_subject}` | What the newest owned listing is about, or blank. |
| `{owned_newest_url}` | URL of the newest current owned listing, or blank. |
| `{owned_random_title}` | Title of one random current owned listing, or blank. |
| `{owned_random_subject}` | What a random owned listing is about, or blank. |
| `{owned_random_url}` | URL of one random current owned listing, or blank. |
| `{tfl_url}` | TFL website URL: `https://thefanlistings.org/` |
| `{tafl_url}` | TAFL website URL: `https://www.animefanlistings.com/` |
| `{if_countries}...{/if_countries}` | Shows only when countries count is greater than zero. |

## Collective Stats PHP Variables

`collective-stats.php` also exposes PHP variables after you `require` it. These
are not brace tokens. You echo them in PHP.

| Variable | What it means |
| --- | --- |
| `$luster_owned` | Total owned listings. |
| `$luster_owned_active` | Current/open owned listings. |
| `$luster_owned_upcoming` | Upcoming owned listings. |
| `$luster_owned_pending` | Pending/inactive owned listings. |
| `$luster_joined` | Total joined listings. |
| `$luster_joined_active` | Active joined listings. |
| `$luster_joined_approved` | Active joined listings that are not upcoming or pending. |
| `$luster_joined_upcoming` | Upcoming joined listings. |
| `$luster_joined_pending` | Pending joined listings. |
| `$luster_affiliates` | Active collective affiliates. |
| `$luster_members_approved` | Approved members across all listings. |
| `$luster_members_pending` | Pending members across all listings. |
| `$luster_members_total` | Total members across all listings. |
| `$luster_owned_growth_rate` | Average current owned listings added per year. |
| `$luster_joined_growth_rate` | Average active joined listings added per year. |
| `$luster_members_growth_rate` | Average approved members added per day. |
| `$luster_owned_newest` | Array of newest current owned `Listing` objects. |
| `$luster_joined_newest` | Array of newest active joined `JoinedListing` objects. |
| `$luster_owned_random` | One random current owned `Listing` object, or `null`. |
| `$luster_joined_random` | One random active joined `JoinedListing` object, or `null`. |
| `$luster_categories` | Array of owned-listing categories with counts. |
| `$luster_countries_count` | Distinct countries across approved members. |
| `$luster_total_cats` | Alias for the number of owned-listing categories. |
| `$luster_owned_current` | Alias for `$luster_owned_active`. |
| `$luster_collective_fans` | Alias for `$luster_members_total`. |

Enthusiast-compatible aliases are also created:

| Alias | Maps to |
| --- | --- |
| `$total_cats`, `$collective_categories` | Number of owned-listing categories. |
| `$owned` | `$luster_owned` |
| `$owned_current` | `$luster_owned_active` |
| `$owned_upcoming` | `$luster_owned_upcoming` |
| `$owned_pending` | `$luster_owned_pending` |
| `$joined`, `$joined_total` | `$luster_joined` |
| `$joined_current` | `$luster_joined_active` |
| `$joined_approved` | `$luster_joined_approved` |
| `$joined_upcoming` | `$luster_joined_upcoming` |
| `$joined_pending` | `$luster_joined_pending` |
| `$affiliates_collective`, `$collective_affiliates` | `$luster_affiliates` |
| `$collective_total_fans_approved` | `$luster_members_approved` |
| `$collective_total_fans_pending` | `$luster_members_pending` |
| `$collective_total_fans` | `$luster_members_total` |
| `$countries_count` | `$luster_countries_count` |
| `$owned_growth_rate` | `$luster_owned_growth_rate` |
| `$joined_growth_rate` | `$luster_joined_growth_rate` |
| `$collective_fans_growth_rate` | `$luster_members_growth_rate` |
| `$owned_newest`, `$owned_random` | Flat arrays with keys `url`, `name`, `title`, `subject`, `desc`, `listingtype`, `opened`. |
| `$joined_newest`, `$joined_random` | Flat arrays with keys `url`, `name`, `subject`, `desc`, `category`, `added`. |

## Pagination Tokens

Used by per-listing `pagination_template`, collective pagination templates, and
the shared `Pagination` renderer.

Related classes: `.fl-pagination-nav`, `.fl-pagination-wrap`,
`.fl-page-prev`, `.fl-page-next`, `.fl-page-link`, `.fl-page-current`,
`.fl-page-gap`.

| Token | What it means |
| --- | --- |
| `{if_pages}...{/if_pages}` | Shows only when there is more than one page. |
| `{current_page}` | Current page number. |
| `{total_pages}` | Total page count. |
| `{prev_url}` | URL for the previous page, or blank. |
| `{next_url}` | URL for the next page, or blank. |
| `{if_prev}...{/if_prev}` | Shows only when a previous page exists. |
| `{if_next}...{/if_next}` | Shows only when a next page exists. |
| `{each_page}...{/each_page}` | Repeats for each page item in the pagination window. |
| `{page_label}` | Page number label, or gap label. |
| `{page_url}` | URL for a linked page item, or blank for current/gap items. |
| `{if_current}...{/if_current}` | Shows only for the current page item. |
| `{if_linked}...{/if_linked}` | Shows only for clickable page items. |
| `{if_gap}...{/if_gap}` | Shows only for gap items. |

## Form Field Wrapper Tokens

Used by the editable `field_wrapper_template` around rendered form inputs.

Related classes: `.fl-field`, `.fl-label`, `.fl-input`, `.fl-select`,
`.fl-textarea`, `.fl-field-hint`, `.fl-field-error`, `.fl-error`.

| Token | What it means |
| --- | --- |
| `{input}` | Raw, trusted input/select/textarea HTML generated by Luster. |
| `{label}` | Field label text. |
| `{field_id}` | Input ID, when the field has one. |
| `{required_html}` | A `<span class="fl-required">*</span>` when the field is required; empty when optional. Raw HTML, not escaped. |
| `{hint}` | Help text for the field. |
| `{error}` | Validation error text for the field. |
| `{lost_password_url}` | Password reset URL for forms that need a forgot-password link. |
| `{if_field_id}...{/if_field_id}` | Shows only when a field ID exists. |
| `{if_no_field_id}...{/if_no_field_id}` | Shows only when no single field ID exists. |
| `{if_hint}...{/if_hint}` | Shows only when hint text exists. |
| `{if_error}...{/if_error}` | Shows only when an error exists. |
| `{if_lost_password_url}...{/if_lost_password_url}` | Shows only when a lost-password URL exists. |

## Success Template Tokens

Used by public form success/action confirmation templates.

Related classes: `.fl-success`, `.fl-success-body`, `.fl-success-actions`.

### Success Value Tokens

| Token | What it means |
| --- | --- |
| `{action}` | The action slug, such as `joined-pending` or `contact-sent`. |
| `{fan_name}` | Fan/member name when available. |
| `{listing_title}` | Listing title when available. |
| `{listing_url}` | Listing URL when available. |
| `{sender_name}` | Contact sender name. |
| `{sender_email}` | Contact sender email. |
| `{site_name}` | Site or collective name. |
| `{owner_name}` | Owner/admin name. |
| `{owner_email}` | Owner/admin email. |
| `{aff_name}` | Affiliate applicant site name. |
| `{aff_url}` | Affiliate applicant URL. |
| `{aff_email}` | Affiliate applicant email. |

### Success Conditionals

| Conditional | What it means |
| --- | --- |
| `{if_joined}...{/if_joined}` | Shows only after an auto-approved join. |
| `{if_joined_pending}...{/if_joined_pending}` | Shows only after a join awaiting approval. |
| `{if_updated}...{/if_updated}` | Shows only after an approved update. |
| `{if_updated_pending}...{/if_updated_pending}` | Shows only after an update awaiting approval. |
| `{if_removed}...{/if_removed}` | Shows only after removal. |
| `{if_reset_sent}...{/if_reset_sent}` | Shows only after a reset email is requested. |
| `{if_reset_done}...{/if_reset_done}` | Shows only after password reset completion. |
| `{if_code_submitted}...{/if_code_submitted}` | Shows only after a code submission. |
| `{if_kim_signed_up}...{/if_kim_signed_up}` | Shows only after a KIM signup. |
| `{if_kim_updated}...{/if_kim_updated}` | Shows only after a KIM update. |
| `{if_kim_reset_done}...{/if_kim_reset_done}` | Shows only after KIM password reset completion. |
| `{if_adopt_submitted}...{/if_adopt_submitted}` | Shows only after an adoption application. |
| `{if_contact_sent}...{/if_contact_sent}` | Shows only after a contact form send. |
| `{if_affiliate_apply_sent}...{/if_affiliate_apply_sent}` | Shows only after an affiliate application. |
| `{if_aff_email}...{/if_aff_email}` | Shows only when affiliate email is present. |
| `{if_no_aff_email}...{/if_no_aff_email}` | Shows only when affiliate email is blank. |

## Codes Template Tokens

Used by per-listing `codes_template` in `public/embed/codes.php`. Collective
codes currently use the built-in default output, not a per-listing template.

Related classes: `.fl-codes`, `.fl-code-group`, `.fl-code-group-label`,
`.fl-code-list`, `.fl-code-item`, `.fl-code-image`, `.fl-code-caption`.

### Codes Loops

| Token | What it means |
| --- | --- |
| `{each_code}...{/each_code}` | Flat mode. Repeats once per approved code. |
| `{each_size_group}...{/each_size_group}` | Grouped mode. Repeats once per code size group. |
| Nested `{each_code}...{/each_code}` | In grouped mode, repeats once per code inside the current size group. |

### Codes Group Tokens

| Token | What it means |
| --- | --- |
| `{group_size}` | Size label for the group, such as `50x50px` or `Other`. |
| `{group_code_count}` | Number of codes in the current group. |

### Codes Row Tokens

| Token | What it means |
| --- | --- |
| `{code_url}` | Direct URL to the code image. |
| `{code_width}` | Code width, or blank. |
| `{code_height}` | Code height, or blank. |
| `{code_size}` | Size label, such as `50x50`, or blank. |
| `{code_name}` | Optional per-code label/name. |
| `{code_donor_name}` | Donor/submitter name, or blank. |
| `{code_donor_url}` | Donor/submitter URL, or blank. |

### Codes Conditionals

| Conditional | What it means |
| --- | --- |
| `{if_size}...{/if_size}` | Shows only when width and height are known. |
| `{if_size_changed}...{/if_size_changed}` | Flat mode only. Shows when the code size differs from the previous code. |
| `{if_donor}...{/if_donor}` | Shows only when donor name exists. |
| `{if_no_donor}...{/if_no_donor}` | Shows only when donor name is blank. |
| `{if_donor_url}...{/if_donor_url}` | Shows only when donor URL exists. |
| `{if_no_donor_url}...{/if_no_donor_url}` | Shows only when donor URL is blank. |
| `{if_code_name}...{/if_code_name}` | Shows only when code name exists. |

## Updates Template Tokens

Used by per-listing `updates_template` and collective
`collective_updates_template`.

Related classes: `.fl-updates`, `.fl-update-item`, `.fl-update-header`,
`.fl-update-title`, `.fl-update-date`, `.fl-update-body`,
`.fl-update-meta`, `.fl-update-categories`, `.fl-update-category-tag`.

| Token | What it means |
| --- | --- |
| `{each_update}...{/each_update}` | Repeats once per update on the current page. |
| `{update_title}` | Update title. |
| `{update_date}` | Display date, formatted like `June 8, 2026`. |
| `{update_body_html}` | Update body as safe generated HTML with line breaks. |
| `{update_author}` | Update author, or blank. |
| `{update_categories}` | Comma-separated update category names. |
| `{if_author}...{/if_author}` | Shows only when author exists. |
| `{if_categories}...{/if_categories}` | Shows only when categories exist. |

## Wishlist Template Tokens

Used by collective `wishlist_template` and `public/embed/wishlist.php`.

Related classes: `.fl-wishlist-group`, `.fl-wishlist-list`,
`.fl-wishlist-item`, `.fl-wishlist-image`, `.fl-wishlist-info`,
`.fl-wishlist-subject`, `.fl-wishlist-category`,
`.fl-wishlist-description`, `.fl-wishlist-type-badge`.

| Token | What it means |
| --- | --- |
| `{each_wishlist_item}...{/each_wishlist_item}` | Repeats once per wishlist entry. |
| `{item_subject}` | Wishlist subject/title. |
| `{item_url}` | Optional URL for the subject. |
| `{item_type}` | Raw type value: `top`, `wanted`, or `granted`. |
| `{item_type_label}` | Human-readable type label. |
| `{item_category}` | Wishlist category, or blank. |
| `{item_description}` | Wishlist description, or blank. |
| `{item_image_url}` | Direct URL to the wishlist image, or blank. |
| `{if_item_url}...{/if_item_url}` | Shows only when URL exists. |
| `{if_no_item_url}...{/if_no_item_url}` | Shows only when URL is blank. |
| `{if_item_category}...{/if_item_category}` | Shows only when category exists. |
| `{if_item_description}...{/if_item_description}` | Shows only when description exists. |
| `{if_item_image}...{/if_item_image}` | Shows only when image exists. |
| `{if_type_top}...{/if_type_top}` | Shows only for top-wanted entries. |
| `{if_type_wanted}...{/if_type_wanted}` | Shows only for wanted entries. |
| `{if_type_granted}...{/if_type_granted}` | Shows only for granted entries. |

## KIM Collective Templates

The KIM add-on supports four editable collective templates. Each is edited in
Admin → Collective → Templates under the KIM group. When a template is blank,
the built-in default form or list is used instead.

### KIM List Overview Template Tokens

Used by `collective_kim_list_overview_template` when `kim-list.php` is in
overview mode (i.e. `$luster_kim_subject_id` is `0` or unset) and the template
string is non-empty. Edit in Admin → Collective → Templates.

| Token | What it means |
| --- | --- |
| `{each_kim_listing}...{/each_kim_listing}` | Repeats the inside once per fanlisting that has at least one approved KIM member. |
| `{listing_title}` | Fanlisting display name. Use inside `{each_kim_listing}`. |
| `{listing_subject}` | What the fanlisting is about. Use inside `{each_kim_listing}`. |
| `{listing_url}` | Link to the fanlisting. Use inside `{each_kim_listing}`. |
| `{listing_kim_url}` | Link to this fanlisting's KIM member list. Use inside `{each_kim_listing}`. |
| `{listing_kim_count}` | Number of approved KIM members for this fanlisting. Use inside `{each_kim_listing}`. |
| `{if_has_listings}...{/if_has_listings}` | Shows only when at least one fanlisting has approved KIM members. |
| `{if_no_listings}...{/if_no_listings}` | Shows only when no fanlistings have approved KIM members (empty state). |

### KIM List Subject Page Template Tokens

Used by `collective_kim_list_subject_template` when `kim-list.php` is in
subject mode (`$luster_kim_subject_id > 0` or `/kim/list/{id}` route) and the
template string is non-empty. Edit in Admin → Collective → Templates.

Note: `{pagination}` is only replaced in embed mode. On the direct `/kim/list/{id}`
route it is always replaced with an empty string.

| Token | What it means |
| --- | --- |
| `{each_kim_member}...{/each_kim_member}` | Repeats the inside once per approved KIM member. |
| `{member_name}` | KIM member name. Use inside `{each_kim_member}`. |
| `{member_url}` | KIM member URL, or blank. Use inside `{each_kim_member}`. |
| `{if_member_url}...{/if_member_url}` | Inside `{each_kim_member}`: shows only when the member has a website. |
| `{if_no_member_url}...{/if_no_member_url}` | Inside `{each_kim_member}`: shows only when the member has no website. |
| `{listing_title}` | Fanlisting display name. |
| `{listing_subject}` | What the fanlisting is about. |
| `{listing_url}` | Link to the fanlisting. |
| `{kim_overview_url}` | Link back to the KIM list overview page. |
| `{kim_signup_url}` | Link to the KIM sign-up form. |
| `{if_kim_overview_url}...{/if_kim_overview_url}` | Shows only when a KIM overview URL is available. |
| `{if_kim_signup_url}...{/if_kim_signup_url}` | Shows only when a KIM sign-up URL is available. |
| `{if_has_kim_members}...{/if_has_kim_members}` | Shows only when at least one approved KIM member exists. |
| `{if_no_kim_members}...{/if_no_kim_members}` | Shows only when there are no approved KIM members (empty state). |
| `{pagination}` | Pagination links (embed mode only). Empty string on the direct route. |

### KIM Update Form Template Tokens

Used by `kim_update_form_template` when `kim-update.php` is embedded or the
`/kim/update` route renders and the template string is non-empty. Field tokens
output the complete label + input block; `_input` variants output just the raw
input element for custom layouts. Edit in Admin → Collective → Templates.

| Token | What it means |
| --- | --- |
| `{field_email}` / `{field_email_input}` | Email field (full block / raw input). |
| `{field_password}` / `{field_password_input}` | Password field (full block / raw input). |
| `{lost_password_url}` | URL to the KIM password reset page. |
| `{if_lost_password_url}...{/if_lost_password_url}` | Shows only when a reset URL is available. |
| `{submit_button}` | The submit button. |
| `{clear_button}` | A reset button that clears the form. |

### KIM Reset Form Template Tokens

Used by `kim_reset_form_template` when `kim-reset.php` is embedded or the
`/kim/reset` / `/kim/reset/confirm` routes render and the template string is
non-empty. This is a single template for both steps — use `{if_step_email}` and
`{if_step_confirm}` to separate them. Edit in Admin → Collective → Templates.

| Token | What it means |
| --- | --- |
| `{if_step_email}...{/if_step_email}` | Block shown on the email-entry step only. |
| `{if_step_confirm}...{/if_step_confirm}` | Block shown on the new-password step only. |
| `{field_email}` / `{field_email_input}` | Email field (email step). |
| `{field_password}` / `{field_password_input}` | New password field (confirm step). |
| `{field_password_confirm}` / `{field_password_confirm_input}` | Confirm new password field (confirm step). |
| `{reset_request_url}` | URL to the email-entry step for a "request a new link" fallback. |
| `{if_token_valid}...{/if_token_valid}` | Shows only when the reset token is valid (confirm step). |
| `{if_token_invalid}...{/if_token_invalid}` | Shows only when the token has expired or is invalid (confirm step). |
| `{submit_button}` | The submit button (label changes per step). |
| `{clear_button}` | A reset button that clears the form. |

### Legacy KIM Member Row Template Tokens

Used by `kim_member_template` as a fallback when `kim-list.php` is in subject
mode and `collective_kim_list_subject_template` is blank. This template covers
only the per-member row; there is no wrapper or conditional support outside the
loop. Prefer `collective_kim_list_subject_template` for new setups.

Related classes: `.fl-kim-member-list`, `.fl-kim-member-item`,
`.fl-kim-member-name`.

| Token | What it means |
| --- | --- |
| `{each_kim_member}...{/each_kim_member}` | Repeats once per approved KIM member for the selected fanlisting. |
| `{kim_name}` | KIM member name. |
| `{kim_url}` | KIM member URL, or blank. |
| `{listing_title}` | Related fanlisting title. |
| `{listing_subject}` | Related fanlisting subject. |
| `{if_kim_url}...{/if_kim_url}` | Shows only when KIM member URL exists. |
| `{if_no_kim_url}...{/if_no_kim_url}` | Shows only when KIM member URL is blank. |

## Join Rules Intro Template Tokens

Used by the `rules_intro_template` field on the per-listing Templates tab
(Edit Listing → Templates → Join Rules Intro). This template is an optional
HTML preamble shown above the rules title inside the join rules gate — useful
for adding a welcome note or listing-specific context.

Edit it in Admin → Listings → Edit → Templates. It is only shown when the Join
Rules add-on is installed and rules are enabled for the listing.

| Token | What it means |
| --- | --- |
| `{listing_title}` | Listing title. |
| `{listing_url}` | Listing URL. |
| `{listing_subject}` | Listing subject/topic. |

## Baseline Stylesheet

The installed baseline stylesheet file is
`public/assets/css/default/style.css`. In a browser, link to it from your
public Luster base URL:

```html
<link rel="stylesheet" href="/luster/assets/css/default/style.css">
```

If your host exposes the `public/` folder directly in the URL, include that
folder in the path. Most Luster pages and generated snippets use the public
base URL plus `/assets/css/default/style.css`.

Load it after the host page's normal CSS. Then, if the user wants extra
overrides outside Luster, load those after Luster's stylesheet.

Typical order:

```html
<link rel="stylesheet" href="/style.css">
<link rel="stylesheet" href="/luster/assets/css/default/style.css">
<link rel="stylesheet" href="/my-luster-overrides.css">
```

The baseline stylesheet is intentionally light. It gives embeds readable
structure without trying to erase the host site's design.

## CSS Custom Properties

These are not classes. They are theme knobs in `:root`. Change one variable to
affect many components.

| Custom property | What it customizes |
| --- | --- |
| `--fl-font-family` | Default font family used by `.fl-embed`. |
| `--fl-font-size` | Default font size used by `.fl-embed`. |
| `--fl-color` | Default text color used by `.fl-embed`. |
| `--fl-link-color` | Link color for embed links. |
| `--fl-link-hover` | Hover color for embed links. |
| `--fl-border-color` | Borders between rows/items. |
| `--fl-radius` | Border radius for success boxes, buttons, badges, and similar elements. |
| `--fl-gap` | General spacing between inline/flex items. |
| `--fl-row-gap` | Spacing between vertical row/list items. |
| `--fl-muted` | Muted secondary text color. |
| `--fl-input-border` | Form input border color. |
| `--fl-input-bg` | Form input background. |
| `--fl-input-color` | Form input text color. |
| `--fl-input-padding` | Form input padding. |
| `--fl-input-radius` | Form input border radius. |
| `--fl-status-current-bg` | Background color reserved for current status styling. |
| `--fl-status-current-fg` | Text color for current status styling. |
| `--fl-status-upcoming-bg` | Background color reserved for upcoming status styling. |
| `--fl-status-upcoming-fg` | Text color for upcoming status styling. |
| `--fl-status-pending-bg` | Background color reserved for pending status styling. |
| `--fl-status-pending-fg` | Text color for pending status styling. |
| `--fl-error-color` | Error text color. |
| `--fl-success-bg` | Success-message background. |
| `--fl-success-border` | Success-message border. |

## Root And Scope Classes

Use `.fl-embed--...` when you want to style one embed type without affecting
the others.

| Class | What it customizes |
| --- | --- |
| `.fl-embed` | Shared root wrapper for most embeds. Useful for broad embed styling and specificity. |
| `.fl-embed--adopt` | Adoption application embed root. |
| `.fl-embed--affiliate-apply` | Affiliate application embed root. |
| `.fl-embed--affiliates` | Affiliates list embed root. |
| `.fl-embed--codes` | Codes gallery embed root. |
| `.fl-embed--codes-submit` | Code submission form embed root. |
| `.fl-embed--collective-updates` | Collective updates feed root. |
| `.fl-embed--contact` | Listing or collective contact form root. |
| `.fl-embed--join` | Join form root. |
| `.fl-embed--joined` | Joined listings root. |
| `.fl-embed--kim-list` | KIM overview or subject list root. |
| `.fl-embed--kim-reset` | KIM password reset root. |
| `.fl-embed--kim-signup` | KIM signup root. |
| `.fl-embed--kim-update` | KIM update root. |
| `.fl-embed--members` | Members list root. |
| `.fl-embed--owned` | Owned listings root. |
| `.fl-embed--remove` | Remove-member form root. |
| `.fl-embed--reset` | Password reset root. |
| `.fl-embed--rules-gate` | Join Rules gate root. |
| `.fl-embed--update` | Update-member form root. |
| `.fl-embed--updates` | Per-listing updates feed root. |
| `.fl-embed--wishlist` | Wishlist embed root. |

Example:

```css
.fl-embed--joined .fl-joined-list {
  gap: 0.5rem;
}
```

## Global Utility Classes

| Class | What it customizes |
| --- | --- |
| `.fl-empty` | Empty-state text, such as "No members to display yet." |
| `.fl-hint` | Help text and short explanatory lines. |
| `.fl-powered-by` | Luster attribution line. |
| `.fl-pagination-wrap` | Wrapper around generated pagination. |
| `.fl-errors` | Error box/list area. |
| `.fl-error` | Single error line, usually inside form field wrappers. |

## Category Navigation Classes

Used mainly by owned and joined listings.

| Class | What it customizes |
| --- | --- |
| `.fl-category-nav` | Category navigation wrapper. |
| `.fl-category-list` | Category navigation as a list. |
| `.fl-category-list-children` | Nested child category list. |
| `.fl-category-count` | Number next to a category name. |
| `.fl-category-dropdown` | Category navigation as a dropdown form. |
| `.fl-category-back` | Back-to-categories link in browse-by-category mode. |
| `.fl-cat-heading` | Heading for the current category/section. |
| `.fl-joined-nav` | Shared dropdown form class currently used by both joined and owned category dropdowns. |

Rendered IDs, not classes: `fl-owned-cat` and `fl-joined-cat` are element IDs
used by labels/selects. Style them with `#fl-owned-cat` or `#fl-joined-cat` if
you need to target the select directly.

## Pagination Classes

| Class | What it customizes |
| --- | --- |
| `.fl-pagination-nav` | Main pagination wrapper. Canonical class. |
| `.fl-page-prev` | Previous-page link. |
| `.fl-page-next` | Next-page link. |
| `.fl-page-link` | Normal clickable page number. |
| `.fl-page-current` | Current page indicator. |
| `.fl-page-gap` | Gap marker between distant page numbers. |

## Form Classes

Used by join, update, remove, reset, contact, code submit, adoption, affiliate
apply, and KIM forms.

| Class | What it customizes |
| --- | --- |
| `.fl-form` | Main form element. |
| `.fl-form-fields` | Optional wrapper around form fields. Styled by baseline for vertical spacing. |
| `.fl-field` | One field row/wrapper. |
| `.fl-label` | Form label. |
| `.fl-input` | Text-like input. |
| `.fl-select` | Select dropdown. |
| `.fl-textarea` | Textarea. |
| `.fl-field-hint` | Hint/help text for one field. |
| `.fl-field-error` | Error text for one field. |
| `.fl-form-actions` | Button/action row. Canonical class. |
| `.fl-submit` | Submit button. |
| `.fl-btn` | General button class. |
| `.fl-btn-secondary` | Secondary button/action style. |
| `.fl-captcha-row` | Captcha row/wrapper. |
| `.fl-captcha-question` | Captcha prompt text. |
| `.fl-fieldset` | Fieldset emitted by the KIM update template. Currently rendered, but not styled by the active baseline stylesheet. |

## Success Classes

| Class | What it customizes |
| --- | --- |
| `.fl-success` | Success/confirmation message box. |
| `.fl-success-body` | Optional success-message body wrapper. |
| `.fl-success-actions` | Optional action area in a success message. |

## Member Classes

| Class | What it customizes |
| --- | --- |
| `.fl-member-controls` | Wrapper for member sort/filter controls. |
| `.fl-member-sort` | Sort-link line. |
| `.fl-member-sort-link` | Individual sort link/current sort label. |
| `.fl-member-filter` | Optional member filter area. |
| `.fl-member-back` | Back-to-country-summary link. |
| `.fl-member-list` | Member list container. |
| `.fl-member-item` | One member or country-summary row. |
| `.fl-member-name` | Member name or country link. |
| `.fl-member-country` | Member country or country count. |
| `.fl-member-email` | Member email text if a custom template prints it. |
| `.fl-member-field` | One custom additional field value. |
| `.fl-member-field-label` | Label for a custom member field. |
| `.fl-member-field-value` | Value for a custom member field. Currently rendered, but not styled by the active baseline stylesheet. |

## Stats Classes

| Class | What it customizes |
| --- | --- |
| `.fl-stats` | Listing stats block. |
| `.fl-stats-grid` | Stats grid wrapper. |
| `.fl-stats-label` | Label part of a stats row. |
| `.fl-stats-value` | Value part of a stats row. |
| `.fl-stat-newmembers` | Newest-members stats row/value when used in a custom stats template. |
| `.fl-stat-approved-by` | Approval-network stats row. |

## Affiliate Classes

| Class | What it customizes |
| --- | --- |
| `.fl-affiliate-list` | Affiliate list container. |
| `.fl-affiliate-item` | One affiliate item. |
| `.fl-affiliate-image` | Affiliate image. |
| `.fl-affiliate-name` | Affiliate text/name. |
| `.fl-affiliates-section` | Custom affiliate section wrapper. |
| `.fl-affiliates-heading` | Custom affiliate section heading. |
| `.fl-affiliates-empty` | Custom affiliate empty-state text. |
| `.fl-affiliate-apply-collapsible` | `<details>` wrapper for the collapsible affiliate application form. |
| `.fl-affiliate-apply-toggle` | `<summary>` element — the clickable toggle text/button. |
| `.fl-affiliate-apply-toggle--link` | Modifier: toggle styled as an underlined text link. |
| `.fl-affiliate-apply-toggle--button` | Modifier: toggle styled as a button. |
| `.fl-affiliate-apply-form-wrap` | Inner wrapper around the application form inside the collapsible. |

## Owned Listing Classes

| Class | What it customizes |
| --- | --- |
| `.fl-listing-list` | Owned listing list container. |
| `.fl-listing-item` | One owned listing row/card. |
| `.fl-listing-item--current` | Modifier for current listing rows. |
| `.fl-listing-item--upcoming` | Modifier for upcoming listing rows. |
| `.fl-listing-item--pending` | Modifier for pending listing rows. |
| `.fl-listing-image-wrap` | Wrapper around an owned listing image. |
| `.fl-listing-image` | Owned listing image itself. |
| `.fl-listing-body` | Text/body area for one listing. |
| `.fl-listing-title` | Main linked title in owned listing templates. |
| `.fl-listing-subject` | Subject/topic text. |
| `.fl-listing-meta` | Metadata row/wrapper. |
| `.fl-listing-category` | Category metadata. |
| `.fl-listing-status` | Status metadata. |
| `.fl-listing-date-opened` | Opened-date metadata. |
| `.fl-listing-date-updated` | Last-updated metadata. |
| `.fl-listing-member-count` | Member-count metadata. |
| `.fl-listing-country-count` | Country-count metadata. |
| `.fl-listing-section` | Wrapper for a sectioned owned-listings group. |
| `.fl-listing-section-heading` | Heading for a sectioned owned-listings group. |
| `.fl-listing-section-count` | Count inside a section heading. |

## Joined Listing Classes

| Class | What it customizes |
| --- | --- |
| `.fl-joined-list` | Joined listing list container (`flex-wrap` — items flow inline). |
| `.fl-joined-item` | One joined listing entry (used in fallback `<li>` rendering). |
| `.fl-joined-image-wrap` | Wrapper around a joined listing image. |
| `.fl-joined-image` | Joined listing image itself. |
| `.fl-joined-body` | Text/body area for one joined listing. |
| `.fl-joined-title` | Main linked joined listing title. |
| `.fl-joined-category` | Joined listing category text. |
| `.fl-joined-date` | Date joined text. |
| `.fl-joined-status` | Joined listing status text. |
| `.fl-joined-section` | Wrapper for a custom joined-listing section. |
| `.fl-joined-section-heading` | Heading for a custom joined-listing section. |

## Collective Stats Classes

| Class | What it customizes |
| --- | --- |
| `.fl-cstats` | Canonical collective stats block. |
| `.fl-cstats-grid` | Canonical collective stats grid. |
| `.fl-cstats-label` | Collective stats label. |
| `.fl-cstats-value` | Collective stats value. |
| `.fl-cstats-newest` | Newest-owned section in collective stats templates. |
| `.fl-cstats-random` | Random/featured-owned section in collective stats templates. |
| `.fl-cstats-categories` | Categories section in collective stats templates. |
| `.fl-muted` | Muted inline text in the collective stats fallback. Currently rendered, but not styled by the active baseline stylesheet. |

## Codes Classes

| Class | What it customizes |
| --- | --- |
| `.fl-codes` | Whole codes gallery wrapper. |
| `.fl-code-group` | One size group. |
| `.fl-code-group-label` | Size group heading. |
| `.fl-code-list` | List/grid of code items. |
| `.fl-code-item` | One code item. |
| `.fl-code-image` | Figure/wrapper around one code image. |
| `.fl-code-caption` | Caption under or beside a code. |

## Updates Classes

| Class | What it customizes |
| --- | --- |
| `.fl-updates` | Updates feed wrapper. |
| `.fl-update-item` | One update entry. |
| `.fl-update-header` | Header row for one update. |
| `.fl-update-title` | Update title. |
| `.fl-update-date` | Update date. |
| `.fl-update-body` | Update body/content. |
| `.fl-update-meta` | Update metadata row. |
| `.fl-update-categories` | Category-tag wrapper. |
| `.fl-update-category-tag` | One update category tag. |

## KIM Classes

| Class | What it customizes |
| --- | --- |
| `.fl-kim` | Shared inner wrapper for KIM embeds. |
| `.fl-kim-heading` | KIM heading. |
| `.fl-kim-intro` | KIM intro/explanatory text. |
| `.fl-kim-list` | KIM fanlisting/subject list. |
| `.fl-kim-item` | One KIM fanlisting/subject item. |
| `.fl-kim-count` | Interested-member count. |
| `.fl-kim-count--empty` | Empty count state, such as "none yet". |
| `.fl-kim-meta` | KIM metadata/navigation text. |
| `.fl-kim-subject` | Subject view wrapper if used in a custom template. |
| `.fl-kim-subject-heading` | Subject view heading. |
| `.fl-kim-member-list` | Interested-member list for one subject. |
| `.fl-kim-member-item` | One interested-member row. |
| `.fl-kim-member-name` | Interested-member name/link. |

## Join Rules Gate Classes

| Class | What it customizes |
| --- | --- |
| `.fl-embed--rules-gate` | Outer root wrapper for the rules gate embed. |
| `.fl-rules-gate` | Inner wrapper shown when the fan has not yet agreed. |
| `.fl-rules-intro` | Optional intro HTML block above the rules title. |
| `.fl-rules-title` | Rules title heading. |
| `.fl-rules-body` | Rules body text area. |
| `.fl-rules-form` | The agreement `<form>` element. |
| `.fl-rules-agree-field` | Field row wrapping the agreement checkbox. |
| `.fl-rules-agree-label` | Label wrapping the checkbox and its text. |
| `.fl-rules-agree-check` | The agreement checkbox input itself. |
| `.fl-rules-agreed` | Wrapper shown when the fan has already agreed this session. |

## Wishlist Classes

| Class | What it customizes |
| --- | --- |
| `.fl-wishlist-group` | Wrapper for a wishlist type group. |
| `.fl-wishlist-group--top` | Group modifier for top-wanted entries. |
| `.fl-wishlist-group--wanted` | Group modifier for wanted entries. |
| `.fl-wishlist-group--granted` | Group modifier for granted entries. |
| `.fl-wishlist-group-label` | Heading/label for a wishlist group. |
| `.fl-wishlist-list` | Wishlist list container. |
| `.fl-wishlist-item` | One wishlist item. |
| `.fl-wishlist-item--top` | Item modifier for top-wanted entries. |
| `.fl-wishlist-item--wanted` | Item modifier for wanted entries. |
| `.fl-wishlist-item--granted` | Item modifier for granted entries. |
| `.fl-wishlist-image` | Wishlist image. |
| `.fl-wishlist-info` | Text/body area for one wishlist item. |
| `.fl-wishlist-subject` | Wishlist subject link/text. |
| `.fl-wishlist-category` | Wishlist category text. |
| `.fl-wishlist-description` | Wishlist description text. |
| `.fl-wishlist-type-badge` | Type badge/label. |

## Common Styling Recipes

Style one embed type:

```css
.fl-embed--members .fl-member-list {
  margin-top: 1rem;
}
```

Style every embed form:

```css
.fl-embed .fl-form {
  display: grid;
  gap: 0.75rem;
}
```

Change the whole Luster embed color system:

```css
:root {
  --fl-link-color: #8a3ffc;
  --fl-link-hover: #5f249f;
  --fl-border-color: #d8c7ff;
}
```

Style only joined listing images:

```css
.fl-embed--joined .fl-joined-image {
  width: 88px;
  height: 31px;
  object-fit: cover;
}
```

Style only owned listing images:

```css
.fl-embed--owned .fl-listing-image {
  max-width: 120px;
  height: auto;
}
```

## Development Notes

- The active installable/public embed stylesheet is
  `public/assets/css/default/style.css`.
- `templates/default/style.css` is not the current embed baseline stylesheet.
- `stats.php`, `latest-listing.php`, `random-listing.php`, and
  `collective-stats.php` are the main embeds that currently do not add a
  dedicated `.fl-embed--...` root class around their saved-template output.
- If a template emits a class listed here but the baseline stylesheet does not
  style it yet, users can still style it in their own CSS. Future development
  can either add a baseline rule or remove the class if it is not useful.
- As of the June 14, 2026 audit, all legacy/deprecated `.fl-*` class aliases
  (`.fl-pagination`, `.fl-actions`, `.fl-listing-cards`, `.fl-listing-card`,
  `.fl-listing-card-image`, `.fl-listing-card-body`, `.fl-collective-stats`,
  `.fl-stats-grid`, `.fl-newest-owned`, `.fl-random-owned`, `.fl-categories`,
  `.fl-codes-group`, the bare `.fl-code`, `.fl-code-size-heading`, the bare
  `.fl-wishlist`, `.fl-wishlist-img`, `.fl-wishlist-cat`, `.fl-wishlist-desc`,
  and the old generic KIM names `.fl-wrap`/`.fl-list`/`.fl-list-item`/
  `.fl-title`/`.fl-intro`/`.fl-count`/`.fl-count--empty`/`.fl-meta`) were
  removed from this guide. None of them are defined in
  `public/assets/css/default/style.css` or emitted by any current template —
  only the canonical names documented above are supported.
