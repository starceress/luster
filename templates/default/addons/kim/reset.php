<?php
/**
 * KIM List password reset — email entry form.
 *
 * Variables:
 *   $form_action  string
 *   $csrf_token   string
 *   $errors       string  pre-escaped HTML error string, or ''
 *   $success_html string  pre-rendered success HTML, or ''
 *   $f            array<string,string>  keys: email
 *   $form_html    string|null  custom form template HTML, or null to use hardcoded form
 *   $base_url     string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
$_linkUpdate = (($link_update ?? null) !== null && $link_update !== '') ? $link_update : null;

if (($success_html ?? '') !== '') {
    echo '<div class="fl-embed fl-embed--kim-reset"><div class="fl-kim">' . $success_html . '</div></div>';
    return;
}

if (isset($form_html) && $form_html !== null) {
    ?>
<div class="fl-embed fl-embed--kim-reset">
  <div class="fl-kim">
    <?php if (($errors ?? '') !== ''): ?><div class="fl-errors"><?= $errors ?></div><?php endif; ?>
    <?= $form_html ?>
    <?php if ($_linkUpdate !== null): ?>
    <p class="fl-kim-meta"><a href="<?= View::e($_linkUpdate) ?>">&larr; Back to Update</a></p>
    <?php endif; ?>
  </div>
</div>
    <?php
    return;
}
?>
<div class="fl-embed fl-embed--kim-reset">
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
</div>
