<?php
/**
 * Remove membership form — content fragment.
 *
 * Variables injected by RemoveController:
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
<div class="fl-wrap">
  <h2 class="fl-title">Remove your membership from <?= View::e($listing_name) ?></h2>

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <p class="fl-muted" style="font-size:.875rem">
    Enter your email address and password to permanently remove your membership.
    This action cannot be undone.
  </p>

  <form method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="fl-field">
      <label class="fl-label" for="rem-email">Email <span class="fl-required">*</span></label>
      <input class="fl-input" type="email" id="rem-email" name="email"
             value="<?= View::e($field_email) ?>" required maxlength="255" autocomplete="email">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="rem-password">Password <span class="fl-required">*</span></label>
      <input class="fl-input" type="password" id="rem-password" name="password"
             required maxlength="255" autocomplete="current-password">
    </div>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="rem-captcha">
        Captcha: <?= View::e($captcha_question) ?> <span class="fl-required">*</span>
      </label>
      <?php if ($captcha_error !== ''): ?>
        <div class="fl-errors"><?= View::e($captcha_error) ?></div>
      <?php endif; ?>
      <input class="fl-input" type="text" id="rem-captcha" name="captcha_answer"
             required maxlength="10" autocomplete="off">
    </div>

    <button class="fl-btn" type="submit" style="background:var(--fl-error)">Remove Membership</button>
  </form>
</div>
