<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 KIM List password reset confirmation content fragment (Keep In Mind add-on,
 step 2 of 2). Validates the reset token from the emailed link and presents a
 new-password form. Shows an expired/invalid message with a recovery link when
 the token is no longer valid. Served as a full page via View::renderPublic()
 or as an embeddable fragment via View::renderFragment() from
 embed/kim-reset.php. Supports custom form templates.
*/

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

if (isset($form_html) && $form_html !== null) {
    ?>
<div class="fl-kim">
  <?php if (($errors ?? '') !== ''): ?><div class="fl-errors"><?= $errors ?></div><?php endif; ?>
  <?= $form_html ?>
</div>
    <?php
    return;
}
?>
<div class="fl-kim">
  <h2 class="fl-kim-heading">KIM List &mdash; Set New Password</h2>

  <?php if (!$token_valid): ?>
    <div class="fl-errors">
      <?php if ($errors !== ''): ?>
        <?= $errors ?>
      <?php else: ?>
        <p>This reset link has expired or is invalid. Please request a new one.</p>
      <?php endif; ?>
    </div>
    <p class="fl-kim-meta">
      <a href="<?= View::e($reset_request_url ?? ($b . '/kim/reset')) ?>">Request a new password reset link</a>
    </p>

  <?php else: ?>

    <?php if ($errors !== ''): ?>
      <div class="fl-errors"><?= $errors ?></div>
    <?php endif; ?>

    <form class="fl-form fl-form-kim-reset-confirm" method="POST" action="<?= View::e($form_action) ?>">
      <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
      <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>
      <input type="hidden" name="token" value="<?= View::e($token) ?>">

      <?= $f['new_password'] ?>
      <?= $f['new_password_confirm'] ?>

      <div class="fl-form-actions">
        <button class="fl-submit fl-btn" type="submit">Set New Password</button>
      </div>
    </form>

  <?php endif; ?>
</div>
