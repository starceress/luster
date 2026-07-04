<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Wishlist admin add/edit form. Used for creating new wishlist entries or editing
 existing ones. Includes fields for subject, URL, description, type (wanted, top
 priority, granted), status, and category selection with network-aware category
 dropdowns (TFL, TAFL, custom).
*/

use Luster\Core\View;
/** @var \Luster\Addons\Wishlist\Wishlist|null $entry   null = add mode */
/** @var array  $old     re-population on validation failure */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var array  $tfl_cats        [{id:int,name:string}] */
/** @var array  $tafl_cats       [{id:int,name:string}] */
/** @var array  $custom_networks string[] */
/** @var array  $custom_cats     network => [{id:int,name:string}] */
$b      = rtrim(View::e($base_url), '/');
$isEdit = $entry !== null;
$action = $isEdit
    ? $b . '/admin/wishlist/' . (int) $entry->id . '/edit'
    : $b . '/admin/wishlist/add';

/** Helper: returns POST repopulation value, falls back to model value, then default. */
$val = static function (string $key, mixed $default = '') use ($old, $entry): mixed {
    if (!empty($old)) {
        return $old[$key] ?? $default;
    }
    return $entry?->$key ?? $default;
};

$dataTfl        = json_encode($tfl_cats        ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl       = json_encode($tafl_cats       ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom     = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustomCats = json_encode($custom_cats     ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);

// Build catLookup: id → [network, name] (not needed here but kept for parity)
$catLookup = [];
foreach ($tfl_cats  ?? [] as $c) { $catLookup[(int)$c['id']] = ['network' => 'TFL',  'name' => $c['name']]; }
foreach ($tafl_cats ?? [] as $c) { $catLookup[(int)$c['id']] = ['network' => 'TAFL', 'name' => $c['name']]; }
foreach ($custom_cats ?? [] as $net => $cats) {
    foreach ($cats as $c) { $catLookup[(int)$c['id']] = ['network' => (string)$net, 'name' => $c['name']]; }
}

// Pre-populate cat-widget rows: from $old arrays (validation re-show) or stored comma-separated value.
$existingCats = [];
$oldNets  = $old['cat_network']        ?? null;
$oldNames = $old['cat_name']           ?? null;
$oldOther = $old['cat_network_other']  ?? [];
if (is_array($oldNets) && is_array($oldNames)) {
    foreach ($oldNets as $i => $net) {
        $net = trim((string) $net);
        $nm  = trim((string) ($oldNames[$i] ?? ''));
        if ($net === 'other') { $net = trim((string) ($oldOther[$i] ?? '')); }
        if ($net !== '' && $nm !== '') { $existingCats[] = ['network' => $net, 'name' => $nm]; }
    }
} else {
    $stored = (string) ($entry?->category ?? '');
    if ($stored !== '') {
        foreach (array_filter(array_map('trim', explode(',', $stored))) as $name) {
            $found = false;
            foreach ($tfl_cats ?? [] as $c) {
                if ($c['name'] === $name) { $existingCats[] = ['network' => 'TFL',  'name' => $name]; $found = true; break; }
            }
            if (!$found) foreach ($tafl_cats ?? [] as $c) {
                if ($c['name'] === $name) { $existingCats[] = ['network' => 'TAFL', 'name' => $name]; $found = true; break; }
            }
            if (!$found) foreach ($custom_cats ?? [] as $net => $cats) {
                foreach ($cats as $c) {
                    if ($c['name'] === $name) { $existingCats[] = ['network' => (string)$net, 'name' => $name]; $found = true; break 2; }
                }
            }
            if (!$found) { $existingCats[] = ['network' => 'other', 'name' => $name]; }
        }
    }
}
$dataExisting = json_encode($existingCats, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/wishlist" class="link">&larr; Back to Wishlist</a>
</div>

<?php if ($error !== ''): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= View::e($action) ?>" enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('wishlist') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title"><?= $isEdit ? 'Edit Wishlist Entry' : 'Add Wishlist Entry' ?></div>
        <div class="panel-header-sub"><?= $isEdit ? 'Update the details for this wishlist entry.' : 'Add a fanlisting subject to your wishlist.' ?></div>
      </div>
    </div>

    <div class="card-body">
      <div class="section-label">Subject Details</div>

      <div class="field-row">
        <label class="field-label" for="wl-subject">
          Subject <span class="req">*</span>
        </label>
        <div>
          <input id="wl-subject" type="text" name="subject" class="form" maxlength="255"
                 value="<?= View::e((string) $val('subject')) ?>" required>
          <p class="form-hint">The fanlisting subject or topic you want to join.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="wl-url">URL</label>
        <div>
          <input id="wl-url" type="url" name="url" class="form" maxlength="255"
                 placeholder="https://"
                 value="<?= View::e((string) $val('url')) ?>">
          <p class="form-hint">Link to the fanlisting (if it exists already).</p>
        </div>
      </div>

      <div class="field-row">
        <span class="field-label">Image</span>
        <div>
          <?php if ($isEdit && ($entry->image ?? '') !== ''): ?>
            <div class="existing-upload-preview">
              <img src="<?= View::e($b . '/uploads/wishlist/' . $entry->image) ?>" alt="Current image">
              <label class="del-img-check">
                <input type="checkbox" name="delete_image" value="1">
                <span class="form-check-label">Delete current image</span>
              </label>
            </div>
          <?php endif; ?>
          <label class="file-drop-zone">
            <input id="wl-image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <span class="file-drop-icon"><?= View::icon('image') ?></span>
            <strong>Drag &amp; drop or click to browse</strong>
            <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
          </label>
        </div>
      </div>

      <div class="section-label">Classification</div>

      <div class="field-row">
        <label class="field-label">Network &amp; Category</label>
        <div>
          <div class="cat-widget" id="cat-widget"
               data-tfl='<?= $dataTfl ?>'
               data-tafl='<?= $dataTafl ?>'
               data-custom='<?= $dataCustom ?>'
               data-custom-cats='<?= $dataCustomCats ?>'
               data-existing='<?= $dataExisting ?>'>
            <div id="cat-rows"></div>
            <a href="#" class="link btn btn-sm" id="cat-add"><?= View::icon('add') ?> Add another category</a>
          </div>
          <p class="form-hint">The network(s) and category(ies) this entry belongs to.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="wl-type">Status</label>
        <div>
          <?php $currentType = (string) $val('type', 'wanted'); ?>
          <div class="select-wrap select-auto">
            <select id="wl-type" name="type">
              <option value="wanted"   <?= $currentType === 'wanted'   ? 'selected' : '' ?>>Wanted</option>
              <option value="top"      <?= $currentType === 'top'      ? 'selected' : '' ?>>Top Wanted</option>
              <option value="granted"  <?= $currentType === 'granted'  ? 'selected' : '' ?>>Granted</option>
            </select>
          </div>
          <p class="form-hint">
            <strong>Wanted</strong> — standard wishlist item.
            <strong>Top Wanted</strong> — highest priority.
            <strong>Granted</strong> — you already opened it.
          </p>
        </div>
      </div>

      <div class="section-label">Public Display</div>

      <div class="field-row">
        <label class="field-label" for="wl-description">Description</label>
        <div>
          <textarea id="wl-description" name="description" class="form" rows="4"
                    maxlength="2000"><?= View::e((string) $val('description')) ?></textarea>
          <p class="form-hint">Optional notes (max 2000 characters).</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="wl-added-at">Date Added</label>
        <div>
          <input id="wl-added-at" type="date" name="added_at" class="form form--compact"
                 value="<?= View::e(!empty($old) ? (string) ($old['added_at'] ?? '') : ($entry?->addedAt ?? '')) ?>">
          <p class="form-hint">Leave blank to record today's date automatically.</p>
        </div>
      </div>

      <div class="field-row">
        <span class="field-label">Show on public wishlist</span>
        <div>
          <?php $isActive = !empty($old) ? ($old['active'] ?? false) : ($entry?->active ?? true); ?>
          <label class="toggle-wrap">
            <input type="checkbox" name="active" value="1" <?= $isActive ? 'checked' : '' ?>>
            <span class="toggle-label">Public</span>
          </label>
        </div>
      </div>

    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Entry' ?></button>
    
      <button type="reset" class="btn btn-ghost">Clear</button>
      <a href="<?= $b ?>/admin/wishlist" class="btn btn-ghost">Cancel</a>
    </div>

  </div>
</form>
