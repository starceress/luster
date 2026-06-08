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
 *   $f            array<string,string>  keys: new_password, new_password_confirm
 *   $base_url     string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>
<div class="fl-embed fl-embed--kim-reset">
  <div class="fl-kim">
    <h2 class="fl-kim-heading">KIM List &mdash; Set New Password</h2>

    <?php if (!$token_valid): ?>
      <div class="fl-errors">
        <?php if ($errors !== ''): ?>
          <?= $errors ?>
        <?php else: ?>
          <p>This reset link has expired or is invalid. Please request a new one.</p>
        <?php endif; ?>
      </div>
      <p class="fl-kim-meta">
        <a href="<?= $b ?>/kim/reset">Request a new password reset link</a>
      </p>

    <?php else: ?>

      <?php if ($errors !== ''): ?>
        <div class="fl-errors"><?= $errors ?></div>
      <?php endif; ?>

      <form class="fl-form" method="POST" action="<?= View::e($form_action) ?>">
        <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="token" value="<?= View::e($token) ?>">

        <?= $f['new_password'] ?>
        <?= $f['new_password_confirm'] ?>

        <div class="fl-form-actions">
          <button class="fl-submit fl-btn" type="submit">Set New Password</button>
        </div>
      </form>

    <?php endif; ?>
  </div>
</div>
