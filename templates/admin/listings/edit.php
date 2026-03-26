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
  <a href="<?= $b ?>/admin/listings" class="back-link">
    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" width="14" height="14"><path d="M10 3L5 8l5 5"/></svg>
    Back to Owned Listings
  </a>
  <div class="topbar-actions">
    <a href="<?= $b ?>/admin/members/<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">Members</a>
    <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/codes" class="btn btn-quaternary btn-sm">Codes</a>
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
  <div class="val" style="font-size:1.2rem"><?= View::e(($fl_last_updated ?? '') !== '' ? date($admin_date_format, strtotime($fl_last_updated)) : 'â€”') ?></div>
  <div class="lbl">Date Last Updated</div>
</div>
</div>

<div class="neb-tabs-wrap">
<div class="neb-tabs">
  <button class="neb-tab-btn active" data-tab="general">General</button>
  <button class="neb-tab-btn" data-tab="fields">Additional Fields</button>
  <button class="neb-tab-btn" data-tab="affiliates">Affiliates</button>
  <button class="neb-tab-btn" data-tab="templates">Templates</button>
  <button class="neb-tab-btn" data-tab="embed">Embed</button>
  <button class="neb-tab-btn" data-tab="advanced">Advanced</button>
</div>
<div class="neb-tabs-select-wrap">
  <span class="neb-tabs-select-label">Navigation</span>
  <div class="select-wrap" style="flex:1">
    <select class="neb-tabs-select select">
      <option value="general">General</option>
      <option value="fields">Additional Fields</option>
      <option value="affiliates">Affiliates</option>
      <option value="templates">Templates</option>
      <option value="embed">Embed</option>
      <option value="advanced">Advanced</option>
    </select>
  </div>
</div>
</div>

<!-- â•â•â• TAB: GENERAL â•â•â• -->
<div id="tab-general" class="neb-tab-panel active">
  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" enctype="multipart/form-data" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="edit-layout">

      <!-- â”€â”€ Left: main card â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
      <div class="main-card">
        <div class="card-section">

          <div class="field-row field-row--top">
            <label class="field-label">Network &amp; Category</label>
            <div>
              <div class="cat-widget" id="cat-widget"
                   data-tfl='<?= $dataTfl ?>'
                   data-tafl='<?= $dataTafl ?>'
                   data-custom='<?= $dataCustom ?>'
                   data-existing='<?= $dataExisting ?>'>
                <div id="cat-rows"></div>
                <a href="#" class="link" id="cat-add" style="font-size:.8125rem;display:inline-block;margin-top:.4rem">+ Add another category</a>
              </div>
              <p class="field-hint-sm">Assign to one or more networks (TFL, TAFL, custom).</p>
            </div>
          </div>

          <div class="field-row field-row--top">
            <label class="field-label" for="fl-subject">Subject</label>
            <div>
              <input id="fl-subject" type="text" name="subject" class="form-input"
                     value="<?= View::e($listing->subject ?? '') ?>" maxlength="255">
              <p class="field-hint-sm">The subject of your listing (e.g. character name, show title).</p>
            </div>
          </div>

          <div class="field-row<?= !empty($fe['title']) ? ' has-error' : '' ?>">
            <label class="field-label" for="fl-title">Title</label>
            <div>
              <input id="fl-title" type="text" name="title" class="form-input"
                     value="<?= View::e((string) $oldTitle) ?>"
                     maxlength="255" required>
              <?php if (!empty($fe['title'])): ?><p class="field-error"><?= View::e($fe['title'][0]) ?></p><?php endif; ?>
            </div>
          </div>

          <div class="field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
            <label class="field-label" for="fl-url">Website URL <span class="req">*</span></label>
            <div>
              <input id="fl-url" type="url" name="url" class="form-input"
                     value="<?= View::e((string) $oldUrl) ?>"
                     maxlength="500" placeholder="https://example.com" required>
              <?php if (!empty($fe['url'])): ?><p class="field-error"><?= View::e($fe['url'][0]) ?></p><?php endif; ?>
            </div>
          </div>

          <div class="field-row field-row--top<?= !empty($fe['email']) ? ' has-error' : '' ?>">
            <label class="field-label" for="fl-email">Contact Email</label>
            <div>
              <input id="fl-email" type="email" name="email" class="form-input"
                     value="<?= View::e((string) $oldEmail) ?>"
                     maxlength="255" required>
              <p class="field-hint-sm">Address listing emails are sent from.</p>
              <?php if (!empty($fe['email'])): ?><p class="field-error"><?= View::e($fe['email'][0]) ?></p><?php endif; ?>
            </div>
          </div>

          <div class="field-row">
            <label class="field-label">Status</label>
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

          <div class="field-row" id="opened-row">
            <label class="field-label" for="fl-opened">Opened On</label>
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

        </div><!-- /.card-section (identity) -->

        <div class="card-section">

          <div class="field-row field-row--top">
            <label class="field-label">Listing Image</label>
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
              <label class="file-drop">
                <input type="file" id="fl-image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
                <svg class="file-drop-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="2" width="12" height="12" rx="1.5"/><circle cx="5.5" cy="5.5" r="1"/><path d="M14 10l-3.5-3.5L6 11l-2-2-2 2"/></svg>
                <span class="file-drop-label">Click to upload</span>
                <span class="file-drop-sub">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
              </label>
            </div>
          </div>

        </div><!-- /.card-section (image) -->

        <div class="card-footer">
          <button type="submit" class="btn btn-primary">Save Changes</button>
          <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
        </div>

      </div><!-- /.main-card -->

      <!-- â”€â”€ Right: panel column â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
      <div class="panel-col">

        <!-- Behaviour -->
        <div class="panel-card">
          <div class="card-head">
            <span class="card-head-title">Behaviour</span>
          </div>
          <div class="panel-body">
            <?php $lt = $listing_type ?? 'listing'; ?>
            <div class="panel-field">
              <label class="field-label" for="fl-listing-type">Type</label>
              <div class="select-wrap">
                <select id="fl-listing-type" name="listing_type" class="panel-select">
                  <option value="listing"    <?= $lt === 'listing'    ? 'selected' : '' ?>>Listing</option>
                  <option value="clique"     <?= $lt === 'clique'     ? 'selected' : '' ?>>Clique</option>
                  <option value="collective" <?= $lt === 'collective' ? 'selected' : '' ?>>Collective</option>
                  <option value="shrine"     <?= $lt === 'shrine'     ? 'selected' : '' ?>>Shrine</option>
                  <option value="webring"    <?= $lt === 'webring'    ? 'selected' : '' ?>>Webring</option>
                  <option value="other"      <?= $lt === 'other'      ? 'selected' : '' ?>>Other</option>
                </select>
              </div>
            </div>
            <div class="panel-field">
              <label class="field-label" for="fl-members-per-page">Members per page</label>
              <input id="fl-members-per-page" type="number" name="members_per_page" class="panel-input"
                     min="5" max="200" value="<?= (int) ($members_per_page ?? 25) ?>">
              <p class="panel-hint">Admin list view (5â€“200).</p>
            </div>
            <div class="panel-field">
              <label class="field-label">Contact Form</label>
              <label class="toggle-wrap">
                <input type="checkbox" name="contact_enabled" value="1"
                       <?= ($contact_enabled ?? true) ? 'checked' : '' ?>>
                <span class="toggle-label">Enable contact form for this listing</span>
              </label>
            </div>
            <div class="panel-field">
              <label class="field-label" for="fl-contact-recipient">Contact recipient email</label>
              <input id="fl-contact-recipient" type="email" name="contact_recipient" class="panel-input"
                     maxlength="255" placeholder="Defaults to listing email"
                     value="<?= View::e($contact_recipient ?? '') ?>">
              <p class="panel-hint">Override which email address receives contact messages for this listing. Leave blank to use the listing's email address.</p>
            </div>
          </div>
        </div>

        <!-- Maintenance -->
        <div class="panel-card">
          <div class="card-head">
            <span class="card-head-title">Maintenance</span>
          </div>
          <div class="panel-body">
            <div class="panel-field">
              <label class="field-label" for="fl-last-updated">Last Updated</label>
              <input id="fl-last-updated" type="date" name="fl_last_updated" class="panel-input"
                     value="<?= View::e($fl_last_updated ?? '') ?>">
              <div class="panel-mini-row">
                <button type="button" class="btn btn-quaternary btn-sm" id="set-today-btn">Today</button>
                <button type="button" class="btn btn-ghost btn-sm" id="clear-date-btn">Clear</button>
              </div>
              <p class="panel-hint">Drives the staleness check on the dashboard.</p>
            </div>
          </div>
        </div>

      </div><!-- /.panel-col -->

    </div><!-- /.edit-layout -->
  </form>
</div><!-- /#tab-general -->

<!-- â•â•â• TAB: TEMPLATES â•â•â• -->
<div id="tab-templates" class="neb-tab-panel">
<div style="display:grid;grid-template-columns:1fr 260px;gap:1.25rem;align-items:start">

  <!-- Left: template editor -->
  <div class="card" style="overflow:hidden">
    <div class="card-head">
      <div class="card-head-text">
        <span class="card-head-title">HTML Templates</span>
        <span class="card-head-sub">Controls how members, affiliates, and stats render on your site.</span>
      </div>
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
        <textarea id="members_template" name="members_template" class="form-textarea" rows="6"><?= View::e($templates['members_template'] ?? '<li><a href="{fan_url}">{fan_name}</a> â€” {fan_country}</li>') ?></textarea>
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
      <span class="card-head-title">Available Tokens</span>
    </div>
    <details class="side-card" open>
      <summary class="side-card-head">
        <svg class="side-card-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h8M4 8h5M4 12h3"/></svg>
        <span class="side-card-title">Members List</span>
        <svg class="side-card-chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 6 8 10 12 6"/></svg>
      </summary>
      <div class="side-card-body">
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

    <details class="side-card" open>
      <summary class="side-card-head">
        <svg class="side-card-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h8M4 8h5M4 12h3"/></svg>
        <span class="side-card-title">Affiliates</span>
        <svg class="side-card-chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 6 8 10 12 6"/></svg>
      </summary>
      <div class="side-card-body">
        <table class="et-token-table">
          <tbody>
            <tr class="tok-row" data-textarea="affiliates_template" data-token="{aff_url}"   title="Click to insert"><td><code>{aff_url}</code></td><td>Affiliate URL</td></tr>
            <tr class="tok-row" data-textarea="affiliates_template" data-token="{aff_image}" title="Click to insert"><td><code>{aff_image}</code></td><td>Affiliate image tag</td></tr>
            <tr class="tok-row" data-textarea="affiliates_template" data-token="{aff_name}"  title="Click to insert"><td><code>{aff_name}</code></td><td>Affiliate name</td></tr>
          </tbody>
        </table>
      </div>
    </details>

    <details class="side-card" open>
      <summary class="side-card-head">
        <svg class="side-card-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 4h8M4 8h5M4 12h3"/></svg>
        <span class="side-card-title">Statistics</span>
        <svg class="side-card-chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 6 8 10 12 6"/></svg>
      </summary>
      <div class="side-card-body">
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

<!-- â•â•â• TAB: ADDITIONAL FIELDS â•â•â• -->
<div id="tab-fields" class="neb-tab-panel">

  <?php if ($listing->dbprefix === ''): ?>
  <div class="card card-padded">
    <p class="text-muted" style="font-size:0.875rem">Additional fields require a member table. This listing has no database table configured yet.</p>
  </div>
  <?php else: ?>

  <!-- Add New Field -->
  <div class="card" style="margin-bottom:1.25rem;overflow:hidden">
    <div class="card-head">
      <div class="card-head-text">
        <span class="card-head-title">Add New Field</span>
        <span class="card-head-sub">Letters (aâ€“z, Aâ€“Z), numbers, and underscores only. No spaces. Max 30 chars, unique per listing.</span>
      </div>
      <button type="submit" form="fields-add-form" class="btn btn-primary btn-sm">Add Field</button>
    </div>
    <div style="padding:1rem 1.25rem">
    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/add" id="fields-add-form">
      <input type="hidden" name="_csrf"     value="<?= View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="from_edit" value="1">
      <div style="display:grid;grid-template-columns:1fr 1fr 140px;gap:0.75rem;align-items:end">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Field Name</label>
          <input type="text" name="name" class="form-input" maxlength="30" pattern="[a-zA-Z0-9_]+"
                 placeholder="e.g. fave_song" required
                 title="Letters, numbers and underscores only">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Label</label>
          <input type="text" name="label" class="form-input" maxlength="100" placeholder="e.g. Favourite Song" required>
        </div>
        <div class="form-group" style="margin-bottom:0">
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
      <div id="fields-options-row" style="display:none;margin-top:0.75rem">
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Options <span class="form-hint" style="display:inline;text-transform:none;letter-spacing:0">(comma-separated, for Drop Down, Checkbox, and Radio)</span></label>
          <textarea name="options" class="form-textarea" rows="2"
                    placeholder="e.g. Option A, Option B, Option C"></textarea>
        </div>
      </div>
    </form>
    </div>
  </div>

  <!-- Existing Fields -->
  <div class="card">
    <div class="card-head">
      <span class="card-head-title">Current Fields</span>
    </div>
    <?php if (empty($fields)): ?>
      <p class="text-muted" style="padding:1rem 1.25rem;font-size:0.875rem">No additional fields defined yet.</p>
    <?php else: ?>
      <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Label</th>
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
              <code style="background:var(--bg-subtle);padding:0.1rem 0.35rem;border-radius:4px;font-size:0.75rem;font-family:'DM Mono',monospace"><?= View::e($field->name) ?></code>
            </td>
            <td><?= View::e($field->type) ?></td>
            <td class="act-col">
              <div class="row-actions">
                <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/<?= urlencode($field->name) ?>/edit"
                   class="btn btn-quaternary btn-sm">Edit</a>
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

</div><!-- /#tab-fields -->

<!-- â•â•â• TAB: AFFILIATES â•â•â• -->
<div id="tab-affiliates" class="neb-tab-panel">
<div class="card">
  <div class="card-head">
    <span class="card-head-title">Affiliates</span>
    <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/affiliates/add"
       class="btn btn-primary btn-sm">+ Add Affiliate</a>
  </div>

  <?php if (empty($affiliates)): ?>
    <p class="text-muted" style="padding:1rem 1.25rem;font-size:0.875rem">No affiliates for this listing yet.</p>
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
            <div class="row-actions">
              <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit?from_listing=<?= (int) $listing->id ?>"
                 class="btn btn-quaternary btn-sm">Edit</a>

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

<!-- â•â•â• TAB: EMBED â•â•â• -->
<div id="tab-embed" class="neb-tab-panel">
<?php
/** @var array  $embed_settings */
/** @var array  $cfg */
$embedSettings  = $embed_settings ?? [];
$installPath    = rtrim((string) ($cfg['install_path']   ?? ''), '/');
$embedBaseUrl   = rtrim((string) ($cfg['embed_base_url'] ?? ''), '/') ?: rtrim((string) ($cfg['site_url'] ?? ''), '/');
?>

<div class="card" style="overflow:hidden">
  <div class="card-head">
    <span class="card-head-title">Embed Settings</span>
    <a href="<?= $b ?>/admin/embed-codes?listing=<?= (int) $listing->id ?>" class="btn btn-ghost btn-sm">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
      Embed Codes &rarr;
    </a>
  </div>
  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/embed">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border)">

      <div class="section-label">Form Page URLs</div>
      <p style="font-size:.78rem;color:var(--text-dim);margin-bottom:.875rem;line-height:1.5">
        Enter the <strong style="color:var(--text-muted)">page filename</strong> on your site containing each embedded form
        (e.g. <span class="neb-code">join.php</span>). Used to redirect visitors back to your page after form submissions and errors.
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

<!-- â•â•â• TAB: ADVANCED â•â•â• -->
<div id="tab-advanced" class="neb-tab-panel">

  <!-- Warning banner -->
  <div style="display:flex;align-items:flex-start;gap:.75rem;padding:.875rem 1.1rem;margin-bottom:1.25rem;background:var(--warn-bg);border:1px solid var(--warn);border-left-width:4px;border-radius:10px">
    <svg style="flex-shrink:0;margin-top:.1rem;color:var(--warn)" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    <div>
      <strong style="font-size:.8rem;color:var(--warn);display:block;margin-bottom:.2rem">Advanced settings â€” proceed with caution</strong>
      <span style="font-size:.75rem;color:var(--text-muted);line-height:1.5">Only modify these settings if you know what you are doing. Incorrect values can break your listing or prevent members from being found.</span>
    </div>
  </div>

  <!-- Database Prefix (collapsible, collapsed by default) -->
  <details class="card adv-collapsible" style="overflow:hidden;margin-bottom:1.25rem">
    <summary class="card-head" style="cursor:pointer;user-select:none">
      <div class="card-head-text">
        <span class="card-head-title">Database Prefix</span>
        <span class="card-head-sub">The member table name in your database. Most users never need to change this.</span>
      </div>
      <svg class="adv-chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;transition:transform .2s"><polyline points="4 6 8 10 12 6"/></svg>
    </summary>
    <div style="padding:1rem 1.25rem">
      <div style="display:flex;align-items:center;gap:.75rem">
        <input type="text" class="form-input" value="<?= View::e($listing->dbprefix) ?>"
               readonly style="color:var(--text-dim);cursor:not-allowed;font-family:'DM Mono',monospace;max-width:260px">
        <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit-prefix"
           class="btn btn-quaternary btn-sm" style="white-space:nowrap;flex-shrink:0">Edit</a>
      </div>
    </div>
  </details>

  <!-- Per-listing Database Connection (collapsible, collapsed by default) -->
  <details class="card adv-collapsible" style="overflow:hidden">
    <summary class="card-head" style="cursor:pointer;user-select:none">
      <div class="card-head-text">
        <span class="card-head-title">Per-listing Database Connection</span>
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
            <div class="input-icon-wrap">
              <input id="adv-db-pass" type="password" name="db_pass" class="form-input"
                     maxlength="500" autocomplete="new-password"
                     placeholder="Leave blank to keep current">
              <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
                <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
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

