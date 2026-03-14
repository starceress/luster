<?php
/** @var array  $cfg     Current config values */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var \Luster\Models\AdminUser|null $admin_user */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

function cfgVal(array $cfg, string $key, $default = ''): string {
    return (string) ($cfg[$key] ?? $default);
}
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<!-- Tab navigation -->
<div class="neb-tabs">
  <button class="neb-tab-btn active" data-tab="profile">Admin Details</button>
  <button class="neb-tab-btn"        data-tab="general">General</button>
  <button class="neb-tab-btn"        data-tab="email">Email</button>
  <button class="neb-tab-btn"        data-tab="embed">Embed &amp; Paths</button>
  <button class="neb-tab-btn"        data-tab="database">Database &amp; Debug</button>
</div>

<script>
document.querySelectorAll('.neb-tab-btn').forEach(function (btn) {
  btn.addEventListener('click', function () {
    document.querySelectorAll('.neb-tab-btn, .neb-tab-panel').forEach(function (el) {
      el.classList.remove('active');
    });
    btn.classList.add('active');
    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
  });
});
</script>

<!-- ── Admin Details ────────────────────────────────────────── -->
  <div id="tab-profile" class="neb-tab-panel active">
    <form method="POST" action="<?= $b ?>/admin/settings/profile">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <div class="settings-card">

        <div class="form-group">
          <label class="form-label" for="p-username">Username</label>
          <input id="p-username" type="text" name="admin_username" class="form-input"
                 maxlength="50" required
                 value="<?= \Luster\Core\View::e($admin_user->username ?? '') ?>">
        </div>

        <div class="form-group">
          <label class="form-label" for="p-email">Email Address</label>
          <input id="p-email" type="email" name="admin_email" class="form-input"
                 maxlength="255" required
                 value="<?= \Luster\Core\View::e($admin_user->email ?? '') ?>">
        </div>

        <div class="section-divider"></div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="p-new-pass">New Password</label>
            <input id="p-new-pass" type="password" name="admin_new_password" class="form-input"
                   maxlength="255" autocomplete="new-password"
                   placeholder="Leave blank to keep current">
            <p class="form-hint">Minimum 8 characters.</p>
          </div>
          <div class="form-group">
            <label class="form-label" for="p-confirm-pass">Confirm New Password</label>
            <input id="p-confirm-pass" type="password" name="admin_confirm_password" class="form-input"
                   maxlength="255" autocomplete="new-password"
                   placeholder="Repeat new password">
          </div>
        </div>

      </div>
      <button type="submit" class="btn btn-primary">Save Profile</button>
    </form>

    <div class="section-divider" style="margin-top:2rem">Admin Accounts</div>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
      <p class="text-muted" style="font-size:.875rem;margin:0">
        <?= count($users ?? []) ?> admin account<?= count($users ?? []) !== 1 ? 's' : '' ?>
      </p>
      <a href="<?= $b ?>/admin/admin-users/add" class="btn btn-primary btn-sm">+ Add Account</a>
    </div>

    <div class="neb-card">
      <table class="data-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Email</th>
            <th>Last Login</th>
            <th>Created</th>
            <th class="act-col"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (($users ?? []) as $user): ?>
          <tr>
            <td style="font-weight:600;color:var(--text)">
              <?= \Luster\Core\View::e($user->username) ?>
              <?php if ($user->id === ($current_admin_id ?? 0)): ?>
                <span class="badge badge-info" style="font-size:.65rem;margin-left:.4rem">You</span>
              <?php endif; ?>
            </td>
            <td style="font-size:.875rem;color:var(--text-muted)"><?= \Luster\Core\View::e($user->email) ?></td>
            <td style="font-size:.8rem;color:var(--text-dim)">
              <?= $user->lastLogin !== null ? \Luster\Core\View::e(substr($user->lastLogin, 0, 16)) : '<span style="color:var(--text-dim)">Never</span>' ?>
            </td>
            <td style="font-size:.8rem;color:var(--text-dim);white-space:nowrap">
              <?= \Luster\Core\View::e(substr($user->createdAt, 0, 10)) ?>
            </td>
            <td class="act-col">
              <a href="<?= $b ?>/admin/admin-users/<?= $user->id ?>/edit"
                 class="btn btn-secondary btn-sm">Edit</a>
              <?php if ($user->id !== ($current_admin_id ?? 0)): ?>
                <form method="POST"
                      action="<?= $b ?>/admin/admin-users/<?= $user->id ?>/delete"
                      onsubmit="return confirm('Delete admin account \'<?= \Luster\Core\View::e($user->username) ?>\'? This cannot be undone.')">
                  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div><!-- /#tab-profile -->

  <form method="POST" action="<?= $b ?>/admin/settings">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <!-- ── General ──────────────────────────────────────────────── -->
    <div id="tab-general" class="neb-tab-panel">
      <div class="settings-card">

        <div class="form-group">
          <label class="form-label" for="s-app-name">Site Name</label>
          <input id="s-app-name" type="text" name="app_name" class="form-input"
                 maxlength="100" required
                 value="<?= \Luster\Core\View::e(cfgVal($cfg, 'app_name', 'Luster')) ?>">
        </div>

        <div class="form-group">
          <label class="form-label" for="s-app-url">
            Base URL <em style="font-weight:400;font-size:.75rem">(no trailing slash)</em>
          </label>
          <input id="s-app-url" type="url" name="app_url" class="form-input"
                 maxlength="500" required
                 value="<?= \Luster\Core\View::e(cfgVal($cfg, 'app_url')) ?>">
          <p class="form-hint">Example: <code class="neb-code">http://localhost/luster/public</code></p>
        </div>

        <div class="form-group">
          <label class="form-label" for="s-timezone">Timezone</label>
          <input id="s-timezone" type="text" name="timezone" class="form-input"
                 maxlength="60" list="tz-list" autocomplete="off"
                 value="<?= \Luster\Core\View::e(cfgVal($cfg, 'timezone', 'UTC')) ?>">
          <datalist id="tz-list">
            <?php foreach (['UTC','America/New_York','America/Chicago','America/Denver','America/Los_Angeles','Europe/London','Europe/Paris','Europe/Berlin','Asia/Tokyo','Australia/Sydney'] as $tz): ?>
              <option value="<?= \Luster\Core\View::e($tz) ?>">
            <?php endforeach; ?>
          </datalist>
          <p class="form-hint">
            PHP timezone identifier.
            <a href="https://www.php.net/timezones" target="_blank" rel="noopener" class="neb-link">Full list &rarr;</a>
          </p>
        </div>

      </div>
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div><!-- /#tab-general -->

    <!-- ── Email ────────────────────────────────────────────────── -->
    <div id="tab-email" class="neb-tab-panel">
      <div class="settings-card">

        <p class="text-muted" style="font-size:.8rem;margin-bottom:1rem">
          Leave SMTP Host blank to disable outgoing email entirely.
        </p>

        <?php if (cfgVal($cfg, 'smtp_host') === ''): ?>
        <div class="alert alert-warning" style="margin-bottom:1rem">
          <strong>Email is not configured.</strong>
          Fan password reset emails will not be delivered until an SMTP host is set.
          Fans who request a password reset will see a "check your email" page but receive nothing.
        </div>
        <?php endif; ?>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="s-smtp-host">SMTP Host</label>
            <input id="s-smtp-host" type="text" name="smtp_host" class="form-input"
                   maxlength="255" placeholder="smtp.example.com"
                   value="<?= \Luster\Core\View::e(cfgVal($cfg, 'smtp_host')) ?>">
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
                   value="<?= \Luster\Core\View::e(cfgVal($cfg, 'smtp_user')) ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="s-smtp-pass">Password</label>
            <input id="s-smtp-pass" type="password" name="smtp_pass" class="form-input"
                   maxlength="500" autocomplete="new-password"
                   placeholder="Leave blank to keep current">
            <p class="form-hint">Only fill in to change the stored password.</p>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="s-smtp-from">From Address</label>
            <input id="s-smtp-from" type="email" name="smtp_from" class="form-input"
                   maxlength="255"
                   value="<?= \Luster\Core\View::e(cfgVal($cfg, 'smtp_from')) ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="s-smtp-from-name">From Name</label>
            <input id="s-smtp-from-name" type="text" name="smtp_from_name" class="form-input"
                   maxlength="255"
                   value="<?= \Luster\Core\View::e(cfgVal($cfg, 'smtp_from_name')) ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="s-smtp-from-fl">Listing From Address</label>
            <input id="s-smtp-from-fl" type="email" name="smtp_from_listing" class="form-input"
                   maxlength="255"
                   value="<?= \Luster\Core\View::e(cfgVal($cfg, 'smtp_from_listing')) ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="s-smtp-from-fl-name">Listing From Name</label>
            <input id="s-smtp-from-fl-name" type="text" name="smtp_from_listing_name" class="form-input"
                   maxlength="255"
                   value="<?= \Luster\Core\View::e(cfgVal($cfg, 'smtp_from_listing_name')) ?>">
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="s-smtp-secure">Encryption</label>
          <div class="select-wrap">
            <select id="s-smtp-secure" name="smtp_secure" class="form-select">
              <?php foreach (['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', '' => 'None'] as $val => $lbl): ?>
                <option value="<?= \Luster\Core\View::e($val) ?>"
                  <?= cfgVal($cfg, 'smtp_secure', 'tls') === $val ? 'selected' : '' ?>>
                  <?= \Luster\Core\View::e($lbl) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

      </div>
      <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">Save Settings</button>
        <?php if (cfgVal($cfg, 'smtp_host') !== ''): ?>
        <form method="POST" action="<?= $b ?>/admin/settings/test-email" style="display:contents">
          <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
          <button type="submit" class="btn btn-ghost btn-sm">Send test email</button>
        </form>
        <span class="form-hint" style="margin:0">
          &rarr; <code class="neb-code"><?= \Luster\Core\View::e($admin_user->email ?? '') ?></code>
        </span>
        <?php endif; ?>
      </div>
    </div><!-- /#tab-email -->

    <!-- ── Embed & Paths ────────────────────────────────────────── -->
    <div id="tab-embed" class="neb-tab-panel">
      <div class="settings-card">

        <div class="form-group">
          <label class="form-label" for="s-install-path">Installation Path (absolute)</label>
          <input id="s-install-path" type="text" name="install_path" class="form-input"
                 maxlength="500" placeholder="/home/user/nebuluna.com/luster/"
                 value="<?= \Luster\Core\View::e(cfgVal($cfg, 'install_path')) ?>">
          <p class="form-hint">
            Absolute server path to the Luster root directory, with trailing slash.
            Used to generate embed include lines below.
          </p>
        </div>

        <div class="form-group">
          <label class="form-label" for="s-cookie-domain">Session Cookie Domain</label>
          <input id="s-cookie-domain" type="text" name="session_cookie_domain" class="form-input"
                 maxlength="255" placeholder=".yourdomain.com"
                 value="<?= \Luster\Core\View::e(cfgVal($cfg, 'session_cookie_domain')) ?>">
          <p class="form-hint">
            Include the leading dot to cover all subdomains
            (e.g. <code class="neb-code">.nebuluna.com</code>). Leave blank for single-domain installs.
            Required when fans submit join forms from <code class="neb-code">fan.yourdomain.com</code>
            to <code class="neb-code">yourdomain.com</code>.
          </p>
        </div>

        <div class="form-group">
          <label class="form-label" for="s-embed-url">Embed Base URL</label>
          <input id="s-embed-url" type="url" name="embed_base_url" class="form-input"
                 maxlength="500" placeholder="https://nebuluna.com/luster/public"
                 value="<?= \Luster\Core\View::e(cfgVal($cfg, 'embed_base_url')) ?>">
          <p class="form-hint">
            The public URL where Luster's form submissions go (no trailing slash).
            Leave blank to use the Base URL from the General tab.
          </p>
        </div>

        <?php
        $installPath = cfgVal($cfg, 'install_path');
        if ($installPath !== ''):
            $installPath = rtrim($installPath, '/');
        ?>
        <div class="section-divider">Per-Listing Embed Lines</div>

        <p class="form-hint" style="margin-bottom:0.75rem">
          Set <code class="neb-code">$luster_fanlisting_id</code> to the listing ID before each include.
          Optionally set <code class="neb-code">$luster_return = true</code> to capture the output as a string.
        </p>

        <?php
        $perListingEmbeds = [
            'join'        => 'Join form',
            'members'     => 'Members list',
            'update'      => 'Update membership',
            'remove'      => 'Remove membership',
            'reset'       => 'Password reset',
            'stats'       => 'Listing stats',
            'affiliates'  => 'Affiliates (per-listing)',
        ];
        foreach ($perListingEmbeds as $file => $label): ?>
        <div class="form-group">
          <label class="form-label"><?= \Luster\Core\View::e($label) ?></label>
          <div class="ro-field" style="font-family:'DM Mono',monospace;font-size:0.8rem;user-select:all">
            $luster_fanlisting_id = 1; require '<?= \Luster\Core\View::e($installPath) ?>/public/embed/<?= \Luster\Core\View::e($file) ?>.php';
          </div>
        </div>
        <?php endforeach; ?>

        <div class="section-divider" style="margin-top:1.25rem">Site-Wide Embed Lines</div>

        <p class="form-hint" style="margin-bottom:0.75rem">
          These embeds aggregate data across your entire collective.
          No <code class="neb-code">$luster_fanlisting_id</code> is needed
          (except Affiliates, where <code class="neb-code">$luster_fanlisting_id = 0</code> gives site-wide affiliates).
        </p>

        <div class="form-group">
          <label class="form-label">Collective stats</label>
          <div class="ro-field" style="font-family:'DM Mono',monospace;font-size:0.8rem;user-select:all">
            require '<?= \Luster\Core\View::e($installPath) ?>/public/embed/collective-stats.php';
          </div>
          <p class="form-hint">
            Exposes <code class="neb-code">$luster_owned_active</code>, <code class="neb-code">$luster_members_approved</code>,
            <code class="neb-code">$luster_owned_newest</code> (array), <code class="neb-code">$luster_owned_random</code>,
            <code class="neb-code">$luster_categories</code>, growth rates, and more.
            Optionally set <code class="neb-code">$luster_cache_ttl = 300</code> (seconds) to cache the stats.
          </p>
        </div>

        <div class="form-group">
          <label class="form-label">Site-wide affiliates</label>
          <div class="ro-field" style="font-family:'DM Mono',monospace;font-size:0.8rem;user-select:all">
            $luster_fanlisting_id = 0; require '<?= \Luster\Core\View::e($installPath) ?>/public/embed/affiliates.php';
          </div>
        </div>
        <?php endif; ?>

      </div>
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div><!-- /#tab-embed -->

    <!-- ── Database & Debug (read-only) ─────────────────────────── -->
    <div id="tab-database" class="neb-tab-panel">
      <div class="settings-card">
        <p class="text-muted" style="font-size:.8rem;margin-bottom:1rem">
          These values are set in <code class="neb-code">config/app.php</code> and cannot be changed from the admin panel.
        </p>
        <div class="db-info-grid">
          <div>
            <label class="form-label" style="margin-bottom:.25rem">Host</label>
            <div class="ro-field"><?= \Luster\Core\View::e(cfgVal($cfg, 'db_host', 'localhost')) ?></div>
          </div>
          <div>
            <label class="form-label" style="margin-bottom:.25rem">Database</label>
            <div class="ro-field"><?= \Luster\Core\View::e(cfgVal($cfg, 'db_name')) ?></div>
          </div>
          <div>
            <label class="form-label" style="margin-bottom:.25rem">User</label>
            <div class="ro-field"><?= \Luster\Core\View::e(cfgVal($cfg, 'db_user')) ?></div>
          </div>
          <div>
            <label class="form-label" style="margin-bottom:.25rem">Debug Mode</label>
            <div class="ro-field"><?= ($cfg['app_debug'] ?? false) ? 'enabled' : 'disabled' ?></div>
          </div>
        </div>
      </div>
    </div><!-- /#tab-database -->

  </form>
