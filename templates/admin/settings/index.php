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

<div class="mini-nav-layout">

  <nav class="panel-card">
    <div class="card-head"><div class="card-head-title">Settings</div></div>
    <div class="panel-body">
      <a href="#panel-admin" class="mini-nav-item"><?= View::icon('account') ?> Account Details</a>
      <a href="#panel-accounts" class="mini-nav-item"><?= View::icon('admins') ?> Admin Accounts</a>
      <a href="#panel-general" class="mini-nav-item"><?= View::icon('general') ?> General</a>
      <a href="#panel-embed" class="mini-nav-item"><?= View::icon('paths') ?> Server Paths</a>
      <a href="#panel-blacklist" class="mini-nav-item"><?= View::icon('blacklist') ?> Blacklist</a>
      <a href="#panel-system" class="mini-nav-item"><?= View::icon('wrench') ?> System Details</a>
    </div>
  </nav>

  <div class="settings-content">

    <div id="panel-admin" class="mini-nav-panel">
      <div class="gradient-card">
        <div class="pc-head">
          <div class="pc-head-icon pc-head-icon-bg">
            <?= View::icon('account') ?>
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
                <div style="position:relative">
                  <input id="p-new-pass" type="password" name="admin_new_password" class="form-input"
                         style="padding-right:42px" maxlength="255" autocomplete="new-password"
                         placeholder="Leave blank to keep current">
                  <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1"
                          style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-dim);padding:4px;line-height:0"
                          onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-dim)'">
                    <?= View::icon('eye-on') ?>
                    <?= View::icon('eye-off') ?>
                  </button>
                </div>
                <?php if (!empty($fe['admin_new_password'])): ?><p class="field-error"><?= View::e($fe['admin_new_password'][0]) ?></p><?php endif; ?>
              </div>
              <div class="form-group<?= !empty($fe['admin_confirm_password']) ? ' has-error' : '' ?>">
                <label class="form-label" for="p-confirm-pass">Confirm New Password</label>
                <div class="input-icon-wrap">
                  <input id="p-confirm-pass" type="password" name="admin_confirm_password" class="form-input"
                         maxlength="255" autocomplete="new-password"
                         placeholder="Repeat new password">
                  <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
                    <?= View::icon('eye-on') ?>
                    <?= View::icon('eye-off') ?>
                  </button>
                </div>
                <?php if (!empty($fe['admin_confirm_password'])): ?><p class="field-error"><?= View::e($fe['admin_confirm_password'][0]) ?></p><?php endif; ?>
              </div>
            </div>
          </div>

          <div class="composer-footer">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <button type="reset" class="btn btn-ghost">Clear Form</button>
            <span class="text-dim">Changes apply immediately</span>
          </div>
        </form>
      </div>
    </div>
    
    <div id="panel-accounts" class="mini-nav-panel">
      <div class="gradient-card gradient-card--plain">
        <div class="pc-head">
          <div class="pc-head-icon pc-head-icon-bg">
            <?= View::icon('admins') ?>
          </div>
          <div class="pc-head-meta">
            <div class="pc-head-title">Admin Accounts <span class="badge badge-info-pink" style="font-family: 'DM Sans', sans-serif; margin-left: .5rem;"><?= count($users ?? []) ?> Total</span></div>
            <div class="pc-head-sub">All accounts with admin panel access. Each logs in independently.</div>
          </div>
          <a href="<?= $b ?>/admin/admin-users/add" class="btn btn-primary btn-sm" style="margin-left:auto; flex-shrink:0;">
            <?= View::icon('add') ?> Add Account
          </a>
        </div>

        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Username</th>
                <th>Email</th>
                <th>Last Login</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($users ?? []) as $user): ?>
              <tr>
                <td>
                  <?= View::e($user->username) ?>
                  <?php if ($user->id === ($current_admin_id ?? 0)): ?>
                    <span class="badge badge-info" style="margin-left: .5rem;">You</span>
                  <?php endif; ?>
                </td>
                <td><?= View::e($user->email) ?></td>
                <td>
                  <?= $user->lastLogin !== null ? View::e(substr($user->lastLogin, 0, 16)) : '—' ?>
                </td>
                <td>
                  <?= View::e(substr($user->createdAt, 0, 10)) ?>
                </td>
                <td class="act-col">
                  <div class="data-actions">
                    <a href="<?= $b ?>/admin/admin-users/<?= $user->id ?>/edit" class="btn btn-tertiary btn-sm">Edit</a>
                    <?php if ($user->id !== ($current_admin_id ?? 0)): ?>
                      <form method="POST"
                            action="<?= $b ?>/admin/admin-users/<?= $user->id ?>/delete"
                            data-del-modal data-del-entity="admin_user"
                            data-del-name="<?= View::e($user->username) ?>">
                        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div><!-- /.gradient-card -->
    </div><!-- /#panel-accounts -->

    <!-- ═══ General + Embed in one form (both POST to /admin/settings) ═══ -->
    <form method="POST" action="<?= $b ?>/admin/settings" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

      <!-- ═══ PANEL: General ══════════════════════════════════════ -->
      <div id="panel-general" class="mini-nav-panel">
        <div class="gradient-card">
          <div class="pc-head">
            <div class="pc-head-icon pc-head-icon-bg">
            <?= View::icon('general') ?>
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
              <label class="form-label" for="s-site-url">Base URL</label>
              <input id="s-site-url" type="url" name="site_url" class="form-input" maxlength="500" required
                     value="<?= View::e(cfgVal($cfg, 'site_url')) ?>">
              <p class="form-hint"><b>No trailing slash.</b> Example: <code class="neb-code">http://localhost/luster/public</code></p>
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
                <p class="form-hint">Used publically, such as in email correspondence.</p>
              </div>
              <div class="form-group">
                <label class="form-label" for="s-owner-email">Owner Email</label>
                <input id="s-owner-email" type="email" name="owner_email" class="form-input" maxlength="255"
                       value="<?= View::e(cfgVal($cfg, 'owner_email')) ?>">
                <p class="form-hint">Used publically, such as in email correspondence.<br> May differ from your admin login email.</p>
              </div>
            </div>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Display & Locale</div>
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
              <div class="select-wrap" style="display:inline-block;max-width:100%">
                <select id="s-timezone" name="timezone" class="form-select" style="width:auto;min-width:min-content;max-width:100%">
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
              <p class="form-hint">Timezone for all date/time display throughout the admin panel.</p>
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

          <div class="pc-section">
            <div class="pc-section-label">Collective Contact Form</div>
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
                     name="collective_contact_recipient" class="form-input" maxlength="255"
                     placeholder="Defaults to Owner Email"
                     value="<?= View::e($site_settings['collective_contact_recipient'] ?? '') ?>">
              <p class="form-hint">Override which email address receives collective contact messages. Leave blank to use the Owner Email above.</p>
            </div>
          </div>

          <div class="composer-footer">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <button type="button" class="btn btn-ghost" id="general-clear-btn">Clear Form</button>
          </div>
        </div>
      </div><!-- /#panel-general -->

      <!-- ═══ PANEL: Embed & Paths ════════════════════════════════ -->
      <div id="panel-embed" class="mini-nav-panel">
        <div class="gradient-card">
          <div class="pc-head">
            <div class="pc-head-icon pc-head-icon-bg">
            <?= View::icon('paths') ?>
            </div>
            <div class="pc-head-meta">
              <div class="pc-head-title">Server Paths</div>
              <div class="pc-head-sub">Paths and session settings for embed codes and forms on external pages.</div>
            </div>
          </div>

          <div class="pc-section">
            <div class="callout" style="margin-block: 0.25rem;">
              <strong>Heads up:</strong> These settings affect how Luster generates <code>require</code> and form action URLs.
              Set them before embedding forms on external pages.
            </div>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Server Paths</div>
            <div class="form-group">
              <label class="form-label" for="s-install-path">Installation Path <em class="opt">(absolute, with trailing slash)</em></label>
              <input id="s-install-path" type="text" name="install_path" class="form-input" maxlength="500"
                     placeholder="/home/user/yourdomain.com/luster/"
                     value="<?= View::e(cfgVal($cfg, 'install_path')) ?>">
              <p class="form-hint">Used to generate embed include lines below.</p>
            </div>
            <div class="form-group">
              <label class="form-label" for="s-embed-url">Embed Base URL</label>
              <input id="s-embed-url" type="url" name="embed_base_url" class="form-input" maxlength="500"
                     placeholder="https://yourdomain.com/luster/public"
                     value="<?= View::e(cfgVal($cfg, 'embed_base_url')) ?>">
              <p class="form-hint">The public URL for form submissions (no trailing slash). Leave blank to use the Base URL from General.</p>
            </div>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Session Cookie Domain</div>
            <div class="form-group">
              <label class="form-label" for="s-cookie-domain">Cookie Domain</label>
              <input id="s-cookie-domain" type="text" name="session_cookie_domain" class="form-input" maxlength="255"
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
            <p class="form-hint">
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
              <div class="ro-field">
                $luster_fanlisting_id = 1; require '<?= View::e($installPath) ?>/public/embed/<?= View::e($file) ?>.php';
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="pc-section">
            <div class="pc-section-label">Site-Wide Embed Lines</div>
            <p class="form-hint">
              These embeds aggregate data across your entire collective.
              No <code class="neb-code">$luster_fanlisting_id</code> needed
              (except Affiliates, where <code class="neb-code">$luster_fanlisting_id = 0</code> gives site-wide affiliates).
            </p>
            <div class="form-group">
              <label class="form-label">Collective stats</label>
              <div class="ro-field">
                require '<?= View::e($installPath) ?>/public/embed/collective-stats.php';
              </div>
              <p class="form-hint">Exposes <code class="neb-code">$luster_owned_active</code>, <code class="neb-code">$luster_members_approved</code>, growth rates, and more.</p>
            </div>
            <div class="form-group">
              <label class="form-label">Site-wide affiliates</label>
              <div class="ro-field">
                $luster_fanlisting_id = 0; require '<?= View::e($installPath) ?>/public/embed/affiliates.php';
              </div>
            </div>
          </div>
          <?php endif; ?>

          <div class="composer-footer">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <button type="button" class="btn btn-ghost" id="embed-clear-btn">Clear Form</button>
          </div>
        </div>
      </div>
    </form>

    <div id="panel-blacklist" class="mini-nav-panel">
      <div class="gradient-card">
        <div class="pc-head">
          <div class="pc-head-icon pc-head-icon-bg">
            <?= View::icon('blacklist') ?>
          </div>
          <div class="pc-head-meta">
            <div class="pc-head-title">Blacklist</div>
            <div class="pc-head-sub">Block specific addresses or entire domains from submitting the join form.</div>
          </div>
        </div>

        <div class="pc-section">
          <div class="pc-section-label">Add Pattern</div>
          <form id="blacklist-add-form" method="POST" action="<?= $b ?>/admin/blacklist/add">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="bl-pattern">Email Pattern <span class="req">*</span></label>
                <input id="bl-pattern" type="text" name="pattern" class="form-input" maxlength="255" required
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
        </div>
        <div class="composer-footer">
          <button type="submit" form="blacklist-add-form" class="btn btn-primary btn-sm">Add to Blacklist</button>
          <button type="reset" form="blacklist-add-form" class="btn btn-ghost btn-sm">Clear Form</button>
        </div>
      </div>

      <?php if (empty($blacklist_entries)): ?>
        <div class="card">
          <div class="empty">
            <span class="empty-icon"><?= View::icon('blacklist', ['width' => 32, 'height' => 32]) ?></span>
            No blocked patterns yet — add email addresses or domains above to prevent them from joining.
          </div>
        </div>
      <?php else: ?>
        <div class="card">
          <div class="card-head">
            <span class="card-head-title">Blocked Patterns</span>
            <span class="row-sub">&mdash; <?= count($blacklist_entries) ?> entr<?= count($blacklist_entries) === 1 ? 'y' : 'ies' ?></span>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Pattern</th>
                  <th>Note</th>
                  <th class="act-col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($blacklist_entries as $entry): ?>
                <tr>
                  <td><code><?= View::e($entry['pattern']) ?></code></td>
                  <td><?= $entry['note'] !== '' ? View::e($entry['note']) : '—' ?></td>
                  <td class="act-col">
                    <form method="POST"
                          action="<?= $b ?>/admin/blacklist/<?= (int) $entry['id'] ?>/delete"
                          style="display:contents"
                          data-del-modal data-del-entity="blacklist"
                          data-del-name="<?= View::e($entry['pattern']) ?>">
                      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div id="panel-system" class="mini-nav-panel">
      <div class="gradient-card">
        <div class="pc-head">
          <div class="pc-head-icon pc-head-icon-bg">
            <?= View::icon('wrench') ?>
          </div>
          <div class="pc-head-meta">
            <div class="pc-head-title">System Details</div>
            <div class="pc-head-sub">Runtime environment and diagnostic information.</div>
          </div>
        </div>

        <!-- Script -->
        <div class="pc-section">
          <div class="pc-section-label">Script</div>
            <div class="sysinfo-row">
              <span class="form-label">Name</span> Luster
            </div>
            <div class="sysinfo-row">
              <span class="form-label">Version</span> beta
            </div>
        </div>

        <!-- Server -->
        <div class="pc-section">
          <div class="pc-section-label">Server</div>
            <div class="sysinfo-row">
              <span class="form-label">PHP Version</span> <?= View::e($php_version) ?>
            </div>
            <div class="sysinfo-row">
              <span class="form-label">Database</span> <?= View::e($db_version) ?>
            </div>
        </div>

        <!-- Database Connection -->
        <div class="pc-section">
          <div class="pc-section-label">
            Database Connection (from <code>config/app.php</code>)</span>
          </div>
            <div class="sysinfo-row">
              <span class="form-label">Host</span> <?= View::e(cfgVal($cfg, 'db_host', 'localhost')) ?>
            </div>
            <div class="sysinfo-row">
              <span class="form-label">Database</span> <?= View::e(cfgVal($cfg, 'db_name')) ?>
            </div>
            <div class="sysinfo-row">
              <span class="form-label">User</span> <?= View::e(cfgVal($cfg, 'db_user')) ?>
            </div>
            <div class="sysinfo-row">
              <span class="form-label">Debug</span>
              <span style="display:flex;align-items:center;gap:.5rem">
                <?php if ($cfg['app_debug'] ?? false): ?>
                  <span class="badge badge-error">Enabled</span>
                  <span class="text-dim" style="font-size:.8rem">Disable in production</span>
                <?php else: ?>
                  <span class="badge badge-inactive">Disabled</span>
                <?php endif; ?>
              </span>
            </div>
        </div>

        <!-- Data Migration -->
        <div class="pc-section">
          <div class="pc-section-label">Data Migration</div>
          <p style="margin:0 0 .75rem;color:var(--text-dim);font-size:.9rem">
            Migrate data from Enthusiast, Listing Admin, or other fanlisting scripts into Luster.
          </p>
          <a href="<?= $b ?>/admin/import" class="btn btn-secondary btn-sm">
            <?= View::icon('import') ?> Import Data Wizard
          </a>
        </div>

      </div>

    </div>

  </div>
</div>
