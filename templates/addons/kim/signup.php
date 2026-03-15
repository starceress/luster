<?php
/**
 * KIM List sign-up form — content fragment.
 *
 * Variables:
 *   $form_action      string
 *   $csrf_token       string
 *   $errors           string  pre-escaped HTML error string, or ''
 *   $fanlistings      Listing[]
 *   $old              array   keys: name, email, url, fanlisting_id, send_copy
 *   $captcha_question string
 *   $captcha_error    string
 *   $base_url         string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>
<div class="fl-wrap">
  <h2 class="fl-title">KIM List — Sign Up</h2>
  <p class="fl-intro">
    KIM (Keep In Mind) is an adoption interest registry. If a fanlisting owner might
    someday give up their listing, you can sign up here to express interest in adopting it.
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
      <label class="fl-label" for="kim-url">Collective / Website URL <span class="fl-required">*</span></label>
      <input class="fl-input" type="url" id="kim-url" name="url"
             value="<?= View::e($old['url'] ?? '') ?>" required maxlength="500"
             placeholder="https://" autocomplete="url">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="kim-email">Email <span class="fl-required">*</span></label>
      <input class="fl-input" type="email" id="kim-email" name="email"
             value="<?= View::e($old['email'] ?? '') ?>" required maxlength="255" autocomplete="email">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="kim-fanlisting">Fanlisting Interested In <span class="fl-required">*</span></label>
      <select class="fl-select" id="kim-fanlisting" name="fanlisting_id" required>
        <option value="">— Select a fanlisting —</option>
        <?php foreach ($fanlistings as $fl): ?>
          <option value="<?= View::e((string) $fl->id) ?>"
            <?= ((string) ($old['fanlisting_id'] ?? '')) === (string) $fl->id ? 'selected' : '' ?>>
            <?= View::e($fl->subject) ?>
            <?= $fl->upcoming ? ' (upcoming)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fl-field">
      <label class="fl-label" for="kim-password">
        Password
        <span style="font-weight:400"> — leave blank to auto-generate; you'll receive it by email</span>
      </label>
      <input class="fl-input" type="password" id="kim-password" name="password"
             maxlength="255" autocomplete="new-password">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="kim-password-confirm">Confirm Password</label>
      <input class="fl-input" type="password" id="kim-password-confirm" name="password_confirm"
             maxlength="255" autocomplete="new-password">
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

    <div class="fl-field">
      <label>
        <input type="checkbox" name="send_copy" value="1"
               <?= ((string) ($old['send_copy'] ?? '')) === '1' ? 'checked' : '' ?>>
        Email me a copy of my submission
      </label>
    </div>

    <button class="fl-btn" type="submit">Sign Up</button>
  </form>

  <p class="fl-meta" style="margin-top:1.5rem">
    Already signed up?
    <a href="<?= $b ?>/kim/update">Update your entry</a> &middot;
    <a href="<?= $b ?>/kim/reset">Forgot password?</a> &middot;
    <a href="<?= $b ?>/kim/list">View the KIM list</a>
  </p>
</div>
