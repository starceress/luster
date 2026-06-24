<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 KIM List password reset content fragment (Keep In Mind add-on, step 1 of 2).
 Presents a form where the visitor enters their email address to receive a
 time-limited reset link for their KIM entries. Rendered via
 View::renderFragment() from embed/kim-reset.php. Supports custom form templates.
*/

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
$_linkUpdate = (($link_update ?? null) !== null && $link_update !== '') ? $link_update : null;

if (($success_html ?? '') !== '') {
    echo '<div class="fl-kim">' . $success_html . '</div>';
    return;
}

if (isset($form_html) && $form_html !== null) {
    ?>
<div class="fl-kim">
  <?php if (($errors ?? '') !== ''): ?><div class="fl-errors"><?= $errors ?></div><?php endif; ?>
  <?= $form_html ?>
  <?php if ($_linkUpdate !== null): ?>
  <p class="fl-kim-meta"><a href="<?= View::e($_linkUpdate) ?>">&larr; Back to Update</a></p>
  <?php endif; ?>
</div>
    <?php
    return;
}
?>
<div class="fl-kim">
  <h2 class="fl-kim-heading">KIM List &mdash; Reset Password</h2>
  <p class="fl-kim-intro">
    Enter your email address below. If any matching KIM entries are found,
    we will send a reset link for each one.
  </p>

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <form class="fl-form fl-form-kim-reset" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

    <?= $f['email'] ?>

    <!-- Honeypot — hidden from humans, filled by bots -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div class="fl-form-actions">
      <button class="fl-submit fl-btn" type="submit">Send Reset Link</button>
    </div>
  </form>

  <?php if ($_linkUpdate !== null): ?>
  <p class="fl-kim-meta">
    <a href="<?= View::e($_linkUpdate) ?>">&larr; Back to Update</a>
  </p>
  <?php endif; ?>
</div>
