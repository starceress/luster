<?php
/**
 * Password reset — email entry form fragment.
 *
 * Variables injected by ResetController / embed/reset.php:
 *   $form_action  string
 *   $csrf_token   string
 *   $errors       string  pre-escaped HTML error block, or ''
 *   $f            array<string,string>  keys: 'email', 'captcha_answer'
 *   $base_url     string
 */

declare(strict_types=1);

use Luster\Core\View;

if (isset($form_html)) {
    ?>
<div class="fl-embed fl-embed--reset">
  <?php if ($errors !== ''): ?><div class="fl-errors"><?= $errors ?></div><?php endif; ?>
  <?= $form_html ?>
</div>
    <?php
    return;
}
?>
<div class="fl-embed fl-embed--reset">
  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <p class="fl-hint">
    If you have lost or forgotten your password, enter the email address you joined with below and a reset link will be sent to you. For your security, reset links expire after 1 hour.
  </p>

  <form class="fl-form" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <?= $f['email'] ?>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <?= $f['captcha_answer'] ?>

    <div class="fl-form-actions">
      <button type="submit" class="fl-submit">Send Reset Link</button>
    </div>
  </form>
</div>
