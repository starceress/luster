### DEFAULT TEMPLATES

## Global Listing & Per-Listing Template Defaults

> Every element uses `fl-*` CSS classes from `public/assets/css/default/style.css`.
> See `docs/CSS-CLASS-MIGRATION.md` for the full class reference and mappings from
> Enthusiast / Listing Admin.

# Members:
```
<h2 class="fl-heading">Member List</h2>
<div class="fl-member-controls">
<p class="fl-member-sort">Sort by: {sort_link_all} | {sort_link_name} | {sort_link_country}</p>
<p class="fl-member-stats">{member_count} fans from {country_count} countries.</p>
</div>

{if_members}
<ul class="fl-member-list">
{each_member}
<li class="fl-member-item">
{if_fan_url}<a href="{fan_url}" class="fl-member-name">{fan_name}</a>{/if_fan_url}
{if_no_fan_url}<span class="fl-member-name">{fan_name}</span>{/if_no_fan_url}
{if_fan_country} <span class="fl-member-country">&mdash; {fan_country}</span>{/if_fan_country}
</li>
{/each_member}
</ul>
{/if_members}

{if_no_members}
<p class="fl-empty">No members yet. <a href="{join_url}">Want to join?</a></p>
{/if_no_members}

<div class="fl-pagination-wrap">{pagination}</div>
```

# Join Form:
```
<h2 class="fl-heading">Join {listing_subject}</h2>
<div class="fl-form-fields">
{field_name}
{field_email}
{field_url}
{field_country}
{additional_fields}
{field_password}
{field_password_confirm}
{captcha_block}
</div>
<div class="fl-form-actions">
{submit_button}
{clear_button}
</div>
```

# Update Form Tokens:
```
{if_not_signed_in}
<h2 class="fl-heading">Update Your Information for {listing_subject}</h2>
<p class="fl-hint">Enter your email address and password to continue.</p>

<div class="fl-form-fields">
{field_signin_email}
{field_signin_password}
</div>
<p class="fl-hint"><a href="{lost_password_url}" class="fl-link">Forgot your password?</a></p>
<div class="fl-form-actions">
{submit_button}
</div>
{/if_not_signed_in}

{if_signed_in}
<h2 class="fl-heading">Update Your Information for {listing_subject}</h2>
<p class="fl-hint">Signed in as <strong>{field_email}</strong>. <a href="{sign_out_url}" class="fl-link">Not you?</a></p>
<p class="fl-hint">Fill in only the fields you want to change. Leave a field blank to keep your current value.</p>
<div class="fl-form-fields">
{field_new_name}
{field_new_email}
{field_new_url}
{field_new_country}
{additional_fields}
{field_password}
{field_password_confirm}
{field_remove}
{captcha_block}
</div>
<div class="fl-form-actions">
{submit_button}
{clear_button}
</div>
{/if_signed_in}
```

# Lost Password Form:
```
{if_step_email}
<h2 class="fl-heading">Reset Password for {listing_subject}</h2>
<p class="fl-hint">Enter your email address and a link will be sent to reset your password. The link expires in 1 hour.</p>

<div class="fl-form-fields">
{field_email}
{captcha_block}
</div>

<div class="fl-form-actions">
{submit_button}
</div>
{/if_step_email}

{if_step_confirm}
<h2 class="fl-heading">Set New Password</h2>
{if_token_invalid}
<p class="fl-errors">This reset link has expired or is invalid. <a href="{reset_request_url}" class="fl-link">Request a new one.</a></p>
{/if_token_invalid}
{if_token_valid}
<div class="fl-form-fields">
{field_new_password}
{field_new_password_confirm}
</div>

<div class="fl-form-actions">
{submit_button}
</div>
{/if_token_valid}
{/if_step_confirm}
```

# Statistics:
```
<h2 class="fl-heading">{listing_subject} Statistics</h2>
<div class="fl-stats">
<div class="fl-stats-grid">
<span class="fl-stats-label">Members:</span><span class="fl-stats-value">{stat_approved} total{if_stat_pending} (+{stat_pending} pending){/if_stat_pending}</span>
<span class="fl-stats-label">Countries:</span><span class="fl-stats-value">{stat_countries}</span>
<span class="fl-stats-label">Newest:</span><span class="fl-stats-value">{stat_newmembers}</span>
<span class="fl-stats-label">Opened:</span><span class="fl-stats-value">{stat_opened}</span>
<span class="fl-stats-label">Updated:</span><span class="fl-stats-value">{stat_updated}</span>
<span class="fl-stats-label">Growth:</span><span class="fl-stats-value">{stat_growth}</span>
{if_approved_by}<span class="fl-stats-label">Approved by:</span><span class="fl-stats-value fl-stat-approved-by"><a href="{if_tfl}{tfl_url}{/if_tfl}{if_tafl}{tafl_url}{/if_tafl}">{network_name}</a></span>{/if_approved_by}
</div>
</div>
```

# Affiliates:
```
<h2 class="fl-heading">Affiliates</h2>
<div class="fl-affiliates-section">
{if_affiliates}
<p class="fl-hint"><a href="{affiliate_apply_url}">Apply to become an affiliate?</a></p>

<ul class="fl-affiliate-list">
{each_affiliate}
<li class="fl-affiliate-item">
{if_aff_image}<a href="{aff_url}" title="{aff_name}" target="_blank"><img src="{aff_image_url}" alt="{aff_name}" class="fl-affiliate-image"></a>{/if_aff_image}
{if_no_aff_image}<a href="{aff_url}" title="{aff_name}" target="_blank" class="fl-affiliate-name">{aff_name}</a>{/if_no_aff_image}
</li>
{/each_affiliate}
</ul>
{/if_affiliates}

{if_no_affiliates}
<p class="fl-affiliates-empty">No affiliates yet. <a href="{affiliate_apply_url}">Apply to become an affiliate?</a></p>
{/if_no_affiliates}
</div>
```

# Affiliate Application Form:
```
<h2 class="fl-heading">Affiliate Application for {listing_subject}</h2>
<p class="fl-hint">Fill out the form below to apply to become an affiliate.</p>
<div class="fl-form-fields">
{field_name}
{field_email}
{field_url}
{field_image}
{field_message}
{captcha_block}
</div>

<div class="fl-form-actions">
{submit_button}
{clear_button}
</div>
```

# Updates:
```
<h2 class="fl-heading">{listing_subject} Updates</h2>
<div class="fl-updates">
{if_no_updates}<p class="fl-empty">No updates yet.</p>{/if_no_updates}
{if_updates}
{each_update}

<div class="fl-update-item">
<div class="fl-update-header">
<span class="fl-update-title">{update_title}</span>
</div>
<div class="fl-update-meta">
<span class="fl-update-date">{update_date}</span>{if_author} by <span class="fl-update-author">{update_author}</span>{/if_author}
{if_categories} &mdash; <span class="fl-update-categories">{update_categories}</span>{/if_categories}
</div>
<div class="fl-update-body">{update_body_html}</div>
</div>

{/each_update}
{/if_updates}
</div>

<div class="fl-pagination-wrap">{pagination}</div>
```


# Codes:
```
<h2 class="fl-heading">Codes</h2>
<div class="fl-codes">
{if_no_codes}<p class="fl-empty">No codes available yet.</p>{/if_no_codes}
{if_codes}

{each_size_group}
<div class="fl-code-group">
<h3 class="fl-code-group-label">{group_size}</h3>

<ul class="fl-code-list">
{each_code}
<li class="fl-code-item">
<img src="{code_url}" alt="{code_size}" class="fl-code-image">
{if_donor}<span class="fl-code-caption">by {if_donor_url}<a href="{code_donor_url}" target="_blank">{code_donor_name}</a>{/if_donor_url}{if_no_donor_url}{code_donor_name}{/if_no_donor_url}</span>{/if_donor}
{if_code_name}<span class="fl-code-caption">{code_name}</span>{/if_code_name}
</li>
{/each_code}
</ul>
</div>
{/each_size_group}

{/if_codes}
</div>
```

# Code Donation Form:
```
<h2 class="fl-heading">Donate Codes to {listing_subject}</h2>
<p class="fl-hint">Submit your fan-made codes or buttons for this listing.</p>
<div class="fl-form-fields">
{field_submitter_name}
{field_submitter_email}
{field_submitter_url}
{field_code_image}
{captcha_block}
</div>
<div class="fl-form-actions">
{submit_button}
{clear_button}
</div>
```

# Contact Form:
```
<h2 class="fl-heading">Contact Form</h2>
<p class="fl-hint">Use the form below to get in touch.</p>
<div class="fl-form-fields">
{field_name}
{field_email}
{field_url}
{field_subject}
{field_message}
{captcha_block}
</div>
<div class="fl-form-actions">
{submit_button}
{clear_button}
</div>
```

# Contact Confirmation:
```
<div class="fl-success">
<p class="fl-success-body">Your message has been sent. Thank you for getting in touch, {sender_name}!</p>
</div>
```


## Template & Dropdown Options Order
- All Templates
- Members List
- Membership Forms: contains
    - Join Form
    - Update Membership Form
    - Lost Password Form
- Statistics
- Codes: contains
  - Codes Display
  - Codes Donation Form
- Affiliates: contains
  - Affiliates Display
  - Affiliate Application
- Contact Form: contains
  - Contact Form
  - Contact Confirmation
- Updates Feed
