<?php
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
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('gear') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Display Settings</div>
        <div class="pc-head-sub">Controls how many listings are shown per page on the Join Rules index.</div>
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
              onclick="document.getElementById('rules-settings-action').value=''">Save Settings</button>
      <button type="submit" class="btn btn-quaternary"
              onclick="document.getElementById('rules-settings-action').value='reset'">Reset to Default</button>
      <button type="submit" class="btn btn-ghost"
              onclick="document.getElementById('rules-settings-action').value='clear'">Clear</button>
    </div>
  </div>
</form>
