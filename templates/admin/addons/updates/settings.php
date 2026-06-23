<?php
declare(strict_types=1);
use Luster\Core\View;
/** @var int    $updates_per_page */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/updates" class="link">&larr; Back to News Feed</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/updates/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon panel-header-icon--filled"><?= View::icon('gear') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Display Settings</div>
        <div class="panel-header-sub">Controls how many update entries are shown per page.</div>
      </div>
    </div>

    <div class="card-body">
      <div class="field-row">
        <label class="field-label" for="updates_per_page">Entries Per Page</label>
        <div>
          <input type="number" id="updates_per_page" name="updates_per_page"
                 class="form form--compact" min="5" max="25"
                 value="<?= (int) $updates_per_page ?>">
          <p class="form-hint">How many entries to show per page (min 5, max 25, default 10). Applies to the updates index and all per-listing update feeds.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <a href="<?= $b ?>/admin/updates" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
