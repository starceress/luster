# Migrating CSS Classes from Enthusiast and Listing Admin

If you had custom CSS on your fanlisting pages that targeted Enthusiast or Listing Admin
class names (like `show_join_name_field` or `input1`), those styles won't work with Luster
because Luster uses a different set of class names. This reference maps every old class
name to its Luster equivalent so you can update your stylesheets.

You'll typically need this guide if you're migrating from Enthusiast or Listing Admin and
had custom styling beyond the default look. If you're starting fresh with Luster or using
the default styles, you can skip straight to the
[quick reference at the bottom](#quick-reference-lusters-complete-public-css-class-list).

Luster uses a consistent `fl-` prefix for all public embed classes. Each form also
gets a form-specific class (e.g. `fl-form-join`) so you can target individual
forms in your CSS without complicated selectors.

---

## How Luster Structures Form Fields

### Template tokens expand to full HTML

In Luster's admin-editable templates, field tokens like `{field_name}`, `{field_email}`,
`{field_url}`, `{field_country}`, `{additional_fields}`, `{field_password}`,
`{field_password_confirm}`, `{captcha_block}`, `{field_message}`, `{field_subject}`,
`{field_image}`, etc. are **not** plain text placeholders. Each token expands to a
complete, pre-styled HTML block built by `FormFieldBuilder` + `FieldWrapper`.

When you write this in a template:

```
{field_name}
{field_email}
```

Luster renders it as:

```html
<div class="fl-field" id="wrap-join-name">
  <label class="fl-label" for="join-name">Name<span class="fl-required">*</span></label>
  <input class="fl-input" type="text" id="join-name" name="name" required>
</div>
<div class="fl-field" id="wrap-join-email">
  <label class="fl-label" for="join-email">Email<span class="fl-required">*</span></label>
  <input class="fl-input" type="email" id="join-email" name="email" required>
  <small class="fl-field-hint">Will not be publicly displayed.</small>
</div>
```

This means you do **not** need to add CSS classes around field tokens in your templates —
they already come fully wrapped. You style them from your site stylesheet using the
classes and IDs that the wrapper generates.

### The wrapper structure

Every field follows the same pattern:

```html
<div class="fl-field" id="wrap-{field-id}">
  <label class="fl-label" for="{field-id}">
    Label Text
    <span class="fl-required">*</span>       <!-- only when required -->
  </label>
  <p class="fl-field-error">Error text</p>    <!-- only when validation fails -->
  <input class="fl-input" id="{field-id}" …>  <!-- or fl-select, fl-textarea -->
  <small class="fl-field-hint">Help text</small> <!-- only when hint is set -->
</div>
```

| Class | Element | Purpose |
|---|---|---|
| `fl-field` | `<div>` | Outer wrapper — one per form field |
| `fl-label` | `<label>` | The field's visible label text |
| `fl-required` | `<span>` | The red asterisk next to required fields |
| `fl-input` | `<input>` | Text, email, password, URL inputs |
| `fl-select` | `<select>` | Dropdown selects (e.g. country) |
| `fl-textarea` | `<textarea>` | Multi-line text areas (e.g. message, comments) |
| `fl-field-hint` | `<small>` | Grey help text below the input |
| `fl-field-error` | `<p>` | Red validation error text (shown after failed submit) |

### Targeting individual fields by ID

Every field wrapper gets a unique `id` based on the field's input ID, prefixed with
`wrap-`. The input element inside also gets the unprefixed ID. This lets you target
specific fields in CSS:

**Join form:**

| Token | Wrapper ID | Input ID |
|---|---|---|
| `{field_name}` | `wrap-join-name` | `join-name` |
| `{field_email}` | `wrap-join-email` | `join-email` |
| `{field_url}` | `wrap-join-url` | `join-url` |
| `{field_country}` | — (uses `fl-select`) | — |
| `{field_password}` | `wrap-join-password` | `join-password` |
| `{field_password_confirm}` | `wrap-join-password-confirm` | `join-password-confirm` |
| `{captcha_block}` | `wrap-join-captcha` | `join-captcha` |
| `{additional_fields}` | `wrap-f-{fieldname}` per field | `f-{fieldname}` |

**Update form (sign-in step):**

| Token | Wrapper ID | Input ID |
|---|---|---|
| `{field_signin_email}` | `wrap-upd-email` | `upd-email` |
| `{field_signin_password}` | `wrap-upd-password` | `upd-password` |

**Update form (signed-in step):**

| Token | Wrapper ID | Input ID |
|---|---|---|
| `{field_new_name}` | `wrap-upd-name` | `upd-name` |
| `{field_new_email}` | `wrap-upd-new-email` | `upd-new-email` |
| `{field_new_url}` | `wrap-upd-url` | `upd-url` |
| `{field_new_country}` | — (uses `fl-select`) | — |
| `{field_password}` | `wrap-upd-new-pass` | `upd-new-pass` |
| `{field_password_confirm}` | `wrap-upd-confirm-pass` | `upd-confirm-pass` |
| `{captcha_block}` | `wrap-upd-captcha` | `upd-captcha` |
| `{field_remove}` | — | `upd-delete-membership` |

**Reset form (email step):**

| Token | Wrapper ID | Input ID |
|---|---|---|
| `{field_email}` | `wrap-rst-email` | `rst-email` |
| `{captcha_block}` | `wrap-rst-captcha` | `rst-captcha` |

**Reset form (confirm step):**

| Token | Wrapper ID | Input ID |
|---|---|---|
| `{field_new_password}` | `wrap-rc-password` | `rc-password` |
| `{field_new_password_confirm}` | `wrap-rc-confirm` | `rc-confirm` |

**Contact form:**

| Token | Wrapper ID | Input ID |
|---|---|---|
| `{field_name}` | `wrap-contact-name` | `contact-name` |
| `{field_email}` | `wrap-contact-email` | `contact-email` |
| `{field_url}` | `wrap-contact-url` | `contact-url` |
| `{field_subject}` | `wrap-contact-subject` | `contact-subject` |
| `{field_message}` | `wrap-contact-message` | `contact-message` |
| `{captcha_block}` | `wrap-contact-captcha` | `contact-captcha` |

**Affiliate application form:**

| Token | Wrapper ID | Input ID |
|---|---|---|
| `{field_name}` | `wrap-aff-name` | `aff-name` |
| `{field_email}` | `wrap-aff-email` | `aff-email` |
| `{field_url}` | `wrap-aff-url` | `aff-url` |
| `{field_image}` | `wrap-aff-image` | `aff-image` |
| `{field_message}` | `wrap-aff-message` | `aff-message` |
| `{captcha_block}` | `wrap-aff-captcha` | `aff-captcha` |

**Code donation form:**

| Token | Wrapper ID | Input ID |
|---|---|---|
| `{field_code_image}` | `wrap-code-image` | `code-image` |
| `{field_submitter_name}` | `wrap-sub-name` | `sub-name` |
| `{field_submitter_email}` | `wrap-sub-email` | `sub-email` |
| `{field_submitter_url}` | `wrap-sub-url` | `sub-url` |
| `{captcha_block}` | `wrap-captcha` | `captcha` |

### CSS examples

Style a specific field:
```css
#wrap-join-name {
  display: grid;
  grid-template-columns: 200px 1fr;
  align-items: baseline;
}
```

Style all labels on the join form:
```css
.fl-form-join .fl-label {
  font-weight: bold;
}
```

Two-column layout for the update form:
```css
.fl-form-update .fl-field {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 0.2em 1em;
  align-items: baseline;
}
.fl-form-update .fl-label {
  margin-bottom: 0;
  white-space: nowrap;
}
.fl-form-update .fl-field-hint,
.fl-form-update .fl-field-error {
  grid-column: 2;
}
```

### How this compares to Enthusiast and Listing Admin

| Concept | Enthusiast | Listing Admin | Luster |
|---|---|---|---|
| Field wrapper | `<p class="show_join_name">` | inline `style="float:left"` | `<div class="fl-field" id="wrap-join-name">` |
| Label | `<span class="show_join_name_label" style="font-weight:bold; display:block">` | inline label text | `<label class="fl-label" for="join-name">` |
| Input | `<input class="show_join_name_field">` | `<input class="input1">` | `<input class="fl-input" id="join-name">` |
| Select | `<select class="show_join_country_field">` | `<select class="input1">` | `<select class="fl-select">` |
| Required marker | `*` in label text | `*` in label text | `<span class="fl-required">*</span>` |
| Hint text | — | — | `<small class="fl-field-hint">` |
| Error text | `<p class="show_join_error">` (top of form) | `<span class="error">` | `<p class="fl-field-error">` (per-field) + `fl-errors` (top) |
| Submit button | `<input class="show_join_submit_button">` | `<input class="input2">` | `<button class="fl-submit">` |
| Reset button | `<input class="show_join_reset_button">` | `<input class="input2">` | `<button class="fl-btn-secondary">` |
| Target one field | `.show_join_name { ... }` | No clean way | `#wrap-join-name { ... }` |
| Target one form | `.show_join_form { ... }` | `#show-update { ... }` | `.fl-form-join { ... }` |

---

## Enthusiast → Luster

### Join Form (`show_join.php`)

| Enthusiast Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `show_join_form` | `<form>` | `fl-form fl-form-join` | |
| `show_join_name` | `<p>` wrapper | `fl-field` + `id="wrap-join-name"` | |
| `show_join_name_label` | `<span>` | `fl-label` | |
| `show_join_name_field` | `<input>` | `fl-input` + `id="join-name"` | |
| `show_join_email` | `<p>` wrapper | `fl-field` + `id="wrap-join-email"` | |
| `show_join_email_label` | `<span>` | `fl-label` | |
| `show_join_email_field` | `<input>` | `fl-input` + `id="join-email"` | |
| `show_join_country` | `<p>` wrapper | `fl-field` | |
| `show_join_country_label` | `<span>` | `fl-label` | |
| `show_join_country_field` | `<select>` | `fl-select` | |
| `show_join_password` | `<p>` wrapper | `fl-field` + `id="wrap-join-password"` | |
| `show_join_password_label` | `<span>` | `fl-label` | |
| `show_join_password_field` | `<input>` | `fl-input` + `id="join-password"` | |
| `show_join_password_field2` | `<input>` | `fl-input` + `id="join-password-confirm"` | |
| `show_join_url` | `<p>` wrapper | `fl-field` + `id="wrap-join-url"` | |
| `show_join_url_label` | `<span>` | `fl-label` | |
| `show_join_url_field` | `<input>` | `fl-input` + `id="join-url"` | |
| `show_join_{fieldname}` | `<p>` wrapper | `fl-field` + `id="wrap-f-{fieldname}"` | Dynamic per custom field |
| `show_join_{fieldname}_label` | `<span>` | `fl-label` | |
| `show_join_{fieldname}_field` | `<input>` | `fl-input` + `id="f-{fieldname}"` | |
| `show_join_comments` | `<p>` wrapper | `fl-field` | |
| `show_join_comments_label` | `<span>` | `fl-label` | |
| `show_join_comments_field` | `<textarea>` | `fl-textarea` | |
| `show_join_submit` | `<p>` wrapper | `fl-form-actions` | |
| `show_join_submit_button` | `<input type="submit">` | `fl-submit` (`<button>`) | |
| `show_join_reset_button` | `<input type="reset">` | `fl-btn-secondary` (`<button>`) | |
| `show_join_error` | `<p>` | `fl-errors` | |
| `show_join_processed_noemail` | `<p>` | `fl-success` | |
| `show_join_processed_errormail` | `<p>` | `fl-success` | |
| `show_join_processed_emailsent` | `<p>` | `fl-success` | |
| `show_join_intro` | `<p>` | `fl-hint` | |
| `show_join_intro_problems` | `<p>` | `fl-hint` | |
| `show_join_intro_required` | `<p>` | `fl-hint` | |
| `show_join_email_settings` | `<p>` | — | Luster does not expose email show/hide to the public form |
| `show_join_email_settings_*` | various | — | See above |
| `show_join_send_account_info` | `<span>` | — | Luster uses `{reset_url}` in join emails instead |
| `enth_show_join_send_account_info_field` | `<input>` | — | See above |
| `show_join_credits` | `<p>` | `fl-powered-by` | Available via `$luster_attribution` variable |

### Update Form (`show_update.php`)

| Enthusiast Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `show_update_form` | `<form>` | `fl-form fl-form-update` | Luster uses a two-step flow: sign-in (`fl-form-signin`), then update |
| `show_update_old_email` | `<p>` wrapper | — | Luster shows "Signed in as **email**" text instead |
| `show_update_current_password` | `<p>` wrapper | — | Handled by sign-in step |
| `show_update_name` | `<p>` wrapper | `fl-field` | |
| `show_update_name_label` | `<span>` | `fl-label` | |
| `show_update_name_field` | `<input>` | `fl-input` | |
| `show_update_email` | `<p>` wrapper | `fl-field` | |
| `show_update_email_label` | `<span>` | `fl-label` | |
| `show_update_email_field` | `<input>` | `fl-input` | |
| `show_update_password` | `<p>` wrapper | `fl-field` | |
| `show_update_password_label` | `<span>` | `fl-label` | |
| `show_update_password_field` | `<input>` | `fl-input` | |
| `show_update_password_field2` | `<input>` | `fl-input` | |
| `show_update_country` | `<p>` wrapper | `fl-field` | |
| `show_update_country_label` | `<span>` | `fl-label` | |
| `show_update_country_field` | `<select>` | `fl-select` | |
| `show_update_url` | `<p>` wrapper | `fl-field` | |
| `show_update_url_label` | `<span>` | `fl-label` | |
| `show_update_url_field` | `<input>` | `fl-input` | |
| `show_update_url_delete` | `<span>` | — | Luster has a "Remove my website URL" checkbox within the URL field |
| `show_update_email_settings` | `<p>` | — | Luster does not expose email show/hide in the update form |
| `show_update_email_settings_*` | various | — | See above |
| `show_update_{fieldname}` | `<p>` wrapper | `fl-field` | Dynamic per custom field |
| `show_update_{fieldname}_label` | `<span>` | `fl-label` | |
| `show_update_{fieldname}_field` | `<input>` | `fl-input` | |
| `show_update_submit` | `<p>` wrapper | `fl-form-actions` | |
| `show_update_submit_button` | `<input type="submit">` | `fl-submit` | |
| `show_update_error` | `<p>` | `fl-errors` | |
| `show_update_process_hold` | `<p>` | `fl-success` | |
| `show_update_process_nohold` | `<p>` | `fl-success` | |
| `show_update_intro` | `<p>` | `fl-hint` | |
| `show_update_intro_instructions` | `<p>` | `fl-hint` | |
| `show_update_intro_link_to_join` | `<p>` | — | Not applicable; Luster's update form requires sign-in |
| `show_update_credits` | `<p>` | `fl-powered-by` | Via `$luster_attribution` |

### Delete Membership (Enthusiast — `show_delete.php`)

| Enthusiast Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `show_delete_form` | `<form>` | — | Luster integrates delete as a checkbox within `fl-form-update` |
| `show_delete_intro` | `<p>` | `fl-field--danger` | Warning block on the update form |
| `show_delete_email` | `<p>` wrapper | — | Handled by sign-in step |
| `show_delete_password` | `<p>` wrapper | — | Handled by sign-in step |
| `show_delete_submit_button` | `<input type="submit">` | — | Checkbox: `name="delete_membership"` inside `fl-field--danger` |
| `show_delete_process` | `<p>` | `fl-success` | |

### Lost Password (`show_lostpass.php`)

| Enthusiast Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `show_lostpass_form` | `<form>` | `fl-form fl-form-reset` | |
| `show_lostpass_email` | `<p>` wrapper | `fl-field` | |
| `show_lostpass_email_label` | `<span>` | `fl-label` | |
| `show_lostpass_email_field` | `<input>` | `fl-input` | |
| `show_lostpass_submit_button` | `<input type="submit">` | `fl-submit` | |
| `show_lostpass_error` | `<p>` | `fl-errors` | |
| `show_lostpass_bad_email` | `<p>` | `fl-errors` | |
| `show_lostpass_no_such_member` | `<p>` | `fl-errors` | |
| `show_lostpass_processed_done` | `<p>` | `fl-success` | |
| `show_lostpass_processed_error` | `<p>` | `fl-errors` | |
| `show_lostpass_intro` | `<p>` | `fl-hint` | |
| `show_lostpass_intro_instructions` | `<p>` | `fl-hint` | |
| `show_lostpass_credits` | `<p>` | `fl-powered-by` | Via `$luster_attribution` |

### Member List (`show_members.php`)

| Enthusiast Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `show_members_showing_what` | `<p>` | `fl-member-stats` | |
| `show_members_email` | `<a>` | `fl-member-email` | |
| `show_members_no_email` | `<span>` | — | Luster does not show struck-through hidden emails |
| `show_members_website` | `<a>` | Within `fl-member-item` | Linked member name |
| `show_members_no_website` | `<span>` | Within `fl-member-item` | Plain text member name |
| `show_members_pagination` | `<p>` | `fl-pagination-nav` | |

### Sort Controls (`show_sort.php`)

| Enthusiast Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `show_sort_form` | `<form>` | `fl-member-controls` | |
| `show_sort_list` | `<ul>` | `fl-member-sort` | Inline sort links |

### Owned Listings (`show_owned.php`)

| Enthusiast Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `show_owned_select_form` | `<form>` / `<select>` | `fl-category-dropdown` | |
| `show_owned_list_items` | `<ul>` | `fl-category-list` | |
| `show_owned_intro_dropdown` | `<p>` | Within `fl-category-nav` | |
| `show_owned_intro_list` | `<p>` | Within `fl-category-nav` | |
| `show_owned_where_you_are` | `<p>` | `fl-cat-heading` | |
| `show_owned_no_listings_here` | `<p>` | `fl-empty` | |
| `show_owned_go_back` | `<p>` | `fl-category-back` | |
| `show_owned_credits` | `<p>` | `fl-powered-by` | Via `$luster_attribution` |

### Joined Listings (`show_joined.php`)

| Enthusiast Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `show_joined_select_form` | `<form>` / `<select>` | `fl-category-dropdown` | |
| `show_joined_list_items` | `<ul>` | `fl-category-list` | |
| `show_joined_intro_dropdown` | `<p>` | Within `fl-category-nav` | |
| `show_joined_intro_list` | `<p>` | Within `fl-category-nav` | |
| `show_joined_where_you_are` | `<p>` | `fl-cat-heading` | |
| `show_joined_all_group_by_cats` | `<p>` | `fl-joined-section-heading` | |
| `show_joined_no_listings_here` | `<p>` | `fl-empty` | |
| `show_joined_go_back` | `<p>` | `fl-category-back` | |

### Stats (`show_stats.php`) and Affiliates (`show_affiliates.php`)

Both Enthusiast and Luster use admin-editable templates with token substitution. No
hardcoded CSS classes in either script. Enthusiast uses `$$token$$` delimiters; Luster
uses `{token}` delimiters.

**Network conditionals:** Luster stats templates offer three conditional blocks for
network approval: `{if_approved_by}` (true for either network), `{if_tfl}` (TFL only),
and `{if_tafl}` (TAFL only). Use `{if_approved_by}` when the content is the same for
both networks. Only use `{if_tfl}` and `{if_tafl}` when you need different content per
network (e.g. linking to different URLs).

### Collective Stats (`show_collective_stats.php`)

Enthusiast exposes PHP variables only (no HTML output). Luster exposes the same
variables **plus** renders an HTML block. See the
[collective-stats embed docs](../public/embed/collective-stats.php) for the full
variable list including Enthusiast-compatible aliases.

---

## Listing Admin → Luster

### Form Input Classes (Global)

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `input1` | `<input>`, `<select>`, `<textarea>` | `fl-input`, `fl-select`, `fl-textarea` | Luster uses separate classes per element type |
| `input2` | `<input type="submit/reset">` | `fl-submit`, `fl-btn-secondary` | Luster uses `<button>` elements |
| `input3` | `<input type="radio/checkbox">` | — | Styled within `fl-field`; no special class |

### Layout Utilities

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `tc` | Various | — | Luster uses specific classes per context, not a global text-center utility |
| `clear` | `<p>` | — | Not needed; Luster uses flexbox/grid |
| `clearField` | `<p>` | — | Not needed |

### Messages / Feedback

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `success` | `<span>` | `fl-success` | |
| `successButton` | `<p>` | `fl-success` | Luster merges label + container into one class |
| `error` | `<span>` | `fl-errors` / `fl-error` | |
| `errorButton` | `<p>` | `fl-errors` | |
| `note` | `<p>` | `fl-hint` | |
| `noteButton` | `<p>` | `fl-hint` | |

### Form Section Wrappers

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `inputPassword` | `<p>` | `fl-field` + `id="wrap-join-password"` | |
| `inputCaptcha` | `<p>` | `fl-field` + `id="wrap-join-captcha"` | |
| `inputAntispam` | `<p>` | `fl-field` + captcha wrapper | Luster uses arithmetic captcha |

### Codes / Buttons

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `codesBlock` | `<div>` | `fl-code-list` | |

### Member List

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `faveField` | `<p>` | `fl-member-field` | |
| `faveField{n}` | `<span>` | `fl-member-field` | Luster does not number fields; each gets the same class |
| `notext` | `<span>` | — | Not used |
| `noMargin` | `<p>` | — | Not needed |

### Affiliates

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `showAffiliates` | `<div>` | `fl-affiliates-section` | |

### Owned Listings

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `sep` | `<div>` | `fl-listing-section` | Section separator |
| `sec` | `<div>` | `fl-listing-section` | Section wrapper |
| `showBack` | `<p>` | `fl-category-back` | |
| `showListingsSort` | `<h3>` | `fl-category-nav` | |
| `ddListings` | `<p>` | `fl-category-dropdown` | |
| `owned` | `<table>` | `fl-listing-list` | Luster uses `<ul>` list, not `<table>` |
| `left` | `<td>` | — | Not applicable; Luster uses flex/grid |
| `center` | `<td>` | — | Not applicable |
| `img` | `<p>` | `fl-listing-image-wrap` | |
| `details` | `<p>` | `fl-listing-meta` | |
| `current` | `<img>` | `fl-listing-image` | |
| `desc` | `<p>` | `fl-listing-body` | |

### Joined Listings

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `joinedImages` | `<div>` | `fl-joined-list` | |
| `joinedListings` | `<div>` | `fl-joined-list` | |
| `joinedbyme` | `<div>` | — | No equivalent; Luster does not distinguish "made by me" |
| `joined` | `<table>`, `<img>` | `fl-joined-list`, `fl-joined-image` | |
| `pending` | `<tbody>` | — | Pending joined listings are not shown publicly |
| `madebyme` | `<tbody>` | — | See `joinedbyme` above |
| `joinedRandom` | `<img>` | `fl-random-joined-listing-image` | |

### Updates / Blog

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `entry_listingadmin` | `<div>` | `fl-update-item` | |
| `date` | `<span>` | `fl-update-date` | |
| `cat` | `<p>` | `fl-update-categories` | |
| `indi` | `<li>` | `fl-update-item` | |
| `post` | `<div>` | `fl-update-item` | |
| `entry` | `<div>` | `fl-update-body` | |
| `archives` | `<div>` | — | Luster does not have an archive view |
| `bymonth` | `<div>` | — | See above |
| `bylisting` | `<div>` | — | See above |
| `backLink` | `<p>` | — | Not applicable |

### Pagination

| Listing Admin Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `pagi` | `<span>` | `fl-pagination-nav` (list) | Luster uses `<ul>` + `<li>` pagination |

### Listing Admin IDs → Luster Wrapper Classes

| Listing Admin ID | Luster Equivalent | Notes |
|---|---|---|
| `#show-members` | `.fl-embed--members` | Embed wrapper |
| `#show-update` | `.fl-embed--update` | Embed wrapper |
| `#show-reset` | `.fl-embed--reset` (if needed) | Embed wrapper |
| `#show-codes` | `.fl-codes` | Codes container |
| `#show-listings` | `.fl-embed` (owned context) | |
| `#show-joined` | `.fl-embed` (joined context) | |
| `#show-updates` | `.fl-updates` | Updates container |
| `#show-delete` | `.fl-field--danger` | Integrated into update form |
| `#show-affiliates` | `.fl-affiliates-section` | |
| `#show-kim-join` | `.fl-embed--kim-signup` | |
| `#show-kim-members` | `.fl-embed--kim-list` | |
| `#show-kim-update` | `.fl-embed--kim-update` | |
| `#show-kim-reset` | `.fl-embed--kim-reset` | |
| `#pagination` | `.fl-pagination-nav` | |
| `#captcha` | — | Luster uses arithmetic text captcha, not an image |

### Credits / Attribution

| Legacy Class | Luster Equivalent | Notes |
|---|---|---|
| `show_join_credits` (Enthusiast) | `fl-powered-by` | Via `echo $luster_attribution;` |
| `show_update_credits` (Enthusiast) | `fl-powered-by` | Via `echo $luster_attribution;` |
| `show_lostpass_credits` (Enthusiast) | `fl-powered-by` | Via `echo $luster_attribution;` |
| `show_owned_credits` (Enthusiast) | `fl-powered-by` | Via `echo $luster_attribution;` |
| `showCredits-LA-RF` (Listing Admin) | `fl-powered-by` | Via `echo $luster_attribution;` |

---

## Fanatically → Luster

Fanatically ships two stylesheets: `stylesheet.css` (the default site theme) and
`templates/style.css` (the template-specific styles). The site-theme classes
(`#container`, `.top-nav`, `#header`, `#footer`, `#navi`, `.navi`, etc.) are
Fanatically's *page layout*—they wrap the whole site, not individual embeds.
Because Luster is embed-only, those classes have no Luster equivalent and won't
appear in migrated embed CSS at all.

The embed component classes below are the ones you'll need to update.

### Forms

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `fan-form` | `<form>` | `fl-form` | Base form class; always paired with a specific class |
| `join-form` | `<form>` | `fl-form fl-form-join` | |
| `affiliate-apply-form` | `<form>` | `fl-form fl-form-affiliate-apply` | |
| `code-donation-form` | `<form>` | `fl-form fl-form-codes-submit` | |
| `join-field` | Field wrapper | `fl-field` | |
| `fan-field-hint` | `<small>` | `fl-field-hint` | |
| `fan-label-hint` | `<span>` | `fl-field-hint` | Luster renders inline label notes the same way as regular hint text |
| `fan-checkbox-label` | `<label>` | `fl-check-label` | |
| `fan-form-actions` | `<div>` | `fl-form-actions` | |
| `fan-form-secondary-link` | `<a>` | `fl-btn-secondary` | No dedicated class; use a plain link or `fl-btn-secondary` |
| `fan-btn-danger` | `<button>` | — | Luster wraps the delete option in `fl-field--danger`; no separate danger button class |

### Success / Error

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `fan-success` | `<div>` | `fl-success` | |
| `join-success` | `<div>` | `fl-success` | |
| `affiliate-apply-success` | `<div>` | `fl-success` | |
| `code-donation-success` | `<div>` | `fl-success` | |
| `fan-errors` | `<div>` | `fl-errors` | |
| `join-errors` | `<div>` | `fl-errors` | |
| `affiliate-apply-errors` | `<div>` | `fl-errors` | |
| `code-donation-errors` | `<div>` | `fl-errors` | |

### Member List

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `member-list-wrap` | `<div>` | `fl-embed fl-embed--members` | Outer embed wrapper |
| `fan-member-list-wrap` | `<div>` | `fl-embed fl-embed--members` | Outer embed wrapper |
| `member-search-form` | `<form>` | `fl-member-controls` | Sort/filter controls wrapper |
| `member-count` | `<p>` | `fl-member-stats` | "Showing X members" line |
| `member-row` | Row element | `fl-member-item` | Single member entry |
| `member-country` | `<span>` | `fl-member-country` | |
| `fan-country-nav` | `<ul>` | `fl-member-filter` | Letter/country filter nav |
| `fan-member-list` | `<table>` | `fl-member-table` | Table-layout member list |

### Pagination

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `pagination` | `<div>` | `fl-pagination-nav` | Luster uses `<ul>` |
| `pagination .page-current` | `<span>` | `fl-page-current` | |
| `fan-pagination` | `<div>` | `fl-pagination-nav` | |
| `fan-pagination span` (current page) | `<span>` | `fl-page-current` | |

### Category Navigation

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `fan-category-list` | `<ul>` | `fl-category-list` | |
| `fan-owned-category-nav` | `<div>` | `fl-category-nav` | |
| `fan-joined-category-nav` | `<div>` | `fl-category-nav` | |
| `fan-cat-active` | modifier | — | No equivalent; Luster uses `<strong>` for the active sort item, not a CSS class modifier |
| `fan-size-nav` | `<div>` | — | Luster groups codes by size with `fl-code-group`; there is no separate size navigation element |

### Joined Listings

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `fan-joined-category-group` | `<div>` | `fl-joined-section` | Category-grouped section |
| `fan-joined-category-heading` | `<h2>` | `fl-joined-section-heading` | |
| `fan-joined-listings` | `<div>` | `fl-joined-list` | |
| `fan-joined-listing-item` | `<div>` | `fl-joined-item` | |

### Codes

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `codes-group` | `<div>` | `fl-code-group` | |
| `codes-size-label` | `<p>` | `fl-code-group-label` | |
| `codes-grid` | `<div>` | `fl-code-list` | Luster uses `<ul>` |
| `code-item` | `<div>` | `fl-code-item` | |
| `fan-donate-toggle` | `<button>` | `fl-codes-submit-toggle` / `fl-affiliate-apply-toggle` | Fanatically uses a button; Luster uses a `<summary>` inside a `<details>` collapsible |

### Affiliates

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `affiliates-list` | `<div>` | `fl-affiliate-list` | Luster uses `<ul>` |
| `affiliate-item` | `<div>` | `fl-affiliate-item` | |

### Updates (Updates Feed add-on)

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `updates-list` | `<div>` | `fl-updates` | |
| `update-entry` | `<div>` | `fl-update-item` | |
| `update-date` | `<span>` | `fl-update-date` | |
| `update-body` | `<div>` | `fl-update-body` | |

### Fanlisting Stats

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `fanlisting-stats` | `<dl>` | `fl-stats` | Both use token-based templates for the output HTML |
| `fanlisting-stats dt` | `<dt>` | `fl-stats-label` | |

### KIM List (KIM List add-on)

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `fan-kim-item` | `<li>` | `fl-kim-item` | |
| `fan-kim-name` | `<span>` | `fl-kim-member-name` | |
| `fan-kim-fanlisting` | `<span>` | — | No equivalent; in Luster, fanlisting context is per-embed, not a per-row class |
| `fan-kim-notes` | `<span>` | — | Luster's KIM list embed has no per-entry notes field |

### Wishlist (Wishlist add-on)

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `fan-wishlist-image` | `<div>` wrapping `<img>` | `fl-wishlist-image` | Fanatically wraps the image in a `<div>`; Luster's class is on `<img>` directly |
| `fan-wishlist-subject` | `<span>` | `fl-wishlist-subject` | |
| `fan-wishlist-category` | `<span>` | `fl-wishlist-category` | |
| `fan-wishlist-description` | `<span>` | `fl-wishlist-description` | |

### Attribution

| Fanatically Class | Element | Luster Equivalent | Notes |
|---|---|---|---|
| `fanatically-credit` | `<p>` | `fl-powered-by` | Via `$luster_attribution` variable |

---

## Luster Form-Specific Classes (Full List)

Every Luster embed form has `fl-form` plus a form-specific class:

| Form | Class | Embed File |
|---|---|---|
| Join | `fl-form-join` | `embed/join.php` |
| Update (sign-in step) | `fl-form-signin` | `embed/update.php` |
| Update (fields step) | `fl-form-update` | `embed/update.php` |
| Password reset (email) | `fl-form-reset` | `embed/reset.php` |
| Password reset (confirm) | `fl-form-reset-confirm` | `embed/reset.php` |
| Contact | `fl-form-contact` | `embed/contact.php` |
| Code submission | `fl-form-codes-submit` | `embed/codes-submit.php` |
| Adoption application | `fl-form-adopt` | `embed/adopt.php` |
| Affiliate application | `fl-form-affiliate-apply` | `embed/affiliate-apply.php` |
| KIM sign-up | `fl-form-kim-signup` | `embed/kim-signup.php` |
| KIM update | `fl-form-kim-update` | `embed/kim-update.php` |
| KIM reset (email) | `fl-form-kim-reset` | `embed/kim-reset.php` |
| KIM reset (confirm) | `fl-form-kim-reset-confirm` | `embed/kim-reset.php` |
| Join rules gate | `fl-form fl-rules-form` | `embed/rules-gate.php` |

---

## Luster Embed Wrapper Classes (Full List)

Each embed's outermost `<div>` has `fl-embed` plus a type-specific modifier:

| Embed | Wrapper Class |
|---|---|
| Join | `fl-embed fl-embed--join` |
| Update | `fl-embed fl-embed--update` |
| Reset | `fl-embed fl-embed--reset` |
| Members | `fl-embed fl-embed--members` |
| Contact | `fl-embed fl-embed--contact` |
| Codes submit | `fl-embed fl-embed--codes-submit` |
| Adopt | `fl-embed fl-embed--adopt` |
| Affiliate apply | `fl-embed fl-embed--affiliate-apply` |
| Codes gallery | `fl-embed fl-embed--codes` |
| Affiliates | `fl-embed fl-embed--affiliates` |
| Affiliate apply | `fl-embed fl-embed--affiliate-apply` |
| Adopt | `fl-embed fl-embed--adopt` |
| Owned listings | `fl-embed fl-embed--owned` |
| Joined listings | `fl-embed fl-embed--joined` |
| Updates (per-listing) | `fl-embed fl-embed--updates` |
| Collective updates | `fl-embed fl-embed--collective-updates` |
| Wishlist | `fl-embed fl-embed--wishlist` |
| Rules gate | `fl-embed fl-embed--rules-gate` |
| KIM signup | `fl-embed fl-embed--kim-signup` |
| KIM update | `fl-embed fl-embed--kim-update` |
| KIM reset | `fl-embed fl-embed--kim-reset` |
| KIM list | `fl-embed fl-embed--kim-list` |

> **Note:** Latest listing, random listing, latest joined listing, random joined listing, stats,
> and collective-stats embeds do not have an outer `fl-embed--...` wrapper. They render their
> own component classes directly.

---

## Quick Reference: Luster's Complete Public CSS Class List

### Global
| Class | Element | Purpose |
|---|---|---|
| `fl-embed` | `<div>` | Root wrapper for every embed |
| `fl-empty` | `<p>` | Empty-state message |
| `fl-hint` | `<p>` | Informational/instructional text |
| `fl-heading` | `<h2>` / `<p>` | Section heading inside an embed |
| `fl-muted` | `<span>` | De-emphasized text |
| `fl-powered-by` | `<p>` | Attribution footer |

### Forms
| Class | Element | Purpose |
|---|---|---|
| `fl-form` | `<form>` | Base form class (always paired with a specific class) |
| `fl-field` | `<div>` | Field wrapper (label + input + hint) |
| `fl-field--danger` | `<div>` | Danger/warning field block (delete membership) |
| `fl-fieldset` | `<fieldset>` | Group of related fields |
| `fl-label` | `<label>` | Field label |
| `fl-input` | `<input>` | Text/email/password/URL input |
| `fl-select` | `<select>` | Dropdown select |
| `fl-textarea` | `<textarea>` | Multi-line text input |
| `fl-checkbox` | `<input type="checkbox">` | Checkbox input |
| `fl-radio` | `<input type="radio">` | Radio button input |
| `fl-check-label` | `<label>` | Label paired with a checkbox or radio |
| `fl-file-zone` | `<label>` | Clickable file-upload drop zone |
| `fl-file-prompt` | `<span>` | Text displayed inside the file drop zone |
| `fl-field-hint` | `<small>` / `<p>` | Help text below a field |
| `fl-field-error` | `<p>` | Per-field validation error |
| `fl-required` | `<span>` | Required field asterisk |
| `fl-form-actions` | `<div>` | Button row (submit + reset) |
| `fl-submit` | `<button>` | Primary submit button |
| `fl-btn` | `<button>` | Generic button |
| `fl-btn-secondary` | `<button>` | Secondary/reset button |
| `fl-errors` | `<div>` | Top-of-form error summary |
| `fl-error` | `<p>` | Single error line |
| `fl-success` | `<div>` | Success message block |
| `fl-success-body` | `<div>` | Body content area of a success message |
| `fl-success-actions` | `<div>` | Action links below a success message |
| `fl-lost-password` | `<p>` | "Forgot your password?" link paragraph (update form) |

### Members
| Class | Element | Purpose |
|---|---|---|
| `fl-member-list` | `<ul>` | Member list container |
| `fl-member-item` | `<li>` | Single member entry |
| `fl-member-name` | `<span>` | Member name |
| `fl-member-country` | `<span>` | Member country |
| `fl-member-email` | `<span>` | Member email |
| `fl-member-field` | `<span>` | Additional field wrapper |
| `fl-member-field-label` | `<span>` | Additional field label |
| `fl-member-field-value` | `<span>` | Additional field value |
| `fl-member-controls` | `<div>` | Sort/filter controls wrapper |
| `fl-member-sort` | `<p>` | Sort links container |
| `fl-member-sort-link` | `<a>` / `<strong>` | Individual sort link (current page uses `<strong>`) |
| `fl-member-stats` | `<p>` | "Showing X members" status line |
| `fl-member-filter` | `<div>` | Letter/country filter |
| `fl-member-back` | `<p>` | "Back to all" link wrapper |
| `fl-member-table` | `<table>` | Table-layout member list (new-style template) |
| `fl-member-table-head` | `<tr>` | Header row of member table |
| `fl-member-table-th` | `<th>` | Header cell |
| `fl-member-table-cell` | `<td>` | Data cell |
| `fl-member-table-row` | `<tr>` | Data row |

### Pagination
| Class | Element | Purpose |
|---|---|---|
| `fl-pagination-wrap` | `<div>` | Outer wrapper around the pagination nav |
| `fl-pagination-nav` | `<ul>` | Pagination link list |
| `fl-page-current` | `<span>` | Current page indicator |
| `fl-page-gap` | `<span>` | Ellipsis gap |

### Category Navigation
| Class | Element | Purpose |
|---|---|---|
| `fl-category-nav` | `<div>` | Category selector wrapper |
| `fl-category-list` | `<ul>` | Bulleted category list |
| `fl-category-list-children` | `<ul>` | Nested subcategory list |
| `fl-category-count` | `<span>` | Count beside a category name |
| `fl-category-dropdown` | `<div>` | Dropdown category form |
| `fl-category-back` | `<a>` | "Back" link in category browse |
| `fl-cat-heading` | `<h2>` / `<p>` | Current category heading |

### Listings (Owned)
| Class | Element | Purpose |
|---|---|---|
| `fl-listing-list` | `<ul>` | Owned listing list |
| `fl-listing-item` | `<li>` | Single owned listing |
| `fl-listing-image-wrap` | `<div>` | Image container |
| `fl-listing-image` | `<img>` | Listing image |
| `fl-listing-body` | `<div>` | Text content area |
| `fl-listing-title` | `<span>` | Listing title |
| `fl-listing-subject` | `<span>` | Listing subject |
| `fl-listing-meta` | `<div>` | Metadata line |
| `fl-listing-network` | `<span>` | TFL/TAFL network badge |
| `fl-listing-category` | `<span>` | Category label |
| `fl-listing-status` | `<span>` | Status label |
| `fl-listing-date-opened` | `<span>` | Opening date |
| `fl-listing-date-updated` | `<span>` | Last-updated date |
| `fl-listing-member-count` | `<span>` | Member count |
| `fl-listing-country-count` | `<span>` | Country count |
| `fl-listing-section` | `<div>` | Status group section |
| `fl-listing-section-heading` | `<h2>` | Section heading |
| `fl-listing-section-count` | `<span>` | Count shown in section heading |

### Listings (Joined)
| Class | Element | Purpose |
|---|---|---|
| `fl-joined-list` | `<ul>` / `<div>` | Joined listing container (`flex-wrap` — items flow inline) |
| `fl-joined-item` | `<li>` | Single joined listing (fallback template only) |
| `fl-joined-image-wrap` | `<div>` | Image container |
| `fl-joined-image` | `<img>` | Joined listing image |
| `fl-joined-body` | `<div>` | Text content area |
| `fl-joined-title` | `<a>` / `<span>` | Listing title/subject |
| `fl-joined-category` | `<span>` | Listing category |
| `fl-joined-date` | `<span>` | Date joined |
| `fl-joined-status` | `<span>` | Listing status |
| `fl-joined-section` | `<div>` | Category-grouped section |
| `fl-joined-section-heading` | `<h2>` | Section heading |

### Random / Latest Owned Listing
| Class | Element | Purpose |
|---|---|---|
| `fl-random-listing-item` | `<div>` | Random listing wrapper |
| `fl-random-listing-image-wrap` | `<div>` | Image container |
| `fl-random-listing-image` | `<img>` | Listing image |
| `fl-random-listing-body` | `<div>` | Text content area |
| `fl-random-listing-title` | `<a>` / `<span>` | Listing title |
| `fl-random-listing-subject` | `<span>` | Listing subject |
| `fl-latest-listing-item` | `<div>` | Latest listing wrapper |
| `fl-latest-listing-image-wrap` | `<div>` | Image container |
| `fl-latest-listing-image` | `<img>` | Listing image |
| `fl-latest-listing-body` | `<div>` | Text content area |
| `fl-latest-listing-title` | `<a>` / `<span>` | Listing title |
| `fl-latest-listing-subject` | `<span>` | Listing subject |
| `fl-latest-listing-date` | `<span>` | Opening date |

### Random / Latest Joined Listing
| Class | Element | Purpose |
|---|---|---|
| `fl-random-joined-listing-item` | `<div>` | Random joined listing wrapper |
| `fl-random-joined-listing-image-wrap` | `<div>` | Image container |
| `fl-random-joined-listing-image` | `<img>` | Listing image |
| `fl-random-joined-listing-body` | `<div>` | Text content area |
| `fl-random-joined-listing-title` | `<a>` / `<span>` | Listing title/subject |
| `fl-random-joined-listing-category` | `<span>` | Listing category |
| `fl-latest-joined-listing-item` | `<div>` | Latest joined listing wrapper |
| `fl-latest-joined-listing-image-wrap` | `<div>` | Image container |
| `fl-latest-joined-listing-image` | `<img>` | Listing image |
| `fl-latest-joined-listing-body` | `<div>` | Text content area |
| `fl-latest-joined-listing-title` | `<a>` / `<span>` | Listing title/subject |
| `fl-latest-joined-listing-category` | `<span>` | Listing category |
| `fl-latest-joined-listing-date` | `<span>` | Date joined |

### Codes
| Class | Element | Purpose |
|---|---|---|
| `fl-codes` | `<div>` | Codes outer wrapper |
| `fl-code-group` | `<div>` | Size group |
| `fl-code-group-label` | `<p>` | Size group heading |
| `fl-code-list` | `<ul>` | Code image list |
| `fl-code-item` | `<li>` / `<figure>` | Single code |
| `fl-code-image` | `<img>` | Code image |

### Affiliates
| Class | Element | Purpose |
|---|---|---|
| `fl-affiliates-section` | `<div>` | Full affiliates block |
| `fl-affiliate-list` | `<ul>` | Affiliate list |
| `fl-affiliate-item` | `<li>` | Single affiliate |
| `fl-affiliate-image` | `<img>` | Affiliate button image |
| `fl-affiliate-name` | `<span>` | Text fallback name |
| `fl-affiliates-empty` | `<p>` | "No affiliates" message |
| `fl-affiliate-apply-collapsible` | `<details>` | Collapsible affiliate application wrapper |
| `fl-affiliate-apply-toggle` | `<summary>` | Clickable toggle |
| `fl-affiliate-apply-toggle--link` | `<summary>` | Toggle styled as text link |
| `fl-affiliate-apply-toggle--button` | `<summary>` | Toggle styled as button |
| `fl-affiliate-apply-form-wrap` | `<div>` | Inner wrapper around the application form |
| `fl-codes-submit-collapsible` | `<details>` | Collapsible code-submission wrapper (when appended to codes embed) |
| `fl-codes-submit-toggle` | `<summary>` | Clickable toggle |
| `fl-codes-submit-toggle--link` | `<summary>` | Toggle styled as text link |
| `fl-codes-submit-toggle--button` | `<summary>` | Toggle styled as button |
| `fl-codes-submit-form-wrap` | `<div>` | Inner wrapper around the code-submission form |

### News Feed (Updates)
| Class | Element | Purpose |
|---|---|---|
| `fl-updates` | `<div>` | Updates outer wrapper (per-listing and collective) |
| `fl-update-item` | `<div>` | Single update entry |
| `fl-update-header` | `<div>` | Header row (title + date) |
| `fl-update-title` | `<span>` | Update title |
| `fl-update-date` | `<span>` | Update date |
| `fl-update-author` | `<span>` | Update author |
| `fl-update-meta` | `<div>` | Meta line (author + category row) |
| `fl-update-body` | `<div>` | Update body text |
| `fl-update-categories` | `<span>` | Category tag group |
| `fl-update-category-tag` | `<span>` | Individual category tag |

### Stats
| Class | Element | Purpose |
|---|---|---|
| `fl-stats` | `<div>` | Per-listing stats wrapper |
| `fl-stats-grid` | `<dl>` | Stats key-value grid |
| `fl-stats-label` | `<dt>` | Stat label |
| `fl-stats-value` | `<dd>` | Stat value |
| `fl-stat-newmembers` | `<dd>` | Modifier on the new-members stat value |
| `fl-stat-approved-by` | `<dd>` | Modifier on the approved-by stat value |
| `fl-cstats` | `<div>` | Collective stats wrapper |
| `fl-cstats-grid` | `<dl>` | Collective stats key-value grid |
| `fl-cstats-label` | `<dt>` | Collective stat label |
| `fl-cstats-value` | `<dd>` | Collective stat value |
| `fl-cstats-newest` | `<div>` | Newest listing block |
| `fl-cstats-random` | `<div>` | Random listing block |
| `fl-cstats-categories` | `<div>` | Categories summary block |

### Rules Gate (Join Rules add-on)
| Class | Element | Purpose |
|---|---|---|
| `fl-rules-gate` | `<div>` | Outer gate wrapper |
| `fl-rules-agreed` | `<div>` | Shown when the visitor has already accepted the rules this session |
| `fl-rules-intro` | `<p>` | Introductory text above the rules |
| `fl-rules-title` | `<h2>` | Rules title heading |
| `fl-rules-body` | `<div>` | Rendered rules content (HTML from the admin editor) |
| `fl-rules-list` | `<ol>` | Numbered rules list rendered inside the gate form |
| `fl-rules-gate-checkbox` | `<div>` | Wrapper row for the agree checkbox |
| `fl-rules-agree-field` | `<div>` | Inner agree field wrapper |
| `fl-rules-agree-label` | `<label>` | Label text for the agree checkbox |
| `fl-rules-agree-check` | `<input>` | The agree checkbox |

### KIM List (KIM List add-on)
| Class | Element | Purpose |
|---|---|---|
| `fl-kim` | `<div>` | KIM outer wrapper |
| `fl-kim-heading` | `<h2>` | KIM heading (overview or subject page) |
| `fl-kim-intro` | `<p>` | Introductory text |
| `fl-kim-list` | `<ul>` | List of KIM subjects (overview page) |
| `fl-kim-item` | `<li>` | Single KIM subject entry |
| `fl-kim-count` | `<span>` | Number of interested members |
| `fl-kim-count--empty` | modifier | Applied to `fl-kim-count` when there are zero members yet |
| `fl-kim-meta` | `<p>` | Meta line at the bottom of the KIM overview |
| `fl-kim-subject` | `<div>` | Subject-detail page wrapper |
| `fl-kim-subject-heading` | `<h2>` | Subject page heading |
| `fl-kim-member-list` | `<ul>` | Interested-member list on a subject page |
| `fl-kim-member-item` | `<li>` | Single interested member |
| `fl-kim-member-name` | `<span>` | Member name |
| `fl-kim-table-wrap` | `<div>` | Wrapper for the table-layout member list |
| `fl-kim-table` | `<table>` | Table-layout member list (admin-selectable) |
| `fl-kim-entry-pending` | `<li>` | Pending KIM entry (not yet approved) |

### Wishlist (Wishlist add-on)
| Class | Element | Purpose |
|---|---|---|
| `fl-wishlist-group` | `<div>` | Type group wrapper (Top, Wanted, Granted) |
| `fl-wishlist-group-label` | `<h3>` | Type group heading |
| `fl-wishlist-list` | `<ul>` | Wishlist item list |
| `fl-wishlist-item` | `<li>` | Single wishlist item |
| `fl-wishlist-item--top` | modifier | Applied when priority is "top wanted" |
| `fl-wishlist-item--wanted` | modifier | Applied when priority is "wanted" |
| `fl-wishlist-item--granted` | modifier | Applied when the wish has been granted |
| `fl-wishlist-image` | `<img>` | Item cover image |
| `fl-wishlist-info` | `<div>` | Text content area |
| `fl-wishlist-subject` | `<a>` / `<span>` | Item title (linked if URL is set) |
| `fl-wishlist-category` | `<span>` | Item category |
| `fl-wishlist-description` | `<p>` | Item description |
| `fl-wishlist-type-badge` | `<span>` | Type label badge (fanlisting, fansite, etc.) |

### Collective Update Form
| Class | Element | Purpose |
|---|---|---|
| `fl-collective-update-form` | `<form>` | Root form on the Step 2 update form. |
| `fl-collective-update-listings` | `<div>` | Container for the listing-selection block. |
| `fl-collective-update-list` | `<ul>` | List of fanlistings the member can apply changes to. |
| `fl-collective-update-item` | `<li>` | Single listing row. |
| `fl-collective-update-url` | `<span>` | Inline URL shown next to each listing title. |
