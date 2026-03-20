<?php
/** @var array  $cfg          Current config values */
/** @var string $success */
/** @var string $error */
/** @var array  $field_errors */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var \Luster\Models\AdminUser|null $admin_user */
/** @var string $php_version */
/** @var string $db_version */
/** @var int    $error_log_count */
$b  = rtrim(\Luster\Core\View::e($base_url), '/');
$fe = $field_errors ?? [];

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

<div class="settings-layout">

<nav class="settings-nav">
  <button class="settings-nav-item active" data-tab="profile">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
    Admin Details
  </button>
  <button class="settings-nav-item" data-tab="general">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07M8.46 8.46a5 5 0 0 0 0 7.07"/></svg>
    General
  </button>
  <button class="settings-nav-item" data-tab="embed">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
    Embed &amp; Paths
  </button>
  <button class="settings-nav-item" data-tab="collective-templates">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
    Collective Templates
  </button>
  <button class="settings-nav-item" data-tab="blacklist">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/></svg>
    Blacklist
  </button>
  <button class="settings-nav-item" data-tab="sysinfo">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
    System Info
  </button>
</nav>

<div class="settings-content">

<!-- ── Admin Details ────────────────────────────────────────── -->
  <div id="tab-profile" class="neb-tab-panel active">
    <form method="POST" action="<?= $b ?>/admin/settings/profile" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <div class="settings-card">

        <div class="form-group<?= !empty($fe['username']) ? ' has-error' : '' ?>">
          <label class="form-label" for="p-username">Username</label>
          <input id="p-username" type="text" name="admin_username" class="form-input"
                 maxlength="50" required
                 value="<?= \Luster\Core\View::e($admin_user->username ?? '') ?>">
          <?php if (!empty($fe['username'])): ?><p class="field-error"><?= \Luster\Core\View::e($fe['username'][0]) ?></p><?php endif; ?>
        </div>

        <div class="form-group<?= !empty($fe['email']) ? ' has-error' : '' ?>">
          <label class="form-label" for="p-email">Email Address</label>
          <input id="p-email" type="email" name="admin_email" class="form-input"
                 maxlength="255" required
                 value="<?= \Luster\Core\View::e($admin_user->email ?? '') ?>">
          <?php if (!empty($fe['email'])): ?><p class="field-error"><?= \Luster\Core\View::e($fe['email'][0]) ?></p><?php endif; ?>
        </div>

        <div class="section-divider"></div>

        <div class="form-row">
          <div class="form-group<?= !empty($fe['admin_new_password']) ? ' has-error' : '' ?>">
            <label class="form-label" for="p-new-pass">New Password</label>
            <div class="input-icon-wrap">
              <input id="p-new-pass" type="password" name="admin_new_password" class="form-input"
                     maxlength="255" autocomplete="new-password"
                     placeholder="Leave blank to keep current">
              <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
                <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <p class="form-hint">Minimum 8 characters.</p>
            <?php if (!empty($fe['admin_new_password'])): ?><p class="field-error"><?= \Luster\Core\View::e($fe['admin_new_password'][0]) ?></p><?php endif; ?>
          </div>
          <div class="form-group<?= !empty($fe['admin_confirm_password']) ? ' has-error' : '' ?>">
            <label class="form-label" for="p-confirm-pass">Confirm New Password</label>
            <div class="input-icon-wrap">
              <input id="p-confirm-pass" type="password" name="admin_confirm_password" class="form-input"
                     maxlength="255" autocomplete="new-password"
                     placeholder="Repeat new password">
              <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
                <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
            <?php if (!empty($fe['admin_confirm_password'])): ?><p class="field-error"><?= \Luster\Core\View::e($fe['admin_confirm_password'][0]) ?></p><?php endif; ?>
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
            <td class="text-strong">
              <?= \Luster\Core\View::e($user->username) ?>
              <?php if ($user->id === ($current_admin_id ?? 0)): ?>
                <span class="neb-badge badge-info" style="margin-left:.4rem">You</span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= \Luster\Core\View::e($user->email) ?></td>
            <td class="text-dim">
              <?= $user->lastLogin !== null ? \Luster\Core\View::e(substr($user->lastLogin, 0, 16)) : '—' ?>
            </td>
            <td class="text-dim nowrap">
              <?= \Luster\Core\View::e(substr($user->createdAt, 0, 10)) ?>
            </td>
            <td class="act-col">
              <a href="<?= $b ?>/admin/admin-users/<?= $user->id ?>/edit"
                 class="btn btn-primary btn-sm">Edit</a>
              <?php if ($user->id !== ($current_admin_id ?? 0)): ?>
                <form method="POST"
                      action="<?= $b ?>/admin/admin-users/<?= $user->id ?>/delete"
                      data-confirm="Delete admin account &#039;<?= \Luster\Core\View::e($user->username) ?>&#039;? This cannot be undone.">
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

  <form method="POST" action="<?= $b ?>/admin/settings" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <!-- ── General ──────────────────────────────────────────────── -->
    <div id="tab-general" class="neb-tab-panel">
      <div class="settings-card">

        <div class="form-group<?= !empty($fe['site_name']) ? ' has-error' : '' ?>">
          <label class="form-label" for="s-site-name">Site Name</label>
          <input id="s-site-name" type="text" name="site_name" class="form-input"
                 maxlength="100" required
                 value="<?= \Luster\Core\View::e(cfgVal($cfg, 'site_name', 'Luster')) ?>">
          <?php if (!empty($fe['site_name'])): ?><p class="field-error"><?= \Luster\Core\View::e($fe['site_name'][0]) ?></p><?php endif; ?>
        </div>

        <div class="form-group<?= !empty($fe['site_url']) ? ' has-error' : '' ?>">
          <label class="form-label" for="s-site-url">
            Base URL <em style="font-weight:400;font-size:.75rem">(no trailing slash)</em>
          </label>
          <input id="s-site-url" type="url" name="site_url" class="form-input"
                 maxlength="500" required
                 value="<?= \Luster\Core\View::e(cfgVal($cfg, 'site_url')) ?>">
          <p class="form-hint">Example: <code class="neb-code">http://localhost/luster/public</code></p>
          <?php if (!empty($fe['site_url'])): ?><p class="field-error"><?= \Luster\Core\View::e($fe['site_url'][0]) ?></p><?php endif; ?>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="s-owner-name">Owner Name</label>
            <input id="s-owner-name" type="text" name="owner_name" class="form-input"
                   maxlength="100"
                   value="<?= \Luster\Core\View::e(cfgVal($cfg, 'owner_name')) ?>">
            <p class="form-hint">Your display name used in outgoing email templates (e.g. <code class="neb-code">Jiji</code>).</p>
          </div>
          <div class="form-group">
            <label class="form-label" for="s-owner-email">Owner Email</label>
            <input id="s-owner-email" type="email" name="owner_email" class="form-input"
                   maxlength="255"
                   value="<?= \Luster\Core\View::e(cfgVal($cfg, 'owner_email')) ?>">
            <p class="form-hint">Contact email shown in outgoing emails. Can differ from your admin login email.</p>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="s-timezone">Timezone</label>
          <?php
            $currentTz = cfgVal($cfg, 'timezone', 'UTC');
            $commonTz  = [
                'UTC'        => ['UTC'],
                'America'    => [
                    'America/New_York', 'America/Chicago', 'America/Denver',
                    'America/Los_Angeles', 'America/Anchorage', 'America/Halifax',
                    'America/Toronto', 'America/Vancouver', 'America/Phoenix',
                    'America/Mexico_City', 'America/Bogota', 'America/Lima',
                    'America/Santiago', 'America/Sao_Paulo', 'America/Buenos_Aires',
                ],
                'Europe'     => [
                    'Europe/London', 'Europe/Dublin', 'Europe/Lisbon',
                    'Europe/Paris', 'Europe/Berlin', 'Europe/Amsterdam',
                    'Europe/Rome', 'Europe/Madrid', 'Europe/Stockholm',
                    'Europe/Warsaw', 'Europe/Athens', 'Europe/Helsinki',
                    'Europe/Istanbul', 'Europe/Moscow',
                ],
                'Asia'       => [
                    'Asia/Dubai', 'Asia/Karachi', 'Asia/Kolkata',
                    'Asia/Dhaka', 'Asia/Bangkok', 'Asia/Singapore',
                    'Asia/Hong_Kong', 'Asia/Shanghai', 'Asia/Tokyo',
                    'Asia/Seoul',
                ],
                'Pacific'    => [
                    'Pacific/Auckland', 'Pacific/Fiji', 'Pacific/Honolulu',
                ],
                'Australia'  => [
                    'Australia/Perth', 'Australia/Adelaide',
                    'Australia/Sydney', 'Australia/Brisbane',
                ],
                'Africa'     => [
                    'Africa/Cairo', 'Africa/Johannesburg', 'Africa/Lagos',
                    'Africa/Nairobi',
                ],
            ];
            // If the current timezone isn't in our list, add it under "Other"
            $allListed = array_merge(...array_values($commonTz));
            if (!in_array($currentTz, $allListed, true)) {
                $commonTz['Other'] = [$currentTz];
            }
          ?>
          <div class="select-wrap">
          <select id="s-timezone" name="timezone" class="form-select">
            <?php foreach ($commonTz as $group => $tzList): ?>
              <optgroup label="<?= \Luster\Core\View::e($group) ?>">
                <?php foreach ($tzList as $tz): ?>
                  <option value="<?= \Luster\Core\View::e($tz) ?>"<?= $tz === $currentTz ? ' selected' : '' ?>>
                    <?= \Luster\Core\View::e(str_replace('_', ' ', $tz)) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
          </div>
          <p class="form-hint">PHP timezone used for all date/time display in the admin panel.</p>
        </div>

      </div>
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div><!-- /#tab-general -->

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

  </form>

  <!-- ═══ TAB: COLLECTIVE TEMPLATES ═══ -->
  <div id="tab-collective-templates" class="neb-tab-panel">
  <form method="POST" action="<?= $b ?>/admin/settings/templates">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

    <div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
      <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem">About Collective Templates</div>
      <p class="form-hint" style="margin:0">
        These templates control how each item looks in the <strong>Owned Listings</strong>,
        <strong>Joined Listings</strong>, and <strong>Site Statistics</strong> embeds on your
        collective page. Write plain HTML and drop in the tokens shown below each field —
        Luster replaces them with real data automatically. Each field is pre-filled
        with the built-in default so you can edit from a working starting point.
      </p>
    </div>

    <?php $ct = $collective_templates ?? []; ?>

    <!-- Owned listing row -->
    <div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
      <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between">
        <span>Owned Listings — Row Template</span>
        <button type="button" class="btn btn-ghost btn-sm js-tpl-preview" data-target="owned_template" data-kind="listing">Preview</button>
      </div>
      <p class="form-hint" style="font-family:'DM Mono',monospace;margin-bottom:.5rem;line-height:2">
        {listing_name} &bull; {listing_url} &bull; {listing_subject} &bull; {listing_status}
      </p>
      <div class="form-group" style="margin-bottom:0">
        <textarea name="owned_template" id="owned_template" class="form-textarea" rows="6"
                  style="font-family:'DM Mono',monospace;font-size:.8125rem"><?= \Luster\Core\View::e($ct['owned_template'] ?? '<li class="fl-fanlisting"><a class="fl-link" href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_name}</a> <span class="fl-muted">— {listing_subject}</span></li>') ?></textarea>
        <div class="tpl-preview-box" id="preview-owned_template" style="display:none"></div>
      </div>
    </div>

    <!-- Joined listing row -->
    <div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
      <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between">
        <span>Joined Listings — Row Template</span>
        <button type="button" class="btn btn-ghost btn-sm js-tpl-preview" data-target="joined_template" data-kind="joined">Preview</button>
      </div>
      <p class="form-hint" style="font-family:'DM Mono',monospace;margin-bottom:.5rem;line-height:2">
        {listing_name} &bull; {listing_url} &bull; {listing_category} &bull; {listing_image}
      </p>
      <div class="form-group" style="margin-bottom:0">
        <textarea name="joined_template" id="joined_template" class="form-textarea" rows="6"
                  style="font-family:'DM Mono',monospace;font-size:.8125rem"><?= \Luster\Core\View::e($ct['joined_template'] ?? '<li class="fl-fanlisting"><a class="fl-link" href="{listing_url}" target="_blank" rel="noopener noreferrer">{listing_name}</a> <span class="fl-muted">— {listing_category}</span></li>') ?></textarea>
        <div class="tpl-preview-box" id="preview-joined_template" style="display:none"></div>
      </div>
    </div>

    <!-- Collective stats template -->
    <div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
      <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem;display:flex;align-items:center;justify-content:space-between">
        <span>Site Statistics Template</span>
        <button type="button" class="btn btn-ghost btn-sm js-tpl-preview" data-target="collective_stats_template" data-kind="stats">Preview</button>
      </div>
      <p class="form-hint" style="font-family:'DM Mono',monospace;margin-bottom:.5rem;line-height:2">
        {owned_active} &bull; {owned_upcoming} &bull; {owned_trouble} &bull; {joined_active}
        &bull; {affiliates} &bull; {members_total} &bull; {members_approved} &bull; {members_pending}
        &bull; {owned_newest_name} &bull; {owned_newest_url} &bull; {owned_random_name} &bull; {owned_random_url}
      </p>
      <div class="form-group" style="margin-bottom:0">
        <textarea name="collective_stats_template" id="collective_stats_template" class="form-textarea" rows="8"
                  style="font-family:'DM Mono',monospace;font-size:.8125rem"><?= \Luster\Core\View::e($ct['collective_stats_template'] ?? '<div class="fl-collective-stats">
  <dl class="fl-stats-grid">
    <dt>Owned listings</dt>
    <dd>{owned_active} open, {owned_upcoming} upcoming</dd>
    <dt>Joined listings</dt>
    <dd>{joined_active}</dd>
    <dt>Total members</dt>
    <dd>{members_approved} approved, {members_pending} pending</dd>
  </dl>
  <p>Newest: <a href="{owned_newest_url}">{owned_newest_name}</a></p>
  <p>Featured: <a href="{owned_random_url}">{owned_random_name}</a></p>
</div>') ?></textarea>
        <div class="tpl-preview-box" id="preview-collective_stats_template" style="display:none"></div>
      </div>
    </div>

    <div style="display:flex;gap:.75rem;align-items:center">
      <button type="submit" class="btn btn-primary">Save Templates</button>
    </div>
  </form>

<!-- Sample data for collective template preview (read by admin.js, not executed) -->
<script type="application/json" id="luster-tpl-samples">
{
  "listing": {
    "{listing_name}":    "My Fanlisting",
    "{listing_url}":     "#",
    "{listing_subject}": "Sample Subject",
    "{listing_status}":  "open",
    "{listing_image}":   ""
  },
  "joined": {
    "{listing_name}":     "A Joined Fanlisting",
    "{listing_url}":      "#",
    "{listing_subject}":  "Sample Subject",
    "{listing_category}": "Anime \u2192 General",
    "{listing_image}":    ""
  },
  "stats": {
    "{owned_active}":      "12",
    "{owned_upcoming}":    "2",
    "{owned_trouble}":     "0",
    "{joined_active}":     "5",
    "{affiliates}":        "8",
    "{members_total}":     "145",
    "{members_approved}":  "143",
    "{members_pending}":   "2",
    "{owned_newest_name}": "My Newest Listing",
    "{owned_newest_url}":  "#",
    "{owned_random_name}": "Featured Listing",
    "{owned_random_url}":  "#"
  }
}
</script>
  </div><!-- /#tab-collective-templates -->

  <!-- ═══ TAB: BLACKLIST ═══ -->
  <div id="tab-blacklist" class="neb-tab-panel">

    <!-- Add form -->
    <div class="neb-card" style="margin-bottom:1.25rem">
      <div class="neb-card-header">Add Pattern</div>
      <form method="POST" action="<?= $b ?>/admin/blacklist/add" style="padding:1.25rem">
        <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

        <div class="form-row">
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" for="bl-pattern">Pattern <span>*</span></label>
            <input id="bl-pattern" type="text" name="pattern" class="form-input"
                   maxlength="255" required
                   placeholder="spam@example.com  or  @spam.com">
            <p class="form-hint">
              Full email for an exact block, or start with <code class="neb-code">@</code> to block an entire domain
              (e.g. <code class="neb-code">@mailnull.com</code>).
            </p>
          </div>

          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" for="bl-note">Note <em style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</em></label>
            <input id="bl-note" type="text" name="note" class="form-input"
                   maxlength="500"
                   placeholder="Why this address was blocked">
          </div>
        </div>

        <div class="btn-row">
          <button type="submit" class="btn btn-primary">Add to Blacklist</button>
        </div>
      </form>
    </div>

    <!-- Entries table -->
    <div class="neb-card">
      <div class="neb-card-header" style="display:flex;align-items:center;justify-content:space-between">
        <span>Blocked Patterns</span>
        <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.8rem;color:var(--text-muted)">
          <?= count($blacklist_entries ?? []) ?> entr<?= count($blacklist_entries ?? []) === 1 ? 'y' : 'ies' ?>
        </span>
      </div>

      <?php if (empty($blacklist_entries)): ?>
        <div class="neb-empty">
          <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          No blocked patterns yet — add email addresses or domains above to prevent them from joining.
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Pattern</th>
              <th>Type</th>
              <th>Note</th>
              <th>Added</th>
              <th class="act-col"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($blacklist_entries as $entry): ?>
            <tr>
              <td>
                <code class="neb-code" style="font-size:.85rem"><?= \Luster\Core\View::e($entry['pattern']) ?></code>
              </td>
              <td>
                <?php if (str_starts_with($entry['pattern'], '@')): ?>
                  <span class="neb-badge badge-info">Domain</span>
                <?php else: ?>
                  <span class="neb-badge badge-inactive">Exact</span>
                <?php endif; ?>
              </td>
              <td class="text-muted" style="font-size:.85rem">
                <?= $entry['note'] !== '' ? \Luster\Core\View::e($entry['note']) : '<span class="text-dim">—</span>' ?>
              </td>
              <td class="text-dim nowrap" style="font-family:'DM Mono',monospace;font-size:.8rem">
                <?= \Luster\Core\View::e(substr($entry['created_at'], 0, 10)) ?>
              </td>
              <td class="act-col">
                <form method="POST"
                      action="<?= $b ?>/admin/blacklist/<?= (int) $entry['id'] ?>/delete"
                      data-confirm="Remove <?= \Luster\Core\View::e($entry['pattern']) ?> from the blacklist?">
                  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </div><!-- /#tab-blacklist -->

<!-- ── System Info ──────────────────────────────────────────── -->
  <div id="tab-sysinfo" class="neb-tab-panel">

    <div class="settings-card" style="margin-bottom:1.25rem">
      <div class="form-section-label">Script</div>
      <div class="sysinfo-grid">
        <span class="sysinfo-label">Name</span>
        <span class="sysinfo-value">Luster</span>
        <span class="sysinfo-label">Version</span>
        <span class="sysinfo-value">beta</span>
      </div>
    </div>

    <div class="settings-card" style="margin-bottom:1.25rem">
      <div class="form-section-label">Server</div>
      <div class="sysinfo-grid">
        <span class="sysinfo-label">PHP Version</span>
        <span class="sysinfo-value"><?= \Luster\Core\View::e($php_version) ?></span>
        <span class="sysinfo-label">Database</span>
        <span class="sysinfo-value"><?= \Luster\Core\View::e($db_version) ?></span>
      </div>
    </div>

    <div class="settings-card" style="margin-bottom:1.25rem">
      <div class="form-section-label">Database Connection</div>
      <p class="form-hint" style="margin-bottom:.75rem">Read from <code class="neb-code">config/app.php</code> — edit that file to change connection settings.</p>
      <div class="sysinfo-grid">
        <span class="sysinfo-label">Host</span>
        <span class="sysinfo-value"><?= \Luster\Core\View::e(cfgVal($cfg, 'db_host', 'localhost')) ?></span>
        <span class="sysinfo-label">Database</span>
        <span class="sysinfo-value"><?= \Luster\Core\View::e(cfgVal($cfg, 'db_name')) ?></span>
        <span class="sysinfo-label">User</span>
        <span class="sysinfo-value"><?= \Luster\Core\View::e(cfgVal($cfg, 'db_user')) ?></span>
        <span class="sysinfo-label">Debug Mode</span>
        <span class="sysinfo-value">
          <?php if ($cfg['app_debug'] ?? false): ?>
            <span class="neb-badge badge-error">enabled</span>
          <?php else: ?>
            <span class="neb-badge badge-inactive">disabled</span>
          <?php endif; ?>
        </span>
      </div>
    </div>

    <div class="settings-card">
      <div class="form-section-label">Diagnostics</div>
      <div class="sysinfo-grid">
        <span class="sysinfo-label">Error Log</span>
        <span class="sysinfo-value">
          <?php if ($error_log_count > 0): ?>
            <span class="neb-badge badge-error"><?= (int) $error_log_count ?> entries</span>
            <a href="<?= $b ?>/admin/errorlog" class="neb-link" style="margin-left:.5rem;font-size:.8125rem">View &rarr;</a>
          <?php else: ?>
            <span class="neb-badge badge-inactive">0 entries</span>
          <?php endif; ?>
        </span>
      </div>
    </div>

  </div><!-- /#tab-sysinfo -->

</div><!-- /.settings-content -->
</div><!-- /.settings-layout -->
