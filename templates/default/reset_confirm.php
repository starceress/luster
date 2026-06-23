<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Password reset confirmation content fragment (step 2 of 2). Validates the
 reset token from the emailed link and presents a new-password form. Shows an
 expired/invalid message with a "request a new one" link when the token is no
 longer valid. Served as a full page via View::renderPublic() or as an
 embeddable fragment via View::renderFragment() from embed/reset.php.
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
