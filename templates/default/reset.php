<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Password reset content fragment (step 1 of 2). Presents a form where the fan
 enters their email address to receive a time-limited password reset link.
 Rendered as an embeddable fragment
 via View::renderFragment() from embed/reset.php. Supports custom form templates.
*/

declare(strict_types=1);

use Luster\Core\View;

if (isset($form_html)) {
    echo '<div class="fl-embed fl-embed--reset">';
    if ($errors !== '') {
        echo '<div class="fl-errors">' . $errors . '</div>';
    }
    echo $form_html;
    echo '</div>';
    return;
}
?>
<div class="fl-embed fl-embed--reset">
<?php if ($errors !== ''): ?>
  <div class="fl-errors"><?= $errors ?></div>
<?php endif; ?>

<p class="fl-hint">
  If you have lost or forgotten your password, enter the email address you joined with below and a reset link will be sent to you. For your security, reset links expire after 1 hour.
</p>

<form class="fl-form fl-form-reset" method="POST" action="<?= View::e($form_action) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

  <?= $f['email'] ?>

  <!-- Honeypot -->
  <div style="display:none" aria-hidden="true">
    <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
  </div>

  <div class="fl-form-actions">
    <button type="submit" class="fl-submit">Send Reset Link</button>
  </div>
</form>
</div>
