<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin joined fanlisting add/edit form. Provides fields for adding or editing
 a joined fanlisting entry, including subject, URL, image, category
 assignment, status, and sort order. Shared by both add and edit routes.
*/
use Luster\Core\View;

$b      = rtrim(View::e($base_url), '/');
$fe     = $field_errors ?? [];
$isEdit = $joined !== null;
$action = $isEdit
    ? $b . '/admin/joined/' . (int) $joined->id . '/edit'
    : $b . '/admin/joined/add';

// Value helper: prefer $old (re-populate on error), then model, then default.
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

// Pre-populate the category rows: the posted rows when a save failed validation
// (so the picks aren't lost), otherwise the listing's saved categories_json.
// $old is only non-empty after a failed save. Check that rather than the
// category arrays: removing every row posts no cat_network[] at all, and that
// must come back as "no categories", not as the saved ones.
$existingCats = [];
if ($old !== []) {
    $oldNets  = array_values((array) ($old['cat_network']       ?? []));
    $oldNames = array_values((array) ($old['cat_name']          ?? []));
    $oldOther = array_values((array) ($old['cat_network_other'] ?? []));
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
} elseif ($isEdit && $joined->categoriesJson !== null) {
    $decoded = json_decode($joined->categoriesJson, true);
    if (is_array($decoded)) {
        $existingCats = $decoded;
    }
}

// Safe JSON for data-* attributes (single-quoted HTML attribute).
$dataTfl        = json_encode($tfl_cats ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl       = json_encode($tafl_cats ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom     = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustomCats = json_encode($custom_cats ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataExist      = json_encode($existingCats, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/joined" class="link">
    &larr; Back to Joined Listings
  </a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $action ?>" enctype="multipart/form-data" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('add') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title"><?= $isEdit ? 'Edit Joined Listing' : 'Add Joined Listing' ?></div>
      </div>
    </div>

    <div class="card-body">
      <div class="field-row">
        <span class="field-label">Network &amp; Category</span>
        <div>
          <div class="cat-widget"
               id="cat-widget"
               data-tfl='<?= $dataTfl ?>'
               data-tafl='<?= $dataTafl ?>'
               data-custom='<?= $dataCustom ?>'
               data-custom-cats='<?= $dataCustomCats ?>'
               data-existing='<?= $dataExist ?>'>
            <div id="cat-rows"></div>
            <a href="#" class="link btn btn-sm" id="cat-add"><?= View::icon('add') ?> Add another category</a>
          </div>
          <p class="form-hint">Assign this joined listing to one or more networks and categories.</p>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="field-label" for="jf-url">Website URL <span class="req">*</span></label>
        <div>
          <input id="jf-url" type="url" name="url" class="form" maxlength="500" placeholder="https://" required value="<?= View::e($val('url', $joined, 'url')) ?>">
          <?php if (!empty($fe['url'])): ?>
            <p class="field-error"><?= View::e($fe['url'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['name']) ? ' has-error' : '' ?>">
        <label class="field-label" for="jf-name">Subject <span class="req">*</span></label>
        <div>
          <input id="jf-name" type="text" name="name" class="form" maxlength="255" required placeholder="e.g. Hunters: Voices Strong" value="<?= View::e($val('name', $joined, 'name')) ?>">
          <?php if (!empty($fe['name'])): ?>
            <p class="field-error"><?= View::e($fe['name'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="jf-date">Date Joined</label>
        <div>
          <input id="jf-date" type="date" name="date_joined" class="form form--compact" value="<?= View::e($val('date_joined', $joined, 'dateJoined')) ?>">
        </div>
      </div>

      <div class="field-row">
        <span class="field-label">Image</span>
        <?php if ($isEdit && ($joined->image ?? '') !== ''): ?>
        <div class="img-upload-panel img-upload-panel--two-col">
          <div class="surface img-current-card" id="fl-img-current-card">
            <div class="img-current-preview">
              <img src="<?= View::e($b . '/uploads/joined/' . $joined->image) ?>" alt="<?= View::e($joined->image) ?>">
            </div>
            <div class="img-current-footer">
              <span class="img-current-name"><?= View::e($joined->image) ?></span>
              <button type="button" class="btn btn-danger btn-sm img-delete-btn" id="fl-delete-image-btn"
                      data-delete-url="<?= $b ?>/admin/joined/<?= (int) $joined->id ?>/delete-image"
                      data-csrf="<?= View::e($csrf_token ?? '') ?>"
                      data-image-entity="joined_image">Delete Image</button>
            </div>
          </div>
          <div class="img-replace-col">
            <div class="panel-label">Replace with a new image</div>
            <label class="file-drop-zone file-drop-zone--sm">
              <input id="jf-image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
              <span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Drag &amp; drop or click to browse</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
            </label>
          </div>
        </div>
        <?php else: ?>
        <div class="img-upload-panel">
          <label class="file-drop-zone">
            <input id="jf-image" type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Drag &amp; drop or click to browse</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
          </label>
        </div>
        <?php endif; ?>
      </div>

      <div class="field-row">
        <label class="field-label" for="jf-status">Status</label>
        <div>
          <div class="select-wrap">
            <select id="jf-status" name="status">
              <option value="approved"<?= $statusVal === 'approved' ? ' selected' : '' ?>>Approved</option>
              <option value="pending"<?= $statusVal === 'pending' ? ' selected' : '' ?>>Pending</option>
            </select>
          </div>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="jf-description">Description</label>
        <div>
          <textarea id="jf-description" name="description" class="form" rows="3" maxlength="2000" placeholder="What this fanlisting is about, or why you joined it."><?= View::e($val('description', $joined, 'description')) ?></textarea>
          <p class="form-hint">Private admin note &mdash; never shown on the public joined-listings page.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="jf-comments">Admin Comments</label>
        <div>
          <textarea id="jf-comments" name="comments" class="form" rows="3" maxlength="2000" placeholder="Internal notes, reminders, or anything else worth tracking."><?= View::e($val('comments', $joined, 'comments')) ?></textarea>
          <p class="form-hint">Private admin note &mdash; never shown on the public joined-listings page.</p>
        </div>
      </div>

      <div class="field-row">
        <span class="field-label">Visibility</span>
        <label class="form-check">
          <input id="jf-active" type="checkbox" name="active" value="1" <?= $activeVal ? 'checked' : '' ?>>
          <span class="toggle-label">Active (visible on public site)</span>
        </label>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Joined Listing' ?></button>
      <?php if ($isEdit): ?>
        <button type="reset" class="btn btn-quaternary">Reset to Original</button>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost" id="clear-form-btn">Clear</button>
      <?php if ($isEdit): ?>
        <span class="flex-fill"></span>
        <button type="submit" form="del-joined-<?= (int) $joined->id ?>" class="btn btn-danger">Delete</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
  <form
    id="del-joined-<?= (int) $joined->id ?>"
    method="POST"
    action="<?= $b ?>/admin/joined/<?= (int) $joined->id ?>/delete"
    data-del-modal
    data-del-entity="joined"
    data-del-name="<?= View::e($joined->name) ?>"
  >
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  </form>
<?php endif; ?>
