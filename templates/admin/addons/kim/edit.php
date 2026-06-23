<?php
/**
 * KIM List admin - edit a KIM member.
 *
 * Variables:
 *   $member          KimMember
 *   $fanlistings     Listing[]
 *   $fanlisting_name string
 *   $base_url        string
 *   $csrf_token      string
 *   $error           string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/kim?listing_id=<?= (int) $member->fanlistingId ?>" class="link">&larr; Back to KIM: <?= View::e($fanlisting_name) ?></a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/kim/<?= (int) $member->id ?>/edit">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('kim') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title"><?= View::e($member->name) ?></div>
        <div class="pc-head-sub">Editing Keep In Mind member &mdash; <?= View::e($fanlisting_name) ?></div>
      </div>
    </div>
    <div class="pc-section">

      <div class="pc-section-label">Information</div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-name">Name <span class="req">*</span></label>
        <input id="kim-name" type="text" name="name" class="form"
               value="<?= View::e($member->name) ?>" required maxlength="100">
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-email">Email <span class="req">*</span></label>
        <input id="kim-email" type="email" name="email" class="form"
               value="<?= View::e($member->email) ?>" required maxlength="255">
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-url">URL</label>
        <input id="kim-url" type="url" name="url" class="form"
               value="<?= View::e($member->url) ?>" maxlength="500" placeholder="https://">
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="kim-fanlisting">Fanlisting <span class="req">*</span></label>
        <div>
          <div class="select-wrap">
            <select id="kim-fanlisting" name="fanlisting_id"  required>
              <?php foreach ($fanlistings as $fl): ?>
                <option value="<?= (int) $fl->id ?>"
                  <?= $fl->id === $member->fanlistingId ? 'selected' : '' ?>>
                  <?= View::e($fl->subject) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="listing-field-row">
        <label class="listing-field-label">Granted</label>
        <div class="radio-group">
          <label class="radio-label">
            <input type="radio" name="granted" value="1" <?= $member->granted ? 'checked' : '' ?>> Yes
          </label>
          <label class="radio-label">
            <input type="radio" name="granted" value="0" <?= !$member->granted ? 'checked' : '' ?>> No
          </label>
        </div>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Password</div>

      
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="kim-new-password">New Password</label>
        <div>
          <div class="input-icon-wrap">
            <input id="kim-new-password" type="password" name="new_password" class="form"
                   maxlength="255" autocomplete="new-password"
                   placeholder="Leave blank to keep current">
            <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1" class="input-icon-btn">
              <?= View::icon('eye-on') ?>
              <?= View::icon('eye-off') ?>
            </button>
          </div>
          <p class="form-hint">Minimum 12 characters. Leave blank to keep the current password.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-new-password-confirm">Confirm Password</label>
        <div class="input-icon-wrap">
          <input id="kim-new-password-confirm" type="password" name="new_password_confirm"
                 class="form" maxlength="255" autocomplete="new-password">
          <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1" class="input-icon-btn">
            <?= View::icon('eye-on') ?>
            <?= View::icon('eye-off') ?>
          </button>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="reset" class="btn btn-quaternary js-form-reset">Reset to Original</button>
    </div>
  </div>
</form>
