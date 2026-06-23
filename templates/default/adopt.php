<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Adoption application form content fragment (Adoption add-on). Allows visitors
 to submit an application expressing interest in adopting a fanlisting, with
 name, email, URL, message, and CAPTCHA fields. Served as a full page via
 View::renderPublic() or as an embeddable fragment via View::renderFragment()
 from embed/adopt.php. Supports custom form templates.
*/

declare(strict_types=1);

use Luster\Core\View;

if (isset($success_html) && $success_html !== '') {
    echo $success_html;
    return;
}
if (isset($form_html)) {
    ?>
<?php if ($errors !== ''): ?><div class="fl-errors"><?= $errors ?></div><?php endif; ?>
<?= $form_html ?>
    <?php
    return;
}
?>

<?php if ($errors !== ''): ?>
  <div class="fl-errors"><?= $errors ?></div>
<?php endif; ?>

<form class="fl-form fl-form-adopt" method="POST" action="<?= View::e($form_action) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

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
