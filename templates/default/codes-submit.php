<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Fan code submission form content fragment. Allows visitors to upload a graphic
 code image for review, with submitter name/email/URL fields and CAPTCHA
 validation. Rendered as an embeddable
 fragment via View::renderFragment() from embed/codes-submit.php. Supports
 custom form templates.
*/

declare(strict_types=1);

use Luster\Core\View;

?>
<?php if (isset($form_html)): ?>
<?php if ($errors !== ''): ?>
  <div class="fl-errors"><?= $errors ?></div>
<?php endif; ?>
<?= $form_html ?>
<?php return; ?>
<?php endif; ?>
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
