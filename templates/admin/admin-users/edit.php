<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var \Luster\Models\AdminUser $user
 * @var string                   $error
 * @var string                   $base_url
 * @var string                   $csrf_token
 */

$b = rtrim(View::e($base_url ?? ''), '/');
?>

<p style="margin:0 0 .75rem">
  <a href="<?= $b ?>/admin/settings" class="neb-link">&larr; Back to Settings</a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="settings-card">
  <form method="POST" action="<?= $b ?>/admin/admin-users/<?= (int) $user->id ?>/edit">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label class="form-label" for="au-username">Username <span>*</span></label>
      <input id="au-username" type="text" name="username" class="form-input"
             maxlength="50" required autocomplete="off"
             value="<?= View::e($user->username) ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="au-email">Email Address <span>*</span></label>
      <input id="au-email" type="email" name="email" class="form-input"
             maxlength="255" required autocomplete="off"
             value="<?= View::e($user->email) ?>">
    </div>

    <div class="section-divider"></div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="au-pass">New Password</label>
        <input id="au-pass" type="password" name="new_password" class="form-input"
               maxlength="255" autocomplete="new-password"
               placeholder="Leave blank to keep current">
        <p class="form-hint">Minimum 8 characters.</p>
      </div>
      <div class="form-group">
        <label class="form-label" for="au-confirm">Confirm New Password</label>
        <input id="au-confirm" type="password" name="new_password_confirm" class="form-input"
               maxlength="255" autocomplete="new-password"
               placeholder="Repeat new password">
      </div>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/settings" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
