<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Fan code submission form — content fragment.
 *
 * Variables injected:
 *   $listing_name     string
 *   $listing_url      string
 *   $form_action      string
 *   $csrf_token       string
 *   $errors           string  (pre-escaped HTML or empty string)
 *   $old              array   (repopulation: submitter_name, submitter_email)
 *   $captcha_question string
 *   $captcha_error    string
 *   $submitted        bool    (true when showing thank-you message)
 *   $base_url         string
 */

$submitted = $submitted ?? false;
?>

<div class="fl-wrap">
  <h2 class="fl-title">Submit a Code for <?= View::e($listing_name) ?></h2>

  <?php if ($submitted): ?>

    <div class="fl-success">
      <p>Thank you for your submission! Your code has been received and will be reviewed shortly.</p>
      <?php if ($listing_url !== ''): ?>
        <p><a class="fl-link" href="<?= View::e($listing_url) ?>">Return to <?= View::e($listing_name) ?></a></p>
      <?php endif; ?>
    </div>

  <?php else: ?>

    <p class="fl-muted" style="font-size:.875rem">
      Submit a code image for consideration. All submissions are reviewed before being published.
      Maximum file size: 200&nbsp;KB. Accepted formats: JPEG, PNG, GIF, WebP.
    </p>

    <?php if ($errors !== ''): ?>
      <p class="fl-error"><?= $errors ?></p>
    <?php endif; ?>

    <form class="fl-form" method="POST" action="<?= View::e($form_action) ?>" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

      <div class="fl-field">
        <label class="fl-label" for="code-image">Code image <span class="fl-required">*</span></label>
        <input class="fl-input" type="file" id="code-image" name="code_image"
               accept="image/jpeg,image/png,image/gif,image/webp" required>
      </div>

      <div class="fl-field">
        <label class="fl-label" for="sub-name">Your name <span class="fl-optional" style="font-weight:400;opacity:.7">(optional)</span></label>
        <input class="fl-input" type="text" id="sub-name" name="submitter_name" maxlength="150"
               value="<?= View::e($old['submitter_name'] ?? '') ?>">
      </div>

      <div class="fl-field">
        <label class="fl-label" for="sub-email">Your email <span class="fl-optional" style="font-weight:400;opacity:.7">(optional)</span></label>
        <input class="fl-input" type="email" id="sub-email" name="submitter_email" maxlength="255"
               value="<?= View::e($old['submitter_email'] ?? '') ?>">
        <p class="fl-hint" style="font-size:.8rem;margin:.2rem 0 0;opacity:.75">Used only to notify you if your code is approved.</p>
      </div>

      <?php if ($captcha_question !== ''): ?>
      <div class="fl-field">
        <label class="fl-label" for="captcha"><?= View::e($captcha_question) ?> <span class="fl-required">*</span></label>
        <?php if ($captcha_error !== ''): ?>
          <p class="fl-error" style="margin:.2em 0"><?= View::e($captcha_error) ?></p>
        <?php endif; ?>
        <input class="fl-input" type="text" id="captcha" name="captcha" required autocomplete="off">
      </div>
      <?php endif; ?>

      <div class="fl-actions">
        <button class="fl-btn fl-btn-primary" type="submit">Submit Code</button>
      </div>
    </form>

  <?php endif; ?>
</div>
