<?php
/** @var array  $cfg     Current config values */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

function cfgVal(array $cfg, string $key, $default = ''): string {
    return (string) ($cfg[$key] ?? $default);
}
?>
<style>
  .settings-form { max-width:680px; }
  .card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;margin-bottom:1.25rem; }
  .card-title { font-size:.95rem;font-weight:700;color:#111827;margin-bottom:1rem;padding-bottom:.6rem;border-bottom:1px solid #f3f4f6; }
  .form-group { margin-bottom:1.1rem; }
  .form-group label { display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:.35rem; }
  .form-group input, .form-group select { width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;color:#111827; }
  .form-group input:focus, .form-group select:focus { outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15); }
  .form-row { display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1rem; }
  .form-hint { font-size:.75rem;color:#6b7280;margin-top:.2rem; }
  .btn { display:inline-block;padding:.5rem 1.5rem;border-radius:6px;font-size:.9rem;font-weight:600;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-primary:hover { background:#4338ca; }
  .alert-success { background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#15803d; }
  .alert-error   { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#b91c1c; }
  .ro-field { background:#f9fafb;padding:.45rem .75rem;border:1px solid #e5e7eb;border-radius:6px;font-size:.875rem;color:#6b7280;font-family:monospace; }
</style>

<?php if ($success !== ''): ?>
  <div class="alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="settings-form">
<form method="POST" action="<?= $b ?>/admin/settings">
  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

  <!-- ─── Application ───────────────────────────────────────── -->
  <div class="card">
    <div class="card-title">Application</div>

    <div class="form-group">
      <label for="s-app-name">Site Name</label>
      <input id="s-app-name" type="text" name="app_name" maxlength="100" required
             value="<?= \Luster\Core\View::e(cfgVal($cfg, 'app_name', 'Luster')) ?>">
    </div>

    <div class="form-group">
      <label for="s-app-url">Base URL <em style="font-weight:400;font-size:.75rem">(no trailing slash)</em></label>
      <input id="s-app-url" type="url" name="app_url" maxlength="500" required
             value="<?= \Luster\Core\View::e(cfgVal($cfg, 'app_url')) ?>">
      <p class="form-hint">Example: <code>http://localhost/luster/public</code></p>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="s-timezone">Timezone</label>
        <input id="s-timezone" type="text" name="timezone" maxlength="60"
               value="<?= \Luster\Core\View::e(cfgVal($cfg, 'timezone', 'UTC')) ?>"
               list="tz-list" autocomplete="off">
        <datalist id="tz-list">
          <?php foreach (['UTC','America/New_York','America/Chicago','America/Denver','America/Los_Angeles','Europe/London','Europe/Paris','Europe/Berlin','Asia/Tokyo','Australia/Sydney'] as $tz): ?>
            <option value="<?= \Luster\Core\View::e($tz) ?>">
          <?php endforeach; ?>
        </datalist>
        <p class="form-hint">PHP timezone identifier. <a href="https://www.php.net/timezones" target="_blank" rel="noopener" style="color:#4f46e5">Full list &rarr;</a></p>
      </div>

      <div class="form-group">
        <label for="s-per-page">Members per page</label>
        <input id="s-per-page" type="number" name="per_page" min="5" max="200"
               value="<?= (int) ($cfg['per_page'] ?? 25) ?>">
      </div>
    </div>
  </div>

  <!-- ─── Email ─────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-title">Email (SMTP)</div>
    <p style="font-size:.8rem;color:#6b7280;margin-bottom:1rem">
      Leave SMTP Host blank to disable outgoing email entirely.
    </p>

    <div class="form-row">
      <div class="form-group">
        <label for="s-smtp-host">SMTP Host</label>
        <input id="s-smtp-host" type="text" name="smtp_host" maxlength="255"
               value="<?= \Luster\Core\View::e(cfgVal($cfg, 'smtp_host')) ?>"
               placeholder="smtp.example.com">
      </div>
      <div class="form-group">
        <label for="s-smtp-port">SMTP Port</label>
        <input id="s-smtp-port" type="number" name="smtp_port" min="1" max="65535"
               value="<?= (int) ($cfg['smtp_port'] ?? 587) ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="s-smtp-user">Username</label>
        <input id="s-smtp-user" type="text" name="smtp_user" maxlength="255" autocomplete="off"
               value="<?= \Luster\Core\View::e(cfgVal($cfg, 'smtp_user')) ?>">
      </div>
      <div class="form-group">
        <label for="s-smtp-pass">Password</label>
        <input id="s-smtp-pass" type="password" name="smtp_pass" maxlength="500" autocomplete="new-password"
               placeholder="Leave blank to keep current">
        <p class="form-hint">Only fill in to change the stored password.</p>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="s-smtp-from">From Address</label>
        <input id="s-smtp-from" type="email" name="smtp_from" maxlength="255"
               value="<?= \Luster\Core\View::e(cfgVal($cfg, 'smtp_from')) ?>">
      </div>
      <div class="form-group">
        <label for="s-smtp-from-name">From Name</label>
        <input id="s-smtp-from-name" type="text" name="smtp_from_name" maxlength="255"
               value="<?= \Luster\Core\View::e(cfgVal($cfg, 'smtp_from_name')) ?>">
      </div>
    </div>

    <div class="form-group">
      <label for="s-smtp-secure">Encryption</label>
      <select id="s-smtp-secure" name="smtp_secure" style="max-width:180px">
        <?php foreach (['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', '' => 'None'] as $val => $lbl): ?>
          <option value="<?= $val ?>" <?= cfgVal($cfg, 'smtp_secure', 'tls') === $val ? 'selected' : '' ?>>
            <?= $lbl ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- ─── Read-only: DB + debug ─────────────────────────────── -->
  <div class="card">
    <div class="card-title">Database &amp; Debug <em style="font-weight:400;font-size:.8rem">(edit config/app.php directly to change)</em></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem 1rem;font-size:.8rem;">
      <div><label style="font-size:.75rem;color:#6b7280">Host</label>
           <div class="ro-field"><?= \Luster\Core\View::e(cfgVal($cfg, 'db_host', 'localhost')) ?></div></div>
      <div><label style="font-size:.75rem;color:#6b7280">Database</label>
           <div class="ro-field"><?= \Luster\Core\View::e(cfgVal($cfg, 'db_name')) ?></div></div>
      <div><label style="font-size:.75rem;color:#6b7280">User</label>
           <div class="ro-field"><?= \Luster\Core\View::e(cfgVal($cfg, 'db_user')) ?></div></div>
      <div><label style="font-size:.75rem;color:#6b7280">Debug mode</label>
           <div class="ro-field"><?= ($cfg['app_debug'] ?? false) ? 'enabled' : 'disabled' ?></div></div>
    </div>
  </div>

  <button type="submit" class="btn btn-primary">Save Settings</button>
</form>
</div>
