<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin feedback and bug report page. Provides a form for reporting bugs, asking
 questions, or suggesting features directly to the Luster developer. Includes
 automatic system information attachment and a copyable fallback for when email
 delivery fails.
*/
use Luster\Core\Version;
use Luster\Core\View;
?>

<?php if ($success !== ''): ?>
<div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<?php if ($sender_warning === 'strong'): ?>
<div class="alert alert-warning">
  <?= View::icon('alert') ?> <strong>Owner Email is not set.</strong>
  Your report may not be delivered. Set it in <a href="<?= View::e($base_url) ?>/admin/settings#panel-general" class="link">Settings &rsaquo; General</a>, or use the copy fallback below the form.
</div>
<?php endif; ?>

<?php if ($send_failed): ?>
<div class="alert alert-error">
  <?= View::icon('alert') ?> Sending failed. Copy the report below and email it to
  <strong><?= View::e(Version::SUPPORT_EMAIL) ?></strong>.
</div>
<?php endif; ?>

<form method="POST" action="<?= View::e($base_url) ?>/admin/feedback">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon panel-header-icon--filled"><?= View::icon('report') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Report Form</div>
        <div class="panel-header-sub">Report bugs, ask questions, or suggest features.</div>
      </div>
    </div>
    <div class="card-body">

      <!-- Contact name -->
      <div class="field-row<?= isset($field_errors['contact_name']) ? ' has-error' : '' ?>">
        <label class="field-label" for="f-contact-name">Name <span class="req">*</span></label>
        <div>
          <input type="text" id="f-contact-name" name="contact_name" class="form" required
                 value="<?= View::e($old['contact_name'] ?? $owner_name) ?>" maxlength="200">
          <?php if (isset($field_errors['contact_name'])): ?>
          <p class="field-error"><?= View::e($field_errors['contact_name']) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Contact email -->
      <div class="field-row<?= isset($field_errors['contact_email']) ? ' has-error' : '' ?>">
        <label class="field-label" for="f-contact">Email <span class="req">*</span></label>
        <div>
          <input type="email" id="f-contact" name="contact_email" class="form" required
                 value="<?= View::e($old['contact_email'] ?? $admin_email) ?>" maxlength="254">
          <p class="form-hint">By default, the report is sent using the email registered with your admin account. If you'd like to use another email, type it above.</p>
          <?php if (isset($field_errors['contact_email'])): ?>
          <p class="field-error"><?= View::e($field_errors['contact_email']) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Contact URL (optional) -->
      <div class="field-row<?= isset($field_errors['contact_url']) ? ' has-error' : '' ?>">
        <label class="field-label" for="f-contact-url">Website URL</label>
        <div>
          <input type="url" id="f-contact-url" name="contact_url" class="form"
                 value="<?= View::e($old['contact_url'] ?? '') ?>" maxlength="512" placeholder="https://yoursite.example.com">
          <p class="form-hint">Optional. Include your site URL if you'd like us to follow up via your contact page.</p>
          <?php if (isset($field_errors['contact_url'])): ?>
          <p class="field-error"><?= View::e($field_errors['contact_url']) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Report type -->
      <div class="field-row<?= isset($field_errors['type']) ? ' has-error' : '' ?>">
        <label class="field-label" for="f-type">Report type <span class="req">*</span></label>
        <div>
          <div class="select-wrap">
            <select id="f-type" name="type" class="form">
              <option value="">&mdash; Select &mdash;</option>
              <?php foreach (['bug' => 'Bug Report', 'question' => 'Question/Concern', 'feature' => 'Feature Request', 'other' => 'Other'] as $val => $label): ?>
              <option value="<?= View::e($val) ?>"<?= ($old['type'] ?? '') === $val ? ' selected' : '' ?>><?= View::e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if (isset($field_errors['type'])): ?>
          <p class="field-error"><?= View::e($field_errors['type']) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Subject -->
      <div class="field-row<?= isset($field_errors['subject']) ? ' has-error' : '' ?>">
        <label class="field-label" for="f-subject">Subject <span class="req">*</span></label>
        <div>
          <input type="text" id="f-subject" name="subject" class="form"
                 value="<?= View::e($old['subject'] ?? '') ?>" maxlength="200">
          <?php if (isset($field_errors['subject'])): ?>
          <p class="field-error"><?= View::e($field_errors['subject']) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Description -->
      <div class="field-row field-row--top<?= isset($field_errors['message']) ? ' has-error' : '' ?>">
        <label class="field-label" for="f-message">Description <span class="req">*</span></label>
        <div>
          <textarea id="f-message" name="message" class="form" rows="9"><?= View::e($old['message'] ?? '') ?></textarea>
          <p class="form-hint">Describe your issue, question, or suggestion in as much detail as possible.</p>
          <?php if (isset($field_errors['message'])): ?>
          <p class="field-error"><?= View::e($field_errors['message']) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- System info -->
      <div class="field-row field-row--top">
        <label class="field-label">System Info</label>
        <div>
          <label class="form-check">
            <input type="checkbox" id="f-sysinfo" name="include_sysinfo" value="1"
                   <?= ($old['include_sysinfo'] ?? '1') !== '' ? 'checked' : '' ?>>
            Attach system information to this report
          </label>
          <p class="form-hint">Includes Luster version, PHP version, database version, and installed add-ons. Recommended for bug reports.</p>
          <details class="form-details">
            <summary>Preview system info</summary>
            <div class="form-details-body">
              <textarea class="form form-mono" rows="12" readonly><?= View::e($sysinfo_text) ?></textarea>
            </div>
          </details>
        </div>
      </div>

      <!-- Error log (only shown when there are entries) -->
      <?php if (!$errorlog_empty): ?>
      <div class="field-row field-row--top">
        <label class="field-label">Error Log</label>
        <div>
          <label class="form-check">
            <input type="checkbox" id="f-errorlog" name="include_errorlog" value="1"
                   <?= ($old['include_errorlog'] ?? '1') !== '' ? 'checked' : '' ?>>
            Attach the last 10 error log entries
          </label>
          <p class="form-hint">Recent technical errors from your install. Useful for diagnosing bugs. May include internal file paths.</p>
          <details class="form-details">
            <summary>Preview error log</summary>
            <div class="form-details-body">
              <textarea class="form form-mono" rows="12" readonly><?= View::e($errorlog_text) ?></textarea>
            </div>
          </details>
        </div>
      </div>
      <?php endif; ?>

    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary"><?= View::icon('send') ?> Send Report</button>
      <button type="reset" class="btn btn-ghost">Clear</button>
    </div>
  </div>
</form>

<?php if ($send_failed && $composed_body !== ''): ?>
<div class="card card--gradient" style="margin-top:1.5rem">
  <div class="card-body">
    <div class="field-row field-row--top">
      <label class="field-label">Copy report</label>
      <div>
        <textarea id="lstr-composed-body" class="form form-mono" rows="20" readonly><?= View::e($composed_body) ?></textarea>
        <p class="form-hint">
          Copy this and email it manually to <strong><?= View::e(Version::SUPPORT_EMAIL) ?></strong>.
        </p>
        <button type="button" class="btn btn-quaternary btn-sm" id="lstr-copy-btn">Copy to clipboard</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var btn = document.getElementById('lstr-copy-btn');
  var ta  = document.getElementById('lstr-composed-body');
  if (!btn || !ta) return;
  btn.addEventListener('click', function () {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(ta.value).then(function () {
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = 'Copy to clipboard'; }, 2500);
      });
    } else {
      ta.select();
      document.execCommand('copy');
      btn.textContent = 'Copied!';
      setTimeout(function () { btn.textContent = 'Copy to clipboard'; }, 2500);
    }
  });
}());
</script>
<?php endif; ?>