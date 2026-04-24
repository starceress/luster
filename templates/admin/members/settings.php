<?php
use Luster\Core\View;
/**
 * Admin — Members display settings.
 * @var array  $cfg
 * @var string $success
 * @var string $error
 * @var string $base_url
 * @var string $csrf_token
 */
$b = rtrim(View::e($base_url), '/');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/members" class="link">&larr; Back to Members</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/members/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="gradient-card">

    <div class="listing-field-section">
      <div class="pc-section-label">Pagination</div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="m-per-page">Members Per Page</label>
        <div>
          <input type="number" id="m-per-page" name="per_page" class="form form--compact"
                 min="5" max="200"
                 value="<?= (int) ($cfg['per_page'] ?? 25) ?>">
          <p class="form-hint">How many members to show per page in the admin list view. Min 5, max 200.</p>
        </div>
      </div>

    </div><!-- /.listing-field-section -->

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <a href="<?= $b ?>/admin/members" class="btn btn-ghost">Cancel</a>
    </div>

  </div><!-- /.card.listing-field-card -->
</form>
