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
 *   $old              array   (repopulation: submitter_name, submitter_email, submitter_url)
 *   $captcha_question string
 *   $captcha_error    string
 *   $submitted        bool    (true when showing thank-you message)
 *   $base_url         string
 */

$submitted = $submitted ?? false;
?>

<div>
  <?php if ($submitted): ?>

    <div>
      <p>Thank you for your submission! Your code has been received and will be reviewed shortly.</p>
      <?php if ($listing_url !== ''): ?>
        <p><a href="<?= View::e($listing_url) ?>">Return to <?= View::e($listing_name) ?></a></p>
      <?php endif; ?>
    </div>

  <?php else: ?>

    <p>
      Submit a code image for consideration. All submissions are reviewed before being published.
      Maximum file size: 200&nbsp;KB. Accepted formats: JPEG, PNG, GIF, WebP.
    </p>

    <?php if ($errors !== ''): ?>
      <p><?= $errors ?></p>
    <?php endif; ?>

    <form method="POST" action="<?= View::e($form_action) ?>" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

      <div>
        <label for="code-image">Upload Code: *</label>
        <input type="file" id="code-image" name="code_image"
               accept="image/jpeg,image/png,image/gif,image/webp" required>
      </div>

      <div>
        <label for="sub-name">Name: (optional)</label>
        <input type="text" id="sub-name" name="submitter_name" maxlength="150"
               value="<?= View::e($old['submitter_name'] ?? '') ?>">
      </div>

      <div>
        <label for="sub-email">Email: (optional)</label>
        <input type="email" id="sub-email" name="submitter_email" maxlength="255"
               value="<?= View::e($old['submitter_email'] ?? '') ?>">
        <p style="font-size:.8rem;margin:.2rem 0 0;opacity:.75">Used only to notify you if your code is approved.</p>
      </div>

      <div>
        <label for="sub-url">Website: (optional)</label>
        <input type="url" id="sub-url" name="submitter_url" maxlength="255"
               placeholder="https://" value="<?= View::e($old['submitter_url'] ?? '') ?>">
        <p style="font-size:.8rem;margin:.2rem 0 0;opacity:.75">Displayed as a link on your donated code.</p>
      </div>

      <?php if ($captcha_question !== ''): ?>
      <div>
        <label for="captcha">Captcha: * <?= View::e($captcha_question) ?></label>
        <?php if ($captcha_error !== ''): ?>
          <p style="margin:.2em 0"><?= View::e($captcha_error) ?></p>
        <?php endif; ?>
        <input type="text" id="captcha" name="captcha" required autocomplete="off">
      </div>
      <?php endif; ?>

      <div>
        <button type="submit">Submit Code</button>
      </div>
    </form>

  <?php endif; ?>
</div>
