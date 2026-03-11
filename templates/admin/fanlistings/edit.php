<?php
/** @var \Luster\Models\Fanlisting    $fanlisting */
/** @var bool                         $auto_approve */
/** @var string                       $error */
/** @var string                       $success */
/** @var array                        $old */
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
$b = rtrim(\Luster\Core\View::e($base_url), '/');

$sizeMap   = ['small' => '50px', 'medium' => '100px', 'large' => '150px'];
$sizeKey   = (string) ($cfg['image_preview_size'] ?? 'medium');
$imgHeight = $sizeMap[$sizeKey] ?? '100px';

// Resolve display values: prefer $old (re-populates on validation fail) then model
$oldTitle  = $old['title']  ?? $fanlisting->name;
$oldUrl    = $old['url']    ?? $fanlisting->url;
$oldEmail  = $old['email']  ?? $fanlisting->email;

// Resolve current status string
if ($fanlisting->upcoming) {
    $currentStatus = 'upcoming';
} elseif ($fanlisting->trouble) {
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
$catidRaw     = (string) ($fanlisting->catid ?? '');
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

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
  <a href="<?= $b ?>/admin/fanlistings" class="neb-link">&larr; Back to Owned Listings</a>
  <div style="display:flex;gap:.5rem;">
    <a href="<?= $b ?>/admin/members/<?= (int) $fanlisting->id ?>" class="btn btn-secondary btn-sm">Members</a>
    <form method="post"
          action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/delete"
          style="display:inline"
          onsubmit="return confirm('Delete this fanlisting and all its members? This cannot be undone.')">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
    </form>
  </div>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem">
<?php $pl = \Luster\Core\View::e($b . '/admin/members/' . (int) $fanlisting->id . '?status=pending'); ?>
<div class="neb-card" style="padding:1rem 1.25rem">
  <span style="font-size:1.75rem;font-weight:700;color:var(--text)"><?= (int) $approved ?></span><br>
  <span class="text-dim">Total Members</span>
</div>
<div class="neb-card" style="padding:1rem 1.25rem">
  <span style="font-size:1.75rem;font-weight:700;color:var(--text)"><?= $pending > 0 ? '<a href="' . $pl . '" class="neb-link">' . $pending . '</a>' : $pending ?></span><br>
  <span class="text-dim">Pending</span>
</div>
<div class="neb-card" style="padding:1rem 1.25rem">
  <span style="font-size:1.75rem;font-weight:700;color:var(--text)"><?= (int) ($countries ?? 0) ?></span><br>
  <span class="text-dim">Total Countries</span>
</div>
<div class="neb-card" style="padding:1rem 1.25rem">
  <span style="font-size:1.75rem;font-weight:700;color:var(--text)"><?= \Luster\Core\View::e($last_updated ?? '—') ?></span><br>
  <span class="text-dim">Date Last Updated</span>
</div>
</div>

<div class="neb-tabs">
  <button class="neb-tab-btn active" data-tab="general">General</button>
  <button class="neb-tab-btn" data-tab="fields">Additional Fields</button>
  <button class="neb-tab-btn" data-tab="affiliates">Affiliates</button>
  <button class="neb-tab-btn" data-tab="templates">Templates</button>
</div>
<script>
document.querySelectorAll('.neb-tab-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.querySelectorAll('.neb-tab-btn, .neb-tab-panel').forEach(function (el) {
      el.classList.remove('active');
    });
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
  });
});
</script>

<!-- ═══ TAB: GENERAL ═══ -->
<div id="tab-general" class="neb-tab-panel active">
<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label class="form-label">Category</label>
      <div class="cat-widget" id="cat-widget"
           data-tfl='<?= $dataTfl ?>'
           data-tafl='<?= $dataTafl ?>'
           data-custom='<?= $dataCustom ?>'
           data-existing='<?= $dataExisting ?>'>
        <div id="cat-rows"></div>
        <a href="#" class="neb-link" id="cat-add" style="font-size:0.8125rem;display:inline-block;margin-top:0.4rem">+ Add another category</a>
      </div>
      <p class="form-hint">Assign to one or more networks (TFL, TAFL, custom).</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-subject">Subject</label>
      <input id="fl-subject" type="text" name="subject" class="form-input"
             value="<?= \Luster\Core\View::e($fanlisting->subject ?? '') ?>" maxlength="255">
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-title">Title</label>
      <input id="fl-title" type="text" name="title" class="form-input"
             value="<?= \Luster\Core\View::e((string) $oldTitle) ?>"
             maxlength="255" required>
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-url">Website URL</label>
      <input id="fl-url" type="url" name="url" class="form-input"
             value="<?= \Luster\Core\View::e((string) $oldUrl) ?>"
             maxlength="500" placeholder="https://example.com" required>
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-email">Contact Email</label>
      <input id="fl-email" type="email" name="email" class="form-input"
             value="<?= \Luster\Core\View::e((string) $oldEmail) ?>"
             maxlength="255" required>
    </div>

    <div class="form-group">
      <label class="form-label">Status</label>
      <div style="display:flex;gap:1.5rem;margin-top:0.35rem">
        <label class="form-check">
          <input type="radio" name="status" value="current" <?= $oldStatus === 'current'  ? 'checked' : '' ?>>
          <span class="form-check-label">Current</span>
        </label>
        <label class="form-check">
          <input type="radio" name="status" value="upcoming" <?= $oldStatus === 'upcoming' ? 'checked' : '' ?>>
          <span class="form-check-label">Upcoming</span>
        </label>
        <label class="form-check">
          <input type="radio" name="status" value="pending" <?= $oldStatus === 'pending'  ? 'checked' : '' ?>>
          <span class="form-check-label">Pending</span>
        </label>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-opened">Opened On</label>
      <input id="fl-opened" type="date" name="opened" class="form-input" style="max-width:200px"
             value="<?= \Luster\Core\View::e(strlen((string) ($fanlisting->opened ?? '')) >= 10 ? substr((string) $fanlisting->opened, 0, 10) : '') ?>">
    </div>

    <div class="section-divider">Luster Settings</div>

    <div class="form-group">
      <label class="form-check">
        <input id="auto-approve" type="checkbox" name="auto_approve" value="1"
               <?= $auto_approve ? 'checked' : '' ?>>
        <span class="form-check-label">Auto-approve new join requests</span>
      </label>
      <p class="form-hint" style="margin-top:0.35rem">
        When enabled, members are approved immediately on join (no admin review).
        Requires <code>migrate.php</code> to have been run.
      </p>
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-listing-type">Listing Type</label>
      <?php $lt = $listing_type ?? 'fanlisting'; ?>
      <div class="select-wrap">
        <select id="fl-listing-type" name="listing_type" class="form-select">
          <option value="fanlisting"  <?= $lt === 'fanlisting'  ? 'selected' : '' ?>>Fanlisting</option>
          <option value="clique"      <?= $lt === 'clique'      ? 'selected' : '' ?>>Clique</option>
          <option value="collective"  <?= $lt === 'collective'  ? 'selected' : '' ?>>Collective</option>
          <option value="shrine"      <?= $lt === 'shrine'      ? 'selected' : '' ?>>Shrine</option>
          <option value="webring"     <?= $lt === 'webring'     ? 'selected' : '' ?>>Webring</option>
          <option value="other"       <?= $lt === 'other'       ? 'selected' : '' ?>>Other</option>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-members-per-page">Members Per Page</label>
      <input id="fl-members-per-page" type="number" name="members_per_page" class="form-input"
             min="5" max="200" style="max-width:120px"
             value="<?= (int) ($members_per_page ?? 25) ?>">
      <p class="form-hint">How many members to show per page in the admin panel (5–200).</p>
    </div>

    <div class="section-divider">Fanlisting Image</div>

    <div class="form-group">
      <?php if (isset($current_image) && $current_image !== null && $current_image !== ''): ?>
        <div style="margin-bottom:0.6rem">
          <img src="<?= $b ?>/uploads/fanlistings/<?= \Luster\Core\View::e($current_image) ?>"
               alt="Current fanlisting image"
               style="max-height:<?= \Luster\Core\View::e($imgHeight) ?>;width:auto;display:block;border-radius:6px;border:1px solid var(--border)">
        </div>
        <label class="form-check" style="margin-bottom:0.6rem">
          <input type="checkbox" id="fl-delete-image" name="delete_image" value="1">
          <span class="form-check-label">Delete current image</span>
        </label>
        <label class="form-label" for="fl-image">Replace with new image</label>
      <?php else: ?>
        <label class="form-label" for="fl-image">Upload image</label>
      <?php endif; ?>
      <input type="file" id="fl-image" name="image" accept="image/*">
      <p class="form-hint" style="margin-top:0.35rem">JPEG, PNG, GIF, or WebP. Max 2 MB. Leave empty to keep the current image.</p>
    </div>

    <div class="section-divider">Database</div>

    <div class="form-group">
      <label class="form-label">Listing ID#</label>
      <div style="display:flex;align-items:center;gap:0.75rem">
        <input type="text" class="form-input" value="<?= \Luster\Core\View::e($fanlisting->dbprefix) ?>"
               readonly style="color:var(--text-dim);cursor:not-allowed;flex:1">
        <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit-prefix"
           class="btn btn-secondary btn-sm" style="white-space:nowrap">Edit Prefix</a>
      </div>
      <p class="form-hint">The member table name in your database.</p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/fanlistings" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
</div><!-- /#tab-general -->

<!-- ═══ TAB: TEMPLATES ═══ -->
<div id="tab-templates" class="neb-tab-panel">
<div class="form-card">
  <div class="form-group">
    <label class="form-label">Stats URL</label>
    <input type="text" class="form-input" readonly
           value="<?= $b ?>/stats/<?= (int) $fanlisting->id ?>"
           style="font-family:'DM Mono',monospace;font-size:0.8125rem;color:var(--text-muted)">
  </div>

  <form method="POST" action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/templates">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label class="form-label" for="members_template">Members List</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;margin-bottom:0.5rem;line-height:1.8">
        $$fan_name$$ &bull; $$fan_email$$ &bull; $$fan_url$$ &bull; $$fan_country$$
      </p>
      <textarea id="members_template" name="members_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:0.8125rem"
                placeholder="Leave blank to use the built-in default"><?= \Luster\Core\View::e($templates['members_template'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
      <label class="form-label" for="affiliates_template">Affiliates</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;margin-bottom:0.5rem;line-height:1.8">
        $$aff_url$$ &bull; $$aff_image$$ &bull; $$aff_name$$
      </p>
      <textarea id="affiliates_template" name="affiliates_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:0.8125rem"
                placeholder="Leave blank to use the built-in default"><?= \Luster\Core\View::e($templates['affiliates_template'] ?? '') ?></textarea>
    </div>

    <div class="form-group">
      <label class="form-label" for="stats_template">Statistics</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;margin-bottom:0.5rem;line-height:1.8">
        $$stat_opened$$ &bull; $$stat_updated$$ &bull; $$stat_approved$$ &bull; $$stat_pending$$ &bull; $$stat_newmembers$$
      </p>
      <textarea id="stats_template" name="stats_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:0.8125rem"
                placeholder="Leave blank to use the built-in default"><?= \Luster\Core\View::e($templates['stats_template'] ?? '') ?></textarea>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Templates</button>
    </div>
  </form>
</div>
</div><!-- /#tab-templates -->

<!-- ═══ TAB: ADDITIONAL FIELDS ═══ -->
<div id="tab-fields" class="neb-tab-panel">

  <?php if ($fanlisting->dbprefix === ''): ?>
  <div class="form-card">
    <p class="text-muted" style="font-size:0.875rem">Additional fields require a member table. This listing has no database table configured yet.</p>
  </div>
  <?php else: ?>

  <!-- Add New Field -->
  <div class="form-card" style="margin-bottom:1.25rem">
    <h2 style="font-size:1rem;font-weight:700;color:var(--text);margin:0 0 0.5rem">Add New Field</h2>
    <p class="form-hint" style="margin:0 0 1rem;line-height:1.6">
      <strong style="color:var(--text-muted)">Field Name Rules:</strong>
      Letters (a-z, A-Z), numbers (0-9), and underscores (_).<br>
      NO spaces, use underscores in place of spaces (i.e. <code>fave_song</code>, <code>vanilla_or_chocolate</code>), NO punctuation, NO special characters (hyphens, dots, slashes, etc.).<br>
      Max length 30 characters. Must be unique per fanlisting.
    </p>
    <form method="POST" action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/fields/add" id="fields-add-form">
      <input type="hidden" name="_csrf"     value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="from_edit" value="1">
      <div style="display:grid;grid-template-columns:1fr 1fr 140px 100px auto;gap:0.75rem;align-items:end">
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
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label">Required</label>
          <div style="display:flex;align-items:center;height:40px">
            <input type="checkbox" name="required" value="1" style="width:auto;margin:0;accent-color:var(--accent)">
          </div>
        </div>
        <div>
          <button type="submit" class="btn btn-primary btn-sm">Add Field</button>
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

  <!-- Existing Fields -->
  <div class="neb-card">
    <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border)">
      <h2 style="font-size:1rem;font-weight:700;color:var(--text);margin:0">Current Fields</h2>
    </div>
    <?php if (empty($fields)): ?>
      <p class="text-muted" style="padding:1rem 1.25rem;font-size:0.875rem">No additional fields defined yet.</p>
    <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Label</th>
            <th>Field Name</th>
            <th>Type</th>
            <th>Required</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fields as $field): ?>
          <tr>
            <td><?= \Luster\Core\View::e($field->label) ?></td>
            <td>
              <code style="background:var(--bg-subtle);padding:0.1rem 0.35rem;border-radius:4px;font-size:0.75rem;font-family:'DM Mono',monospace"><?= \Luster\Core\View::e($field->name) ?></code>
            </td>
            <td><?= \Luster\Core\View::e($field->type) ?></td>
            <td>
              <?php if ($field->required): ?>
                <span class="neb-badge badge-current">Yes</span>
              <?php else: ?>
                <span class="neb-badge" style="background:var(--bg-subtle);color:var(--text-dim);border-color:var(--border)">No</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;white-space:nowrap" class="act-col">
              <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/fields/<?= urlencode($field->name) ?>/edit"
                 class="neb-link" style="font-size:0.8125rem;margin-right:0.5rem">Edit</a>
              <form method="POST"
                    action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/fields/<?= urlencode($field->name) ?>/delete"
                    style="display:inline"
                    onsubmit="return confirm('Drop the column for field \u201c<?= \Luster\Core\View::e(addslashes($field->name)) ?>\u201d? This permanently removes member data and cannot be undone.')">
                <input type="hidden" name="_csrf"     value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="from_edit" value="1">
                <button type="submit" style="background:none;border:none;color:var(--danger);font-size:0.8125rem;cursor:pointer;padding:0">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php endif; ?>

<script>
(function () {
  var s = document.getElementById('fields-type-sel');
  var r = document.getElementById('fields-options-row');
  if (!s) return;
  function t() { r.style.display = (s.value === 'dropdown' || s.value === 'checkbox' || s.value === 'radio') ? '' : 'none'; }
  s.addEventListener('change', t);
  t();
}());
</script>
</div><!-- /#tab-fields -->

<!-- ═══ TAB: AFFILIATES ═══ -->
<div id="tab-affiliates" class="neb-tab-panel">
<div class="neb-card">
  <div style="display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;border-bottom:1px solid var(--border)">
    <h2 style="font-size:1rem;font-weight:700;color:var(--text);margin:0">Affiliates</h2>
    <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/affiliates/add"
       class="btn btn-primary btn-sm">+ Add Affiliate</a>
  </div>

  <?php if (empty($affiliates)): ?>
    <p class="text-muted" style="padding:1rem 1.25rem;font-size:0.875rem">No affiliates for this fanlisting yet.</p>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Status</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($affiliates as $aff): ?>
        <tr>
          <td>
            <a href="<?= \Luster\Core\View::e($aff->url ?? '') ?>" target="_blank" rel="noopener noreferrer"
               class="neb-link"><?= \Luster\Core\View::e($aff->name ?? '') ?></a>
          </td>
          <td>
            <?php if ($aff->active): ?>
              <span class="neb-badge badge-current">Active</span>
            <?php else: ?>
              <span class="neb-badge badge-pending">Inactive</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right" class="act-col">
            <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit?from_fanlisting=<?= (int) $fanlisting->id ?>"
               class="neb-link" style="font-size:0.8125rem;margin-right:0.5rem">Edit</a>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                  style="display:inline">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_fanlisting" value="<?= (int) $fanlisting->id ?>">
              <button type="submit" style="background:none;border:none;color:var(--warn);font-size:0.8125rem;cursor:pointer;padding:0;margin-right:0.5rem">
                <?= $aff->active ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                  style="display:inline"
                  onsubmit="return confirm('Delete this affiliate?')">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_fanlisting" value="<?= (int) $fanlisting->id ?>">
              <button type="submit" style="background:none;border:none;color:var(--danger);font-size:0.8125rem;cursor:pointer;padding:0">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</div><!-- /#tab-affiliates -->

<script>
(function () {
  'use strict';

  var widget   = document.getElementById('cat-widget');
  var rowsEl   = document.getElementById('cat-rows');
  var addLink  = document.getElementById('cat-add');

  var tflCats    = JSON.parse(widget.dataset.tfl);
  var taflCats   = JSON.parse(widget.dataset.tafl);
  var customNets = JSON.parse(widget.dataset.custom);
  var existing   = JSON.parse(widget.dataset.existing);

  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function buildNetOpts(selected) {
    var h = '<option value="">\u2014 select network \u2014</option>';
    var nets = ['TFL', 'TAFL'].concat(customNets).sort(function (a, b) {
      return a.toLowerCase().localeCompare(b.toLowerCase());
    });
    nets.forEach(function (name) {
      h += '<option value="' + esc(name) + '"' + (selected === name ? ' selected' : '') + '>' + esc(name) + '</option>';
    });
    h += '<option value="other"' + (selected === 'other' ? ' selected' : '') + '>Other (add new)</option>';
    return h;
  }

  function buildCatOpts(cats, selected) {
    var h = '<option value="">\u2014 select category \u2014</option>';
    cats.forEach(function (c) {
      h += '<option value="' + esc(c.name) + '"' + (selected === c.name ? ' selected' : '') + '>' + esc(c.name) + '</option>';
    });
    return h;
  }

  function syncRow(row, netVal, catVal) {
    var catSel = row.querySelector('.cat-name-sel');
    var catTxt = row.querySelector('.cat-name-txt');
    var othTxt = row.querySelector('.cat-other-net');
    catVal = catVal || '';

    if (netVal === 'TFL' || netVal === 'TAFL') {
      catSel.innerHTML     = buildCatOpts(netVal === 'TFL' ? tflCats : taflCats, catVal);
      catSel.disabled      = false;
      catSel.style.display = '';
      catTxt.disabled      = true;
      catTxt.style.display = 'none';
      othTxt.style.display = 'none';
    } else if (netVal === 'other') {
      catSel.disabled      = true;
      catSel.style.display = 'none';
      catTxt.disabled      = false;
      catTxt.style.display = '';
      if (catVal) { catTxt.value = catVal; }
      othTxt.style.display = '';
    } else if (netVal !== '') {
      catSel.disabled      = true;
      catSel.style.display = 'none';
      catTxt.disabled      = false;
      catTxt.style.display = '';
      if (catVal) { catTxt.value = catVal; }
      othTxt.style.display = 'none';
    } else {
      catSel.disabled      = true;
      catSel.style.display = 'none';
      catTxt.disabled      = true;
      catTxt.style.display = 'none';
      othTxt.style.display = 'none';
    }
  }

  function makeRow(netVal, catVal, otherNetVal) {
    netVal      = netVal      || '';
    catVal      = catVal      || '';
    otherNetVal = otherNetVal || '';

    var row = document.createElement('div');
    row.className = 'cat-row';
    row.innerHTML =
      '<select name="cat_network[]" class="cat-net-sel">' + buildNetOpts(netVal) + '</select>' +
      '<select name="cat_name[]" class="cat-name-sel" disabled style="display:none"></select>' +
      '<input  type="text" name="cat_network_other[]" class="cat-other-net" value="' + esc(otherNetVal) + '" placeholder="New network name" style="display:none">' +
      '<input  type="text" name="cat_name[]"          class="cat-name-txt"  disabled style="display:none" placeholder="Category name">' +
      '<button type="button" class="cat-remove-btn" title="Remove">&times;</button>';

    row.querySelector('.cat-net-sel').addEventListener('change', function () {
      syncRow(row, this.value, '');
    });
    row.querySelector('.cat-remove-btn').addEventListener('click', function () {
      row.remove();
    });

    if (netVal) { syncRow(row, netVal, catVal); }
    return row;
  }

  existing.forEach(function (entry) {
    rowsEl.appendChild(makeRow(entry.network || '', entry.name || '', ''));
  });

  if (rowsEl.children.length === 0) {
    rowsEl.appendChild(makeRow('', '', ''));
  }

  addLink.addEventListener('click', function (e) {
    e.preventDefault();
    rowsEl.appendChild(makeRow('', '', ''));
  });

  window.addCatRow = function () {
    rowsEl.appendChild(makeRow('', '', ''));
  };
}());
</script>
