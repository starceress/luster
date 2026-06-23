<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Update membership content fragment (two-step). Step 1 presents a sign-in form
 (email + password) with a "Forgot password?" link. Step 2 shows the editable
 profile fields and an option to permanently delete the membership. Served as a
 full page via View::renderPublic() or as an embeddable fragment via
 View::renderFragment() from embed/update.php. Supports custom form templates.
*/

declare(strict_types=1);

use Luster\Core\View;

$signed_in = $signed_in ?? false;

if (isset($form_html)) {
    ?>
<?php if ($errors !== ''): ?>
  <div class="fl-errors"><?= $errors ?></div>
<?php endif; ?>
<?= $form_html ?>
    <?php
    return;
}
?>
<?php if ($errors !== ''): ?>
  <div class="fl-errors"><?= $errors ?></div>
<?php endif; ?>

<?php if (!$signed_in): ?>

  <p>If you are an existing member and would like to <b>update</b> your information or <b>remove</b> your membership entirely, log in with your current email address and password below.</p>

  <!-- ── Step 1: Sign in ──────────────────────────────────────────────── -->
  <form class="fl-form fl-form-signin" method="POST" action="<?= View::e($sign_in_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

    <?= $f['email'] ?>
    <?= $f['password'] ?>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div class="fl-form-actions">
      <button type="submit" class="fl-submit">Sign In</button>
    </div>
  </form>

  <?php if (($lost_password_url ?? '') !== ''): ?>
    <p class="fl-lost-password"><a href="<?= View::e($lost_password_url) ?>">Forgot your password?</a></p>
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

  <form class="fl-form fl-form-update" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

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

    <div class="fl-field fl-field--danger">
      <label class="fl-label fl-label-danger">
        <input type="checkbox" class="fl-checkbox" id="upd-delete-membership" name="delete_membership" value="1">
        Permanently delete my membership instead of updating it.
      </label>
      <p class="fl-field-hint">
        This immediately and permanently removes your membership from this listing.
        Any changes above are ignored, and this cannot be undone.
      </p>
    </div>

    <div class="fl-form-actions">
      <button type="submit" class="fl-submit">Update Information</button>
      <button type="reset" class="fl-btn-secondary">Clear</button>
    </div>
  </form>

  <?php if (!defined('FL_EMBED_JS_LOADED')): define('FL_EMBED_JS_LOADED', true); ?>
  <script src="<?= View::e($base_url) ?>/assets/js/fl-embed.js"></script>
  <?php endif; ?>

<?php endif; ?>
