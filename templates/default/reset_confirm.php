<?php
/**
 * Password reset confirmation — new password form fragment.
 *
 * Variables injected by ResetController:
 *   $fanlisting_name  string
 *   $form_action      string
 *   $csrf_token       string
 *   $errors           string  pre-escaped HTML error string, or ''
 *   $token            string  raw reset token (placed in hidden field)
 *   $token_valid      bool    false = show expired/invalid message, no form
 *   $reset_url        string  URL of the email-entry form (for "request a new one" link)
 *   $base_url         string
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div class="fl-wrap">
  <h2 class="fl-title">Set a new password for <?= View::e($fanlisting_name) ?></h2>

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <?php if (!$token_valid): ?>
    <p>
      This reset link has expired or is invalid.
      <a class="fl-link" href="<?= View::e($reset_url) ?>">Request a new one.</a>
    </p>
  <?php else: ?>
    <p class="fl-muted" style="font-size:.875rem">
      Choose a new password. It must be at least 8 characters long.
    </p>

    <form method="POST" action="<?= View::e($form_action) ?>">
      <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
      <input type="hidden" name="token"  value="<?= View::e($token) ?>">

      <div class="fl-field">
        <label class="fl-label" for="rc-password">New Password <span class="fl-required">*</span></label>
        <input class="fl-input" type="password" id="rc-password" name="new_password"
               requiredmaxlength="255" autocomplete="new-password">
      </div>

      <div class="fl-field">
        <label class="fl-label" for="rc-confirm">Confirm New Password <span class="fl-required">*</span></label>
        <input class="fl-input" type="password" id="rc-confirm" name="new_password_confirm"
               requiredmaxlength="255" autocomplete="new-password">
      </div>

      <button class="fl-btn" type="submit">Update Password</button>
    </form>
  <?php endif; ?>
</div>
