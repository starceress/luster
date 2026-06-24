<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin collective settings page. Provides toggles and configuration for
 site-wide collective features such as the contact form, codes submission,
 affiliate applications, and adoption form settings.
*/

declare(strict_types=1);
use Luster\Core\View;
$b = rtrim(View::e($base_url ?? ''), '/');
?>

<div class="toolbar">
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

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon panel-header-icon--filled"><?= View::icon('general') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Collective Settings</div>
        <div class="panel-header-sub">Turn site-wide collective features on or off, and choose where their messages go.</div>
      </div>
    </div>

    <div class="card-body">
      <div class="section-label">Contact Form</div>

      <div class="field-row field-row--top">
        <label class="field-label">Contact Form</label>
        <div>
          <label class="toggle-wrap">
            <input type="checkbox" name="collective_contact_enabled" value="1"
                   <?= ($site_settings['collective_contact_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span class="toggle-label">Enable collective contact form</span>
          </label>
          <p class="form-hint">Turns on the public contact form for your whole collective, including the contact page and the matching embed.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="s-collective-contact-recipient">Recipient email</label>
        <div>
          <input id="s-collective-contact-recipient" type="email"
                 name="collective_contact_recipient" class="form" maxlength="255"
                 placeholder="Defaults to Owner Email"
                 value="<?= View::e($site_settings['collective_contact_recipient'] ?? '') ?>">
          <p class="form-hint">Where collective contact messages are sent. Leave blank to use the Owner Email from General Settings.</p>
        </div>
      </div>
    </div>

    <div class="card-body">
      <div class="section-label">Affiliate Applications</div>

      <div class="field-row field-row--top">
        <label class="field-label">Applications</label>
        <div>
          <label class="toggle-wrap">
            <input type="checkbox" name="collective_affiliate_apply_enabled" value="1"
                   <?= ($site_settings['collective_affiliate_apply_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
            <span class="toggle-label">Enable collective affiliate applications</span>
          </label>
          <p class="form-hint">Lets other sites apply to affiliate with your whole collective. This is separate from affiliate applications for one specific fanlisting.</p>
        </div>
      </div>
    </div>

    <div class="card-body">
      <div class="section-label">Host Page URLs</div>

      <div class="field-row field-row--top">
        <label class="field-label" for="s-form-url-codes-submit">Code Donation Form</label>
        <div>
          <input id="s-form-url-codes-submit" type="text" name="collective_form_url_codes_submit" class="form" maxlength="200"
                 value="<?= View::e($site_settings['collective_form_url_codes_submit'] ?? '') ?>"
                 placeholder="codes-submit.php">
          <p class="form-hint">The public page on your site where visitors can donate codes to your collective.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="s-form-url-affiliate-apply">Affiliate Application</label>
        <div>
          <input id="s-form-url-affiliate-apply" type="text" name="collective_form_url_affiliate_apply" class="form" maxlength="200"
                 value="<?= View::e($site_settings['collective_form_url_affiliate_apply'] ?? '') ?>"
                 placeholder="affiliate-apply.php">
          <p class="form-hint">The public page on your site where other sites can apply to affiliate with your collective.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="s-form-url-adopt">Adoption Form</label>
        <div>
          <input id="s-form-url-adopt" type="text" name="collective_form_url_adopt" class="form" maxlength="200"
                 value="<?= View::e($site_settings['collective_form_url_adopt'] ?? '') ?>"
                 placeholder="adopt.php">
          <p class="form-hint">The public page on your site hosting the collective adoption application form.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="s-form-url-contact">Contact Form</label>
        <div>
          <input id="s-form-url-contact" type="text" name="collective_form_url_contact" class="form" maxlength="200"
                 value="<?= View::e($site_settings['collective_form_url_contact'] ?? '') ?>"
                 placeholder="contact.php">
          <p class="form-hint">The public page on your site hosting the collective contact form.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
  </div>
</form>
