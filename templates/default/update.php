<?php
/**
 * Update membership — content fragment.
 *
 * Two-step rendering controlled by $signed_in:
 *
 * Step 1 ($signed_in = false):
 *   $sign_in_action   string  POST URL for the sign-in form
 *   $csrf_token       string
 *   $errors           string  pre-escaped HTML, or ''
 *   $field_email      string  repopulated email on error
 *   $lost_password_url string
 *
 * Step 2 ($signed_in = true):
 *   $form_action      string  POST URL for the update form
 *   $sign_out_url     string  GET URL to clear auth and return to step 1
 *   $csrf_token       string
 *   $errors           string
 *   $update_error     string  raw update-specific error (password mismatch, email taken)
 *   $field_email      string  authenticated member's current email (display only)
 *   $field_name       string  repopulated new_name on error
 *   $field_new_email  string  repopulated new_email on error
 *   $field_url        string  repopulated url on error
 *   $country_select   string  pre-built <select> HTML
 *   $fields           AdditionalField[]
 *   $old              array
 *   $captcha_question string
 *   $captcha_error    string
 *   $current_name     string  member's existing name (pre-fill placeholder)
 *   $current_url      string  member's existing URL
 *   $current_country  string  member's existing country
 *   $current_extra    array   member's existing additional field values
 */

declare(strict_types=1);

use Luster\Core\View;

$signed_in = $signed_in ?? false;
?>
<div>
  <?php if ($errors !== ''): ?>
    <div><?= $errors ?></div>
  <?php endif; ?>

<?php if (!$signed_in): ?>

  <!-- ── Step 1: Sign in ──────────────────────────────────────────────── -->
  <form method="POST" action="<?= View::e($sign_in_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div>
      <label for="upd-email">Email Address *</label>
      <input type="email" id="upd-email" name="email"
             value="<?= View::e($field_email) ?>" required maxlength="255"
             autocomplete="email">
    </div>

    <div>
      <label for="upd-password">Password *</label>
      <input type="password" id="upd-password" name="password"
             required maxlength="255" autocomplete="current-password">
    </div>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <button type="submit">Sign in</button>
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

  <form method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <?php if ($update_error !== ''): ?>
      <div><?= View::e($update_error) ?></div>
    <?php endif; ?>

    <div>
      <label for="upd-name">New Name</label>
      <input type="text" id="upd-name" name="new_name"
             value="<?= View::e($field_name) ?>"
             placeholder="<?= View::e($current_name) ?>"
             maxlength="100" autocomplete="name">
    </div>

    <div>
      <label for="upd-new-email">New Email</label>
      <input type="email" id="upd-new-email" name="new_email"
             value="<?= View::e($field_new_email) ?>"
             placeholder="<?= View::e($field_email) ?>"
             maxlength="255">
    </div>

    <div>
      <label for="upd-url">New Website URL</label>
      <input type="url" id="upd-url" name="url"
             value="<?= View::e($field_url !== '' ? $field_url : $current_url) ?>"
             maxlength="500" placeholder="https://">
      <label>
        <input type="checkbox" name="delete_url" value="1">
        Remove my website URL
      </label>
    </div>

    <div>
      <label for="country">New Country</label>
      <?= $country_select ?>
    </div>

    <?php foreach ($fields as $field): ?>
    <?php
    $currentFieldVal = $current_extra[$field->name] ?? '';
    $oldFieldVal     = $old['field_' . $field->name] ?? null;
    $fieldDisplayVal = $oldFieldVal !== null ? (string) $oldFieldVal : (string) $currentFieldVal;
    ?>
    <div>
      <label for="f-<?= View::e($field->name) ?>">
        <?= View::e($field->label) ?><?= $field->required ? ' *' : '' ?>
      </label>
      <?php if ($field->type === 'textarea'): ?>
        <textarea id="f-<?= View::e($field->name) ?>"
                  name="field_<?= View::e($field->name) ?>"
                  maxlength="255"
                  <?= $field->required ? 'required' : '' ?>><?= View::e($fieldDisplayVal) ?></textarea>

      <?php elseif ($field->type === 'dropdown'): ?>
        <select id="f-<?= View::e($field->name) ?>"
                name="field_<?= View::e($field->name) ?>"
                <?= $field->required ? 'required' : '' ?>>
          <option value=""></option>
          <?php foreach (array_filter(array_map('trim', explode(',', $field->options))) as $opt): ?>
            <option value="<?= View::e($opt) ?>"
              <?= $fieldDisplayVal === $opt ? 'selected' : '' ?>>
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
                   <?= $fieldDisplayVal === '1' ? 'checked' : '' ?>
                   <?= $field->required ? 'required' : '' ?>>
            <?= View::e($field->label) ?>
          </label>
        <?php else: ?>
          <?php $selArr = array_filter(array_map('trim', explode(',', $fieldDisplayVal))); ?>
          <?php foreach ($opts as $opt): ?>
            <label>
              <input type="checkbox"
                     name="field_<?= View::e($field->name) ?>[]" value="<?= View::e($opt) ?>"
                     <?= in_array($opt, $selArr, true) ? 'checked' : '' ?>>
              <?= View::e($opt) ?>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($field->type === 'radio'): ?>
        <?php foreach (array_filter(array_map('trim', explode(',', $field->options)), fn($o) => $o !== '') as $opt): ?>
          <label>
            <input type="radio"
                   name="field_<?= View::e($field->name) ?>" value="<?= View::e($opt) ?>"
                   <?= $fieldDisplayVal === $opt ? 'checked' : '' ?>
                   <?= $field->required ? 'required' : '' ?>>
            <?= View::e($opt) ?>
          </label>
        <?php endforeach; ?>

      <?php else: ?>
        <input type="text"
               id="f-<?= View::e($field->name) ?>"
               name="field_<?= View::e($field->name) ?>"
               value="<?= View::e($fieldDisplayVal) ?>"
               maxlength="255"
               <?= $field->required ? 'required' : '' ?>>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div>
      <label for="upd-new-pass">New Password</label>
      <input type="password" id="upd-new-pass" name="new_password"
             maxlength="255" autocomplete="new-password" placeholder="Leave blank to keep current">
    </div>

    <div>
      <label for="upd-confirm-pass">Confirm New Password</label>
      <input type="password" id="upd-confirm-pass" name="new_password_confirm"
             maxlength="255" autocomplete="new-password" placeholder="Repeat new password">
    </div>

    <!-- Honeypot -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div>
      <label for="upd-captcha">
        Captcha: <?= View::e($captcha_question) ?> *
      </label>
      <?php if ($captcha_error !== ''): ?>
        <div><?= View::e($captcha_error) ?></div>
      <?php endif; ?>
      <input type="text" id="upd-captcha" name="captcha_answer"
             required maxlength="10" autocomplete="off">
    </div>

    <button type="submit">Update Information</button>
    <button type="reset">Clear</button>
  </form>

<?php endif; ?>
</div>
