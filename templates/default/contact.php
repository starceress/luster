<?php
/**
 * Contact form — content fragment.
 *
 * Variables injected by ContactController:
 *   $listing        Listing|null  null for collective form
 *   $listing_name   string
 *   $form_action    string
 *   $csrf_token     string
 *   $errors         string  pre-escaped HTML error string, or ''
 *   $captcha_question string
 *   $captcha_error  string  pre-escaped, or ''
 *   $field_name     string  repopulation value (pre-escaped)
 *   $field_email    string  repopulation value (pre-escaped)
 *   $field_url      string  repopulation value (pre-escaped), optional
 *   $field_subject  string  repopulation value (pre-escaped)
 *   $field_message  string  repopulation value (pre-escaped)
 *   $old            array
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div>

  <?php if ($errors !== ''): ?>
    <div><?= $errors ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div>
      <label for="contact-name">Name *</label>
      <input type="text" id="contact-name" name="name"
             value="<?= $field_name ?>" required maxlength="100" autocomplete="name">
    </div>

    <div>
      <label for="contact-email">Email *</label>
      <input type="email" id="contact-email" name="email"
             value="<?= $field_email ?>" required maxlength="255" autocomplete="email">
    </div>

    <div>
      <label for="contact-url">Website URL</label>
      <input type="url" id="contact-url" name="url"
             value="<?= $field_url ?>" maxlength="255" autocomplete="url">
    </div>

    <div>
      <label for="contact-subject">Subject *</label>
      <select id="contact-subject" name="subject" required>
        <option value="" disabled<?= $field_subject === '' ? ' selected' : '' ?>>Choose &mdash;</option>
        <?php foreach (['Comments', 'Contact', 'Questions/Concerns', 'Affiliation', 'Link Exchange'] as $_subjectOpt): ?>
          <option value="<?= View::e($_subjectOpt) ?>"<?= $field_subject === $_subjectOpt ? ' selected' : '' ?>>
            <?= View::e($_subjectOpt) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label for="contact-message">Message *</label>
      <textarea id="contact-message" name="message"
                required maxlength="5000" rows="6"><?= $field_message ?></textarea>
    </div>

    <!-- Honeypot — hidden from humans, filled by bots -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div>
      <label for="contact-captcha">
        Captcha: <?= View::e($captcha_question) ?> *
      </label>
      <?php if ($captcha_error !== ''): ?>
        <div><?= $captcha_error ?></div>
      <?php endif; ?>
      <input type="text" id="contact-captcha" name="captcha_answer"
             required maxlength="10" autocomplete="off">
    </div>

    <div>
      <button type="submit">Send Message</button>
    </div>
  </form>

</div>
