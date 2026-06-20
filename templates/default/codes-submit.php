<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Fan code submission form — content fragment.
 *
 * Variables injected:
 *   $listing_title  string
 *   $listing_url   string
 *   $form_action   string
 *   $csrf_token    string
 *   $errors        string  (pre-escaped HTML or empty string)
 *   $f             array<string,string>  keys: code_image, submitter_name, submitter_email, submitter_url, captcha
 *   $base_url      string
 *   $form_html     string|null  pre-rendered custom form; when set, default markup is skipped
 */

?>
<?php if (isset($form_html)): ?>
<div class="fl-embed fl-embed--codes-submit">
  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>
  <?= $form_html ?>
</div>
<?php return; ?>
<?php endif; ?>
<div class="fl-embed fl-embed--codes-submit">
  <p class="fl-hint">
    Submit a code image for consideration. All submissions are reviewed before being published.
    Maximum file size: 200&nbsp;KB. Accepted formats: JPEG, PNG, GIF, WebP.
  </p>

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <form class="fl-form fl-form-codes-submit" method="POST" action="<?= View::e($form_action) ?>" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

    <!-- Honeypot — hidden from humans, filled by bots -->
    <div aria-hidden="true" style="display:none">
      <label for="website-confirm">Website (do not fill in)</label>
      <input type="text" id="website-confirm" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <?= $f['submitter_name'] ?>
    <?= $f['submitter_email'] ?>
    <?= $f['submitter_url'] ?>
    <?= $f['code_image'] ?>
    <?= $f['captcha'] ?>

    <div class="fl-form-actions">
      <button type="submit" class="fl-submit">Submit Code</button>
      <button type="reset" class="fl-btn-secondary">Clear</button>
    </div>
  </form>
</div>
