<?php
/**
 * Admin — Owned Listings display settings.
 *
 * Variables provided by ListingController::indexSettings() via layout():
 * @var array  $cfg     Current config values
 * @var string $success Flash success message (empty when absent)
 * @var string $error   Flash error message (empty when absent)
 * @var string $base_url
 * @var string $csrf_token
 */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

function flsCfgVal(array $cfg, string $key, $default = ''): string {
    return (string) ($cfg[$key] ?? $default);
}
?>

<p style="margin-bottom:1.25rem">
  <a href="<?= $b ?>/admin/listings" class="neb-link">&larr; Back to Owned Listings</a>
</p>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div style="max-width:620px">
<form method="POST" action="<?= $b ?>/admin/listings/settings">
  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

  <!-- Sort order -->
  <div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
    <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem">Sort Order</div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label" for="fl-sort">Sort listings by</label>
      <div class="select-wrap" style="max-width:260px">
        <select id="fl-sort" name="owned_sort" class="form-select">
          <?php
            $currentSort = flsCfgVal($cfg, 'owned_sort', 'id');
            $sortOptions = [
                'id'       => 'Listing # (default)',
                'subject'  => 'Subject (A–Z)',
                'category' => 'Category (A–Z)',
                'status'   => 'Status (Current → Upcoming → Pending)',
            ];
            foreach ($sortOptions as $val => $lbl):
          ?>
            <option value="<?= \Luster\Core\View::e($val) ?>"
              <?= $currentSort === $val ? 'selected' : '' ?>>
              <?= \Luster\Core\View::e($lbl) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="form-hint">Applies to the main Owned Listings table. Filters and search are applied first, then the selected sort.</p>
    </div>
  </div>

  <!-- Image preview size -->
  <div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
    <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem">Image Preview</div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label" for="fl-preview-size">Listing image preview size</label>
      <div class="select-wrap" style="max-width:200px">
        <select id="fl-preview-size" name="image_preview_size" class="form-select">
          <?php
            $currentSize = flsCfgVal($cfg, 'image_preview_size', 'medium');
            foreach (['small' => 'Small (50 px)', 'medium' => 'Medium (100 px)', 'large' => 'Large (150 px)'] as $val => $lbl):
          ?>
            <option value="<?= \Luster\Core\View::e($val) ?>"
              <?= $currentSize === $val ? 'selected' : '' ?>>
              <?= \Luster\Core\View::e($lbl) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="form-hint">Controls the max height of listing images shown in the Owned Listings table.</p>
    </div>
  </div>

  <!-- Listings per page -->
  <div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
    <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem">Pagination</div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label" for="fl-per-page">Listings per page</label>
      <input type="number" id="fl-per-page" name="owned_per_page" class="form-input"
             min="5" max="200" style="max-width:100px"
             value="<?= (int) ($cfg['owned_per_page'] ?? 10) ?>">
      <p class="form-hint">How many listings to show per page on the Owned Listings table. Min 5, max 200. Default is 10.</p>
    </div>
  </div>

  <div style="display:flex;gap:.75rem;align-items:center">
    <button type="submit" class="btn btn-primary">Save Settings</button>
    <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
  </div>
</form>
</div>
