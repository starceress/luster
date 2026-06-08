<?php
/**
 * KIM List password reset — email entry form.
 *
 * Variables:
 *   $form_action  string
 *   $csrf_token   string
 *   $errors       string  pre-escaped HTML error string, or ''
 *   $f            array<string,string>  keys: email, fanlisting_id, captcha_answer
 *   $base_url     string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
$_linkUpdate = ($link_update !== null && $link_update !== '') ? $link_update : null;
?>
<div class="fl-embed fl-embed--kim-reset">
  <div class="fl-kim">
    <h2 class="fl-kim-heading">KIM List &mdash; Reset Password</h2>
    <p class="fl-kim-intro">
      Enter your email address and select the fanlisting your KIM entry is for.
      If a matching entry is found, we will send a reset link to that address.
    </p>

    <?php if ($errors !== ''): ?>
      <div class="fl-errors"><?= $errors ?></div>
    <?php endif; ?>

    <form class="fl-form" method="POST" action="<?= View::e($form_action) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

      <?= $f['email'] ?>
      <?= $f['fanlisting_id'] ?>

      <!-- Honeypot — hidden from humans, filled by bots -->
      <div style="display:none" aria-hidden="true">
        <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
      </div>

      <?= $f['captcha_answer'] ?>

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
</div>
