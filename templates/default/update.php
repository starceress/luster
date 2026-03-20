<?php
/**
 * Update membership form — content fragment.
 *
 * Variables injected by UpdateController:
 *   $listing_name        string
 *   $form_action            string
 *   $csrf_token             string
 *   $errors                 string  pre-escaped HTML error string, or ''
 *   $update_error           string  raw update-specific error (password mismatch, email taken)
 *   $field_email            string  current email (pre-filled, readonly)
 *   $field_name             string  old name value
 *   $field_new_email        string  old new_email value
 *   $field_url              string  old url value
 *   $country_select         string  pre-built <select> HTML
 *   $country_field_enabled  bool
 *   $fields                 AdditionalField[]
 *   $old                    array
 *   $captcha_question       string
 *   $captcha_error          string
 *   $lost_password_url      string
 *   $base_url               string
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div class="fl-wrap">
  <h2 class="fl-title">Update your membership in <?= View::e($listing_name) ?></h2>

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <p class="fl-muted" style="font-size:.875rem">
      Enter your email and password to authenticate, then fill in only the fields you want to change.
      Leave a field blank to keep your current value.
    </p>

    <div class="fl-field">
      <label class="fl-label" for="upd-email">Email <span class="fl-required">*</span></label>
      <input class="fl-input" type="email" id="upd-email" name="email"
             value="<?= View::e($field_email) ?>" required maxlength="255" autocomplete="email">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="upd-password">Password <span class="fl-required">*</span></label>
      <input class="fl-input" type="password" id="upd-password" name="password"
             required maxlength="255" autocomplete="current-password">
      <p class="fl-muted" style="font-size:.8125rem;margin-top:.3rem">
        <a class="fl-link" href="<?= View::e($lost_password_url) ?>">Forgot your password?</a>
      </p>
    </div>

    <hr style="margin:1.25rem 0;border:none;border-top:1px solid var(--fl-border)">

    <?php if ($update_error !== ''): ?>
      <div class="fl-errors"><?= View::e($update_error) ?></div>
    <?php endif; ?>

    <div class="fl-field">
      <label class="fl-label" for="upd-name">New Name</label>
      <input class="fl-input" type="text" id="upd-name" name="new_name"
             value="<?= View::e($field_name) ?>" maxlength="100" autocomplete="name">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="upd-new-email">New Email</label>
      <input class="fl-input" type="email" id="upd-new-email" name="new_email"
             value="<?= View::e($field_new_email) ?>" maxlength="255">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="upd-url">New Website URL</label>
      <input class="fl-input" type="url" id="upd-url" name="url"
             value="<?= View::e($field_url) ?>" maxlength="500" placeholder="https://">
      <label style="margin-top:.35rem;display:flex;align-items:center;gap:.5rem;font-size:.875rem">
        <input type="checkbox" name="delete_url" value="1">
        Remove my website URL
      </label>
    </div>

    <div class="fl-field">
      <label class="fl-label" for="country">New Country</label>
      <?= $country_select ?>
    </div>

    <?php foreach ($fields as $field): ?>
    <div class="fl-field">
      <label class="fl-label" for="f-<?= View::e($field->name) ?>">
        <?= View::e($field->label) ?><?= $field->required ? ' <span class="fl-required">*</span>' : '' ?>
      </label>
      <?php if ($field->type === 'textarea'): ?>
        <textarea class="fl-textarea"
                  id="f-<?= View::e($field->name) ?>"
                  name="field_<?= View::e($field->name) ?>"
                  maxlength="255"
                  <?= $field->required ? 'required' : '' ?>><?= View::e($old['field_' . $field->name] ?? '') ?></textarea>

      <?php elseif ($field->type === 'dropdown'): ?>
        <select class="fl-select"
                id="f-<?= View::e($field->name) ?>"
                name="field_<?= View::e($field->name) ?>"
                <?= $field->required ? 'required' : '' ?>>
          <option value=""></option>
          <?php foreach (array_filter(array_map('trim', explode(',', $field->options))) as $opt): ?>
            <option value="<?= View::e($opt) ?>"
              <?= ($old['field_' . $field->name] ?? '') === $opt ? 'selected' : '' ?>>
              <?= View::e($opt) ?>
            </option>
          <?php endforeach; ?>
        </select>

      <?php elseif ($field->type === 'checkbox'): ?>
        <?php $opts = array_filter(array_map('trim', explode(',', $field->options)), fn($o) => $o !== ''); ?>
        <?php if (empty($opts)): ?>
          <label>
            <input type="checkbox"
                   name="field_<?= View::e($field->name) ?>" value="1"
                   <?= ($old['field_' . $field->name] ?? '') === '1' ? 'checked' : '' ?>
                   <?= $field->required ? 'required' : '' ?>>
            <?= View::e($field->label) ?>
          </label>
        <?php else: ?>
          <?php $oldArr = (array) ($old['field_' . $field->name] ?? []); ?>
          <?php foreach ($opts as $opt): ?>
            <label>
              <input type="checkbox"
                     name="field_<?= View::e($field->name) ?>[]" value="<?= View::e($opt) ?>"
                     <?= in_array($opt, $oldArr, true) ? 'checked' : '' ?>>
              <?= View::e($opt) ?>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($field->type === 'radio'): ?>
        <?php foreach (array_filter(array_map('trim', explode(',', $field->options)), fn($o) => $o !== '') as $opt): ?>
          <label>
            <input type="radio"
                   name="field_<?= View::e($field->name) ?>" value="<?= View::e($opt) ?>"
                   <?= ($old['field_' . $field->name] ?? '') === $opt ? 'checked' : '' ?>
                   <?= $field->required ? 'required' : '' ?>>
            <?= View::e($opt) ?>
          </label>
        <?php endforeach; ?>

      <?php else: ?>
        <input class="fl-input" type="text"
               id="f-<?= View::e($field->name) ?>"
               name="field_<?= View::e($field->name) ?>"
               value="<?= View::e($old['field_' . $field->name] ?? '') ?>"
               maxlength="255"
               <?= $field->required ? 'required' : '' ?>>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="fl-field">
      <label class="fl-label" for="upd-new-pass">New Password</label>
      <input class="fl-input" type="password" id="upd-new-pass" name="new_password"
             maxlength="255" autocomplete="new-password" placeholder="Leave blank to keep current">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="upd-confirm-pass">Confirm New Password</label>
      <input class="fl-input" type="password" id="upd-confirm-pass" name="new_password_confirm"
             maxlength="255" autocomplete="new-password" placeholder="Repeat new password">
    </div>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div class="fl-field">
      <label class="fl-label" for="upd-captcha">
        Captcha: <?= View::e($captcha_question) ?> <span class="fl-required">*</span>
      </label>
      <?php if ($captcha_error !== ''): ?>
        <div class="fl-errors"><?= View::e($captcha_error) ?></div>
      <?php endif; ?>
      <input class="fl-input" type="text" id="upd-captcha" name="captcha_answer"
             required maxlength="10" autocomplete="off">
    </div>

    <button class="fl-btn" type="submit">Update Membership</button>
  </form>
</div>
