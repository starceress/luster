<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Keep In Mind (KIM) admin settings page. Configures the public KIM page URLs
 (sign-up, update, reset, list) and the admin display pagination setting.
*/

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

<div class="toolbar">
  <a href="<?= $b ?>/admin/kim" class="link">&larr; Back to Keep In Mind</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card card--gradient">
  <div class="panel-header">
    <div class="panel-header-icon panel-header-icon-filled"><?= View::icon('gear') ?></div>
    <div class="panel-header-meta">
      <div class="panel-header-title">Display & Embed Settings</div>
      <div class="panel-header-sub">Configure the display settings of the admin panel & the public page URLs used by the KIM List embed.</div>
    </div>
  </div>
  <form method="post" action="<?= $b ?>/admin/kim/settings">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div class="card-body">
      <div class="section-label">Display Settings</div>
      <div class="field-row">
        <label class="field-label">Default View</label>
        <div>
          <div class="radio-group">
            <label class="radio-label">
              <input type="radio" name="kim_default_landing" value="members"
                     <?= ($kim_default_landing ?? 'members') === 'members' ? 'checked' : '' ?>> Member View
            </label>
            <label class="radio-label">
              <input type="radio" name="kim_default_landing" value="listings"
                     <?= ($kim_default_landing ?? 'members') === 'listings' ? 'checked' : '' ?>> Listing View
            </label>
          </div>
          <p class="form-hint">Which view opens when you click Keep In Mind in the sidebar.</p>
        </div>
      </div>
      <div class="field-row">
        <label class="field-label" for="kim-per-page">Entries Per Page</label>
        <div>
          <input id="kim-per-page" type="number" name="kim_per_page"
                 class="form form--compact" min="5" max="25"
                 value="<?= (int) ($kim_per_page ?? 10) ?>">
          <p class="form-hint">How many rows to show per page in the Keep In Mind admin data table. Does not affect the public KIM embed — use the Embed Wizard for that. Min 5, max 25, default 10.</p>
        </div>
      </div>
    </div>

    <div class="card-body">
      <div class="section-label">Host Page URLs</div>
      <p class="text-dim">Enter the <strong>page filename</strong> of each KIM page on your site. Luster will prepend your site's base URL automatically. These are used for email links and cross-embed navigation.</p>

      <div class="card-body">
        <div class="field-row">
          <label class="field-label" for="kim-url-signup">Sign Up page</label>
          <input id="kim-url-signup" type="text" name="signup" class="form"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_signup'] ?? '') !== '' ? $kim_urls['kim_url_signup'] : $_defaults['kim_url_signup']) ?>">
        </div>
        <div class="field-row">
          <label class="field-label" for="kim-url-update">Update Info page</label>
          <input id="kim-url-update" type="text" name="update" class="form"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_update'] ?? '') !== '' ? $kim_urls['kim_url_update'] : $_defaults['kim_url_update']) ?>">
        </div>
        <div class="field-row">
          <label class="field-label" for="kim-url-reset">Password Reset page</label>
          <input id="kim-url-reset" type="text" name="reset" class="form"
                 maxlength="500"
                 value="<?= View::e(($kim_urls['kim_url_reset'] ?? '') !== '' ? $kim_urls['kim_url_reset'] : $_defaults['kim_url_reset']) ?>">
        </div>
        <div class="field-row">
          <label class="field-label" for="kim-url-list">Public KIM List page</label>
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
    </div>
  </form>
</div>
