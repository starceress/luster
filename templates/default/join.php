<?php
/**
 * Join form — content fragment.
 *
 * Variables injected by JoinController:
 *   $listing_name        string
 *   $form_action            string
 *   $csrf_token             string
 *   $errors                 string  pre-escaped HTML error string, or ''
 *   $field_name             string  repopulation value
 *   $field_email            string  repopulation value
 *   $field_url              string  repopulation value
 *   $country_select         string  pre-built <select> HTML (trusted server HTML)
 *   $country_field_enabled  bool
 *   $fields                 AdditionalField[]
 *   $old                    array   keyed by 'field_{name}' for extra fields
 *   $captcha_question       string
 *   $captcha_error          string  raw (escaped in template)
 *   $send_info_checked      string  'checked' or ''
 *   $base_url               string
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div>

  <?php if ($errors !== ''): ?>
    <div><?= $errors ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div>
      <label for="join-name">Name *</label>
      <input type="text" id="join-name" name="name"
             value="<?= View::e($field_name) ?>" required maxlength="100" autocomplete="name">
    </div>

    <div>
      <label for="join-email">Email *</label>
      <input type="email" id="join-email" name="email"
             value="<?= View::e($field_email) ?>" required maxlength="255" autocomplete="email">
    </div>

    <div>
      <label for="join-url">Website URL <span class="fl-opt">(optional)</span></label>
      <input type="url" id="join-url" name="url"
             value="<?= View::e($field_url) ?>" maxlength="500" placeholder="https://" autocomplete="url">
    </div>

    <div>
      <label for="country">Country *</label>
      <?= $country_select ?>
    </div>

    <?php foreach ($fields as $field): ?>
    <div>
      <label for="f-<?= View::e($field->name) ?>">
        <?= View::e($field->label) ?><?= $field->required ? ' *' : '' ?>
      </label>
      <?php if ($field->type === 'textarea'): ?>
        <textarea id="f-<?= View::e($field->name) ?>"
                  name="field_<?= View::e($field->name) ?>"
                  maxlength="255"
                  <?= $field->required ? 'required' : '' ?>><?= View::e($old['field_' . $field->name] ?? '') ?></textarea>

      <?php elseif ($field->type === 'dropdown'): ?>
        <select id="f-<?= View::e($field->name) ?>"
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
        <input type="text"
               id="f-<?= View::e($field->name) ?>"
               name="field_<?= View::e($field->name) ?>"
               value="<?= View::e($old['field_' . $field->name] ?? '') ?>"
               maxlength="255"
               <?= $field->required ? 'required' : '' ?>>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div>
      <label for="join-password">
        Password <span class="fl-opt">(optional — one will be emailed to you if left blank)</span>
      </label>
      <input type="password" id="join-password" name="password"
             maxlength="255" autocomplete="new-password">
    </div>

    <div>
      <label for="join-password-confirm">Confirm Password <span class="fl-opt">(optional)</span></label>
      <input type="password" id="join-password-confirm" name="password_confirm"
             maxlength="255" autocomplete="new-password">
    </div>

    <!-- Honeypot — hidden from humans, filled by bots -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <div>
      <label for="join-captcha">
        Captcha: <?= View::e($captcha_question) ?> *
      </label>
      <?php if ($captcha_error !== ''): ?>
        <div><?= View::e($captcha_error) ?></div>
      <?php endif; ?>
      <input type="text" id="join-captcha" name="captcha_answer"
             required maxlength="10" autocomplete="off">
    </div>

    <div>
      <label>
        <input type="checkbox" name="send_info" value="1" <?= $send_info_checked ?>>
        Send me a confirmation email
      </label>
    </div>

    <div>
      <button type="submit">Join Fanlisting</button>
      <button type="reset">Clear</button>
    </div>
  </form>
</div>
