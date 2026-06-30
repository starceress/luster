<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin settings page. A multi-panel page using CSS :target panel switching
 for profile and password management, admin user accounts, appearance and
 skin selection, general configuration including SMTP, embed URL and path
 settings, blacklist management, and system details.
*/

use Luster\Core\SkinCatalog;
use Luster\Core\UpdateStatus;
use Luster\Core\Version;
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

  <nav class="card">
    <div class="card-head">Settings</div>
    <div class="panel-body">
      <a href="#panel-general" class="mini-nav-item"><?= View::icon('general') ?> General</a>
      <a href="#panel-admin" class="mini-nav-item"><?= View::icon('account') ?> Account Details</a>
      <a href="#panel-accounts" class="mini-nav-item"><?= View::icon('admins') ?> Admin Accounts</a>
      <a href="#panel-notifications" class="mini-nav-item"><?= View::icon('email') ?> Notifications</a>
      <a href="#panel-appearance" class="mini-nav-item"><?= View::icon('palette') ?> Appearance</a>
      <a href="#panel-smtp" class="mini-nav-item"><?= View::icon('email') ?> SMTP &amp; Email</a>
      <a href="#panel-embed" class="mini-nav-item"><?= View::icon('paths') ?> Server Paths</a>
      <a href="#panel-blacklist" class="mini-nav-item"><?= View::icon('blacklist') ?> Spam Filtering</a>
      <a href="#panel-system" class="mini-nav-item"><?= View::icon('wrench') ?> System Details</a>
      <div class="select-wrap settings-nav-select-wrap">
        <select id="settings-nav-select">
          <option value="panel-general">General</option>
          <option value="panel-admin">Account Details</option>
          <option value="panel-accounts">Admin Accounts</option>
          <option value="panel-notifications">Notifications</option>
          <option value="panel-appearance">Appearance</option>
          <option value="panel-smtp">SMTP &amp; Email</option>
          <option value="panel-embed">Server Paths</option>
          <option value="panel-blacklist">Spam Filtering</option>
          <option value="panel-system">System Details</option>
        </select>
      </div>
    </div>
  </nav>

  <div class="settings-content">

<!-- ──── PANEL: ACCOUNT DETAILS ─────────────────────────────────────── -->
    <div id="panel-admin" class="mini-nav-panel">
      <div class="card card--gradient">
        <div class="panel-header">
          <div class="panel-header-icon panel-header-icon-filled">
            <?= View::icon('account') ?>
          </div>
          <div class="panel-header-meta">
            <div class="panel-header-title">Account Details</div>
            <div class="panel-header-sub">Update your login credentials and password. Changes apply immediately upon saving.</div>
          </div>
        </div>

        <form method="POST" action="<?= $b ?>/admin/settings/profile" class="js-warn-unsaved">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

          <div class="card-body">
            <div class="section-label">Login Details</div>
            <div class="field-row<?= !empty($fe['username']) ? ' has-error' : '' ?>">
              <label class="field-label" for="p-username">Username <span class="req">*</span></label>
              <div>
                <input id="p-username" type="text" name="admin_username" class="form"
                       maxlength="50" required
                       value="<?= View::e($admin_user->username ?? '') ?>">
                <p class="form-hint">The name you type on the admin login screen.</p>
                <?php if (!empty($fe['username'])): ?><p class="field-error"><?= View::e($fe['username'][0]) ?></p><?php endif; ?>
              </div>
            </div>

            <div class="field-row<?= !empty($fe['email']) ? ' has-error' : '' ?>">
              <label class="field-label" for="p-email">Email Address <span class="req">*</span></label>
              <div>
                <input id="p-email" type="email" name="admin_email" class="form"
                       maxlength="255" required
                       value="<?= View::e($admin_user->email ?? '') ?>">
                <p class="form-hint">Used for admin account messages, including admin password resets.</p>
                <?php if (!empty($fe['email'])): ?><p class="field-error"><?= View::e($fe['email'][0]) ?></p><?php endif; ?>
              </div>
            </div>

            <div class="field-row">
              <label class="field-label" for="p-owner-name">Owner Name <span class="req">*</span></label>
              <div>
                <input id="p-owner-name" type="text" name="admin_display_name" class="form"
                       maxlength="100" required
                       value="<?= View::e($admin_user->displayName ?? '') ?>">
                <p class="form-hint">The friendly name shown inside the admin panel for this account.</p>
              </div>
            </div>
          </div>

          <div class="card-body">
            <div class="section-label">Change Password</div>
            <div class="field-group<?= !empty($fe['admin_current_password']) ? ' has-error' : '' ?>">
              <label class="field-label" for="p-current-pass">Current Password <em class="opt">(required to set a new password)</em></label>
              <div class="input-icon-wrap">
                <input id="p-current-pass" type="password" name="admin_current_password" class="form"
                       maxlength="255" autocomplete="current-password"
                       placeholder="Enter your current password">
                <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1" class="input-icon-btn">
                  <?= View::icon('eye-on') ?>
                  <?= View::icon('eye-off') ?>
                </button>
              </div>
              <p class="form-hint">Only needed when you are changing your password.</p>
              <?php if (!empty($fe['admin_current_password'])): ?><p class="field-error"><?= View::e($fe['admin_current_password'][0]) ?></p><?php endif; ?>
            </div>
            <div class="field-grid">
              <div class="field-group<?= !empty($fe['admin_new_password']) ? ' has-error' : '' ?>">
                <label class="field-label" for="p-new-pass">New Password</label>
                <div class="input-icon-wrap">
                  <input id="p-new-pass" type="password" name="admin_new_password" class="form"
                         maxlength="255" autocomplete="new-password"
                         placeholder="Leave blank to keep current">
                  <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1" class="input-icon-btn">
                    <?= View::icon('eye-on') ?>
                    <?= View::icon('eye-off') ?>
                  </button>
                </div>
                <p class="form-hint">Leave blank to keep your current password. New passwords must be at least 12 characters.</p>
                <?php if (!empty($fe['admin_new_password'])): ?><p class="field-error"><?= View::e($fe['admin_new_password'][0]) ?></p><?php endif; ?>
              </div>
              <div class="field-group<?= !empty($fe['admin_confirm_password']) ? ' has-error' : '' ?>">
                <label class="field-label" for="p-confirm-pass">Confirm New Password</label>
                <div class="input-icon-wrap">
                  <input id="p-confirm-pass" type="password" name="admin_confirm_password" class="form"
                         maxlength="255" autocomplete="new-password"
                         placeholder="Repeat new password">
                  <button type="button" data-pw-toggle class="input-icon-btn" aria-label="Show password" tabindex="-1">
                    <?= View::icon('eye-on') ?>
                    <?= View::icon('eye-off') ?>
                  </button>
                </div>
                <p class="form-hint">Type the same new password again so Luster can catch typos.</p>
                <?php if (!empty($fe['admin_confirm_password'])): ?><p class="field-error"><?= View::e($fe['admin_confirm_password'][0]) ?></p><?php endif; ?>
              </div>
            </div>
          </div>
          <div class="composer-footer">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <button type="reset" class="btn btn-ghost">Clear</button>
          </div>
        </form>
      </div>
    </div>

<!-- ──── PANEL: ADMIN ACCOUNTS ──────────────────────────────────────── -->
    <div id="panel-accounts" class="mini-nav-panel">
      <div class="card card--gradient card--gradient--plain">
        <div class="panel-header">
          <div class="panel-header-icon panel-header-icon-filled">
            <?= View::icon('admins') ?>
          </div>
            <div class="panel-header-meta">
            <div class="panel-header-title">Admin Accounts &nbsp; <span class="badge badge-info2" style="font-family: var(--font-sans);"><?= count($users ?? []) ?> Total</span></div>
            <div class="panel-header-sub">All accounts with admin panel access. Each logs in independently.</div>
          </div>
          <a href="<?= $b ?>/admin/admin-users/add" class="btn btn-primary btn-sm panel-header-actions">
            <?= View::icon('add') ?> Add Account
          </a>
        </div>

        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Username</th>
                <th>Display Name</th>
                <th>Email</th>
                <th>Last Login</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($users ?? []) as $user): ?>
              <tr>
                <td data-label="Username">
                  <?= View::e($user->username) ?>
                  <?php if ($user->id === ($current_admin_id ?? 0)): ?>
                    <span class="badge badge-info badge-inline-gap">You</span>
                  <?php endif; ?>
                </td>
                <td data-label="Display Name"><?= $user->displayName !== null && $user->displayName !== '' ? View::e($user->displayName) : '<span class="text-dim">&mdash;</span>' ?></td>
                <td data-label="Email"><?= View::emailWrap($user->email) ?></td>
                <td data-label="Last Login">
                  <?= $user->lastLogin !== null ? View::e(substr($user->lastLogin, 0, 16)) : '&mdash;' ?>
                </td>
                <td data-label="Created">
                  <?= View::e(substr($user->createdAt, 0, 10)) ?>
                </td>
                <td class="actions-col">
                  <div class="actions-row">
                    <a href="<?= $b ?>/admin/admin-users/<?= $user->id ?>/edit" class="btn btn-tertiary btn-sm">Edit</a>
                    <?php if ($user->id !== ($current_admin_id ?? 0)): ?>
                      <form method="POST"
                            action="<?= $b ?>/admin/admin-users/<?= $user->id ?>/delete"
                            class="action-form-inline"
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
      </div>
    </div>


<!-- ──── PANEL: APPEARANCE ──────────────────────────────────────────── -->
    <div id="panel-appearance" class="mini-nav-panel">
      <div class="card card--gradient">
        <div class="panel-header">
          <div class="panel-header-icon panel-header-icon-filled"><?= View::icon('palette') ?></div>
          <div class="panel-header-meta">
            <div class="panel-header-title">Appearance</div>
            <div class="panel-header-sub">Choose a colour skin for the admin panel. The skin applies to all admin pages.</div>
          </div>
        </div>

        <div class="card-body">
          <div class="section-label">Default Mode</div>
          <?php $activeDefaultTheme = $site_settings['admin_default_theme'] ?? 'light'; ?>
          <div class="field-row">
            <label class="field-label">Light / Dark</label>
            <div>
              <div class="theme-mode-picker">
                <form method="POST" action="<?= $b ?>/admin/settings/default-theme"
                      class="action-form-inline" id="default-theme-form">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="admin_default_theme" id="default-theme-value"
                         value="<?= View::e($activeDefaultTheme) ?>">
                </form>
                <label class="theme-mode-btn<?= $activeDefaultTheme === 'light' ? ' theme-mode-btn--active' : '' ?>">
                  <input type="radio" name="default_theme_radio" value="light" class="sr-only"
                         <?= $activeDefaultTheme === 'light' ? 'checked' : '' ?>>
                  <?= View::icon('sun') ?> Light
                </label>
                <label class="theme-mode-btn<?= $activeDefaultTheme === 'dark' ? ' theme-mode-btn--active' : '' ?>">
                  <input type="radio" name="default_theme_radio" value="dark" class="sr-only"
                         <?= $activeDefaultTheme === 'dark' ? 'checked' : '' ?>>
                  <?= View::icon('moon') ?> Dark
                </label>
              </div>
              <p class="form-hint">Sets the starting theme for new browsers and sessions. Each admin can override it locally using the toggle button in the header.</p>
            </div>
          </div>
        </div>

        <div class="card-body">
          <div class="section-label">Active Skin</div>
          <?php
            $activeSkin   = $site_settings['selected_skin'] ?? '';
            $bundledSkins = SkinCatalog::all();
          ?>
          <div class="skin-picker">
            <?php foreach ($bundledSkins as $skin):
                $slug = $skin['slug'];
            ?>
              <form method="POST" action="<?= $b ?>/admin/settings/skin" class="action-form-inline">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="skin" value="<?= View::e($slug) ?>">
                <button type="submit" class="skin-card<?= $activeSkin === $slug ? ' skin-card--active' : '' ?>"
                        data-skin-preview="<?= View::e($slug) ?>">
                  <span class="skin-swatch">
                    <?php foreach ($skin['colors'] as $c): ?>
                      <span class="skin-swatch-dot" style="background:<?= View::e($c) ?>"></span>
                    <?php endforeach; ?>
                  </span>
                  <span class="skin-card-name"><?= View::e($skin['name']) ?></span>
                  <span class="skin-card-desc"><?= View::e($skin['desc']) ?></span>
                  <span class="skin-card-modes">
                    <span>Light: <?= View::e($skin['light']) ?></span>
                    <span>Dark: <?= View::e($skin['dark']) ?></span>
                  </span>
                  <?php if ($activeSkin === $slug): ?>
                    <span class="skin-card-active-badge"><?= View::icon('check') ?> Active</span>
                  <?php endif; ?>
                </button>
              </form>
            <?php endforeach; ?>

            <?php foreach (($community_skins ?? []) as $slug): ?>
              <form method="POST" action="<?= $b ?>/admin/settings/skin" class="action-form-inline">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="skin" value="<?= View::e($slug) ?>">
                <button type="submit" class="skin-card<?= $activeSkin === $slug ? ' skin-card--active' : '' ?>">
                  <span class="skin-swatch skin-swatch--community">
                    <span class="skin-swatch-label"><?= View::e(strtoupper(substr($slug, 0, 2))) ?></span>
                  </span>
                  <span class="skin-card-name"><?= View::e(ucwords(str_replace('-', ' ', $slug))) ?></span>
                  <span class="skin-card-desc">Community skin</span>
                  <?php if ($activeSkin === $slug): ?>
                    <span class="skin-card-active-badge"><?= View::icon('check') ?> Active</span>
                  <?php endif; ?>
                </button>
              </form>
            <?php endforeach; ?>
          </div>
        
          <?php if (!SkinCatalog::isBundled($activeSkin)): ?>
          <?php $activeSkinHash = $site_settings['selected_skin_hash'] ?? null; ?>
          <div class="callout callout-warn" style="margin-top:1rem">
            <?= View::icon('alert') ?>
            <div>
              <strong>Community skin active.</strong>
              Community skin CSS files run on every admin page and are treated as trusted code.
              Only activate skins from authors you control or explicitly trust.
              <?php if ($activeSkinHash !== null): ?>
                This fingerprint helps you confirm the file has not changed since you activated it.
                <br><code class="text-xs link-break"><?= View::e($activeSkinHash) ?></code>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>


    <form id="settings-test-email-form" method="POST" action="<?= $b ?>/admin/settings/test-email" class="is-hidden">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
    </form>

<!-- ──── PANEL: GENERAL ─────────────────────────────────────────────── -->
    <form method="POST" action="<?= $b ?>/admin/settings" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <div id="panel-general" class="mini-nav-panel">
        <div class="card card--gradient">
          <div class="panel-header">
            <div class="panel-header-icon panel-header-icon-filled">
            <?= View::icon('general') ?>
            </div>
            <div class="panel-header-meta">
              <div class="panel-header-title">General</div>
              <div class="panel-header-sub">Core site identity and display preferences.</div>
            </div>
          </div>

          <div class="card-body">
            <div class="section-label">Site Information</div>
            <div class="field-group<?= !empty($fe['site_name']) ? ' has-error' : '' ?>">
              <label class="field-label" for="s-site-name">Site Name <span class="req">*</span></label>
              <input id="s-site-name" type="text" name="site_name" class="form"
                     maxlength="100" required
                     value="<?= View::e(cfgVal($cfg, 'site_name', 'Luster')) ?>">
              <p class="form-hint">Your collective's name. Luster also uses this in outgoing emails and admin page titles.</p>
              <?php if (!empty($fe['site_name'])): ?><p class="field-error"><?= View::e($fe['site_name'][0]) ?></p><?php endif; ?>
            </div>
            <div class="field-group<?= !empty($fe['site_url']) ? ' has-error' : '' ?>">
              <label class="field-label" for="s-site-url">Collective Public URL <span class="req">*</span></label>
              <input id="s-site-url" type="url" name="site_url" class="form" maxlength="500" required
                     value="<?= View::e(cfgVal($cfg, 'site_url')) ?>">
              <p class="form-hint">Your collective's public homepage URL, without a trailing slash. Luster uses this for the &ldquo;Visit site&rdquo; admin link and as a fallback for embedded forms.</p>
              <?php if (!empty($fe['site_url'])): ?><p class="field-error"><?= View::e($fe['site_url'][0]) ?></p><?php endif; ?>
            </div>
          </div>

          <div class="card-body">
            <div class="section-label">Owner Information</div>
            <div class="field-grid">
              <div class="field-group">
                <label class="field-label" for="s-owner-name">Owner Name</label>
                <input id="s-owner-name" type="text" name="owner_name" class="form"
                       maxlength="100"
                       value="<?= View::e(cfgVal($cfg, 'owner_name')) ?>">
                <p class="form-hint">The public owner name used in email templates and messages.</p>
              </div>
              <div class="field-group">
                <label class="field-label" for="s-owner-email">Contact Email</label>
                <input id="s-owner-email" type="email" name="owner_email" class="form" maxlength="255"
                       value="<?= View::e(cfgVal($cfg, 'owner_email')) ?>">
                <p class="form-hint">The public contact email used for outgoing messages. This can be different from your admin login email.</p>
              </div>
            </div>
          </div>

          <div class="card-body">
            <div class="section-label">Display & Locale</div>
            <div class="field-grid field-grid--3">
              <div class="field-group">
                <label class="field-label" for="s-timezone">Timezone</label>
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
                  <select id="s-timezone" name="timezone">
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
              <div class="field-group">
                <label class="field-label" for="s-date-format">Date Format</label>
                <?php
                  $dateFmtFormats = ['j M Y', 'M j, Y', 'F j, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'm-d-Y'];
                  $dateFmtOptions = array_combine($dateFmtFormats, array_map(static fn($f) => date($f), $dateFmtFormats));
                  $curDateFmt = $admin_date_format ?? 'j M Y';
                ?>
                <div class="select-wrap">
                  <select id="s-date-format" name="date_format">
                    <?php foreach ($dateFmtOptions as $fmt => $example): ?>
                      <option value="<?= View::e($fmt) ?>"<?= $fmt === $curDateFmt ? ' selected' : '' ?>><?= View::e($example) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <p class="form-hint">Choose how dates look in admin tables and activity screens.</p>
              </div>
              <div class="field-group">
                <label class="field-label" for="s-time-format">Time Format</label>
                <?php
                  $timeFmtFormats = ['H:i', 'g:i A', 'g:i a'];
                  $timeFmtOptions = array_combine($timeFmtFormats, array_map(static fn($f) => date($f), $timeFmtFormats));
                  $curTimeFmt = $admin_time_format ?? 'H:i';
                ?>
                <div class="select-wrap">
                  <select id="s-time-format" name="time_format">
                    <?php foreach ($timeFmtOptions as $fmt => $example): ?>
                      <option value="<?= View::e($fmt) ?>"<?= $fmt === $curTimeFmt ? ' selected' : '' ?>><?= View::e($example) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <p class="form-hint">Choose 24-hour time or 12-hour AM/PM time.</p>
              </div>
            </div>
          </div>

          <div class="composer-footer">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <button type="button" class="btn btn-ghost" id="general-clear-btn">Clear</button>
          </div>
        </div>
      </div>
    </form>

<!-- ──── PANEL: SMTP / EMAIL ────────────────────────────────────────── -->
    <form method="POST" action="<?= $b ?>/admin/settings/smtp" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <div id="panel-smtp" class="mini-nav-panel">
        <div class="card card--gradient">
          <div class="panel-header">
            <div class="panel-header-icon panel-header-icon-filled">
            <?= View::icon('email') ?>
            </div>
            <div class="panel-header-meta">
              <div class="panel-header-title">SMTP &amp; Email</div>
              <div class="panel-header-sub">Outgoing mail delivery and message sender details.</div>
            </div>
          </div>

          <div class="card-body">
            <div class="section-label">SMTP Email</div>
            <div class="field-group">
              <label class="toggle-wrap">
                <input type="checkbox" name="smtp_enabled" value="1"
                       <?= (cfgVal($cfg, 'smtp_enabled') === '1') ? 'checked' : '' ?>>
                <span class="toggle-label">Send email via SMTP</span>
              </label>
              <p class="form-hint">Turn this on when you want Luster to send mail through a real email account or email service. Leave it off only if your web host's built-in PHP mail sending is already reliable.</p>
            </div>
            <div class="field-grid">
              <div class="field-group">
                <label class="field-label" for="s-smtp-host">SMTP Host</label>
                <input id="s-smtp-host" type="text" name="smtp_host" class="form"
                       maxlength="255" placeholder="e.g. smtp.gmail.com"
                       value="<?= View::e(cfgVal($cfg, 'smtp_host')) ?>">
                <p class="form-hint">The mail server address from your email provider.</p>
              </div>
              <div class="field-group">
                <label class="field-label" for="s-smtp-port">Port</label>
                <input id="s-smtp-port" type="number" name="smtp_port" class="form form--narrow"
                       min="1" max="65535"
                       value="<?= View::e((string) (int) ($cfg['smtp_port'] ?? 587)) ?>">
                <p class="form-hint">587 for TLS, 465 for SSL.</p>
              </div>
            </div>
            <div class="field-grid">
              <div class="field-group">
                <label class="field-label" for="s-smtp-user">SMTP Username</label>
                <input id="s-smtp-user" type="text" name="smtp_user" class="form"
                       maxlength="255" autocomplete="off"
                       value="<?= View::e(cfgVal($cfg, 'smtp_user')) ?>">
                <p class="form-hint">Usually your full email address, unless your provider says otherwise.</p>
              </div>
              <div class="field-group">
                <label class="field-label" for="s-smtp-pass">SMTP Password</label>
                <input id="s-smtp-pass" type="password" name="smtp_pass" class="form"
                       maxlength="255" autocomplete="new-password"
                       placeholder="<?= (cfgVal($cfg, 'smtp_pass') !== '') ? '(password stored - leave blank to keep)' : '' ?>">
                <p class="form-hint">Leave blank to keep the current stored password. Enter a new value to replace it.</p>
              </div>
            </div>
            <div class="field-group">
              <label class="field-label" for="s-smtp-secure">Encryption</label>
              <div class="select-wrap">
                <select id="s-smtp-secure" name="smtp_secure">
                  <?php
                    $curSecure = cfgVal($cfg, 'smtp_secure', 'tls');
                    $secureOpts = ['tls' => 'STARTTLS (port 587, recommended)', 'ssl' => 'SMTPS (port 465)', 'none' => 'None (not recommended)'];
                  ?>
                  <?php foreach ($secureOpts as $val => $label): ?>
                    <option value="<?= View::e($val) ?>"<?= $val === $curSecure ? ' selected' : '' ?>><?= View::e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <p class="form-hint">Use the encryption type your email provider lists for outgoing SMTP.</p>
            </div>
            <div class="field-grid">
              <div class="field-group">
                <label class="field-label" for="s-smtp-from">From Address</label>
                <input id="s-smtp-from" type="email" name="smtp_from" class="form"
                       maxlength="255" placeholder="Defaults to Contact Email"
                       value="<?= View::e(cfgVal($cfg, 'smtp_from')) ?>">
                <p class="form-hint">The sender email address recipients see. Leave blank to use the Contact Email above.</p>
              </div>
              <div class="field-group">
                <label class="field-label" for="s-smtp-from-name">From Name</label>
                <input id="s-smtp-from-name" type="text" name="smtp_from_name" class="form"
                       maxlength="100" placeholder="Defaults to Owner Name"
                       value="<?= View::e(cfgVal($cfg, 'smtp_from_name')) ?>">
                <p class="form-hint">The sender name recipients see. Leave blank to use Owner Name.</p>
              </div>
            </div>
          </div>

          <div class="card-body">
            <div class="section-label">Test Email</div>
              <p>Send a test message to your admin account email to confirm your mail settings are working. Sends to <strong><?= View::e($admin_user->email ?? '') ?></strong>.

              <?= (cfgVal($cfg, 'smtp_enabled') === '1') ? 'Will use SMTP (' . View::e(cfgVal($cfg, 'smtp_host')) . ').' : 'Will use your web host\'s built-in mail sending.' ?></p>
              
            <button type="submit" form="settings-test-email-form" class="btn btn-secondary btn-sm">Send Test Email</button>
            <?php if (($cfg['owner_email'] ?? '') === ''): ?>
              <p class="form-hint form-hint--warn" style="margin-top:.75rem">
                Please set a Contact Email under the "General" tab before sending a test email.
              </p>
            <?php endif; ?>
          </div>

          <div class="composer-footer">
            <button type="submit" class="btn btn-primary">Save Settings</button>
            <button type="button" class="btn btn-ghost" id="smtp-clear-btn">Clear</button>
          </div>
        </div>
      </div>
    </form>


<!-- ──── PANEL: EMBED & PATHS ───────────────────────────────────────── -->
    <form method="POST" action="<?= $b ?>/admin/settings/paths" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <div id="panel-embed" class="mini-nav-panel">
        <div class="card card--gradient">
          <div class="panel-header">
            <div class="panel-header-icon panel-header-icon-filled">
            <?= View::icon('paths') ?>
            </div>
            <div class="panel-header-meta">
              <div class="panel-header-title">Server Paths</div>
              <div class="panel-header-sub">Paths for embed codes and forms on external pages.</div>
            </div>
          </div>

          <div class="card-body">
            <div class="callout callout-warn"><?= View::icon('caution') ?> 
              <div>These settings tell copied embed snippets where Luster lives. Most sites can keep the detected values. Edit them only when embedded forms submit to the wrong place or link checks look for the wrong URL.</div>
            </div>
            
              <div class="field-row">
                <label class="field-label" for="s-install-path">Server Install Path</label>
                <div>
                  <?php $_installPath = cfgVal($cfg, 'install_path') ?: (rtrim(str_replace('\\', '/', LUSTER_BASE), '/') . '/'); ?>
                  <div class="input-action-row">
                    <input id="s-install-path" type="text" name="install_path" class="form" maxlength="500"
                           value="<?= View::e($_installPath) ?>" readonly>
                    <button type="button" class="btn btn-danger btn-sm path-edit-btn" data-target="s-install-path">Edit</button>
                  </div>
                  <p class="form-hint">The server folder where Luster is installed, ending with a slash. This is a disk path, not a web address. Only change it if copied embed snippets cannot find Luster.</p>
                </div>
              </div>
              <div class="field-row">
                <label class="field-label" for="s-embed-url">Embed Form Submission URL</label>
                <div>
                  <?php $_embedBaseUrl = cfgVal($cfg, 'embed_base_url') ?: rtrim(cfgVal($cfg, 'app_url'), '/'); ?>
                  <div class="input-action-row">
                    <input id="s-embed-url" type="url" name="embed_base_url" class="form" maxlength="500"
                           value="<?= View::e($_embedBaseUrl) ?>" readonly>
                    <button type="button" class="btn btn-danger btn-sm path-edit-btn" data-target="s-embed-url">Edit</button>
                  </div>
                  <p class="form-hint">The web address embedded forms submit to, without a trailing slash. Change this only if your public fanlisting pages live on a different domain or folder than the Luster admin app.</p>
                </div>
              </div>
              <div class="field-row">
                <label class="field-label" for="s-reciprocal-url">Reciprocal Link Check URL</label>
                <div>
                  <?php $_reciprocalUrl = cfgVal($cfg, 'reciprocal_url') ?: rtrim(cfgVal($cfg, 'app_url'), '/'); ?>
                  <div class="input-action-row">
                    <input id="s-reciprocal-url" type="url" name="reciprocal_url" class="form" maxlength="500"
                           value="<?= View::e($_reciprocalUrl) ?>" readonly>
                    <button type="button" class="btn btn-danger btn-sm path-edit-btn" data-target="s-reciprocal-url">Edit</button>
                  </div>
                  <p class="form-hint">The public URL affiliates should link back to, without a trailing slash. The Link Checker looks for this address on affiliate sites.</p>
                </div>
              </div>
          </div>
        </div>
      </div>
    </form>


<!-- ──── PANEL: NOTIFICATIONS ────────────────────────────────────── -->
    <div id="panel-notifications" class="mini-nav-panel">
      <div class="card card--gradient">
        <div class="panel-header">
          <div class="panel-header-icon panel-header-icon-filled">
            <?= View::icon('email') ?>
          </div>
          <div class="panel-header-meta">
            <div class="panel-header-title">Notifications</div>
            <div class="panel-header-sub">Control which automated email alerts Luster sends to administrators.</div>
          </div>
        </div>

        <form method="POST" action="<?= $b ?>/admin/settings/notifications">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

          <div class="card-body">
            <div class="section-label">Admin Alerts</div>

            <div class="field-row">
              <label class="field-label" for="notif-update-email">Luster Updates</label>
              <div>
                <label class="toggle-wrap">
                  <input type="hidden" name="update_email_notify" value="0">
                  <input type="checkbox" id="notif-update-email" name="update_email_notify" value="1"
                         <?= ($site_settings['update_email_notify'] ?? '1') === '1' ? 'checked' : '' ?>>
                  <span class="toggle-label">Email me when a new Luster update is available</span>
                </label>
                <p class="form-hint">Sends one email when an update is detected. Requires a working mail configuration above.</p>
              </div>
            </div>

            <div class="field-row">
              <label class="field-label" for="notif-pending-digest">Pending Digest</label>
              <div>
                <label class="toggle-wrap">
                  <input type="hidden" name="pending_digest_enabled" value="0">
                  <input type="checkbox" id="notif-pending-digest" name="pending_digest_enabled" value="1"
                         <?= ($site_settings['pending_digest_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                  <span class="toggle-label">Send a weekly summary of all pending items</span>
                </label>
                <p class="form-hint">Sends at most one digest per week. Fires automatically when you load an admin page &mdash; no cron job required.</p>
              </div>
            </div>
          </div>

          <div class="card-body">
            <div class="section-label">Overdue Notifications</div>

            <div class="field-row">
              <label class="field-label" for="notif-stale-days">Overdue Warning (days)</label>
              <div>
                <input id="notif-stale-days" type="number" name="stale_days" class="form form--compact"
                       min="1" max="365"
                       value="<?= View::e($site_settings['stale_days'] ?? '45') ?>">
                <p class="form-hint">Show a dashboard warning when this many days pass without marking a listing updated. Default: 45.</p>
              </div>
            </div>

            <div class="field-row">
              <label class="field-label" for="notif-overdue-email">Overdue Email</label>
              <div>
                <label class="toggle-wrap">
                  <input type="hidden" name="overdue_notify_enabled" value="0">
                  <input type="checkbox" id="notif-overdue-email" name="overdue_notify_enabled" value="1"
                         <?= ($site_settings['overdue_notify_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                  <span class="toggle-label">Email me when listings haven't been updated in N days</span>
                </label>
                <p class="form-hint">Sends at most one reminder per 24 hours. Fires automatically when you load an admin page &mdash; no cron job required.</p>
              </div>
            </div>

            <div class="field-row">
              <label class="field-label" for="notif-overdue-days">Days Until Overdue Email</label>
              <div>
                <input id="notif-overdue-days" type="number" name="overdue_days" class="form form--compact"
                       min="1" max="365"
                       value="<?= View::e($site_settings['overdue_days'] ?? '55') ?>">
                <p class="form-hint">Send the reminder when this many days pass without an update. Default: 55. TFL guidelines recommend 55 days.</p>
              </div>
            </div>

          <?php
            $lastSentTs = (int) ($site_settings['overdue_notify_last_sent'] ?? '0');
            if ($lastSentTs > 0) {
                $nfDatePart = date($admin_date_format ?? 'j M Y', $lastSentTs);
                $nfTimePart = date($admin_time_format ?? 'H:i', $lastSentTs);
                $nfLastSent = $nfDatePart . ' at ' . $nfTimePart;
            } else {
                $nfLastSent = 'Never';
            }
          ?>

            <div class="field-row">
              <label class="field-label">Last Sent</label>
              <div>
                <p class="form-hint">Last notification sent: <strong><?= View::e($nfLastSent) ?></strong></p>
              </div>
            </div>

            <div class="field-row">
              <label class="field-label">Send Now</label>
              <div>
                <button type="button" class="btn btn-quaternary btn-sm"
                        onclick="document.getElementById('overdue-now-form').requestSubmit()">
                  <?= View::icon('email') ?> Send overdue notification now
                </button>
                <p class="form-hint" style="margin-top:.5rem">Bypasses the 24-hour window and immediately sends the overdue notification if any listings are due.</p>
              </div>
            </div>
          </div>

          <div class="composer-footer">
            <button type="submit" class="btn btn-primary">Save Notification Settings</button>
            <button type="button" id="notifications-clear-btn" class="btn btn-ghost">Clear</button>
          </div>
        </form>

        <form id="overdue-now-form" method="POST"
              action="<?= $b ?>/admin/listings/settings/send-overdue-now" style="display:none">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        </form>
      </div>
    </div>


<!-- ──── PANEL: BLACKLIST ───────────────────────────────────────────── -->
    <div id="panel-blacklist" class="mini-nav-panel">
      <div class="card card--gradient">
        <div class="panel-header">
          <div class="panel-header-icon panel-header-icon-filled">
            <?= View::icon('blacklist') ?>
          </div>
          <div class="panel-header-meta">
            <div class="panel-header-title">Spam Filtering</div>
            <div class="panel-header-sub">Block specific email addresses or whole email domains from public forms.</div>
          </div>
        </div>

        <form method="POST" action="<?= $b ?>/admin/blacklist/add">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

          <div class="card-body">
            <div class="field-grid">
              <div class="field-group">
                <label class="field-label" for="bl-pattern">Email Pattern <span class="req">*</span></label>
                <input id="bl-pattern" type="text" name="pattern" class="form" maxlength="255" required
                       placeholder="spam@example.com  or  @spam.com">
                <p class="form-hint">Use a full email address to block one person, or start with <code>@</code> to block everyone from that email domain.</p>
              </div>
              <div class="field-group">
                <label class="field-label" for="bl-note">Note <em class="opt">(optional)</em></label>
                <input id="bl-note" type="text" name="note" class="form"
                       maxlength="500" placeholder="Why this address was blocked">
                <p class="form-hint">Internal note &mdash; not shown to visitors.</p>
              </div>
            </div>
          </div>

          <div class="composer-footer">
            <button type="submit" class="btn btn-primary">Add Filter Rule</button>
            <button type="reset" class="btn btn-ghost">Clear</button>
          </div>
        </form>
      </div>

      <?php if (empty($blacklist_entries)): ?>
        <div class="card empty">
          <?= View::icon('blacklist', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
          <div><strong>No spam filter patterns found.</strong></div>
          <div>Add email addresses or domains above to prevent them from joining.</div>
        </div>
      <?php else: ?>
        <div class="card">
          <div class="card-head">
            <span>Blocked Patterns</span>
            <span class="card-head-sub">&mdash; <?= count($blacklist_entries) ?> entr<?= count($blacklist_entries) === 1 ? 'y' : 'ies' ?></span>
          </div>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Pattern</th>
                  <th>Note</th>
                  <th class="actions-col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($blacklist_entries as $entry): ?>
                <tr>
                  <td><code><?= View::e($entry['pattern']) ?></code></td>
                  <td><?= $entry['note'] !== '' ? View::e($entry['note']) : '&mdash;' ?></td>
                  <td class="actions-col">
                    <form method="POST"
                          action="<?= $b ?>/admin/blacklist/<?= (int) $entry['id'] ?>/delete"
                          class="action-form-inline"
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


<!-- ──── PANEL: SYSTEM ──────────────────────────────────────────────── -->
    <div id="panel-system" class="mini-nav-panel">
      <div class="card card--gradient">
        <div class="panel-header">
          <div class="panel-header-icon panel-header-icon-filled">
            <?= View::icon('wrench') ?>
          </div>
          <div class="panel-header-meta">
            <div class="panel-header-title">System Details</div>
            <div class="panel-header-sub">Version, server, and database details useful when troubleshooting.</div>
          </div>
        </div>

        <?php if (($htaccess_base_actual ?? null) !== null): ?>
        <div class="callout callout-warn" style="margin:1.25rem 1.5rem 0">
          <?= View::icon('caution') ?>
          <div>
            <strong>RewriteBase mismatch.</strong>
            Your <code>public/.htaccess</code> has
            <code>RewriteBase <?= View::e($htaccess_base_actual) ?></code>
            but your install URL suggests it should be
            <code>RewriteBase <?= View::e($htaccess_base_expected) ?></code>.
            Clean admin URLs may 404. Open <code>public/.htaccess</code>,
            find the <code>RewriteBase</code> line, and update it.
          </div>
        </div>
        <?php endif; ?>



        <!-- Script -->
        <div class="card-body">
          <div class="section-label">Script</div>
            <div class="sysinfo-row">
              <span class="field-label">Name</span> Luster
            </div>

          <?php
            $us          = $update_status;
            $checkedFmt  = ($admin_date_format ?? 'j M Y') . ', ' . ($admin_time_format ?? 'H:i');
            $checkedStr  = $us->checkedAt !== null
                ? date($checkedFmt, $us->checkedAt)
                : null;
          ?>

          <div class="sysinfo-row">
            <span class="field-label">Installed Version</span>
            <?= View::e(Version::CURRENT) ?>
          </div>

          <?php if (!$us->neverChecked): ?>
          <div class="sysinfo-row">
            <span class="field-label">Latest Version</span>
            <?php if ($us->latest !== null): ?>
              <?= View::e($us->latest) ?>
            <?php elseif (!$us->checkFailed): ?>
              No releases published yet
            <?php else: ?>
              Unknown
            <?php endif; ?>
          </div>
          <div class="sysinfo-row">
            <span class="field-label">Release Status</span>
            <span class="status-inline">
              <?php if ($us->checkFailed): ?>
                <span class="badge badge-inactive">Check failed</span>
                <?php if ($us->error !== null): ?>
                  <?= View::e($us->error) ?>
                <?php endif; ?>
              <?php elseif ($us->latest === null): ?>
                <span class="badge badge-inactive">No releases yet</span>
              <?php elseif ($us->updateAvailable): ?>
                <span class="badge badge-pending">Update available</span>
                <a href="<?= $b ?>/admin/update" class="btn btn-download btn-sm">
                  Prepare Update &rarr;
                </a>
              <?php else: ?>
                <span class="badge badge-current">Up to date</span>
              <?php endif; ?>
            </span>
          </div>
          <?php endif; ?>
          <div class="sysinfo-row">
            <span class="field-label">Last Checked</span>
            <span style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
              <?php if (!$us->neverChecked): ?>
                <?= View::e($checkedStr ?? '') ?>
              <?php endif; ?>
              <button type="button" id="update-check-btn" class="btn btn-secondary btn-xs"
                      data-url="<?= View::e($b) ?>/admin/settings/check-update"
                      data-csrf="<?= View::e($csrf_token ?? '') ?>">
                <?= View::icon('refresh') ?> Check for Updates
              </button>
              <span id="update-check-result" style="font-size:var(--fs-sm)">
                <?php if ($us->neverChecked): ?>
                  <span class="text-dim">No update check has been run yet</span>
                <?php endif; ?>
              </span>
            </span>
          </div>
        </div>

        <!-- Server -->
        <div class="card-body">
          <div class="section-label">Server</div>
            <div class="sysinfo-row">
              <span class="field-label">PHP Version</span> <?= View::e($php_version) ?>
            </div>
            <div class="sysinfo-row">
              <span class="field-label">Database</span> <?= View::e($db_version) ?>
            </div>
            <?php $spd = $session_path_diagnostics ?? null; ?>
            <?php if ($spd !== null): ?>
            <div class="sysinfo-row">
              <span class="field-label">Session Storage</span>
              <span class="status-inline">
                <?php if ($spd['usedFallback']): ?>
                  <span class="badge badge-pending">Fallback in use</span>
                <?php else: ?>
                  <span class="badge badge-current">Default path</span>
                <?php endif; ?>
                <span class="text-mono text-dim"><?= View::e($spd['path']) ?></span>
              </span>
              <?php if ($spd['usedFallback']): ?>
                <p class="form-hint">
                  Your server's configured session storage location was not writable, so Luster
                  is using a fallback location instead. Logins and member sessions still work
                  normally, but consider asking your host to fix write permissions on the
                  default <code>session.save_path</code>.
                </p>
              <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Database Connection -->
        <div class="card-body">
          <div class="section-label">
            Database Connection
          </div>
          <p class="text-dim" style="margin-bottom:1.25rem">
            These values come from <code>config/app.php</code>. Luster shows them here so you can verify what database this install is using.
          </p>
            <div class="sysinfo-row">
              <span class="field-label">Host</span> <?= View::e(cfgVal($cfg, 'db_host', 'localhost')) ?>
            </div>
            <div class="sysinfo-row">
              <span class="field-label">Database</span> <?= View::e(cfgVal($cfg, 'db_name')) ?>
            </div>
            <div class="sysinfo-row">
              <span class="field-label">User</span> <?= View::e(cfgVal($cfg, 'db_user')) ?>
            </div>
            <div class="sysinfo-row">
              <span class="field-label">Debug</span>
              <span class="status-inline">
                <?php if ($cfg['app_debug'] ?? false): ?>
                  <span class="badge badge-error">Enabled</span>
                  <span class="text-dim">Shows extra error details. Turn off on a live public site.</span>
                <?php else: ?>
                  <span class="badge badge-inactive">Disabled</span>
                <?php endif; ?>
              </span>
            </div>
            <div class="sysinfo-row">
              <span class="field-label">Install URL</span>
              <span class="text-mono text-dim"><?= View::e(cfgVal($cfg, 'app_url')) ?></span>
            </div>
            <p class="form-hint">The install URL is where the Luster app itself lives. Your public collective URL can be different.</p>
        </div>
      </div>
    </div>
  </div>
</div>
