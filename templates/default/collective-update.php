<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Fragment template for the collective-update embed. Renders either the
 initial email-request form (Step 1, pre-rendered from the admin-editable
 template) or the multi-listing update form (Step 2), depending on whether
 a verified token is present.
*/
use Luster\Core\Countries;
use Luster\Core\View;
?>
<div class="fl-embed fl-embed--collective-update">
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
  <p class="fl-form-intro"><strong>Updating memberships for:</strong> <strong><?= $token_email ?></strong></p>
  <p class="fl-form-intro">Select the fanlistings to apply changes to below, fill in any fields you want to change, and click <strong>Update Memberships</strong>. Leave a field blank to keep your current value.</p>

  <div class="fl-collective-update-table-wrap">
    <table class="fl-collective-update-table">
      <thead>
        <tr>
          <th></th>
          <th>Listing</th>
          <th>Subject</th>
          <th>Website URL</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($memberships as $m):
          $listing    = $m['listing'];
          $checkboxId = 'fl-cu-listing-' . (int) $listing->id;
        ?>
        <tr>
          <td><input type="checkbox" class="fl-checkbox" id="<?= View::e($checkboxId) ?>" name="listing_ids[]" value="<?= (int) $listing->id ?>" checked></td>
          <td><label for="<?= View::e($checkboxId) ?>"><?= View::e($listing->title) ?></label></td>
          <td><?= View::e($listing->subject) ?></td>
          <td><?= $listing->url !== '' ? '<a href="' . View::e($listing->url) . '" rel="nofollow noreferrer" target="_blank">' . View::e($listing->url) . '</a>' : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="fl-field fl-field--danger">
    <label class="fl-label fl-label-danger">
      <input type="checkbox" class="fl-checkbox" id="fl-cu-delete-membership" name="delete_membership" value="1">
      Permanently delete my membership(s) instead of updating them.
    </label>
    <p class="fl-field-hint">
      This immediately and permanently removes your membership from every fanlisting you select above.
      Any changes below are ignored, and this cannot be undone.
    </p>
  </div>

  <fieldset class="fl-fieldset fl-collective-update-changes">
    <legend class="fl-label">Changes to apply to selected fanlistings</legend>
    <div class="fl-field">
      <label class="fl-label" for="fl-cu-name">New Name</label>
      <input class="fl-input" type="text" id="fl-cu-name" name="name" value="" maxlength="100" placeholder="<?= View::e($firstMember->name) ?>">
    </div>
    <div class="fl-field">
      <label class="fl-label" for="fl-cu-email">New Email</label>
      <input class="fl-input" type="email" id="fl-cu-email" name="new_email" value="" maxlength="255" autocomplete="email" placeholder="<?= $token_email ?>">
      <p class="fl-hint">Changes the email on every fanlisting you select above.</p>
    </div>
    <div class="fl-field">
      <label class="fl-label" for="fl-cu-url">New Website URL</label>
      <input class="fl-input" type="url" id="fl-cu-url" name="url" value="" placeholder="<?= View::e($firstMember->url) ?>" maxlength="500">
      <label class="fl-check-label">
        <input type="checkbox" class="fl-checkbox" name="delete_url" value="1"> Remove my website URL
      </label>
    </div>
    <div class="fl-field">
      <label class="fl-label" for="fl-cu-country">Country</label>
      <?= Countries::buildSelect($firstMember->country, 'fl-cu-country', 'country') ?>
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
  </fieldset>

  <div class="fl-form-actions">
    <button type="submit" class="fl-submit fl-btn">Update Memberships</button>
    <button type="reset" class="fl-btn-secondary">Clear</button>
  </div>
</form>
<?php endif; ?>
