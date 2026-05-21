<?php
use Luster\Core\View;
/**
 * Admin - Owned Listings display settings.
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

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings" class="link">
    &larr; Back to Owned Listings
  </a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/listings/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('gear') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Display Settings</div>
        <div class="pc-head-sub">Controls how owned listings are sorted and paginated within the admin panel.</div>
      </div>
    </div>

    <div class="pc-section">
      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-sort">Sort listings by</label>
        <div>
          <div class="select-wrap select-auto">
            <select id="fl-sort" name="owned_sort" >
              <?php
                $currentSort = flsCfgVal($cfg, 'owned_sort', 'id');
                $sortOptions = [
                    'id'       => 'Listing #',
                    'subject'  => 'Subject (A-Z)',
                    'category' => 'Category (A-Z)',
                    'status'   => 'Status (Current -> Upcoming -> Pending)',
                ];
                foreach ($sortOptions as $val => $lbl):
              ?>
                <option value="<?= View::e($val) ?>" <?= $currentSort === $val ? 'selected' : '' ?>>
                  <?= View::e($lbl) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="pc-section">
      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-per-page">Listings per page</label>
        <div>
          <input type="number" id="fl-per-page" name="owned_per_page" class="form form--compact" min="5" max="50" value="<?= (int) ($cfg['owned_per_page'] ?? 10) ?>">
          <p class="form-hint">How many listings to show per page. Min 5, max 50.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <button type="button" class="btn btn-quaternary js-form-reset">Reset to Default</button>
      <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>