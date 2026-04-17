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
  <a href="<?= $b ?>/admin/kim/<?= (int) $member->fanlistingId ?>/show" class="link">&larr; Back to KIM: <?= View::e($fanlisting_name) ?>
  </a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/kim/<?= (int) $member->id ?>/edit">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="gradient-card">
    <div class="card-body">

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
        </div>
      </div>

      <div class="pc-section-label">Password</div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="kim-new-password">New Password</label>
        <div>
          <div style="position:relative">
            <input id="kim-new-password" type="password" name="new_password" class="form-input"
                   style="padding-right:42px" maxlength="255" autocomplete="new-password"
                   placeholder="Leave blank to keep current">
            <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1"
                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-dim);padding:4px;line-height:0"
                    onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-dim)'">
              <?= View::icon('eye-on') ?>
              <?= View::icon('eye-off') ?>
            </button>
          </div>
          <p class="form-hint">Minimum 8 characters. Leave blank to keep the current password.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="kim-new-password-confirm">Confirm Password</label>
        <div style="position:relative">
          <input id="kim-new-password-confirm" type="password" name="new_password_confirm"
                 class="form-input" style="padding-right:42px" maxlength="255" autocomplete="new-password">
          <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-dim);padding:4px;line-height:0"
                  onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-dim)'">
            <?= View::icon('eye-on') ?>
            <?= View::icon('eye-off') ?>
          </button>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/kim/<?= (int) $member->fanlistingId ?>/show" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
