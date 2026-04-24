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
$oldTitle  = $old['title']  ?? $listing->name;
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

// Pre-populate existing categories from catid pipe-string
$existingCats = [];
$catidRaw     = (string) ($listing->catid ?? '');
$catIds       = array_filter(explode('|', $catidRaw), static fn($v) => $v !== '');
if (!empty($catIds)) {
    $catLookup = [];
    foreach ($tfl_cats  ?? [] as $c) { $catLookup[(int) $c['id']] = ['network' => 'TFL',  'name' => $c['name']]; }
    foreach ($tafl_cats ?? [] as $c) { $catLookup[(int) $c['id']] = ['network' => 'TAFL', 'name' => $c['name']]; }
    foreach ($custom_cats ?? [] as $net => $cats) {
        foreach ($cats as $c) { $catLookup[(int) $c['id']] = ['network' => $net, 'name' => $c['name']]; }
    }
    foreach ($catIds as $cid) {
        if (isset($catLookup[(int) $cid])) { $existingCats[] = $catLookup[(int) $cid]; }
    }
}
$dataExisting = json_encode($existingCats, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings" class="link">
    &larr; Back to Owned Listings
  </a>
  <div class="topbar-actions">
    <a href="<?= $b ?>/admin/members/<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">Member List</a>
    <a href="<?= $b ?>/admin/emails?tab=templates&listing=<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">Bulk Email</a>
    <form method="post"
          action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/delete"
          class="action-form-inline"
          data-del-modal data-del-entity="listing"
          data-del-name="<?= View::e($listing->name) ?>">
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
<?php $pl = View::e($b . '/admin/members/' . (int) $listing->id . '?status=pending'); ?>
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
  <div class="stat-header">Date Last Updated</div>
  <div class="stat-value"><?= View::e(($fl_last_updated ?? '') !== '' ? date($admin_date_format, strtotime($fl_last_updated)) : '-') ?></div>
</div>
</div>

<div class="tabs-wrap">
<div class="tabs">
  <button class="tabs-btn active" data-tab="general">General</button>
  <button class="tabs-btn" data-tab="fields">Additional Fields</button>
  <button class="tabs-btn" data-tab="codes">Codes</button>
  <button class="tabs-btn" data-tab="affiliates">Affiliates</button>
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
      <option value="affiliates">Affiliates</option>
      <option value="templates">Templates</option>
      <option value="embed">Embed</option>
      <option value="advanced">Advanced</option>
    </select>
  </div>
</div>
</div>

<!--  TAB: GENERAL  -->
<div id="tab-general" class="tabs-panel active">
  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" enctype="multipart/form-data" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('star') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">Listing Details</div>
          <div class="pc-head-sub">Manage the core profile, image, behaviour, and maintenance settings for this listing.</div>
        </div>
      </div>
      <div class="pc-section">
        <div class="pc-section-label">Listing Details</div>
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
            <span class="form-hint">Optional name of your listing, &mdash; defaults to "Subject" if left blank.</span>
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
            <p class="form-hint form-hint-inline">This is the email address that listing emails will be sent from.</p>
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
              <input type="file" id="fl-image" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Click to upload</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
            </label>
          </div>
        </div>
      </div>


      <div class="pc-section">
        <div class="pc-section-label">Behaviour</div>
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

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-members-per-page">Members per page</label>
          <div>
            <input id="fl-members-per-page" type="number" name="members_per_page" class="form form--compact"
                   min="5" max="200" value="<?= (int) ($members_per_page ?? 25) ?>">
            <p class="form-hint">Admin list view (5&ndash;200).</p>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Contact Form</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="contact_enabled" value="1"
                     <?= ($contact_enabled ?? true) ? 'checked' : '' ?>>
              <span class="toggle-label">Enable contact form for this listing</span>
            </label>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-contact-recipient">Contact recipient</label>
          <div>
            <input id="fl-contact-recipient" type="email" name="contact_recipient" class="form"
                   maxlength="255" placeholder="Defaults to listing email"
                   value="<?= View::e($contact_recipient ?? '') ?>">
            <p class="form-hint">Override which email address receives contact messages for this listing. Leave blank to use the listing's email address.</p>
          </div>
        </div>
      </div>

      <div class="pc-section">
        <div class="pc-section-label">Maintenance</div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-last-updated">Last Updated</label>
          <div>
            <div class="compact-actions">
              <input id="fl-last-updated" type="date" name="fl_last_updated" class="form form--compact"
                     value="<?= View::e($fl_last_updated ?? '') ?>">
              <button type="button" class="btn btn-tertiary btn-sm" id="set-today-btn">Set Today's Date</button>
              <button type="button" class="btn btn-ghost btn-sm" id="clear-date-btn">Reset to Default</button>
            </div>
            <p class="form-hint">Drives the staleness check on the dashboard.</p>
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
          <div class="pc-head-sub">Controls how members, affiliates, and stats render on your site.</div>
        </div>
      </div>

      <div class="card-body">
        <div class="pc-section-label">Members List</div>
        <div class="form-group">
          <textarea id="members_template" name="members_template" class="form tpl-textarea" spellcheck="false"><?= View::e($templates['members_template'] ?? '<li><a href="{fan_url}">{fan_name}</a> &mdash; {fan_country}</li>') ?></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost btn-sm btn-tpl-reset" data-target="members_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Affiliates</div>
        <div class="form-group">
          <textarea id="affiliates_template" name="affiliates_template" class="form tpl-textarea" spellcheck="false"><?= View::e($templates['affiliates_template'] ?? '<a href="{aff_url}" target="_blank" title="{aff_name}">{aff_image}</a>') ?></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost btn-sm btn-tpl-reset" data-target="affiliates_template">Reset to Default</button>
        </div>

        <div class="pc-section-label">Statistics</div>
        <div class="form-group">
          <textarea id="stats_template" name="stats_template" class="form tpl-textarea tpl-textarea-lg" spellcheck="false"><?= View::e($templates['stats_template'] ?? '<div><b>Opened:</b> {stat_opened}<br>
<b>Updated:</b> {stat_last_updated}<br>
<b>Fans:</b> {stat_approved} (+ {stat_pending} pending)<br>
<b>Newest Fan:</b> {stat_newmembers}<br>
<b>Growth:</b> {stat_growth}</div>') ?></textarea>
        </div>
        <div class="modal-footer">
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
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="members_template" data-token="{fan_name}"><td><code>{fan_name}</code></td><td>Fan&rsquo;s display name</td></tr>
              <tr class="tok-row" data-textarea="members_template" data-token="{fan_email}"><td><code>{fan_email}</code></td><td>Fan&rsquo;s email address</td></tr>
              <tr class="tok-row" data-textarea="members_template" data-token="{fan_url}"><td><code>{fan_url}</code></td><td>Fan&rsquo;s website URL</td></tr>
              <tr class="tok-row" data-textarea="members_template" data-token="{fan_country}"><td><code>{fan_country}</code></td><td>Fan&rsquo;s country</td></tr>
            </tbody>
          </table>
        </div>
      </details>

      <details class="card chevron" open>
        <summary class="chevron-head">Affiliates Tokens</summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="affiliates_template" data-token="{aff_url}"><td><code>{aff_url}</code></td><td>Affiliate URL</td></tr>
              <tr class="tok-row" data-textarea="affiliates_template" data-token="{aff_image}"><td><code>{aff_image}</code></td><td>Affiliate image tag</td></tr>
              <tr class="tok-row" data-textarea="affiliates_template" data-token="{aff_name}"><td><code>{aff_name}</code></td><td>Affiliate name</td></tr>
            </tbody>
          </table>
        </div>
      </details>

      <details class="card chevron" open>
        <summary class="chevron-head">Statistics Tokens</summary>
        <div class="chevron-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_opened}"><td><code>{stat_opened}</code></td><td>Date opened</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_updated}"><td><code>{stat_updated}</code></td><td>Last updated (formatted)</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_last_updated}"><td><code>{stat_last_updated}</code></td><td>Last updated (raw date)</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_approved}"><td><code>{stat_approved}</code></td><td>Approved member count</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_pending}"><td><code>{stat_pending}</code></td><td>Pending member count</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_newmembers}"><td><code>{stat_newmembers}</code></td><td>Newest member name</td></tr>
              <tr class="tok-row" data-textarea="stats_template" data-token="{stat_growth}"><td><code>{stat_growth}</code></td><td>Average members/day since opening</td></tr>
            </tbody>
          </table>
        </div>
      </details>
    </div>

  </div>
</form>

<script type="application/json" id="luster-tpl-defaults">{"members_template":"<li><a href=\"{fan_url}\">{fan_name}<\/a> \u2014 {fan_country}<\/li>","affiliates_template":"<a href=\"{aff_url}\" target=\"_blank\" title=\"{aff_name}\">{aff_image}<\/a>","stats_template":"<div>\n<b>Opened:<\/b> {stat_opened}<br>\n<b>Updated:<\/b> {stat_last_updated}<br>\n<b>Fans:<\/b> {stat_approved} (+ {stat_pending} pending)<br>\n<b>Newest Fan:<\/b> {stat_newmembers}<br>\n<b>Growth:<\/b> {stat_growth}\n<\/div>"}</script>
</div><!-- /#tab-templates -->

<!--  TAB: ADDITIONAL FIELDS  -->
<div id="tab-fields" class="tabs-panel">

  <?php if ($listing->dbprefix === ''): ?>
  <div class="card">
    <p class="text-muted">Additional fields require a member table. This listing has no database table configured yet.</p>
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
        <div class="callout"><strong>Rules:</strong> Letters (a-z, A-Z) and numbers (0-9) only. <u>NO SPACES</u> — use underscores in place of spaces. Max 30 characters. Must be unique per listing and per additional field.</div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="af-name">Field Name <span class="req">*</span></label>
          <div>
            <input type="text" class="form" id="af-name" name="name"
                   maxlength="30" pattern="[a-zA-Z0-9_]+"
                   placeholder="e.g. fave_song" required
                   title="Letters, numbers and underscores only">
            <p class="form-hint">Database field name. Please refer to the rules highlighted above..</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="af-label">Label <span class="req">*</span></label>
          <div>
            <input type="text" class="form" id="af-label" name="label"
                   maxlength="100" placeholder="e.g. Favourite Song" required>
            <p class="form-hint">The associatedlabel members will see on your join form and members pages.</p>
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
            <p class="form-hint">Comma-separated values for drop down, checkbox, and radio button fields.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <span class="listing-field-label">Required</span>
          <div>
            <div style="display:flex;gap:1.25rem;">
              <label class="form-check">
                <input type="radio" name="required" value="1">
                <span class="form-check-label">Yes</span>
              </label>
              <label class="form-check">
                <input type="radio" name="required" value="0" checked>
                <span class="form-check-label">No</span>
              </label>
            </div>
            <p class="form-hint">If Yes, members must fill in this field to successfully submit the form.</p>
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

<!--  TAB: CODES  -->
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
    <a href="<?= $code_status_filter === 'pending' ? $_tabBase : $_tabBase . '&status_filter=pending' ?>" class="btn btn-tertiary btn-sm" data-code-filter="<?= $code_status_filter === 'pending' ? '' : 'pending' ?>" data-codes-pending-action><?= $code_status_filter === 'pending' ? '&larr; Show all codes' : 'Review pending &rarr;' ?></a>
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
              <td class="text-dim"><?= $dn->email !== null ? View::e($dn->email) : '&mdash;' ?></td>
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
      <button class="btn btn-primary full-width-btn">Save changes</button>
    </form>
    <div class="code-modal-footer">
      <form method="POST"
            action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/<?= (int)$code->id ?>/delete"
            data-del-modal data-del-entity="code"
            data-del-name="<?= View::e($_dLabel !== null ? $_dLabel : 'Code #' . (int)$code->id) ?>">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <button type="submit" class="btn btn-danger btn-sm full-width-btn">Delete this code</button>
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
          <div class="panel-field upload-panel-row">
            <label class="panel-label" for="upload-donor">Donor</label>
            <div class="select-wrap">
              <select id="upload-donor" name="donor_id">
                <option value="">None</option>
                <?php foreach ($code_donors as $dn): ?>
                  <option value="<?= (int)$dn->id ?>"><?= View::e($dn->name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <button type="submit" class="btn btn-primary btn-full">Upload</button>
          <p class="panel-hint panel-hint-spaced">Dimensions are detected automatically. Settings above apply to all files in this batch.</p>
        </form>
      </div>
    </div>
    <?php if ($_approvedCount > 0): ?>
    <a href="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/export" class="btn btn-quaternary btn-full btn-col"><span><?= View::icon('download') ?> Export ZIP</span><span class="card-head-sub">Download all approved codes in a ZIP file.</span></a>
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
      <a href="<?= $_tabBase ?>" class="tabs-btn<?= $code_status_filter === '' ? ' active' : '' ?>" data-code-filter="">
        All <span class="badge badge-current"><?= $total_codes ?></span>
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
      <div class="card-section card-empty-state">
        <div class="empty">
          <span class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></span>
          No codes yet.
        </div>
      </div>
    <?php else: ?>
      <div class="card-section card-empty-state<?= $code_status_filter === 'pending' && $_pendingCount === 0 ? '' : ' is-hidden' ?>" id="codes-filter-empty">
        <div class="empty">
          <span class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></span>
          No codes match this filter &mdash; <a href="<?= $_tabBase ?>" class="link" data-code-filter="">clear filter</a> to see all <?= $total_codes ?>.
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


<!--  TAB: AFFILIATES  -->
<div id="tab-affiliates" class="tabs-panel">
<?php $_affFid = (int) $listing->id; ?>
<?php $_nextAffSort = empty($affiliates) ? 1 : max(array_map(fn($a) => $a->sortOrder ?? 0, $affiliates)) + 1; ?>

<form method="POST" action="<?= $b ?>/admin/listings/<?= $_affFid ?>/affiliates/add" enctype="multipart/form-data" class="js-warn-unsaved">
  <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="fanlisting_id" value="<?= $_affFid ?>">
  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('link') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Add Affiliate</div>
        <div class="pc-head-sub">Add a new affiliate to this listing.</div>
      </div>
    </div>
    <div class="listing-field-section">
      <div class="listing-field-row">
        <label class="listing-field-label" for="aff-name">Affiliate Name <span class="req">*</span></label>
        <div>
          <input id="aff-name" type="text" name="name" class="form"
                 maxlength="255" required placeholder="e.g. Starlight Fanlisting">
        </div>
      </div>
      <div class="listing-field-row">
        <label class="listing-field-label" for="aff-url">URL <span class="req">*</span></label>
        <div>
          <input id="aff-url" type="url" name="url" class="form"
                 maxlength="500" placeholder="https://" required>
        </div>
      </div>
      <div class="listing-field-row">
        <label class="listing-field-label" for="aff-email">Email</label>
        <div>
          <input id="aff-email" type="email" name="email" class="form"
                 maxlength="255" placeholder="owner@example.com">
        </div>
      </div>
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="aff-sort">Sort Order</label>
        <div>
          <input id="aff-sort" type="number" name="sort_order" class="form form--compact"
                 min="1" max="9999" value="<?= $_nextAffSort ?>">
          <p class="form-hint">Lower numbers appear first. Same order = sorted alphabetically.</p>
        </div>
      </div>
      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Image</span>
        <div>
          <label class="file-drop-zone">
            <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <span class="file-drop-icon"><?= View::icon('image') ?></span>
            <strong>Click to upload</strong>
            <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
          </label>
        </div>
      </div>
    </div>
    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Add Affiliate</button>
      <button type="reset" class="btn btn-ghost">Clear</button>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-head">Current Affiliates</div>
  <?php if (empty($affiliates)): ?>
    <div class="empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
      No affiliates for this listing yet.
    </div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Sort #</th>
          <th>Name</th>
          <th>Email</th>
          <th>Status</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($affiliates as $aff): ?>
        <tr>
          <td class="text-dim"><?= (int) $aff->sortOrder ?></td>
          <td>
            <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
               class="link text-strong"><?= View::e($aff->name) ?></a>
            <?= View::icon('external-link') ?>
          </td>
          <td class="text-muted"><?= $aff->email !== null ? View::e($aff->email) : '&mdash;' ?></td>
          <td>
            <?php if ($aff->active): ?>
              <span class="badge badge-current">Active</span>
            <?php else: ?>
              <span class="badge badge-pending">Inactive</span>
            <?php endif; ?>
          </td>
          <td class="act-col">
            <div class="data-actions">
              <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit?from_listing=<?= $_affFid ?>"
                 class="btn btn-tertiary btn-sm">Edit</a>
              <form method="POST"
                    action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                    class="action-form-inline">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="from_listing" value="<?= $_affFid ?>">
                <button type="submit" class="btn btn-sm <?= $aff->active ? 'btn-warn' : 'btn-quaternary' ?>">
                  <?= $aff->active ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
              <form method="POST"
                    action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                    class="action-form-inline"
                    data-del-modal data-del-entity="affiliate"
                    data-del-name="<?= View::e($aff->name ?? '') ?>">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="from_listing" value="<?= $_affFid ?>">
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
</div>


<!--  TAB: EMBED  -->
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
      <div class="pc-head-sub">Configure the external page filenames and generated snippets used to embed this listing on your site.</div>
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
          <p class="form-hint">Filename of the page on your site containing the join embed.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-update">Update Membership</label>
        <div>
          <input id="fl-form-update" type="text" name="form_url_update" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_update'] !== '' ? $embedSettings['form_url_update'] : 'update.php') ?>">
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-remove">Remove Membership</label>
        <div>
          <input id="fl-form-remove" type="text" name="form_url_remove" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_remove'] !== '' ? $embedSettings['form_url_remove'] : 'remove.php') ?>">
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-reset">Password Reset</label>
        <div>
          <input id="fl-form-reset" type="text" name="form_url_reset" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_reset'] !== '' ? $embedSettings['form_url_reset'] : 'reset.php') ?>">
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-codes-submit">Code Donation</label>
        <div>
          <input id="fl-form-codes-submit" type="text" name="form_url_codes_submit" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_codes_submit'] !== '' ? $embedSettings['form_url_codes_submit'] : 'codes-submit.php') ?>">
        </div>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Embed Snippets</div>

      <?php if ($installPath !== ''): ?>
        <?php foreach (['join', 'members', 'update', 'remove'] as $page): ?>
        <div class="listing-field-row listing-field-row--top">
          <span class="listing-field-label"><?= ucfirst(View::e($page)) ?> page</span>
          <div>
            <div class="ro-field embed-snippet">
              $luster_fanlisting_id = <?= (int) $listing->id ?>;<br>
              require '<?= View::e($installPath) ?>/public/embed/<?= View::e($page) ?>.php';
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="listing-field-row listing-field-row--top">
          <span class="listing-field-label">Snippets</span>
          <div>
            <p class="form-hint">
              Set the <strong>Installation Path</strong> in
              <a href="<?= $b ?>/admin/settings" class="link">Settings &rarr; Embed &amp; Paths</a>
              to generate embed snippets here.
            </p>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <div class="composer-footer">
    <button type="submit" class="btn btn-primary">Save Embed Settings</button>
    <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
  </div>
  </form>
  </div>
</div>


<!--  TAB: ADVANCED  -->
<div id="tab-advanced" class="tabs-panel">

  <div class="callout callout-inline">
    <?= View::icon('alert', ['width' => 24, 'height' => 24, 'style' => 'color:var(--warn)']) ?>
    <div>
      <strong>Advanced settings - proceed with caution!</strong>
      <span>Only modify these settings if you know what you are doing. Incorrect values can break your listing or prevent members from being found.</span>
    </div>
  </div>

  <details class="card chevron">
    <summary class="card-head">Database Prefix
      <div class="card-head-sub">The member table name in your database. Most users never need to change this.</siv>
    </summary>
    <div class="card-body">
      <div class="listining-field-row">
        <input type="text" class="form mono" value="<?= View::e($listing->dbprefix) ?>"
               readonly>
        <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit-prefix"
           class="btn btn-danger">Edit</a>
      </div>
    </div>
  </details>

  <details class="card chevron">
    <summary class="card-head">Per-listing Database Connection
      <div class="card-head-sub">Override the global database connection for this listing only. Leave all fields blank to use the global database.</div>
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
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="adv-db-name">DB Name</label>
          <div>
            <input id="adv-db-name" type="text" name="db_name" class="form"
                   maxlength="255" placeholder="my_database"
                   value="<?= View::e($advEmbedSettings['db_name'] ?? '') ?>">
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="adv-db-user">DB User</label>
          <div>
            <input id="adv-db-user" type="text" name="db_user" class="form"
                   maxlength="255" autocomplete="off"
                   value="<?= View::e($advEmbedSettings['db_user'] ?? '') ?>">
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
            <p class="form-hint">Only fill in to change the stored password.</p>
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