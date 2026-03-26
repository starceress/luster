<?php
use Luster\Core\View;
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
$b = rtrim(View::e($base_url), '/');
?>

<div class="back-link">
  <a href="<?= $b ?>/admin/members" class="link">&larr; Back to Members</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div>
<form method="POST" action="<?= $b ?>/admin/members/settings">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card">
    <div class="card-head">Pagination</div>

    <div class="panel-content">
    <div class="form-group">
      <label class="form-label" for="m-per-page">Members per page</label>
      <input type="number" id="m-per-page" name="per_page" class="form-input"
             min="5" max="200" style="max-width:100px"
             value="<?= (int) ($cfg['per_page'] ?? 25) ?>">
      <p class="form-hint">The number of members to show per page. Min 5, max 200.</p>
    </div>
  </div>
</div>

    <button type="submit" class="btn btn-primary">Save Settings</button>
    <a href="<?= $b ?>/admin/members" class="btn btn-ghost">Cancel</a>
</form>
</div>
