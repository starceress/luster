<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Wishlist admin settings page. Configures the number of wishlist entries displayed
 per page in the admin panel's Wishlist view.
*/

declare(strict_types=1);
use Luster\Core\View;
/** @var int    $wishlist_per_page */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/wishlist" class="link">&larr; Back to Wishlist</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/wishlist/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon panel-header-icon-filled"><?= View::icon('gear') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Display Settings</div>
        <div class="panel-header-sub">Controls how many wishlist entries are shown per page.</div>
      </div>
    </div>

    <div class="card-body">
      <div class="field-row">
        <label class="field-label" for="wishlist_per_page">Entries Per Page</label>
        <div>
          <input type="number" id="wishlist_per_page" name="wishlist_per_page"
                 class="form form--compact" min="1" max="100"
                 value="<?= (int) $wishlist_per_page ?>">
          <p class="form-hint">How many entries to show per page. Min 1, max 100. Default is 10.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <a href="<?= $b ?>/admin/wishlist" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
