<?php

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
$_defaults = [
    'kim_url_signup' => 'kim.php',
    'kim_url_update' => 'kim-update.php',
    'kim_url_reset'  => 'kim-reset.php',
    'kim_url_list'   => 'kim-list.php',
];
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/kim" class="link">&larr; Back to KIM List</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('gear') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Display Settings</div>
      <div class="pc-head-sub">Configure the public page URLs used by the KIM List embed.</div>
    </div>
  </div>
  <form method="post" action="<?= $b ?>/admin/kim/settings">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div class="pc-section">
      <p class="form-hint mb-section">Enter the <strong>page filename</strong> of each KIM page on your site. The embed will prepend your site's base URL automatically. Leave blank to hide a link entirely.</p>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label-sm" for="kim-url-signup">Sign Up page</label>
          <input id="kim-url-signup" type="text" name="signup" class="form"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_signup'] ?? '') !== '' ? $kim_urls['kim_url_signup'] : $_defaults['kim_url_signup']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label-sm" for="kim-url-update">Update Info page</label>
          <input id="kim-url-update" type="text" name="update" class="form"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_update'] ?? '') !== '' ? $kim_urls['kim_url_update'] : $_defaults['kim_url_update']) ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label-sm" for="kim-url-reset">Password Reset page</label>
          <input id="kim-url-reset" type="text" name="reset" class="form"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_reset'] ?? '') !== '' ? $kim_urls['kim_url_reset'] : $_defaults['kim_url_reset']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label-sm" for="kim-url-list">Public KIM List page</label>
          <input id="kim-url-list" type="text" name="list" class="form"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_list'] ?? '') !== '' ? $kim_urls['kim_url_list'] : $_defaults['kim_url_list']) ?>">
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <button type="submit" name="reset_defaults" value="1" class="btn btn-quaternary">Reset to Default</button>
      <button type="reset" class="btn btn-ghost">Clear</button>
      <a href="<?= $b ?>/admin/kim" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
