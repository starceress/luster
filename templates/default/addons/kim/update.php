<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 KIM List update sign-in form content fragment (Keep In Mind add-on, step 1 of
 2). Presents an email + password form so the visitor can authenticate before
 managing their KIM entries in the dashboard (step 2). Rendered via
 View::renderFragment() from embed/kim-update.php. Supports custom form
 templates.
*/

declare(strict_types=1);

use Luster\Core\View;

$b          = rtrim(View::e($base_url), '/');
$_linkReset = (($link_reset ?? null) !== null && $link_reset !== '') ? $link_reset : null;
$_linkList  = (($link_list  ?? null) !== null && $link_list  !== '') ? $link_list  : null;

if (($success_html ?? '') !== '') {
    echo '<div class="fl-kim">' . $success_html . '</div>';
    return;
}

if (isset($form_html) && $form_html !== null) {
    ?>
<div class="fl-kim">
  <?php if (($errors ?? '') !== ''): ?><div class="fl-errors"><?= $errors ?></div><?php endif; ?>
  <?= $form_html ?>
  <?php
  $_metaLinks = [];
  if ($_linkReset !== null) $_metaLinks[] = '<a href="' . View::e($_linkReset) . '">Forgot password?</a>';
  if ($_linkList  !== null) $_metaLinks[] = '<a href="' . View::e($_linkList)  . '">View the KIM list</a>';
  ?>
  <?php if (!empty($_metaLinks)): ?>
  <p class="fl-kim-meta"><?= implode(' &middot; ', $_metaLinks) ?></p>
  <?php endif; ?>
</div>
    <?php
    return;
}
?>
<div class="fl-kim">
  <h2 class="fl-kim-heading">KIM List &mdash; Sign In</h2>
  <p class="fl-kim-intro">Sign in to manage your KIM List entries across all fanlistings.</p>

  <?php if ($errors !== ''): ?>
    <div class="fl-errors"><?= $errors ?></div>
  <?php endif; ?>

  <form class="fl-form fl-form-kim-update" method="POST" action="<?= View::e($form_action) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <?php if (!empty($form_marker ?? '')): ?><input type="hidden" name="_luster_embed_form" value="<?= View::e($form_marker) ?>"><?php endif; ?>

    <!-- Honeypot — hidden from humans, filled by bots -->
    <div style="display:none" aria-hidden="true">
      <input type="text" name="website_confirm" tabindex="-1" autocomplete="off">
    </div>

    <?= $f['email'] ?>
    <?= $f['password'] ?>

    <div class="fl-form-actions">
      <button class="fl-submit fl-btn" type="submit">Sign In</button>
    </div>
  </form>

  <?php
  $_metaLinks = [];
  if ($_linkReset !== null) $_metaLinks[] = '<a href="' . View::e($_linkReset) . '">Forgot password?</a>';
  if ($_linkList  !== null) $_metaLinks[] = '<a href="' . View::e($_linkList)  . '">View the KIM list</a>';
  ?>
  <?php if (!empty($_metaLinks)): ?>
  <p class="fl-kim-meta">
    <?= implode(' &middot; ', $_metaLinks) ?>
  </p>
  <?php endif; ?>
</div>
