<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Contact form content fragment. Provides a per-listing or collective contact
 form with name, email, URL, subject, message, and CAPTCHA fields. Served as a
 embeddable fragment via
 View::renderFragment() from embed/contact.php or embed/collective-contact.php.
 Supports custom form templates.
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

<?php if ($errors !== ''): ?>
  <div class="fl-errors"><?= $errors ?></div>
<?php endif; ?>

<form class="fl-form fl-form-contact" method="POST" action="<?= View::e($form_action) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

  <?= $f['name'] ?>
  <?= $f['email'] ?>
  <?= $f['url'] ?>
  <?= $f['subject'] ?>
  <?= $f['message'] ?>

  <!-- Honeypot — hidden from humans, filled by bots -->
  <div style="display:none" aria-hidden="true">
    <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
  </div>

  <?= $f['captcha_answer'] ?>

  <div class="fl-form-actions">
    <button type="submit" class="fl-submit">Send Message</button>
    <button type="reset" class="fl-btn-secondary">Clear</button>
  </div>
</form>
