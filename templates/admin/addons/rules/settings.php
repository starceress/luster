<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Join Rules admin settings page. Configures the number of listings displayed per
 page in the admin panel's Join Rules overview table.
*/

declare(strict_types=1);
use Luster\Core\View;
/** @var int    $rules_per_page */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/rules" class="link">&larr; Back to Join Rules</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/rules/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="settings_action" value="" id="rules-settings-action">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('gear') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Display Settings</div>
        <div class="panel-header-sub">Controls how many listings are shown per page on the Join Rules index.</div>
      </div>
    </div>

    <div class="card-body">
      <div class="field-row">
        <label class="field-label" for="rules_per_page">Entries Per Page</label>
        <div>
          <input type="number" id="rules_per_page" name="rules_per_page"
                 class="form form--compact" min="5" max="100"
                 value="<?= (int) $rules_per_page ?>">
          <p class="form-hint">How many listings to show per page on the Join Rules index (min 5, max 100, default 10).</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary"
              data-set-field="rules-settings-action" data-set-value="">Save Settings</button>
      <button type="submit" class="btn btn-quaternary"
              data-set-field="rules-settings-action" data-set-value="reset">Reset to Default</button>
      <button type="submit" class="btn btn-ghost"
              data-set-field="rules-settings-action" data-set-value="clear">Clear</button>
    </div>
  </div>
</form>
