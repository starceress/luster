<?php
/**
 * KIM List admin — show members for one fanlisting.
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

<div class="page-header">
  <p class="page-back" style="margin:0"><a href="<?= $b ?>/admin/kim" class="neb-link">&larr; Back to KIM List</a></p>
  <?php if ($approved_count > 0): ?>
    <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/mass-email"
       class="btn btn-tertiary">Mass Email (<?= (int) $approved_count ?>)</a>
  <?php endif; ?>
</div>

<!-- ── Pending ──────────────────────────────────────────────────────────── -->
<div class="neb-card" style="margin-bottom:1.5rem">
  <div class="neb-card-header">
    Pending
    <?php if ($pending_count > 0): ?>
      <span class="neb-badge badge-upcoming" style="margin-left:.4rem"><?= (int) $pending_count ?></span>
    <?php endif; ?>
  </div>

  <?php if (empty($pendingMembers)): ?>
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      No pending entries — all caught up.
    </div>
  <?php else: ?>
    <div class="neb-table-wrap">
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
                 class="neb-link"><?= View::e($m->url) ?></a>
            <?php else: ?>
              <span class="text-dim">—</span>
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

<!-- ── Approved ─────────────────────────────────────────────────────────── -->
<div class="neb-card">
  <div class="neb-card-header">
    Approved
    <?php if ($approved_count > 0): ?>
      <span class="neb-badge badge-current" style="margin-left:.4rem"><?= (int) $approved_count ?></span>
    <?php endif; ?>
  </div>

  <?php if (empty($approvedMembers)): ?>
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      No approved entries yet.
    </div>
  <?php else: ?>
    <div class="neb-table-wrap">
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
                 class="neb-link"><?= View::e($m->url) ?></a>
            <?php else: ?>
              <span class="text-dim">—</span>
            <?php endif; ?>
          </td>
          <td class="text-dim nowrap"><?= View::e(date($admin_date_format, strtotime($m->addedAt))) ?></td>
          <td class="act-col">
            <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/edit"
               class="btn btn-tertiary btn-sm">Edit</a>
            <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/email-member"
               class="btn btn-tertiary btn-sm">Email</a>
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
