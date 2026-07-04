<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Members display settings. Configures default sort order, items per page, and
 other member-list display preferences that apply across all listing member views
 in the admin panel.
*/
use Luster\Core\View;
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

    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('gear') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Display Settings</div>
        <div class="panel-header-sub">Controls how members are paginated in the admin list view.</div>
      </div>
    </div>

    <div class="card-body">

      <div class="field-row">
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
