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
 *   $form_html    ?string  pre-rendered <form> HTML when a custom contact_form_template is set;
 *                          bypasses the markup below when present
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<?php if (isset($form_html)): ?>
<div class="fl-embed fl-embed--contact">
  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>
  <?= $form_html ?>
</div>
<?php return; ?>
<?php endif; ?>
<div class="fl-embed fl-embed--contact">

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

</div>
