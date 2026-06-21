# Luster Template Token Audit

**Scope:** All admin-editable template types — what tokens are actually replaced in
controller/embed code, what appears in the admin sidebar token picker, and
inconsistencies between the two.

**Processing engine key:**
- `TemplateEngine::render()` — handles loops, conditionals, and flat tokens
  simultaneously via `strtr()` (no cascading substitution)
- `FormTemplateRenderer::expandTokens()` — simple `strtr()` flat replacement only
  (no loops, no built-in conditionals)
- `TemplateEngine::conditionals()` — `preg_replace` over `{if_X}...{/if_X}` blocks;
  must be called explicitly before the loop or token step
- Direct `str_replace()` / `preg_replace()` — used by StatsController and updates
  controllers for pre-replacement before the engine runs

Column key: ✓ = present/supported, ✗ = absent, — = not applicable

---

## A. Per-Listing Templates

### A1. Stats Template (`stats_template`)

Processed by `StatsController::index()` — direct `preg_replace` for conditionals then
`str_replace()` for flat tokens.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{stat_opened}` | ✓ | ✓ | ✓ | |
| `{stat_updated}` | ✓ | ✓ | ✓ | More recent of newest member's join date and last_updated |
| `{stat_approved}` | ✓ | ✓ | ✓ | |
| `{stat_pending}` | ✓ | ✗ | ✓ | |
| `{stat_countries}` | ✓ | ✗ | ✓ | |
| `{stat_newmembers}` | ✓ | ✗ | ✓ | Up to 5 newest approved members, hyperlinked to website when available; ", and more..." when total > 5. Raw HTML |
| `{stat_growth}` | ✓ | ✗ | ✓ | Value includes unit: `"0.15 members/day"` — unit baked in, see C8 |
| `{listing_title}` | ✓ | ✗ | ✓ | |
| `{listing_subject}` | ✓ | ✗ | ✓ | |
| `{listing_url}` | ✓ | ✗ | ✓ | |
| `{approved_by}` | ✓ | ✗ | ✓ | Uppercase acronym or `""` |
| `{network}` | ✓ | ✗ | ✓ | **Alias** — identical output to `{approved_by}` |
| `{network_status}` | ✓ | ✗ | ✗ | **Deprecated alias** — identical output; not listed in sidebar |
| `{network_name}` | ✓ | ✗ | ✓ | Full name: "The Fanlistings Network" etc. |
| `{tfl_url}` | ✓ | ✗ | ✓ | Static: `https://thefanlistings.org/` |
| `{tafl_url}` | ✓ | ✗ | ✓ | Static: `https://www.animefanlistings.com/` |
| `{if_approved_by}...{/if_approved_by}` | ✓ | ✗ | ✓ | |
| `{if_tfl}...{/if_tfl}` | ✓ | ✗ | ✓ | |
| `{if_tafl}...{/if_tafl}` | ✓ | ✗ | ✓ | |
| `{if_stat_pending}...{/if_stat_pending}` | ✓ | ✗ | ✓ | |

---

### A2. Members List Template (`members_template`)

Processed by `MembersTemplateRenderer` via `TemplateEngine`. Two modes: **new-style**
(template contains `{each_member}`) and **legacy** (entire template is the per-row
fragment). Page-level tokens are silently ignored in legacy mode.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{each_member}...{/each_member}` | ✓ | ✓ | ✓ | New-style loop |
| `{fan_name}` | ✓ | ✓ | ✓ | |
| `{fan_url}` | ✓ | ✓ | ✓ | |
| `{fan_email}` | ✓ | ✗ | ✓ | Not in default; sidebar warns of privacy implications |
| `{fan_country}` | ✓ | ✓ | ✓ | |
| `{fan_FIELDNAME}` | ✓ | ✗ | ✓ | Dynamic — one token per admin-defined additional field |
| `{if_fan_url}...{/if_fan_url}` | ✓ | ✓ | ✓ | |
| `{if_no_fan_url}...{/if_no_fan_url}` | ✓ | ✓ | ✓ | |
| `{if_fan_country}...{/if_fan_country}` | ✓ | ✗ | ✓ | |
| `{if_no_fan_country}...{/if_no_fan_country}` | ✓ | ✗ | ✓ | |
| `{if_members}...{/if_members}` | ✓ | ✓ | ✓ | Page-level; new-style only |
| `{if_no_members}...{/if_no_members}` | ✓ | ✓ | ✓ | Page-level; new-style only |
| `{join_url}` | ✓ | ✓ | ✓ | Page-level; new-style only |
| `{member_count}` | ✓ | ✓ | ✓ | Page-level; new-style only |
~~| `{stats_line}` | ✓ | ✗ | ✓ | Page-level; pre-built HTML: "11 member(s) — page 1 of 2" |~~
| `{sort_link_all}` | ✓ | ✓ | ✓ | Page-level; raw `<a>` or `<strong>` HTML |
| `{sort_link_name}` | ✓ | ✓ | ✓ | Page-level; raw HTML |
| `{sort_link_country}` | ✓ | ✓ | ✓ | Page-level; raw HTML |

**Note:** `{stats_line}`, `{sort_link_*}` are raw (unescaped) tokens — pre-built HTML.

---

### A3. Affiliates Section Template (`affiliates_section_template`)

Processed by `AffiliatesController` (loop + conditionals), then `AffiliateRowRenderer`
for per-affiliate row tokens.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{each_affiliate}...{/each_affiliate}` | ✓ | ✓ | ✓ | |
| `{aff_name}` | ✓ | ✓ | ✓ | Inside loop |
| `{aff_url}` | ✓ | ✓ | ✓ | Inside loop |
| `{aff_image_url}` | ✓ | ✓ | ✓ | Inside loop; raw src URL, empty when none |
| `{if_aff_image}...{/if_aff_image}` | ✓ | ✓ | ✓ | Inside loop |
| `{if_no_aff_image}...{/if_no_aff_image}` | ✓ | ✓ | ✓ | Inside loop |
| `{affiliates_count}` | ✓ | ✓ | ✓ | Page-level |
| `{affiliate_apply_url}` | ✓ | ✓ | ✓ | Page-level |
| `{if_affiliates}...{/if_affiliates}` | ✓ | ✓ | ✓ | |
| `{if_no_affiliates}...{/if_no_affiliates}` | ✓ | ✓ | ✓ | |

No inconsistencies.

---

### A4. Updates Feed Template (`updates_template`)

Processed by `UpdatesPublicController::buildUpdatesHtml()` via `TemplateEngine`.
`{listing_title}` and `{listing_subject}` are pre-replaced via `str_replace()` on the
template shell *before* the engine runs — they can appear anywhere including outside loops.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{listing_title}` | ✓ | ✓ | ✓ | Per-listing only; pre-replaced before loop |
| `{listing_subject}` | ✓ | ✓ | ✓ | Per-listing only; pre-replaced before loop |
| `{listing_url}` | **✗** | ✗ | ✓ | **BUG — see C3.** Advertised but never replaced |
| `{if_updates}...{/if_updates}` | ✓ | ✓ | ✓ | |
| `{if_no_updates}...{/if_no_updates}` | ✓ | ✓ | ✓ | |
| `{each_update}...{/each_update}` | ✓ | ✓ | ✓ | |
| `{update_title}` | ✓ | ✓ | ✓ | |
| `{update_date}` | ✓ | ✓ | ✓ | Formatted: "June 1, 2025" |
| `{update_date_raw}` | ✓ | ✗ | ✓ | YYYY-MM-DD |
| `{update_author}` | ✓ | ✓ | ✓ | Empty when not set |
| `{update_categories}` | ✓ | ✗ | ✓ | Comma-separated |
| `{update_body_html}` | ✓ (raw) | ✓ | ✓ | HTML-escaped + `nl2br()` applied |
| `{if_author}...{/if_author}` | ✓ | ✓ | ✓ | |
| `{if_categories}...{/if_categories}` | ✓ | ✗ | ✓ | |

**Bug:** `{listing_url}` is listed in the sidebar but is never included in the
`str_replace()` pre-replacement call. It would appear as the literal string in output.

---

### A5. Codes Display Template (`codes_template`)

Processed by `CodesTemplateRenderer`. Two modes: **grouped** (template contains
`{each_size_group}`) and **flat** (template uses bare `{each_code}` only).

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{each_size_group}...{/each_size_group}` | ✓ | ✓ | ✓ | Outer loop; grouped mode |
| `{group_size}` | ✓ | ✓ | ✓ | Inside size_group; e.g. "88×31px" |
| `{group_code_count}` | ✓ | ✓ | ✓ | Inside size_group |
| `{each_code}...{/each_code}` | ✓ | ✓ | ✓ | Inner loop (grouped) or sole loop (flat) |
| `{code_url}` | ✓ | ✓ | ✓ | |
| `{code_alt}` | ✓ | ✓ | ✓ | Donor name → size → listing name |
| `{code_width}` | ✓ | ✓ | ✓ | Empty when unknown |
| `{code_height}` | ✓ | ✓ | ✓ | Empty when unknown |
| `{code_size}` | ✓ | ✗ | ✓ | "WxH"; empty when unknown |
| `{code_name}` | ✓ | ✗ | ✓ | Empty when not set |
| `{code_categories}` | ✓ | ✗ | ✓ | Comma-separated category names |
| `{code_donor_name}` | ✓ | ✗ | ✓ | Empty when not donated |
| `{code_donor_url}` | ✓ | ✗ | ✓ | Empty when not set |
| `{if_size}...{/if_size}` | ✓ | ✓ | ✓ | |
| `{if_size_changed}...{/if_size_changed}` | ✓ | ✗ | ✓ | Flat mode only |
| `{if_donor}...{/if_donor}` | ✓ | ✗ | ✓ | |
| `{if_no_donor}...{/if_no_donor}` | ✓ | ✗ | ✓ | |
| `{if_donor_url}...{/if_donor_url}` | ✓ | ✗ | ✓ | |
| `{if_no_donor_url}...{/if_no_donor_url}` | ✓ | ✗ | ✓ | |
| `{if_categories}...{/if_categories}` | ✓ | ✗ | ✓ | |
| `{if_code_name}...{/if_code_name}` | ✓ | ✗ | ✓ | |
| `{if_codes}...{/if_codes}` | ✓ | ✗ | ✓ | Page-level |
| `{if_no_codes}...{/if_no_codes}` | ✓ | ✗ | ✓ | Page-level |

No inconsistencies.

**Grouped vs flat mode — when to use which:**

Grouped mode (`{each_size_group}` wrapping `{each_code}`) lets you wrap each size in its
own container element and gives you `{group_code_count}`:
```html
{each_size_group}
<section class="size-group">
  <h3>{group_size} ({group_code_count} codes)</h3>
  <div class="grid">
    {each_code}<a href="{code_url}"><img src="{code_url}" alt="{code_alt}"></a>{/each_code}
  </div>
</section>
{/each_size_group}
```

Flat mode (bare `{each_code}` with `{if_size_changed}`) is simpler but can only insert
headings between groups — you cannot wrap each group in its own `<div>`:
```html
{each_code}
{if_size_changed}<h3>{code_size}</h3>{/if_size_changed}
<a href="{code_url}"><img src="{code_url}" alt="{code_alt}"></a>
{/each_code}
```

Use grouped when you need per-group containers or `{group_code_count}`. Use flat for
simple lists with heading dividers.

---

### A6. Join Form Template (`join_form_template`)

Processed by `JoinController` via `FormTemplateRenderer::expandTokens()` (flat
`strtr()` only — no loops or TemplateEngine conditionals).

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{listing_title}` | ✓ | ✓ | ✓ | |
| `{listing_subject}` | ✓ | ✓ | ✓ | |
| `{listing_url}` | ✓ | ✗ | ✓ | |
| `{field_name}` | ✓ | ✓ | ✓ | Label + wrapper |
| `{field_name_input}` | ✓ | ✗ | ✓ | Bare input only |
| `{field_email}` | ✓ | ✓ | ✓ | |
| `{field_email_input}` | ✓ | ✗ | ✓ | |
| `{field_url}` | ✓ | ✓ | ✓ | |
| `{field_url_input}` | ✓ | ✗ | ✓ | |
| `{field_country}` | ✓ | ✓ | ✓ | Empty when country field disabled |
| `{field_country_input}` | ✓ | ✗ | ✓ | |
| `{additional_fields}` | ✓ | ✓ | ✓ | All extra fields with labels |
| `{additional_fields_input}` | ✓ | ✗ | ✓ | Bare inputs only |
| `{field_password}` | ✓ | ✓ | ✓ | |
| `{field_password_input}` | ✓ | ✗ | ✓ | |
| `{field_password_confirm}` | ✓ | ✓ | ✓ | |
| `{field_password_confirm_input}` | ✓ | ✗ | ✓ | |
| `{captcha_block}` | ✓ | ✓ | ✓ | |
| `{captcha_block_input}` | ✓ | ✗ | ✓ | |
| `{captcha_question}` | ✓ | ✓ | ✓ | |
| `{submit_button}` | ✓ | ✓ | ✓ | |
| `{clear_button}` | ✓ | ✗ | ✓ | |

No inconsistencies.

---

### A7. Update Form Template (`update_form_template`)

Processed by `UpdateController` via `TemplateEngine::conditionals()` then
`FormTemplateRenderer::expandTokens()`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{if_not_signed_in}...{/if_not_signed_in}` | ✓ | ✓ | ✓ | Step 1: sign-in |
| `{if_signed_in}...{/if_signed_in}` | ✓ | ✓ | ✓ | Step 2: update fields |
| `{listing_title}` | ✓ | ✓ | ✓ | |
| `{listing_subject}` | ✓ | ✓ | ✓ | |
| `{listing_url}` | ✓ | ✗ | ✓ | |
| `{field_signin_email}` | ✓ | ✓ | ✓ | Step 1 |
| `{field_signin_password}` | ✓ | ✓ | ✓ | Step 1 |
| `{lost_password_url}` | ✓ | ✓ | ✓ | Step 1 only; `""` on step 2 |
| `{field_new_name}` | ✓ | ✓ | ✓ | Step 2 |
| `{field_email}` | ✓ | ✓ | ✓ | Step 2; current email displayed as text, not editable |
| `{sign_out_url}` | ✓ | ✓ | ✓ | Step 2 |
| `{field_new_email}` | ✓ | ✓ | ✓ | Step 2 |
| `{field_new_url}` | ✓ | ✓ | ✓ | Step 2; composite — includes "remove URL" checkbox |
| `{field_new_country}` | ✓ | ✓ | ✓ | Step 2 |
| `{additional_fields}` | ✓ | ✓ | ✓ | Step 2 |
| `{field_password}` | ✓ | ✓ | ✓ | Step 2; new password |
| `{field_password_confirm}` | ✓ | ✓ | ✓ | Step 2 |
| `{captcha_block}` | ✓ | ✓ | ✓ | |
| `{captcha_block_input}` | ✓ | ✗ | ✓ | |
| `{captcha_question}` | ✓ | ✓ | ✓ | |
| `{field_remove}` | ✓ | ✓ | ✓ | "Delete my membership" checkbox |
| `{submit_button}` | ✓ | ✓ | ✓ | |
| `{clear_button}` | ✓ | ✓ | ✓ | |

**Note:** `{lost_password_url}` is step-dependent — `""` in step 2. The sidebar does not
explain this. `{field_email}` on the update step is the current email as **display text**,
not an editable input.

---

### A8. Reset Form Template (`reset_form_template`)

Processed by `ResetController` via `TemplateEngine::conditionals()` then
`FormTemplateRenderer::expandTokens()`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{if_step_email}...{/if_step_email}` | ✓ | ✓ | ✓ | Email-entry step |
| `{if_step_confirm}...{/if_step_confirm}` | ✓ | ✓ | ✓ | New-password step |
| `{if_token_valid}...{/if_token_valid}` | ✓ | ✓ | ✓ | Inside confirm step |
| `{if_token_invalid}...{/if_token_invalid}` | ✓ | ✓ | ✓ | Inside confirm step |
| `{listing_title}` | ✓ | ✓ | ✓ | |
| `{listing_subject}` | ✓ | ✓ | ✓ | |
| `{listing_url}` | ✓ | ✗ | ✓ | |
| `{field_email}` | ✓ | ✓ | ✓ | Email-entry step |
| `{captcha_block}` | ✓ | ✓ | ✓ | |
| `{captcha_block_input}` | ✓ | ✗ | ✓ | |
| `{captcha_question}` | ✓ | ✓ | ✓ | |
| `{field_new_password}` | ✓ | ✓ | ✓ | Confirm step |
| `{field_new_password_confirm}` | ✓ | ✓ | ✓ | Confirm step |
| `{reset_request_url}` | ✓ | ✓ | ✓ | For "request a new token" link |
| `{submit_button}` | ✓ | ✓ | ✓ | Label changes per step |
| `{clear_button}` | ✓ | ✓ | ✓ | |
| `{hidden_token}` | ✓ (always `""`) | ✗ | ✗ | Internal only — see C7 |

No inconsistencies.

---

### A9. Codes Submit Form Template (`codes_submit_form_template`)

Processed by `CodesSubmitController` via `FormTemplateRenderer::expandTokens()`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{listing_title}` | ✓ | ✓ | ✓ | |
| `{listing_subject}` | ✓ | ✓ | ✓ | |
| `{listing_url}` | ✓ | ✓ | ✓ | |
| `{field_code_image}` | ✓ | ✓ | ✓ | |
| `{field_code_image_input}` | ✓ | ✗ | ✓ | |
| `{field_submitter_name}` | ✓ | ✓ | ✓ | |
| `{field_submitter_name_input}` | ✓ | ✗ | ✓ | |
| `{field_submitter_email}` | ✓ | ✓ | ✓ | |
| `{field_submitter_email_input}` | ✓ | ✗ | ✓ | |
| `{field_submitter_url}` | ✓ | ✓ | ✓ | |
| `{field_submitter_url_input}` | ✓ | ✗ | ✓ | |
| `{captcha_block}` | ✓ | ✓ | ✓ | |
| `{captcha_block_input}` | ✓ | ✗ | ✓ | |
| `{captcha_question}` | ✓ | ✓ | ✓ | |
| `{submit_button}` | ✓ | ✓ | ✓ | |
| `{clear_button}` | ✓ | ✗ | ✓ | |

No inconsistencies.

---

### A10. Affiliate Apply Form Template (`affiliate_apply_form_template`)

Processed by `AffiliateApplyController` via `FormTemplateRenderer::expandTokens()`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{listing_title}` | ✓ | ✓ | ✓ | |
| `{listing_subject}` | ✓ | ✓ | ✓ | |
| `{listing_url}` | ✓ | ✓ | ✓ | |
| `{field_name}` | ✓ | ✓ | ✓ | Site name |
| `{field_name_input}` | ✓ | ✗ | ✓ | |
| `{field_url}` | ✓ | ✓ | ✓ | Site URL |
| `{field_url_input}` | ✓ | ✗ | ✓ | |
| `{field_email}` | ✓ | ✓ | ✓ | Optional |
| `{field_email_input}` | ✓ | ✗ | ✓ | |
| `{field_image}` | ✓ | ✓ | ✓ | Button/image upload |
| `{field_image_input}` | ✓ | ✗ | ✓ | |
| `{field_message}` | ✓ | ✓ | ✓ | Optional |
| `{field_message_input}` | ✓ | ✗ | ✓ | |
| `{captcha_block}` | ✓ | ✓ | ✓ | |
| `{captcha_block_input}` | ✓ | ✗ | ✓ | |
| `{captcha_question}` | ✓ | ✓ | ✓ | |
| `{submit_button}` | ✓ | ✓ | ✓ | |
| `{clear_button}` | ✓ | ✗ | ✓ | |

No inconsistencies.

---

### A11. Contact Form Template (`contact_form_template`)

Processed by `ContactController` via `FormTemplateRenderer::expandTokens()`. In
collective mode `{listing_title}` = site name, `{listing_url}` = app URL.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{listing_title}` | ✓ | ✓ | ✓ | |
| `{listing_subject}` | ✓ | ✓ | ✗ | **GAP** — see C6; replaced but not listed |
| `{listing_url}` | ✓ | ✓ | ✓ | |
| `{field_name}` | ✓ | ✓ | ✓ | |
| `{field_name_input}` | ✓ | ✗ | ✓ | |
| `{field_email}` | ✓ | ✓ | ✓ | |
| `{field_email_input}` | ✓ | ✗ | ✓ | |
| `{field_url}` | ✓ | ✓ | ✓ | |
| `{field_url_input}` | ✓ | ✗ | ✓ | |
| `{field_subject}` | ✓ | ✓ | ✓ | Subject dropdown |
| `{field_subject_input}` | ✓ | ✗ | ✓ | |
| `{field_message}` | ✓ | ✓ | ✓ | |
| `{field_message_input}` | ✓ | ✗ | ✓ | |
| `{captcha_block}` | ✓ | ✓ | ✓ | |
| `{captcha_block_input}` | ✓ | ✗ | ✓ | |
| `{captcha_question}` | ✓ | ✓ | ✓ | |
| `{submit_button}` | ✓ | ✓ | ✓ | |
| `{clear_button}` | ✓ | ✗ | ✓ | |

---

### A12. Contact Sent Template (`contact_sent_template`)

Processed by `SuccessRenderer::render()` with action `'contact-sent'`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{sender_name}` | ✓ | ✓ | ✓ | Falls back to `"there"` on honeypot path |
| `{sender_email}` | ✓ | ✗ | ✓ | Not populated on honeypot path |
| `{listing_title}` | ✓ | ✗ | ✓ | |
| `{listing_url}` | ✓ | ✗ | ✓ | |
| `{site_name}` | ✓ | ✗ | ✓ | |

---

## B. Collective / Global Templates

### B1. Owned Listing Row Template (`owned_template`)

Processed by `ListingRowRenderer::render()` via `TemplateEngine`. Used by `owned.php`,
`latest-listing.php`, `random-listing.php`, and the admin Collective Templates preview.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{each_listing}...{/each_listing}` | ✓ | ✓ | ✓ | Flat loop |
| `{listing_title}` | ✓ | ✓ | ✓ | |
| `{listing_url}` | ✓ | ✓ | ✓ | |
| `{listing_subject}` | ✓ | ✓ | ✓ | |
| `{listing_status}` | ✓ | ✓ | ✓ | `"current"` / `"upcoming"` / `"pending"` |
| `{listing_email}` | ✓ | ✗ | ✓ | |
| `{listing_type}` | ✓ | ✗ | ✓ | |
| `{listing_category}` | ✓ | ✓ | ✓ | Comma-separated |
| `{listing_image_url}` | ✓ | ✓ | ✓ | Raw src URL; empty when none |
| `{listing_image_html}` | ✓ (raw) | ✓ | ✓ | Ready-made `<img>` tag or `""` |
| `{date_opened}` | ✓ | ✓ | ✓ | |
| `{date_updated}` | ✓ | ✗ | ✓ | Admin-set date |
| `{member_count}` | ✓ | ✓ | ✓ | On-demand DB query when token present |
| `{member_pending_count}` | ✓ | ✗ | ✓ | Pending members awaiting approval |
| `{country_count}` | ✓ | ✗ | ✓ | On-demand DB query when token present |
| `{listing_description}` | ✓ | ✗ | ✓ | |
| `{approved_by}` | ✓ | ✗ | ✓ | Uppercase acronym or `""` |
| `{network}` | ✓ | ✗ | ✓ | **Alias** — identical to `{approved_by}` |
| `{network_status}` | ✓ | ✗ | ✓ | **Deprecated alias** — identical output |
| `{network_name}` | ✓ | ✗ | ✓ | Full network name |
| `{tfl_url}` | ✓ | ✗ | ✓ | Static |
| `{tafl_url}` | ✓ | ✗ | ✓ | Static |
| `{if_listing_image}...{/if_listing_image}` | ✓ | ✓ | ✓ | |
| `{if_countries}...{/if_countries}` | ✓ | ✗ | ✓ | |
| `{if_description}...{/if_description}` | ✓ | ✗ | ✓ | |
| `{if_pending}...{/if_pending}` | ✓ | ✗ | ✓ | True when pending member count > 0 |
| `{if_status_current}...{/if_status_current}` | ✓ | ✗ | ✓ | |
| `{if_status_upcoming}...{/if_status_upcoming}` | ✓ | ✗ | ✓ | |
| `{if_status_pending}...{/if_status_pending}` | ✓ | ✗ | ✓ | Listing status, not members |
| `{if_date_updated}...{/if_date_updated}` | ✓ | ✗ | ✓ | |
| `{if_tfl}...{/if_tfl}` | ✓ | ✗ | ✓ | |
| `{if_tafl}...{/if_tafl}` | ✓ | ✗ | ✓ | |
| `{if_approved_by}...{/if_approved_by}` | ✓ | ✗ | ✓ | |

**Owned Sections template** (active when `$luster_status='all'`):

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{current_content}` | ✓ | ✓ | ✓ | Pre-rendered rows |
| `{upcoming_content}` | ✓ | ✓ | ✓ | |
| `{pending_content}` | ✓ | ✓ | ✓ | |
| `{current_count}` | ✓ | ✓ | ✓ | |
| `{upcoming_count}` | ✓ | ✓ | ✓ | |
| `{pending_count}` | ✓ | ✓ | ✓ | Listing-status count (NOT pending members) |
| `{total_count}` | ✓ | ✓ | ✓ | |
| `{if_current_listings}...{/if_current_listings}` | ✓ | ✓ | ✓ | |
| `{if_upcoming_listings}...{/if_upcoming_listings}` | ✓ | ✓ | ✓ | |
| `{if_pending_listings}...{/if_pending_listings}` | ✓ | ✓ | ✓ | |

**Note:** In the row template, `{member_pending_count}` = pending members awaiting
approval, while in the sections template, `{pending_count}` = listings with
"pending" status. These are different things with similar names.

---

### B2. Latest Owned / B3. Random Owned

Same row token set as B1 flat-loop mode. The grouped-loop variants and sections tokens
do not apply. Sidebars correctly reflect this subset.

---

### B4. Joined Listing Row Template (`joined_template`)

Processed by `JoinedListingRowRenderer::renderTemplate()` via `TemplateEngine`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{each_joined}...{/each_joined}` | ✓ | ✓ | ✓ | Flat loop |
| `{listing_subject}` | ✓ | ✓ | ✓ | What the fanlisting is about |
| `{listing_url}` | ✓ | ✓ | ✓ | |
| `{listing_category}` | ✓ | ✓ | ✓ | |
| `{listing_image_url}` | ✓ | ✓ | ✓ | |
| `{listing_image_html}` | ✓ (raw) | ✓ | ✓ | Ready-made `<img>` or `""` |
| `{listing_status}` | ✓ | ✗ | ✓ | |
| `{date_joined}` | ✓ | ✓ | ✓ | |
| `{if_listing_image}...{/if_listing_image}` | ✓ | ✓ | ✓ | |
| `{if_no_listing_image}...{/if_no_listing_image}` | ✓ | ✗ | ✗ | **GAP** — see C5 |

**Joined Sections template** (active when `$luster_category='all'`):

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{current_content}` | ✓ | ✓ | ✓ | |
| `{current_count}` | ✓ | ✓ | ✓ | |
| `{total_count}` | ✓ | ✓ | ✓ | |
| `{if_current_listings}...{/if_current_listings}` | ✓ | ✓ | ✓ | |

**Note:** Joined listings have `{listing_subject}` but no `{listing_title}` — the
`JoinedListing` model stores the subject/topic, not a separate display name.
This differs from owned listings, which have both.

---

### B5. Latest Joined / B6. Random Joined

Same row token set as B4. `{if_no_listing_image}` undocumented but functional.

---

### B7. Collective Stats Template (`collective_stats_template`)

Processed by `public/embed/collective-stats.php` via direct `str_replace()` after
`preg_replace` for conditionals. No TemplateEngine used.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{owned_active}` | ✓ | ✓ | ✓ | |
| `{owned_upcoming}` | ✓ | ✗ | ✓ | |
| `{owned_pending}` | ✓ | ✗ | ✓ | |
| `{owned_tfl_count}` | ✓ | ✗ | ✓ | Active listings approved by TFL |
| `{owned_tafl_count}` | ✓ | ✗ | ✓ | |
| `{joined_active}` | ✓ | ✓ | ✓ | |
| `{joined_pending}` | ✓ | ✗ | ✓ | |
| `{joined_tfl_count}` | ✓ | ✗ | ✓ | Active joined listings in TFL categories |
| `{joined_tafl_count}` | ✓ | ✗ | ✓ | |
| `{members_total}` | ✓ | ✗ | ✓ | |
| `{members_approved}` | ✓ | ✓ | ✓ | |
| `{members_pending}` | ✓ | ✗ | ✓ | |
| `{countries_count}` | ✓ | ✗ | ✓ | Distinct countries across all approved members |
| `{collective_last_updated}` | ✓ | ✗ | ✓ | MAX(opened) across owned listings |
| `{owned_newest_title}` | ✓ | ✗ | ✓ | Listing title |
| `{owned_newest_subject}` | ✓ | ✗ | ✓ | Listing subject |
| `{owned_newest_url}` | ✓ | ✗ | ✓ | |
| `{owned_random_title}` | ✓ | ✗ | ✓ | Listing title |
| `{owned_random_subject}` | ✓ | ✗ | ✓ | Listing subject |
| `{owned_random_url}` | ✓ | ✗ | ✓ | |
| `{tfl_url}` | ✓ | ✗ | ✓ | Static |
| `{tafl_url}` | ✓ | ✗ | ✓ | Static |
| `{if_countries}...{/if_countries}` | ✓ | ✗ | ✓ | |
| `{if_pending}...{/if_pending}` | ✓ | ✗ | ✓ | True when `{members_pending}` > 0 |

No inconsistencies.

---

### B8. Collective Codes / B9. Collective Codes Submit Form

Collective codes shares the same token set as A5 (per-listing codes). The collective
codes submit form adds `{site_name}` in place of `{listing_title}` / `{listing_subject}`.
No inconsistencies beyond the intentional context difference.

---

### B10. Collective Affiliates Section Template

Same token set as A3. `{affiliate_apply_url}` points to the collective apply form.
No inconsistencies.

---

### B11. Collective Affiliate Apply Form Template

Same token set as A10. `{listing_title}` = collective name, `{listing_url}` = collective
URL (correctly documented in sidebar). No inconsistencies.

---

### B12. Collective Contact Form Template

Same token set as A11 (per-listing contact form). In collective mode, `{listing_title}`
= app name, `{listing_url}` = app URL. `{listing_subject}` is replaced (same value as
`{listing_title}` in collective) but not listed in the sidebar for either per-listing or
collective contact form. See C6.

---

### B13. Collective Contact Sent Template (`collective_contact_sent_template`)

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{sender_name}` | ✓ | ✓ | ✓ | Falls back to `"there"` on honeypot |
| `{sender_email}` | ✓ | ✗ | ✓ | Not populated on honeypot path |
| `{site_name}` | ✓ | ✗ | ✓ | |

**Note:** Per-listing contact-sent supports `{listing_title}` and `{listing_url}`; the
collective version does **not** — only `{site_name}`. The sidebars reflect this correctly.

---

### B14. KIM List Overview Template (`collective_kim_list_overview_template`)

Processed by `KimListController` via `TemplateEngine::conditionals()` then
`TemplateEngine::loop()`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{if_has_listings}...{/if_has_listings}` | ✓ | ✓ | ✓ | |
| `{if_no_listings}...{/if_no_listings}` | ✓ | ✓ | ✓ | |
| `{each_kim_listing}...{/each_kim_listing}` | ✓ | ✓ | ✓ | |
| `{listing_title}` | ✓ | ✓ | ✓ | Inside loop |
| `{listing_subject}` | ✓ | ✓ | ✓ | Inside loop |
| `{listing_url}` | ✓ | ✗ | ✓ | Inside loop |
| `{listing_kim_url}` | ✓ | ✓ | ✓ | URL to KIM subject view for this listing |
| `{listing_kim_count}` | ✓ | ✓ | ✓ | Count of approved KIM members |

No inconsistencies.

---

### B15. KIM List Subject Template (`collective_kim_list_subject_template`)

Processed by `KimListController` via `TemplateEngine::conditionals()`, `loop()`, then
`tokens()`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{listing_title}` | ✓ | ✓ | ✓ | |
| `{listing_subject}` | ✓ | ✓ | ✓ | |
| `{listing_url}` | ✓ | ✓ | ✓ | |
| `{if_has_kim_members}...{/if_has_kim_members}` | ✓ | ✓ | ✓ | |
| `{if_no_kim_members}...{/if_no_kim_members}` | ✓ | ✓ | ✓ | |
| `{each_kim_member}...{/each_kim_member}` | ✓ | ✓ | ✓ | |
| `{member_name}` | ✓ | ✓ | ✓ | Inside loop |
| `{member_url}` | ✓ | ✓ | ✓ | Inside loop |
| `{if_member_url}...{/if_member_url}` | ✓ | ✓ | ✓ | Inside loop |
| `{if_no_member_url}...{/if_no_member_url}` | ✓ | ✓ | ✓ | Inside loop |
| `{kim_overview_url}` | ✓ | ✓ | ✓ | |
| `{kim_signup_url}` | ✓ | ✓ | ✓ | |
| `{if_kim_overview_url}...{/if_kim_overview_url}` | ✓ | ✓ | ✓ | |
| `{if_kim_signup_url}...{/if_kim_signup_url}` | ✓ | ✓ | ✓ | |
| `{pagination}` | ✓ (raw) | ✓ | ✓ | Pre-built pagination HTML |

No inconsistencies.

---

### B16. KIM Signup Form Template (`kim_signup_form_template`)

Processed by `KimSignupController` via `FormTemplateRenderer::expandTokens()`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{site_name}` | ✓ | ✓ | ✓ | |
| `{field_name}` | ✓ | ✓ | ✓ | |
| `{field_name_input}` | ✓ | ✗ | ✓ | |
| `{field_url}` | ✓ | ✓ | ✓ | |
| `{field_url_input}` | ✓ | ✗ | ✓ | |
| `{field_email}` | ✓ | ✓ | ✓ | |
| `{field_email_input}` | ✓ | ✗ | ✓ | |
| `{field_fanlisting_id}` | ✓ | ✓ | ✓ | Listing selector |
| `{field_fanlisting_id_input}` | ✓ | ✗ | ✓ | |
| `{field_password}` | ✓ | ✓ | ✓ | |
| `{field_password_input}` | ✓ | ✗ | ✓ | |
| `{field_password_confirm}` | ✓ | ✓ | ✓ | |
| `{field_password_confirm_input}` | ✓ | ✗ | ✓ | |
| `{field_send_copy}` | ✓ | ✓ | ✓ | |
| `{field_send_copy_input}` | ✓ | ✗ | ✓ | |
| `{captcha_block}` | ✓ | ✓ | ✓ | |
| `{captcha_block_input}` | ✓ | ✗ | ✓ | |
| `{captcha_question}` | ✓ | ✗ | ✓ | |
| `{submit_button}` | ✓ | ✓ | ✓ | |
| `{clear_button}` | ✓ | ✗ | ✓ | |

No inconsistencies.

---

### B17. KIM Update Form Template (`kim_update_form_template`)

Processed by `KimUpdateController` via `TemplateEngine::conditionals()` then
`FormTemplateRenderer::expandTokens()`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{if_lost_password_url}...{/if_lost_password_url}` | ✓ | ✓ | ✓ | |
| `{lost_password_url}` | ✓ | ✓ | ✓ | |
| `{field_name}` | ✓ | ✓ | ✓ | |
| `{field_name_input}` | ✓ | ✗ | ✓ | |
| `{field_current_email}` | ✓ | ✓ | ✓ | |
| `{field_current_email_input}` | ✓ | ✗ | ✓ | |
| `{field_current_password}` | ✓ | ✓ | ✓ | |
| `{field_current_password_input}` | ✓ | ✗ | ✓ | |
| `{field_fanlisting_id}` | ✓ | ✓ | ✓ | |
| `{field_fanlisting_id_input}` | ✓ | ✗ | ✓ | |
| `{field_new_email}` | ✓ | ✓ | ✓ | |
| `{field_new_email_input}` | ✓ | ✗ | ✓ | |
| `{field_new_url}` | ✓ | ✓ | ✓ | |
| `{field_new_url_input}` | ✓ | ✗ | ✓ | |
| `{field_new_password}` | ✓ | ✓ | ✓ | |
| `{field_new_password_input}` | ✓ | ✗ | ✓ | |
| `{field_new_password_confirm}` | ✓ | ✓ | ✓ | |
| `{field_new_password_confirm_input}` | ✓ | ✗ | ✓ | |
| `{field_send_copy}` | ✓ | ✓ | ✓ | |
| `{field_send_copy_input}` | ✓ | ✗ | ✓ | |
| `{captcha_block}` | ✓ | ✓ | ✓ | |
| `{captcha_block_input}` | ✓ | ✗ | ✓ | |
| `{captcha_question}` | ✓ | ✗ | ✓ | |
| `{submit_button}` | ✓ | ✓ | ✓ | |
| `{clear_button}` | ✓ | ✗ | ✓ | |

No inconsistencies.

---

### B18. KIM Reset Form Template (`kim_reset_form_template`)

Same structure as A8 (fan reset form) but for KIM members. Adds `{field_fanlisting_id}`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{if_step_email}...{/if_step_email}` | ✓ | ✓ | ✓ | |
| `{if_step_confirm}...{/if_step_confirm}` | ✓ | ✓ | ✓ | |
| `{if_token_valid}...{/if_token_valid}` | ✓ | ✓ | ✓ | |
| `{if_token_invalid}...{/if_token_invalid}` | ✓ | ✓ | ✓ | |
| `{field_email}` | ✓ | ✓ | ✓ | |
| `{field_email_input}` | ✓ | ✗ | ✓ | |
| `{field_fanlisting_id}` | ✓ | ✓ | ✓ | KIM-specific; selects which listing to reset for |
| `{field_fanlisting_id_input}` | ✓ | ✗ | ✓ | |
| `{captcha_block}` | ✓ | ✓ | ✓ | |
| `{captcha_block_input}` | ✓ | ✗ | ✓ | |
| `{captcha_question}` | ✓ | ✗ | ✓ | |
| `{field_password}` | ✓ | ✓ | ✓ | Confirm step |
| `{field_password_input}` | ✓ | ✗ | ✓ | |
| `{field_password_confirm}` | ✓ | ✓ | ✓ | Confirm step |
| `{field_password_confirm_input}` | ✓ | ✗ | ✓ | |
| `{reset_request_url}` | ✓ | ✓ | ✓ | |
| `{submit_button}` | ✓ | ✓ | ✓ | |
| `{clear_button}` | ✓ | ✗ | ✓ | |
| `{hidden_token}` | ✓ (always `""`) | ✗ | ✗ | Internal only — see C7 |

No inconsistencies.

---

### B19. Collective Adoption Form Template (`collective_adoption_form_template`)

Processed by `AdoptPublicController` via `FormTemplateRenderer::expandTokens()`.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{site_name}` | ✓ | ✓ | ✓ | |
| `{field_listing_id}` | ✓ | ✓ | ✓ | Listing selector |
| `{field_listing_id_input}` | ✓ | ✗ | ✓ | |
| `{field_name}` | ✓ | ✓ | ✓ | |
| `{field_name_input}` | ✓ | ✗ | ✓ | |
| `{field_email}` | ✓ | ✓ | ✓ | |
| `{field_email_input}` | ✓ | ✗ | ✓ | |
| `{field_url}` | ✓ | ✓ | ✓ | |
| `{field_url_input}` | ✓ | ✗ | ✓ | |
| `{field_message}` | ✓ | ✓ | ✓ | |
| `{field_message_input}` | ✓ | ✗ | ✓ | |
| `{captcha_block}` | ✓ | ✓ | ✓ | |
| `{captcha_block_input}` | ✓ | ✗ | ✓ | |
| `{captcha_question}` | ✓ | ✗ | ✓ | |
| `{submit_button}` | ✓ | ✓ | ✓ | |
| `{clear_button}` | ✓ | ✗ | ✓ | |

No inconsistencies.

---

### B20. Wishlist Template (`wishlist_template`)

Processed via `TemplateEngine`. Three typed loops plus one combined loop.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{each_wishlist_item}...{/each_wishlist_item}` | ✓ | ✗ | ✓ | All items combined; listed first in sidebar but unused by default |
| `{each_wishlist_item_top}...{/each_wishlist_item_top}` | ✓ | ✓ | ✓ | |
| `{each_wishlist_item_wanted}...{/each_wishlist_item_wanted}` | ✓ | ✓ | ✓ | |
| `{each_wishlist_item_granted}...{/each_wishlist_item_granted}` | ✓ | ✓ | ✓ | |
| `{if_has_top}...{/if_has_top}` | ✓ | ✓ | ✓ | |
| `{if_has_wanted}...{/if_has_wanted}` | ✓ | ✓ | ✓ | |
| `{if_has_granted}...{/if_has_granted}` | ✓ | ✓ | ✓ | |
| `{item_subject}` | ✓ | ✓ | ✓ | Inside any loop |
| `{item_url}` | ✓ | ✓ | ✓ | |
| `{item_type}` | ✓ | ✗ | ✓ | Raw type key: `"top"` / `"wanted"` / `"granted"` |
| `{item_type_label}` | ✓ | ✗ | ✓ | Human-readable label |
| `{item_category}` | ✓ | ✓ | ✓ | |
| `{item_description}` | ✓ | ✓ | ✓ | |
| `{item_image_url}` | ✓ | ✓ | ✓ | |
| `{if_item_image}...{/if_item_image}` | ✓ | ✓ | ✓ | |
| `{if_item_url}...{/if_item_url}` | ✓ | ✓ | ✓ | |
| `{if_no_item_url}...{/if_no_item_url}` | ✓ | ✓ | ✓ | |
| `{if_item_category}...{/if_item_category}` | ✓ | ✓ | ✓ | |
| `{if_item_description}...{/if_item_description}` | ✓ | ✓ | ✓ | |
| `{if_type_top}...{/if_type_top}` | ✓ | ✓ | ✓ | Inside `{each_wishlist_item}` only |
| `{if_type_wanted}...{/if_type_wanted}` | ✓ | ✓ | ✓ | |
| `{if_type_granted}...{/if_type_granted}` | ✓ | ✓ | ✓ | |

No inconsistencies.

---

### B21. Collective Updates Feed Template (`collective_updates_template`)

Same token set as A4 (per-listing updates). Key difference: in collective mode
`{listing_title}` and `{listing_subject}` are never replaced (collective updates have
no single listing context). The collective sidebar correctly omits those two tokens.

| Token | Supported | In Default | In Sidebar | Notes |
|---|---|---|---|---|
| `{if_updates}...{/if_updates}` | ✓ | ✓ | ✓ | |
| `{if_no_updates}...{/if_no_updates}` | ✓ | ✓ | ✓ | |
| `{each_update}...{/each_update}` | ✓ | ✓ | ✓ | |
| `{update_title}` | ✓ | ✓ | ✓ | |
| `{update_date}` | ✓ | ✓ | ✓ | |
| `{update_date_raw}` | ✓ | ✗ | ✓ | |
| `{update_author}` | ✓ | ✓ | ✓ | |
| `{update_categories}` | ✓ | ✗ | ✓ | |
| `{update_body_html}` | ✓ (raw) | ✓ | ✓ | |
| `{if_author}...{/if_author}` | ✓ | ✓ | ✓ | |
| `{if_categories}...{/if_categories}` | ✓ | ✗ | ✓ | |

No inconsistencies.

---

## C. Cross-Template Consistency Issues

### C1. Triple alias: `{approved_by}` = `{network}` = `{network_status}`
**Where:** Stats template, Owned listing row template.

All three produce **identical output** — the uppercase network acronym (`"TFL"`,
`"TAFL"`, or `""`). `{network_status}` is deprecated; `{network}` is a legacy alias.
`{approved_by}` is the canonical token. Users should prefer `{approved_by}`.
`{network_status}` is not listed in the stats sidebar but is listed (as deprecated) in
the owned row sidebar.

**Recommendation:** Remove `{network}` and `{network_status}` from the controller token
maps (and sidebars) in a future breaking-change release. They add no value and create
confusion. For now, keep them and ensure all sidebars document the deprecation.

---

### C2. `{pending_count}` has two different meanings
**Where:** Owned listing row template.

In the **row template**, `{member_pending_count}` = pending members awaiting approval.
In the **sections template** (status='all'), `{pending_count}` = count of listings with
pending status.

These are different things. The sidebar acknowledges this ("For pending members, use
`{member_pending_count}`") but the naming overlap will confuse users.

**Recommendation:** No code change needed — the current naming is load-bearing for
backward compatibility. Sidebar documentation is sufficient.

---

### C3. `{listing_url}` in per-listing updates feed: advertised but never replaced
**Where:** `A4. Updates Feed Template` sidebar + controller.

**This is a real bug.** `UpdatesPublicController::feed()` pre-replaces only
`{listing_title}` and `{listing_subject}`. `{listing_url}` appears in the sidebar and
logically belongs in the heading, but the controller never replaces it. A user who writes
`<a href="{listing_url}">` in their updates template will see the literal string in output.

**Fix:** Add `'{listing_url}'` to the `str_replace()` call in
`UpdatesPublicController::feed()`. Also add it to the embed's pre-replacement block in
`public/embed/updates.php`.

---

### C4. `{listing_title}` / `{listing_subject}` unavailable in collective updates
**Where:** `B21. Collective Updates`.

Collective updates pass no listing context tokens. The collective sidebar correctly omits
these. This is intentional and consistent. No action needed.

---

### C5. `{if_no_listing_image}` supported but undocumented in joined listing templates
**Where:** `B4. Joined Listing Row`, Latest Joined, Random Joined sidebars.

`JoinedListingRowRenderer::render()` registers `no_listing_image` as a condition, so
`{if_no_listing_image}...{/if_no_listing_image}` is fully functional. It is not listed
in any joined listing sidebar. The owned listing row does NOT support this conditional
(only `{if_listing_image}`).

**Fix:** Add `{if_no_listing_image}` to the joined listing row sidebar token pickers.

---

### C6. `{listing_subject}` in contact form: replaced but not in sidebar
**Where:** `A11. Contact Form Template` — both per-listing and collective sidebars.

`ContactController` passes `{listing_subject}` to `FormTemplateRenderer::expandTokens()`
for both modes. Neither contact form sidebar lists it. In collective mode, its value is
the same as `{listing_title}` (the site name) so it has no practical use there, but for
per-listing contact forms it is a genuinely useful token for a heading like
"Contact the {listing_subject} fanlisting".

**Fix:** Add `{listing_subject}` to the per-listing contact form sidebar. Optionally also
to the collective sidebar with a note that it resolves to the same value as
`{listing_title}`.

---

### C7. `{hidden_token}` — internal token always set to `""`
**Where:** Fan reset form (A8), KIM reset form (B18).

Both reset controllers include `{hidden_token}` in their token maps, always set to `""`.
The hidden token `<input>` is appended directly as raw HTML after the template via
`wrapForm()`. This token is intentionally invisible to users and correctly absent from
all sidebars. No action needed.

---

### C8. `{stat_growth}` includes unit text — cannot be formatted separately
**Where:** A1. Stats template.

The replacement value is `"0.15 members/day"` — the ` members/day` suffix is baked in.
Users cannot display just the number or change the unit label.

**Recommendation:** Consider adding a `{stat_growth_rate}` token (numeric only, no unit)
for users who want to build their own formatting. Low priority.

---

### C9. `{network_status}` in `RAW_TOKEN_ALLOWLIST` has no effect
**Where:** `TemplateEngine::RAW_TOKEN_ALLOWLIST`.

The allowlist would prevent HTML-escaping of `{network_status}`. However, all callers
pass it as an **escaped** token (in the `escaped:` array of `TemplateEngine::render()`),
so the allowlist entry is never reached. The value is always HTML-escaped at the call
site before reaching the engine. No functional impact; just dead configuration.

---

### C10. Naming asymmetry: `{listing_title}` vs `{listing_subject}` in joined listings
**Where:** Owned listing row (B1) vs Joined listing row (B4).

Owned listings support **both** `{listing_title}` (display name) and `{listing_subject}`
(what it's about). Joined listings support only `{listing_subject}` — the `JoinedListing`
model's primary text field maps to subject (the fanlisting topic), and there is no
separate display title for joined listings.

This asymmetry is intentional. Users who move templates between owned and joined contexts
should be aware that `{listing_title}` will not resolve in joined templates.

---

## D. Bugs — Tokens Advertised but Not Replaced

| # | Token | Template | Severity | Fix |
|---|---|---|---|---|
| C3 | `{listing_url}` | Per-listing updates feed | Medium | Add to `str_replace()` in `UpdatesPublicController::feed()` and `embed/updates.php` |

## E. Gaps — Tokens Replaced but Not in Sidebar

| # | Token | Template | Severity | Fix |
|---|---|---|---|---|
| C5 | `{if_no_listing_image}` | Joined listing row (+ latest/random) | Low | Add to joined listing row sidebars |
| C6 | `{listing_subject}` | Contact form (per-listing, possibly collective) | Low | Add to contact form sidebar |

## F. Deprecated / Alias Tokens

| Token | Canonical | Status | Where |
|---|---|---|---|
| `{network_status}` | `{approved_by}` | Deprecated alias | Stats, Owned row |
| `{network}` | `{approved_by}` | Legacy alias (no deprecation notice in stats sidebar) | Stats, Owned row |

---

*End of audit.*
