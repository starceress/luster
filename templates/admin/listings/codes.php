<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var \Luster\Models\Listing $listing
 * @var object[]                  $codes        CodesRepo result objects
 * @var string                    $uploads_url  Base URL for /uploads/codes
 * @var string                    $success
 * @var string                    $error
 * @var string                    $base_url
 * @var string                    $csrf_token
 */

$b = rtrim(View::e($base_url), '/');
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
  <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" class="neb-link">
    &larr; Back to <?= View::e($listing->name) ?>
  </a>
  <span style="font-size:.8rem;color:var(--text-dim)"><?= count($codes) ?> code<?= count($codes) !== 1 ? 's' : '' ?> uploaded</span>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ── Upload ──────────────────────────────────────────────────────────── -->
<div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
  <h2 class="card-title" style="margin-bottom:1rem">Upload Codes</h2>
  <form method="POST"
        action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/codes/upload"
        enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
      <div style="flex:1;min-width:220px">
        <label class="form-label" for="codes-input">
          Images
          <span style="font-weight:400;color:var(--text-dim)">(JPEG, PNG, GIF, WebP &mdash; max 500 KB each)</span>
        </label>
        <input class="form-input" type="file" id="codes-input" name="codes[]"
               accept="image/jpeg,image/png,image/gif,image/webp" multiple>
      </div>
      <button type="submit" class="btn btn-primary">Upload</button>
    </div>
    <p class="form-hint" style="margin-top:.5rem">
      Select multiple files at once. Width &times; height is detected automatically.
    </p>
  </form>
</div>

<!-- ── Codes grid ──────────────────────────────────────────────────────── -->

<?php if (empty($codes)): ?>

  <div class="neb-card" style="padding:2.5rem;text-align:center">
    <div style="font-size:1.75rem;margin-bottom:.75rem">🖼️</div>
    <div style="font-weight:600;color:var(--text);margin-bottom:.35rem">No codes yet</div>
    <div style="font-size:.85rem;color:var(--text-dim)">
      Upload code images above. Members will link to
      <a class="neb-link" href="<?= View::e($listing->url !== '' ? $listing->url : '#') ?>"
         target="_blank" rel="noopener noreferrer"><?= View::e($listing->name) ?></a>
      using these images.
    </div>
  </div>

<?php else: ?>

  <?php
  /*
   * Bulk and individual deletes use the HTML5 `form` attribute to associate
   * inputs and submit buttons with their respective <form> elements without
   * nesting. The bulk-form and per-code delete forms are declared here;
   * buttons inside the grid reference them by ID.
   */
  ?>

  <!-- Standalone bulk form (no content rendered — buttons reference it by id) -->
  <form id="codes-bulk-form" method="POST"
        action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/codes/bulk">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <input type="hidden" name="bulk_action" value="delete">
  </form>

  <!-- Standalone per-code delete forms -->
  <?php foreach ($codes as $code): ?>
  <form id="del-<?= (int) $code->id ?>" method="POST"
        action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/codes/<?= (int) $code->id ?>/delete"
        onsubmit="return confirm('Delete this code? This cannot be undone.')">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  </form>
  <?php endforeach; ?>

  <!-- Toolbar -->
  <div class="neb-toolbar" style="margin-bottom:1rem">
    <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--text-dim);cursor:pointer">
      <input type="checkbox" id="codes-select-all">
      Select all
    </label>
    <button type="submit" form="codes-bulk-form" class="btn btn-danger btn-sm"
            onclick="return confirm('Delete all selected codes? This cannot be undone.')">
      Delete Selected
    </button>
  </div>

  <!-- Grid -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:1rem">
    <?php foreach ($codes as $code): ?>
    <div class="neb-card" style="padding:.75rem;text-align:center">
      <!-- Checkbox (associated with bulk form via `form` attribute) -->
      <div style="display:flex;justify-content:flex-start;margin-bottom:.5rem">
        <input type="checkbox" form="codes-bulk-form" name="selected[]"
               value="<?= (int) $code->id ?>" class="code-check"
               style="cursor:pointer">
      </div>

      <!-- Preview image -->
      <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
           alt="Code #<?= (int) $code->id ?>"
           style="max-width:100%;max-height:80px;object-fit:contain;display:block;margin:0 auto .5rem"
           loading="lazy">

      <!-- Dimensions -->
      <?php if ($code->width > 0 && $code->height > 0): ?>
        <div style="font-size:.7rem;color:var(--text-dim);margin-bottom:.4rem">
          <?= (int) $code->width ?>&times;<?= (int) $code->height ?>
        </div>
      <?php endif; ?>

      <!-- Delete button (associated with this code's own form) -->
      <button type="submit" form="del-<?= (int) $code->id ?>"
              class="btn btn-danger btn-sm" style="width:100%">
        Delete
      </button>
    </div>
    <?php endforeach; ?>
  </div>

  <script>
  document.getElementById('codes-select-all').addEventListener('change', function () {
    document.querySelectorAll('.code-check').forEach(cb => { cb.checked = this.checked; });
  });
  </script>

<?php endif; ?>
