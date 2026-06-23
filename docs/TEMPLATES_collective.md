### DEFAULT TEMPLATES

## Collective Template Defaults

# Owned Listing
```
<div class="fl-listing-list">
{each_listing}

<div class="fl-listing-item">
{if_listing_image}<div class="fl-listing-image-wrap"><img class="fl-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}

<div class="fl-listing-body">
<a href="{listing_url}" target="_blank" title="{listing_subject}">{listing_subject}</a>

<span class="fl-listing-subject">{listing_title}</span>
<div class="fl-listing-meta">
{if_approved_by}<b>Approved by:</b> {if_tfl}The Fanlistings Network{/if_tfl}{if_tafl}The Anime Fanlistings Network{/if_tafl}<br>{/if_approved_by}
<b>Category:</b> {listing_category}<br>
<b>Members:</b> {member_count} member(s) & {if_pending}{member_pending_count} pending{/if_pending}<br>
<b>Countries:</b> {if_countries}{country_count} countries{/if_countries}<br>
<b>Opened:</b> {date_opened}<br>
{if_date_updated}<b>Updated:</b> {date_updated}{/if_date_updated}

</div>
</div>
</div>
{/each_listing}
</div>
```

# Random Owned:
```
<div class="fl-random-listing">
{each_listing}

<div class="fl-random-listing-item">
{if_listing_image}<div class="fl-random-listing-image-wrap"><img class="fl-random-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}

<div class="fl-random-listing-body">
<a href="{listing_url}" target="_blank" title="{listing_subject}">{listing_subject}</a>

<span class="fl-random-listing-subject">{listing_title}</span>
</div>

</div>
{/each_listing}
</div>
```

# Latest Owned:
```
<div class="fl-latest-listing">
{each_listing}

<div class="fl-latest-listing-item">
{if_listing_image}<div class="fl-latest-listing-image-wrap"><img class="fl-latest-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}

<div class="fl-latest-listing-body">
<a href="{listing_url}" target="_blank" title="{listing_subject}">{listing_subject}</a>

<span class="fl-latest-listing-subject">{listing_title}</span>
</div>

</div>
{/each_listing}
</div>
```

# Joined Listings:
```
<div class="fl-joined-list">
{each_joined}

{if_listing_image}
<a href="{listing_url}" target="_blank"><img src="{listing_image_url}" title="{listing_subject} - {listing_category}&#010;Joined on {date_joined}"></a>
{/if_listing_image}
{if_no_listing_image}
<a href="{listing_url}" target="_blank">{listing_subject}</a>
{/if_no_listing_image}

{/each_joined}
</div>
```

# Random Joined :
```
<div class="fl-random-joined-listing">
{each_joined}

<div class="fl-random-joined-listing-item">
{if_listing_image}<div class="fl-random-joined-listing-image-wrap"><img class="fl-random-joined-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}

<div class="fl-random-joined-listing-body">
<a href="{listing_url}" target="_blank" title="{listing_subject} - {listing_category}&#010;Joined on {date_joined}">{listing_subject}</a>

<span class="fl-random-joined-listing-category">{listing_category}</span>
</div>

</div>
{/each_joined}
</div>
```

# Latest Joined:
```
<div class="fl-latest-joined-listing">
{each_joined}

<div class="fl-latest-joined-listing-item">
{if_listing_image}<div class="fl-latest-joined-listing-image-wrap"><img class="fl-latest-joined-listing-image" src="{listing_image_url}" alt="{listing_subject}"></div>{/if_listing_image}

<div class="fl-latest-joined-listing-body">
<a href="{listing_url}" target="_blank" title="{listing_subject} - {listing_category}&#010;Joined on {date_joined}">{listing_subject}</a>

<span class="fl-latest-joined-listing-category">{listing_category}</span>
</div>

</div>
{/each_joined}
</div>
```

# Statistics:
```
<div class="fl-cstats">
<div class="fl-cstats-grid">
<b>Owned Listings:</b> {owned_active} open, {owned_upcoming} upcoming<br>
<b>Joined listings:</b> {joined_active}<br>
<b>Total members:</b> {members_approved} approved<br>
</div>
</div>
```

# Codes:
```
<h2>Codes</h2>
<div class="fl-embed fl-embed--codes">
{if_no_codes}<p>No codes available yet.</p>{/if_no_codes}
{if_codes}

<div class="fl-codes">
{each_size_group}

<div class="fl-code-group">
<h2>{group_size}</h2>

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
</div>

{/if_codes}
</div>
```

# Codes Donation Form:
```
<h2>Donate Codes to {listing_subject}</h2>
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

# Collective Contact Form:
```
<h2>Contact Form</h2>
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

# Affiliates:
```
<h2>Affiliates</h2>
<div class="fl-affiliates-section">
{if_affiliates}
{each_affiliate}
{if_aff_image}<a href="{aff_url}" title="{aff_name}" target="_blank"><img src="{aff_image_url}" alt="{aff_name}"></a>{/if_aff_image}
{if_no_aff_image}<a href="{aff_url}" title="{aff_name}" target="_blank">{aff_name}</a>{/if_no_aff_image}

{/each_affiliate}{/if_affiliates}

{if_no_affiliates}
No affiliates yet.
{/if_no_affiliates}
{affiliate_apply_toggle}
</div>
```

# Collective Affiliate Application Form:
```
<h2>Affiliate Application</h2>
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

# Adoption Form:
```
<h2>Adoption Form</h2>
<div class="fl-form-fields">
{field_name}
{field_email}
{field_url}
{field_listing_id}
{field_message}
{captcha_block}
</div>

<div class="fl-form-actions">
{submit_button}
{clear_button}
</div>
```

# KIM Sign-Up Form:
```
<h2>KIM - Sign-Up</h2>
<div class="fl-form-fields">
{field_name}
{field_url}
{field_email}
{field_fanlisting_id}
{field_password}
{field_password_confirm}
</div>

<div class="fl-form-actions">
{submit_button}
{clear_button}
</div>
```

# KIM Update Form:
```
<h2>KIM - Sign In</h2>
<div class="fl-form-fields">
{field_email}
{field_password}
</div>

<div class="fl-form-actions">
{submit_button}
{clear_button}
</div>

{if_lost_password_url}<p class="fl-form-hint"><a href="{lost_password_url}">Forgot your password?</a></p>{/if_lost_password_url}
```

# KIM Reset Form:
```
<h2>KIM - Password Reset</h2>
{if_step_email}
<div class="fl-form-fields">
{field_email}
</div>
<div class="fl-form-actions">
{submit_button}
{clear_button}
</div>
{/if_step_email}

{if_step_confirm}
{if_token_invalid}<p class="fl-errors">This reset link has expired or is invalid. Please <a href="{reset_request_url}">request a new one</a>.</p>{/if_token_invalid}

{if_token_valid}
<div class="fl-form-fields">
{field_password}
{field_password_confirm}
</div>
<div class="fl-form-actions">
{submit_button}
{clear_button}
</div>
{/if_token_valid}
{/if_step_confirm}
```

# KIM List Overview:
```
<h2>Keep In Mind List</h2>
<div class="fl-embed fl-embed--kim-list">
{if_no_listings}<p>No fanlistings with sign-ups yet.</p>{/if_no_listings}

{if_has_listings}
<ul>

{each_kim_listing}
<li><a href="{listing_kim_url}">{listing_subject}</a> &mdash; {listing_kim_count} Interested</li>
{/each_kim_listing}

</ul>
{/if_has_listings}
</div>
```

# KIM List Subject Page:
```
<h2>KIM &mdash; {listing_subject}</h2>
<div class="fl-embed fl-embed--kim-subject">
{if_kim_overview_url}<p><a href="{kim_overview_url}">&larr; Back to KIM List</a></p>{/if_kim_overview_url}

{if_kim_signup_url}<p>Interested in adopting this fanlisting? <a href="{kim_signup_url}">Click here to sign-up.</a></p>{/if_kim_signup_url}

{if_no_kim_members}<p>No one has signed up yet.</p>{/if_no_kim_members}

{if_has_kim_members}
<ul>
{each_kim_member}

<li>
{if_member_url}<a href="{member_url}" target="_blank">{member_name}</a>{/if_member_url}{if_no_member_url}<span class="fl-kim-member-name">{member_name}</span>{/if_no_member_url}
</li>

{/each_kim_member}
</ul>
{/if_has_kim_members}
</div>
```

# Wishlist:
```
<h2>Wishlist</h2>
<div class="fl-embed fl-embed--wishlist">
{if_has_top}
<h3>Top Wanted</h3>
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
<h3>Wanted</h3>
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
<h3>Granted</h3>
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
</div>
```

# News Feed:
```
<h2>{listing_subject} Updates</h2>
<div class="fl-updates-list">
{if_no_updates}<p>No updates yet.</p>{/if_no_updates}
{if_updates}
{each_update}

<div class="fl-update-item">
<h3 class="fl-update-title">{update_title}</h3>
<div class="fl-update-meta">
<span class="fl-update-date"><b>Date Posted:</b> {update_date}</span>{if_author} by <span class="fl-update-author">{update_author}</span>{/if_author}<br>
{if_categories}<b>Categories:</b> <span class="fl-update-categories">{update_categories}</span>{/if_categories}</div>

<div class="fl-update-body">{update_body_html}</div>
</div>

{/each_update}
{/if_updates}
</div>
```



## Template & Dropdown Options Order
- All Templates (default)
- Owned Listings: contains
  - Owned Listings
  - Latest Owned
  - Random Owned
- Joined Listings: contains
  - Joined Listings
  - Latest Joined
  - Random Joined
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
- Keep In Mind: contains
  - KIM List Overview
  - KIM Listing View
  - KIM Sign-Up Form
  - KIM Update Form
  - KIM Reset Form
- Adoption Form
- Wishlist
- News Feed