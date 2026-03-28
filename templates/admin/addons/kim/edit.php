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

<div class="page-topbar">
  <a href="<?= $b ?>/admin/kim/<?= (int) $member->fanlistingId ?>/show" class="back-link">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    Back to <?= View::e($fanlisting_name) ?>
  </a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/kim/<?= (int) $member->id ?>/edit">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card listing-field-card" style="margin-bottom:0">

    <!-- Identity -->
    <div class="listing-field-section">
      <div class="pc-section-label">Identity</div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-name">Name <span class="req">*</span></label>
        <input id="kim-name" type="text" name="name" class="form-input"
               value="<?= View::e($member->name) ?>" required maxlength="100">
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-email">Email <span class="req">*</span></label>
        <input id="kim-email" type="email" name="email" class="form-input"
               value="<?= View::e($member->email) ?>" required maxlength="255">
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-url">URL</label>
        <input id="kim-url" type="url" name="url" class="form-input"
               value="<?= View::e($member->url) ?>" maxlength="500" placeholder="https://">
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="kim-fanlisting">Fanlisting <span class="req">*</span></label>
        <div>
          <div class="select-wrap">
            <select id="kim-fanlisting" name="fanlisting_id" class="form-select" required>
              <?php foreach ($fanlistings as $fl): ?>
                <option value="<?= (int) $fl->id ?>"
                  <?= $fl->id === $member->fanlistingId ? 'selected' : '' ?>>
                  <?= View::e($fl->subject) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="form-hint">Move this member to a different listing if needed.</p>
        </div>
      </div>
    </div><!-- /.listing-field-section (identity) -->

    <!-- Password -->
    <div class="listing-field-section" style="border-top:1px solid var(--border)">
      <div class="pc-section-label">Password</div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="kim-new-password">New Password</label>
        <div>
          <div class="input-icon-wrap">
            <input id="kim-new-password" type="password" name="new_password" class="form-input"
                   maxlength="255" autocomplete="new-password"
                   placeholder="Leave blank to keep current">
            <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
              <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <p class="form-hint">Minimum 8 characters. Leave blank to keep the current password.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-new-password-confirm">Confirm Password</label>
        <div class="input-icon-wrap">
          <input id="kim-new-password-confirm" type="password" name="new_password_confirm"
                 class="form-input" maxlength="255" autocomplete="new-password">
          <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
            <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
      </div>
    </div><!-- /.listing-field-section (password) -->

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/kim/<?= (int) $member->fanlistingId ?>/show" class="btn btn-ghost">Cancel</a>
    </div>

  </div><!-- /.listing-field-card -->
</form>
