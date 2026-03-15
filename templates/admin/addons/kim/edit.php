<?php
/**
 * KIM List admin — edit a KIM member.
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

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="page-header">
  <div class="page-header-left">
    <nav class="breadcrumb">
      <a href="<?= $b ?>/admin/addons">Add-ons</a>
      <span class="breadcrumb-sep">/</span>
      <a href="<?= $b ?>/admin/kim">KIM List</a>
      <span class="breadcrumb-sep">/</span>
      <a href="<?= $b ?>/admin/kim/<?= View::e((string) $member->fanlistingId) ?>/show">
        <?= View::e($fanlisting_name) ?>
      </a>
      <span class="breadcrumb-sep">/</span>
      <span>Edit</span>
    </nav>
    <h1>Edit KIM Member</h1>
  </div>
</div>

<div class="neb-card">
  <form method="POST" action="<?= $b ?>/admin/kim/<?= View::e((string) $member->id) ?>/edit">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="form-group">
      <label class="form-label" for="kim-name">Name <span class="text-danger">*</span></label>
      <input class="form-input" type="text" id="kim-name" name="name"
             value="<?= View::e($member->name) ?>" required maxlength="100">
    </div>

    <div class="form-group">
      <label class="form-label" for="kim-email">Email <span class="text-danger">*</span></label>
      <input class="form-input" type="email" id="kim-email" name="email"
             value="<?= View::e($member->email) ?>" required maxlength="255">
    </div>

    <div class="form-group">
      <label class="form-label" for="kim-url">URL</label>
      <input class="form-input" type="url" id="kim-url" name="url"
             value="<?= View::e($member->url) ?>" maxlength="500" placeholder="https://">
    </div>

    <div class="form-group">
      <label class="form-label" for="kim-fanlisting">Fanlisting <span class="text-danger">*</span></label>
      <select class="form-input" id="kim-fanlisting" name="fanlisting_id" required>
        <?php foreach ($fanlistings as $fl): ?>
          <option value="<?= View::e((string) $fl->id) ?>"
            <?= $fl->id === $member->fanlistingId ? 'selected' : '' ?>>
            <?= View::e($fl->subject) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label" for="kim-new-password">
        New Password
        <span class="form-hint">(min 8 chars; leave blank to keep current)</span>
      </label>
      <input class="form-input" type="password" id="kim-new-password" name="new_password"
             maxlength="255" autocomplete="new-password">
    </div>

    <div class="form-group">
      <label class="form-label" for="kim-new-password-confirm">Confirm New Password</label>
      <input class="form-input" type="password" id="kim-new-password-confirm" name="new_password_confirm"
             maxlength="255" autocomplete="new-password">
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/kim/<?= View::e((string) $member->fanlistingId) ?>/show"
         class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
