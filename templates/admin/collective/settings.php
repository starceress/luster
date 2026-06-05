<?php

declare(strict_types=1);

/**
 * @var array  $site_settings
 * @var string $success
 * @var string $error
 * @var string $base_url
 * @var string $csrf_token
 */

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/collective" class="link">&larr; Back to Collective Templates</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/collective/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('general') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Collective Settings</div>
        <div class="pc-head-sub">Site-wide collective features and public-facing pages.</div>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Contact Form</div>
      <div class="form-group">
        <label class="toggle-wrap">
          <input type="checkbox" name="collective_contact_enabled" value="1"
                 <?= ($site_settings['collective_contact_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
          <span class="toggle-label">Enable collective contact form</span>
        </label>
        <p class="form-hint">Controls the <code class="neb-code">/contact</code> page and the <code class="neb-code">collective-contact.php</code> embed.</p>
      </div>
      <div class="form-group">
        <label class="form-label" for="s-collective-contact-recipient">Recipient email</label>
        <input id="s-collective-contact-recipient" type="email"
               name="collective_contact_recipient" class="form" maxlength="255"
               placeholder="Defaults to Owner Email"
               value="<?= View::e($site_settings['collective_contact_recipient'] ?? '') ?>">
        <p class="form-hint">Override which email address receives collective contact messages. Leave blank to use the Owner Email.</p>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Affiliate Applications</div>
      <div class="form-group">
        <label class="toggle-wrap">
          <input type="checkbox" name="collective_affiliate_apply_enabled" value="1"
                 <?= ($site_settings['collective_affiliate_apply_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
          <span class="toggle-label">Enable collective affiliate applications</span>
        </label>
        <p class="form-hint">Controls the <code class="neb-code">/affiliate-apply/collective</code> route and the <code class="neb-code">affiliate-apply.php</code> embed with <code class="neb-code">$luster_fanlisting_id = 0</code>.</p>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <a href="<?= $b ?>/admin/collective" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
