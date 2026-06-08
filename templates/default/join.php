<?php
/**
 * Join form — content fragment.
 *
 * Variables injected by JoinController / embed/join.php:
 *   $listing_name  string
 *   $form_action   string
 *   $csrf_token    string
 *   $errors        string  pre-escaped HTML error block, or ''
 *   $fields        AdditionalField[]  used to echo additional fields in order
 *   $f             array<string,string>  wrapped field HTML keyed by field name
 *   $base_url      string
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div>

  <?php if ($errors !== ''): ?>
    <div><?= $errors ?></div>
  <?php endif; ?>

  <form class="fl-form" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <?= $f['name'] ?>
    <?= $f['email'] ?>
    <?= $f['url'] ?>
    <?= $f['country'] ?>

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

    <div class="fl-actions">
      <button type="submit">Join Fanlisting</button>
      <button type="reset">Clear</button>
    </div>
  </form>
</div>
