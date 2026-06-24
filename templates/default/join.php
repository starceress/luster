<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Join form content fragment. Renders the public "Join this listing" form with
 name, email, URL, country, additional fields, password, and CAPTCHA. Served as
 a embeddable fragment via
 View::renderFragment() from embed/join.php. Supports custom form templates.
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

<form class="fl-form fl-form-join" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

    <?= $f['name'] ?>
    <?= $f['email'] ?>
    <?= $f['url'] ?>
    <?php if ($country_field_enabled ?? true): ?>
      <?= $f['country'] ?>
    <?php endif; ?>

    <?php foreach ($fields as $_field): ?>
      <?= $f['field_' . $_field->name] ?? '' ?>
    <?php endforeach; ?>

    <?= $f['password'] ?>
    <?= $f['password_confirm'] ?>

    <!-- Honeypot — hidden from humans, filled by bots -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <?= $f['captcha_answer'] ?>

    <div class="fl-form-actions">
      <button type="submit" class="fl-submit">Join Listing</button>
      <button type="reset" class="fl-btn-secondary">Clear Form</button>
  </div>
</form>
