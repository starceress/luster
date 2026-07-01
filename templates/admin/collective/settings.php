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
$_contactOn   = ($site_settings['collective_contact_enabled'] ?? '1') === '1';
$_affiliateOn = ($site_settings['collective_affiliate_apply_enabled'] ?? '1') === '1';
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

  <div class="card card-gradient">
    <div class="panel-header">
      <div class="panel-header-icon panel-header-icon-filled"><?= View::icon('general') ?></div>
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
                   data-toggle-row="row-contact-recipient,row-url-contact"
                   <?= $_contactOn ? 'checked' : '' ?>>
            <span class="toggle-label">Enable collective contact form</span>
          </label>
          <p class="form-hint">Turns on the public contact form for your whole collective, including the contact page and the matching embed.</p>
        </div>
      </div>

      <div class="field-row" id="row-contact-recipient"<?= !$_contactOn ? ' style="display:none"' : '' ?>>
        <label class="field-label" for="s-collective-contact-recipient">Recipient email</label>
        <div>
          <input id="s-collective-contact-recipient" type="email"
                 name="collective_contact_recipient" class="form" maxlength="255"
                 placeholder="Defaults to Owner Email"
                 value="<?= View::e($site_settings['collective_contact_recipient'] ?? '') ?>">
          <p class="form-hint">Where collective contact messages are sent. Leave blank to use the Owner Email from General Settings.</p>
        </div>
      </div>

      <div class="field-row" id="row-url-contact"<?= !$_contactOn ? ' style="display:none"' : '' ?>>
        <label class="field-label" for="s-form-url-contact">Host page URL</label>
        <div>
          <input id="s-form-url-contact" type="text" name="collective_form_url_contact" class="form" maxlength="200"
                 value="<?= View::e($site_settings['collective_form_url_contact'] ?? '') ?>"
                 placeholder="contact.php">
          <p class="form-hint">The public page on your site hosting the collective contact form.</p>
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
                   data-toggle-row="row-url-affiliate-apply,row-aff-style,row-aff-toggle-label"
                   <?= $_affiliateOn ? 'checked' : '' ?>>
            <span class="toggle-label">Enable collective affiliate applications</span>
          </label>
          <p class="form-hint">Lets other sites apply to affiliate with your whole collective. This is separate from affiliate applications for one specific fanlisting.</p>
        </div>
      </div>

      <div class="field-row" id="row-aff-style"<?= !$_affiliateOn ? ' style="display:none"' : '' ?>>
        <label class="field-label" for="s-aff-style">Toggle style</label>
        <div>
          <div class="select-wrap select-auto">
            <select id="s-aff-style" name="collective_affiliate_apply_style" class="form">
              <option value="link" <?= ($site_settings['collective_affiliate_apply_style'] ?? 'link') === 'link' ? 'selected' : '' ?>>Text link</option>
              <option value="button" <?= ($site_settings['collective_affiliate_apply_style'] ?? 'link') === 'button' ? 'selected' : '' ?>>Button</option>
            </select>
          </div>
          <p class="form-hint">How the <code>{affiliate_apply_toggle}</code> appears &mdash; as a plain underlined link or a styled button.</p>
        </div>
      </div>

      <div class="field-row" id="row-aff-toggle-label"<?= !$_affiliateOn ? ' style="display:none"' : '' ?>>
        <label class="field-label" for="s-aff-toggle-label">Toggle label</label>
        <div>
          <input id="s-aff-toggle-label" type="text" name="collective_affiliate_apply_toggle_label" class="form" maxlength="200"
                 value="<?= View::e($site_settings['collective_affiliate_apply_toggle_label'] ?? '') ?>"
                 placeholder="Apply to become an affiliate?">
          <p class="form-hint">The text on the collapsible <code>{affiliate_apply_toggle}</code>. Leave blank for the default.</p>
        </div>
      </div>

      <div class="field-row" id="row-url-affiliate-apply"<?= !$_affiliateOn ? ' style="display:none"' : '' ?>>
        <label class="field-label" for="s-form-url-affiliate-apply">Host page URL</label>
        <div>
          <input id="s-form-url-affiliate-apply" type="text" name="collective_form_url_affiliate_apply" class="form" maxlength="200"
                 value="<?= View::e($site_settings['collective_form_url_affiliate_apply'] ?? '') ?>"
                 placeholder="affiliates.php">
          <p class="form-hint">The public page on your site where other sites can apply to affiliate with your collective.</p>
        </div>
      </div>
    </div>

    <?php
      $_csCollOn = ($site_settings['collective_codes_submit_enabled'] ?? '1') === '1';
    ?>
    <div class="card-body">
      <div class="section-label">Code Donations</div>

      <div class="field-row field-row--top">
        <label class="field-label">Code Donations</label>
        <div>
          <label class="toggle-wrap">
            <input type="checkbox" name="collective_codes_submit_enabled" value="1"
                   data-toggle-row="row-cs-style,row-cs-toggle-label,row-cs-url"
                   <?= $_csCollOn ? 'checked' : '' ?>>
            <span class="toggle-label">Enable collective code donations</span>
          </label>
          <p class="form-hint">Lets visitors submit fan codes to your collective. This is separate from per-listing code donations.</p>
        </div>
      </div>

      <div class="field-row" id="row-cs-style"<?= !$_csCollOn ? ' style="display:none"' : '' ?>>
        <label class="field-label" for="s-cs-style">Toggle style</label>
        <div>
          <div class="select-wrap select-auto">
            <select id="s-cs-style" name="collective_codes_submit_style" class="form">
              <option value="link" <?= ($site_settings['collective_codes_submit_style'] ?? 'link') === 'link' ? 'selected' : '' ?>>Text link</option>
              <option value="button" <?= ($site_settings['collective_codes_submit_style'] ?? 'link') === 'button' ? 'selected' : '' ?>>Button</option>
            </select>
          </div>
          <p class="form-hint">How the <code>{codes_submit_toggle}</code> appears in collective codes templates.</p>
        </div>
      </div>

      <div class="field-row" id="row-cs-toggle-label"<?= !$_csCollOn ? ' style="display:none"' : '' ?>>
        <label class="field-label" for="s-cs-toggle-label">Toggle label</label>
        <div>
          <input id="s-cs-toggle-label" type="text" name="collective_codes_submit_toggle_label" class="form" maxlength="200"
                 value="<?= View::e($site_settings['collective_codes_submit_toggle_label'] ?? '') ?>"
                 placeholder="Donate a code?">
          <p class="form-hint">The text on the collapsible <code>{codes_submit_toggle}</code>. Leave blank for the default.</p>
        </div>
      </div>

      <div class="field-row" id="row-cs-url"<?= !$_csCollOn ? ' style="display:none"' : '' ?>>
        <label class="field-label" for="s-form-url-codes-submit">Host page URL</label>
        <div>
          <input id="s-form-url-codes-submit" type="text" name="collective_form_url_codes_submit" class="form" maxlength="200"
                 value="<?= View::e($site_settings['collective_form_url_codes_submit'] ?? '') ?>"
                 placeholder="codes-submit.php">
          <p class="form-hint">The public page on your site where visitors can donate codes to your collective.</p>
        </div>
      </div>
    </div>

    <?php if ($adoption_installed ?? false): ?>
    <div class="card-body">
      <div class="section-label">Adoptions</div>
      <div class="field-row field-row--top">
        <label class="field-label" for="s-form-url-adopt">Host page URL</label>
        <div>
          <input id="s-form-url-adopt" type="text" name="collective_form_url_adopt" class="form" maxlength="200"
                 value="<?= View::e($site_settings['collective_form_url_adopt'] ?? '') ?>"
                 placeholder="adopt.php">
          <p class="form-hint">The public page on your site hosting the collective adoption application form.</p>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($kim_installed ?? false): ?>
    <div class="card-body">
      <div class="section-label">KIM Page URLs</div>

      <div class="field-row field-row--top">
        <label class="field-label" for="s-kim-url-signup">Sign Up page</label>
        <div>
          <input id="s-kim-url-signup" type="text" name="kim_url_signup" class="form" maxlength="500"
                 value="<?= View::e($site_settings['kim_url_signup'] ?? '') ?>"
                 placeholder="kim.php">
          <p class="form-hint">The page hosting the KIM sign-up form.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="s-kim-url-update">Update Info page</label>
        <div>
          <input id="s-kim-url-update" type="text" name="kim_url_update" class="form" maxlength="500"
                 value="<?= View::e($site_settings['kim_url_update'] ?? '') ?>"
                 placeholder="kim-update.php">
          <p class="form-hint">The page hosting the KIM member update form.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="s-kim-url-reset">Password Reset page</label>
        <div>
          <input id="s-kim-url-reset" type="text" name="kim_url_reset" class="form" maxlength="500"
                 value="<?= View::e($site_settings['kim_url_reset'] ?? '') ?>"
                 placeholder="kim-reset.php">
          <p class="form-hint">The page hosting the KIM password reset form.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="s-kim-url-list">Public KIM List page</label>
        <div>
          <input id="s-kim-url-list" type="text" name="kim_url_list" class="form" maxlength="500"
                 value="<?= View::e($site_settings['kim_url_list'] ?? '') ?>"
                 placeholder="kim-list.php">
          <p class="form-hint">The page hosting the public KIM member list.</p>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
  </div>
</form>
