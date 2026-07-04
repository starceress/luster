<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Global listing templates. Edit the default templates that new listings inherit for
 members row, stats, affiliates section, codes display, news feed, join form, update
 form, reset form, codes-submit form, affiliate-apply form, and contact form.
*/
declare(strict_types=1);
use Luster\Core\View;
use Luster\Repositories\ListingSettingsRepo;

$b  = rtrim(View::e($base_url ?? ''), '/');
$g  = $globals ?? [];
$d  = [
    'listing_default_members_template'              => ListingSettingsRepo::defaultMembersTemplate(),
    'listing_default_stats_template'                => ListingSettingsRepo::defaultStatsTemplate(),
    'listing_default_affiliates_section_template'   => ListingSettingsRepo::defaultAffiliatesSectionTemplate(),
    'listing_default_newsfeed_template'             => ListingSettingsRepo::defaultNewsfeedTemplate(),
    'listing_default_join_form_template'            => ListingSettingsRepo::defaultJoinFormTemplate(),
    'listing_default_codes_submit_form_template'    => ListingSettingsRepo::defaultCodesSubmitFormTemplate(),
    'listing_default_affiliate_apply_form_template' => ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),
    'listing_default_update_form_template'          => ListingSettingsRepo::defaultUpdateFormTemplate(),
    'listing_default_reset_form_template'           => ListingSettingsRepo::defaultResetFormTemplate(),
    'listing_default_codes_template'                => ListingSettingsRepo::defaultCodesTemplate(),
    'listing_default_contact_form_template'         => ListingSettingsRepo::defaultContactFormTemplate(),
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

<?php
$activeGroup = (string) ($_GET['group'] ?? 'all');
$_validGroups = ['all', 'members', 'join-form', 'update-form', 'reset-form', 'statistics', 'codes', 'codes-form', 'contact-form', 'affiliates', 'affiliate-form', 'newsfeed'];
if (!in_array($activeGroup, $_validGroups, true)) { $activeGroup = 'all'; }
$_grp = static fn(string $g) => ($activeGroup !== 'all' && $activeGroup !== $g) ? 'hidden' : '';
?>

<div class="filter-panel is-open">
  <div class="filter-row" id="tpl-filter-row">
    <label for="tpl-group-select" class="tpl-filter-label">Select Template:</label>
    <div class="select-wrap">
      <select id="tpl-group-select" class="form">
        <option value="all"<?= $activeGroup === 'all' ? ' selected' : '' ?>>All Templates</option>
        <option value="affiliate-form"<?= $activeGroup === 'affiliate-form' ? ' selected' : '' ?>>Affiliate Application</option>
        <option value="affiliates"<?= $activeGroup === 'affiliates' ? ' selected' : '' ?>>Affiliates Display</option>
        <option value="codes"<?= $activeGroup === 'codes' ? ' selected' : '' ?>>Codes Display</option>
        <option value="codes-form"<?= $activeGroup === 'codes-form' ? ' selected' : '' ?>>Codes Donation Form</option>
        <option value="contact-form"<?= $activeGroup === 'contact-form' ? ' selected' : '' ?>>Contact Form</option>
        <option value="join-form"<?= $activeGroup === 'join-form' ? ' selected' : '' ?>>Join Form</option>
        <option value="reset-form"<?= $activeGroup === 'reset-form' ? ' selected' : '' ?>>Lost Password Form</option>
        <option value="members"<?= $activeGroup === 'members' ? ' selected' : '' ?>>Members List</option>
        <option value="newsfeed"<?= $activeGroup === 'newsfeed' ? ' selected' : '' ?>>News Feed</option>
        <option value="statistics"<?= $activeGroup === 'statistics' ? ' selected' : '' ?>>Statistics</option>
        <option value="update-form"<?= $activeGroup === 'update-form' ? ' selected' : '' ?>>Update Membership Form</option>
      </select>
    </div>
    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="search" id="tpl-group-search" class="form" placeholder="Search templates&hellip;" autocomplete="off">
    </div>
    <button type="button" class="btn btn-secondary btn-sm" id="tpl-group-search-btn">Search</button>
    <button type="button" class="btn btn-quaternary btn-sm" id="tpl-group-search-reset">Reset</button>
  </div>
</div>

<form method="POST" action="<?= $b ?>/admin/listings/global-templates" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="return_group" id="tpl-return-group" value="<?= View::e($activeGroup) ?>">

  <div class="composer-grid">

    <!-- ── Left column: template editors ─────────────────────────────────── -->
    <div class="card card--gradient">
      <div class="panel-header">
        <div class="panel-header-icon panel-header-icon-filled"><?= View::icon('embed') ?></div>
        <div class="panel-header-meta">
          <div class="panel-header-title">Global Listing Template Defaults</div>
          <div class="panel-header-sub">Site-wide defaults used by all listings that have not set a custom template. Overridden per listing on the listing's Templates tab.</div>
        </div>
      </div>

      <div class="tpl-reset-all-row card-body" data-tpl-group="all" <?= $activeGroup !== 'all' ? 'hidden' : '' ?>>
        <div class="tpl-actions-row">
          <button type="button" class="btn btn-tertiary btn-sm"
                  id="fl-reset-all-templates"
                  data-action="<?= $b ?>/admin/listings/global-templates/reset-all"
                  data-csrf="<?= View::e($csrf_token ?? '') ?>"
                  data-confirm="Reset all global listing templates to the Luster built-in defaults?&#10;&#10;Any global customisations will be lost. Per-listing overrides are not affected.">Reset All Templates to Default</button>
          <button type="button" class="btn btn-secondary btn-sm"
                  id="fl-tpack-export-btn"
                  data-endpoint="<?= $b ?>/admin/listings/global-templates/template-pack/export"
                  data-csrf="<?= View::e($csrf_token ?? '') ?>">Export as JSON</button>
          <button type="button" class="btn btn-secondary btn-sm"
                  id="fl-tpack-export-zip-btn"
                  data-endpoint="<?= $b ?>/admin/listings/global-templates/template-pack/export-zip"
                  data-csrf="<?= View::e($csrf_token ?? '') ?>">Export as ZIP</button>
          <button type="button" class="btn btn-secondary btn-sm"
                  id="fl-tpack-import-btn"
                  data-endpoint="<?= $b ?>/admin/listings/global-templates/template-pack/import-preview"
                  data-apply-endpoint="<?= $b ?>/admin/listings/global-templates/template-pack/import-apply"
                  data-csrf="<?= View::e($csrf_token ?? '') ?>">Import Templates (.json)</button>
          <button type="button" class="btn btn-secondary btn-sm"
                  id="fl-tpack-copy-btn"
                  data-listings-endpoint="<?= $b ?>/admin/listings/global-templates/template-pack/listings-for-copy"
                  data-source-endpoint="<?= $b ?>/admin/listings/global-templates/template-pack/copy-source"
                  data-apply-endpoint="<?= $b ?>/admin/listings/global-templates/template-pack/copy-apply"
                  data-csrf="<?= View::e($csrf_token ?? '') ?>">Copy From&hellip;</button>
        </div>
        <input type="file" id="fl-tpack-file-input" accept=".json" style="display:none">
      </div>

      <div class="card-body">

        <div class="tpl-group-section" data-tpl-group="members" <?= $_grp('members') ?>>
          <div class="section-label">Members List</div>
          <p class="form-hint form-hint--spaced">Default for all listings. Wrap your row design in <code>{each_member}&hellip;{/each_member}</code>.</p>
          <div class="field-row" style="margin-bottom: 1em">
            <label class="field-label" for="gl-members-per-page">Members per page</label>
            <div>
              <input id="gl-members-per-page" type="number" name="listing_default_members_per_page" class="form form--compact"
                     min="10" max="100" placeholder="25"
                     value="<?= $global_members_per_page !== null ? (int) $global_members_per_page : '' ?>">
              <p class="form-hint">Global default members per page. Leave blank to use the <code>per_page</code> app config value. Per-listing setting overrides this.</p>
            </div>
          </div>
          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label">Display style</label>
            <div>
              <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_members_template" data-layout-preset="listing_default_members_template">List</button>
              <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_members_template" data-layout-preset="listing_default_members_template__table">Table</button>
              <p class="form-hint">Switches the template between a list layout and a table layout.</p>
            </div>
          </div>
          <div class="field-group">
            <textarea name="listing_default_members_template" id="listing_default_members_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_members_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="members" data-textarea="listing_default_members_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_members_template">Reset to Luster Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="join-form" <?= $_grp('join-form') ?>>
          <div class="section-label">Join Form</div>
          <p class="form-hint form-hint--spaced">Default join form layout. Write any introductory text directly at the top of the template. Use the tokens below to arrange fields.</p>
          <div class="field-group">
            <textarea name="listing_default_join_form_template" id="listing_default_join_form_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_join_form_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="join_form" data-textarea="listing_default_join_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_join_form_template">Reset to Luster Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="update-form" <?= $_grp('update-form') ?>>
          <div class="section-label">Update Membership Form</div>
          <p class="form-hint form-hint--spaced">Default layout for the update (edit membership) form. Two steps: sign-in and edit fields.</p>
          <div class="field-group">
            <textarea name="listing_default_update_form_template" id="listing_default_update_form_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_update_form_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="update_form" data-textarea="listing_default_update_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_update_form_template">Reset to Luster Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="reset-form" <?= $_grp('reset-form') ?>>
          <div class="section-label">Lost Password Form</div>
          <p class="form-hint form-hint--spaced">Default layout for the password reset form. Two steps: email entry and new password.</p>
          <div class="field-group">
            <textarea name="listing_default_reset_form_template" id="listing_default_reset_form_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_reset_form_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="reset_form" data-textarea="listing_default_reset_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_reset_form_template">Reset to Luster Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="statistics" <?= $_grp('statistics') ?>>
          <div class="section-label">Statistics</div>

          <?php $_glNml = ($stat_newmembers_limit ?? null) !== null && ($stat_newmembers_limit ?? '') !== '' ? (int) $stat_newmembers_limit : null; ?>
          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label" for="gl-newmembers-limit">Default newest members shown</label>
            <div>
              <div class="select-wrap select-auto">
                <select id="gl-newmembers-limit" name="stat_newmembers_limit" class="form">
                  <option value="0"<?= $_glNml !== null && $_glNml === 0 ? ' selected' : '' ?>>Show all</option>
                  <option value="5"<?= $_glNml === null || $_glNml > 0 ? ' selected' : '' ?>>Truncate after 5</option>
                </select>
              </div>
              <p class="form-hint">Global default for <code>{stat_newmembers}</code>. &ldquo;Show all&rdquo; lists every member. &ldquo;Truncate&rdquo; shows the 5 most recent and adds &ldquo;&hellip;and more&rdquo;. Per-listing overrides this.</p>
            </div>
          </div>

          <div class="field-group">
            <textarea name="listing_default_stats_template" id="listing_default_stats_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_stats_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="stats" data-textarea="listing_default_stats_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_stats_template">Reset to Luster Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="codes" <?= $_grp('codes') ?>>
          <div class="section-label">Codes Display</div>
          <p class="form-hint form-hint--spaced">Default layout for the codes/buttons gallery. Use <code>{each_size_group}</code> for grouped mode or <code>{each_code}</code> for flat mode.</p>

          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label" for="gl-cs-toggle-style">Donation toggle style</label>
            <div>
              <div class="select-wrap select-auto">
                <select id="gl-cs-toggle-style" name="codes_submit_toggle_style" class="form">
                  <option value="link" <?= ($codes_submit_toggle_style ?? 'link') === 'link' ? 'selected' : '' ?>>Text link</option>
                  <option value="button" <?= ($codes_submit_toggle_style ?? 'link') === 'button' ? 'selected' : '' ?>>Button</option>
                </select>
              </div>
              <p class="form-hint">How the <code>{codes_submit_toggle}</code> appears &mdash; as a plain underlined link or a styled button. Per-listing overrides this.</p>
            </div>
          </div>

          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label" for="gl-cs-toggle-label">Toggle label</label>
            <div>
              <input id="gl-cs-toggle-label" type="text" name="codes_submit_toggle_label" class="form" maxlength="200"
                     value="<?= View::e($codes_submit_toggle_label ?? '') ?>"
                     placeholder="Donate a code?">
              <p class="form-hint">Default text for the <code>{codes_submit_toggle}</code>. Per-listing overrides this. Leave blank for the built-in default.</p>
            </div>
          </div>

          <div class="field-group">
            <textarea name="listing_default_codes_template" id="listing_default_codes_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_codes_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="codes" data-textarea="listing_default_codes_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_codes_template">Reset to Luster Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="codes-form" <?= $_grp('codes-form') ?>>
          <div class="section-label">Codes Donation Form</div>
          <p class="form-hint form-hint--spaced">Default layout for the code donation form.</p>
          <div class="field-group">
            <textarea name="listing_default_codes_submit_form_template" id="listing_default_codes_submit_form_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_codes_submit_form_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="codes_submit_form" data-textarea="listing_default_codes_submit_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_codes_submit_form_template">Reset to Luster Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="affiliates" <?= $_grp('affiliates') ?>>
          <div class="section-label">Affiliates Display</div>
          <p class="form-hint form-hint--spaced">Controls the full affiliates block. Use <code>{each_affiliate}&hellip;{/each_affiliate}</code> to design each row.</p>

          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label" for="gl-aff-apply-style">Application toggle style</label>
            <div>
              <div class="select-wrap select-auto">
                <select id="gl-aff-apply-style" name="affiliate_apply_style" class="form">
                  <option value="link" <?= ($affiliate_apply_style ?? 'link') === 'link' ? 'selected' : '' ?>>Text link</option>
                  <option value="button" <?= ($affiliate_apply_style ?? 'link') === 'button' ? 'selected' : '' ?>>Button</option>
                </select>
              </div>
              <p class="form-hint">How the <code>{affiliate_apply_toggle}</code> appears &mdash; as a plain underlined link or a styled button. Per-listing overrides this.</p>
            </div>
          </div>

          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label" for="gl-aff-toggle-label">Toggle label</label>
            <div>
              <input id="gl-aff-toggle-label" type="text" name="affiliate_apply_toggle_label" class="form" maxlength="200"
                     value="<?= View::e($affiliate_apply_toggle_label ?? '') ?>"
                     placeholder="Apply to become an affiliate?">
              <p class="form-hint">Default text for the <code>{affiliate_apply_toggle}</code>. Per-listing overrides this. Leave blank for the built-in default.</p>
            </div>
          </div>

          <div class="field-group">
            <textarea name="listing_default_affiliates_section_template" id="listing_default_affiliates_section_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_affiliates_section_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="affiliates_section" data-textarea="listing_default_affiliates_section_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_affiliates_section_template">Reset to Luster Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="affiliate-form" <?= $_grp('affiliate-form') ?>>
          <div class="section-label">Affiliate Application</div>
          <p class="form-hint form-hint--spaced">Default layout for affiliate application forms.</p>
          <div class="field-group">
            <textarea name="listing_default_affiliate_apply_form_template" id="listing_default_affiliate_apply_form_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_affiliate_apply_form_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="affiliate_apply_form" data-textarea="listing_default_affiliate_apply_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_affiliate_apply_form_template">Reset to Luster Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="contact-form" <?= $_grp('contact-form') ?>>
          <div class="section-label">Contact Form</div>
          <p class="form-hint form-hint--spaced">Default layout for the contact form (name, email, subject, message).</p>
          <div class="field-group">
            <textarea name="listing_default_contact_form_template" id="listing_default_contact_form_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_contact_form_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="contact_form" data-textarea="listing_default_contact_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_contact_form_template">Reset to Luster Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="newsfeed" <?= $_grp('newsfeed') ?>>
          <div class="section-label">News Feed</div>
          <p class="form-hint form-hint--spaced">Default for listings with the News Feed add-on. Wrap each entry in <code>{each_update}&hellip;{/each_update}</code>.</p>
          <div class="field-group">
            <textarea name="listing_default_newsfeed_template" id="listing_default_newsfeed_template" class="form form-mono" spellcheck="false"><?= View::e($val('listing_default_newsfeed_template')) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="newsfeed" data-textarea="listing_default_newsfeed_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="listing_default_newsfeed_template">Reset to Luster Default</button>
          </div>
        </div>

      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Global Defaults</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>
    </div>

    <!-- ── Right column: token reference ─────────────────────────────────── -->
    <div class="composer-side composer-side--sticky">

      <div class="filter-panel is-open token-search-wrap">
        <div class="search-field-wrap">
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
      </div>

      <p id="global-token-search-empty" data-token-search-empty class="form-hint token-search-empty" hidden>No tokens found.</p>

      <!-- Members List -->
      <details class="card chevron" data-token-card data-tpl-group="members" <?= $_grp('members') ?>>
        <summary class="chevron-head">Members List Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Wrap your row design in {each_member}...{/each_member}. Use {if_no_members} outside the loop for an empty state.</p>
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

      <!-- Join Form -->
      <details class="card chevron" data-token-card data-tpl-group="join-form" <?= $_grp('join-form') ?>>
        <summary class="chevron-head">Join Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Arrange and label every field of the join form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_join_form_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $tok('listing_default_join_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $tok('listing_default_join_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
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
            <?= $tok('listing_default_join_form_template', '{submit_button}', '{submit_button}', 'The "Join Listing" submit button.') ?>
            <?= $tok('listing_default_join_form_template', '{clear_button}',  '{clear_button}',  'The "Clear Form" button.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Update Membership Form -->
      <details class="card chevron" data-token-card data-tpl-group="update-form" <?= $_grp('update-form') ?>>
        <summary class="chevron-head">Update Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Use {if_not_signed_in}...{/if_not_signed_in} for the sign-in step and {if_signed_in}...{/if_signed_in} for the update fields step.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_update_form_template', '{if_not_signed_in}{/if_not_signed_in}', '{if_not_signed_in}...{/if_not_signed_in}', 'Shows the sign-in step (email + password).') ?>
            <?= $tok('listing_default_update_form_template', '{if_signed_in}{/if_signed_in}',         '{if_signed_in}...{/if_signed_in}',         'Shows the update form step.') ?>
            <?= $tok('listing_default_update_form_template', '{field_signin_email}',    '{field_signin_email}',    'Sign-in step: the Email field, with label and wrapper.') ?>
            <?= $tok('listing_default_update_form_template', '{field_signin_password}', '{field_signin_password}', 'Sign-in step: the Password field, with label and wrapper.') ?>
            <?= $tok('listing_default_update_form_template', '{lost_password_url}',     '{lost_password_url}',     'Sign-in step: URL of the password reset page.') ?>
            <?= $tok('listing_default_update_form_template', '{field_new_name}',    '{field_new_name}',    'Update step: the New Name field, with label and wrapper.') ?>
            <?= $tok('listing_default_update_form_template', '{field_new_email}',   '{field_new_email}',   'Update step: the New Email field, with label and wrapper.') ?>
            <?= $tok('listing_default_update_form_template', '{field_new_url}',     '{field_new_url}',     'Update step: the New Website URL field, with label and wrapper.') ?>
            <?= $tok('listing_default_update_form_template', '{field_new_country}', '{field_new_country}', 'Update step: the Country selector, with label and wrapper.') ?>
            <?= $tok('listing_default_update_form_template', '{additional_fields}', '{additional_fields}', 'Update step: all additional fields, with labels and wrappers, in order.') ?>
            <?= $tok('listing_default_update_form_template', '{field_password}',         '{field_password}',         'Update step: the New Password field, with label and wrapper.') ?>
            <?= $tok('listing_default_update_form_template', '{field_password_confirm}', '{field_password_confirm}', 'Update step: the Confirm New Password field, with label and wrapper.') ?>

            <?= $tok('listing_default_update_form_template', '{field_remove}',     '{field_remove}',     'Update step: the &ldquo;Delete my membership&rdquo; checkbox block.') ?>
            <?= $tok('listing_default_update_form_template', '{submit_button}',    '{submit_button}',    'The submit button.') ?>
            <?= $tok('listing_default_update_form_template', '{clear_button}',     '{clear_button}',     'The &ldquo;Clear&rdquo; reset button.') ?>
            <?= $tok('listing_default_update_form_template', '{listing_title}',   '{listing_title}',   'The listing&rsquo;s display name.') ?>
            <?= $tok('listing_default_update_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $tok('listing_default_update_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Lost Password Form -->
      <details class="card chevron" data-token-card data-tpl-group="reset-form" <?= $_grp('reset-form') ?>>
        <summary class="chevron-head">Lost Password Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Use {if_step_email} for the email-entry step and {if_step_confirm} for the new-password step. Use {if_token_valid} or {if_token_invalid} inside the confirm step.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_reset_form_template', '{if_step_email}{/if_step_email}',     '{if_step_email}...{/if_step_email}',     'Shows the email-entry step.') ?>
            <?= $tok('listing_default_reset_form_template', '{if_step_confirm}{/if_step_confirm}', '{if_step_confirm}...{/if_step_confirm}', 'Shows the new-password step.') ?>
            <?= $tok('listing_default_reset_form_template', '{if_token_valid}{/if_token_valid}',   '{if_token_valid}...{/if_token_valid}',   'Inside confirm step: shows when the reset token is valid.') ?>
            <?= $tok('listing_default_reset_form_template', '{if_token_invalid}{/if_token_invalid}', '{if_token_invalid}...{/if_token_invalid}', 'Inside confirm step: shows when the token has expired or is invalid.') ?>
            <?= $tok('listing_default_reset_form_template', '{field_email}',               '{field_email}',               'Email-entry step: the Email field, with label and wrapper.') ?>

            <?= $tok('listing_default_reset_form_template', '{field_new_password}',        '{field_new_password}',        'Confirm step: the New Password field, with label and wrapper.') ?>
            <?= $tok('listing_default_reset_form_template', '{field_new_password_confirm}', '{field_new_password_confirm}', 'Confirm step: the Confirm New Password field, with label and wrapper.') ?>
            <?= $tok('listing_default_reset_form_template', '{reset_request_url}',         '{reset_request_url}',         'URL of the email-entry form. Use inside {if_token_invalid} for a &ldquo;request a new one&rdquo; link.') ?>
            <?= $tok('listing_default_reset_form_template', '{submit_button}',             '{submit_button}',             'The submit button.') ?>
            <?= $tok('listing_default_reset_form_template', '{clear_button}',              '{clear_button}',              'The &ldquo;Clear&rdquo; reset button.') ?>
            <?= $tok('listing_default_reset_form_template', '{listing_title}',   '{listing_title}',   'The listing&rsquo;s display name.') ?>
            <?= $tok('listing_default_reset_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $tok('listing_default_reset_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Statistics -->
      <details class="card chevron" data-token-card data-tpl-group="statistics" <?= $_grp('statistics') ?>>
        <summary class="chevron-head">Statistics Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>These tokens fill in the public stats block for a listing.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_stats_template', '{stat_opened}',       '{stat_opened}',       'The date the listing opened.') ?>
            <?= $tok('listing_default_stats_template', '{stat_updated}',      '{stat_updated}',      'The most recent activity date: whichever is more recent of the newest approved member\'s join date and the Last Updated date you set. Shows &ldquo;N/A&rdquo; if neither is set.') ?>
            <?= $tok('listing_default_stats_template', '{stat_approved}',     '{stat_approved}',     'How many approved members this listing has.') ?>
            <?= $tok('listing_default_stats_template', '{stat_pending}',      '{stat_pending}',      'How many members are waiting for approval.') ?>
            <?= $tok('listing_default_stats_template', '{stat_countries}',    '{stat_countries}',    'Number of distinct countries represented among approved members.') ?>
            <?= $tok('listing_default_stats_template', '{stat_newmembers}',        '{stat_newmembers}',        'Newest approved members, linked to their websites when available. Count controlled by the &ldquo;Default newest members shown&rdquo; setting above (per-listing can override). Shows &ldquo;&hellip;and more&rdquo; when there are more total.') ?>
            <?= $tok('listing_default_stats_template', '{if_stat_pending}{/if_stat_pending}', '{if_stat_pending}...{/if_stat_pending}', 'Shows only when at least one member is awaiting approval.') ?>
            <?= $tok('listing_default_stats_template', '{stat_growth}',            '{stat_growth}',            'Average members per day, with unit (e.g. &ldquo;0.15 members/day&rdquo;).') ?>
            <?= $tok('listing_default_stats_template', '{stat_growth_rate}',       '{stat_growth_rate}',       'Numeric growth rate only (e.g. &ldquo;0.15&rdquo;). Use this to build your own formatting.') ?>
            <?= $tok('listing_default_stats_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $tok('listing_default_stats_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $tok('listing_default_stats_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
            <?= $tok('listing_default_stats_template', '{approved_by}',       '{approved_by}',       'Network acronym: &ldquo;TFL&rdquo;, &ldquo;TAFL&rdquo;, or empty. Plain text, no link.') ?>
            <?= $tok('listing_default_stats_template', '{network_name}',      '{network_name}',      'Full network name: &ldquo;The Fanlistings Network&rdquo;, &ldquo;The Anime Fanlistings Network&rdquo;, or empty.') ?>
            <?= $tok('listing_default_stats_template', '{tfl_url}',           '{tfl_url}',           'TFL website URL: <code>https://thefanlistings.org/</code>') ?>
            <?= $tok('listing_default_stats_template', '{tafl_url}',          '{tafl_url}',          'TAFL website URL: <code>https://www.animefanlistings.com/</code>') ?>
            <?= $tok('listing_default_stats_template', '{if_approved_by}{/if_approved_by}', '{if_approved_by}...{/if_approved_by}', 'Shows only when an approved-by network is saved.') ?>
            <?= $tok('listing_default_stats_template', '{if_tfl}{/if_tfl}',             '{if_tfl}...{/if_tfl}',             'Shows only when this listing is TFL-approved.') ?>
            <?= $tok('listing_default_stats_template', '{if_tafl}{/if_tafl}',           '{if_tafl}...{/if_tafl}',           'Shows only when this listing is TAFL-approved.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Codes Display -->
      <details class="card chevron" data-token-card data-tpl-group="codes" <?= $_grp('codes') ?>>
        <summary class="chevron-head">Codes Display Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Use {each_size_group}...{/each_size_group} (with an inner {each_code}...{/each_code}) to group codes by dimensions, or a single {each_code}...{/each_code} loop for a flat gallery.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_codes_template', '{each_size_group}{/each_size_group}', '{each_size_group}...{/each_size_group}', 'Repeats the HTML inside it once per group of same-sized codes. Wrap an {each_code}...{/each_code} loop inside it.') ?>
            <?= $tok('listing_default_codes_template', '{group_size}',       '{group_size}',       'The group label, e.g. &ldquo;88&times;31px&rdquo; or &ldquo;Other&rdquo;. Use inside {each_size_group}.') ?>
            <?= $tok('listing_default_codes_template', '{group_code_count}', '{group_code_count}', 'How many codes are in this size group. Use inside {each_size_group}.') ?>
            <?= $tok('listing_default_codes_template', '{each_code}{/each_code}', '{each_code}...{/each_code}', 'Repeats the HTML inside it once for each approved code shown.') ?>
            <?= $tok('listing_default_codes_template', '{code_url}',        '{code_url}',        'The full image URL for the code.') ?>
            <?= $tok('listing_default_codes_template', '{code_width}',      '{code_width}',      'The code\'s width in pixels. Empty when dimensions are unknown.') ?>
            <?= $tok('listing_default_codes_template', '{code_height}',     '{code_height}',     'The code\'s height in pixels. Empty when dimensions are unknown.') ?>
            <?= $tok('listing_default_codes_template', '{code_size}',       '{code_size}',       'The code\'s dimensions as &ldquo;WxH&rdquo;, e.g. &ldquo;88&times;31&rdquo;. Empty when unknown.') ?>
            <?= $tok('listing_default_codes_template', '{code_name}',       '{code_name}',       'The code\'s saved name/title. Empty when not set.') ?>
            <?= $tok('listing_default_codes_template', '{code_donor_name}', '{code_donor_name}', 'The donor\'s name. Empty when the code was not donated.') ?>
            <?= $tok('listing_default_codes_template', '{code_donor_url}',  '{code_donor_url}',  'The donor\'s website URL. Empty when not set.') ?>
            <?= $tok('listing_default_codes_template', '{if_size}{/if_size}',           '{if_size}...{/if_size}',           'Shows only when width and height are known.') ?>
            <?= $tok('listing_default_codes_template', '{if_size_changed}{/if_size_changed}', '{if_size_changed}...{/if_size_changed}', 'Flat mode only. Shows when the code size differs from the previous code &mdash; useful for size headings.') ?>
            <?= $tok('listing_default_codes_template', '{if_donor}{/if_donor}',         '{if_donor}...{/if_donor}',         'Shows only when the code was donated by a named donor.') ?>
            <?= $tok('listing_default_codes_template', '{if_no_donor}{/if_no_donor}',   '{if_no_donor}...{/if_no_donor}',   'Shows only when the code has no donor.') ?>
            <?= $tok('listing_default_codes_template', '{if_donor_url}{/if_donor_url}', '{if_donor_url}...{/if_donor_url}', 'Shows only when the donor has a website URL saved.') ?>
            <?= $tok('listing_default_codes_template', '{if_no_donor_url}{/if_no_donor_url}', '{if_no_donor_url}...{/if_no_donor_url}', 'Shows only when the donor has no website URL.') ?>
            <?= $tok('listing_default_codes_template', '{if_code_name}{/if_code_name}', '{if_code_name}...{/if_code_name}', 'Shows only when the code has a saved name.') ?>
            <?= $tok('listing_default_codes_template', '{if_codes}{/if_codes}',       '{if_codes}...{/if_codes}',       'Shows when this listing has at least one approved code. Place outside the loop.') ?>
            <?= $tok('listing_default_codes_template', '{if_no_codes}{/if_no_codes}', '{if_no_codes}...{/if_no_codes}', 'Shows when this listing has no approved codes (empty state). Place outside the loop.') ?>
            <?= $tok('listing_default_codes_template', '{codes_submit_toggle}', '{codes_submit_toggle}', 'Collapsible code donation form. Label, style (link or button) are configurable above. Empty when code donations are disabled.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Code Donation Form -->
      <details class="card chevron" data-token-card data-tpl-group="codes-form" <?= $_grp('codes-form') ?>>
        <summary class="chevron-head">Codes Donation Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Arrange and label every field of the code donation form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_codes_submit_form_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $tok('listing_default_codes_submit_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
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

      <!-- Affiliates Section -->
      <details class="card chevron" data-token-card data-tpl-group="affiliates" <?= $_grp('affiliates') ?>>
        <summary class="chevron-head">Affiliates Section Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Use {each_affiliate}...{/each_affiliate} to design each row yourself.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_affiliates_section_template', '{each_affiliate}{/each_affiliate}', '{each_affiliate}...{/each_affiliate}', 'Repeats the HTML inside it once for each affiliate.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{aff_name}',      '{aff_name}',      'The affiliate site name. Use inside {each_affiliate}.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{aff_url}',       '{aff_url}',       'The affiliate site link. Use inside {each_affiliate}.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{aff_image_url}', '{aff_image_url}', 'The affiliate image URL. Empty when there is no image. Use inside {each_affiliate}.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{affiliates_count}', '{affiliates_count}', 'How many active affiliates this listing has.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{affiliate_apply_url}',                '{affiliate_apply_url}',                   'URL of the affiliate application form for this listing.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{affiliate_apply_toggle}',              '{affiliate_apply_toggle}',                'Collapsible affiliate application form. Label, style (link or button) are configurable above. Empty when applications are disabled.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{if_affiliates}{/if_affiliates}',       '{if_affiliates}...{/if_affiliates}',       'Shows only when this listing has at least one affiliate.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{if_no_affiliates}{/if_no_affiliates}', '{if_no_affiliates}...{/if_no_affiliates}', 'Shows only when this listing has no affiliates.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{if_aff_image}{/if_aff_image}',       '{if_aff_image}...{/if_aff_image}',       'Inside {each_affiliate}, shows only when the affiliate has an image.') ?>
            <?= $tok('listing_default_affiliates_section_template', '{if_no_aff_image}{/if_no_aff_image}', '{if_no_aff_image}...{/if_no_aff_image}', 'Inside {each_affiliate}, shows only when the affiliate has no image.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Affiliate Application Form -->
      <details class="card chevron" data-token-card data-tpl-group="affiliate-form" <?= $_grp('affiliate-form') ?>>
        <summary class="chevron-head">Affiliate Application Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Arrange and label every field of the affiliate application form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_affiliate_apply_form_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $tok('listing_default_affiliate_apply_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
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

      <!-- Contact Form -->
      <details class="card chevron" data-token-card data-tpl-group="contact-form" <?= $_grp('contact-form') ?>>
        <summary class="chevron-head">Contact Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Arrange and label every field of the contact form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_contact_form_template', '{listing_title}',         '{listing_title}',         'The listing\'s display name.') ?>
            <?= $tok('listing_default_contact_form_template', '{listing_subject}',       '{listing_subject}',       'What this fanlisting is about (e.g. &ldquo;Archeology&rdquo;, &ldquo;Marilyn Monroe&rdquo;).') ?>
            <?= $tok('listing_default_contact_form_template', '{listing_url}',           '{listing_url}',           'The link to the listing.') ?>
            <?= $tok('listing_default_contact_form_template', '{field_name}',            '{field_name}',            'The Name field, with label and wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{field_name_input}',      '{field_name_input}',      'Just the Name input, no label or wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{field_email}',           '{field_email}',           'The Email field, with label and wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{field_email_input}',     '{field_email_input}',     'Just the Email input, no label or wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{field_url}',             '{field_url}',             'The Website URL field (optional), with label and wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{field_url_input}',       '{field_url_input}',       'Just the Website URL input, no label or wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{field_subject}',         '{field_subject}',         'The Subject dropdown, with label and wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{field_subject_input}',   '{field_subject_input}',   'Just the Subject select element, no label or wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{field_message}',         '{field_message}',         'The Message textarea, with label and wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{field_message_input}',   '{field_message_input}',   'Just the Message textarea, no label or wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{captcha_block}',         '{captcha_block}',         'The captcha field, with label and wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{captcha_block_input}',   '{captcha_block_input}',   'Just the captcha answer input, no label or wrapper.') ?>
            <?= $tok('listing_default_contact_form_template', '{captcha_question}',      '{captcha_question}',      'The captcha question text on its own.') ?>
            <?= $tok('listing_default_contact_form_template', '{submit_button}',         '{submit_button}',         'The "Send Message" submit button.') ?>
            <?= $tok('listing_default_contact_form_template', '{clear_button}',          '{clear_button}',          'The "Clear" reset button.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Newsfeed -->
      <details class="card chevron" data-token-card data-tpl-group="newsfeed" <?= $_grp('newsfeed') ?>>
        <summary class="chevron-head">News Feed Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Wrap each entry in {each_update}...{/each_update}. Luster repeats it once per news feed entry. Requires the News Feed add-on.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $tok('listing_default_newsfeed_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $tok('listing_default_newsfeed_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $tok('listing_default_newsfeed_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
            <?= $tok('listing_default_newsfeed_template', '{if_updates}{/if_updates}',       '{if_updates}...{/if_updates}',       'Shows only when there is at least one entry on the current page.') ?>
            <?= $tok('listing_default_newsfeed_template', '{if_no_updates}{/if_no_updates}', '{if_no_updates}...{/if_no_updates}', 'Shows when the current page has no entries (use for an empty state message).') ?>
            <?= $tok('listing_default_newsfeed_template', '{each_update}{/each_update}', '{each_update}...{/each_update}', 'Repeats the HTML inside it once for each entry shown.') ?>
            <?= $tok('listing_default_newsfeed_template', '{update_title}',      '{update_title}',      'The entry title.') ?>
            <?= $tok('listing_default_newsfeed_template', '{update_date}',       '{update_date}',       'The posted date in human-readable format, e.g. June 1, 2025.') ?>
            <?= $tok('listing_default_newsfeed_template', '{update_author}',     '{update_author}',     'The author name. Empty when not set.') ?>
            <?= $tok('listing_default_newsfeed_template', '{update_categories}', '{update_categories}', 'Category names, comma-separated. Empty when not set.') ?>
            <?= $tok('listing_default_newsfeed_template', '{update_body_html}',  '{update_body_html}',  'The entry body text, with line breaks converted to HTML.') ?>
            <?= $tok('listing_default_newsfeed_template', '{if_author}{/if_author}',         '{if_author}...{/if_author}',         'Shows only when the entry has an author set.') ?>
            <?= $tok('listing_default_newsfeed_template', '{if_categories}{/if_categories}', '{if_categories}...{/if_categories}', 'Shows only when the entry has categories set.') ?>
          </tbody></table>
        </div>
      </details>

    </div><!-- /.composer-side -->
  </div><!-- /.composer-grid -->
</form>

<script type="application/json" id="luster-coltpl-defaults">
<?= json_encode(array_merge($d, [
    'listing_default_members_template__table' => ListingSettingsRepo::defaultMembersTableTemplate(),
]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
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
