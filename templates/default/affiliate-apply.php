<?php

declare(strict_types=1);

use Luster\Core\View;

/** @var object  $listing */
/** @var string  $listing_name */
/** @var string  $listing_url */
/** @var string  $form_action */
/** @var string  $csrf_token */
/** @var string  $captcha_question */
/** @var string  $captcha_error */
/** @var string  $errors */
/** @var array   $old */

?>
<?php if ($errors !== ''): ?>
  <div class="fl-error"><?= $errors ?></div>
<?php endif; ?>

<form method="POST" action="<?= View::e($form_action) ?>" enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="fl-field">
    <label class="fl-label" for="aff-name">Site name *</label>
    <input class="fl-input" type="text" id="aff-name" name="name" required maxlength="200"
           value="<?= View::e($old['name'] ?? '') ?>">
  </div>

  <div class="fl-field">
    <label class="fl-label" for="aff-url">Site URL *</label>
    <input class="fl-input" type="url" id="aff-url" name="url" required maxlength="500"
           value="<?= View::e($old['url'] ?? '') ?>">
  </div>

  <div class="fl-field">
    <label class="fl-label" for="aff-email">Your email (optional)</label>
    <input class="fl-input" type="email" id="aff-email" name="email" maxlength="255"
           value="<?= View::e($old['email'] ?? '') ?>">
    <small class="fl-hint">Used to notify you when your application is approved or rejected.</small>
  </div>

  <div class="fl-field">
    <label class="fl-label" for="aff-image">Site button / image (optional)</label>
    <input class="fl-input" type="file" id="aff-image" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
    <small class="fl-hint">JPEG, PNG, GIF, or WebP. Max 2 MB.</small>
  </div>

  <div class="fl-field">
    <label class="fl-label" for="aff-message">Message (optional)</label>
    <textarea class="fl-input" id="aff-message" name="message" maxlength="2000"
              rows="4"><?= View::e($old['message'] ?? '') ?></textarea>
  </div>

  <div class="fl-field">
    <label class="fl-label" for="aff-captcha">
      <?= View::e($captcha_question) ?> *
    </label>
    <input class="fl-input" type="text" id="aff-captcha" name="captcha_answer" required autocomplete="off">
    <?php if (!empty($captcha_error)): ?>
      <span class="fl-error"><?= View::e($captcha_error) ?></span>
    <?php endif; ?>
  </div>

  <!-- Honeypot — hidden from real users -->
  <div style="display:none" aria-hidden="true">
    <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
  </div>

  <div class="fl-actions">
    <button type="submit" class="fl-btn">Submit Application</button>
  </div>
</form>
