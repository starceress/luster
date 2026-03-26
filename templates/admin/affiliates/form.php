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
$backLabel = $contextFid > 0 ? '&larr; Listing' : '&larr; Affiliates';

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

<p style="margin-bottom:1rem">
  <a href="<?= $backUrl ?>" class="link"><?= $backLabel ?></a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card card-padded">
  <form method="POST" action="<?= $action ?>" enctype="multipart/form-data" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <?php if ($isEdit && $contextFid > 0): ?>
      <input type="hidden" name="from_listing" value="<?= $contextFid ?>">
    <?php endif; ?>

    <?php if ($contextFid === 0): ?>
    <div class="form-group">
      <label class="form-label" for="aff-listing">Listing</label>
      <div class="select-wrap">
        <select id="aff-listing" name="fanlisting_id" class="form-select">
          <option value="0"><?= View::e(($site_name ?? 'Collective') . ' (Collective)') ?></option>
          <?php foreach ($listings as $fl): ?>
            <?php $selId = (int) ($old['fanlisting_id'] ?? ($affiliate?->listingId ?? 0)); ?>
            <option value="<?= (int) $fl->id ?>"
                    <?= (int) $fl->id === $selId ? 'selected' : '' ?>>
              <?= View::e($fl->name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="form-hint">Leave as &ldquo;Collective&rdquo; if this affiliate belongs to all listings.</p>
    </div>
    <?php else: ?>
      <input type="hidden" name="fanlisting_id" value="<?= $contextFid ?>">
    <?php endif; ?>

    <div class="form-group<?= !empty($fe['name']) ? ' has-error' : '' ?>">
      <label class="form-label" for="aff-name">Affiliate Name <span>*</span></label>
      <input id="aff-name" type="text" name="name" class="form-input" maxlength="255" required
             value="<?= View::e($v('name', $affiliate, 'name')) ?>">
      <?php if (!empty($fe['name'])): ?><p class="field-error"><?= View::e($fe['name'][0]) ?></p><?php endif; ?>
    </div>

    <div class="form-group<?= !empty($fe['url']) ? ' has-error' : '' ?>">
      <label class="form-label" for="aff-url">URL <span>*</span></label>
      <input id="aff-url" type="url" name="url" class="form-input" maxlength="500"
             placeholder="https://" required
             value="<?= View::e($v('url', $affiliate, 'url')) ?>">
      <?php if (!empty($fe['url'])): ?><p class="field-error"><?= View::e($fe['url'][0]) ?></p><?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="aff-email">Email</label>
      <input id="aff-email" type="email" name="email" class="form-input" maxlength="255"
             value="<?= View::e($v('email', $affiliate, 'email')) ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="aff-sort-order">Sort Order</label>
      <input id="aff-sort-order" type="number" name="sort_order" class="form-input"
             min="1" max="9999" style="max-width:120px"
             value="<?= View::e($v('sort_order', $affiliate, 'sortOrder', (string) ($next_sort_order ?? 1))) ?>">
      <p class="form-hint">Lower numbers appear first on the public site. Affiliates with the same sort order are sorted alphabetically.</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="aff-image">Image</label>
      <?php if ($isEdit && ($affiliate->image ?? '') !== ''): ?>
        <div style="margin-bottom:.75rem">
          <p class="form-hint" style="margin:0 0 .35rem">Current image:</p>
          <img src="<?= $b ?>/uploads/affiliates/<?= View::e($affiliate->image) ?>"
               alt="Current affiliate image"
               style="max-height:80px;max-width:200px;border:1px solid var(--border);border-radius:4px;display:block;margin-bottom:.5rem">
          <label class="form-check" style="color:var(--danger)">
            <input type="checkbox" name="delete_image" value="1">
            <span class="form-check-label" style="color:var(--danger)">Delete current image</span>
          </label>
        </div>
      <?php endif; ?>
      <input id="aff-image" type="file" name="image"
             accept="image/jpeg,image/png,image/gif,image/webp">
      <p class="form-hint">Accepted: JPG, PNG, GIF, WEBP. Max 2 MB.</p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Affiliate' ?></button>
      <a href="<?= $backUrl ?>" class="link">Cancel</a>
    </div>
  </form>
</div>
