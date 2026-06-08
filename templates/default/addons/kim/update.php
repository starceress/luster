<?php
/**
 * KIM List update form — content fragment.
 *
 * Variables:
 *   $form_action  string
 *   $csrf_token   string
 *   $errors       string  pre-escaped HTML error string, or ''
 *   $old          array   keys: send_copy
 *   $f            array<string,string>  keys: name, current_email, current_password,
 *                                             fanlisting_id, new_email, new_url,
 *                                             new_password, new_password_confirm, captcha_answer
 *   $base_url     string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
$_linkReset = ($link_reset !== null && $link_reset !== '') ? $link_reset : null;
$_linkList  = ($link_list  !== null && $link_list  !== '') ? $link_list  : null;
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

  <form class="fl-form" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <?= $f['name'] ?>
    <?= $f['current_email'] ?>
    <?= $f['current_password'] ?>
    <?= $f['fanlisting_id'] ?>

    <fieldset class="fl-fieldset">
      <legend>Optional Updates</legend>

      <?= $f['new_email'] ?>
      <?= $f['new_url'] ?>
      <?= $f['new_password'] ?>
      <?= $f['new_password_confirm'] ?>
    </fieldset>

    <!-- Honeypot — hidden from humans, filled by bots -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <?= $f['captcha_answer'] ?>

    <div class="fl-field">
      <label>
        <input type="checkbox" name="send_copy" value="1"
               <?= ((string) ($old['send_copy'] ?? '')) === '1' ? 'checked' : '' ?>>
        Email me a confirmation of this update
      </label>
    </div>

    <div class="fl-actions">
      <button class="fl-btn" type="submit">Update</button>
    </div>
  </form>

  <?php
  $_metaLinks = [];
  if ($_linkReset !== null) $_metaLinks[] = '<a href="' . View::e($_linkReset) . '">Forgot password?</a>';
  if ($_linkList  !== null) $_metaLinks[] = '<a href="' . View::e($_linkList)  . '">View the KIM list</a>';
  ?>
  <?php if (!empty($_metaLinks)): ?>
  <p class="fl-meta">
    <?= implode(' &middot; ', $_metaLinks) ?>
  </p>
  <?php endif; ?>
</div>
