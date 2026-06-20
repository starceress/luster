<?php
/**
 * Join form — content fragment.
 *
 * Variables injected by JoinController / embed/join.php:
 *   $listing_title           string
 *   $form_action            string
 *   $csrf_token             string
 *   $errors                 string  pre-escaped HTML error block, or ''
 *   $fields                 AdditionalField[]  used to echo additional fields in order
 *   $f                      array<string,string>  wrapped field HTML keyed by field name
 *   $base_url               string
 *   $country_field_enabled  bool  whether to render the country field
 *   $form_html              ?string  pre-rendered <form> HTML when a custom
 *                           join_form_template is set; bypasses the markup below
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<?php if (isset($form_html)): ?>
<div class="fl-embed fl-embed--join">
  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>
  <?= $form_html ?>
</div>
<?php return; ?>
<?php endif; ?>
<div class="fl-embed fl-embed--join">

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
      <button type="submit" class="fl-submit">Join Fanlisting</button>
      <button type="reset" class="fl-btn-secondary">Clear</button>
    </div>
  </form>
</div>
