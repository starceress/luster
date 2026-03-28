<?php
/**
 * KIM List password reset — email entry form.
 *
 * Variables:
 *   $form_action      string
 *   $csrf_token       string
 *   $errors           string  pre-escaped HTML error string, or ''
 *   $fanlistings      Listing[]
 *   $old_email        string
 *   $captcha_question string
 *   $captcha_error    string
 *   $base_url         string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
$_linkUpdate = ($link_update !== null && $link_update !== '') ? $link_update : null;
?>
<div class="fl-wrap">
  <h2 class="fl-title">KIM List — Reset Password</h2>
  <p class="fl-intro">
    Enter your email address and select the fanlisting your KIM entry is for.
    If a matching entry is found, we will send a reset link to that address.
  </p>

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="fl-field">
      <label class="fl-label" for="kim-email">Email <span class="fl-required">*</span></label>
      <input class="fl-input" type="email" id="kim-email" name="email"
             value="<?= View::e($old_email) ?>" required maxlength="255" autocomplete="email">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="kim-fanlisting">Fanlisting <span class="fl-required">*</span></label>
      <select class="fl-select" id="kim-fanlisting" name="fanlisting_id" required>
        <option value="">— Select the fanlisting your entry is for —</option>
        <?php foreach ($fanlistings as $fl): ?>
          <option value="<?= View::e((string) $fl->id) ?>">
            <?= View::e($fl->subject) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Honeypot — hidden from humans, filled by bots -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="kim-captcha">
        Captcha: <?= View::e($captcha_question) ?> <span class="fl-required">*</span>
      </label>
      <?php if ($captcha_error !== ''): ?>
        <div class="fl-errors"><?= View::e($captcha_error) ?></div>
      <?php endif; ?>
      <input class="fl-input" type="text" id="kim-captcha" name="captcha_answer"
             required maxlength="10" autocomplete="off">
    </div>

    <button class="fl-btn" type="submit">Send Reset Link</button>
  </form>

  <?php if ($_linkUpdate !== null): ?>
  <p class="fl-meta" style="margin-top:1.5rem">
    <a href="<?= View::e($_linkUpdate) ?>">&larr; Back to Update</a>
  </p>
  <?php endif; ?>
</div>
