<?php
use Luster\Core\View;
/** @var \Luster\Models\JoinedListing|null $joined  null = add mode */
/** @var string $error */
/** @var array  $old */
/** @var array  $field_errors */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var array  $tfl_cats         [{id: int, name: string}] */
/** @var array  $tafl_cats        [{id: int, name: string}] */
/** @var array  $custom_networks  string[] */

$b      = rtrim(View::e($base_url), '/');
$fe     = $field_errors ?? [];
$isEdit = $joined !== null;
$action = $isEdit
    ? $b . '/admin/joined/' . (int) $joined->id . '/edit'
    : $b . '/admin/joined/add';

// Value helper: prefer $old (re-populate on error), then model, then default
$val = static function (string $key, $model, string $prop, string $default = '') use ($old): string {
    if (isset($old[$key])) {
        return (string) $old[$key];
    }
    if ($model !== null && property_exists($model, $prop)) {
        return (string) ($model->$prop ?? $default);
    }
    return $default;
};

$activeVal = isset($old['active']) ? ($old['active'] === '1') : ($joined?->active ?? true);
$statusVal = isset($old['status']) ? $old['status'] : ($joined?->status ?? 'approved');

// Decode existing categories_json for JS pre-population
$existingCats = [];
if ($isEdit && $joined->categoriesJson !== null) {
    $decoded = json_decode($joined->categoriesJson, true);
    if (is_array($decoded)) {
        $existingCats = $decoded;
    }
}

// Safe JSON for data-* attributes (single-quoted HTML attribute)
$dataTfl    = json_encode($tfl_cats      ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl   = json_encode($tafl_cats     ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataExist  = json_encode($existingCats,     JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/joined" class="link">
    &larr;Back to Joined Listings
  </a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $action ?>" enctype="multipart/form-data" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card listing-field-card" style="margin-bottom:0">
    <div class="listing-field-section">

      <!-- Network & Category widget -->
      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Network &amp; Category</span>
        <div>
          <div class="cat-widget"
               id="cat-widget"
               data-tfl='<?= $dataTfl ?>'
               data-tafl='<?= $dataTafl ?>'
               data-custom='<?= $dataCustom ?>'
               data-existing='<?= $dataExist ?>'>
            <div id="cat-rows"></div>
            <a href="#" class="link" id="cat-add">+ Add another category</a>
          </div>
          <p class="form-hint">Assign to one or more networks (TFL, TAFL, custom).</p>
        </div>
      </div>

      <!-- URL -->
      <div class="listing-field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="jf-url">URL <span class="req">*</span></label>
        <div>
          <input id="jf-url" type="url" name="url" class="form-input" maxlength="500"
                 placeholder="https://" required
                 value="<?= View::e($val('url', $joined, 'url')) ?>">
          <?php if (!empty($fe['url'])): ?>
            <p class="field-error"><?= View::e($fe['url'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Subject -->
      <div class="listing-field-row<?= !empty($fe['name']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="jf-name">Subject <span class="req">*</span></label>
        <div>
          <input id="jf-name" type="text" name="name" class="form-input" maxlength="255" required
                 placeholder="e.g. Hunters: Voices Strong"
                 value="<?= View::e($val('name', $joined, 'name')) ?>">
          <?php if (!empty($fe['name'])): ?>
            <p class="field-error"><?= View::e($fe['name'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Date Joined -->
      <div class="listing-field-row">
        <label class="listing-field-label" for="jf-date">Date Joined</label>
        <input id="jf-date" type="date" name="date_joined" class="form-input" style="max-width:200px"
               value="<?= View::e($val('date_joined', $joined, 'dateJoined')) ?>">
      </div>

      <!-- Image -->
      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Image</span>
        <div>
          <?php if ($isEdit && ($joined->image ?? '') !== ''): ?>
            <div style="margin-bottom:.75rem">
              <img src="<?= View::e($b . '/uploads/joined/' . $joined->image) ?>"
                   alt="Current image"
                   style="max-height:80px;max-width:200px;border:1px solid var(--border);border-radius:6px;display:block;margin-bottom:.5rem">
              <label class="form-check" style="color:var(--danger)">
                <input type="checkbox" id="jf-delete-img" name="delete_image" value="1">
                <span class="form-check-label" style="color:var(--danger)">Delete current image</span>
              </label>
            </div>
          <?php endif; ?>
          <label class="file-drop-zone">
            <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><?= View::icon('image') ?>
            <span class="file-drop-label">Click to upload</span>
            <span class="file-drop-sub">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
          </label>
        </div>
      </div>

      <!-- Status -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="jf-status">Status</label>
        <div>
          <div class="select-wrap" style="max-width:200px">
            <select id="jf-status" name="status" class="form-select">
              <option value="approved"<?= $statusVal === 'approved' ? ' selected' : '' ?>>Approved</option>
              <option value="pending"<?= $statusVal === 'pending'  ? ' selected' : '' ?>>Pending</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Visibility -->
      <div class="listing-field-row">
        <span class="listing-field-label">Visibility</span>
        <label class="form-check">
          <input id="jf-active" type="checkbox" name="active" value="1"
                 <?= $activeVal ? 'checked' : '' ?>>
          <span class="form-check-label">Active (visible on public site)</span>
        </label>
      </div>

    </div><!-- /.listing-field-section -->

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Joined FL' ?></button>
      <button type="button" class="btn btn-ghost" id="clear-form-btn">Clear</button>
      <a href="<?= $b ?>/admin/joined" class="btn btn-ghost">Cancel</a>
    </div>
  </div>

</form>
