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

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $action ?>" enctype="multipart/form-data" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="sort_order" value="<?= View::e($v('sort_order', $affiliate, 'sortOrder', (string) ($next_sort_order ?? 1))) ?>">
  <?php if ($isEdit && $contextFid > 0): ?>
    <input type="hidden" name="from_listing" value="<?= $contextFid ?>">
  <?php endif; ?>

  <div class="card card--gradient">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('wrench') ?></div>
        <div class="pc-head-meta">
        <div class="pc-head-title"><?= $isEdit ? View::e($affiliate->name) : 'Add New Affiliate' ?></div>
        <div class="pc-head-sub"><?= $isEdit ? 'Update this affiliate\'s details.' : 'Add a site to your affiliates list.' ?></div>
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
        <label class="field-label" for="aff-status">Status</label>
        <div>
          <?php $currentStatus = $old['status'] ?? $affiliate->status ?? 'approved'; ?>
          <div class="select-wrap">
            <select id="aff-status" name="status">
              <option value="approved" <?= $currentStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
              <option value="pending"  <?= $currentStatus === 'pending'  ? 'selected' : '' ?>>Pending</option>
              <option value="rejected" <?= $currentStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
          </div>
          <p class="form-hint">Changing to Rejected will also deactivate the affiliate.</p>
        </div>
      </div>
      <div class="field-row">
        <label class="field-label" for="aff-active">Active</label>
        <div>
          <?php $isActive = isset($old['active']) ? $old['active'] === '1' : ($affiliate->active ?? false); ?>
          <div class="select-wrap">
            <select id="aff-active" name="active">
              <option value="1" <?= $isActive ? 'selected' : '' ?>>Active</option>
              <option value="0" <?= !$isActive ? 'selected' : '' ?>>Inactive</option>
            </select>
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
              <strong>Drag &amp; drop or click to browse</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
          </label>
        </div>
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
  <?php if ($contextFid > 0): ?>
    <input type="hidden" name="from_listing" value="<?= $contextFid ?>">
  <?php endif; ?>
</form>
<?php endif; ?>
