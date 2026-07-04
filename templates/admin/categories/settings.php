<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin category display settings page. Controls how categories are paginated
 in the admin table, including items-per-page configuration.
*/
declare(strict_types=1);
use Luster\Core\View;
$b = rtrim(View::e($base_url ?? ''), '/');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/categories" class="link">&larr; Back to Categories</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/categories/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('gear') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Display Settings</div>
        <div class="panel-header-sub">Controls how categories are paginated in the admin table.</div>
      </div>
    </div>

    <div class="card-body">

      <div class="field-row">
        <label class="field-label" for="per_page">Items Per Page</label>
        <div>
          <input type="number" id="per_page" name="per_page" class="form form--compact"
                 min="5" max="100"
                 value="<?= (int) ($per_page ?? 10) ?>">
          <p class="form-hint">How many categories to show per page in the table view. Min 5, max 100.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <button type="submit" name="reset_defaults" value="1" class="btn btn-quaternary">Reset to Default</button>
      <a href="<?= $b ?>/admin/categories" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
