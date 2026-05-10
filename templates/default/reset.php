<?php
/**
 * Password reset — email entry form fragment.
 *
 * Variables injected by ResetController:
 *   $listing_name   string
 *   $form_action       string
 *   $csrf_token        string
 *   $errors            string  pre-escaped HTML error string, or ''
 *   $field_email       string  repopulation value
 *   $captcha_question  string
 *   $captcha_error     string
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
    Enter your email address and we'll send you a link to reset your password.
    The link expires in 1 hour.
  </p>

  <form method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div>
      <label for="rst-email">Email *</label>
      <input type="email" id="rst-email" name="email"
             value="<?= View::e($field_email) ?>" required maxlength="255" autocomplete="email">
    </div>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div>
      <label for="rst-captcha">
        Captcha: <?= View::e($captcha_question) ?> *
      </label>
      <?php if ($captcha_error !== ''): ?>
        <div><?= View::e($captcha_error) ?></div>
      <?php endif; ?>
      <input type="text" id="rst-captcha" name="captcha_answer"
             required maxlength="10" autocomplete="off">
    </div>

    <button type="submit">Send Reset Link</button>
  </form>
</div>
