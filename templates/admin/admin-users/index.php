<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var \Luster\Models\AdminUser[] $users
 * @var int                        $current_id   ID of the currently logged-in admin
 * @var string                     $success
 * @var string                     $error
 * @var string                     $base_url
 * @var string                     $csrf_token
 */

$b = rtrim(View::e($base_url ?? ''), '/');
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem">
  <p class="text-muted" style="font-size:.875rem;margin:0">
    <?= count($users) ?> admin account<?= count($users) !== 1 ? 's' : '' ?>
  </p>
  <a href="<?= $b ?>/admin/admin-users/add" class="btn btn-primary btn-sm">+ Add Account</a>
</div>

<div class="neb-card">
  <table class="data-table">
    <thead>
      <tr>
        <th>Username</th>
        <th>Email</th>
        <th>Last Login</th>
        <th>Created</th>
        <th class="act-col"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $user): ?>
      <tr>
        <td style="font-weight:600;color:var(--text)">
          <?= View::e($user->username) ?>
          <?php if ($user->id === $current_id): ?>
            <span class="badge badge-info" style="font-size:.65rem;margin-left:.4rem">You</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.875rem;color:var(--text-muted)"><?= View::e($user->email) ?></td>
        <td style="font-size:.8rem;color:var(--text-dim)">
          <?= $user->lastLogin !== null ? View::e(substr($user->lastLogin, 0, 16)) : '<span style="color:var(--text-dim)">Never</span>' ?>
        </td>
        <td style="font-size:.8rem;color:var(--text-dim);white-space:nowrap">
          <?= View::e(substr($user->createdAt, 0, 10)) ?>
        </td>
        <td class="act-col">
          <a href="<?= $b ?>/admin/admin-users/<?= $user->id ?>/edit"
             class="btn btn-secondary btn-sm">Edit</a>
          <?php if ($user->id !== $current_id): ?>
            <form method="POST"
                  action="<?= $b ?>/admin/admin-users/<?= $user->id ?>/delete"
                  onsubmit="return confirm('Delete admin account \'<?= View::e($user->username) ?>\'? This cannot be undone.')">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
