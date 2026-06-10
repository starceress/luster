<?php
/**
 * Update membership — content fragment (two-step).
 *
 * Variables injected by UpdateController / embed/update.php:
 *   $signed_in      bool    false = step 1 (sign in), true = step 2 (update form)
 *
 * Step 1 ($signed_in = false):
 *   $sign_in_action   string
 *   $csrf_token       string
 *   $errors           string  pre-escaped HTML error block, or ''
 *   $lost_password_url string
 *   $f                array<string,string>  keys: 'email', 'password'
 *
 * Step 2 ($signed_in = true):
 *   $form_action      string
 *   $sign_out_url     string
 *   $csrf_token       string
 *   $errors           string
 *   $field_email      string  authenticated member's current email (display only)
 *   $fields           AdditionalField[]  used to echo additional fields in order
 *   $f                array<string,string>  wrapped field HTML keyed by field name
 */

declare(strict_types=1);

use Luster\Core\View;

$signed_in = $signed_in ?? false;
?>
<div class="fl-embed fl-embed--update">
  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

<?php if (!$signed_in): ?>

  <!-- ── Step 1: Sign in ──────────────────────────────────────────────── -->
  <form class="fl-form" method="POST" action="<?= View::e($sign_in_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <?= $f['email'] ?>
    <?= $f['password'] ?>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div class="fl-form-actions">
      <button type="submit">Sign in</button>
    </div>
  </form>

  <?php if (($lost_password_url ?? '') !== ''): ?>
    <p><a href="<?= View::e($lost_password_url) ?>">Forgot your password?</a></p>
  <?php endif; ?>

<?php else: ?>

  <!-- ── Step 2: Update form ──────────────────────────────────────────── -->
  <p class="fl-hint">
    Signed in as <strong><?= View::e($field_email) ?></strong>.
    <a href="<?= View::e($sign_out_url) ?>">Not you?</a>
  </p>

  <p class="fl-hint">
    Fill in only the fields you want to change. Leave a field blank to keep your current value.
  </p>

  <form class="fl-form" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <?= $f['new_name'] ?>
    <?= $f['new_email'] ?>
    <?= $f['url'] ?>
    <?= $f['country'] ?>

    <?php foreach ($fields as $_field): ?>
      <?= $f['field_' . $_field->name] ?? '' ?>
    <?php endforeach; ?>

    <?= $f['new_password'] ?>
    <?= $f['new_password_confirm'] ?>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <?= $f['captcha_answer'] ?>

    <div class="fl-form-actions">
      <button type="submit" class="fl-submit">Update Information</button>
      <button type="reset" class="fl-btn-secondary">Clear</button>
    </div>
  </form>

<?php endif; ?>
</div>
