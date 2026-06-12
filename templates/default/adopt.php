<?php
/**
 * Adoption application form — content fragment.
 *
 * Variables injected by AdoptPublicController / embed/adopt.php:
 *   $listing_name  string
 *   $form_action   string
 *   $csrf_token    string
 *   $errors        string  pre-escaped HTML error block, or ''
 *   $f             array<string,string>  keys: 'name', 'email', 'url', 'message', 'captcha_answer'
 *   $form_html     string|null  when set, renders custom template and short-circuits default
 */

declare(strict_types=1);

use Luster\Core\View;

if (isset($form_html)) {
    ?>
<div class="fl-embed fl-embed--adopt">
  <?php if ($errors !== ''): ?><div class="fl-errors"><?= $errors ?></div><?php endif; ?>
  <?= $form_html ?>
</div>
    <?php
    return;
}
?>
<div class="fl-embed fl-embed--adopt">

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <form class="fl-form" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <?= $f['name'] ?>
    <?= $f['email'] ?>
    <?= $f['url'] ?>
    <?= $f['message'] ?>

    <!-- Honeypot — hidden from humans, filled by bots -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <?= $f['captcha_answer'] ?>

    <div class="fl-form-actions">
      <button type="submit" class="fl-submit">Submit Application</button>
      <button type="reset" class="fl-btn-secondary">Clear</button>
    </div>
  </form>
</div>
