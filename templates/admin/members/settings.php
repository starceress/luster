<?php
/**
 * Admin — Members display settings.
 *
 * Variables provided by MemberController::indexSettings() via layout():
 * @var array  $cfg
 * @var string $success
 * @var string $error
 * @var string $base_url
 * @var string $csrf_token
 */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>

<p style="margin-bottom:1.25rem">
  <a href="<?= $b ?>/admin/members" class="neb-link">&larr; Back to Members</a>
</p>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div>
<form method="POST" action="<?= $b ?>/admin/members/settings">
  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

  <div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
    <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem">Pagination</div>
    <div class="form-group" style="margin-bottom:0">
      <label class="form-label" for="m-per-page">Members per page</label>
      <input type="number" id="m-per-page" name="per_page" class="form-input"
             min="5" max="200" style="max-width:100px"
             value="<?= (int) ($cfg['per_page'] ?? 25) ?>">
      <p class="form-hint">How many members to show per page in the admin member list. Min 5, max 200. Default is 25.</p>
    </div>
  </div>

  <div style="display:flex;gap:.75rem;align-items:center">
    <button type="submit" class="btn btn-primary">Save Settings</button>
    <a href="<?= $b ?>/admin/members" class="btn btn-ghost">Cancel</a>
  </div>
</form>
</div>
