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

<p style="margin-bottom:1.25rem">
  <a href="<?= $b ?>/admin/kim" class="link">&larr; Back to Keep In Mind</a>
</p>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h2 class="card-title">KIM Page URLs</h2>
  </div>
  <form method="post" action="<?= $b ?>/admin/kim/settings">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div style="padding:1.25rem">
      <p style="font-size:.78rem;color:var(--text-dim);margin-bottom:1.25rem;line-height:1.5">
        Enter the <strong style="color:var(--text-muted)">page filename</strong> of each KIM page on your site
        (e.g. <span class="neb-code">kim.php</span>). The embed will prepend your site&rsquo;s base URL automatically.
        Leave blank to hide a link entirely.
      </p>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label-sm" for="kim-url-signup">Sign-up page</label>
          <input id="kim-url-signup" type="text" name="signup" class="form-input"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_signup'] ?? '') !== '' ? $kim_urls['kim_url_signup'] : $_defaults['kim_url_signup']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label-sm" for="kim-url-update">Update page</label>
          <input id="kim-url-update" type="text" name="update" class="form-input"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_update'] ?? '') !== '' ? $kim_urls['kim_url_update'] : $_defaults['kim_url_update']) ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label-sm" for="kim-url-reset">Password reset page</label>
          <input id="kim-url-reset" type="text" name="reset" class="form-input"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_reset'] ?? '') !== '' ? $kim_urls['kim_url_reset'] : $_defaults['kim_url_reset']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label-sm" for="kim-url-list">List page</label>
          <input id="kim-url-list" type="text" name="list" class="form-input"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_list'] ?? '') !== '' ? $kim_urls['kim_url_list'] : $_defaults['kim_url_list']) ?>">
        </div>
      </div>

      <div class="section-divider"></div>
      <div style="display:flex;gap:.75rem;align-items:center">
        <button type="submit" class="btn btn-primary">Save Settings</button>
        <a href="<?= $b ?>/admin/kim" class="btn btn-ghost">Cancel</a>
      </div>
    </div>
  </form>
</div>
