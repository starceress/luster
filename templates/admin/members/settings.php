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

<div class="toolbar">
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

  <div class="card card--gradient">

    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('gear') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Display Settings</div>
        <div class="pc-head-sub">Controls how members are paginated in the admin list view.</div>
      </div>
    </div>

    <div class="card-body">

      <div class="field-row field-row--top">
        <label class="field-label" for="m-per-page">Members Per Page</label>
        <div>
          <input type="number" id="m-per-page" name="per_page" class="form form--compact"
                 min="5" max="200"
                 value="<?= (int) ($cfg['per_page'] ?? 25) ?>">
          <p class="form-hint">How many items to show per page in the admin list view (applies to members and pending updates). Min 5, max 200.</p>
        </div>
      </div>

    </div><!-- /.card-body -->

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <button type="submit" name="reset_defaults" value="1" class="btn btn-quaternary">Reset to Default</button>
      <a href="<?= $b ?>/admin/members" class="btn btn-ghost">Cancel</a>
    </div>

  </div><!-- /.card.field-card -->
</form>
