<?php
/** @var array  $cfg */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var \Luster\Models\AdminUser|null $admin_user */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

function smtpCfgVal(array $cfg, string $key, string $default = ''): string {
    return (string) ($cfg[$key] ?? $default);
}
?>

<div class="neb-tabs" style="margin-bottom:1.5rem">
  <a href="<?= $b ?>/admin/email-templates"    class="neb-tab-btn">Email Templates</a>
  <a href="<?= $b ?>/admin/emails"             class="neb-tab-btn">Compose Email</a>
  <a href="<?= $b ?>/admin/new-email-template" class="neb-tab-btn">New Template</a>
  <a href="<?= $b ?>/admin/emails/smtp-settings" class="neb-tab-btn active">Email/SMTP Settings</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/emails/smtp-settings/save">
<input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

<div class="settings-card">

  <p class="text-muted" style="font-size:.8rem;margin-bottom:1rem">
    Leave SMTP Host blank to send via PHP's native <code>mail()</code> function (requires a From address below).
    Leave both SMTP Host and From Address blank to disable outgoing email entirely.
  </p>

  <?php if (smtpCfgVal($cfg, 'smtp_host') === '' && smtpCfgVal($cfg, 'smtp_from') === ''): ?>
  <div class="alert alert-warning" style="margin-bottom:1rem">
    <strong>Email is disabled.</strong>
    No From address is set. Fan password reset emails will not be delivered.
    Set a From Address below to enable PHP <code>mail()</code>, or configure an SMTP host.
  </div>
  <?php endif; ?>

  <div class="form-row">
    <div class="form-group">
      <label class="form-label" for="s-smtp-host">SMTP Host</label>
      <input id="s-smtp-host" type="text" name="smtp_host" class="form-input"
             maxlength="255" placeholder="smtp.example.com"
             value="<?= \Luster\Core\View::e(smtpCfgVal($cfg, 'smtp_host')) ?>">
    </div>
    <div class="form-group">
      <label class="form-label" for="s-smtp-port">SMTP Port</label>
      <input id="s-smtp-port" type="number" name="smtp_port" class="form-input"
             min="1" max="65535"
             value="<?= (int) ($cfg['smtp_port'] ?? 587) ?>">
    </div>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label class="form-label" for="s-smtp-user">Username</label>
      <input id="s-smtp-user" type="text" name="smtp_user" class="form-input"
             maxlength="255" autocomplete="off"
             value="<?= \Luster\Core\View::e(smtpCfgVal($cfg, 'smtp_user')) ?>">
    </div>
    <div class="form-group">
      <label class="form-label" for="s-smtp-pass">Password</label>
      <div class="input-icon-wrap">
        <input id="s-smtp-pass" type="password" name="smtp_pass" class="form-input"
               maxlength="500" autocomplete="new-password"
               placeholder="Leave blank to keep current">
        <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
          <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </button>
      </div>
      <p class="form-hint">Only fill in to change the stored password.</p>
    </div>
  </div>

  <div class="form-row">
    <div class="form-group">
      <label class="form-label" for="s-smtp-from">From Address</label>
      <input id="s-smtp-from" type="email" name="smtp_from" class="form-input"
             maxlength="255"
             value="<?= \Luster\Core\View::e(smtpCfgVal($cfg, 'smtp_from')) ?>">
    </div>
    <div class="form-group">
      <label class="form-label" for="s-smtp-from-name">From Name</label>
      <input id="s-smtp-from-name" type="text" name="smtp_from_name" class="form-input"
             maxlength="255"
             value="<?= \Luster\Core\View::e(smtpCfgVal($cfg, 'smtp_from_name')) ?>">
    </div>
    <div class="form-group">
      <label class="form-label" for="s-smtp-from-fl">Listing From Address</label>
      <input id="s-smtp-from-fl" type="email" name="smtp_from_listing" class="form-input"
             maxlength="255"
             value="<?= \Luster\Core\View::e(smtpCfgVal($cfg, 'smtp_from_listing')) ?>">
    </div>
    <div class="form-group">
      <label class="form-label" for="s-smtp-from-fl-name">Listing From Name</label>
      <input id="s-smtp-from-fl-name" type="text" name="smtp_from_listing_name" class="form-input"
             maxlength="255"
             value="<?= \Luster\Core\View::e(smtpCfgVal($cfg, 'smtp_from_listing_name')) ?>">
    </div>
  </div>

  <div class="form-group">
    <label class="form-label" for="s-smtp-secure">Encryption</label>
    <div class="select-wrap">
      <select id="s-smtp-secure" name="smtp_secure" class="form-select">
        <?php foreach (['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', '' => 'None'] as $val => $lbl): ?>
          <option value="<?= \Luster\Core\View::e($val) ?>"
            <?= smtpCfgVal($cfg, 'smtp_secure', 'tls') === $val ? 'selected' : '' ?>>
            <?= \Luster\Core\View::e($lbl) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

</div>

<div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
  <button type="button" class="btn btn-secondary btn-sm" id="smtp-validate-btn"
          data-url="<?= $b ?>/admin/settings/validate-smtp">
    Validate connection
  </button>
  <span id="smtp-validate-result" style="font-size:.85rem;display:none"></span>
</div>

<div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
  <button type="submit" class="btn btn-primary">Save Settings</button>
  <form method="POST" action="<?= $b ?>/admin/settings/test-email" style="display:contents">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">
    <button type="submit" class="btn btn-ghost btn-sm">Send test email</button>
  </form>
  <?php if (!empty($admin_user->email)): ?>
  <span class="form-hint" style="margin:0">
    &rarr; <code class="neb-code"><?= \Luster\Core\View::e($admin_user->email) ?></code>
  </span>
  <?php endif; ?>
</div>

</form>
