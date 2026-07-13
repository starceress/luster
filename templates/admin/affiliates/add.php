<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Add or edit affiliate form. Provides fields for name, URL, email, image upload,
 listing assignment (collective-wide or per-listing), and approval status. Used for
 both creating new affiliates and editing existing ones, determined by whether
 the $affiliate variable is null.
*/
use Luster\Core\View;
$b          = rtrim(View::e($base_url), '/');
$fe         = $field_errors ?? [];
$isEdit     = $affiliate !== null;
$contextFid = (int) ($context_fid ?? 0);
$fromFid    = (int) ($from_listing ?? 0);

// Form action uses $fromFid (route-based origin) not $contextFid (preselect hint)
if ($isEdit) {
    $editQs = $fromFid > 0 ? '?from_listing=' . $fromFid : '';
    $action = $b . '/admin/affiliates/' . (int) $affiliate->id . '/edit' . $editQs;
} elseif ($fromFid > 0) {
    $action = $b . '/admin/listings/' . $fromFid . '/affiliates/add';
} else {
    $action = $b . '/admin/affiliates/add';
}

// Back/cancel destination — based on where the user came from, not the preselect
$backUrl   = $fromFid > 0 ? $b . '/admin/listings/' . $fromFid . '/edit?tab=affiliates' : $b . '/admin/affiliates';
$backLabel = $fromFid > 0 ? 'Back to Listing' : 'Back to Affiliates';

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

<div class="toolbar">
  <a href="<?= $backUrl ?>" class="link">
    &larr; <?= View::e($backLabel) ?>
  </a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $action ?>" enctype="multipart/form-data" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="sort_order" value="<?= View::e($v('sort_order', $affiliate, 'sortOrder', (string) ($next_sort_order ?? 1))) ?>">
  <?php if (($from_listing ?? 0) > 0): ?>
    <input type="hidden" name="from_listing" value="<?= (int) $from_listing ?>">
  <?php endif; ?>

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('wrench') ?></div>
        <div class="panel-header-meta">
        <div class="panel-header-title"><?= $isEdit ? View::e($affiliate->name) : 'Add New Affiliate' ?></div>
        <div class="panel-header-sub"><?= $isEdit ? 'Update this affiliate\'s details.' : 'Add a site to your affiliates list.' ?></div>
      </div>
    </div>

    <div class="card-body">

      <!-- Listing -->
      <?php if ($contextFid === 0): ?>
        <?php if ($isEdit): ?>
          <?php
            $scopeLabel = (string) ($site_name ?? 'Collective') . ' (Collective)';
            if ($affiliate->listingId !== null) {
                foreach ($listings as $fl) {
                    if ((int) $fl->id === $affiliate->listingId) {
                        $scopeLabel = $fl->subject ?: $fl->title;
                        break;
                    }
                }
            }
          ?>
          <div class="field-row field-row--top">
            <label class="field-label">Listing</label>
            <div>
              <p class="text-strong"><?= View::e($scopeLabel) ?></p>
              <p class="form-hint">Listing assignment cannot be changed after creation.</p>
              <input type="hidden" name="fanlisting_id" value="<?= (int) ($affiliate->listingId ?? 0) ?>">
            </div>
          </div>
        <?php else: ?>
          <div class="field-row field-row--top">
            <label class="field-label" for="aff-listing">Listing</label>
            <div>
              <div class="select-wrap">
                <select id="aff-listing" name="fanlisting_id">
                  <option value="0"><?= View::e(($site_name ?? 'Collective') . ' (Collective)') ?></option>
                  <?php
                    $sortedListings = $listings;
                    usort($sortedListings, static fn($a, $b) => strcasecmp($a->subject ?: $a->title, $b->subject ?: $b->title));
                    $selId = (int) ($old['fanlisting_id'] ?? 0);
                  ?>
                  <?php foreach ($sortedListings as $fl): ?>
                    <option value="<?= (int) $fl->id ?>" <?= (int) $fl->id === $selId ? 'selected' : '' ?>>
                      <?= View::e($fl->subject ?: $fl->title) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <input type="hidden" name="fanlisting_id" value="<?= $contextFid ?>">
      <?php endif; ?>

      <!-- Affiliate Name -->
      <div class="field-row<?= !empty($fe['name']) ? ' has-error' : '' ?>">
        <label class="field-label" for="aff-name">
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
      <div class="field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="field-label" for="aff-url">
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
      <div class="field-row">
        <label class="field-label" for="aff-email">Email</label>
        <div>
          <input id="aff-email" type="email" name="email" class="form"
                 maxlength="255" placeholder="owner@example.com"
                 value="<?= View::e($v('email', $affiliate, 'email')) ?>">
        </div>
      </div>

      <!-- Status (edit mode only) -->
      <?php if ($isEdit): ?>
      <div class="field-row">
        <span class="field-label">Status</span>
        <div>
          <?php $currentStatus = $old['status'] ?? $affiliate->status ?? 'approved'; ?>
          <div class="choice-inline-group">
            <label class="form-check">
              <input type="radio" name="status" value="approved" <?= $currentStatus === 'approved' ? 'checked' : '' ?>>
              <span class="toggle-label">Approved</span>
            </label>
            <label class="form-check">
              <input type="radio" name="status" value="pending" <?= $currentStatus === 'pending' ? 'checked' : '' ?>>
              <span class="toggle-label">Pending</span>
            </label>
            <label class="form-check">
              <input type="radio" name="status" value="rejected" <?= $currentStatus === 'rejected' ? 'checked' : '' ?>>
              <span class="toggle-label">Rejected</span>
            </label>
          </div>
          <p class="form-hint">Changing to Rejected will also deactivate the affiliate.</p>
        </div>
      </div>
      <div class="field-row">
        <span class="field-label">Active</span>
        <div>
          <?php $isActive = isset($old['active']) ? $old['active'] === '1' : ($affiliate->active ?? false); ?>
          <div class="choice-inline-group">
            <label class="form-check">
              <input type="radio" name="active" value="1" <?= $isActive ? 'checked' : '' ?>>
              <span class="toggle-label">Active</span>
            </label>
            <label class="form-check">
              <input type="radio" name="active" value="0" <?= !$isActive ? 'checked' : '' ?>>
              <span class="toggle-label">Inactive</span>
            </label>
          </div>
          <p class="form-hint">Inactive affiliates are hidden from the public site.</p>
        </div>
      </div>
      <?php endif; ?>

      <!-- Application message (read-only, edit mode only, when present) -->
      <?php if ($isEdit && ($affiliate->applicationMessage ?? '') !== ''): ?>
      <div class="field-row field-row--top">
        <label class="field-label">Application Message</label>
        <div>
          <textarea class="form" rows="4" readonly><?= View::e($affiliate->applicationMessage) ?></textarea>
          <p class="form-hint">Submitted by the applicant. Read-only.</p>
        </div>
      </div>
      <?php endif; ?>

      <!-- Image -->
      <div class="field-row field-row--top">
        <span class="field-label">Image</span>
        <?php if ($isEdit && ($affiliate->image ?? '') !== ''): ?>
        <div class="img-upload-panel img-upload-panel--two-col">
          <div class="img-current-card" id="fl-img-current-card">
            <div class="img-current-preview">
              <img src="<?= $b ?>/uploads/affiliates/<?= View::e($affiliate->image) ?>"
                   alt="<?= View::e($affiliate->image) ?>">
            </div>
            <div class="img-current-footer">
              <span class="img-current-name"><?= View::e($affiliate->image) ?></span>
              <button type="button" class="btn btn-danger btn-sm img-delete-btn" id="fl-delete-image-btn"
                      data-delete-url="<?= $b ?>/admin/affiliates/<?= (int) $affiliate->id ?>/delete-image"
                      data-csrf="<?= View::e($csrf_token ?? '') ?>"
                      data-image-entity="affiliate_image"
                      data-max-size-hint="JPEG, PNG, GIF, WebP &mdash; max 500 KB">Delete Image</button>
            </div>
          </div>
          <div class="img-replace-col">
            <div class="panel-label">Replace with a new image</div>
            <label class="file-drop-zone file-drop-zone--sm">
              <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
              <span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Drag &amp; drop or click to browse</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 500 KB</span>
            </label>
          </div>
        </div>
        <?php else: ?>
        <div class="img-upload-panel">
          <label class="file-drop-zone">
            <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
            <span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Drag &amp; drop or click to browse</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 500 KB</span>
          </label>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- /.card-body -->

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Affiliate' ?></button>
      <?php if ($isEdit): ?>
        <button type="reset" class="btn btn-quaternary">Reset to Original</button>
      <?php endif; ?>
      <button type="button" class="btn btn-ghost js-form-clear">Clear</button>
      <?php if ($isEdit): ?>
        <span class="composer-footer-spacer"></span>
        <button type="submit"
                form="del-affiliate-<?= (int) $affiliate->id ?>"
                class="btn btn-danger">Delete</button>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($isEdit): ?>
<form id="del-affiliate-<?= (int) $affiliate->id ?>"
      method="POST"
      action="<?= $b ?>/admin/affiliates/<?= (int) $affiliate->id ?>/delete"
      data-del-modal
      data-del-entity="affiliate"
      data-del-name="<?= View::e($affiliate->name) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <?php if (($from_listing ?? 0) > 0): ?>
    <input type="hidden" name="from_listing" value="<?= (int) $from_listing ?>">
  <?php endif; ?>
</form>
<?php endif; ?>
