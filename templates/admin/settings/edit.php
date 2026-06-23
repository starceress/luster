<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var \Luster\Models\AdminUser $user
 * @var string                   $error
 * @var array                    $field_errors
 * @var string                   $base_url
 * @var string                   $csrf_token
 */

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

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('admins') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Edit Admin Account</div>
        <div class="pc-head-sub">Update login details or set a new password for this admin.</div>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Account Details</div>

      <div class="listing-field-row<?= !empty($fe['username']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="au-username">Username <span class="req">*</span></label>
        <div>
          <input id="au-username" type="text" name="username" class="form"
                 maxlength="50" required autocomplete="off"
                 value="<?= View::e($user->username) ?>">
          <p class="form-hint">The name this admin types on the login screen.</p>
          <?php if (!empty($fe['username'])): ?><p class="field-error"><?= View::e($fe['username'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="listing-field-row<?= !empty($fe['email']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="au-email">Email Address <span class="req">*</span></label>
        <div>
          <input id="au-email" type="email" name="email" class="form"
                 maxlength="255" required autocomplete="off"
                 value="<?= View::e($user->email) ?>">
          <p class="form-hint">Used for admin account messages, including admin password resets.</p>
          <?php if (!empty($fe['email'])): ?><p class="field-error"><?= View::e($fe['email'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="au-owner-name">Display Name <span class="req">*</span></label>
        <div>
          <input id="au-owner-name" type="text" name="display_name" class="form"
                 maxlength="100" required autocomplete="off"
                 value="<?= View::e($user->displayName ?? '') ?>">
          <p class="form-hint">The friendly name shown inside the admin panel for this account.</p>
        </div>
      </div>

      <div class="pc-section-label">Password</div>

      <div class="listing-field-row listing-field-row--top<?= !empty($fe['new_password']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="au-pass">New Password</label>
        <div>
          <div class="input-icon-wrap">
            <input id="au-pass" type="password" name="new_password" class="form"
                   maxlength="255" autocomplete="new-password"
                   placeholder="Leave blank to keep current">
            <button type="button" class="input-icon-btn" data-pw-toggle aria-label="Show password" tabindex="-1">
              <?= View::icon('eye-on') ?>
              <?= View::icon('eye-off') ?>
            </button>
          </div>
          <p class="form-hint">Leave blank to keep the current password. If changing it, use at least 12 characters.</p>
          <?php if (!empty($fe['new_password'])): ?><p class="field-error"><?= View::e($fe['new_password'][0]) ?></p><?php endif; ?>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top<?= !empty($fe['new_password_confirm']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="au-confirm">Confirm New Password</label>
        <div>
          <div class="input-icon-wrap">
            <input id="au-confirm" type="password" name="new_password_confirm" class="form"
                   maxlength="255" autocomplete="new-password"
                   placeholder="Repeat new password">
            <button type="button" class="input-icon-btn" data-pw-toggle aria-label="Show password" tabindex="-1">
              <?= View::icon('eye-on') ?>
              <?= View::icon('eye-off') ?>
            </button>
          </div>
          <p class="form-hint">Type the same new password again so Luster can catch typos.</p>
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
