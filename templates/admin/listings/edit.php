<?php
/** @var \Luster\Models\Listing    $listing */
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

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
  <a href="<?= $b ?>/admin/listings" class="neb-link">&larr; Back to Owned Listings</a>
  <div style="display:flex;gap:.5rem;">
    <a href="<?= $b ?>/admin/members/<?= (int) $listing->id ?>" class="btn btn-secondary btn-sm">Members</a>
    <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/codes" class="btn btn-secondary btn-sm">Codes</a>
    <a href="<?= $b ?>/admin/email-templates?listing=<?= (int) $listing->id ?>" class="btn btn-secondary btn-sm">Emails</a>
    <form method="post"
          action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/delete"
          style="display:inline"
          data-confirm="Delete this listing and all its members? This cannot be undone.">
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
<?php $pl = \Luster\Core\View::e($b . '/admin/members/' . (int) $listing->id . '?status=pending'); ?>
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
  <span style="font-size:1.75rem;font-weight:700;color:var(--text)"><?= \Luster\Core\View::e($fl_last_updated ?? '—') ?></span><br>
  <span class="text-dim">Date Last Updated</span>
</div>
</div>

<div class="neb-tabs">
  <button class="neb-tab-btn active" data-tab="general">General</button>
  <button class="neb-tab-btn" data-tab="fields">Additional Fields</button>
  <button class="neb-tab-btn" data-tab="affiliates">Affiliates</button>
  <button class="neb-tab-btn" data-tab="templates">Templates</button>
  <button class="neb-tab-btn" data-tab="embed">Embed</button>
</div>

<!-- ═══ TAB: GENERAL ═══ -->
<div id="tab-general" class="neb-tab-panel active">
<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" enctype="multipart/form-data">
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
             value="<?= \Luster\Core\View::e($listing->subject ?? '') ?>" maxlength="255">
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
             value="<?= \Luster\Core\View::e(strlen((string) ($listing->opened ?? '')) >= 10 ? substr((string) $listing->opened, 0, 10) : '') ?>">
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
      <?php $lt = $listing_type ?? 'listing'; ?>
      <div class="select-wrap">
        <select id="fl-listing-type" name="listing_type" class="form-select">
          <option value="listing"  <?= $lt === 'listing'  ? 'selected' : '' ?>>Listing</option>
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

    <div class="form-group">
      <label class="form-label" for="fl-last-updated">Last Updated Date</label>
      <div style="display:flex;align-items:center;gap:0.6rem">
        <input id="fl-last-updated" type="date" name="fl_last_updated" class="form-input"
               style="max-width:200px"
               value="<?= \Luster\Core\View::e($fl_last_updated ?? '') ?>">
        <button type="button" class="btn btn-secondary btn-sm" id="set-today-btn">
          Set to today
        </button>
        <button type="button" class="btn btn-ghost btn-sm" id="clear-date-btn">
          Clear
        </button>
      </div>
      <p class="form-hint" style="margin-top:0.35rem">
        The date this listing was last actively maintained.
        Auto-updated when members are approved or affiliates are changed.
        Drives the staleness check on the dashboard.
      </p>
    </div>

    <div class="section-divider">Listing Image</div>

    <div class="form-group">
      <?php if (isset($current_image) && $current_image !== null && $current_image !== ''): ?>
        <div style="margin-bottom:0.6rem">
          <img src="<?= $b ?>/uploads/listings/<?= \Luster\Core\View::e($current_image) ?>"
               alt="Current listing image"
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
        <input type="text" class="form-input" value="<?= \Luster\Core\View::e($listing->dbprefix) ?>"
               readonly style="color:var(--text-dim);cursor:not-allowed;flex:1">
        <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit-prefix"
           class="btn btn-secondary btn-sm" style="white-space:nowrap">Edit Prefix</a>
      </div>
      <p class="form-hint">The member table name in your database.</p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
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
           value="<?= $b ?>/stats/<?= (int) $listing->id ?>"
           style="font-family:'DM Mono',monospace;font-size:0.8125rem;color:var(--text-muted)">
  </div>

  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/templates">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label class="form-label" for="members_template">Members List</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;margin-bottom:0.5rem;line-height:1.8">
        $$fan_name$$ &bull; $$fan_email$$ &bull; $$fan_url$$ &bull; $$fan_country$$
      </p>
      <textarea id="members_template" name="members_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:0.8125rem"><?= \Luster\Core\View::e($templates['members_template'] ?? '<li class="fl-member"><a class="fl-link" href="$$fan_url$$">$$fan_name$$</a> <span class="fl-muted">— $$fan_country$$</span></li>') ?></textarea>
    </div>

    <div class="form-group">
      <label class="form-label" for="affiliates_template">Affiliates</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;margin-bottom:0.5rem;line-height:1.8">
        $$aff_url$$ &bull; $$aff_image$$ &bull; $$aff_name$$
      </p>
      <textarea id="affiliates_template" name="affiliates_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:0.8125rem"><?= \Luster\Core\View::e($templates['affiliates_template'] ?? '<li class="fl-affiliate"><a class="fl-affiliate-link" href="$$aff_url$$" target="_blank" rel="noopener noreferrer">$$aff_image$$$$aff_name$$</a></li>') ?></textarea>
    </div>

    <div class="form-group">
      <label class="form-label" for="stats_template">Statistics</label>
      <p class="form-hint" style="font-family:'DM Mono',monospace;margin-bottom:0.5rem;line-height:1.8">
        $$stat_opened$$ &bull; $$stat_updated$$ &bull; $$stat_last_updated$$ &bull; $$stat_approved$$ &bull; $$stat_pending$$ &bull; $$stat_newmembers$$ &bull; $$stat_growth$$
      </p>
      <textarea id="stats_template" name="stats_template" class="form-textarea" rows="8"
                style="font-family:'DM Mono',monospace;font-size:0.8125rem"><?= \Luster\Core\View::e($templates['stats_template'] ?? '<p>Opened: $$stat_opened$$<br>Last updated: $$stat_updated$$<br>Members: $$stat_approved$$ ($$stat_pending$$ pending)<br>Newest: $$stat_newmembers$$</p>') ?></textarea>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Templates</button>
    </div>
  </form>
</div>
</div><!-- /#tab-templates -->

<!-- ═══ TAB: ADDITIONAL FIELDS ═══ -->
<div id="tab-fields" class="neb-tab-panel">

  <?php if ($listing->dbprefix === ''): ?>
  <div class="form-card">
    <p class="text-muted" style="font-size:0.875rem">Additional fields require a member table. This listing has no database table configured yet.</p>
  </div>
  <?php else: ?>

  <!-- Add New Field -->
  <div class="form-card" style="margin-bottom:1.25rem">
    <h2 class="card-title" style="margin-bottom:0.5rem">Add New Field</h2>
    <p class="form-hint" style="margin:0 0 1rem;line-height:1.6">
      <strong style="color:var(--text-muted)">Field Name Rules:</strong>
      Letters (a-z, A-Z), numbers (0-9), and underscores (_).<br>
      NO spaces, use underscores in place of spaces (i.e. <code>fave_song</code>, <code>vanilla_or_chocolate</code>), NO punctuation, NO special characters (hyphens, dots, slashes, etc.).<br>
      Max length 30 characters. Must be unique per listing.
    </p>
    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/add" id="fields-add-form">
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
      <h2 class="card-title">Current Fields</h2>
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
              <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/<?= urlencode($field->name) ?>/edit"
                 class="neb-link" style="font-size:0.8125rem;margin-right:0.5rem">Edit</a>
              <form method="POST"
                    action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/<?= urlencode($field->name) ?>/delete"
                    style="display:inline"
                    data-confirm="Drop the column for field &#x201C;<?= \Luster\Core\View::e($field->name) ?>&#x201D;? This permanently removes member data and cannot be undone.">
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

</div><!-- /#tab-fields -->

<!-- ═══ TAB: AFFILIATES ═══ -->
<div id="tab-affiliates" class="neb-tab-panel">
<div class="neb-card">
  <div style="display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;border-bottom:1px solid var(--border)">
    <h2 class="card-title">Affiliates</h2>
    <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/affiliates/add"
       class="btn btn-primary btn-sm">+ Add Affiliate</a>
  </div>

  <?php if (empty($affiliates)): ?>
    <p class="text-muted" style="padding:1rem 1.25rem;font-size:0.875rem">No affiliates for this listing yet.</p>
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
            <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit?from_listing=<?= (int) $listing->id ?>"
               class="neb-link" style="font-size:0.8125rem;margin-right:0.5rem">Edit</a>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                  style="display:inline">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_listing" value="<?= (int) $listing->id ?>">
              <button type="submit" style="background:none;border:none;color:var(--warn);font-size:0.8125rem;cursor:pointer;padding:0;margin-right:0.5rem">
                <?= $aff->active ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                  style="display:inline"
                  data-confirm="Delete this affiliate?">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_listing" value="<?= (int) $listing->id ?>">
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

<!-- ═══ TAB: EMBED ═══ -->
<div id="tab-embed" class="neb-tab-panel">
<?php
/** @var array  $embed_settings */
/** @var array  $cfg */
$embedSettings  = $embed_settings ?? [];
$installPath    = rtrim((string) ($cfg['install_path']   ?? ''), '/');
$embedBaseUrl   = rtrim((string) ($cfg['embed_base_url'] ?? ''), '/') ?: rtrim((string) ($cfg['site_url'] ?? ''), '/');
?>

<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/embed">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="section-divider">Public Page Settings</div>

    <div class="form-group">
      <label class="form-check">
        <input type="checkbox" name="country_field_enabled" value="1"
               <?= ($embedSettings['country_field_enabled'] ?? true) ? 'checked' : '' ?>>
        <span class="form-check-label">Show country field on join &amp; update forms</span>
      </label>
      <p class="form-hint" style="margin-top:0.35rem">
        Uncheck to hide the country dropdown for this listing.
      </p>
    </div>

    <div class="section-divider">Form Page URLs</div>

    <p class="form-hint" style="margin-bottom:1rem">
      If you embed Luster's forms on your own site, enter the URL of each page
      containing the embed. When a visitor submits a form and validation fails,
      Luster redirects back here (instead of its internal page) so the error
      appears on your site. Leave blank to use Luster's internal form pages.
    </p>

    <div class="form-group">
      <label class="form-label" for="fl-form-join">Join page URL</label>
      <input id="fl-form-join" type="url" name="form_url_join" class="form-input"
             maxlength="500" placeholder="https://fan.example.com/join.php"
             value="<?= \Luster\Core\View::e($embedSettings['form_url_join'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-form-update">Update page URL</label>
      <input id="fl-form-update" type="url" name="form_url_update" class="form-input"
             maxlength="500" placeholder="https://fan.example.com/update.php"
             value="<?= \Luster\Core\View::e($embedSettings['form_url_update'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-form-remove">Remove page URL</label>
      <input id="fl-form-remove" type="url" name="form_url_remove" class="form-input"
             maxlength="500" placeholder="https://fan.example.com/remove.php"
             value="<?= \Luster\Core\View::e($embedSettings['form_url_remove'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-form-reset">Password reset page URL</label>
      <input id="fl-form-reset" type="url" name="form_url_reset" class="form-input"
             maxlength="500" placeholder="https://fan.example.com/reset.php"
             value="<?= \Luster\Core\View::e($embedSettings['form_url_reset'] ?? '') ?>">
    </div>

    <div class="section-divider">Post-Action Redirect URLs</div>

    <p class="form-hint" style="margin-bottom:1rem">
      Override the default success pages with custom URLs.
      Leave blank to use Luster's built-in success page.
    </p>

    <div class="form-group">
      <label class="form-label" for="fl-redir-join">After Join</label>
      <input id="fl-redir-join" type="url" name="redirect_url_join" class="form-input"
             maxlength="500" placeholder="https://fan.example.com/thanks"
             value="<?= \Luster\Core\View::e($embedSettings['redirect_url_join'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-redir-update">After Update</label>
      <input id="fl-redir-update" type="url" name="redirect_url_update" class="form-input"
             maxlength="500" placeholder="https://fan.example.com/updated"
             value="<?= \Luster\Core\View::e($embedSettings['redirect_url_update'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-redir-remove">After Remove</label>
      <input id="fl-redir-remove" type="url" name="redirect_url_remove" class="form-input"
             maxlength="500" placeholder="https://fan.example.com/removed"
             value="<?= \Luster\Core\View::e($embedSettings['redirect_url_remove'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="fl-redir-reset">After Password Reset</label>
      <input id="fl-redir-reset" type="url" name="redirect_url_reset" class="form-input"
             maxlength="500" placeholder="https://fan.example.com/password-updated"
             value="<?= \Luster\Core\View::e($embedSettings['redirect_url_reset'] ?? '') ?>">
    </div>

    <div class="section-divider">Per-Listing Database (Advanced)</div>

    <p class="form-hint" style="margin-bottom:1rem">
      Override the global database connection for this listing only.
      Leave all fields blank to use the global database.
    </p>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="fl-db-host">DB Host</label>
        <input id="fl-db-host" type="text" name="db_host" class="form-input"
               maxlength="255" placeholder="localhost"
               value="<?= \Luster\Core\View::e($embedSettings['db_host'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label" for="fl-db-name">DB Name</label>
        <input id="fl-db-name" type="text" name="db_name" class="form-input"
               maxlength="255" placeholder="my_database"
               value="<?= \Luster\Core\View::e($embedSettings['db_name'] ?? '') ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="fl-db-user">DB User</label>
        <input id="fl-db-user" type="text" name="db_user" class="form-input"
               maxlength="255" autocomplete="off"
               value="<?= \Luster\Core\View::e($embedSettings['db_user'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label" for="fl-db-pass">DB Password</label>
        <div class="input-icon-wrap">
          <input id="fl-db-pass" type="password" name="db_pass" class="form-input"
                 maxlength="500" autocomplete="new-password"
                 placeholder="Leave blank to keep current">
          <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
            <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
        <p class="form-hint">Only fill in to change the stored password.</p>
      </div>
    </div>

    <?php if ($installPath !== ''): ?>
    <div class="section-divider">Embed Snippets</div>

    <p class="form-hint" style="margin-bottom:0.75rem">
      Paste these lines into your own site's pages to embed this listing's forms.
    </p>

    <?php foreach (['join', 'members', 'update', 'remove'] as $page): ?>
    <div class="form-group">
      <label class="form-label" style="text-transform:capitalize"><?= \Luster\Core\View::e($page) ?> page</label>
      <div class="ro-field" style="font-family:'DM Mono',monospace;font-size:0.8rem;user-select:all">
        $luster_fanlisting_id = <?= (int) $listing->id ?>;<br>
        require '<?= \Luster\Core\View::e($installPath) ?>/public/embed/<?= \Luster\Core\View::e($page) ?>.php';
      </div>
    </div>
    <?php endforeach; ?>
    <?php elseif ($installPath === ''): ?>
    <div class="section-divider">Embed Snippets</div>
    <p class="form-hint">
      Set the <strong>Installation Path</strong> in
      <a href="<?= $b ?>/admin/settings" class="neb-link">Settings → Embed &amp; Paths</a>
      to generate embed snippets here.
    </p>
    <?php endif; ?>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Embed Settings</button>
    </div>
  </form>
</div>
</div><!-- /#tab-embed -->

