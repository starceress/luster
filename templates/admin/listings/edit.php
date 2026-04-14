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
$dataTfl    = json_encode($tfl_cats       ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl   = json_encode($tafl_cats      ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);

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
    <a href="<?= $b ?>/admin/members/<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">Members</a>
    <a href="<?= $b ?>/admin/emails?tab=templates&listing=<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">Emails</a>
    <form method="post"
          action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/delete"
          style="display:inline"
          data-del-modal data-del-entity="listing"
          data-del-name="<?= View::e($listing->name) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
    </form>
  </div>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="stats-row">
<?php $pl = View::e($b . '/admin/members/' . (int) $listing->id . '?status=pending'); ?>
<div class="stat-mini">
  <div class="val"><?= (int) $approved ?></div>
  <div class="lbl">Total Members</div>
</div>
<div class="stat-mini">
  <div class="val"><?= $pending > 0 ? '<a href="' . $pl . '" class="link" style="font-family:inherit;font-size:inherit;font-weight:inherit">' . $pending . '</a>' : $pending ?></div>
  <div class="lbl">Pending</div>
</div>
<div class="stat-mini">
  <div class="val"><?= (int) ($countries ?? 0) ?></div>
  <div class="lbl">Total Countries</div>
</div>
<div class="stat-mini">
  <div class="val" style="font-size:1.2rem"><?= View::e(($fl_last_updated ?? '') !== '' ? date($admin_date_format, strtotime($fl_last_updated)) : '-') ?></div>
  <div class="lbl">Date Last Updated</div>
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
  <span class="tabs-select-label">Navigation</span>
  <div class="select-wrap" style="flex:1">
    <select class="tabs-select select">
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

    <div class="card">

      <!-- Listing Details -->
      <div class="listing-field-section">
        <div class="pc-section-label">Listing Details</div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Network &amp; Category</label>
          <div>
            <div class="cat-widget" id="cat-widget"
                 data-tfl='<?= $dataTfl ?>'
                 data-tafl='<?= $dataTafl ?>'
                 data-custom='<?= $dataCustom ?>'
                 data-existing='<?= $dataExisting ?>'>
              <div id="cat-rows"></div>
              <a href="#" class="link" id="cat-add" style="font-size:.8125rem;display:inline-block;margin-top:.4rem">+ Add another category</a>
            </div>
            <p class="form-hint">Assign to one or more networks (TFL, TAFL, custom).</p>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-subject">Subject</label>
          <div>
            <input id="fl-subject" type="text" name="subject" class="form-input"
                   value="<?= View::e($listing->subject ?? '') ?>" maxlength="255">
            <p class="form-hint">The subject of your listing (e.g. character name, show title).</p>
          </div>
        </div>

        <div class="listing-field-row<?= !empty($fe['title']) ? ' has-error' : '' ?>">
          <label class="listing-field-label" for="fl-title">Title</label>
          <div>
            <input id="fl-title" type="text" name="title" class="form-input"
                   value="<?= View::e((string) $oldTitle) ?>"
                   maxlength="255" required>
            <?php if (!empty($fe['title'])): ?><p class="field-error"><?= View::e($fe['title'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="listing-field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
          <label class="listing-field-label" for="fl-url">Website URL <span class="req">*</span></label>
          <div>
            <input id="fl-url" type="url" name="url" class="form-input"
                   value="<?= View::e((string) $oldUrl) ?>"
                   maxlength="500" placeholder="https://example.com" required>
            <?php if (!empty($fe['url'])): ?><p class="field-error"><?= View::e($fe['url'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top<?= !empty($fe['email']) ? ' has-error' : '' ?>">
          <label class="listing-field-label" for="fl-email">Contact Email</label>
          <div>
            <input id="fl-email" type="email" name="email" class="form-input"
                   value="<?= View::e((string) $oldEmail) ?>"
                   maxlength="255" required>
            <p class="form-hint">Address listing emails are sent from.</p>
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
            <input id="fl-opened" type="date" name="opened" class="form-input" style="max-width:200px"
                   value="<?= View::e($openedDateVal) ?>">
            <input type="hidden" id="fl-opened-tba" name="opened" value="TBA" disabled>
            <span id="fl-opened-tba-text" style="display:none;font-size:.85rem;color:var(--text-muted);font-style:italic;margin-top:.35rem">TBA &mdash; date will be set when the listing opens.</span>
          </div>
        </div>

      </div><!-- /.listing-field-section (details) -->

      <!-- Listing Image -->
      <div class="listing-field-section">
        <div class="pc-section-label">Listing Image</div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Image</label>
          <div>
            <?php if (isset($current_image) && $current_image !== null && $current_image !== ''): ?>
              <div class="existing-image-row" style="margin-bottom:.625rem">
                <div class="existing-img-placeholder">
                  <img src="<?= $b ?>/uploads/listings/<?= View::e($current_image) ?>"
                       alt="<?= View::e($current_image) ?>">
                </div>
                <div>
                  <div style="font-size:.8rem;font-weight:600;color:var(--text)"><?= View::e($current_image) ?></div>
                  <label class="del-img-check">
                    <input type="checkbox" id="fl-delete-image" name="delete_image" value="1">
                    Delete current image
                  </label>
                </div>
              </div>
              <p style="font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:.35rem">Replace with new image</p>
            <?php endif; ?>
            <label class="file-drop-zone">
              <input type="file" id="fl-image" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><span class="file-drop-icon"><?= View::icon('image') ?></span>
              <span class="file-drop-label">Click to upload</span>
              <span class="file-drop-sub">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
            </label>
          </div>
        </div>

      </div><!-- /.listing-field-section (image) -->

      <!-- Behaviour -->
      <div class="listing-field-section">
        <div class="pc-section-label">Behaviour</div>

        <?php $lt = $listing_type ?? 'listing'; ?>
        <div class="listing-field-row">
          <label class="listing-field-label" for="fl-listing-type">Type</label>
          <div class="select-wrap" style="max-width:200px">
            <select id="fl-listing-type" name="listing_type" class="form-select">
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
            <input id="fl-members-per-page" type="number" name="members_per_page" class="form-input" style="max-width:100px"
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
            <input id="fl-contact-recipient" type="email" name="contact_recipient" class="form-input"
                   maxlength="255" placeholder="Defaults to listing email"
                   value="<?= View::e($contact_recipient ?? '') ?>">
            <p class="form-hint">Override which email address receives contact messages for this listing. Leave blank to use the listing's email address.</p>
          </div>
        </div>

      </div><!-- /.listing-field-section (behaviour) -->

      <!-- Maintenance -->
      <div class="listing-field-section">
        <div class="pc-section-label">Maintenance</div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-last-updated">Last Updated</label>
          <div>
            <input id="fl-last-updated" type="date" name="fl_last_updated" class="form-input" style="max-width:200px"
                   value="<?= View::e($fl_last_updated ?? '') ?>">
            <div style="display:flex;gap:.5rem;margin-top:.45rem">
              <button type="button" class="btn btn-quaternary btn-sm" id="set-today-btn">Today</button>
              <button type="button" class="btn btn-ghost btn-sm" id="clear-date-btn">Clear</button>
            </div>
            <p class="form-hint">Drives the staleness check on the dashboard.</p>
          </div>
        </div>

      </div><!-- /.listing-field-section (maintenance) -->

      <div class="card-footer">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>

    </div><!-- /.card.listing-field-card -->
  </form>
</div><!-- /#tab-general -->

<!--  TAB: TEMPLATES  -->
<div id="tab-templates" class="tabs-panel">
<div style="display:grid;grid-template-columns:1fr 260px;gap:1.25rem;align-items:start">

  <!-- Left: template editor -->
  <div class="card">
    <div class="card-head">HTML Templates
        <div class="card-head-sub">Controls how members, affiliates, and stats render on your site.</div>
    </div>

    <div class="tpl-field">
      <div class="tpl-label">Stats URL</div>
      <div class="ro-field" style="margin-top:.35rem;font-size:.8rem"><?= $b ?>/stats/<?= (int) $listing->id ?></div>
    </div>

    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/templates">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

      <div class="tpl-field">
        <div class="tpl-label" style="display:flex;align-items:center;justify-content:space-between">
          Members List
          <button type="button" class="btn-tpl-reset" data-target="members_template">Reset to default</button>
        </div>
        <textarea id="members_template" name="members_template" class="form-textarea" rows="6"><?= View::e($templates['members_template'] ?? '<li><a href="{fan_url}">{fan_name}</a> - {fan_country}</li>') ?></textarea>
      </div>

      <div class="tpl-field">
        <div class="tpl-label" style="display:flex;align-items:center;justify-content:space-between">
          Affiliates
          <button type="button" class="btn-tpl-reset" data-target="affiliates_template">Reset to default</button>
        </div>
        <textarea id="affiliates_template" name="affiliates_template" class="form-textarea" rows="6"><?= View::e($templates['affiliates_template'] ?? '<a href="{aff_url}" target="_blank" title="{aff_name}">{aff_image}</a>') ?></textarea>
      </div>

      <div class="tpl-field">
        <div class="tpl-label" style="display:flex;align-items:center;justify-content:space-between">
          Statistics
          <button type="button" class="btn-tpl-reset" data-target="stats_template">Reset to default</button>
        </div>
        <textarea id="stats_template" name="stats_template" class="form-textarea" rows="6"><?= View::e($templates['stats_template'] ?? '<div><b>Opened:</b> {stat_opened}<br>
<b>Updated:</b> {stat_last_updated}<br>
<b>Fans:</b> {stat_approved} (+ {stat_pending} pending)<br>
<b>Newest Fan:</b> {stat_newmembers}<br>
<b>Growth:</b> {stat_growth}</div>') ?></textarea>
      </div>

      <div class="card-footer">
        <button type="submit" class="btn btn-primary">Save Templates</button>
      </div>
    </form>

    <script type="application/json" id="luster-tpl-defaults">{"members_template":"<li><a href=\"{fan_url}\">{fan_name}<\/a> \u2014 {fan_country}<\/li>","affiliates_template":"<a href=\"{aff_url}\" target=\"_blank\" title=\"{aff_name}\">{aff_image}<\/a>","stats_template":"<div>\n<b>Opened:<\/b> {stat_opened}<br>\n<b>Updated:<\/b> {stat_last_updated}<br>\n<b>Fans:<\/b> {stat_approved} (+ {stat_pending} pending)<br>\n<b>Newest Fan:<\/b> {stat_newmembers}<br>\n<b>Growth:<\/b> {stat_growth}\n<\/div>"}</script>
  </div>

  <!-- Right: token reference -->
  <div class="card" style="overflow:hidden;position:sticky;top:1rem">
    <div class="card-head">
      <span>Available Tokens</span>
    </div>
    <details class="chevron" open>
      <summary class="chevron-head">Members List</summary>
      <div class="chevron-body">
        <table class="et-token-table">
          <tbody>
            <tr class="tok-row" data-textarea="members_template" data-token="{fan_name}"    title="Click to insert"><td><code>{fan_name}</code></td><td>Fan&rsquo;s display name</td></tr>
            <tr class="tok-row" data-textarea="members_template" data-token="{fan_email}"   title="Click to insert"><td><code>{fan_email}</code></td><td>Fan&rsquo;s email address</td></tr>
            <tr class="tok-row" data-textarea="members_template" data-token="{fan_url}"     title="Click to insert"><td><code>{fan_url}</code></td><td>Fan&rsquo;s website URL</td></tr>
            <tr class="tok-row" data-textarea="members_template" data-token="{fan_country}" title="Click to insert"><td><code>{fan_country}</code></td><td>Fan&rsquo;s country</td></tr>
          </tbody>
        </table>
      </div>
    </details>

    <details class="chevron" open>
      <summary class="chevron-head">
        <span class="chevron-title">Affiliates</span>
      </summary>
      <div class="chevron-body">
        <table class="et-token-table">
          <tbody>
            <tr class="tok-row" data-textarea="affiliates_template" data-token="{aff_url}"   title="Click to insert"><td><code>{aff_url}</code></td><td>Affiliate URL</td></tr>
            <tr class="tok-row" data-textarea="affiliates_template" data-token="{aff_image}" title="Click to insert"><td><code>{aff_image}</code></td><td>Affiliate image tag</td></tr>
            <tr class="tok-row" data-textarea="affiliates_template" data-token="{aff_name}"  title="Click to insert"><td><code>{aff_name}</code></td><td>Affiliate name</td></tr>
          </tbody>
        </table>
      </div>
    </details>

    <details class="chevron" open>
      <summary class="chevron-head">
        <span class="chevron-title">Statistics</span>
      </summary>
      <div class="chevron-body">
        <table class="et-token-table">
          <tbody>
            <tr class="tok-row" data-textarea="stats_template" data-token="{stat_opened}"       title="Click to insert"><td><code>{stat_opened}</code></td><td>Date opened</td></tr>
            <tr class="tok-row" data-textarea="stats_template" data-token="{stat_updated}"      title="Click to insert"><td><code>{stat_updated}</code></td><td>Last updated (formatted)</td></tr>
            <tr class="tok-row" data-textarea="stats_template" data-token="{stat_last_updated}" title="Click to insert"><td><code>{stat_last_updated}</code></td><td>Last updated (raw date)</td></tr>
            <tr class="tok-row" data-textarea="stats_template" data-token="{stat_approved}"     title="Click to insert"><td><code>{stat_approved}</code></td><td>Approved member count</td></tr>
            <tr class="tok-row" data-textarea="stats_template" data-token="{stat_pending}"      title="Click to insert"><td><code>{stat_pending}</code></td><td>Pending member count</td></tr>
            <tr class="tok-row" data-textarea="stats_template" data-token="{stat_newmembers}"   title="Click to insert"><td><code>{stat_newmembers}</code></td><td>Newest member name</td></tr>
            <tr class="tok-row" data-textarea="stats_template" data-token="{stat_growth}"       title="Click to insert"><td><code>{stat_growth}</code></td><td>Average members per day since opening (e.g. 1.23 members/day)</td></tr>
          </tbody>
        </table>
      </div>
    </details>
  </div>

</div>
</div><!-- /#tab-templates -->

<!--  TAB: ADDITIONAL FIELDS  -->
<div id="tab-fields" class="tabs-panel">

  <?php if ($listing->dbprefix === ''): ?>
  <div class="card">
    <p class="text-muted">Additional fields require a member table. This listing has no database table configured yet.</p>
  </div>
  <?php else: ?>

  <div class="card">
    <div class="card-head">Add New Field</div>
    <div class="panel-content">
    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/add" id="fields-add-form">
      <input type="hidden" name="_csrf"     value="<?= View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="from_edit" value="1">
      <div class="fields-add-grid">
        <div class="form-group">
          <label class="form-label">Field Name</label>
          <input type="text" name="name" class="form-input" maxlength="30" pattern="[a-zA-Z0-9_]+"
                 placeholder="e.g. fave_song" required
                 title="Letters, numbers and underscores only">
          <p class="form-hint">Letters (a-z, A-Z) and numbers (0-9) only. NO SPACES — use underscores in place of spaces.<br>
          Max 30 characters. Must be unique per listing and per additional field.</p>
        </div>
        <div class="form-group">
          <label class="form-label">Label</label>
          <input type="text" name="label" class="form-input" maxlength="100" placeholder="e.g. Favourite Song" required>
        </div>
        <div class="form-group">
          <label class="form-label">Type</label>
          <div class="select-wrap">
            <select name="type" id="fields-type-sel" class="form-select">
              <option value="text">Text Field</option>
              <option value="dropdown">Dropdown List</option>
              <option value="checkbox">Checkbox</option>
              <option value="radio">Radio Button</option>
            </select>
          </div>
        </div>
      </div>
      <div id="fields-options-row" style="display:none">
        <div class="form-group">
          <label class="form-label">Options</label>
          <textarea name="options" class="form-textarea" rows="2"
                    placeholder="e.g. Option A, Option B, Option C"></textarea>
          <p class="form-hint">Separate options with commas.</p>
        </div>
      </div>
    </form>
    </div>
    <div class="composer-footer">
      <button type="submit" form="fields-add-form" class="btn btn-primary">Add Field</button>
      <button type="reset" form="fields-add-form" class="btn btn-ghost">Clear Form</button>
    </div>
  </div>

  <div class="card">
    <div class="card-head">Current Fields</div>
    <?php if (empty($fields)): ?>
      <p class="text-muted">No additional fields defined yet.</p>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Form Label</th>
            <th>Field Name</th>
            <th>Type</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fields as $field): ?>
          <tr>
            <td><?= View::e($field->label) ?></td>
            <td>
              <code><?= View::e($field->name) ?></code>
            </td>
            <?php $_typeLabels = ['text' => 'Text Field', 'dropdown' => 'Dropdown List', 'checkbox' => 'Checkbox', 'radio' => 'Radio Button']; ?>
            <td><?= View::e($_typeLabels[$field->type] ?? ucfirst($field->type)) ?></td>
            <td class="act-col">
              <div class="data-actions">
                <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/<?= urlencode($field->name) ?>/edit"
                   class="btn btn-tertiary btn-sm">Edit</a>
                <form method="POST"
                      action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/<?= urlencode($field->name) ?>/delete"
                      style="display:contents"
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

<!-- Topbar row -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
  <div></div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <span class="badge badge-muted"><?= $total_codes ?> code<?= $total_codes !== 1 ? 's' : '' ?></span>
    <?php if ($_approvedCount > 0): ?>
    <a href="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/export" class="btn btn-quaternary btn-sm">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
      Export ZIP
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Pending codes banner -->
<?php if ($_pendingCount > 0 && $code_status_filter !== 'pending'): ?>
<div class="reminder-banner" style="margin-bottom:.75rem">
  <div class="reminder-banner-head">
    <div class="reminder-banner-left">
      <span class="reminder-banner-icon"><?= View::icon('bell') ?></span>
      <div>
        <span class="reminder-banner-title">Codes awaiting review</span>
        <span class="reminder-banner-msg"><?= $_pendingCount ?> code<?= $_pendingCount !== 1 ? 's are' : ' is' ?> pending approval</span>
      </div>
    </div>
    <a href="<?= $_tabBase ?>&status_filter=pending" class="btn btn-primary btn-sm">Review pending</a>
  </div>
</div>
<?php endif; ?>

<!-- Code Settings collapsible -->
<details class="card chevron" <?= $_settingsOpen ? 'open' : '' ?> id="settingsBand" open>
  <summary class="card-head">Code Categories & Donors
  </summary>

  <div class="settings-band-grid">
    <div class="settings-band-col">
      <div class="pc-section-label">Categories</div>
      <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/categories/add"
            style="display:flex;gap:.5rem;align-items:flex-end;margin-bottom:.875rem;flex-wrap:wrap">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <div style="flex:1;min-width:120px">
          <input class="form-input" type="text" name="name" placeholder="Category name&hellip;" required>
        </div>
        <?php if (!empty($_topLevelCats)): ?>
        <div class="toolbar-select-wrap" style="min-width:130px">
          <select name="parent_id" class="select">
            <option value="">None (top-level)</option>
            <?php foreach ($_topLevelCats as $tlc): ?>
              <option value="<?= (int)$tlc->id ?>"><?= View::e($tlc->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-secondary btn-sm" style="flex-shrink:0"><?= View::icon('add') ?> Add</button>
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
            <tr <?= $_isCatEditing ? 'style="display:none"' : '' ?>>
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
                      style="display:contents" data-del-modal data-del-entity="code_category" data-del-name="<?= View::e($cat->name) ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <button class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <tr <?= !$_isCatEditing ? 'style="display:none"' : '' ?>>
              <td colspan="4">
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/categories/<?= (int)$cat->id ?>/edit"
                      style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <div style="flex:1;min-width:100px">
                    <input class="form-input" type="text" name="name" value="<?= View::e($cat->name) ?>" required>
                  </div>
                  <div>
                    <div class="toolbar-select-wrap">
                      <select name="parent_id" class="select" style="font-size:.8rem">
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
      <?php else: ?>
      <p class="text-dim">No categories yet. Use these to tag codes by theme.</p>
      <?php endif; ?>
    </div>

    <div class="settings-band-col">
      <div class="pc-section-label" style="margin-bottom:.875rem">Donors</div>
      <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/donors/add" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.5rem;align-items:end;margin-bottom:.875rem">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <input type="text"  class="form-input" name="name"  placeholder="Display name&hellip;" required>
        <input type="email" class="form-input" name="email" placeholder="Email (optional)">
        <input type="url"   class="form-input" name="url"   placeholder="URL (optional)">
        <button type="submit" class="btn btn-quaternary btn-sm" style="white-space:nowrap"><?= View::icon('add') ?> Add</button>
      </form>
      <?php if ($_hasDonors): ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Name</th><th>Email</th><th>URL</th><th># Donated</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($code_donors as $dn): ?>
            <?php $_isDnEditing = $code_edit_donor_id === $dn->id; ?>
            <tr <?= $_isDnEditing ? 'style="display:none"' : '' ?>>
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
                      style="display:contents" data-del-modal data-del-entity="code_donor" data-del-name="<?= View::e($dn->name) ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <button class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <tr <?= !$_isDnEditing ? 'style="display:none"' : '' ?>>
              <td colspan="5" style="padding:.6rem .75rem;background:var(--bg-subtle)">
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/donors/<?= (int)$dn->id ?>/edit"
                      style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <div style="flex:1;min-width:100px"><input class="form-input" type="text" name="name" value="<?= View::e($dn->name) ?>" required></div>
                  <div style="flex:1;min-width:100px"><input class="form-input" type="email" name="email" value="<?= View::e($dn->email ?? '') ?>" placeholder="Email"></div>
                  <div style="flex:1;min-width:100px"><input class="form-input" type="url" name="url" value="<?= View::e($dn->url ?? '') ?>" placeholder="URL"></div>
                  <button class="btn btn-primary btn-sm">Save</button>
                  <a href="<?= $_tabBase ?>" class="btn btn-quaternary btn-sm">Cancel</a>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <p class="text-dim">No donors yet. Credit the people who made codes for this listing.</p>
      <?php endif; ?>
    </div>
  </div>
</details>

<!-- Out-of-flow forms -->
<form id="codes-bulk-form" method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/bulk">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
</form>
<form id="codes-reorder-form" method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/reorder">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" id="reorder-ids" name="ordered_ids" value="">
</form>

<!-- Per-code delete forms + edit dialogs -->
<?php foreach ($codes as $code): ?>
<?php
  $_dDonor = ($code->donorId !== null && isset($code_donor_map[$code->donorId])) ? $code_donor_map[$code->donorId] : null;
  $_dLabel = ($code->name !== null && $code->name !== '') ? $code->name : null;
?>
<form id="del-<?= (int)$code->id ?>" method="POST"
      action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/<?= (int)$code->id ?>/delete"
      data-del-modal data-del-entity="code"
      data-del-name="<?= View::e($_dLabel !== null ? $_dLabel : 'Code #' . (int)$code->id) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
</form>
<dialog id="edit-dialog-<?= (int)$code->id ?>" class="code-edit-dialog">
  <div class="code-edit-dialog-header">
    <span class="code-edit-dialog-title">
      Edit code<?= $_dLabel !== null ? ' &mdash; ' . View::e($_dLabel) : ' #' . (int)$code->id ?>
    </span>
    <button type="button" class="code-edit-dialog-close" data-close-dialog aria-label="Close">&times;</button>
  </div>
  <div class="code-edit-dialog-body">
    <div style="text-align:center;margin-bottom:.875rem">
      <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
           alt="Code #<?= (int)$code->id ?>"
           style="max-width:100%;max-height:120px;object-fit:contain;border-radius:6px">
      <?php if ($code->width > 0 && $code->height > 0): ?>
      <div style="font-size:.7rem;color:var(--text-dim);margin-top:.3rem"><?= (int)$code->width ?>&times;<?= (int)$code->height ?>px</div>
      <?php endif; ?>
    </div>
    <form method="POST"
          action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/<?= (int)$code->id ?>/update"
          style="display:flex;flex-direction:column;gap:.6rem">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <div>
        <label class="form-label" style="font-size:.8rem">Label (optional)</label>
        <input class="form-input" type="text" name="name" value="<?= View::e($code->name ?? '') ?>" placeholder="e.g. Animated version">
      </div>
      <div>
        <label class="form-label" style="font-size:.8rem">Status</label>
        <div class="toolbar-select-wrap">
          <select name="status" class="select" style="width:100%">
            <option value="approved" <?= $code->status === 'approved' ? 'selected' : '' ?>>&#10003; Live</option>
            <option value="pending"  <?= $code->status === 'pending'  ? 'selected' : '' ?>>&#8987; Pending</option>
          </select>
        </div>
      </div>
      <?php if ($_hasDonors): ?>
      <div>
        <label class="form-label" style="font-size:.8rem">Donor</label>
        <div class="toolbar-select-wrap">
          <select name="donor_id" class="select" style="width:100%">
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
        <label class="form-label" style="font-size:.8rem">Category</label>
        <div class="toolbar-select-wrap">
          <select name="cat_ids[]" class="select" style="width:100%">
            <option value="">None</option>
            <?php
              $_dCatIds = $code_category_ids[$code->id] ?? [];
              foreach ($code_cats as $cat):
            ?>
              <option value="<?= (int)$cat->id ?>" <?= in_array($cat->id, $_dCatIds, true) ? 'selected' : '' ?>>
                <?= $cat->parentId !== null ? '└ ' : '' ?><?= View::e($cat->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($code->submitterName !== null): ?>
      <div>
        <label class="form-label" style="font-size:.8rem">Submitter website</label>
        <input class="form-input" type="url" name="submitter_url" value="<?= View::e($code->submitterUrl ?? '') ?>" placeholder="https://example.com">
      </div>
      <?php endif; ?>
      <button class="btn btn-primary" style="width:100%">Save changes</button>
    </form>
    <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border-soft)">
      <button type="submit" form="del-<?= (int)$code->id ?>" class="btn btn-danger btn-sm" style="width:100%">Delete this code</button>
    </div>
  </div>
</dialog>
<?php endforeach; ?>

<!-- Split layout -->
<div class="codes-split">

  <!-- Left: Upload panel -->
  <div class="codes-panel-col">
    <div class="panel-card">
      <div class="card-head">Upload Codes</div>
      <div class="panel-content">
        <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/upload" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
          <div class="codes-drop">
            <input type="file" id="codes-input" name="codes[]" multiple accept="image/jpeg,image/png,image/gif,image/webp">
            <svg class="codes-drop-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
            <span class="codes-drop-label">Click or drop images</span>
            <span class="codes-drop-hint">JPEG, PNG, GIF, WebP &mdash; max 500 KB each</span>
          </div>
          <div class="field-row-2" style="margin-bottom:.625rem">
            <div class="panel-field" style="margin-bottom:0">
              <label class="panel-label" for="upload-donor">Donor</label>
              <div class="select-wrap">
                <select id="upload-donor" name="donor_id" class="panel-select">
                  <option value="">None</option>
                  <?php foreach ($code_donors as $dn): ?>
                    <option value="<?= (int)$dn->id ?>"><?= View::e($dn->name) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="panel-field" style="margin-bottom:0">
              <label class="panel-label" for="upload-status">Status</label>
              <div class="select-wrap">
                <select id="upload-status" name="upload_status" class="panel-select">
                  <option value="approved">Live</option>
                  <option value="pending">Pending</option>
                </select>
              </div>
            </div>
          </div>
          <?php if ($_hasCats): ?>
          <div class="panel-field">
            <label class="panel-label">Category</label>
            <div class="select-wrap">
              <select name="cat_ids[]" class="panel-select">
                <option value="">None</option>
                <?php foreach ($code_cats as $cat): ?>
                  <option value="<?= (int)$cat->id ?>"><?= $cat->parentId !== null ? '└ ' : '' ?><?= View::e($cat->name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:.25rem">Upload</button>
          <p class="panel-hint" style="margin-top:.5rem">Dimensions are detected automatically. Settings above apply to all files in this batch.</p>
        </form>
      </div>
    </div>
  </div>

  <div class="main-card">
    <div class="card-head"><?= $_approvedCount ?> approved code<?= $_approvedCount !== 1 ? 's' : '' ?>
        <?php if ($_groupCount > 0): ?> &mdash; drag tiles to reorder.<?php endif; ?>
      <?php if ($total_codes > 0 && $_pendingCount === 0): ?>
        <span class="badge badge-current">All Live</span>
      <?php elseif ($_pendingCount > 0): ?>
        <span class="badge badge-upcoming"><?= $_pendingCount ?> Pending</span>
      <?php endif; ?>
    </div>

    <div class="codes-tabs">
      <a href="<?= $_tabBase ?>" class="tabs-btn<?= $code_status_filter === '' ? ' active' : '' ?>">
        All <span class="badge badge-info" style="font-size:.55rem;padding:1px 6px;vertical-align:middle"><?= $total_codes ?></span>
      </a>
      <a href="<?= $_tabBase ?>&status_filter=pending" class="tabs-btn<?= $code_status_filter === 'pending' ? ' active' : '' ?>">
        Pending <span class="badge badge-<?= $_pendingCount > 0 ? 'pending' : 'inactive' ?>" style="font-size:.55rem;padding:1px 6px;vertical-align:middle"><?= $_pendingCount ?></span>
      </a>
    </div>

    <div class="codes-bulk">
      <label style="display:flex;align-items:center;gap:5px;font-size:.78rem;color:var(--text-dim);cursor:pointer;flex-shrink:0">
        <input type="checkbox" id="codes-select-all">Select all </label>
      <div class="toolbar-select-wrap">
        <select id="bulk-action-sel" class="select">
          <option value="delete">Delete</option>
          <option value="approve">Approve</option>
          <option value="reject">Reject</option>
          <?php if ($_hasCats): ?>
            <option value="categorize">Add to categories&hellip;</option>
          <?php endif; ?>
        </select>
      </div>
      <?php if ($_hasCats): ?>
      <div id="bulk-cats-wrap" style="display:none">
        <select id="bulk-cats-sel" name="bulk_cat_ids[]" multiple size="<?= min(4, count($code_cats)) ?>" class="form-select">
          <?php foreach ($code_cats as $cat): ?>
            <option value="<?= (int)$cat->id ?>"><?= $cat->parentId !== null ? '  └ ' : '' ?><?= View::e($cat->name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <button type="button" id="bulk-apply-btn" class="btn btn-quaternary btn-sm">Apply to Selected</button>
      <div class="codes-bulk-right">
        <button type="button" id="save-order-btn" class="btn btn-quaternary btn-sm" style="display:none">Save Order</button>
      </div>
    </div>

    <?php if ($total_codes === 0): ?>
      <div class="card-section" style="text-align:center;padding:2.5rem">
        <div class="empty">
          <span class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></span>
          No codes yet.
        </div>
      </div>
    <?php elseif (empty($codes)): ?>
      <div class="card-section" style="text-align:center;padding:2.5rem">
        <div class="empty">
          <span class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></span>
          No codes match this filter &mdash; <a href="<?= $_tabBase ?>" class="link">clear filter</a> to see all <?= $total_codes ?>.
        </div>
      </div>
    <?php else: ?>
      <div class="card-section">
        <?php foreach ($code_grouped as $group): ?>
          <?php if (empty($group['codes'])) continue; ?>
          <?php
            $firstCode = reset($group['codes']);
            $gridMin   = max((int)$firstCode->width + 32, 80) . 'px';
          ?>
          <div class="dim-head">
            <span class="dim-label"><?= View::e($group['label']) ?></span>
            <span class="dim-count"><?= count($group['codes']) ?> code<?= count($group['codes']) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="code-grid" style="grid-template-columns:repeat(auto-fill,minmax(<?= $gridMin ?>,1fr))">
            <?php foreach ($group['codes'] as $code): ?>
            <?php
              $_codeCatIds = $code_category_ids[$code->id] ?? [];
              $_codeDonor  = ($code->donorId !== null && isset($code_donor_map[$code->donorId])) ? $code_donor_map[$code->donorId] : null;
              $_codeLabel  = ($code->name !== null && $code->name !== '') ? $code->name : null;
              $_isDonated  = $_codeDonor !== null || $code->submitterName !== null;
            ?>
            <div class="code-tile code-card"
                 data-code-id="<?= (int)$code->id ?>"
                 <?= $_isDonated ? 'style="border-color:rgba(249,115,22,.3)"' : '' ?>>
              <input type="checkbox" form="codes-bulk-form" name="selected[]" value="<?= (int)$code->id ?>" class="code-tile-cb code-check">
              <span class="code-drag-handle drag-handle" title="Drag to reorder">&#8942;&#8942;</span>
              <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
                   alt="<?= (int)$code->width ?>&times;<?= (int)$code->height ?>px"
                   style="max-width:100%;object-fit:contain" loading="lazy">
              <?php if ($_codeLabel !== null): ?>
              <div style="font-size:.68rem;font-weight:600;color:var(--text);text-align:center;word-break:break-word;line-height:1.3"><?= View::e($_codeLabel) ?></div>
              <?php endif; ?>
              <?php if ($_codeDonor !== null): ?>
              <div class="code-tile-donor">&hearts; <?= View::e($_codeDonor->name) ?></div>
              <?php elseif ($code->submitterName !== null): ?>
              <div class="code-tile-donor">&hearts; <?= View::e($code->submitterName) ?></div>
              <?php endif; ?>
              <?php if ($code->status === 'pending'): ?>
              <span class="badge badge-upcoming" style="font-size:.58rem;padding:1px 7px">Pending</span>
              <?php endif; ?>
              <button type="button" class="btn btn-quaternary btn-sm code-tile-btn code-edit-btn" data-dialog="edit-dialog-<?= (int)$code->id ?>">
                &#9881; Edit
              </button>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div><!-- /.card-section -->
    <?php endif; ?>

  </div><!-- /.main-card -->

</div><!-- /.codes-split -->
</div><!-- /#tab-codes -->

<!--  TAB: AFFILIATES  -->
<div id="tab-affiliates" class="tabs-panel">
<div class="card">
  <div class="card-head">
    <span>Affiliates</span>
    <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/affiliates/add"
       class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Affiliate</a>
  </div>

  <?php if (empty($affiliates)): ?>
    <div class="empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
      No affiliates for this listing yet.
      <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/affiliates/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Affiliate</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($affiliates as $aff): ?>
        <tr>
          <td>
            <a href="<?= View::e($aff->url ?? '') ?>" target="_blank" rel="noopener noreferrer"
               class="link"><?= View::e($aff->name ?? '') ?></a>
          </td>
          <td>
            <?php if ($aff->active): ?>
              <span class="badge badge-current">Active</span>
            <?php else: ?>
              <span class="badge badge-pending">Inactive</span>
            <?php endif; ?>
          </td>
          <td class="act-col">
            <div class="data-actions">
              <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit?from_listing=<?= (int) $listing->id ?>"
                 class="btn btn-tertiary btn-sm">Edit</a>

              <form method="POST"
                    action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                    style="display:contents">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="from_listing" value="<?= (int) $listing->id ?>">
                <button type="submit" class="btn btn-sm <?= $aff->active ? 'btn-warn' : 'btn-quaternary' ?>">
                  <?= $aff->active ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>

              <form method="POST"
                    action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                    style="display:contents"
                    data-del-modal data-del-entity="affiliate"
                    data-del-name="<?= View::e($aff->name ?? '') ?>">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="from_listing" value="<?= (int) $listing->id ?>">
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
</div><!-- /#tab-affiliates -->

<!--  TAB: EMBED  -->
<div id="tab-embed" class="tabs-panel">
<?php
/** @var array  $embed_settings */
/** @var array  $cfg */
$embedSettings  = $embed_settings ?? [];
$installPath    = rtrim((string) ($cfg['install_path']   ?? ''), '/');
$embedBaseUrl   = rtrim((string) ($cfg['embed_base_url'] ?? ''), '/') ?: rtrim((string) ($cfg['site_url'] ?? ''), '/');
?>

<div class="card">
  <div class="card-head">
    <span>Embed Settings</span>
    <a href="<?= $b ?>/admin/embed-codes?listing=<?= (int) $listing->id ?>" class="btn btn-ghost btn-sm">
      Embed Codes &rarr;
    </a>
  </div>
  
    <div class="panel-content">

  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/embed">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <div class="pc-section-label">Form Page URLs</div>
      <p >
        Enter the <strong>page filename</strong> on your site containing each embedded form (e.g. <code>join.php</code>)
      </p>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label-sm" for="fl-form-join">Join page</label>
          <input id="fl-form-join" type="text" name="form_url_join" class="form-input"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_join'] !== '' ? $embedSettings['form_url_join'] : 'join.php') ?>">
        </div>
        <div class="form-group">
          <label class="form-label-sm" for="fl-form-update">Update page</label>
          <input id="fl-form-update" type="text" name="form_url_update" class="form-input"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_update'] !== '' ? $embedSettings['form_url_update'] : 'update.php') ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label-sm" for="fl-form-remove">Remove page</label>
          <input id="fl-form-remove" type="text" name="form_url_remove" class="form-input"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_remove'] !== '' ? $embedSettings['form_url_remove'] : 'remove.php') ?>">
        </div>
        <div class="form-group">
          <label class="form-label-sm" for="fl-form-reset">Password reset page</label>
          <input id="fl-form-reset" type="text" name="form_url_reset" class="form-input"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_reset'] !== '' ? $embedSettings['form_url_reset'] : 'reset.php') ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label-sm" for="fl-form-codes-submit">Code submission page</label>
          <input id="fl-form-codes-submit" type="text" name="form_url_codes_submit" class="form-input"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_codes_submit'] !== '' ? $embedSettings['form_url_codes_submit'] : 'codes-submit.php') ?>">
        </div>
      </div>

      <div class="section-label">Embed Snippets</div>
      <?php if ($installPath !== ''): ?>
        <p style="font-size:.78rem;color:var(--text-dim);margin-bottom:.875rem;line-height:1.5">
          Paste these lines into your own site&rsquo;s pages to embed this listing&rsquo;s forms.
        </p>
        <?php foreach (['join', 'members', 'update', 'remove'] as $page): ?>
        <div class="form-group">
          <label class="form-label-sm" style="text-transform:capitalize"><?= View::e($page) ?> page</label>
          <div class="ro-field" style="font-family:'DM Mono',monospace;font-size:0.8rem;user-select:all">
            $luster_fanlisting_id = <?= (int) $listing->id ?>;<br>
            require '<?= View::e($installPath) ?>/public/embed/<?= View::e($page) ?>.php';
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p style="font-size:.78rem;color:var(--text-dim);line-height:1.5">
          Set the <strong style="color:var(--text-muted)">Installation Path</strong> in
          <a href="<?= $b ?>/admin/settings" class="link">Settings &rarr; Embed &amp; Paths</a>
          to generate embed snippets here.
        </p>
      <?php endif; ?>

    </div>
    <div class="card-footer">
      <button type="submit" class="btn btn-primary">Save Embed Settings</button>
    </div>
  </form>
</div>
</div><!-- /#tab-embed -->

<!--  TAB: ADVANCED  -->
<div id="tab-advanced" class="tabs-panel">

  <!-- Warning banner -->
  <div style="display:flex;align-items:flex-start;gap:.75rem;padding:.875rem 1.1rem;margin-bottom:1.25rem;background:var(--warn-bg);border:1px solid var(--warn);border-left-width:4px;border-radius:10px">
    <svg style="flex-shrink:0;margin-top:.1rem;color:var(--warn)" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div>
      <strong style="font-size:.8rem;color:var(--warn);display:block;margin-bottom:.2rem">Advanced settings - proceed with caution</strong>
      <span style="font-size:.75rem;color:var(--text-muted);line-height:1.5">Only modify these settings if you know what you are doing. Incorrect values can break your listing or prevent members from being found.</span>
    </div>
  </div>

  <!-- Database Prefix (collapsible, collapsed by default) -->
  <details class="card adv-collapsible" style="overflow:hidden;margin-bottom:1.25rem">
    <summary class="card-head">
      <div class="card-head-text">
        <span>Database Prefix</span>
        <span class="card-head-sub">The member table name in your database. Most users never need to change this.</span>
      </div>
      <svg class="adv-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;transition:transform .2s"><polyline points="4 6 8 10 12 6"/></svg>
    </summary>
    <div style="padding:1rem 1.25rem">
      <div style="display:flex;align-items:center;gap:.75rem">
        <input type="text" class="form-input" value="<?= View::e($listing->dbprefix) ?>"
               readonly style="color:var(--text-dim);cursor:not-allowed;font-family:'DM Mono',monospace;max-width:260px">
        <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit-prefix"
           class="btn btn-tertiary btn-sm" style="white-space:nowrap;flex-shrink:0">Edit</a>
      </div>
    </div>
  </details>

  <!-- Per-listing Database Connection (collapsible, collapsed by default) -->
  <details class="card adv-collapsible" style="overflow:hidden">
    <summary class="card-head">
      <div class="card-head-text">
        <span>Per-listing Database Connection</span>
        <span class="card-head-sub">Override the global database connection for this listing only. Leave all fields blank to use the global database.</span>
      </div>
      <svg class="adv-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;transition:transform .2s"><polyline points="4 6 8 10 12 6"/></svg>
    </summary>
    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/embed">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <?php
      /** @var array $embed_settings */
      /** @var array $cfg */
      $advEmbedSettings = $embed_settings ?? [];
      ?>
      <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border)">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label-sm" for="adv-db-host">DB Host</label>
            <input id="adv-db-host" type="text" name="db_host" class="form-input"
                   maxlength="255" placeholder="localhost"
                   value="<?= View::e($advEmbedSettings['db_host'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label-sm" for="adv-db-name">DB Name</label>
            <input id="adv-db-name" type="text" name="db_name" class="form-input"
                   maxlength="255" placeholder="my_database"
                   value="<?= View::e($advEmbedSettings['db_name'] ?? '') ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label-sm" for="adv-db-user">DB User</label>
            <input id="adv-db-user" type="text" name="db_user" class="form-input"
                   maxlength="255" autocomplete="off"
                   value="<?= View::e($advEmbedSettings['db_user'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label class="form-label-sm" for="adv-db-pass">DB Password</label>
            <div style="position:relative">
              <input id="adv-db-pass" type="password" name="db_pass" class="form-input"
                     style="padding-right:42px" maxlength="500" autocomplete="new-password"
                     placeholder="Leave blank to keep current">
              <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1"
                      style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-dim);padding:4px;line-height:0"
                      onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-dim)'">
                <?= View::icon('eye-on') ?>
                <?= View::icon('eye-off') ?>
              </button>
            </div>
            <p class="form-hint-sm">Only fill in to change the stored password.</p>
          </div>
        </div>
      </div>
      <div class="card-footer">
        <button type="submit" class="btn btn-primary">Save Database Settings</button>
      </div>
    </form>
  </details>

</div><!-- /#tab-advanced -->

