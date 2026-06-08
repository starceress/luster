<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing    $listing */
/** @var string                       $error */
/** @var string                       $success */
/** @var array                        $old */
/** @var array                        $field_errors */
/** @var array                        $cfg */
/** @var string|null                  $current_image */
/** @var int                          $approved */
/** @var int                          $pending */
/** @var \Luster\Models\Affiliate[]        $affiliates */
/** @var \Luster\Models\AdditionalField[]  $fields */
/** @var array                        $templates */
/** @var \Luster\Models\Category[]    $categories */
/** @var int|null                     $current_category_id */
/** @var string                       $base_url */
/** @var string                       $csrf_token */
$b  = rtrim(View::e($base_url), '/');
$fe = $field_errors ?? [];

$sizeMap   = ['small' => '50px', 'medium' => '100px', 'large' => '150px'];
$sizeKey   = (string) ($cfg['image_preview_size'] ?? 'medium');
$imgHeight = $sizeMap[$sizeKey] ?? '100px';

// Resolve display values: prefer $old (re-populates on validation fail) then model
$oldTitle  = $old['title']  ?? $listing->title;
$oldUrl    = $old['url']    ?? $listing->url;
$oldEmail  = $old['email']  ?? $listing->email;

// Resolve current status string
if ($listing->upcoming) {
    $currentStatus = 'upcoming';
} elseif ($listing->trouble) {
    $currentStatus = 'pending';
} else {
    $currentStatus = 'current';
}
$oldStatus = $old['status'] ?? $currentStatus;

// Cat-widget JSON data
$dataTfl       = json_encode($tfl_cats       ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl      = json_encode($tafl_cats      ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom    = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustomCats = json_encode($custom_cats   ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);

// Build category lookup from all available sources
$catLookup = [];
foreach ($tfl_cats  ?? [] as $c) { $catLookup[(int) $c['id']] = ['network' => 'TFL',  'name' => $c['name']]; }
foreach ($tafl_cats ?? [] as $c) { $catLookup[(int) $c['id']] = ['network' => 'TAFL', 'name' => $c['name']]; }
foreach ($custom_cats ?? [] as $net => $cats) {
    foreach ($cats as $c) { $catLookup[(int) $c['id']] = ['network' => $net, 'name' => $c['name']]; }
}

// Pre-populate cat-widget: posted arrays (validation error) → junction table → catid fallback
$existingCats = [];
$oldNets  = $old['cat_network']       ?? null;
$oldNames = $old['cat_name']          ?? null;
$oldOther = $old['cat_network_other'] ?? [];
if (is_array($oldNets) && is_array($oldNames)) {
    foreach ($oldNets as $i => $net) {
        $net = trim((string) $net);
        $nm  = trim((string) ($oldNames[$i] ?? ''));
        if ($net === 'other') {
            $net = trim((string) ($oldOther[$i] ?? ''));
        }
        if ($net !== '' && $nm !== '') {
            $existingCats[] = ['network' => $net, 'name' => $nm];
        }
    }
} elseif (!empty($current_category_ids ?? [])) {
    foreach ($current_category_ids as $cid) {
        if (isset($catLookup[(int) $cid])) {
            $existingCats[] = $catLookup[(int) $cid];
        }
    }
} else {
    $catidRaw = (string) ($listing->catid ?? '');
    $catIds   = array_filter(explode('|', $catidRaw), static fn($v) => $v !== '');
    foreach ($catIds as $cid) {
        if (isset($catLookup[(int) $cid])) {
            $existingCats[] = $catLookup[(int) $cid];
        }
    }
}
$dataExisting = json_encode($existingCats, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings" class="link">
    &larr; Back to Owned Listings
  </a>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $listing->id ?>&status=all" class="btn btn-quaternary btn-sm">Member List</a>
    <a href="<?= $b ?>/admin/emails?tab=bulk&listing=<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">Bulk Email</a>
    <?php if (in_array('Kim', $nav_installed_addons ?? [], true)): ?>
      <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/show" class="btn btn-quaternary btn-sm">KIM List</a>
    <?php endif; ?>
    <?php if (in_array('Updates', $nav_installed_addons ?? [], true)): ?>
      <a href="<?= $b ?>/admin/updates/<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">Updates Feed</a>
    <?php endif; ?>
    <form method="post"
          action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/delete"
          class="action-form-inline"
          data-del-modal data-del-entity="listing"
          data-del-name="<?= View::e($listing->title) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger btn-sm"><?= View::icon('delete') ?> Delete Listing</button>
    </form>
  </div>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="stats-grid stats-grid-row">
<?php $pl = View::e($b . '/admin/members?listing_id=' . (int) $listing->id . '&status=pending'); ?>
<div class="stat-card">
  <div class="stat-header">Total Members</div>
  <div class="stat-value"><?= (int) $approved ?></div>
</div>
<div class="stat-card">
  <div class="stat-header">Pending</div>
  <div class="stat-value"><?= $pending > 0 ? '<a href="' . $pl . '" class="link pending-link">' . $pending . '</a>' : $pending ?></div>
</div>
<div class="stat-card">
  <div class="stat-header">Total Countries</div>
  <div class="stat-value"><?= (int) ($countries ?? 0) ?></div>
</div>
<div class="stat-card">
  <div class="stat-header">Last Updated</div>
  <div class="stat-value"><?= View::e(($fl_last_updated ?? '') !== '' ? date($admin_date_format, strtotime($fl_last_updated)) : '-') ?></div>
</div>
</div>

<div class="tabs-wrap">
<div class="tabs">
  <button class="tabs-btn active" data-tab="general">General</button>
  <button class="tabs-btn" data-tab="fields">Additional Fields</button>
  <button class="tabs-btn" data-tab="codes">Codes</button>
  <button class="tabs-btn" data-tab="affiliates">Affiliates<?php if (!empty($affiliates)): ?> <span class="badge badge-inactive badge-inline-sm"><?= count($affiliates) ?></span><?php endif; ?></button>
  <button class="tabs-btn" data-tab="templates">Templates</button>
  <button class="tabs-btn" data-tab="embed">Embed</button>
  <button class="tabs-btn" data-tab="advanced">Advanced</button>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">Section</span>
  <div class="select-wrap flex-fill">
    <select class="tabs-select">
      <option value="general">General</option>
      <option value="fields">Additional Fields</option>
      <option value="codes">Codes</option>
      <option value="affiliates">Affiliates<?= !empty($affiliates) ? ' (' . count($affiliates) . ')' : '' ?></option>
      <option value="templates">Templates</option>
      <option value="embed">Embed</option>
      <option value="advanced">Advanced</option>
    </select>
  </div>
</div>
</div>
<!-- ──── TAB: GENERAL ───────────────────────────────────────────────── -->
<div id="tab-general" class="tabs-panel active">
  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" enctype="multipart/form-data" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('star') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">General Details</div>
          <div class="pc-head-sub">Manage the listing's details, image, status, and public form settings.</div>
        </div>
      </div>
      <div class="pc-section">
        <div class="pc-section-label">Details</div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Network &amp; Category</label>
          <div>
            <div class="cat-widget" id="cat-widget"
                 data-tfl='<?= $dataTfl ?>'
                 data-tafl='<?= $dataTafl ?>'
                 data-custom='<?= $dataCustom ?>'
                 data-custom-cats='<?= $dataCustomCats ?>'
                 data-existing='<?= $dataExisting ?>'>
              <div id="cat-rows" class="select-auto"></div>
              <a href="#" class="link btn btn-sm" id="cat-add"><?= View::icon('add') ?> Add another category</a>
            </div>
            <p class="form-hint">The network(s) and category(ies) this listing is listed under.</p>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-subject">Subject</label>
          <div>
            <input id="fl-subject" type="text" name="subject" class="form"
                   value="<?= View::e($listing->subject ?? '') ?>" maxlength="255">
            <p class="form-hint form-hint-inline">The subject of your listing (e.g. character name, show title).</p>
          </div>
        </div>

        <div class="listing-field-row<?= !empty($fe['title']) ? ' has-error' : '' ?>">
          <label class="listing-field-label" for="fl-title">Title</label>
          <div>
            <input id="fl-title" type="text" name="title" class="form"
                   value="<?= View::e((string) $oldTitle) ?>"
                   maxlength="255" required>
            <p class="form-hint">Optional display name for your listing. If you leave it blank, Luster uses the subject.</p>
            <?php if (!empty($fe['title'])): ?><p class="field-error"><?= View::e($fe['title'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="listing-field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
          <label class="listing-field-label" for="fl-url">Website URL <span class="req">*</span></label>
          <div>
            <input id="fl-url" type="url" name="url" class="form"
                   value="<?= View::e((string) $oldUrl) ?>"
                   maxlength="500" placeholder="https://" required>
            <?php if (!empty($fe['url'])): ?><p class="field-error"><?= View::e($fe['url'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top<?= !empty($fe['email']) ? ' has-error' : '' ?>">
          <label class="listing-field-label" for="fl-email">Contact Email</label>
          <div>
            <input id="fl-email" type="email" name="email" class="form"
                   value="<?= View::e((string) $oldEmail) ?>"
                   maxlength="255" required>
            <p class="form-hint form-hint-inline">Luster uses this address as the sender for emails from this listing.</p>
            <?php if (!empty($fe['email'])): ?><p class="field-error"><?= View::e($fe['email'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="listing-field-row">
          <label class="listing-field-label">Status</label>
          <div class="status-pill-row" id="status-pills">
            <label class="status-pill status-pill--current">
              <input type="radio" name="status" value="current"  <?= $oldStatus === 'current'  ? 'checked' : '' ?>> Current
            </label>
            <label class="status-pill status-pill--upcoming">
              <input type="radio" name="status" value="upcoming" <?= $oldStatus === 'upcoming' ? 'checked' : '' ?>> Upcoming
            </label>
            <label class="status-pill status-pill--pending">
              <input type="radio" name="status" value="pending"  <?= $oldStatus === 'pending'  ? 'checked' : '' ?>> Pending
            </label>
          </div>
        </div>

        <div class="listing-field-row" id="opened-row">
          <label class="listing-field-label" for="fl-opened">Opened On</label>
          <div>
            <?php
              $openedRaw = (string) ($listing->opened ?? '');
              $openedDateVal = strlen($openedRaw) >= 10 ? substr($openedRaw, 0, 10) : '';
            ?>
            <input id="fl-opened" type="date" name="opened" class="form form--compact"
                   value="<?= View::e($openedDateVal) ?>">
            <input type="hidden" id="fl-opened-tba" name="opened" value="TBA" disabled>
            <span id="fl-opened-tba-text" class="tba-note">TBA &mdash; date will be set when the listing opens.</span>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-last-updated">Last Updated</label>
          <div>
            <div class="actions-row actions-row--wrap">
              <input id="fl-last-updated" type="date" name="fl_last_updated" class="form form--compact"
                     value="<?= View::e($fl_last_updated ?? '') ?>">
              <button type="button" class="btn btn-tertiary btn-sm" id="set-today-btn">Set Today's Date</button>
              <button type="button" class="btn btn-quaternary btn-sm" id="clear-date-btn">Reset</button>
            </div>
          </div>
        </div>
      </div>


      <div class="pc-section">
        <div class="pc-section-label">Listing Image</div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Image</label>
          <div>
            <?php if (isset($current_image) && $current_image !== null && $current_image !== ''): ?>
              <div class="existing-image-row upload-panel-row">
                <div class="existing-img-placeholder">
                  <img src="<?= $b ?>/uploads/listings/<?= View::e($current_image) ?>"
                       alt="<?= View::e($current_image) ?>">
                </div>
                <div>
                  <div class="file-meta-name"><?= View::e($current_image) ?></div>
                  <label class="del-img-check">
                    <input type="checkbox" id="fl-delete-image" name="delete_image" value="1">
                    Delete current image
                  </label>
                </div>
              </div>
              <p class="replace-note">Replace with new image</p>
            <?php endif; ?>
            <label class="file-drop-zone">
              <input type="file" id="thumbnail" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Click to upload</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
            </label>
          </div>
        </div>
      </div>


      <div class="pc-section">
        <div class="pc-section-label">Preferences</div>
        <?php $lt = $listing_type ?? 'listing'; ?>
        <div class="listing-field-row">
          <label class="listing-field-label" for="fl-listing-type">Type</label>
          <div class="select-wrap select-auto">
            <select id="fl-listing-type" name="listing_type" >
              <option value="listing"    <?= $lt === 'listing'    ? 'selected' : '' ?>>Fanlisting</option>
              <option value="clique"     <?= $lt === 'clique'     ? 'selected' : '' ?>>Clique</option>
              <option value="collective" <?= $lt === 'collective' ? 'selected' : '' ?>>Collective</option>
              <option value="shrine"     <?= $lt === 'shrine'     ? 'selected' : '' ?>>Shrine</option>
              <option value="webring"    <?= $lt === 'webring'    ? 'selected' : '' ?>>Webring</option>
              <option value="other"      <?= $lt === 'other'      ? 'selected' : '' ?>>Other</option>
            </select>
          </div>
        </div>

        <?php $ab = $approved_by ?? ''; ?>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-approved-by">Directory Approval</label>
          <div>
            <div class="select-wrap select-auto">
              <select id="fl-approved-by" name="approved_by">
                <option value=""     <?= $ab === ''     ? 'selected' : '' ?>>Not approved / N/A</option>
                <option value="tfl"  <?= $ab === 'tfl'  ? 'selected' : '' ?>>Approved by TFL</option>
                <option value="tafl" <?= $ab === 'tafl' ? 'selected' : '' ?>>Approved by TAFL</option>
              </select>
            </div>
            <p class="form-hint">Mark whether this fanlisting is officially approved by The Fanlistings Network (TFL) or The Anime Fanlistings Network (TAFL).</p>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-members-per-page">Members per page</label>
          <div>
            <input id="fl-members-per-page" type="number" name="members_per_page" class="form form--compact"
                   min="5" max="200" value="<?= (int) ($members_per_page ?? 25) ?>">
            <p class="form-hint">How many members to show on each admin members page for this listing. Choose 5&ndash;200.</p>
          </div>
        </div>

        <div class="listing-field-row">
          <label class="listing-field-label">Contact Form</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="contact_enabled" value="1"
                     <?= ($contact_enabled ?? true) ? 'checked' : '' ?>>
              <span class="toggle-label">Enable contact form for this listing</span>
            </label>
          </div>
        </div>


        <?php if ($adoption_addon_installed ?? false): ?>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Adoption</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="for_adoption" value="1" id="fl-for-adoption"
                     <?= ($for_adoption ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Accept adoption applications</span>
            </label>
            <p class="form-hint">Show a public adoption form for this listing and collect applications in the admin panel.</p>
            <?php if (($for_adoption ?? false) && ($adoption_count ?? 0) > 0): ?>
            <p class="form-hint">
              <a href="<?= $b ?>/admin/adoptions/<?= (int) $listing->id ?>" class="link">
                View <?= (int) $adoption_count ?> application<?= $adoption_count !== 1 ? 's' : '' ?> &rarr;
              </a>
            </p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Code Donations</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="codes_submit_enabled" value="1" id="fl-codes-submit-enabled"
                     <?= ($embed_settings['codes_submit_enabled'] ?? true) ? 'checked' : '' ?>>
              <span class="toggle-label">Allow fans to donate codes for this listing</span>
            </label>
            <p class="form-hint">Enables the public code submission form. Submissions appear in the Codes tab for review.</p>
            <?php if ($embed_settings['codes_submit_enabled'] ?? true): ?>
            <div style="margin-top:.75rem">
              <?php
                $_cae = $code_approval_email ?? null;
                $_caeVal = $_cae === null ? '' : ($_cae ? '1' : '0');
              ?>
              <div class="select-wrap select-auto">
                <select name="code_approval_email">
                  <option value=""  <?= $_caeVal === ''  ? 'selected' : '' ?>>Follow global setting (email donor on approval)</option>
                  <option value="1" <?= $_caeVal === '1' ? 'selected' : '' ?>>Always email donor on approval</option>
                  <option value="0" <?= $_caeVal === '0' ? 'selected' : '' ?>>Never email donor on approval</option>
                </select>
              </div>
              <p class="form-hint">Controls the <em>Code Donation Approved</em> email for this listing. The global setting is configured in Settings &rarr; General.</p>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Affiliate Applications</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="affiliate_apply_enabled" value="1" id="fl-affiliate-apply-enabled"
                     <?= ($affiliate_apply_enabled ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Accept affiliate applications for this listing</span>
            </label>
            <p class="form-hint">Enables a public application form. Submissions appear in
              <a href="<?= $b ?>/admin/affiliates/pending" class="link">Affiliates &rarr; Pending</a>.
            </p>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Join Settings</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="auto_approve_joins" value="1" id="fl-auto-approve-joins"
                     <?= ($auto_approve_joins ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Auto-approve new joins for this listing</span>
            </label>
            <p class="form-hint">New joins are held for manual approval by default. Enable to approve them automatically for this listing only.</p>
          </div>
        </div>
      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="button" class="btn btn-ghost js-form-reset">Reset</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>
    </div>
  </form>
</div>


<div id="tab-templates" class="tabs-panel">
<form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/templates" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="composer-grid">

    <!-- Left: template editors -->
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('embed') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">HTML Templates</div>
          <div class="pc-head-sub">Choose the HTML Luster prints for this listing's members, affiliates, and stats.</div>
        </div>
      </div>

      <div class="pc-section">
        <div class="pc-section-label">Members List</div>
        <div class="form-group">
          <textarea id="members_template" name="members_template" class="form tpl-textarea" spellcheck="false"><?= View::e($templates['members_template'] ?? '<li><a href="{fan_url}">{fan_name}</a> - {fan_country}</li>') ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-sm btn-tpl-reset" data-target="members_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Affiliates Template</div>
        <p class="form-hint mb-sm">Controls the full affiliates section for this listing. Use <code>{each_affiliate}&hellip;{/each_affiliate}</code> when you want to design each row yourself, or <code>{affiliates}</code> when you want Luster to drop in the ready-made rows.</p>
        <div class="form-group">
          <textarea id="affiliates_section_template" name="affiliates_section_template" class="form tpl-textarea tpl-textarea-lg" spellcheck="false"><?= View::e($templates['affiliates_section_template'] ?: '{if_affiliates}<ul>
{each_affiliate}<li><a href="{aff_url}" target="_blank" title="{aff_name}">{aff_image_tag}</a></li>{/each_affiliate}
</ul>{/if_affiliates}') ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-quaternary btn-sm btn-tpl-reset" data-target="affiliates_section_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Statistics</div>
        <div class="form-group">
          <textarea id="stats_template" name="stats_template" class="form tpl-textarea tpl-textarea-lg" spellcheck="false"><?= View::e($templates['stats_template'] ?? '<div><b>Opened:</b> {stat_opened}<br>
<b>Updated:</b> {stat_last_updated}<br>
<b>Fans:</b> {stat_approved} (+ {stat_pending} pending)<br>
<b>Newest Fan:</b> {stat_newmembers}<br>
<b>Growth:</b> {stat_growth}{if_approved_by}<br>
<b>Approved by:</b> {approved_by}{/if_approved_by}</div>') ?></textarea>
        </div>
        <div class="tpl-reset-row">
          <button type="button" class="btn btn-ghost btn-sm btn-tpl-reset" data-target="stats_template">Reset to Default</button>
        </div>
      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Templates</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>
    </div>

    <!-- Right: token reference -->
    <div class="composer-side">
      <details class="card chevron" open>
        <summary class="chevron-head">Members List Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm">A token is a placeholder. Luster replaces it with each member's real information when the members list loads.</p>
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="members_template" data-token="{fan_name}"><td><code>{fan_name}</code></td><td>The member's display name.</td></tr>
              <tr class="tok-row" data-textarea="members_template" data-token="{fan_email}"><td><code>{fan_email}</code></td><td>The member's email address. Only use this if you want emails visible on your public site.</td></tr>
              <tr class="tok-row" data-textarea="members_template" data-token="{fan_url}"><td><code>{fan_url}</code></td><td>The member's website link.</td></tr>
              <tr class="tok-row" data-textarea="members_template" data-token="{fan_country}"><td><code>{fan_country}</code></td><td>The member's country.</td></tr>
              <tr><td><code>{fan_field_name}</code></td><td>Additional field values use the field name after <code>fan_</code>. For a field named <code>fave_song</code>, use <code>{fan_fave_song}</code>.</td></tr>
            </tbody>
          </table>
        </div>
      </details>

      <details class="card chevron" open>
        <summary class="chevron-head">Affiliates Template Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint" style="font-size:0.8em;margin-bottom:0.4em">Use these inside <code>{each_affiliate}&hellip;{/each_affiliate}</code>. Luster repeats that block once for each affiliate.</p>
          <table class="et-token-table">
            <tbody>
              <tr><td><code>{each_affiliate}&hellip;{/each_affiliate}</code></td><td>Repeats the HTML inside it once for each affiliate being shown.</td></tr>
              <tr class="tok-row" data-textarea="affiliates_section_template" data-token="{aff_name}"><td><code>{aff_name}</code></td><td>The affiliate site's name.</td></tr>
              <tr class="tok-row" data-textarea="affiliates_section_template" data-token="{aff_url}"><td><code>{aff_url}</code></td><td>The affiliate site's link.</td></tr>
              <tr class="tok-row" data-textarea="affiliates_section_template" data-token="{aff_image_tag}"><td><code>{aff_image_tag}</code></td><td>A ready-made <code>&lt;img&gt;</code> tag for the affiliate image. Empty when there is no image.</td></tr>
              <tr class="tok-row" data-textarea="affiliates_section_template" data-token="{aff_image_url}"><td><code>{aff_image_url}</code></td><td>The full image link. Empty when there is no image.</td></tr>
              <tr class="tok-row" data-textarea="affiliates_section_template" data-token="{aff_image}"><td><code>{aff_image}</code></td><td>The saved image filename only. Most templates should use <code>{aff_image_tag}</code> instead.</td></tr>
            </tbody>
          </table>
          <p class="form-hint" style="font-size:0.8em;margin-top:0.75em;margin-bottom:0.4em">Use these outside the affiliate row loop.</p>
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="affiliates_section_template" data-token="{affiliates}"><td><code>{affiliates}</code></td><td>Ready-made affiliate rows. Handy when you do not want to design each row yourself.</td></tr>
              <tr class="tok-row" data-textarea="affiliates_section_template" data-token="{affiliates_count}"><td><code>{affiliates_count}</code></td><td>How many active affiliates this listing has.</td></tr>
              <tr class="tok-row" data-textarea="affiliates_section_template" data-token="{affiliates_list}"><td><code>{affiliates_list}</code></td><td>A simple comma-separated list of linked affiliate names.</td></tr>
              <tr><td><code>{if_affiliates}&hellip;{/if_affiliates}</code></td><td>Shows the HTML inside only when this listing has at least one affiliate.</td></tr>
              <tr><td><code>{if_no_affiliates}&hellip;{/if_no_affiliates}</code></td><td>Shows the HTML inside only when this listing has no affiliates.</td></tr>
            </tbody>
          </table>
        </div>
      </details>

      <details class="card chevron" open>
        <summary class="chevron-head">Statistics Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm">These fill in the public stats block for this one listing.</p>
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_opened}"><td><code>{stat_opened}</code></td><td>The date you opened the listing.</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_updated}"><td><code>{stat_updated}</code></td><td>The date the newest approved member joined.</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_last_updated}"><td><code>{stat_last_updated}</code></td><td>The Last Updated date you set for this listing in Luster.</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_approved}"><td><code>{stat_approved}</code></td><td>How many approved members this listing has.</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_pending}"><td><code>{stat_pending}</code></td><td>How many members are waiting for approval.</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_newmembers}"><td><code>{stat_newmembers}</code></td><td>The names of the 5 newest approved members.</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_growth}"><td><code>{stat_growth}</code></td><td>Average new members per day since the listing opened.</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{listing_title}"><td><code>{listing_title}</code></td><td>The listing's display name.</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{listing_url}"><td><code>{listing_url}</code></td><td>The link to the listing.</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{approved_by}"><td><code>{approved_by}</code></td><td>The network that approved the listing, if one is saved.</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{if_approved_by}"><td><code>{if_approved_by}&hellip;{/if_approved_by}</code></td><td>Shows the HTML inside only when an approved-by network is saved.</td></tr>
            </tbody>
          </table>
        </div>
      </details>
    </div>

  </div>
</form>

<script type="application/json" id="luster-tpl-defaults">{"members_template":"<li><a href=\"{fan_url}\">{fan_name}<\/a> - {fan_country}<\/li>","affiliates_section_template":"{if_affiliates}<ul>\n{each_affiliate}<li><a href=\"{aff_url}\" target=\"_blank\" title=\"{aff_name}\">{aff_image_tag}<\/a><\/li>{\/each_affiliate}\n<\/ul>{\/if_affiliates}","stats_template":"<div>\n<b>Opened:<\/b> {stat_opened}<br>\n<b>Updated:<\/b> {stat_last_updated}<br>\n<b>Fans:<\/b> {stat_approved} (+ {stat_pending} pending)<br>\n<b>Newest Fan:<\/b> {stat_newmembers}<br>\n<b>Growth:<\/b> {stat_growth}{if_approved_by}<br>\n<b>Approved by:<\/b> {approved_by}{\/if_approved_by}\n<\/div>"}</script>
</div><!-- /#tab-templates -->
<!-- ──── TAB: ADDITIONAL FIELDS ─────────────────────────────────────── -->
<div id="tab-fields" class="tabs-panel">

  <?php if ($listing->dbprefix === ''): ?>
  <div class="card">
    <p class="text-muted">Additional fields can be added after Luster creates the member storage for this listing.</p>
  </div>
  <?php else: ?>

  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/add" id="fields-add-form" class="js-warn-unsaved">
    <input type="hidden" name="_csrf"     value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="from_edit" value="1">
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('add') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">Add New Field</div>
          <div class="pc-head-sub">Create an extra member field for this listing.</div>
        </div>
      </div>
      <div class="listing-field-section">
        <div class="callout callout-info"><?= View::icon('notes') ?><div><strong>Rules:</strong> Use letters, numbers, and underscores only. No spaces. Keep it under 30 characters, and choose a name this listing does not already use.</div></div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="af-name">Field Name <span class="req">*</span></label>
          <div>
            <input type="text" class="form" id="af-name" name="name"
                   maxlength="30" pattern="[a-zA-Z0-9_]+"
                   placeholder="e.g. fave_song" required
                   title="Letters, numbers and underscores only">
            <p class="form-hint">Short internal name for this field. Members will not see this exact name; they see the label below.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="af-label">Label <span class="req">*</span></label>
          <div>
            <input type="text" class="form" id="af-label" name="label"
                   maxlength="100" placeholder="e.g. Favourite Song" required>
            <p class="form-hint">The label members will see on your join form and members pages.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="af-type">Type</label>
          <div>
            <div class="select-wrap">
              <select class="form" id="af-type" name="type">
                <option value="text">Text Field</option>
                <option value="dropdown">Dropdown</option>
                <option value="checkbox">Checkbox</option>
                <option value="radio">Radio Button</option>
              </select>
            </div>
          </div>
        </div>
        <div id="af-options-row" class="listing-field-row listing-field-row--top is-hidden">
          <label class="listing-field-label" for="af-options">Options</label>
          <div>
            <textarea class="form" id="af-options" name="options" rows="2"
                      placeholder="e.g. Option A, Option B, Option C"></textarea>
            <p class="form-hint">For dropdown, checkbox, and radio fields, write the choices separated by commas.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <span class="listing-field-label">Required</span>
          <div>
            <div class="flex-row">
              <label class="form-check">
                <input type="radio" name="required" value="1">
                <span class="form-check-label">Yes</span>
              </label>
              <label class="form-check">
                <input type="radio" name="required" value="0" checked>
                <span class="form-check-label">No</span>
              </label>
            </div>
            <p class="form-hint">Choose Yes when members must answer this before they can submit the form.</p>
          </div>
        </div>
      </div>
      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Add Field</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
      </div>
    </div>
  </form>

  <div class="card">
    <div class="card-head">Current Fields</div>
    <?php if (empty($fields)): ?>
      <div class="empty">
        <span class="empty-icon"><?= View::icon('tag', ['width' => 32, 'height' => 32]) ?></span>
        No additional fields defined yet.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Label</th>
              <th>Field Name</th>
              <th>Type</th>
              <th>Required</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fields as $field): ?>
            <?php $_typeLabels = ['text' => 'Text Field', 'dropdown' => 'Dropdown', 'checkbox' => 'Checkbox', 'radio' => 'Radio Button']; ?>
            <tr>
              <td class="text-strong"><?= View::e($field->label) ?></td>
              <td><code class="neb-code"><?= View::e($field->name) ?></code></td>
              <td class="text-muted"><?= View::e($_typeLabels[$field->type] ?? ucfirst($field->type)) ?></td>
              <td>
                <?php if ($field->required): ?>
                  <span class="badge badge-current">Yes</span>
                <?php else: ?>
                  <span class="badge badge-inactive">No</span>
                <?php endif; ?>
              </td>
              <td class="act-col">
                <div class="data-actions">
                  <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/<?= urlencode($field->name) ?>/edit"
                     class="btn btn-tertiary btn-sm">Edit</a>
                  <form method="POST"
                        action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/<?= urlencode($field->name) ?>/delete"
                        class="action-form-inline"
                        data-del-modal data-del-entity="field"
                        data-del-name="<?= View::e($field->name) ?>">
                    <input type="hidden" name="_csrf"     value="<?= View::e($csrf_token ?? '') ?>">
                    <input type="hidden" name="from_edit" value="1">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  </form>
  <?php endif; ?>
</div>
<!-- ──── TAB: CODES ─────────────────────────────────────────────────── -->
<div id="tab-codes" class="tabs-panel">
<?php
// Unpack code variables for convenience
$_fid          = (int) $listing->id;
$_hasCats      = !empty($code_cats);
$_hasDonors    = !empty($code_donors);
$_pendingCount = $code_status_counts['pending'] ?? 0;
$_approvedCount = $code_status_counts['approved'] ?? 0;
$_nonEmptyGroups = array_filter($code_grouped, fn($g) => !empty($g['codes']));
$_groupCount   = count($_nonEmptyGroups);
$_topLevelCats = array_filter($code_cats, fn($c) => $c->parentId === null);
$_tabBase      = '?tab=codes';
if ($_hasCats || $_hasDonors) {
    $_settingsSummary = count($code_cats) . ' categor' . (count($code_cats) !== 1 ? 'ies' : 'y')
        . ' &middot; ' . count($code_donors) . ' donor' . (count($code_donors) !== 1 ? 's' : '');
} else {
    $_settingsSummary = 'categories &amp; donors';
}
$_settingsOpen = $_hasCats || $_hasDonors;
?>

<?php if ($_pendingCount > 0): ?>
<div class="reminder-banner banner-spaced" data-codes-pending-banner>
  <div class="reminder-banner-head">
    <div class="reminder-banner-left">
      <span class="reminder-banner-icon"><?= View::icon('bell') ?></span>
      <div>
        <span class="reminder-banner-title" data-codes-pending-title><?= $code_status_filter === 'pending' ? 'Showing pending codes' : 'Codes awaiting review' ?></span>
        <span class="reminder-banner-msg" data-codes-pending-msg"><?= $_pendingCount ?> code<?= $_pendingCount !== 1 ? 's are' : ' is' ?> pending approval</span>
      </div>
    </div>
    <a href="<?= $code_status_filter === 'pending' ? $_tabBase . '&status_filter=approved' : $_tabBase . '&status_filter=pending' ?>" class="btn btn-tertiary btn-sm" data-code-filter="<?= $code_status_filter === 'pending' ? 'approved' : 'pending' ?>" data-codes-pending-action><?= $code_status_filter === 'pending' ? '&larr; Show approved' : 'Review pending &rarr;' ?></a>
  </div>
</div>
<?php endif; ?>

<details class="card chevron" <?= $_settingsOpen ? 'open' : '' ?> id="settingsBand" open>
  <summary class="card-head">Code Categories & Donors</summary>

  <div class="settings-band-grid">
    <div class="settings-band-col">
      <div class="pc-section-label">Categories</div>
      <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/categories/add" class="codes-form-inline">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <div class="codes-form-grow">
          <input class="form" type="text" name="name" placeholder="Category name&hellip;" required>
        </div>
        <?php if (!empty($_topLevelCats)): ?>
        <div class="select-wrap codes-select-wrap">
          <select name="parent_id" >
            <option value="">None (top-level)</option>
            <?php foreach ($_topLevelCats as $tlc): ?>
              <option value="<?= (int)$tlc->id ?>"><?= View::e($tlc->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary"><?= View::icon('add') ?> Add</button>
      </form>
      <?php if ($_hasCats): ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr>
              <th>Name</th>
              <th>Amount</th>
              <th>Parent</th>
              <th>Actions</th>
            </tr></thead>
          <tbody>
            <?php foreach ($code_cats as $cat): ?>
            <?php $_isCatEditing = $code_edit_cat_id === $cat->id; ?>
            <tr<?= $_isCatEditing ? ' class="table-row-hidden"' : '' ?>>
              <td class="text-strong"><?= View::e($cat->name) ?></td>
              <td>
                <?php $_catCodeCount = count(array_filter($code_category_ids, fn($ids) => in_array($cat->id, $ids, true))); ?>
                <span class="badge badge-info"><?= $_catCodeCount ?> code<?= $_catCodeCount !== 1 ? 's' : '' ?></span>
              </td>
              <td>
                <?php if ($cat->parentId !== null && isset($code_cat_map[$cat->parentId])): ?>
                  <span class="text-dim"><?= View::e($code_cat_map[$cat->parentId]->name) ?></span>
                <?php else: ?>
                  <span class="text-dim">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="act-col">
                <a href="<?= $_tabBase ?>&edit_cat=<?= (int)$cat->id ?>" class="btn btn-tertiary btn-sm">Edit</a>
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/categories/<?= (int)$cat->id ?>/delete"
                      class="action-form-inline" data-del-modal data-del-entity="code_category" data-del-name="<?= View::e($cat->name) ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <button class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <tr<?= !$_isCatEditing ? ' class="table-row-hidden"' : '' ?>>
              <td colspan="4">
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/categories/<?= (int)$cat->id ?>/edit" class="codes-form-actions">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <div class="codes-form-grow-sm">
                    <input class="form" type="text" name="name" value="<?= View::e($cat->name) ?>" required>
                  </div>
                  <div>
                    <div class="select-wrap">
                      <select name="parent_id" class="select-xs">
                        <option value="">None (top-level)</option>
                        <?php foreach ($code_cats as $pcat): ?>
                          <?php if ($pcat->id === $cat->id || $pcat->parentId !== null) continue; ?>
                          <option value="<?= (int)$pcat->id ?>" <?= $cat->parentId === $pcat->id ? 'selected' : '' ?>>
                            <?= View::e($pcat->name) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <button class="btn btn-primary btn-sm">Save</button>
                  <a href="<?= $_tabBase ?>" class="btn btn-quaternary btn-sm">Cancel</a>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="settings-band-col">
      <div class="pc-section-label codes-section-label">Donors</div>
      <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/donors/add" class="codes-form-inline">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <div class="codes-form-grow-sm"><input type="text" class="form" name="name" placeholder="Display name&hellip;" required></div>
        <div class="codes-form-grow-sm"><input type="email" class="form" name="email" placeholder="Email (optional)"></div>
        <div class="codes-form-grow-sm"><input type="url" class="form" name="url" placeholder="URL (optional)"></div>
        <button type="submit" class="btn btn-primary"><?= View::icon('add') ?> Add</button>
      </form>
      <?php if ($_hasDonors): ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Name</th><th>Email</th><th>URL</th><th># Donated</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($code_donors as $dn): ?>
            <?php $_isDnEditing = $code_edit_donor_id === $dn->id; ?>
            <tr<?= $_isDnEditing ? ' class="table-row-hidden"' : '' ?>>
              <td class="text-strong"><?= View::e($dn->name) ?></td>
              <td class="text-dim"><?= $dn->email !== null ? View::emailWrap($dn->email) : '&mdash;' ?></td>
              <td class="text-dim">
                <?php if ($dn->url !== null): ?>
                  <?php $_dnHost = parse_url($dn->url, PHP_URL_HOST) ?? $dn->url; ?>
                  <a href="<?= View::e($dn->url) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($_dnHost) ?></a>
                <?php else: ?>&mdash;<?php endif; ?>
              </td>
              <td><span class="badge badge-info"><?= count(array_filter($codes, fn($c) => $c->donorId === $dn->id)) ?></span></td>
              <td class="act-col">
                <a href="<?= $_tabBase ?>&edit_donor=<?= (int)$dn->id ?>" class="btn btn-tertiary btn-sm">Edit</a>
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/donors/<?= (int)$dn->id ?>/delete"
                      class="action-form-inline" data-del-modal data-del-entity="code_donor" data-del-name="<?= View::e($dn->name) ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <button class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <tr<?= !$_isDnEditing ? ' class="table-row-hidden"' : '' ?>>
              <td colspan="5" class="table-edit-cell">
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/donors/<?= (int)$dn->id ?>/edit" class="codes-form-actions">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <div class="codes-form-grow-sm"><input class="form" type="text" name="name" value="<?= View::e($dn->name) ?>" required></div>
                  <div class="codes-form-grow-sm"><input class="form" type="email" name="email" value="<?= View::e($dn->email ?? '') ?>" placeholder="Email"></div>
                  <div class="codes-form-grow-sm"><input class="form" type="url" name="url" value="<?= View::e($dn->url ?? '') ?>" placeholder="URL"></div>
                  <button class="btn btn-primary btn-sm">Save</button>
                  <a href="<?= $_tabBase ?>" class="btn btn-quaternary btn-sm">Cancel</a>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</details>

<form id="codes-bulk-form" method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/bulk">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
</form>
<form id="codes-reorder-form" method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/reorder">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" id="reorder-ids" name="ordered_ids" value="">
</form>

<?php foreach ($codes as $code): ?>
<?php
  $_dDonor = ($code->donorId !== null && isset($code_donor_map[$code->donorId])) ? $code_donor_map[$code->donorId] : null;
  $_dLabel = ($code->name !== null && $code->name !== '') ? $code->name : null;
?>
<dialog id="edit-dialog-<?= (int)$code->id ?>" class="modal">
  <div class="modal-header modal-header-sticky">
    <span class="modal-title">
      Edit code<?= $_dLabel !== null ? ' &mdash; ' . View::e($_dLabel) : ' #' . (int)$code->id ?>
    </span>
    <button type="button" class="modal-close" data-close-dialog aria-label="Close">&times;</button>
  </div>
  <div class="modal-body">
    <div class="code-preview">
      <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
           alt="Code #<?= (int)$code->id ?>"
           class="code-preview-img">
      <?php if ($code->width > 0 && $code->height > 0): ?>
      <div class="code-preview-meta"><?= (int)$code->width ?>&times;<?= (int)$code->height ?>px</div>
      <?php endif; ?>
    </div>
    <form method="POST"
          action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/<?= (int)$code->id ?>/update"
          class="code-modal-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <div>
        <label class="form-label code-modal-label">Label (optional)</label>
        <input class="form" type="text" name="name" value="<?= View::e($code->name ?? '') ?>" placeholder="e.g. Animated version">
      </div>
      <div>
        <label class="form-label code-modal-label">Status</label>
        <div class="select-wrap">
          <select name="status" >
            <option value="approved" <?= $code->status === 'approved' ? 'selected' : '' ?>>&#10003; Live</option>
            <option value="pending"  <?= $code->status === 'pending'  ? 'selected' : '' ?>>&#8987; Pending</option>
          </select>
        </div>
      </div>
      <?php if ($_hasDonors): ?>
      <div>
        <label class="form-label code-modal-label">Donor</label>
        <div class="select-wrap">
          <select name="donor_id" >
            <option value="">No donor</option>
            <?php foreach ($code_donors as $dn): ?>
              <option value="<?= (int)$dn->id ?>" <?= $code->donorId === $dn->id ? 'selected' : '' ?>><?= View::e($dn->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($_hasCats): ?>
      <div>
        <label class="form-label code-modal-label">Category</label>
        <div class="select-wrap">
          <select name="cat_ids[]" >
            <option value="">None</option>
            <?php
              $_dCatIds = $code_category_ids[$code->id] ?? [];
              foreach ($code_cats as $cat):
            ?>
              <option value="<?= (int)$cat->id ?>" <?= in_array($cat->id, $_dCatIds, true) ? 'selected' : '' ?>>
                <?= $cat->parentId !== null ? '&#x2514; ' : '' ?><?= View::e($cat->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($code->submitterName !== null): ?>
      <div>
        <label class="form-label code-modal-label">Submitter website</label>
        <input class="form" type="url" name="submitter_url" value="<?= View::e($code->submitterUrl ?? '') ?>" placeholder="https://example.com">
      </div>
      <?php endif; ?>
      <button class="btn btn-primary btn-block-center">Save changes</button>
    </form>
    <div class="code-modal-footer">
      <form method="POST"
            action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/<?= (int)$code->id ?>/delete"
            data-del-modal data-del-entity="code"
            data-del-name="<?= View::e($_dLabel !== null ? $_dLabel : 'Code #' . (int)$code->id) ?>">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <button type="submit" class="btn btn-danger btn-sm btn-block-center">Delete this code</button>
      </form>
    </div>
  </div>
</dialog>
<?php endforeach; ?>

<div class="mini-nav-layout">
    <div class="panel-col">
    <div class="panel-card">
      <div class="card-head">Upload Codes</div>
      <div class="pc-section">
        <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/upload" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
          <div class="file-drop-zone">
            <input type="file" id="codes-input" name="codes[]" multiple accept="image/jpeg,image/png,image/gif,image/webp">
            <span class="file-drop-icon"><?= View::icon('image') ?></span>
            <strong>Click or drop images</strong>
            <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 500 KB each</span>
          </div>
          <?php if ($_hasCats): ?>
          <div class="panel-field upload-panel-row">
            <label class="panel-label" for="upload-category">Category</label>
            <div class="select-wrap">
              <select id="upload-category" name="cat_ids[]">
                <option value="">None</option>
                <?php foreach ($code_cats as $cat): ?>
                  <option value="<?= (int)$cat->id ?>"><?= $cat->parentId !== null ? '&#x2514; ' : '' ?><?= View::e($cat->name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php endif; ?>
          <?php $_editSubmitters = $code_submitter_names ?? []; ?>
          <div class="panel-field upload-panel-row">
            <label class="panel-label" for="upload-donor">Donor</label>
            <div class="select-wrap">
              <select id="upload-donor" name="donor_id">
                <option value="">None</option>
                <?php if (!empty($code_donors)): ?>
                  <?php if (!empty($_editSubmitters)): ?><optgroup label="Registered Donors"><?php endif; ?>
                  <?php foreach ($code_donors as $dn): ?>
                    <option value="<?= (int)$dn->id ?>"><?= View::e($dn->name) ?></option>
                  <?php endforeach; ?>
                  <?php if (!empty($_editSubmitters)): ?></optgroup><?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($_editSubmitters)): ?>
                  <optgroup label="Past Submitters">
                    <?php foreach ($_editSubmitters as $sn): ?>
                      <option value="s:<?= View::e($sn) ?>"><?= View::e($sn) ?></option>
                    <?php endforeach; ?>
                  </optgroup>
                <?php endif; ?>
              </select>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-full">Upload</button>
          <p class="panel-hint panel-hint-spaced">Dimensions are detected automatically. Settings above apply to all files in this batch.</p>
        </form>
      </div>
    </div>
    <?php if ($_approvedCount > 0): ?>
    <a href="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/export" class="btn btn-quaternary btn-full"><span><?= View::icon('download') ?> Export ZIP</span><span class="card-head-sub">Download all approved codes in a ZIP file.</span></a>
    <?php endif; ?>
    </div>

  <div class="gradient-card codes-browser" data-initial-code-filter="<?= View::e($code_status_filter) ?>">
    <div class="card-head">Current Codes <span class="badge badge-inactive"><?= $_approvedCount ?> Approved</span>
      <?php if ($_pendingCount > 0): ?>
        <span class="badge badge-pending"><?= $_pendingCount ?> Pending</span>
      <?php endif; ?>
      <?php if ($_groupCount > 0): ?><span class="card-head-sub">Click and drag the top left corner to reorder codes &mdash; changes are effective immediately.</span><?php endif; ?>
    </div>

    <div class="codes-tabs">
      <a href="<?= $_tabBase ?>&status_filter=approved" class="tabs-btn<?= $code_status_filter === 'approved' ? ' active' : '' ?>" data-code-filter="approved">
        Approved <span class="badge badge-<?= $_approvedCount > 0 ? 'current' : 'inactive' ?>"><?= $_approvedCount ?></span>
      </a>
      <a href="<?= $_tabBase ?>&status_filter=pending" class="tabs-btn<?= $code_status_filter === 'pending' ? ' active' : '' ?>" data-code-filter="pending">
        Pending <span class="badge badge-<?= $_pendingCount > 0 ? 'pending' : 'inactive' ?>"><?= $_pendingCount ?></span>
      </a>
    </div>

    <div class="codes-bulk">
      <label class="codes-bulk-label">
        <input type="checkbox" id="codes-select-all">Select all </label>
      <div class="select-wrap">
        <select id="bulk-action-sel" class="select-xs">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="delete">Delete</option>
          <option value="approve">Approve</option>
          <option value="reject">Reject</option>
          <?php if ($_hasCats): ?>
            <option value="categorize">Add to Categories&hellip;</option>
          <?php endif; ?>
        </select>
      </div>
      <?php if ($_hasCats): ?>
      <div id="bulk-cats-wrap" class="is-hidden">
        <select id="bulk-cats-sel" name="bulk_cat_ids[]" multiple size="<?= min(4, count($code_cats)) ?>" >
          <?php foreach ($code_cats as $cat): ?>
            <option value="<?= (int)$cat->id ?>"><?= $cat->parentId !== null ? '  &#x2514; ' : '' ?><?= View::e($cat->name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <button type="button" id="bulk-apply-btn" class="btn btn-quaternary btn-sm">Apply to Selected</button>
      <div class="codes-bulk-right">
        <button type="button" id="save-order-btn" class="btn btn-quaternary btn-sm is-hidden">Save Order</button>
      </div>
    </div>

    <?php if ($total_codes === 0): ?>
      <div class="card-empty-state">
        <div class="empty">
          <span class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></span>
          No codes yet.
        </div>
      </div>
    <?php else: ?>
      <div class="card-empty-state<?= ($code_status_filter === 'approved' && $_approvedCount === 0) || ($code_status_filter === 'pending' && $_pendingCount === 0) ? '' : ' is-hidden' ?>" id="codes-filter-empty">
        <div class="empty">
          <span class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></span>
          No codes match this filter &mdash; <a href="<?= $_tabBase ?>&status_filter=approved" class="link" data-code-filter="approved">show approved</a>.
        </div>
      </div>
        <?php foreach ($code_grouped as $group): ?>
          <?php if (empty($group['codes'])) continue; ?>
          <?php
            $firstCode = reset($group['codes']);
            $gridMin   = max((int)$firstCode->width + 32, 80) . 'px';
          ?>
          <div class="pc-section" data-code-group>
            <div class="pc-section-label"><?= View::e($group['label']) ?>
              <span class="badge badge-inactive" data-code-group-count data-total-count="<?= count($group['codes']) ?>"><?= count($group['codes']) ?> code<?= count($group['codes']) !== 1 ? 's' : '' ?></span>
            </div>
            <div class="code-grid" data-code-group-grid style="grid-template-columns:repeat(auto-fill,minmax(<?= $gridMin ?>,1fr))">
            <?php foreach ($group['codes'] as $code): ?>
            <?php
              $_codeCatIds = $code_category_ids[$code->id] ?? [];
              $_codeDonor  = ($code->donorId !== null && isset($code_donor_map[$code->donorId])) ? $code_donor_map[$code->donorId] : null;
              $_codeLabel  = ($code->name !== null && $code->name !== '') ? $code->name : null;
              $_isDonated  = $_codeDonor !== null || $code->submitterName !== null;
            ?>
            <div class="code-tile code-card<?= $_isDonated ? : '' ?>"
                 data-code-status="<?= View::e($code->status) ?>"
                 data-code-id="<?= (int)$code->id ?>">
              <input type="checkbox" form="codes-bulk-form" name="selected[]" value="<?= (int)$code->id ?>" class="code-tile-cb code-check">
              <span class="code-drag-handle drag-handle" title="Drag to reorder">&#8942;&#8942;</span>
              <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
                   alt="<?= (int)$code->width ?>&times;<?= (int)$code->height ?>px"
                   class="code-tile-img" loading="lazy">
              <?php if ($_codeLabel !== null): ?>
              <div class="text-muted"><?= View::e($_codeLabel) ?></div>
              <?php endif; ?>
              <?php if ($_codeDonor !== null): ?>
              <div class="badge badge-info-pink">&hearts; <?= View::e($_codeDonor->name) ?></div>
              <?php elseif ($code->submitterName !== null): ?>
              <div class="badge badge-info-pink">&hearts; <?= View::e($code->submitterName) ?></div>
              <?php endif; ?>
              <?php if ($code->status === 'pending'): ?>
              <span class="badge badge-pending">Pending</span>
              <?php endif; ?>
              <button type="button" class="btn btn-quaternary btn-sm" data-dialog="edit-dialog-<?= (int)$code->id ?>">
                Edit
              </button>
            </div>
            <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</div>
<!-- ──── TAB: AFFILIATES ────────────────────────────────────────────── -->
<div id="tab-affiliates" class="tabs-panel">
<div class="toolbar" style="justify-content:flex-end;margin-bottom:1rem">
  <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/affiliates/add"
     class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add New Affiliate</a>
</div>

<?php
  $_affFid       = (int) $listing->id;
  // Filter out rejected affiliates for the embedded table
  $_affFiltered  = array_values(array_filter($affiliates, fn($a) => $a->status !== 'rejected'));
  // Variables expected by affiliates/index.php
  $affiliates         = $_affFiltered;
  $tab                = 'all';
  $scope              = (string) $_affFid;
  $search             = '';
  $sort               = 'sort_order';
  $dir                = 'asc';
  $page               = 1;
  $per_page           = max(1, count($affiliates));
  $total              = count($affiliates);
  $total_pages        = 1;
  $rangeStart         = $total > 0 ? 1 : 0;
  $rangeEnd           = $total;
  $counts             = ['all' => $total, 'pending' => 0, 'approved' => 0, 'inactive' => 0, 'rejected' => 0];
  $conflict_ids       = [];
  $listing_map        = [$_affFid => $listing];
  $listings           = [];
  $filters_active     = false;
  $scope_counts       = [];
  $returnUrl          = $b . '/admin/listings/' . $_affFid . '?tab=affiliates';
  $_aff_table_only    = true;
  include LUSTER_BASE . '/templates/admin/affiliates/index.php';
  unset($affiliates, $tab, $scope, $search, $sort, $dir, $page, $per_page, $total, $total_pages,
        $rangeStart, $rangeEnd, $counts, $conflict_ids, $listing_map, $listings,
        $filters_active, $scope_counts, $returnUrl, $_aff_table_only, $_affFiltered, $_tableOnly);
  $affiliates = $affiliates ?? [];  // restore for any subsequent use
?>
</div>
<!-- ──── TAB: EMBED ─────────────────────────────────────────────────── -->
<div id="tab-embed" class="tabs-panel">
<?php
/** @var array  $embed_settings */
/** @var array  $cfg */
$embedSettings  = $embed_settings ?? [];
$installPath    = rtrim((string) ($cfg['install_path']   ?? ''), '/');
$embedBaseUrl   = rtrim((string) ($cfg['embed_base_url'] ?? ''), '/') ?: rtrim((string) ($cfg['site_url'] ?? ''), '/');
?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('embed') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Embed Settings</div>
      <div class="pc-head-sub">Tell Luster which public pages hold this listing's forms, and get the include snippets for your site.</div>
    </div>
  </div>
  
    <div class="pc-section">
    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/embed" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

      <div class="pc-section-label">Form Page URLs</div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-join">Join Form</label>
        <div>
          <input id="fl-form-join" type="text" name="form_url_join" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_join'] !== '' ? $embedSettings['form_url_join'] : 'join.php') ?>">
          <p class="form-hint">The public page on your site where visitors can join this listing.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-update">Update Membership</label>
        <div>
          <input id="fl-form-update" type="text" name="form_url_update" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_update'] !== '' ? $embedSettings['form_url_update'] : 'update.php') ?>">
          <p class="form-hint">The public page where members can update their information.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-remove">Remove Membership</label>
        <div>
          <input id="fl-form-remove" type="text" name="form_url_remove" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_remove'] !== '' ? $embedSettings['form_url_remove'] : 'remove.php') ?>">
          <p class="form-hint">The public page where members can remove themselves from this listing.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-reset">Password Reset</label>
        <div>
          <input id="fl-form-reset" type="text" name="form_url_reset" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_reset'] !== '' ? $embedSettings['form_url_reset'] : 'reset.php') ?>">
          <p class="form-hint">The public page where members can request or finish a password reset.</p>
        </div>
      </div>

      <div class="listing-field-row" id="fl-aff-form-url-row"
           <?= ($affiliate_apply_enabled ?? false) ? '' : 'style="display:none"' ?>>
        <label class="listing-field-label" for="fl-form-url-aff-apply">Affiliate Applications</label>
        <div>
          <input id="fl-form-url-aff-apply" type="text" name="form_url_affiliate_apply" class="form"
                 maxlength="500"
                 value="<?= View::e(($form_url_affiliate_apply ?? '') !== '' ? $form_url_affiliate_apply : 'affiliate-apply.php') ?>">
          <p class="form-hint">The public page where other sites can apply to affiliate with this listing. Leave blank to use Luster's built-in application page.</p>
        </div>
      </div>

      <div class="listing-field-row" id="fl-codes-submit-embed-row"
           <?= ($embedSettings['codes_submit_enabled'] ?? true) ? '' : 'style="display:none"' ?>>
        <label class="listing-field-label" for="fl-form-codes-submit">Code Donation</label>
        <div>
          <input id="fl-form-codes-submit" type="text" name="form_url_codes_submit" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_codes_submit'] !== '' ? $embedSettings['form_url_codes_submit'] : 'codes-submit.php') ?>">
          <p class="form-hint">The public page where visitors can donate codes for this listing.</p>
        </div>
      </div>
    </div>

  <div class="composer-footer">
    <button type="submit" class="btn btn-primary">Save Embed Settings</button>
    <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
  </div>
  </form>
  </div>

<!-- ──── EMBED SNIPPETS ───────────────────────────────────────────── -->
<details class="chevron card" style="margin-bottom:1.75rem">
  <summary class="card-head">Embed Snippets</summary>
  <div class="card-body">
    <?php if ($installPath !== ''): ?>
      <?php
        $_es = $embedSettings ?? [];
        $_snipPath = View::e($installPath);
        $_snipId   = (int) $listing->id;
        // Helper to render one snippet row
        $snip = static function (string $label, string $file) use ($_snipPath, $_snipId, $b): void {
            echo '<div class="listing-field-row listing-field-row--top">';
            echo '<span class="listing-field-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '<div><div class="ro-field embed-snippet">';
            echo '$luster_fanlisting_id = ' . $_snipId . ';<br>';
            echo "require '" . $_snipPath . '/public/embed/' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . "';";
            echo '</div></div></div>';
        };
      ?>

      <details class="snip-section" open>
        <summary class="pc-section-label snip-section-summary">Member Pages</summary>
        <?php $snip('Join', 'join.php') ?>
        <?php $snip('Members list', 'members.php') ?>
        <?php $snip('Update membership', 'update.php') ?>
        <?php $snip('Remove membership', 'remove.php') ?>
        <?php $snip('Password reset', 'reset.php') ?>
      </details>

      <details class="snip-section" open>
        <summary class="pc-section-label snip-section-summary">Stats &amp; Extras</summary>
        <?php $snip('Stats', 'stats.php') ?>
        <?php $snip('Codes', 'codes.php') ?>
        <?php $snip('Affiliates', 'affiliates.php') ?>
        <?php if ($contact_enabled ?? false): ?>
          <?php $snip('Contact form', 'contact.php') ?>
        <?php endif; ?>
      </details>

      <?php if (in_array('Updates', $nav_installed_addons ?? [], true)): ?>
      <details class="snip-section" open>
        <summary class="pc-section-label snip-section-summary">Updates Feed</summary>
        <?php $snip('Updates feed', 'updates.php') ?>
      </details>
      <?php endif; ?>

      <?php if (in_array('Adoption', $nav_installed_addons ?? [], true) && ($adoption_addon_installed ?? false)): ?>
      <details class="snip-section" open>
        <summary class="pc-section-label snip-section-summary">Adoption</summary>
        <?php $snip('Adoption form', 'adopt.php') ?>
      </details>
      <?php endif; ?>

      <?php if (in_array('Kim', $nav_installed_addons ?? [], true)): ?>
      <details class="snip-section" open>
        <summary class="pc-section-label snip-section-summary">Keep In Mind</summary>
        <?php $snip('KIM sign-up', 'kim-signup.php') ?>
        <?php $snip('KIM member list', 'kim-list.php') ?>
        <?php $snip('KIM update', 'kim-update.php') ?>
        <?php $snip('KIM password reset', 'kim-reset.php') ?>
      </details>
      <?php endif; ?>

    <?php else: ?>
      <p class="form-hint">
        Set the <strong>Installation Path</strong> in
        <a href="<?= $b ?>/admin/settings" class="link">Settings &rarr; Server Paths</a>
        to show ready-to-use snippets here.
      </p>
    <?php endif; ?>
  </div>
</details>

<!-- ──── COMPAT WRAPPERS ──────────────────────────────────────────── -->
<details class="chevron card" style="margin-bottom:1.75rem" id="compat-wrappers">
  <summary class="card-head">Compatibility Wrappers</summary>
  <div class="card-body">
    <p class="form-hint mb-md">
      Use these files when an older Enthusiast or Listing Admin page still includes files like
      <code>show_join.php</code>. The wrapper lets that old include point to Luster instead.
      Copy the wrapper into the old script folder, then add the two lines shown before the include.
    </p>

    <?php
    $compatInstallPath = rtrim((string) ($cfg['install_path'] ?? ''), '/');
    $compatTabs = [
        'Enthusiast' => [
            ['file' => 'show_join.php',     'label' => 'Join form'],
            ['file' => 'show_members.php',  'label' => 'Members list'],
            ['file' => 'show_update.php',   'label' => 'Update membership'],
            ['file' => 'show_lostpass.php', 'label' => 'Lost password'],
            ['file' => 'show_stats.php',    'label' => 'Stats'],
        ],
        'Listing Admin' => [
            ['file' => 'show-join.php',     'label' => 'Join form'],
            ['file' => 'show-members.php',  'label' => 'Members list'],
            ['file' => 'show-update.php',   'label' => 'Update membership'],
            ['file' => 'show-remove.php',   'label' => 'Remove membership'],
            ['file' => 'show-stats.php',    'label' => 'Stats'],
        ],
    ];
    $compatSubDirs = ['Enthusiast' => 'enthusiast', 'Listing Admin' => 'listing-admin'];
    $compatTabId   = 'compat-' . (int) $listing->id;
    ?>

    <div class="tab-nav">
      <a href="#<?= $compatTabId ?>-enth"  class="tab-link tab-link--active" data-target="<?= $compatTabId ?>-enth">Enthusiast</a>
      <a href="#<?= $compatTabId ?>-la"    class="tab-link"                  data-target="<?= $compatTabId ?>-la">Listing Admin</a>
    </div>

    <?php foreach ($compatTabs as $network => $files): ?>
    <?php $subDir = $compatSubDirs[$network]; $panelId = $compatTabId . '-' . ($network === 'Enthusiast' ? 'enth' : 'la'); ?>
    <div id="<?= $panelId ?>" class="compat-panel" <?= $network !== 'Enthusiast' ? 'style="display:none"' : '' ?>>
      <?php foreach ($files as $entry): ?>
      <div class="listing-field-row listing-field-row--top mb-md">
        <span class="listing-field-label"><?= View::e($entry['label']) ?></span>
        <div>
          <p class="form-hint mb-xs">
            Copy <code>public/compat/<?= View::e($subDir) ?>/<?= View::e($entry['file']) ?></code>
            into the old <?= View::e($network) ?> folder, then add this to your page:
          </p>
          <div class="ro-field embed-snippet">
            $luster_listing_id = <?= (int) $listing->id ?>;<?php if ($compatInstallPath !== ''): ?><br>
            $luster_path = '<?= View::e($compatInstallPath) ?>';<?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if ($compatInstallPath !== ''): ?>
      <div class="mt-md">
        <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/compat-zip/<?= View::e($subDir) ?>"
           class="btn btn-ghost btn-sm">
          <?= View::icon('download') ?> Download pre-configured <?= View::e($network) ?> wrappers (ZIP)
        </a>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

  </div>
</details>
</div>

<!-- ──── TAB: ADVANCED ──────────────────────────────────────────────── -->
<div id="tab-advanced" class="tabs-panel">

  <div class="callout callout-err">
    <?= View::icon('alert') ?>
    <div>
      <strong>Advanced settings - proceed with caution!</strong>
      <span>Only change these if you are intentionally moving where this listing stores or reads members. Wrong values can make the listing lose track of its members.</span>
    </div>
  </div>

  <details class="card chevron">
    <summary class="card-head">Member Storage Name
      <div class="card-head-sub">The internal name Luster uses for this listing's member records. Most users never need to change this.</div>
    </summary>
    <div class="card-body">
      <div class="listining-field-row">
        <input type="text" class="form mono" value="<?= View::e($listing->dbprefix) ?>"
               readonly>
        <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit-prefix"
           class="btn btn-danger">Rename</a>
      </div>
    </div>
  </details>

  <details class="card chevron">
    <summary class="card-head">Separate Database Login
      <div class="card-head-sub">Use this only if this listing's members live in a different database from the rest of Luster. Leave everything blank for the normal setup.</div>
    </summary>
    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/embed" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <?php
      /** @var array $embed_settings */
      /** @var array $cfg */
      $advEmbedSettings = $embed_settings ?? [];
      ?>
      <div class="listing-field-section">
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="adv-db-host">DB Host</label>
          <div>
            <input id="adv-db-host" type="text" name="db_host" class="form"
                   maxlength="255" placeholder="localhost"
                   value="<?= View::e($advEmbedSettings['db_host'] ?? '') ?>">
            <p class="form-hint">The database server name, usually <code>localhost</code>.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="adv-db-name">DB Name</label>
          <div>
            <input id="adv-db-name" type="text" name="db_name" class="form"
                   maxlength="255" placeholder="my_database"
                   value="<?= View::e($advEmbedSettings['db_name'] ?? '') ?>">
            <p class="form-hint">The database name that contains this listing's member records.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="adv-db-user">DB User</label>
          <div>
            <input id="adv-db-user" type="text" name="db_user" class="form"
                   maxlength="255" autocomplete="off"
                   value="<?= View::e($advEmbedSettings['db_user'] ?? '') ?>">
            <p class="form-hint">The username Luster should use for that separate database.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="adv-db-pass">DB Password</label>
          <div>
            <div class="input-icon-wrap">
              <input id="adv-db-pass" type="password" name="db_pass" class="form"
                     maxlength="500" autocomplete="new-password"
                     placeholder="Leave blank to keep current">
              <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1" class="input-icon-btn">
                <?= View::icon('eye-on') ?>
                <?= View::icon('eye-off') ?>
              </button>
            </div>
            <p class="form-hint">Only fill this in when you want to set or replace the saved password.</p>
          </div>
        </div>
      </div>
      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Settings</button>
        <button type="reset" class="btn btn-ghost">Reset to Default</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </details>

</div>
