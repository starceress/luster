<?php

declare(strict_types=1);

use Luster\Core\View;

/** @var object|null             $listing */
/** @var string                  $listing_name */
/** @var string                  $listing_url */
/** @var string                  $form_action */
/** @var string                  $csrf_token */
/** @var string                  $errors */
/** @var array<string,string>    $f  keys: name, url, email, image, message, captcha_answer */
/** @var string|null             $form_html  Non-null when a custom affiliate-apply form template is active */

?>
<?php if (isset($form_html)): ?>
<div class="fl-embed fl-embed--affiliate-apply">
<?php if ($errors !== ''): ?>
  <div class="fl-errors"><?= $errors ?></div>
<?php endif; ?>
  <?= $form_html ?>
</div>
<?php return; ?>
<?php endif; ?>
<div class="fl-embed fl-embed--affiliate-apply">
<?php if ($errors !== ''): ?>
  <div class="fl-errors"><?= $errors ?></div>
<?php endif; ?>

<form class="fl-form" method="POST" action="<?= View::e($form_action) ?>" enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <?= $f['name'] ?>
  <?= $f['email'] ?>
  <?= $f['url'] ?>
  <?= $f['image'] ?>
  <?= $f['message'] ?>

  <!-- Honeypot — hidden from humans, filled by bots -->
  <div style="display:none" aria-hidden="true">
    <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
  </div>

  <?= $f['captcha_answer'] ?>

  <div class="fl-form-actions">
    <button type="submit" class="fl-submit fl-btn">Submit Application</button>
  </div>
</form>
</div>
