<?php
/**
 * Password reset confirmation — new password form fragment.
 *
 * Variables injected by ResetController:
 *   $listing_name  string
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
<div>
  <?php if ($errors !== ''): ?>
    <div><?= $errors ?></div>
  <?php endif; ?>

  <?php if (!$token_valid): ?>
    <p>
      This reset link has expired or is invalid.
      <a href="<?= View::e($reset_url) ?>">Request a new one.</a>
    </p>
  <?php else: ?>
    <p class="fl-hint">
      Choose a new password.
    </p>

    <form method="POST" action="<?= View::e($form_action) ?>">
      <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
      <input type="hidden" name="token"  value="<?= View::e($token) ?>">

      <div>
        <label for="rc-password">New Password *</label>
        <input type="password" id="rc-password" name="new_password"
               required maxlength="255" autocomplete="new-password">
      </div>

      <div>
        <label for="rc-confirm">Confirm New Password *</label>
        <input type="password" id="rc-confirm" name="new_password_confirm"
               required maxlength="255" autocomplete="new-password">
      </div>

      <button type="submit">Update Password</button>
    </form>
  <?php endif; ?>
</div>
