<?php
/**
 * KIM List password reset — new password form.
 *
 * Variables:
 *   $form_action  string
 *   $csrf_token   string
 *   $errors       string  pre-escaped HTML error string, or ''
 *   $token        string  raw token (empty when invalid)
 *   $token_valid  bool
 *   $base_url     string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>
<div class="fl-wrap">
  <h2 class="fl-title">KIM List — Set New Password</h2>

  <?php if (!$token_valid): ?>
    <div class="fl-errors">
      <?php if ($errors !== ''): ?>
        <?= $errors ?>
      <?php else: ?>
        <p>This reset link has expired or is invalid. Please request a new one.</p>
      <?php endif; ?>
    </div>
    <p class="fl-meta">
      <a href="<?= $b ?>/kim/reset">Request a new password reset link</a>
    </p>

  <?php else: ?>

    <?php if ($errors !== ''): ?>
      <div class="fl-errors"><?= $errors ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= View::e($form_action) ?>">
      <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
      <input type="hidden" name="token" value="<?= View::e($token) ?>">

      <div class="fl-field">
        <label class="fl-label" for="kim-new-password">
          New Password <span class="fl-required">*</span>
        </label>
        <input class="fl-input" type="password" id="kim-new-password" name="new_password"
               required maxlength="255" autocomplete="new-password">
      </div>

      <div class="fl-field">
        <label class="fl-label" for="kim-new-password-confirm">
          Confirm New Password <span class="fl-required">*</span>
        </label>
        <input class="fl-input" type="password" id="kim-new-password-confirm" name="new_password_confirm"
               required maxlength="255" autocomplete="new-password">
      </div>

      <div class="fl-actions">
        <button class="fl-btn" type="submit">Set New Password</button>
      </div>
    </form>

  <?php endif; ?>
</div>
