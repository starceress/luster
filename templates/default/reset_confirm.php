<?php
/**
 * Password reset confirmation — new password form fragment.
 *
 * Variables injected by ResetController / embed/reset.php (confirm step):
 *   $form_action  string
 *   $csrf_token   string
 *   $errors       string  pre-escaped HTML error block, or ''
 *   $token        string  raw reset token (placed in hidden field)
 *   $token_valid  bool    false = show expired/invalid message, no form
 *   $reset_url    string  URL of the email-entry form (for "request a new one" link)
 *   $f            array<string,string>  keys: 'new_password', 'new_password_confirm'
 *   $base_url     string
 */

declare(strict_types=1);

use Luster\Core\View;

if (isset($form_html)) {
    ?>
<?php if ($errors !== ''): ?><div class="fl-errors"><?= $errors ?></div><?php endif; ?>
<?= $form_html ?>
    <?php
    return;
}
?>
<?php if ($errors !== ''): ?>
  <div class="fl-errors"><?= $errors ?></div>
<?php endif; ?>

<?php if (!$token_valid): ?>
  <p class="fl-errors">
    This reset link has expired or is invalid.
    <a href="<?= View::e($reset_url) ?>">Request a new one.</a>
  </p>
<?php else: ?>
  <p class="fl-hint">Choose a new password. Minimum 12 characters.</p>

  <form class="fl-form fl-form-reset-confirm" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
    <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>
    <input type="hidden" name="token"  value="<?= View::e($token) ?>">

    <?= $f['new_password'] ?>
    <?= $f['new_password_confirm'] ?>

    <div class="fl-form-actions">
      <button type="submit" class="fl-submit">Update Password</button>
    </div>
  </form>
<?php endif; ?>
