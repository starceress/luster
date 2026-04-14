<?php
/**
 * KIM List admin - show members for one fanlisting.
 *
 * Variables:
 *   $listing         Listing
 *   $members         KimMember[]
 *   $pending_count   int
 *   $approved_count  int
 *   $base_url        string
 *   $csrf_token      string
 *   $success         string
 *   $error           string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

$pendingMembers  = array_filter($members, static fn($m) => $m->pending);
$approvedMembers = array_filter($members, static fn($m) => !$m->pending);
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

  <p class="page-back" style="margin:0"><a href="<?= $b ?>/admin/kim" class="link">&larr; Back to KIM List Overview</a></p>
  <?php if ($approved_count > 0): ?>
    <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/mass-email"
       class="btn btn-quaternary">Mass Email (<?= (int) $approved_count ?>)</a>
  <?php endif; ?>

<!--  Pending  -->
<div class="card" style="margin-bottom:1.5rem">
  <div class="card-head">
    <div class="card-head-title">Pending</div>
    <?php if ($pending_count > 0): ?>
      <span class="badge badge-upcoming" style="margin-left:.4rem"><?= (int) $pending_count ?></span>
    <?php endif; ?>
  </div>

  <?php if (empty($pendingMembers)): ?>
    <div class="empty">
      <?= View::icon('check-badge', ['width' => '40', 'height' => '40']) ?>
      No pending entries - all caught up.
    </div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>URL</th>
          <th>Date</th>
          <th class="act-col"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingMembers as $m): ?>
        <tr>
          <td class="text-strong"><?= View::e($m->name) ?></td>
          <td class="text-muted"><?= View::e($m->email) ?></td>
          <td>
            <?php if ($m->url !== ''): ?>
              <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                 class="link"><?= View::e($m->url) ?></a>
            <?php else: ?>
              <span class="text-dim">-</span>
            <?php endif; ?>
          </td>
          <td class="text-dim nowrap"><?= View::e(date($admin_date_format, strtotime($m->addedAt))) ?></td>
          <td class="act-col">
            <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/edit"
               class="btn btn-tertiary btn-sm">Edit</a>
            <form method="POST" action="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/approve"
                  style="display:inline">
              <input type="hidden" name="_csrf"    value="<?= View::e($csrf_token) ?>">
              <input type="hidden" name="from_fid" value="<?= (int) $listing->id ?>">
              <button type="submit" class="btn btn-primary btn-sm">Approve</button>
            </form>
            <form method="POST" action="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/delete"
                  style="display:inline"
                  data-del-modal data-del-entity="kim_member"
                  data-del-name="<?= View::e($m->name) ?>">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<!--  Approved  -->
<div class="card">
  <div class="card-head">
    <div class="card-head-title">Approved</div>
    <?php if ($approved_count > 0): ?>
      <span class="badge badge-current" style="margin-left:.4rem"><?= (int) $approved_count ?></span>
    <?php endif; ?>
  </div>

  <?php if (empty($approvedMembers)): ?>
    <div class="empty">
      <?= View::icon('check-badge', ['width' => '40', 'height' => '40']) ?>
      No approved entries yet.
    </div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>URL</th>
          <th>Date</th>
          <th class="act-col"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($approvedMembers as $m): ?>
        <tr>
          <td class="text-strong"><?= View::e($m->name) ?></td>
          <td class="text-muted"><?= View::e($m->email) ?></td>
          <td>
            <?php if ($m->url !== ''): ?>
              <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                 class="link"><?= View::e($m->url) ?></a>
            <?php else: ?>
              <span class="text-dim">-</span>
            <?php endif; ?>
          </td>
          <td class="text-dim nowrap"><?= View::e(date($admin_date_format, strtotime($m->addedAt))) ?></td>
          <td class="act-col">
            <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/edit"
               class="btn btn-tertiary btn-sm">Edit</a>
            <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/email-member"
               class="btn btn-quaternary btn-sm">Email</a>
            <form method="POST" action="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/delete"
                  style="display:inline"
                  data-del-modal data-del-entity="kim_member"
                  data-del-name="<?= View::e($m->name) ?>">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
