<?php declare(strict_types=1);
use Luster\Core\View;
$b = rtrim(View::e($base_url ?? ''), '/');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/collective" class="link">&larr; Back to Collective</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/collective/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('general') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Collective Settings</div>
        <div class="pc-head-sub">Turn site-wide collective features on or off, and choose where their messages go.</div>
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
        <p class="form-hint">Turns on the public contact form for your whole collective, including the contact page and the matching embed.</p>
      </div>
      <div class="form-group">
        <label class="form-label" for="s-collective-contact-recipient">Recipient email</label>
        <input id="s-collective-contact-recipient" type="email"
               name="collective_contact_recipient" class="form" maxlength="255"
               placeholder="Defaults to Owner Email"
               value="<?= View::e($site_settings['collective_contact_recipient'] ?? '') ?>">
        <p class="form-hint">Choose where collective contact messages are sent. Leave blank to use the Owner Email from General Settings.</p>
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
        <p class="form-hint">Lets other sites apply to affiliate with your whole collective. This is separate from affiliate applications for one specific fanlisting.</p>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Pending Items Digest</div>
      <div class="form-group">
        <label class="toggle-wrap">
          <input type="checkbox" name="pending_digest_enabled" value="1"
                 <?= ($site_settings['pending_digest_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
          <span class="toggle-label">Email me a weekly pending items digest</span>
        </label>
        <p class="form-hint">Sent to the Owner Email once a week when there are pending members, affiliate applications, code submissions, update requests, KIM sign-ups, or adoption applications waiting for review. Nothing is sent when the queue is empty.</p>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <a href="<?= $b ?>/admin/collective" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
