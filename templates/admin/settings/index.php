<?php
/** @var array  $cfg          Current config values */
/** @var string $success */
/** @var string $error */
/** @var array  $field_errors */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var \Luster\Models\AdminUser|null $admin_user */
/** @var \Luster\Models\AdminUser[]    $users */
/** @var int    $current_admin_id */
/** @var array  $blacklist_entries */
/** @var string $php_version */
/** @var string $db_version */
/** @var int    $error_log_count */

use Luster\Core\View;

$b  = rtrim(View::e($base_url), '/');
$fe = $field_errors ?? [];

function cfgVal(array $cfg, string $key, $default = ''): string {
    return (string) ($cfg[$key] ?? $default);
}
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="settings-layout">

  <!-- ── Sidebar nav ─────────────────────────────────────────── -->
  <nav class="neb-sidebar-nav">
    <div class="settings-nav-header"><div class="settings-nav-title">Settings</div></div>
    <div class="settings-nav-body">
      <a href="#panel-admin" class="neb-sidebar-nav-item">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Admin Details
      </a>
      <a href="#panel-general" class="neb-sidebar-nav-item">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07M8.46 8.46a5 5 0 0 0 0 7.07"/></svg>
        General
      </a>
      <a href="#panel-embed" class="neb-sidebar-nav-item">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
        Embed &amp; Paths
      </a>
      <a href="#panel-blacklist" class="neb-sidebar-nav-item">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/></svg>
        Blacklist
      </a>
      <a href="#panel-system" class="neb-sidebar-nav-item">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        System Details
      </a>
    </div>
  </nav>

  <div class="settings-content">

    <!-- ═══ PANEL: Admin Details ═══════════════════════════════ -->
    <div id="panel-admin" class="settings-panel">

      <!-- Account Details -->
      <div class="settings-card">
        <div class="pc-head">
          <div class="pc-head-icon pc-head-icon--pink">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </div>
          <div class="pc-head-meta">
            <div class="pc-head-title">Account Details</div>
            <div class="pc-head-sub">Update your login credentials and password.</div>
          </div>
        </div>

        <form method="POST" action="<?= $b ?>/admin/settings/profile" class="js-warn-unsaved">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

          <div class="pc-section">
            <div class="form-row">
              <div class="form-group<?= !empty($fe['username']) ? ' has-error' : '' ?>">
                <label class="form-label" for="p-username">Username <span class="req">*</span></label>
                <input id="p-username" type="text" name="admin_username" class="form-input"
                       maxlength="50" required
                       value="<?= View::e($admin_user->username ?? '') ?>">
                <?php if (!empty($fe['username'])): ?><p class="field-error"><?= View::e($fe['username'][0]) ?></p><?php endif; ?>
              </div>
              <div class="form-group<?= !empty($fe['email']) ? ' has-error' : '' ?>">
                <label class="form-label" for="p-email">Email Address <span class="req">*</span></label>
                <input id="p-email" type="email" name="admin_email" class="form-input"
                       maxlength="255" required
                       value="<?= View::e($admin_user->email ?? '') ?>">
                <?php if (!empty($fe['email'])): ?><p class="field-error"><?= View::e($fe['email'][0]) ?></p><?php endif; ?>
              </div>
            </div>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Change Password</div>
            <div class="form-row">
              <div class="form-group<?= !empty($fe['admin_new_password']) ? ' has-error' : '' ?>">
                <label class="form-label" for="p-new-pass">New Password <em class="opt">(optional)</em></label>
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
                <?php if (!empty($fe['admin_new_password'])): ?><p class="field-error"><?= View::e($fe['admin_new_password'][0]) ?></p><?php endif; ?>
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
                <?php if (!empty($fe['admin_confirm_password'])): ?><p class="field-error"><?= View::e($fe['admin_confirm_password'][0]) ?></p><?php endif; ?>
              </div>
            </div>
          </div>

          <div class="pc-footer">
            <button type="submit" class="btn btn-primary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              Save Profile
            </button>
            <span class="text-dim" style="font-size:.75rem">Changes apply immediately</span>
          </div>
        </form>
      </div>

      <!-- Admin Accounts -->
      <div class="settings-card settings-card--plain">
        <div class="pc-head">
          <div class="pc-head-icon pc-head-icon--purple">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          </div>
          <div class="pc-head-meta">
            <div class="pc-head-title">Admin Accounts</div>
            <div class="pc-head-sub">All accounts with admin panel access. Each logs in independently.</div>
          </div>
          <a href="<?= $b ?>/admin/admin-users/add" class="btn btn-ghost btn-sm" style="margin-left:auto;flex-shrink:0">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Add Account
          </a>
        </div>

        <div class="neb-table-wrap">
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
                <td>
                  <span class="acct-name"><?= View::e($user->username) ?></span>
                  <?php if ($user->id === ($current_admin_id ?? 0)): ?>
                    <span class="acct-you">You</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted" style="font-family:'DM Mono',monospace;font-size:.8rem"><?= View::e($user->email) ?></td>
                <td class="text-dim" style="font-size:.8rem">
                  <?= $user->lastLogin !== null ? View::e(substr($user->lastLogin, 0, 16)) : '—' ?>
                </td>
                <td class="text-dim nowrap" style="font-size:.8rem">
                  <?= View::e(substr($user->createdAt, 0, 10)) ?>
                </td>
                <td class="act-col">
                  <div class="row-actions">
                    <a href="<?= $b ?>/admin/admin-users/<?= $user->id ?>/edit" class="btn btn-ghost btn-xs">Edit</a>
                    <?php if ($user->id !== ($current_admin_id ?? 0)): ?>
                      <form method="POST"
                            action="<?= $b ?>/admin/admin-users/<?= $user->id ?>/delete"
                            data-del-modal data-del-entity="admin_user"
                            data-del-name="<?= View::e($user->username) ?>">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                        <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="table-footer"><?= count($users ?? []) ?> admin account<?= count($users ?? []) !== 1 ? 's' : '' ?> total</div>
      </div>

      <!-- Danger zone -->
      <div class="danger-zone">
        <div class="danger-zone-head">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="13" height="13" style="color:var(--danger)"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          <div class="danger-zone-title">Danger Zone</div>
        </div>
        <div class="danger-zone-row">
          <div>
            <div class="danger-zone-label">Delete Admin Account</div>
            <div class="danger-zone-desc">Permanently remove another admin account. You cannot delete your own.</div>
          </div>
          <a href="<?= $b ?>/admin/admin-users" class="btn btn-danger btn-sm" style="flex-shrink:0">Manage Accounts</a>
        </div>
      </div>

    </div><!-- /#panel-admin -->

    <!-- ═══ General + Embed in one form (both POST to /admin/settings) ═══ -->
    <form method="POST" action="<?= $b ?>/admin/settings" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

      <!-- ═══ PANEL: General ══════════════════════════════════════ -->
      <div id="panel-general" class="settings-panel">
        <div class="settings-card">
          <div class="pc-head">
            <div class="pc-head-icon pc-head-icon--teal">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
            </div>
            <div class="pc-head-meta">
              <div class="pc-head-title">General Settings</div>
              <div class="pc-head-sub">Core site identity and display preferences.</div>
            </div>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Site Identity</div>
            <div class="form-group<?= !empty($fe['site_name']) ? ' has-error' : '' ?>">
              <label class="form-label" for="s-site-name">Site Name <span class="req">*</span></label>
              <input id="s-site-name" type="text" name="site_name" class="form-input"
                     maxlength="100" required
                     value="<?= View::e(cfgVal($cfg, 'site_name', 'Luster')) ?>">
              <p class="form-hint">Shown in the browser tab and welcome block.</p>
              <?php if (!empty($fe['site_name'])): ?><p class="field-error"><?= View::e($fe['site_name'][0]) ?></p><?php endif; ?>
            </div>
            <div class="form-group<?= !empty($fe['site_url']) ? ' has-error' : '' ?>">
              <label class="form-label" for="s-site-url">Base URL <em class="opt">(no trailing slash)</em></label>
              <input id="s-site-url" type="url" name="site_url" class="form-input"
                     style="font-family:'DM Mono',monospace" maxlength="500" required
                     value="<?= View::e(cfgVal($cfg, 'site_url')) ?>">
              <p class="form-hint">Example: <code class="neb-code">http://localhost/luster/public</code></p>
              <?php if (!empty($fe['site_url'])): ?><p class="field-error"><?= View::e($fe['site_url'][0]) ?></p><?php endif; ?>
            </div>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Owner Info</div>
            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="s-owner-name">Owner Name</label>
                <input id="s-owner-name" type="text" name="owner_name" class="form-input"
                       maxlength="100"
                       value="<?= View::e(cfgVal($cfg, 'owner_name')) ?>">
                <p class="form-hint">Used in outgoing email templates.</p>
              </div>
              <div class="form-group">
                <label class="form-label" for="s-owner-email">Owner Email</label>
                <input id="s-owner-email" type="email" name="owner_email" class="form-input"
                       style="font-family:'DM Mono',monospace" maxlength="255"
                       value="<?= View::e(cfgVal($cfg, 'owner_email')) ?>">
                <p class="form-hint">May differ from your admin login email.</p>
              </div>
            </div>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Display &amp; Locale</div>
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
                    'Pacific'    => ['Pacific/Auckland', 'Pacific/Fiji', 'Pacific/Honolulu'],
                    'Australia'  => [
                        'Australia/Perth', 'Australia/Adelaide',
                        'Australia/Sydney', 'Australia/Brisbane',
                    ],
                    'Africa'     => [
                        'Africa/Cairo', 'Africa/Johannesburg', 'Africa/Lagos',
                        'Africa/Nairobi',
                    ],
                ];
                $allListed = array_merge(...array_values($commonTz));
                if (!in_array($currentTz, $allListed, true)) {
                    $commonTz['Other'] = [$currentTz];
                }
              ?>
              <div class="select-wrap">
                <select id="s-timezone" name="timezone" class="form-select">
                  <?php foreach ($commonTz as $group => $tzList): ?>
                    <optgroup label="<?= View::e($group) ?>">
                      <?php foreach ($tzList as $tz): ?>
                        <option value="<?= View::e($tz) ?>"<?= $tz === $currentTz ? ' selected' : '' ?>>
                          <?= View::e(str_replace('_', ' ', $tz)) ?>
                        </option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endforeach; ?>
                </select>
              </div>
              <p class="form-hint">PHP timezone for all date/time display in the admin panel.</p>
            </div>
            <div class="form-row" style="max-width:440px">
              <div class="form-group">
                <label class="form-label" for="s-date-format">Date Format</label>
                <?php
                  $dateFmtFormats = ['j M Y', 'M j, Y', 'F j, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'm-d-Y'];
                  $dateFmtOptions = array_combine($dateFmtFormats, array_map(static fn($f) => date($f), $dateFmtFormats));
                  $curDateFmt = $admin_date_format ?? 'j M Y';
                ?>
                <div class="select-wrap">
                  <select id="s-date-format" name="date_format" class="form-select">
                    <?php foreach ($dateFmtOptions as $fmt => $example): ?>
                      <option value="<?= View::e($fmt) ?>"<?= $fmt === $curDateFmt ? ' selected' : '' ?>><?= View::e($example) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label" for="s-time-format">Time Format</label>
                <?php
                  $timeFmtFormats = ['H:i', 'g:i A', 'g:i a'];
                  $timeFmtOptions = array_combine($timeFmtFormats, array_map(static fn($f) => date($f), $timeFmtFormats));
                  $curTimeFmt = $admin_time_format ?? 'H:i';
                ?>
                <div class="select-wrap">
                  <select id="s-time-format" name="time_format" class="form-select">
                    <?php foreach ($timeFmtOptions as $fmt => $example): ?>
                      <option value="<?= View::e($fmt) ?>"<?= $fmt === $curTimeFmt ? ' selected' : '' ?>><?= View::e($example) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <div class="pc-footer">
            <button type="submit" class="btn btn-primary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              Save Settings
            </button>
          </div>
        </div>
      </div><!-- /#panel-general -->

      <!-- ═══ PANEL: Embed & Paths ════════════════════════════════ -->
      <div id="panel-embed" class="settings-panel">
        <div class="settings-card">
          <div class="pc-head">
            <div class="pc-head-icon pc-head-icon--gold">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
            </div>
            <div class="pc-head-meta">
              <div class="pc-head-title">Embed &amp; Paths</div>
              <div class="pc-head-sub">Server paths and session settings for embedding forms on external pages.</div>
            </div>
          </div>

          <div class="pc-section">
            <div class="ec-callout">
              <strong>Heads up:</strong> These settings affect how Luster generates <code class="neb-code">require</code> and form action URLs.
              Set them before embedding forms on external pages.
            </div>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Server Paths</div>
            <div class="form-group">
              <label class="form-label" for="s-install-path">Installation Path <em class="opt">(absolute, with trailing slash)</em></label>
              <input id="s-install-path" type="text" name="install_path" class="form-input"
                     style="font-family:'DM Mono',monospace" maxlength="500"
                     placeholder="/home/user/yourdomain.com/luster/"
                     value="<?= View::e(cfgVal($cfg, 'install_path')) ?>">
              <p class="form-hint">Used to generate embed include lines below.</p>
            </div>
            <div class="form-group">
              <label class="form-label" for="s-embed-url">Embed Base URL</label>
              <input id="s-embed-url" type="url" name="embed_base_url" class="form-input"
                     style="font-family:'DM Mono',monospace" maxlength="500"
                     placeholder="https://yourdomain.com/luster/public"
                     value="<?= View::e(cfgVal($cfg, 'embed_base_url')) ?>">
              <p class="form-hint">The public URL for form submissions (no trailing slash). Leave blank to use the Base URL from General.</p>
            </div>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Session Cookie Domain</div>
            <div class="form-group">
              <label class="form-label" for="s-cookie-domain">Cookie Domain</label>
              <input id="s-cookie-domain" type="text" name="session_cookie_domain" class="form-input"
                     style="font-family:'DM Mono',monospace" maxlength="255"
                     placeholder=".yourdomain.com"
                     value="<?= View::e(cfgVal($cfg, 'session_cookie_domain')) ?>">
              <p class="form-hint">
                Include the leading dot for all subdomains (e.g. <code class="neb-code">.yourdomain.com</code>).
                Leave blank for single-domain installs.
              </p>
            </div>
          </div>

          <?php
            $installPath = cfgVal($cfg, 'install_path');
            if ($installPath !== ''):
              $installPath = rtrim($installPath, '/');
          ?>
          <div class="pc-section">
            <div class="pc-section-label">Per-Listing Embed Lines</div>
            <p class="form-hint" style="margin-bottom:.875rem">
              Set <code class="neb-code">$luster_fanlisting_id</code> to the listing ID before each include.
              Optionally set <code class="neb-code">$luster_return = true</code> to capture the output as a string.
            </p>
            <?php
            $perListingEmbeds = [
                'join'       => 'Join form',
                'members'    => 'Members list',
                'update'     => 'Update membership',
                'remove'     => 'Remove membership',
                'reset'      => 'Password reset',
                'stats'      => 'Listing stats',
                'affiliates' => 'Affiliates (per-listing)',
            ];
            foreach ($perListingEmbeds as $file => $label): ?>
            <div class="form-group">
              <label class="form-label"><?= View::e($label) ?></label>
              <div class="ro-field" style="font-family:'DM Mono',monospace;font-size:.8rem;user-select:all">
                $luster_fanlisting_id = 1; require '<?= View::e($installPath) ?>/public/embed/<?= View::e($file) ?>.php';
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Site-Wide Embed Lines</div>
            <p class="form-hint" style="margin-bottom:.875rem">
              These embeds aggregate data across your entire collective.
              No <code class="neb-code">$luster_fanlisting_id</code> needed
              (except Affiliates, where <code class="neb-code">$luster_fanlisting_id = 0</code> gives site-wide affiliates).
            </p>
            <div class="form-group">
              <label class="form-label">Collective stats</label>
              <div class="ro-field" style="font-family:'DM Mono',monospace;font-size:.8rem;user-select:all">
                require '<?= View::e($installPath) ?>/public/embed/collective-stats.php';
              </div>
              <p class="form-hint">Exposes <code class="neb-code">$luster_owned_active</code>, <code class="neb-code">$luster_members_approved</code>, growth rates, and more.</p>
            </div>
            <div class="form-group">
              <label class="form-label">Site-wide affiliates</label>
              <div class="ro-field" style="font-family:'DM Mono',monospace;font-size:.8rem;user-select:all">
                $luster_fanlisting_id = 0; require '<?= View::e($installPath) ?>/public/embed/affiliates.php';
              </div>
            </div>
          </div>
          <?php endif; ?>

          <div class="pc-footer">
            <button type="submit" class="btn btn-primary">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              Save Settings
            </button>
          </div>
        </div>
      </div><!-- /#panel-embed -->

    </form><!-- /general + embed form -->

    <!-- ═══ PANEL: Blacklist ════════════════════════════════════ -->
    <div id="panel-blacklist" class="settings-panel">

      <div class="settings-card">
        <div class="pc-head">
          <div class="pc-head-icon pc-head-icon--pink">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
          </div>
          <div class="pc-head-meta">
            <div class="pc-head-title">Email Blacklist</div>
            <div class="pc-head-sub">Block specific addresses or entire domains from submitting join requests.</div>
          </div>
        </div>

        <div class="pc-section">
          <div class="pc-section-label">Add Pattern</div>
          <form method="POST" action="<?= $b ?>/admin/blacklist/add">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="bl-pattern">Email Pattern <span class="req">*</span></label>
                <input id="bl-pattern" type="text" name="pattern" class="form-input"
                       style="font-family:'DM Mono',monospace" maxlength="255" required
                       placeholder="spam@example.com  or  @spam.com">
                <p class="form-hint">Full email for exact block, or start with <code class="neb-code">@</code> to block an entire domain.</p>
              </div>
              <div class="form-group">
                <label class="form-label" for="bl-note">Note <em class="opt">(optional)</em></label>
                <input id="bl-note" type="text" name="note" class="form-input"
                       maxlength="500" placeholder="Why this address was blocked">
                <p class="form-hint">Internal note — not shown to visitors.</p>
              </div>
            </div>
            <button type="submit" class="btn btn-danger btn-sm" style="margin-top:.25rem">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
              Add to Blacklist
            </button>
          </form>
        </div>

        <div class="pc-section" style="padding-bottom:0">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.875rem">
            <div class="pc-section-label" style="margin-bottom:0">Blocked Patterns</div>
            <span class="neb-badge badge-error"><?= count($blacklist_entries ?? []) ?> entr<?= count($blacklist_entries ?? []) === 1 ? 'y' : 'ies' ?></span>
          </div>

          <?php if (empty($blacklist_entries)): ?>
            <div class="neb-empty" style="margin-bottom:1.25rem">
              <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              No blocked patterns yet — add email addresses or domains above to prevent them from joining.
            </div>
          <?php else: ?>
            <div class="bl-list" style="margin-bottom:1.25rem">
              <?php foreach ($blacklist_entries as $entry): ?>
                <div class="pattern-row">
                  <span class="pattern-tag"><?= View::e($entry['pattern']) ?></span>
                  <?php if ($entry['note'] !== ''): ?>
                    <span class="pattern-tag-note"><?= View::e($entry['note']) ?></span>
                  <?php endif; ?>
                  <form method="POST"
                        action="<?= $b ?>/admin/blacklist/<?= (int) $entry['id'] ?>/delete"
                        style="margin-left:auto"
                        data-del-modal data-del-entity="blacklist"
                        data-del-name="<?= View::e($entry['pattern']) ?>">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                    <button type="submit" class="pattern-remove-btn" aria-label="Remove">&times;</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /#panel-blacklist -->

    <!-- ═══ PANEL: System Details ══════════════════════════════ -->
    <div id="panel-system" class="settings-panel">

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">

        <!-- Script info -->
        <div class="sysinfo-card">
          <div class="sysinfo-head">
            <svg class="sysinfo-head-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14 10l-2 1m0 0l-2-1m2 1v2.5M20 7l-2 1m2-1l-2-1m2 1v2.5M14 4l-2-1-2 1M4 7l2-1M4 7l2 1M4 7v2.5M12 21l-2-1m2 1l2-1m-2 1v-2.5M6 18l-2-1v-2.5M18 18l2-1v-2.5"/></svg>
            <span class="sysinfo-head-title">Script</span>
          </div>
          <div class="sysinfo-body">
            <div class="sysinfo-row">
              <span class="sysinfo-key">Name</span>
              <span class="sysinfo-val">Luster</span>
            </div>
            <div class="sysinfo-row">
              <span class="sysinfo-key">Version</span>
              <div class="sysinfo-val-flex">
                <span>beta</span>
                <span class="neb-badge badge-warn">Beta</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Server info -->
        <div class="sysinfo-card">
          <div class="sysinfo-head">
            <svg class="sysinfo-head-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
            <span class="sysinfo-head-title">Server</span>
          </div>
          <div class="sysinfo-body">
            <div class="sysinfo-row">
              <span class="sysinfo-key">PHP Version</span>
              <div class="sysinfo-val-flex">
                <span class="status-dot status-dot--green"></span>
                <span><?= View::e($php_version) ?></span>
                <span class="neb-badge badge-success">OK</span>
              </div>
            </div>
            <div class="sysinfo-row">
              <span class="sysinfo-key">Database</span>
              <div class="sysinfo-val-flex">
                <span class="status-dot status-dot--green"></span>
                <span><?= View::e($db_version) ?></span>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- DB Connection -->
      <div class="sysinfo-card">
        <div class="sysinfo-head">
          <svg class="sysinfo-head-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
          <span class="sysinfo-head-title">Database Connection</span>
          <span style="margin-left:auto;font-size:.7rem;color:var(--text-dim)">Read from <code class="neb-code">config/app.php</code></span>
        </div>
        <div class="sysinfo-body" style="padding:.75rem 1.25rem">
          <div class="db-conn-card">
            <div class="db-conn-row"><span class="db-conn-key">host</span><span class="db-conn-val"><?= View::e(cfgVal($cfg, 'db_host', 'localhost')) ?></span></div>
            <div class="db-conn-row"><span class="db-conn-key">database</span><span class="db-conn-val"><?= View::e(cfgVal($cfg, 'db_name')) ?></span></div>
            <div class="db-conn-row"><span class="db-conn-key">user</span><span class="db-conn-val"><?= View::e(cfgVal($cfg, 'db_user')) ?></span></div>
            <div class="db-conn-row">
              <span class="db-conn-key">debug</span>
              <span class="db-conn-val">
                <?php if ($cfg['app_debug'] ?? false): ?>
                  <span class="neb-badge badge-warn">Enabled</span>
                  <span style="color:var(--text-dim);font-family:'DM Sans',sans-serif;font-size:.72rem;margin-left:.35rem">Disable in production</span>
                <?php else: ?>
                  <span class="neb-badge badge-inactive">Disabled</span>
                <?php endif; ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Diagnostics -->
      <div class="sysinfo-card">
        <div class="sysinfo-head">
          <svg class="sysinfo-head-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
          <span class="sysinfo-head-title">Diagnostics</span>
        </div>
        <div class="sysinfo-body">
          <div class="diag-row">
            <div class="diag-icon diag-icon--red">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div>
              <div class="diag-label">Error Log</div>
              <div class="diag-sub">PHP errors caught by Luster's error handler</div>
            </div>
            <?php if ($error_log_count > 0): ?>
              <span class="neb-badge badge-error" style="margin-left:auto;flex-shrink:0"><?= (int) $error_log_count ?> entr<?= $error_log_count === 1 ? 'y' : 'ies' ?></span>
            <?php else: ?>
              <span class="neb-badge badge-inactive" style="margin-left:auto;flex-shrink:0">0 entries</span>
            <?php endif; ?>
            <a href="<?= $b ?>/admin/errorlog" class="btn btn-ghost btn-xs" style="flex-shrink:0">View &rarr;</a>
          </div>
          <div class="diag-row">
            <div class="diag-icon diag-icon--teal">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <div>
              <div class="diag-label">Activity Log</div>
              <div class="diag-sub">Admin actions and system events</div>
            </div>
            <span class="neb-badge badge-success" style="margin-left:auto;flex-shrink:0">Active</span>
            <a href="<?= $b ?>/admin/activity" class="btn btn-ghost btn-xs" style="flex-shrink:0">View &rarr;</a>
          </div>
        </div>
      </div>

    </div><!-- /#panel-system -->

  </div><!-- /.settings-content -->
</div><!-- /.settings-layout -->
