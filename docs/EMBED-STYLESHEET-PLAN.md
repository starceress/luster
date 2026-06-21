# Luster Embed Stylesheet — Implementation Plan

> Review and approve this document before any code changes are made.
> It covers every class that will be created, every template that changes, and all files touched.

---

## 1. Goal

Luster's embed outputs should look clean and consistent on any host page with zero additional CSS
required from the site owner. The mechanism:

1. A single canonical stylesheet (`public/assets/css/default/style.css`) ships with Luster and
   contains **real, neutral visual defaults** for every `fl-*` class.
2. Every default admin-panel template (member rows, affiliate rows, owned/joined listing rows,
   stats, collective stats, KIM list, wishlist, etc.) uses only `fl-*` classes — no inline styles,
   no generic element selectors.
3. Embed snippet code (from Admin → Embed Codes) includes a `<link>` tag pointing at this file.
4. Users who want to customise a component copy the relevant rule(s) from `style.css` into their
   own site stylesheet and override. The CSS cascade handles the rest — their rule wins because it
   loads later.

---

## 2. Design Principles

**Neutral defaults.** Styles should read clearly on any background — light or dark, serif or sans.
No strong colour opinions, no brand-specific fonts. Use `inherit` wherever reading the host
context is preferable to overriding it.

**CSS custom properties for easy theming.** A small set of `--fl-*` variables at the `:root`
level lets a site owner retheme all embed components by overriding three or four variables rather
than hunting individual rules.

**Tight scoping.** Every selector is prefixed with `fl-`. No bare element selectors (`ul`, `li`,
`a`, `p`, `form`) are used at stylesheet level. This means Luster's stylesheet can never bleed
into host-page content outside an embed, no matter how it is loaded.

**Per-embed root wrapper.** Every embed output is wrapped in `<div class="fl-embed fl-embed--{type}">`.
This serves two purposes: (1) it provides a scoping anchor so users can write overrides like
`.fl-embed .fl-member-list { ... }` that target only Luster output; (2) it gives a specificity
boost — Luster's stylesheet can use `.fl-embed .fl-member-list` (specificity 0,2,0) which
competes with common host patterns like `.content ul` instead of losing to them.
The modifier class (e.g. `.fl-embed--joined`) lets users apply different styles per embed type.

> **Realistic expectation:** "Exactly the same on every site" is only achievable at the structural
> and spacing level. A host stylesheet with very specific element or contextual rules (`body .content a`,
> `main li::before`) can still win on narrow properties. The wrapper raises the floor significantly
> but does not guarantee pixel parity. The goal is: embeds are readable and correctly laid out by
> default, not: embeds are visually identical to Luster's admin panel.

**Container / item pattern.** Every list-type component has a named container class
(`.fl-member-list`) and a named child class (`.fl-member-item`). Components that have internal
sub-sections add a third level (`.fl-code-group > .fl-code-list > .fl-code-item`). This gives
users a hook at every level.

**No `<nav>` elements in embeds.** Already fixed; documented here for completeness. Category
pickers and pagination use `<div>` wrappers with `fl-*` classes. The `<nav>` element is reserved
for the host page's own navigation.

**No heavy reset inside `.fl-embed`.** Do not globally reset `a`, `p`, `ul`, `li`, or other
elements inside `.fl-embed`. Use targeted structural rules only (e.g. `.fl-member-list` removes
its own list bullets; `.fl-code-list` sets its own flex layout). A broad reset inside the embed
wrapper would stomp the host's intentional typography and link styles, which the embed should
inherit by default via `inherit` values on the custom properties.

---

## 3. File Strategy

| File | Action |
|---|---|
| `public/assets/css/default/style.css` | Expanded with all `fl-*` defaults + CSS custom properties. Luster owns this file — users do not edit it directly. |
| `src/Repositories/SiteSettingsRepo.php` | Update `collectiveDefaults()` — replace inline-style HTML with `fl-*` class HTML in `owned_template`, `joined_template`, `collective_stats_template`. |
| `src/Repositories/ListingSettingsRepo.php` | Update PHP default strings for `members_template`, `stats_template`, and the affiliate row template. **Do not write these to the DB on first render** — see Default Template Strategy below. |
| `templates/default/*.php` + addon templates | Add root wrapper classes; add container/wrapper `fl-*` classes. Fix KIM templates that use generic `.fl-list` / `.fl-list-item`. |
| `public/embed/collective-updates.php` | **Bug fix required (independent of stylesheet work):** this embed does not apply `collective_updates_template` from `lstr_site_settings`, while `UpdatesPublicController` does. The embed must fetch and apply the stored template the same way the controller does. |
| `templates/admin/embed-codes/index.php` | Already has the callout. Verify the JS snippet generator includes the `<link>` tag (in `admin.js`). |
| `public/assets/js/admin.js` | Confirm/add `<link rel="stylesheet">` line in generated embed snippets. |

The file at `public/assets/css/default/style.css` is **not** user-editable in the git-update
workflow. Users copy rules from it to their own stylesheet. The file's comment block will be
updated to say exactly this.

### Default Template Strategy (null/fallback handling)

Several templates (`members_template`, `stats_template`, affiliate row template) default to `NULL`
in the database. Currently each PHP template file handles the NULL case with its own fallback loop.

**Decision: do not write defaults to the DB on first render.** Instead:

1. Define a PHP constant or static method in the relevant Repo class holding the default HTML string.
2. Use that same string as the pre-filled value in the admin panel textarea (so admins see the
   default when they open the template editor, can clear it back to default, etc.).
3. Use the same string as the public-rendering fallback when the DB value is NULL.

This means the DB value stays NULL until the user actually saves a customisation. No silent writes,
no migration needed, admin always sees the canonical default.

### Stylesheet Load Order

For embeds to work correctly out of the box, the page that includes them must load stylesheets
in this order:

```
1. host.css                   (host site's main stylesheet)
2. style.css                  (Luster's baseline embed stylesheet)
3. host-luster-overrides.css  (host site's Luster customisations, if any)
```

The embed snippet generated by Admin → Embed Codes should include a note making this order
explicit. The ideal placement is a single `<link>` tag in `<head>`, after the host's own CSS
links. Browsers accept `<link>` inside page body content and it still works, but in-head
placement is cleaner and avoids flash-of-unstyled-embed. The snippet note should say:
"Add the Luster CSS link once in your `<head>`, after your own stylesheet links. Then place
the PHP require snippet wherever you want the embed to appear in your page content."

---

## 4. CSS Custom Properties

These are declared at `:root` so any host page can override them. All `fl-*` rules use these
variables with a fallback value so the embed works even if the variables are absent.

```css
:root {
  /* Typography — inherit from host by default */
  --fl-font-family:    inherit;
  --fl-font-size:      inherit;
  --fl-color:          inherit;

  /* Links */
  --fl-link-color:     #0066cc;
  --fl-link-hover:     #004499;

  /* Borders and spacing */
  --fl-border-color:   #d8d8d8;
  --fl-radius:         3px;
  --fl-gap:            0.5em;
  --fl-row-gap:        0.6em;

  /* Muted / secondary text */
  --fl-muted:          #777;

  /* Form inputs */
  --fl-input-border:   #bbb;
  --fl-input-bg:       #fff;
  --fl-input-color:    #222;
  --fl-input-padding:  0.35em 0.6em;
  --fl-input-radius:   3px;

  /* Status badge colours */
  --fl-status-current-bg:   #e6f4ea;
  --fl-status-current-fg:   #2d6a3f;
  --fl-status-upcoming-bg:  #fff8e1;
  --fl-status-upcoming-fg:  #7a5c00;
  --fl-status-pending-bg:   #f3f3f3;
  --fl-status-pending-fg:   #555;

  /* Error / success feedback */
  --fl-error-color:    #c00;
  --fl-success-bg:     #f0fff4;
  --fl-success-border: #6ccf8a;
}
```

A user who only wants to match their site's link colour adds one line to their own stylesheet:
```css
:root { --fl-link-color: #c05; }
```

---

## 5. Class Inventory

### 5.0 Per-embed root wrapper

Every PHP fragment template has an existing outer `<div>` with no class (or in KIM's case
`.fl-wrap`). That element gains `fl-embed` and a type modifier — no extra nesting level added.

| Modifier class | Embed file(s) |
|---|---|
| `.fl-embed--join` | `join.php` |
| `.fl-embed--members` | `members.php` |
| `.fl-embed--update` | `update.php` |
| `.fl-embed--remove` | `remove.php` |
| `.fl-embed--reset` | `reset.php`, `reset_confirm.php` |
| `.fl-embed--stats` | `stats.php` (public page) |
| `.fl-embed--affiliates` | `affiliates.php` |
| `.fl-embed--affiliate-apply` | `affiliate-apply.php` |
| `.fl-embed--codes` | `codes.php` |
| `.fl-embed--codes-submit` | `codes-submit.php` |
| `.fl-embed--contact` | `contact.php` |
| `.fl-embed--owned` | `owned.php` |
| `.fl-embed--joined` | `joined.php` |
| `.fl-embed--collective-stats` | `collective-stats.php` (embed output) |
| `.fl-embed--collective-contact` | `collective-contact.php` |
| `.fl-embed--updates` | updates feed fragment |
| `.fl-embed--collective-updates` | collective updates fragment |
| `.fl-embed--adopt` | `adopt.php` |
| `.fl-embed--random-listing` | `random-listing.php` |
| `.fl-embed--latest-listing` | `latest-listing.php` |
| `.fl-embed--kim-signup` | `addons/kim/signup.php` |
| `.fl-embed--kim-list` | `addons/kim/list.php`, `subject.php` |
| `.fl-embed--kim-update` | `addons/kim/update.php` |
| `.fl-embed--kim-reset` | `addons/kim/reset.php`, `reset-confirm.php` |
| `.fl-embed--wishlist` | `wishlist.php` |

**How scoped overrides work in practice.** Luster's stylesheet defines:
```css
.fl-embed .fl-member-list { ... }   /* specificity 0,2,0 */
```
A user who wants to override just the joined-embed member list writes in their own CSS (loaded
after Luster's):
```css
.fl-embed--joined .fl-member-list { ... }   /* same specificity, later = wins */
```
A user who wants to override all embeds at once:
```css
.fl-embed .fl-member-list { ... }   /* same specificity, later = wins */
```
No `!important` needed.

---

### 5.1 Global utilities (new or formalised)

| Class | Description |
|---|---|
| `.fl-empty` | Empty-state paragraph ("No members yet", etc.). Currently used in wishlist and KIM templates but not defined in style.css. **Formalise.** |
| `.fl-powered-by` | Attribution line. Already exists. |
| `.fl-hint` | Small secondary-text line (member count summary, etc.). Currently used in members.php without a style.css definition. **Add.** |
| `.fl-pagination-wrap` | Wrapper div around `{pagination}` output. Currently used in templates without a definition. **Add.** |

---

### 5.2 Category navigator (existing — document only)

Already correct. No new classes needed.

| Class | Status |
|---|---|
| `.fl-category-nav` | Exists |
| `.fl-category-list` | Exists |
| `.fl-category-list-children` | Exists |
| `.fl-category-count` | Exists |
| `.fl-category-dropdown` | Exists |
| `.fl-category-back` | Exists |
| `.fl-cat-heading` | Exists |

---

### 5.3 Pagination (existing — document only)

Already correct.

| Class | Status |
|---|---|
| `.fl-pagination` | Exists (compat alias) |
| `.fl-pagination-nav` | Exists (primary) |
| `.fl-page-current` | Exists |
| `.fl-page-link` | Exists |
| `.fl-page-gap` | Exists |
| `.fl-page-prev` | Exists |
| `.fl-page-next` | Exists |

---

### 5.4 Form system

Applies to: **join, update, remove, reset, reset_confirm, contact, collective-contact,
codes-submit, affiliate-apply, adopt, kim-signup, kim-update, kim-reset**

The per-field HTML (label, input, hint, error) is already built by `FormFieldBuilder` /
`FieldWrapper` using the existing `fl-field`, `fl-label`, `fl-input`, `fl-select`,
`fl-textarea`, `fl-errors` classes. The changes here are at the **form wrapper** level.

| Class | Status | Description |
|---|---|---|
| `.fl-form` | Exists in templates, **missing from style.css** | Wrapper around the entire `<form>` element |
| `.fl-form-fields` | **New** | Groups the field rows; provides vertical spacing |
| `.fl-field` | Exists | Single field row (label + control) |
| `.fl-label` | Exists | `<label>` element |
| `.fl-input` | Exists | `<input type="text/email/url/password">` |
| `.fl-select` | Exists | `<select>` element |
| `.fl-textarea` | Exists | `<textarea>` element |
| `.fl-field-hint` | **New** (replaces ad-hoc approach) | Hint text below a field |
| `.fl-field-error` | **New** | Per-field error message |
| `.fl-errors` | Exists | Summary error block at top of form |
| `.fl-form-actions` | **New** (replaces `.fl-actions`) | Row containing submit/reset buttons |
| `.fl-submit` | **New** | Primary submit button |
| `.fl-btn` | Exists in adopt.php, **missing from style.css** | Secondary/ghost button |
| `.fl-btn-secondary` | Exists in adopt.php, **missing from style.css** | Secondary variant |
| `.fl-captcha-row` | **New** | Wrapper for the captcha field row |
| `.fl-captcha-question` | **New** | The arithmetic question text |

> **Note on `.fl-actions`:** Used in `join.php`. Will be aliased to `.fl-form-actions` during
> the PHP template pass so both work until old usages are cleaned up.

---

### 5.5 Member list

**Where used:** `embed/members.php` → `templates/default/members.php`
**Template type:** PHP fragment (not stored in DB). The *row* rendering uses the DB-stored
`members_template` token template.

#### Container / sort controls (PHP template)
| Class | Status | Description |
|---|---|---|
| `.fl-member-controls` | **New** | Wrapper for sort, filter, and search bar |
| `.fl-member-sort` | **New** | Sort links row ("Sort by: Name / Country / View All") |
| `.fl-member-sort-link` | **New** | Individual sort link |
| `.fl-member-sort-link[aria-current]` | **New** (state) | Active sort indicator |
| `.fl-member-filter` | **New** | Country filter form |
| `.fl-member-back` | **New** | "Back to countries" link |

#### Row token template defaults (DB-stored, `members_template`)
| Class | Status | Description |
|---|---|---|
| `.fl-member-list` | **New** | Container `<div>` wrapping all member rows |
| `.fl-member-item` | **New** | Each member row |
| `.fl-member-name` | **New** | Member name (or `<a>` when URL exists) |
| `.fl-member-country` | **New** | Country value |
| `.fl-member-email` | **New** | Email address (when shown) |
| `.fl-member-field` | **New** | A custom additional-field label+value pair |
| `.fl-member-field-label` | **New** | The field label ("Favourite:") |
| `.fl-member-field-value` | **New** | The field value |

**Default `members_template` (replaces current NULL/fallback):**
```html
<div class="fl-member-list">
{each_member}
<div class="fl-member-item">
  {if_fan_url}<a class="fl-member-name" href="{fan_url}" rel="noopener noreferrer">{fan_name}</a>{/if_fan_url}{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url}
  {if_fan_country}<span class="fl-member-country">{fan_country}</span>{/if_fan_country}
</div>
{/each_member}
</div>
```
> Custom additional fields ({fan_FIELDNAME}) are added by the user in their own template.

---

### 5.6 Stats

**Where used:** `embed/stats.php` → DB-stored `stats_template`
**Template type:** DB-stored token template

| Class | Status | Description |
|---|---|---|
| `.fl-stats` | **New** | Outer wrapper |
| `.fl-stats-table` | **New** | Key/value grid (replaces `.fl-stats-grid` which existed only in collective-stats context) |
| `.fl-stat-row` | **New** | A single label+value row |
| `.fl-stat-label` | **New** | Label cell ("Members:") |
| `.fl-stat-value` | **New** | Value cell ("42") |
| `.fl-stat-newmembers` | **New** | "Newest members:" line |
| `.fl-stat-approved-by` | **New** | TFL/TAFL badge line |

**Default `stats_template` (replaces current inline-style default):**
```html
<div class="fl-stats">
  <div class="fl-stats-table">
    <div class="fl-stat-row"><span class="fl-stat-label">Members:</span><span class="fl-stat-value">{stat_approved}</span></div>
    <div class="fl-stat-row"><span class="fl-stat-label">Opened:</span><span class="fl-stat-value">{stat_opened}</span></div>
    <div class="fl-stat-row"><span class="fl-stat-label">Last Updated:</span><span class="fl-stat-value">{stat_last_updated}</span></div>
    {if_approved_by}<div class="fl-stat-row fl-stat-approved-by"><span class="fl-stat-label">Listed at:</span><span class="fl-stat-value">{approved_by}</span></div>{/if_approved_by}
  </div>
</div>
```

---

### 5.7 Affiliates

**Where used:** `embed/affiliates.php` → DB-stored row template + section template
**Template type:** DB-stored token template (row), plus PHP fragment wrapper

#### Row template (DB-stored)
| Class | Status | Description |
|---|---|---|
| `.fl-affiliate-list` | **New** | Container — wraps all affiliate items |
| `.fl-affiliate-item` | **New** | Each affiliate |
| `.fl-affiliate-image` | **New** | The `<img>` tag (or absent when no image) |
| `.fl-affiliate-name` | **New** | Text name link (used when no image, or alongside image) |

**Default affiliate row template:**
```html
<div class="fl-affiliate-list">
{each_affiliate}
<div class="fl-affiliate-item">
  <a href="{aff_url}" rel="noopener noreferrer">
    {if_aff_image_tag}<img class="fl-affiliate-image" src="{aff_image_url}" alt="{aff_name}">{/if_aff_image_tag}{if_no_aff_image_tag}<span class="fl-affiliate-name">{aff_name}</span>{/if_no_aff_image_tag}
  </a>
</div>
{/each_affiliate}
</div>
```

> **Note:** `{if_aff_image_tag}` / `{if_no_aff_image_tag}` are existing conditional tokens.

#### Section template (DB-stored, currently blank default)
| Class | Status | Description |
|---|---|---|
| `.fl-affiliates-section` | **New** | Full affiliates block wrapper |
| `.fl-affiliates-heading` | **New** | Optional "Affiliates (N)" heading |
| `.fl-affiliates-empty` | **New** | Empty state text |

The section template default will remain blank (users opt in). The classes are defined in style.css
so they work when a user does write a section template.

---

### 5.8 Owned listings

**Where used:** `embed/owned.php`, `embed/latest-listing.php`, `embed/random-listing.php`
**Template type:** DB-stored token template (`owned_template`) + PHP fragment wrapper

#### Row template (DB-stored, replaces inline-style default)
| Class | Status | Description |
|---|---|---|
| `.fl-listing-list` | **New** | Container. Replaces deprecated `.fl-listing-cards` |
| `.fl-listing-item` | **New** | Each listing row. Replaces deprecated `.fl-listing-card` |
| `.fl-listing-item--current` | **New** | Modifier for current-status listings |
| `.fl-listing-item--upcoming` | **New** | Modifier for upcoming listings |
| `.fl-listing-item--pending` | **New** | Modifier for pending listings |
| `.fl-listing-image-wrap` | **New** | Wraps the listing image. Replaces deprecated `.fl-listing-card-image` |
| `.fl-listing-image` | **New** | The `<img>` element itself |
| `.fl-listing-body` | **New** | Text content area. Replaces deprecated `.fl-listing-card-body` |
| `.fl-listing-title` | **New** | Listing name, wrapped in `<a>` |
| `.fl-listing-subject` | **New** | Listing subject |
| `.fl-listing-meta` | **New** | Metadata row (dates, counts, category) |
| `.fl-listing-category` | **New** | Category name(s) |
| `.fl-listing-status` | **New** | Status label text |
| `.fl-listing-date-opened` | **New** | Opened date |
| `.fl-listing-date-updated` | **New** | Last updated date |
| `.fl-listing-member-count` | **New** | Member count |
| `.fl-listing-country-count` | **New** | Country count |

#### Section template tokens (for `{each_current}` / `{each_upcoming}` / `{each_pending}` templates)
| Class | Status | Description |
|---|---|---|
| `.fl-listing-section` | **New** | Each status section wrapper |
| `.fl-listing-section-heading` | **New** | Section heading ("Current", "Upcoming", etc.) |
| `.fl-listing-section-count` | **New** | Count badge beside heading |

**Default `owned_template` (replaces current inline-style default):**
```html
<div class="fl-listing-list">
{each_listing}
<div class="fl-listing-item">
  {if_listing_image}<div class="fl-listing-image-wrap"><img class="fl-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}
  <div class="fl-listing-body">
    <a class="fl-listing-title" href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_subject}</a>
    <span class="fl-listing-subject">{listing_title}</span>
    <div class="fl-listing-meta">
      <span class="fl-listing-category">{listing_category}</span>
      <span class="fl-listing-member-count">{member_count} member(s){if_pending} +{member_pending_count} pending{/if_pending}</span>
      {if_countries}<span class="fl-listing-country-count">{country_count} countries</span>{/if_countries}
      <span class="fl-listing-date-opened">Opened: {date_opened}</span>
    </div>
  </div>
</div>
{/each_listing}
</div>
```

---

### 5.9 Joined listings

**Where used:** `embed/joined.php`
**Template type:** DB-stored token template (`joined_template`) + PHP fragment wrapper

| Class | Status | Description |
|---|---|---|
| `.fl-joined-list` | **New** | Container |
| `.fl-joined-item` | **New** | Each joined listing |
| `.fl-joined-image-wrap` | **New** | Image wrapper |
| `.fl-joined-image` | **New** | The `<img>` element |
| `.fl-joined-body` | **New** | Text content area |
| `.fl-joined-title` | **New** | Listing name, wrapped in `<a>` |
| `.fl-joined-category` | **New** | Category |
| `.fl-joined-date` | **New** | Date joined |
| `.fl-joined-status` | **New** | Status label |
| `.fl-joined-section` | **New** | Category group wrapper (when `$luster_category='all'`) |
| `.fl-joined-section-heading` | **New** | Category heading inside a section |

**Default `joined_template` (replaces current inline-style default):**
```html
<div class="fl-joined-list">
{each_joined}
<div class="fl-joined-item">
  {if_listing_image}<div class="fl-joined-image-wrap"><img class="fl-joined-image" src="{listing_image_url}" alt="{listing_title}"></div>{/if_listing_image}
  <div class="fl-joined-body">
    <a class="fl-joined-title" href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_title}</a>
    <span class="fl-joined-category">{listing_category}</span>
    <span class="fl-joined-date">{date_joined}</span>
  </div>
</div>
{/each_joined}
</div>
```

---

### 5.10 Collective stats

**Where used:** `embed/collective-stats.php`
**Template type:** DB-stored token template (`collective_stats_template`) + PHP fragment

| Class | Status | Description |
|---|---|---|
| `.fl-cstats` | Exists (as `.fl-collective-stats`) — **rename to `.fl-cstats`** | Outer wrapper |
| `.fl-cstats-grid` | Exists | Key/value layout grid |
| `.fl-cstats-label` | **New** | A stat label cell |
| `.fl-cstats-value` | **New** | A stat value cell |
| `.fl-cstats-newest` | Exists (as `.fl-newest-owned`) — **rename** | Newest listing item |
| `.fl-cstats-random` | Exists (as `.fl-random-owned`) — **rename** | Random listing item |
| `.fl-cstats-categories` | Exists (as `.fl-categories`) — **rename** | Category list section |

> Old names (`.fl-collective-stats`, `.fl-newest-owned`, `.fl-random-owned`, `.fl-categories`)
> will be kept as selector aliases (comma-separated) for one release cycle, then removed.

**Default `collective_stats_template` (replaces current bare-text default):**
```html
<div class="fl-cstats">
  <div class="fl-cstats-grid">
    <span class="fl-cstats-label">Owned listings:</span><span class="fl-cstats-value">{owned_active} open, {owned_upcoming} upcoming</span>
    <span class="fl-cstats-label">Joined listings:</span><span class="fl-cstats-value">{joined_active}</span>
    <span class="fl-cstats-label">Total members:</span><span class="fl-cstats-value">{members_approved} approved</span>
  </div>
  <div class="fl-cstats-random">Featured: <a href="{owned_random_url}">{owned_random_title}</a></div>
</div>
```

---

### 5.11 Codes gallery

**Where used:** `embed/codes.php` → `templates/default/codes.php`
**Template type:** PHP fragment (base render), with optional DB-stored token template overlay

#### Current class names → new names
| Old | New | Notes |
|---|---|---|
| `.fl-codes-group` | `.fl-code-list` | The flex-wrap row of code images within one size |
| `.fl-code` | `.fl-code-item` | Each individual code `<figure>` |
| `.fl-code-size-heading` | `.fl-code-group-label` | The "50×50 (12 codes)" heading |
| _(none)_ | `.fl-codes` | New: outermost wrapper around the whole codes section |
| _(none)_ | `.fl-code-group` | New: wrapper for one size group (label + list of codes) |
| _(none)_ | `.fl-code-image` | New: the `<img>` inside each `fl-code-item` |
| _(none)_ | `.fl-code-caption` | New: the `<figcaption>` inside each `fl-code-item` |

Old names will be kept as comma-separated aliases for one release cycle.

| Class | Description |
|---|---|
| `.fl-codes` | Outermost container |
| `.fl-code-group` | One size section (e.g. all 50×50 codes) |
| `.fl-code-group-label` | Size label heading ("50×50 — 12 codes") |
| `.fl-code-list` | Flex-wrap row of code images |
| `.fl-code-item` | Single code `<figure>` |
| `.fl-code-image` | The `<img>` inside each item |
| `.fl-code-caption` | `<figcaption>` for donor/category/name info |
| `.fl-code-caption--donor` | Modifier: caption is a donor attribution |
| `.fl-code-caption--category` | Modifier: caption lists code categories |

---

### 5.12 Updates feed

**Where used:** `embed/updates.php`, `embed/collective-updates.php`
**Template type:** DB-stored token template
- Per-listing: `updates_template` column in `lstr_fanlisting_settings` (read by `ListingSettingsRepo`)
- Collective: `collective_updates_template` key in `lstr_site_settings` (read by `SiteSettingsRepo`)

> **Known gap:** `embed/collective-updates.php` currently does not fetch or apply
> `collective_updates_template`. The per-listing `embed/updates.php` does apply its template.
> Fixing the collective embed is a prerequisite for the collective updates template default
> to have any effect at runtime.

| Class | Status | Description |
|---|---|---|
| `.fl-updates` | **New** | Outer container |
| `.fl-update-item` | **New** | Each update post |
| `.fl-update-header` | **New** | Title + date row |
| `.fl-update-title` | **New** | Post title |
| `.fl-update-date` | **New** | Post date |
| `.fl-update-body` | **New** | Post body text |
| `.fl-update-meta` | **New** | Author + categories row |
| `.fl-update-author` | **New** | Author name |
| `.fl-update-categories` | **New** | Category tags wrapper |
| `.fl-update-category-tag` | **New** | Individual category tag |

**Default updates template:**
```html
<div class="fl-updates">
{each_update}
<div class="fl-update-item">
  <div class="fl-update-header">
    <span class="fl-update-title">{update_title}</span>
    <span class="fl-update-date">{update_date}</span>
  </div>
  <div class="fl-update-body">{update_body_html}</div>
  {if_author}<div class="fl-update-meta"><span class="fl-update-author">{update_author}</span></div>{/if_author}
</div>
{/each_update}
</div>
```

---

### 5.13 KIM List

**Where used:** `embed/kim-list.php` → `templates/default/addons/kim/list.php`, `subject.php`
**Template type:** PHP fragment

**Problem:** The current KIM templates use generic class names (`.fl-list`, `.fl-list-item`,
`.fl-wrap`, `.fl-title`, `.fl-intro`, `.fl-meta`, `.fl-count`) that could collide with host-page
CSS. These must be renamed.

#### Rename map
| Old | New |
|---|---|
| `.fl-wrap` (KIM only) | `.fl-kim` |
| `.fl-title` (KIM only) | `.fl-kim-heading` |
| `.fl-intro` (KIM only) | `.fl-kim-intro` |
| `.fl-list` (KIM only) | `.fl-kim-list` |
| `.fl-list-item` (KIM only) | `.fl-kim-item` |
| `.fl-count` (KIM only) | `.fl-kim-count` |
| `.fl-count--empty` (KIM only) | `.fl-kim-count--empty` |
| `.fl-meta` (KIM only) | `.fl-kim-meta` |

#### New classes for subject (per-fanlisting) view
| Class | Description |
|---|---|
| `.fl-kim-subject` | Wrapper for the per-fanlisting member list view |
| `.fl-kim-subject-heading` | Fanlisting name heading at top of subject view |
| `.fl-kim-member-list` | Container for member rows in subject view |
| `.fl-kim-member-item` | Each approved KIM member |
| `.fl-kim-member-name` | Member name or link |
| `.fl-kim-member-url` | Member site link |

---

### 5.14 Wishlist

**Where used:** `embed/wishlist.php` → `templates/default/wishlist.php`
**Template type:** PHP fragment (base), with optional DB-stored token template overlay

Most wishlist classes already exist. Audit and clean up:

| Class | Status | Notes |
|---|---|---|
| `.fl-wishlist-group` | Exists | Wrapper per type group (Top, Wanted, Granted). Keep. |
| `.fl-wishlist-group-label` | Exists | Group heading. Keep. |
| `.fl-wishlist` | Exists | The `<ul>` of items. **Rename to `.fl-wishlist-list`** for consistency. |
| `.fl-wishlist-item` | Exists | Each `<li>`. Keep name, ensure it's in style.css. |
| `.fl-wishlist-item--top` | **New** | Modifier class for top-wanted items |
| `.fl-wishlist-item--wanted` | **New** | Modifier class for wanted items |
| `.fl-wishlist-item--granted` | **New** | Modifier class for granted items |
| `.fl-wishlist-img` | Exists | Item image. **Rename to `.fl-wishlist-image`** for consistency. |
| `.fl-wishlist-info` | Exists | Text content area. Keep. |
| `.fl-wishlist-subject` | Exists | Subject name. Keep. |
| `.fl-wishlist-cat` | Exists (informal) | Category. **Rename to `.fl-wishlist-category`**. |
| `.fl-wishlist-desc` | Exists | Description. **Rename to `.fl-wishlist-description`**. |
| `.fl-wishlist-type-badge` | **New** | "Top Wanted" / "Wanted" / "Granted" type pill |

---

### 5.15 Success / error messages

| Class | Status | Description |
|---|---|---|
| `.fl-success` | **New** | Success message outer wrapper |
| `.fl-success-body` | **New** | Body text inside success message |
| `.fl-success-actions` | **New** | "What next" links row |
| `.fl-errors` | Exists | Error summary block at top of forms |
| `.fl-error` | **New** | Single error line inside `.fl-errors` |

---

## 6. Deprecated / Renamed Classes

These currently exist in `style.css` or templates. All will be kept as comma-separated selector
aliases in `style.css` for one release, then removed:

| Old name | Replaced by | Where |
|---|---|---|
| `.fl-listing-cards` | `.fl-listing-list` | style.css + owned template default |
| `.fl-listing-card` | `.fl-listing-item` | style.css + owned template default |
| `.fl-listing-card-image` | `.fl-listing-image-wrap` | style.css |
| `.fl-listing-card-body` | `.fl-listing-body` | style.css |
| `.fl-codes-group` | `.fl-code-list` | style.css + codes.php |
| `.fl-code` | `.fl-code-item` | style.css + codes.php |
| `.fl-code-size-heading` | `.fl-code-group-label` | style.css + codes.php |
| `.fl-collective-stats` | `.fl-cstats` | style.css |
| `.fl-newest-owned` | `.fl-cstats-newest` | style.css |
| `.fl-random-owned` | `.fl-cstats-random` | style.css |
| `.fl-categories` (collective) | `.fl-cstats-categories` | style.css |
| `.fl-wishlist` (the `<ul>`) | `.fl-wishlist-list` | style.css + wishlist.php |
| `.fl-wishlist-img` | `.fl-wishlist-image` | style.css + wishlist.php |
| `.fl-wishlist-cat` | `.fl-wishlist-category` | style.css + wishlist.php |
| `.fl-wishlist-desc` | `.fl-wishlist-description` | style.css + wishlist.php |
| `.fl-list` (KIM) | `.fl-kim-list` | kim/list.php + kim/subject.php |
| `.fl-list-item` (KIM) | `.fl-kim-item` | kim/list.php |
| `.fl-wrap` (KIM) | `.fl-kim` | kim/list.php + kim/subject.php |
| `.fl-title` (KIM) | `.fl-kim-heading` | kim/list.php |
| `.fl-intro` (KIM) | `.fl-kim-intro` | kim/list.php |
| `.fl-count` (KIM) | `.fl-kim-count` | kim/list.php |
| `.fl-count--empty` (KIM) | `.fl-kim-count--empty` | kim/list.php |
| `.fl-meta` (KIM) | `.fl-kim-meta` | kim/list.php |
| `.fl-actions` | `.fl-form-actions` | join.php |

---

## 7. Files Touched

### CSS
- `public/assets/css/default/style.css` — complete rewrite/expansion

### PHP repositories (default template strings)
- `src/Repositories/SiteSettingsRepo.php` — `collectiveDefaults()`: `owned_template`, `joined_template`, `collective_stats_template`
- `src/Repositories/ListingSettingsRepo.php` — stats template default, members template default, affiliate row template default

### PHP fragment templates

Every template listed below also gains `fl-embed fl-embed--{type}` on its outermost `<div>`
(see Section 5.0). Changes beyond that are noted per file.

- `templates/default/join.php` — root div → `fl-embed fl-embed--join`; add `.fl-form-fields`, `.fl-form-actions`, `.fl-submit`
- `templates/default/update.php` — root div → `fl-embed fl-embed--update`; same form class changes
- `templates/default/remove.php` — root div → `fl-embed fl-embed--remove`; same
- `templates/default/reset.php` — root div → `fl-embed fl-embed--reset`; same
- `templates/default/reset_confirm.php` — root div → `fl-embed fl-embed--reset`; same
- `templates/default/contact.php` — root div → `fl-embed fl-embed--contact`; same
- `templates/default/adopt.php` — root div → `fl-embed fl-embed--adopt`; `.fl-actions` → `.fl-form-actions`; formalise `.fl-btn`
- `templates/default/affiliate-apply.php` — root div → `fl-embed fl-embed--affiliate-apply`; same form changes
- `templates/default/codes-submit.php` — root div → `fl-embed fl-embed--codes-submit`; same form changes
- `templates/default/members.php` — root div → `fl-embed fl-embed--members`; add `.fl-member-controls`, `.fl-member-sort`, `.fl-member-sort-link`, `.fl-member-back`; `<ul>` fallback → `.fl-member-list` / `.fl-member-item`
- `templates/default/affiliates.php` — root div → `fl-embed fl-embed--affiliates`; `<a>` fallback → `.fl-affiliate-list` / `.fl-affiliate-item` / `.fl-affiliate-image`
- `templates/default/codes.php` — root div → `fl-embed fl-embed--codes`; rename `.fl-codes-group` → `.fl-code-list`, `.fl-code` → `.fl-code-item`, add `.fl-codes` / `.fl-code-group`
- `templates/default/owned.php` — root div → `fl-embed fl-embed--owned`; bare `<ul><li>` fallback → `.fl-listing-list` / `.fl-listing-item`
- `templates/default/joined.php` — root div → `fl-embed fl-embed--joined`; bare `<ul><li>` fallback → `.fl-joined-list` / `.fl-joined-item`
- `templates/default/wishlist.php` — root div → `fl-embed fl-embed--wishlist`; rename old classes, add modifier classes
- `templates/default/addons/kim/list.php` — root div: `fl-wrap` → `fl-embed fl-embed--kim-list fl-kim`; rename all other generic classes to `.fl-kim-*`
- `templates/default/addons/kim/subject.php` — root div → `fl-embed fl-embed--kim-list fl-kim-subject`; add `.fl-kim-subject-*` classes
- `templates/default/addons/kim/signup.php` — root div → `fl-embed fl-embed--kim-signup`; form wrapper classes
- `templates/default/addons/kim/update.php` — root div → `fl-embed fl-embed--kim-update`; same
- `templates/default/addons/kim/reset.php` — root div → `fl-embed fl-embed--kim-reset`; same
- `templates/default/addons/kim/reset-confirm.php` — root div → `fl-embed fl-embed--kim-reset`; same
- `templates/default/addons/updates/feed.php` — this template is shared by both per-listing and
  collective updates rendering. The calling code must pass an `$is_collective` flag (already
  exists in both the controller and the collective embed) so the template can conditionally set
  the root class: `fl-embed fl-embed--updates` for per-listing, `fl-embed fl-embed--collective-updates`
  for collective. Add `.fl-updates` / `.fl-update-item` etc. to both paths.

### Admin JS (snippet generator)
- `public/assets/js/admin.js` — confirm/add `<link rel="stylesheet">` for `style.css` in generated embed code

### Tests
- Any test that asserts on rendered HTML class names for affected templates (check `tests/Unit/` for references to old class names)

---

## 8. What Is NOT Changing

- Embed PHP files (`public/embed/*.php`) — no structural changes, **except** `collective-updates.php`
  which requires a bug fix to apply `collective_updates_template` (see Section 3)
- Controllers — no changes
- The token system — `{tokens}` stay exactly as-is
- The `FormFieldBuilder` / `FieldWrapper` / `FieldRenderer` per-field HTML — already uses correct `fl-field`, `fl-label`, `fl-input` etc.
- Admin templates — no changes (admin has its own stylesheet)
- `tokens.css` / `admin.css` / `skins.css` — no changes

---

## 9. Implementation Order

If implemented in phases, the safest order is:

1. **style.css** — write all new/renamed classes. Old names kept as aliases. Nothing breaks.
2. **Default template strings** (`SiteSettingsRepo`, `ListingSettingsRepo`) — update defaults.
   Only affects sites where the admin has not overridden the template (i.e. DB value is NULL).
   Existing customised templates are untouched.
3. **PHP fragment templates** — class rename pass. Low risk; these are markup-only changes.
4. **KIM template rename** — slightly higher risk due to existing sites potentially having
   `.fl-list` in their own CSS. Document the rename in the changelog.
5. **Admin JS** — add stylesheet link to generated snippets.
6. **Tests** — update any assertions on class names.
