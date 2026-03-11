<?php
/**
 * Password reset — email entry form fragment.
 *
 * Variables injected by ResetController:
 *   $fanlisting_name   string
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
<div class="fl-wrap">
  <h2 class="fl-title">Reset your password for <?= View::e($fanlisting_name) ?></h2>

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <p class="fl-muted" style="font-size:.875rem">
    Enter your email address and we'll send you a link to reset your password.
    The link expires in 1 hour.
  </p>

  <form method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="fl-field">
      <label class="fl-label" for="rst-email">Email <span class="fl-required">*</span></label>
      <input class="fl-input" type="email" id="rst-email" name="email"
             value="<?= View::e($field_email) ?>" required maxlength="255" autocomplete="email">
    </div>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="rst-captcha">
        <?= View::e($captcha_question) ?> <span class="fl-required">*</span>
      </label>
      <?php if ($captcha_error !== ''): ?>
        <div class="fl-errors"><?= View::e($captcha_error) ?></div>
      <?php endif; ?>
      <input class="fl-input" type="text" id="rst-captcha" name="captcha_answer"
             required maxlength="10" autocomplete="off">
    </div>

    <button class="fl-btn" type="submit">Send Reset Link</button>
  </form>
</div>
