<?php
/**
 * KIM List sign-up form — content fragment.
 *
 * Variables:
 *   $form_action  string
 *   $csrf_token   string
 *   $errors       string  pre-escaped HTML error string, or ''
 *   $old          array
 *   $f            array<string,string>  keys: name, url, email, fanlisting_id, password,
 *                                             password_confirm
 *   $base_url     string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
$_linkUpdate = ($link_update !== null && $link_update !== '') ? $link_update : null;
$_linkReset  = ($link_reset  !== null && $link_reset  !== '') ? $link_reset  : null;
$_linkList   = ($link_list   !== null && $link_list   !== '') ? $link_list   : null;

if (isset($form_html) && $form_html !== null) {
    ?>
<div class="fl-kim">
  <?php if (($errors ?? '') !== ''): ?><div class="fl-errors"><?= $errors ?></div><?php endif; ?>
  <?= $form_html ?>
  <?php
  $_metaLinks = [];
  if ($_linkUpdate !== null) $_metaLinks[] = '<a href="' . View::e($_linkUpdate) . '">Update your entry</a>';
  if ($_linkReset  !== null) $_metaLinks[] = '<a href="' . View::e($_linkReset)  . '">Forgot password?</a>';
  if ($_linkList   !== null) $_metaLinks[] = '<a href="' . View::e($_linkList)   . '">View the KIM list</a>';
  ?>
  <?php if (!empty($_metaLinks)): ?>
  <p class="fl-kim-meta">Already signed up? <?= implode(' &middot; ', $_metaLinks) ?></p>
  <?php endif; ?>
</div>
    <?php
    return;
}
?>
<div class="fl-kim">
    <h2 class="fl-kim-heading">KIM List &mdash; Sign Up</h2>
    <p class="fl-kim-intro">
      KIM (Keep In Mind) is an adoption interest registry. If a fanlisting owner might
      someday give up their listing, you can sign up here to express interest in adopting it.
    </p>

    <?php if ($errors !== ''): ?>
      <div class="fl-errors"><?= $errors ?></div>
    <?php endif; ?>

    <form class="fl-form fl-form-kim-signup" method="POST" action="<?= View::e($form_action) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

      <?= $f['name'] ?>
      <?= $f['email'] ?>
      <?= $f['url'] ?>
      <?= $f['fanlisting_id'] ?>
      <?= $f['password'] ?>
      <?= $f['password_confirm'] ?>
      <?= $f['captcha_answer'] ?>

      <!-- Honeypot — hidden from humans, filled by bots -->
      <div style="display:none" aria-hidden="true">
        <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
      </div>

      <div class="fl-form-actions">
        <button class="fl-submit fl-btn" type="submit">Sign Up</button>
      </div>
    </form>

    <?php
    $_metaLinks = [];
    if ($_linkUpdate !== null) $_metaLinks[] = '<a href="' . View::e($_linkUpdate) . '">Update your entry</a>';
    if ($_linkReset  !== null) $_metaLinks[] = '<a href="' . View::e($_linkReset)  . '">Forgot password?</a>';
    if ($_linkList   !== null) $_metaLinks[] = '<a href="' . View::e($_linkList)   . '">View the KIM list</a>';
    ?>
    <?php if (!empty($_metaLinks)): ?>
    <p class="fl-kim-meta">
      Already signed up? <?= implode(' &middot; ', $_metaLinks) ?>
    </p>
    <?php endif; ?>
</div>
