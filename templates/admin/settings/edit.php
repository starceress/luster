<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin user edit form. Provides fields for updating an existing admin
 account's username, email, display name, and optionally resetting their
 password.
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

<form method="POST" action="<?= $b ?>/admin/admin-users/<?= (int) $user->id ?>/edit" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('admins') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Edit Admin Account</div>
        <div class="panel-header-sub">Update login details or set a new password for this admin.</div>
      </div>
    </div>

    <div class="card-body">

      <div class="field-row<?= !empty($fe['username']) ? ' has-error' : '' ?>">
        <label class="field-label" for="au-username">Username <span class="req">*</span></label>
        <div>
          <input id="au-username" type="text" name="username" class="form"
                 maxlength="50" required autocomplete="off"
                 value="<?= View::e($user->username) ?>">
          <?php if (!empty($fe['username'])): ?><p class="field-error"><?= View::e($fe['username'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['email']) ? ' has-error' : '' ?>">
        <label class="field-label" for="au-email">Email Address <span class="req">*</span></label>
        <div>
          <input id="au-email" type="email" name="email" class="form"
                 maxlength="255" required autocomplete="off"
                 value="<?= View::e($user->email) ?>">
          <?php if (!empty($fe['email'])): ?><p class="field-error"><?= View::e($fe['email'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="au-owner-name">Display Name <span class="req">*</span></label>
        <div>
          <input id="au-owner-name" type="text" name="display_name" class="form"
                 maxlength="100" required autocomplete="off"
                 value="<?= View::e($user->displayName ?? '') ?>">
          <p class="form-hint">The display name shown inside the admin panel for this account.</p>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['new_password']) ? ' has-error' : '' ?>">
        <label class="field-label" for="au-pass">New Password</label>
        <div>
          <div class="input-icon-wrap">
            <input id="au-pass" type="password" name="new_password" class="form"
                   maxlength="255" autocomplete="new-password"
                   placeholder="Leave blank to keep current password">
            <button type="button" class="input-icon-btn" data-pw-toggle aria-label="Show password" tabindex="-1">
              <?= View::icon('eye-on') ?>
              <?= View::icon('eye-off') ?>
            </button>
          </div>
          <p class="form-hint">Leave blank to keep your current password. New passwords must be at least 12 characters.</p>
          <?php if (!empty($fe['new_password'])): ?><p class="field-error"><?= View::e($fe['new_password'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['new_password_confirm']) ? ' has-error' : '' ?>">
        <label class="field-label" for="au-confirm">Confirm New Password</label>
        <div>
          <div class="input-icon-wrap">
            <input id="au-confirm" type="password" name="new_password_confirm" class="form"
                   maxlength="255" autocomplete="new-password"
                   placeholder="Retype new password">
            <button type="button" class="input-icon-btn" data-pw-toggle aria-label="Show password" tabindex="-1">
              <?= View::icon('eye-on') ?>
              <?= View::icon('eye-off') ?>
            </button>
          </div>
          <?php if (!empty($fe['new_password_confirm'])): ?><p class="field-error"><?= View::e($fe['new_password_confirm'][0]) ?></p><?php endif; ?>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/settings#panel-accounts" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
