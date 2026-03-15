<?php
/**
 * KIM List update form — content fragment.
 *
 * Variables:
 *   $form_action      string
 *   $csrf_token       string
 *   $errors           string  pre-escaped HTML error string, or ''
 *   $fanlistings      Listing[]
 *   $old              array   keys: name, current_email, fanlisting_id, new_email, new_url, send_copy
 *   $captcha_question string
 *   $captcha_error    string
 *   $base_url         string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>
<div class="fl-wrap">
  <h2 class="fl-title">KIM List — Update Your Entry</h2>
  <p class="fl-intro">
    Update your name, URL, or email address on the KIM list.
    Provide your current email and password to authenticate.
  </p>

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="fl-field">
      <label class="fl-label" for="kim-name">Name <span class="fl-required">*</span></label>
      <input class="fl-input" type="text" id="kim-name" name="name"
             value="<?= View::e($old['name'] ?? '') ?>" required maxlength="100" autocomplete="name">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="kim-current-email">Current Email <span class="fl-required">*</span></label>
      <input class="fl-input" type="email" id="kim-current-email" name="current_email"
             value="<?= View::e($old['current_email'] ?? '') ?>" required maxlength="255" autocomplete="email">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="kim-current-password">Current Password <span class="fl-required">*</span></label>
      <input class="fl-input" type="password" id="kim-current-password" name="current_password"
             required maxlength="255" autocomplete="current-password">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="kim-fanlisting">Fanlisting <span class="fl-required">*</span></label>
      <select class="fl-select" id="kim-fanlisting" name="fanlisting_id" required>
        <option value="">— Select the fanlisting your entry is for —</option>
        <?php foreach ($fanlistings as $fl): ?>
          <option value="<?= View::e((string) $fl->id) ?>"
            <?= ((string) ($old['fanlisting_id'] ?? '')) === (string) $fl->id ? 'selected' : '' ?>>
            <?= View::e($fl->subject) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <fieldset style="border:1px solid var(--border,#ccc);padding:.75rem 1rem;border-radius:4px;margin:.5rem 0">
      <legend style="padding:0 .5rem;font-weight:600">Optional Updates</legend>

      <div class="fl-field">
        <label class="fl-label" for="kim-new-email">New Email <span style="font-weight:400">(leave blank to keep current)</span></label>
        <input class="fl-input" type="email" id="kim-new-email" name="new_email"
               value="<?= View::e($old['new_email'] ?? '') ?>" maxlength="255" autocomplete="off">
      </div>

      <div class="fl-field">
        <label class="fl-label" for="kim-new-url">New Collective / Website URL <span style="font-weight:400">(leave blank to keep current)</span></label>
        <input class="fl-input" type="url" id="kim-new-url" name="new_url"
               value="<?= View::e($old['new_url'] ?? '') ?>" maxlength="500" placeholder="https://">
      </div>

      <div class="fl-field">
        <label class="fl-label" for="kim-new-password">New Password <span style="font-weight:400">(min 8 chars; leave blank to keep current)</span></label>
        <input class="fl-input" type="password" id="kim-new-password" name="new_password"
               maxlength="255" autocomplete="new-password">
      </div>

      <div class="fl-field">
        <label class="fl-label" for="kim-new-password-confirm">Confirm New Password</label>
        <input class="fl-input" type="password" id="kim-new-password-confirm" name="new_password_confirm"
               maxlength="255" autocomplete="new-password">
      </div>
    </fieldset>

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

    <div class="fl-field">
      <label>
        <input type="checkbox" name="send_copy" value="1"
               <?= ((string) ($old['send_copy'] ?? '')) === '1' ? 'checked' : '' ?>>
        Email me a confirmation of this update
      </label>
    </div>

    <button class="fl-btn" type="submit">Update</button>
  </form>

  <p class="fl-meta" style="margin-top:1.5rem">
    <a href="<?= $b ?>/kim/reset">Forgot password?</a> &middot;
    <a href="<?= $b ?>/kim/list">View the KIM list</a>
  </p>
</div>
