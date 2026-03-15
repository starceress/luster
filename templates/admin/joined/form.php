<?php
/** @var \Luster\Models\JoinedListing|null $joined  null = add mode */
/** @var string $error */
/** @var array  $old */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var array  $tfl_cats         [{id: int, name: string}] */
/** @var array  $tafl_cats        [{id: int, name: string}] */
/** @var array  $custom_networks  string[] */

$b      = rtrim(\Luster\Core\View::e($base_url), '/');
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
$statusVal = isset($old['status']) ? $old['status'] : ($joined?->status ?? 'joined');

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

<p class="page-back">
  <a href="<?= $b ?>/admin/joined" class="neb-link">&larr; Joined Listings</a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" action="<?= $action ?>" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <!-- ① Category widget -->
    <div class="form-group">
      <label class="form-label">Category</label>
      <div class="cat-widget"
           id="cat-widget"
           data-tfl='<?= $dataTfl ?>'
           data-tafl='<?= $dataTafl ?>'
           data-custom='<?= $dataCustom ?>'
           data-existing='<?= $dataExist ?>'>
        <div id="cat-rows"></div>
        <a href="#" class="neb-link" id="cat-add"
           style="font-size:0.8125rem;display:inline-block;margin-top:0.4rem">+ Add another category</a>
      </div>
      <p class="form-hint">Assign to one or more networks (TFL, TAFL, custom).</p>
    </div>

    <div class="section-divider"></div>

    <!-- ② URL -->
    <div class="form-group">
      <label class="form-label" for="jf-url">URL <span>*</span></label>
      <input id="jf-url" type="url" name="url" class="form-input" maxlength="500"
             placeholder="https://" required
             value="<?= \Luster\Core\View::e($val('url', $joined, 'url')) ?>">
    </div>

    <!-- ③ Subject -->
    <div class="form-group">
      <label class="form-label" for="jf-name">Subject <span>*</span></label>
      <input id="jf-name" type="text" name="name" class="form-input" maxlength="255" required
             value="<?= \Luster\Core\View::e($val('name', $joined, 'name')) ?>">
    </div>

    <!-- ④ Date Joined -->
    <div class="form-group">
      <label class="form-label" for="jf-date">Date Joined</label>
      <input id="jf-date" type="date" name="date_joined" class="form-input" style="max-width:200px"
             value="<?= \Luster\Core\View::e($val('date_joined', $joined, 'dateJoined')) ?>">
    </div>

    <div class="section-divider"></div>

    <!-- ⑤ Image -->
    <div class="form-group">
      <label class="form-label" for="jf-image">Image</label>
      <?php if ($isEdit && $joined->image !== null && $joined->image !== ''): ?>
        <img src="<?= \Luster\Core\View::e($b . '/uploads/joined/' . $joined->image) ?>"
             alt="Current image" class="fl-image" style="max-height:80px;margin-bottom:.5rem">
        <label class="form-check" style="margin-bottom:.4rem;color:var(--danger)">
          <input type="checkbox" id="jf-delete-img" name="delete_image" value="1">
          <span class="form-check-label" style="color:var(--danger)">Delete current image</span>
        </label>
        <p class="form-hint" style="margin-bottom:.4rem">Replace image:</p>
      <?php endif; ?>
      <input id="jf-image" type="file" name="image"
             accept="image/jpeg,image/png,image/gif,image/webp">
      <p class="form-hint">JPEG, PNG, GIF, or WebP &mdash; max&nbsp;2&nbsp;MB.</p>
    </div>

    <!-- ⑥ Status -->
    <div class="form-group">
      <label class="form-label" for="jf-status">Status</label>
      <div class="select-wrap" style="max-width:200px">
        <select id="jf-status" name="status" class="form-select">
          <option value="joined"<?= $statusVal === 'joined'  ? ' selected' : '' ?>>Joined</option>
          <option value="pending"<?= $statusVal === 'pending' ? ' selected' : '' ?>>Pending</option>
        </select>
      </div>
    </div>

    <!-- ⑦ Visibility -->
    <div class="form-group">
      <label class="form-label">Visibility</label>
      <label class="form-check">
        <input id="jf-active" type="checkbox" name="active" value="1"
               <?= $activeVal ? 'checked' : '' ?>>
        <span class="form-check-label">Active (visible on public site)</span>
      </label>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Joined FL' ?></button>
      <button type="button" class="btn btn-ghost" id="clear-form-btn">Clear Form</button>
      <a href="<?= $b ?>/admin/joined" class="neb-link">Cancel</a>
    </div>

  </form>
</div>

