<?php
/**
 * Contact form — content fragment.
 *
 * Variables injected by ContactController / embed/contact.php:
 *   $listing      Listing|null  null for collective form
 *   $listing_name string
 *   $form_action  string
 *   $csrf_token   string
 *   $errors       string  pre-escaped HTML error block, or ''
 *   $f            array<string,string>  keys: 'name', 'email', 'url', 'subject', 'message', 'captcha_answer'
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div class="fl-embed fl-embed--contact">

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <form class="fl-form" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

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
    </div>
  </form>

</div>
