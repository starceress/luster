<?php
/**
 * Remove membership form — content fragment.
 *
 * Variables injected by RemoveController:
 *   $listing_name      string
 *   $form_action       string
 *   $csrf_token        string
 *   $errors            string  pre-escaped HTML error string, or ''
 *   $field_email       string  repopulation value
 *   $lost_password_url string  URL to the reset page, or '' to hide the link
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

  <p style="font-size:.875rem">
    Enter your email address and password to permanently remove your membership.
    This action cannot be undone.
  </p>

  <form method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div>
      <label for="rem-email">Email *</label>
      <input type="email" id="rem-email" name="email"
             value="<?= View::e($field_email) ?>" required maxlength="255" autocomplete="email">
    </div>

    <div>
      <label for="rem-password">Password *</label>
      <input type="password" id="rem-password" name="password"
             required maxlength="255" autocomplete="current-password">
      <?php if (($lost_password_url ?? '') !== ''): ?>
      <p><a href="<?= View::e($lost_password_url) ?>">Forgot your password?</a></p>
      <?php endif; ?>
    </div>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div>
      <label for="rem-captcha">
        Captcha: <?= View::e($captcha_question) ?> *
      </label>
      <?php if ($captcha_error !== ''): ?>
        <div><?= View::e($captcha_error) ?></div>
      <?php endif; ?>
      <input type="text" id="rem-captcha" name="captcha_answer"
             required maxlength="10" autocomplete="off">
    </div>

    <button type="submit">Remove Information</button>
    <button type="reset">Clear Form</button>
  </form>
</div>
