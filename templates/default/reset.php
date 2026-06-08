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
?>
<div class="fl-embed fl-embed--reset">
  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <p class="fl-hint">
    Enter your email address and we'll send you a link to reset your password.
    The link expires in 1 hour.
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
