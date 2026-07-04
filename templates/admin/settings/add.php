<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin user add form. Provides fields for creating a new admin account,
 including username, email, display name, and password.
*/

declare(strict_types=1);

use Luster\Core\View;

$b  = rtrim(View::e($base_url ?? ''), '/');
$fe = $field_errors ?? [];
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/settings#panel-accounts" class="link">&larr; Back to Settings</a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/admin-users/add" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('admins') ?></div>
        <div class="panel-header-meta">
        <div class="panel-header-title">Add New Admin Account</div>
        <div class="panel-header-sub">Grant admin panel access to a new user.</div>
      </div>
    </div>

    <div class="card-body">
      <div class="section-label">Account Details</div>

      <div class="field-row<?= !empty($fe['username']) ? ' has-error' : '' ?>">
        <label class="field-label" for="au-username">Username <span class="req">*</span></label>
        <div>
          <input id="au-username" type="text" name="username" class="form"
                 maxlength="50" required autocomplete="off"
                 value="<?= View::e((string) ($old['username'] ?? '')) ?>">
          <p class="form-hint">The name this admin will type on the login screen.</p>
          <?php if (!empty($fe['username'])): ?><p class="field-error"><?= View::e($fe['username'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['email']) ? ' has-error' : '' ?>">
        <label class="field-label" for="au-email">Email Address <span class="req">*</span></label>
        <div>
          <input id="au-email" type="email" name="email" class="form"
                 maxlength="255" required autocomplete="off"
                 value="<?= View::e((string) ($old['email'] ?? '')) ?>">
          <p class="form-hint">Used for admin account messages, including admin password resets.</p>
          <?php if (!empty($fe['email'])): ?><p class="field-error"><?= View::e($fe['email'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="au-owner-name">Display Name <span class="req">*</span></label>
        <div>
          <input id="au-owner-name" type="text" name="display_name" class="form"
                 maxlength="100" required autocomplete="off"
                 value="<?= View::e((string) ($old['display_name'] ?? '')) ?>">
          <p class="form-hint">The friendly name shown inside the admin panel for this account.</p>
        </div>
      </div>

      <div class="section-label">Password</div>

      <div class="field-row<?= !empty($fe['password']) ? ' has-error' : '' ?>">
        <label class="field-label" for="au-pass">Password <span class="req">*</span></label>
        <div>
          <div class="input-icon-wrap">
            <input id="au-pass" type="password" name="password" class="form"
                   maxlength="255" required autocomplete="new-password">
            <button type="button" class="input-icon-btn" data-pw-toggle aria-label="Show password" tabindex="-1">
              <?= View::icon('eye-on') ?>
              <?= View::icon('eye-off') ?>
            </button>
          </div>
          <p class="form-hint">Minimum 12 characters. The new admin can change this after logging in.</p>
          <?php if (!empty($fe['password'])): ?><p class="field-error"><?= View::e($fe['password'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['password_confirm']) ? ' has-error' : '' ?>">
        <label class="field-label" for="au-confirm">Confirm Password <span class="req">*</span></label>
        <div>
          <div class="input-icon-wrap">
            <input id="au-confirm" type="password" name="password_confirm" class="form"
                   maxlength="255" required autocomplete="new-password">
            <button type="button" class="input-icon-btn" data-pw-toggle aria-label="Show password" tabindex="-1">
              <?= View::icon('eye-on') ?>
              <?= View::icon('eye-off') ?>
            </button>
          </div>
          <p class="form-hint">Type the same password again so Luster can catch typos.</p>
          <?php if (!empty($fe['password_confirm'])): ?><p class="field-error"><?= View::e($fe['password_confirm'][0]) ?></p><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Create Account</button>
      <a href="<?= $b ?>/admin/settings#panel-accounts" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
