<?php
use Luster\Core\View;
/**
 * Admin — Owned Listings display settings.
 *
 * @var array  $cfg
 * @var string $success
 * @var string $error
 * @var string $base_url
 * @var string $csrf_token
 */
$b = rtrim(View::e($base_url), '/');
function flsCfgVal(array $cfg, string $key, $default = ''): string {
    return (string) ($cfg[$key] ?? $default);
}
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings" class="link">
    &larr; Back to Owned Listings
  </a>
</div>

<form method="POST" action="<?= $b ?>/admin/listings/settings">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card">
    <div class="card-head">Display Settings</div>
    <div class="card-body">
      <div class="pc-section-label">Sort Order</div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-sort">Sort listings by</label>
        <div>
          <div class="select-wrap" style="width:fit-content">
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
                <option value="<?= View::e($val) ?>"
                  <?= $currentSort === $val ? 'selected' : '' ?>>
                  <?= View::e($lbl) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="pc-section-label">Pagination</div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-per-page">Listings per page</label>
        <div>
          <input type="number" id="fl-per-page" name="owned_per_page" class="form-input" min="5" max="50" style="width:fit-content" value="<?= (int) ($cfg['owned_per_page'] ?? 10) ?>">
          <p class="form-hint">How many listings to show per page. Min 5, max 50.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>