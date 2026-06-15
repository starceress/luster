<?php declare(strict_types=1);
use Luster\Core\View;
use Luster\Repositories\ListingSettingsRepo;

$b  = rtrim(View::e($base_url ?? ''), '/');
$g  = $globals ?? [];
$d  = [
    'listing_default_members_template'              => ListingSettingsRepo::defaultMembersTemplate(),
    'listing_default_stats_template'                => ListingSettingsRepo::defaultStatsTemplate(),
    'listing_default_affiliates_section_template'   => ListingSettingsRepo::defaultAffiliatesSectionTemplate(),
    'listing_default_updates_template'              => ListingSettingsRepo::defaultUpdatesTemplate(),
    'listing_default_join_form_template'            => ListingSettingsRepo::defaultJoinFormTemplate(),
    'listing_default_codes_submit_form_template'    => ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
    'listing_default_affiliate_apply_form_template' => ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),
    'listing_default_adopt_form_template'           => ListingSettingsRepo::defaultAdoptFormTemplate(),
];

// Resolve display value: prefer stored global, otherwise fall through to built-in
$val = static fn(string $key): string => ($g[$key] ?? '') ?: $d[$key];

// Renders one clickable token row for the sidebar reference panel.
$tok = static fn(string $ta, string $token, string $label, string $desc): string =>
    '<tr class="tok-row" tabindex="0" role="button"'
    . ' title="'          . View::e($desc)  . '"'
    . ' aria-label="Insert ' . View::e($label) . '. ' . View::e($desc) . '"'
    . ' data-textarea="'  . View::e($ta)    . '"'
    . ' data-token="'     . View::e($token) . '">'
    . '<td class="token-cell" data-desc="' . View::e($desc) . '"><code>' . View::e($label) . '</code></td>'
    . '</tr>' . "\n";
?>

<div class="toolbar">
  <div class="actions-row">
    <a href="<?= $b ?>/admin/listings" class="link">&larr; Back to Listings</a>
  </div>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/listings/global-templates" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="composer-grid">

    <!-- ── Left column: template editors ─────────────────────────────────── -->
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('embed') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">Global Listing Template Defaults</div>
          <div class="pc-head-sub">Site-wide defaults used by all listings that have not set a custom template. Overridden per listing on the listing's Templates tab.</div>
        </div>
      </div>

      <div class="pc-section">

        <div class="pc-section-label">Members List</div>
        <p class="form-hint mb-sm">Default for all listings. Wrap your row design in <code>{each_member}&hellip;{/each_member}</code>.</p>
        <div class="form-group">
          <textarea name="listing_default_members_template" id="listing_default_members_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_members_template')) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_members_template">Reset to Built-in Default</button>
          &nbsp; <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="members" data-textarea="listing_default_members_template"><?= View::icon('eye-on') ?> 
        </div>

        <div class="pc-section-label">Statistics</div>
        <div class="form-group">
          <textarea name="listing_default_stats_template" id="listing_default_stats_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_stats_template')) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_stats_template">Reset to Built-in Default</button>
          &nbsp; <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="stats" data-textarea="listing_default_stats_template"><?= View::icon('eye-on') ?> Preview</button>
        </div>

        <div class="pc-section-label">Affiliates</div>
        <p class="form-hint mb-sm">Controls the full affiliates block. Use <code>{each_affiliate}&hellip;{/each_affiliate}</code> to design each row, or <code>{affiliates}</code> to drop in ready-made rows.</p>
        <div class="form-group">
          <textarea name="listing_default_affiliates_section_template" id="listing_default_affiliates_section_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_affiliates_section_template')) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_affiliates_section_template">Reset to Built-in Default</button>
          &nbsp; <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="affiliates_section" data-textarea="listing_default_affiliates_section_template"><?= View::icon('eye-on') ?> Preview</button>
        </div>

        <div class="pc-section-label">Updates Feed</div>
        <p class="form-hint mb-sm">Default for listings with the Updates Feed add-on. Wrap each post in <code>{each_update}&hellip;{/each_update}</code>.</p>
        <div class="form-group">
          <textarea name="listing_default_updates_template" id="listing_default_updates_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_updates_template')) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_updates_template">Reset to Built-in Default</button>
          &nbsp; <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="updates" data-textarea="listing_default_updates_template"><?= View::icon('eye-on') ?> Preview</button>
        </div>

        <div class="pc-section-label">Join Form</div>
        <p class="form-hint mb-sm">Default join form layout. Write any introductory text directly at the top of the template. Use the tokens below to arrange fields.</p>
        <div class="form-group">
          <textarea name="listing_default_join_form_template" id="listing_default_join_form_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_join_form_template')) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_join_form_template">Reset to Built-in Default</button>
          &nbsp; <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="join_form" data-textarea="listing_default_join_form_template"><?= View::icon('eye-on') ?> Preview</button>
        </div>

        <div class="pc-section-label">Code Donation Form</div>
        <p class="form-hint mb-sm">Default layout for the code donation form.</p>
        <div class="form-group">
          <textarea name="listing_default_codes_submit_form_template" id="listing_default_codes_submit_form_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_codes_submit_form_template')) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_codes_submit_form_template">Reset to Built-in Default</button>
          &nbsp; <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="codes_submit_form" data-textarea="listing_default_codes_submit_form_template"><?= View::icon('eye-on') ?> Preview</button>
        </div>

        <div class="pc-section-label">Affiliate Form</div>
        <p class="form-hint mb-sm">Default layout for affiliate application forms.</p>
        <div class="form-group">
          <textarea name="listing_default_affiliate_apply_form_template" id="listing_default_affiliate_apply_form_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_affiliate_apply_form_template')) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_affiliate_apply_form_template">Reset to Built-in Default</button>
          &nbsp; <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="affiliate_apply_form" data-textarea="listing_default_affiliate_apply_form_template"><?= View::icon('eye-on') ?> Preview</button>
        </div>

        <div class="pc-section-label">Adoption Form</div>
        <p class="form-hint mb-sm">Default layout for adoption application forms (requires Adoption add-on).</p>
        <div class="form-group">
          <textarea name="listing_default_adopt_form_template" id="listing_default_adopt_form_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_adopt_form_template')) ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_adopt_form_template">Reset to Built-in Default</button>
          &nbsp; <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="adopt_form" data-textarea="listing_default_adopt_form_template"><?= View::icon('eye-on') ?> Preview</button>
        </div>

      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Global Defaults</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>
    </div>

    <!-- ── Right column: token reference ─────────────────────────────────── -->
    <div class="composer-side composer-side--sticky">

      <div class="search-field-wrap token-search-wrap">
        <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
        <input type="search"
               id="global-token-search"
               data-token-search
               class="form"
               placeholder="Search embed tokens"
               autocomplete="off"
               aria-label="Search embed tokens">
        <button type="button"
                id="global-token-search-clear"
                data-token-search-clear
                class="token-search-clear"
                aria-label="Clear embed token search"
                title="Clear search"
                hidden><?= View::icon('x', ['aria-hidden' => 'true']) ?></button>
      </div>

      <p id="global-token-search-empty" data-token-search-empty class="form-hint token-search-empty" hidden>No tokens found.</p>

      <!-- Members List -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Members List Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Wrap your row design in {each_member}...{/each_member}. Use {if_no_members} outside the loop for an empty state.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_members_template', '{each_member}{/each_member}',   '{each_member}...{/each_member}',   'Repeats the HTML inside it once for each approved member shown.') ?>
            <?= $tok('listing_default_members_template', '{fan_name}',    '{fan_name}',    'The member\'s display name.') ?>
            <?= $tok('listing_default_members_template', '{fan_email}',   '{fan_email}',   'The member\'s email. Only use this if you want emails visible publicly.') ?>
            <?= $tok('listing_default_members_template', '{fan_url}',     '{fan_url}',     'The member\'s website URL.') ?>
            <?= $tok('listing_default_members_template', '{fan_country}', '{fan_country}', 'The member\'s country.') ?>
            <?= $tok('listing_default_members_template', '{fan_FIELDNAME}', '{fan_FIELDNAME}', 'Additional field value. Replace FIELDNAME with the field\'s internal name, e.g. {fan_fave_song}.') ?>
            <?= $tok('listing_default_members_template', '{if_fan_url}{/if_fan_url}',               '{if_fan_url}...{/if_fan_url}',               'Shows only when the member has a website URL.') ?>
            <?= $tok('listing_default_members_template', '{if_no_fan_url}{/if_no_fan_url}',         '{if_no_fan_url}...{/if_no_fan_url}',         'Shows only when the member has no website URL.') ?>
            <?= $tok('listing_default_members_template', '{if_fan_country}{/if_fan_country}',       '{if_fan_country}...{/if_fan_country}',       'Shows only when the member has a country saved.') ?>
            <?= $tok('listing_default_members_template', '{if_no_fan_country}{/if_no_fan_country}', '{if_no_fan_country}...{/if_no_fan_country}', 'Shows only when the member has no country saved.') ?>
            <?= $tok('listing_default_members_template', '{if_members}{/if_members}',       '{if_members}...{/if_members}',       'Shows when there is at least one member. Place outside {each_member}.') ?>
            <?= $tok('listing_default_members_template', '{if_no_members}{/if_no_members}', '{if_no_members}...{/if_no_members}', 'Shows when the list is empty (empty state). Place outside {each_member}.') ?>
            <?= $tok('listing_default_members_template', '{join_url}',     '{join_url}',     'URL of the join form. Useful inside {if_no_members} for a "Want to join?" link.') ?>
            <?= $tok('listing_default_members_template', '{member_count}', '{member_count}', 'Total approved member count. Place outside {each_member}.') ?>
            <?= $tok('listing_default_members_template', '{stats_line}',   '{stats_line}',   'The stats summary line, e.g. "11 member(s) — page 1 of 2".') ?>
            <?= $tok('listing_default_members_template', '{sort_link_all}',     '{sort_link_all}',     'The "View All" sort link, bold when active.') ?>
            <?= $tok('listing_default_members_template', '{sort_link_name}',    '{sort_link_name}',    'The "Name" sort link, bold when active.') ?>
            <?= $tok('listing_default_members_template', '{sort_link_country}', '{sort_link_country}', 'The "Country" sort link, bold when active.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Statistics -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Statistics Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>These tokens fill in the public stats block for a listing.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_stats_template', '{stat_opened}',       '{stat_opened}',       'The date the listing opened.') ?>
            <?= $tok('listing_default_stats_template', '{stat_updated}',      '{stat_updated}',      'The most recent activity date: whichever is more recent of the newest approved member\'s join date and the Last Updated date set for the listing. Shows "N/A" if neither is set.') ?>
            <?= $tok('listing_default_stats_template', '{stat_approved}',     '{stat_approved}',     'How many approved members this listing has.') ?>
            <?= $tok('listing_default_stats_template', '{stat_pending}',      '{stat_pending}',      'How many members are waiting for approval.') ?>
            <?= $tok('listing_default_stats_template', '{stat_countries}',    '{stat_countries}',    'Number of distinct countries represented among approved members.') ?>
            <?= $tok('listing_default_stats_template', '{stat_newmembers}',        '{stat_newmembers}',        'Names of the 5 newest approved members, formatted with Oxford comma.') ?>
            <?= $tok('listing_default_stats_template', '{if_stat_pending}{/if_stat_pending}', '{if_stat_pending}...{/if_stat_pending}', 'Shows only when at least one member is awaiting approval.') ?>
            <?= $tok('listing_default_stats_template', '{stat_growth}',            '{stat_growth}',            'Average new members per day since the listing opened.') ?>
            <?= $tok('listing_default_stats_template', '{listing_title}',     '{listing_title}',     'The listing\'s display name.') ?>
            <?= $tok('listing_default_stats_template', '{listing_url}',       '{listing_url}',       'The link to the listing.') ?>
            <?= $tok('listing_default_stats_template', '{approved_by}',       '{approved_by}',       'The network that approved this listing, as a linked name. Empty when none saved.') ?>
            <?= $tok('listing_default_stats_template', '{if_approved_by}{/if_approved_by}', '{if_approved_by}...{/if_approved_by}', 'Shows only when an approved-by network is saved.') ?>
            <?= $tok('listing_default_stats_template', '{network_status}',    '{network_status}',    'The approving network as a linked name, or "Independent" when none is saved. Always shows something.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Affiliates Section -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Affiliates Section Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {each_affiliate}...{/each_affiliate} to design each row. Use {affiliates} to drop in ready-made rows without a custom loop.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_affiliates_section_template', '{each_affiliate}{/each_affiliate}', '{each_affiliate}...{/each_affiliate}', 'Repeats the HTML inside it once for each affiliate.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{aff_name}',      '{aff_name}',      'The affiliate site name. Use inside {each_affiliate}.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{aff_url}',       '{aff_url}',       'The affiliate site link. Use inside {each_affiliate}.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{aff_image_url}', '{aff_image_url}', 'The affiliate image, or a text link to the affiliate when there is no image. Use inside {each_affiliate}.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{affiliates}',       '{affiliates}',       'Ready-made affiliate rows. Use this when you do not want to design each row yourself.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{affiliates_count}', '{affiliates_count}', 'How many active affiliates this listing has.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{affiliates_list}',  '{affiliates_list}',  'A comma-separated list of linked affiliate names.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{affiliate_apply_url}',                '{affiliate_apply_url}',                   'URL of the affiliate application form for this listing.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{if_affiliates}{/if_affiliates}',       '{if_affiliates}...{/if_affiliates}',       'Shows only when this listing has at least one affiliate.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{if_no_affiliates}{/if_no_affiliates}', '{if_no_affiliates}...{/if_no_affiliates}', 'Shows only when this listing has no affiliates.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Updates Feed -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Updates Feed Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Wrap each post in {each_update}...{/each_update}. Luster repeats it once per update entry. Requires the Updates Feed add-on.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_updates_template', '{each_update}{/each_update}', '{each_update}...{/each_update}', 'Repeats the HTML inside it once for each update post shown.') ?>
            <?= $tok('listing_default_updates_template', '{update_title}',      '{update_title}',      'The update post title.') ?>
            <?= $tok('listing_default_updates_template', '{update_date}',       '{update_date}',       'The posted date in human-readable format, e.g. June 1, 2025.') ?>
            <?= $tok('listing_default_updates_template', '{update_date_raw}',   '{update_date_raw}',   'The posted date in YYYY-MM-DD format.') ?>
            <?= $tok('listing_default_updates_template', '{update_author}',     '{update_author}',     'The author name. Empty when not set.') ?>
            <?= $tok('listing_default_updates_template', '{update_categories}', '{update_categories}', 'Category names, comma-separated. Empty when not set.') ?>
            <?= $tok('listing_default_updates_template', '{update_body_html}',  '{update_body_html}',  'The update body text, with line breaks converted to HTML.') ?>
            <?= $tok('listing_default_updates_template', '{if_author}{/if_author}',         '{if_author}...{/if_author}',         'Shows only when the update has an author set.') ?>
            <?= $tok('listing_default_updates_template', '{if_categories}{/if_categories}', '{if_categories}...{/if_categories}', 'Shows only when the update has categories set.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Join Form -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Join Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Arrange and label every field of the join form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_join_form_template', '{listing_title}', '{listing_title}', 'The listing\'s display name.') ?>
            <?= $tok('listing_default_join_form_template', '{listing_url}',   '{listing_url}',   'The link to the listing.') ?>
            <?= $tok('listing_default_join_form_template', '{field_name}',       '{field_name}',       'The Name field, with label and wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{field_name_input}', '{field_name_input}', 'Just the Name input, no label or wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{field_email}',       '{field_email}',       'The Email field, with label and wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{field_email_input}', '{field_email_input}', 'Just the Email input, no label or wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{field_url}',       '{field_url}',       'The Website URL field, with label and wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{field_url_input}', '{field_url_input}', 'Just the Website URL input, no label or wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{field_country}',       '{field_country}',       'The Country field, with label and wrapper. Empty when the country field is disabled for this listing.') ?>
            <?= $tok('listing_default_join_form_template', '{field_country_input}', '{field_country_input}', 'Just the Country select, no label or wrapper. Empty when the country field is disabled for this listing.') ?>
            <?= $tok('listing_default_join_form_template', '{additional_fields}',       '{additional_fields}',       'All admin-defined additional fields, with labels and wrappers, in order.') ?>
            <?= $tok('listing_default_join_form_template', '{additional_fields_input}', '{additional_fields_input}', 'All admin-defined additional fields as bare inputs only, in order.') ?>
            <?= $tok('listing_default_join_form_template', '{field_password}',       '{field_password}',       'The Password field, with label and wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{field_password_input}', '{field_password_input}', 'Just the Password input, no label or wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{field_password_confirm}',       '{field_password_confirm}',       'The Confirm Password field, with label and wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{field_password_confirm_input}', '{field_password_confirm_input}', 'Just the Confirm Password input, no label or wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{captcha_block}',       '{captcha_block}',       'The captcha question field, with label and wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{captcha_block_input}', '{captcha_block_input}', 'Just the captcha answer input, no label or wrapper.') ?>
            <?= $tok('listing_default_join_form_template', '{captcha_question}', '{captcha_question}', 'The captcha question text on its own.') ?>
            <?= $tok('listing_default_join_form_template', '{submit_button}', '{submit_button}', 'The "Join Fanlisting" submit button.') ?>
            <?= $tok('listing_default_join_form_template', '{clear_button}',  '{clear_button}',  'The "Clear" reset button.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Code Donation Form -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Code Donation Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Arrange and label every field of the code donation form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_codes_submit_form_template', '{listing_title}', '{listing_title}', 'The listing\'s display name.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{listing_url}',   '{listing_url}',   'The link to the listing.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{field_code_image}',       '{field_code_image}',       'The file upload field, with label and wrapper.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{field_code_image_input}', '{field_code_image_input}', 'Just the file input, no label or wrapper.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{field_submitter_name}',       '{field_submitter_name}',       'The Name field, with label and wrapper.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{field_submitter_name_input}', '{field_submitter_name_input}', 'Just the Name input, no label or wrapper.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{field_submitter_email}',       '{field_submitter_email}',       'The Email field, with label and wrapper.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{field_submitter_email_input}', '{field_submitter_email_input}', 'Just the Email input, no label or wrapper.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{field_submitter_url}',       '{field_submitter_url}',         'The Website field, with label and wrapper.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{field_submitter_url_input}', '{field_submitter_url_input}',   'Just the Website input, no label or wrapper.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{captcha_block}',       '{captcha_block}',       'The captcha question field, with label and wrapper.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{captcha_block_input}', '{captcha_block_input}', 'Just the captcha answer input, no label or wrapper.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{captcha_question}', '{captcha_question}', 'The captcha question text on its own.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{submit_button}', '{submit_button}', 'The "Submit Code" submit button.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{clear_button}',  '{clear_button}',  'The "Clear" reset button.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Affiliate Application Form -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Affiliate Application Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Arrange and label every field of the affiliate application form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_affiliate_apply_form_template', '{listing_title}', '{listing_title}', 'The listing\'s display name.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{listing_url}',   '{listing_url}',   'The link to the listing.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{field_name}',       '{field_name}',       'The Site Name field, with label and wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{field_name_input}', '{field_name_input}', 'Just the Site Name input, no label or wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{field_url}',       '{field_url}',       'The Site URL field, with label and wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{field_url_input}', '{field_url_input}', 'Just the URL input, no label or wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{field_email}',       '{field_email}',       'The Email field (optional), with label and wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{field_email_input}', '{field_email_input}', 'Just the Email input, no label or wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{field_image}',       '{field_image}',       'The site button/image upload field, with label and wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{field_image_input}', '{field_image_input}', 'Just the file input (styled drop zone), no label or wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{field_message}',       '{field_message}',       'The Message textarea (optional), with label and wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{field_message_input}', '{field_message_input}', 'Just the Message textarea, no label or wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{captcha_block}',       '{captcha_block}',       'The captcha question field, with label and wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{captcha_block_input}', '{captcha_block_input}', 'Just the captcha answer input, no label or wrapper.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{captcha_question}', '{captcha_question}', 'The captcha question text on its own.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{submit_button}', '{submit_button}', 'The "Submit Application" submit button.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{clear_button}',  '{clear_button}',  'The "Clear" reset button.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Adopt Form -->
      <details class="card chevron" data-token-card>
        <summary class="chevron-head">Adopt Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Arrange and label every field of the adoption application form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts. Requires the Adoption add-on.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_adopt_form_template', '{listing_title}',        '{listing_title}',        'The listing\'s display name.') ?>
            <?= $tok('listing_default_adopt_form_template', '{listing_url}',          '{listing_url}',          'The link to the listing.') ?>
            <?= $tok('listing_default_adopt_form_template', '{field_name}',           '{field_name}',           'The Name field, with label and wrapper.') ?>
            <?= $tok('listing_default_adopt_form_template', '{field_name_input}',     '{field_name_input}',     'Just the Name input, no label or wrapper.') ?>
            <?= $tok('listing_default_adopt_form_template', '{field_email}',          '{field_email}',          'The Email field, with label and wrapper.') ?>
            <?= $tok('listing_default_adopt_form_template', '{field_email_input}',    '{field_email_input}',    'Just the Email input, no label or wrapper.') ?>
            <?= $tok('listing_default_adopt_form_template', '{field_url}',            '{field_url}',            'The Website URL field (optional), with label and wrapper.') ?>
            <?= $tok('listing_default_adopt_form_template', '{field_url_input}',      '{field_url_input}',      'Just the Website URL input, no label or wrapper.') ?>
            <?= $tok('listing_default_adopt_form_template', '{field_message}',        '{field_message}',        'The Message textarea (optional), with label and wrapper.') ?>
            <?= $tok('listing_default_adopt_form_template', '{field_message_input}',  '{field_message_input}',  'Just the Message textarea, no label or wrapper.') ?>
            <?= $tok('listing_default_adopt_form_template', '{captcha_block}',        '{captcha_block}',        'The captcha field, with label and wrapper.') ?>
            <?= $tok('listing_default_adopt_form_template', '{captcha_block_input}',  '{captcha_block_input}',  'Just the captcha answer input, no label or wrapper.') ?>
            <?= $tok('listing_default_adopt_form_template', '{captcha_question}',     '{captcha_question}',     'The captcha question text on its own.') ?>
            <?= $tok('listing_default_adopt_form_template', '{submit_button}',        '{submit_button}',        'The "Submit Application" submit button.') ?>
            <?= $tok('listing_default_adopt_form_template', '{clear_button}',         '{clear_button}',         'The "Clear" reset button.') ?>
          </tbody></table>
        </div>
      </details>

    </div><!-- /.composer-side -->
  </div><!-- /.composer-grid -->
</form>

<script type="application/json" id="luster-coltpl-defaults">
<?= json_encode(array_combine(
    array_keys($d),
    array_values($d)
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<script type="application/json" id="luster-coltpl-meta"><?= json_encode([
    'previewUrl' => $b . '/admin/listings/global-templates/preview',
    'csrf'       => $csrf_token ?? '',
], JSON_UNESCAPED_SLASHES) ?></script>

<div id="coltpl-prev-modal" class="modal-backdrop is-hidden" style="display:none" role="dialog" aria-modal="true" aria-label="Template preview">
  <div class="modal modal--preview" style="max-width:680px;height:68vh">
    <div class="modal-header">
      <span id="coltpl-prev-title" class="modal-title">Preview</span>
      <div style="display:flex;align-items:center;gap:.5rem">
        <span id="coltpl-prev-spinner" style="display:none;font-size:.8rem;color:var(--text-muted)">Loading&hellip;</span>
        <button type="button" id="coltpl-prev-close" class="btn btn-tertiary btn-sm" aria-label="Close preview">&times;&ensp;Close</button>
      </div>
    </div>
    <div class="modal-body--iframe">
      <iframe id="coltpl-prev-iframe" title="Template preview" sandbox=""></iframe>
    </div>
  </div>
</div>
