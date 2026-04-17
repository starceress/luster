<?php
declare(strict_types=1);
use Luster\Core\View;
/** @var string $display_mode */
/** @var int    $per_page */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/joined" class="link">&larr; Back to Joined FLs</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/joined/settings">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="gradient-card">

    <div class="listing-field-section">
      <div class="pc-section-label">Display Preferences</div>

      <!-- Sort order -->
      <div class="listing-field-row">
        <label class="listing-field-label" for="display_mode">Sort Listings By</label>
        <div class="select-wrap">
          <select id="display_mode" name="display_mode" class="form-select">
            <option value="all"<?=      ($display_mode ?? 'all') === 'all'      ? ' selected' : '' ?>>Show All</option>
            <option value="network"<?=  ($display_mode ?? 'all') === 'network'  ? ' selected' : '' ?>>Group by Network</option>
            <option value="category"<?= ($display_mode ?? 'all') === 'category' ? ' selected' : '' ?>>Group by Category</option>
            <option value="status"<?=   ($display_mode ?? 'all') === 'status'   ? ' selected' : '' ?>>Group by Status</option>
          </select>
        </div>
      </div>

      <!-- Per page -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="per_page">Items Per Page</label>
        <div>
          <input type="number" id="per_page" name="per_page" class="form-input"
                 min="5" max="50" style="max-width:120px"
                 value="<?= (int) ($per_page ?? 10) ?>">
          <p class="form-hint">How many joined listings to show per page in the table view. Min 5, max 50.</p>
        </div>
      </div>

    </div><!-- /.listing-field-section -->

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <a href="<?= $b ?>/admin/joined" class="btn btn-ghost">Cancel</a>
    </div>

  </div><!-- /.card.listing-field-card -->
</form>
