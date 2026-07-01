<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Fragment template for the collective-update embed. Renders either the
 initial email-request form (Step 1, pre-rendered from the admin-editable
 template) or the multi-listing update form (Step 2), depending on whether
 a verified token is present.
*/
use Luster\Core\View;
?>
<?php if ($success_html !== ''): ?>
<?= $success_html ?>
<?php elseif (!$token_valid): ?>
<?php /* Step 1: email-request form (rendered from collective_update_form_template) */ ?>
<?php if ($error !== ''): ?>
<p class="fl-errors"><?= $error ?></p>
<?php endif; ?>
<?= $request_form_html ?>
<?php else: ?>
<?php /* Step 2: update form — token verified */ ?>
<?php if ($error !== ''): ?>
<p class="fl-errors"><?= $error ?></p>
<?php endif; ?>
<?php
  // Pre-fill from first membership's member data
  $firstMember = $memberships[0]['member'];
?>
<form class="fl-form fl-collective-update-form" method="post" action="<?= View::e($form_action) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_luster_embed_form" value="collective_update">
  <input type="hidden" name="_cu_token" value="<?= View::e($raw_token) ?>">
  <div style="display:none" aria-hidden="true"><input type="text" name="website_confirm" tabindex="-1" autocomplete="off"></div>
  <p class="fl-hint">Updating memberships for: <strong><?= $token_email ?></strong></p>
  <div class="fl-field">
    <label class="fl-label" for="fl-cu-name">Name</label>
    <input class="fl-input" type="text" id="fl-cu-name" name="name" value="<?= View::e($firstMember->name) ?>" maxlength="100">
  </div>
  <div class="fl-field">
    <label class="fl-label" for="fl-cu-url">Website URL</label>
    <input class="fl-input" type="url" id="fl-cu-url" name="url" value="<?= View::e($firstMember->url) ?>" maxlength="500">
    <label class="fl-check-label">
      <input type="checkbox" class="fl-checkbox" name="delete_url" value="1"> Remove my website URL
    </label>
  </div>
  <div class="fl-field">
    <label class="fl-label" for="fl-cu-country">Country</label>
    <select class="fl-select" id="fl-cu-country" name="country">
      <option value="">-- Select --</option>
      <?php foreach ($countries as $code => $label): ?>
      <option value="<?= View::e($code) ?>"<?= $firstMember->country === $code ? ' selected' : '' ?>><?= View::e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fl-field">
    <label class="fl-label" for="fl-cu-password">New password (optional)</label>
    <input class="fl-input" type="password" id="fl-cu-password" name="new_password" maxlength="255" autocomplete="new-password">
    <p class="fl-hint">Minimum 12 characters. Leave blank to keep your current password.</p>
  </div>
  <div class="fl-field">
    <label class="fl-label" for="fl-cu-password-confirm">Confirm new password</label>
    <input class="fl-input" type="password" id="fl-cu-password-confirm" name="new_password_confirm" maxlength="255" autocomplete="new-password">
  </div>
  <div class="fl-field fl-collective-update-listings">
    <p class="fl-label">Apply to these fanlistings:</p>
    <ul class="fl-collective-update-list">
      <?php foreach ($memberships as $m): ?>
      <li class="fl-collective-update-item">
        <label class="fl-check-label">
          <input type="checkbox" class="fl-checkbox" name="listing_ids[]" value="<?= (int) $m['listing']->id ?>" checked>
          <?= View::e($m['listing']->title) ?>
          <?php if ($m['listing']->url !== ''): ?>
          <span class="fl-collective-update-url">(<a href="<?= View::e($m['listing']->url) ?>" rel="nofollow noreferrer" target="_blank"><?= View::e($m['listing']->url) ?></a>)</span>
          <?php endif; ?>
        </label>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="fl-field">
    <button type="submit" class="fl-btn">Update memberships</button>
  </div>
</form>
<?php endif; ?>
