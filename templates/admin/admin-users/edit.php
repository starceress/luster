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

<p class="page-back">
  <a href="<?= $b ?>/admin/settings" class="link">&larr; Back to Settings</a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card">
  <form method="POST" action="<?= $b ?>/admin/admin-users/<?= (int) $user->id ?>/edit" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="form-group<?= !empty($fe['username']) ? ' has-error' : '' ?>">
      <label class="form-label" for="au-username">Username <span>*</span></label>
      <input id="au-username" type="text" name="username" class="form-input"
             maxlength="50" required autocomplete="off"
             value="<?= View::e($user->username) ?>">
      <?php if (!empty($fe['username'])): ?><p class="field-error"><?= View::e($fe['username'][0]) ?></p><?php endif; ?>
    </div>

    <div class="form-group<?= !empty($fe['email']) ? ' has-error' : '' ?>">
      <label class="form-label" for="au-email">Email Address <span>*</span></label>
      <input id="au-email" type="email" name="email" class="form-input"
             maxlength="255" required autocomplete="off"
             value="<?= View::e($user->email) ?>">
      <?php if (!empty($fe['email'])): ?><p class="field-error"><?= View::e($fe['email'][0]) ?></p><?php endif; ?>
    </div>

    <div class="form-row">
      <div class="form-group<?= !empty($fe['new_password']) ? ' has-error' : '' ?>">
        <label class="form-label" for="au-pass">New Password</label>
        <div style="position:relative">
          <input id="au-pass" type="password" name="new_password" class="form-input"
                 style="padding-right:42px" maxlength="255" autocomplete="new-password"
                 placeholder="Leave blank to keep current">
          <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-dim);padding:4px;line-height:0"
                  onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-dim)'">
            <?= View::icon('eye-on') ?>
            <?= View::icon('eye-off') ?>
          </button>
        </div>
        <p class="form-hint">Minimum 12 characters.</p>
        <?php if (!empty($fe['new_password'])): ?><p class="field-error"><?= View::e($fe['new_password'][0]) ?></p><?php endif; ?>
      </div>
      <div class="form-group<?= !empty($fe['new_password_confirm']) ? ' has-error' : '' ?>">
        <label class="form-label" for="au-confirm">Confirm New Password</label>
        <div class="input-icon-wrap">
          <input id="au-confirm" type="password" name="new_password_confirm" class="form-input"
                 maxlength="255" autocomplete="new-password"
                 placeholder="Repeat new password">
          <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
            <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
        <?php if (!empty($fe['new_password_confirm'])): ?><p class="field-error"><?= View::e($fe['new_password_confirm'][0]) ?></p><?php endif; ?>
      </div>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/settings" class="btn btn-quaternary">Cancel</a>
    </div>
  </form>
</div>
