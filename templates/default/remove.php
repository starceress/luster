<?php
/**
 * Remove membership form — content fragment.
 *
 * Variables injected by RemoveController / embed/remove.php:
 *   $form_action       string
 *   $csrf_token        string
 *   $errors            string  pre-escaped HTML error block, or ''
 *   $lost_password_url string  URL to the reset page, or '' to hide the link
 *   $f                 array<string,string>  keys: 'email', 'password', 'captcha_answer'
 *   $base_url          string
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div>
  <?php if ($errors !== ''): ?>
    <div><?= $errors ?></div>
  <?php endif; ?>

  <p class="fl-hint">
    Enter your email address and password to permanently remove your membership.
    This action cannot be undone.
  </p>

  <form class="fl-form" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <?= $f['email'] ?>
    <?= $f['password'] ?>
    <?php if (($lost_password_url ?? '') !== ''): ?>
      <p><a href="<?= View::e($lost_password_url) ?>">Forgot your password?</a></p>
    <?php endif; ?>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <?= $f['captcha_answer'] ?>

    <div class="fl-actions">
      <button type="submit">Remove Information</button>
      <button type="reset">Clear</button>
    </div>
  </form>
</div>
