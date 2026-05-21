<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing[]   $listings */
/** @var \Luster\Models\Affiliate|null $affiliate   null = add mode */
/** @var int    $context_fid  non-zero = came from a specific listing */
/** @var string $error */
/** @var array  $old */
/** @var array  $field_errors */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var string $site_name */
$b          = rtrim(View::e($base_url), '/');
$fe         = $field_errors ?? [];
$isEdit     = $affiliate !== null;
$contextFid = (int) ($context_fid ?? 0);

// Form action: listing-context add uses the dedicated route
if ($isEdit) {
    $action = $b . '/admin/affiliates/' . (int) $affiliate->id . '/edit';
} elseif ($contextFid > 0) {
    $action = $b . '/admin/listings/' . $contextFid . '/affiliates/add';
} else {
    $action = $b . '/admin/affiliates/add';
}

// Back/cancel destination
$backUrl   = $contextFid > 0 ? $b . '/admin/listings/' . $contextFid : $b . '/admin/affiliates';
$backLabel = $contextFid > 0 ? 'Back to Listing' : 'Back to Affiliates';

// Value helper: prefer $old (re-populate on error), then model, then ''
$v = static function (string $key, $model, string $prop, string $default = '') use ($old): string {
    if (isset($old[$key])) {
        return (string) $old[$key];
    }
    if ($model !== null && property_exists($model, $prop)) {
        return (string) ($model->$prop ?? $default);
    }
    return $default;
};
?>

<div class="page-topbar">
  <a href="<?= $backUrl ?>" class="link">
    &larr; <?= $backLabel ?>
  </a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $action ?>" enctype="multipart/form-data" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <?php if ($isEdit && $contextFid > 0): ?>
    <input type="hidden" name="from_listing" value="<?= $contextFid ?>">
  <?php endif; ?>

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('wrench') ?></div>
        <div class="pc-head-meta">
        <div class="pc-head-title"><?= $isEdit ? View::e($affiliate->name) : 'Add New Affiliate' ?></div>
        <div class="pc-head-sub"><?= $isEdit ? 'Update this affiliate\'s details.' : 'Add a site to your affiliates list.' ?></div>
      </div>
    </div>

    <div class="listing-field-section">

      <!-- Listing -->
      <?php if ($contextFid === 0): ?>
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="aff-listing">Listing</label>
        <div>
          <div class="select-wrap">
            <select id="aff-listing" name="fanlisting_id" >
              <option value="0"><?= View::e(($site_name ?? 'Collective') . ' (Collective)') ?></option>
              <?php foreach ($listings as $fl): ?>
                <?php $selId = (int) ($old['fanlisting_id'] ?? ($affiliate?->listingId ?? 0)); ?>
                <option value="<?= (int) $fl->id ?>" <?= (int) $fl->id === $selId ? 'selected' : '' ?>>
                  <?= View::e($fl->name) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <?php else: ?>
        <input type="hidden" name="fanlisting_id" value="<?= $contextFid ?>">
      <?php endif; ?>

      <!-- Affiliate Name -->
      <div class="listing-field-row<?= !empty($fe['name']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="aff-name">
          Affiliate Name <span class="req">*</span>
        </label>
        <div>
          <input id="aff-name" type="text" name="name" class="form"
                 maxlength="255" required
                 placeholder="e.g. Starlight Fanlisting"
                 value="<?= View::e($v('name', $affiliate, 'name')) ?>">
          <?php if (!empty($fe['name'])): ?>
            <p class="field-error"><?= View::e($fe['name'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- URL -->
      <div class="listing-field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="aff-url">
          URL <span class="req">*</span>
        </label>
        <div>
          <input id="aff-url" type="url" name="url" class="form"
                 maxlength="500" placeholder="https://" required
                 value="<?= View::e($v('url', $affiliate, 'url')) ?>">
          <?php if (!empty($fe['url'])): ?>
            <p class="field-error"><?= View::e($fe['url'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Email -->
      <div class="listing-field-row">
        <label class="listing-field-label" for="aff-email">Email</label>
        <div>
          <input id="aff-email" type="email" name="email" class="form"
                 maxlength="255" placeholder="owner@example.com"
                 value="<?= View::e($v('email', $affiliate, 'email')) ?>">
        </div>
      </div>

      <!-- Sort Order -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="aff-sort-order">Sort Order</label>
        <div>
          <input id="aff-sort-order" type="number" name="sort_order" class="form form--compact"
                 min="1" max="9999"
                 value="<?= View::e($v('sort_order', $affiliate, 'sortOrder', (string) ($next_sort_order ?? 1))) ?>">
          <p class="form-hint">Lower numbers appear first. Same order = sorted alphabetically.</p>
        </div>
      </div>

      <!-- Image -->
      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Image</span>
        <div>
          <?php if ($isEdit && ($affiliate->image ?? '') !== ''): ?>
            <div class="existing-upload-preview">
              <img src="<?= $b ?>/uploads/affiliates/<?= View::e($affiliate->image) ?>"
                   alt="Current affiliate image">
              <label class="del-img-check">
                <input type="checkbox" name="delete_image" value="1">
                <span class="form-check-label">Delete current image</span>
              </label>
            </div>
          <?php endif; ?>
          <label class="file-drop-zone">
            <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Click to upload</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
          </label>
        </div>
      </div>

    </div><!-- /.listing-field-section -->

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Affiliate' ?></button>
      <a href="<?= $backUrl ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
