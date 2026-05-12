<?php
/**
 * Adoption application form — content fragment.
 *
 * Variables injected by AdoptController:
 *   $listing_name     string
 *   $form_action      string
 *   $csrf_token       string
 *   $errors           string  pre-escaped HTML error string, or ''
 *   $field_name       string  repopulation value
 *   $field_email      string  repopulation value
 *   $field_url        string  repopulation value (optional)
 *   $field_message    string  repopulation value (optional)
 *   $captcha_question string
 *   $captcha_error    string  raw (escaped in template)
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div>

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= View::e($form_action) ?>" class="fl-form">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="fl-field">
      <label class="fl-label" for="adopt-name">Name *</label>
      <input class="fl-input" type="text" id="adopt-name" name="name"
             value="<?= View::e($field_name) ?>" required maxlength="100" autocomplete="name">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="adopt-email">Email *</label>
      <input class="fl-input" type="email" id="adopt-email" name="email"
             value="<?= View::e($field_email) ?>" required maxlength="255" autocomplete="email">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="adopt-url">Website URL <span class="fl-opt">(optional)</span></label>
      <input class="fl-input" type="url" id="adopt-url" name="url"
             value="<?= View::e($field_url) ?>" maxlength="500" placeholder="https://" autocomplete="url">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="adopt-message">Message <span class="fl-opt">(optional)</span></label>
      <textarea class="fl-input" id="adopt-message" name="message"
                maxlength="2000" rows="5"><?= View::e($field_message) ?></textarea>
    </div>

    <!-- Honeypot — hidden from humans, filled by bots -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="adopt-captcha">Captcha: <?= View::e($captcha_question) ?> *</label>
      <?php if ($captcha_error !== ''): ?>
        <div class="fl-errors"><?= View::e($captcha_error) ?></div>
      <?php endif; ?>
      <input class="fl-input" type="text" id="adopt-captcha" name="captcha_answer"
             required maxlength="10" autocomplete="off">
    </div>

    <div class="fl-actions">
      <button type="submit" class="fl-btn">Submit Application</button>
      <button type="reset" class="fl-btn fl-btn-secondary">Clear</button>
    </div>
  </form>
</div>
