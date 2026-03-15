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
  <div class="page-header-left">
    <nav class="breadcrumb">
      <a href="<?= $b ?>/admin/addons">Add-ons</a>
      <span class="breadcrumb-sep">/</span>
      <a href="<?= $b ?>/admin/kim">KIM List</a>
      <span class="breadcrumb-sep">/</span>
      <span><?= View::e($listing->subject) ?></span>
    </nav>
    <h1>KIM — <?= View::e($listing->subject) ?></h1>
  </div>
  <?php if ($approved_count > 0): ?>
  <div class="page-header-right">
    <a href="<?= $b ?>/admin/kim/<?= View::e((string) $listing->id) ?>/mass-email"
       class="btn btn-secondary">Mass Email (<?= View::e((string) $approved_count) ?>)</a>
  </div>
  <?php endif; ?>
</div>

<!-- ── Pending ──────────────────────────────────────────────────────────── -->
<div class="neb-card" style="margin-bottom:1.5rem">
  <h2 class="neb-card-title">
    Pending
    <?php if ($pending_count > 0): ?>
      <span class="badge badge-warning"><?= View::e((string) $pending_count) ?></span>
    <?php endif; ?>
  </h2>

  <?php if (empty($pendingMembers)): ?>
    <p class="neb-empty">No pending entries.</p>
  <?php else: ?>
    <table class="neb-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>URL</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingMembers as $m): ?>
        <tr>
          <td><?= View::e($m->name) ?></td>
          <td><?= View::e($m->email) ?></td>
          <td>
            <?php if ($m->url !== ''): ?>
              <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"><?= View::e($m->url) ?></a>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= View::e($m->addedAt) ?></td>
          <td class="actions">
            <a href="<?= $b ?>/admin/kim/<?= View::e((string) $m->id) ?>/edit"
               class="btn btn-secondary btn-sm">Edit</a>
            <form method="POST" action="<?= $b ?>/admin/kim/<?= View::e((string) $m->id) ?>/approve"
                  style="display:inline">
              <input type="hidden" name="_csrf"    value="<?= View::e($csrf_token) ?>">
              <input type="hidden" name="from_fid" value="<?= View::e((string) $listing->id) ?>">
              <button type="submit" class="btn btn-success btn-sm">Approve</button>
            </form>
            <form method="POST" action="<?= $b ?>/admin/kim/<?= View::e((string) $m->id) ?>/delete"
                  style="display:inline"
                  onsubmit="return confirm('Delete this KIM entry for <?= View::e(addslashes($m->name)) ?>?')">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ── Approved ─────────────────────────────────────────────────────────── -->
<div class="neb-card">
  <h2 class="neb-card-title">
    Approved
    <?php if ($approved_count > 0): ?>
      <span class="badge badge-success"><?= View::e((string) $approved_count) ?></span>
    <?php endif; ?>
  </h2>

  <?php if (empty($approvedMembers)): ?>
    <p class="neb-empty">No approved entries.</p>
  <?php else: ?>
    <table class="neb-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Email</th>
          <th>URL</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($approvedMembers as $m): ?>
        <tr>
          <td><?= View::e($m->name) ?></td>
          <td><?= View::e($m->email) ?></td>
          <td>
            <?php if ($m->url !== ''): ?>
              <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"><?= View::e($m->url) ?></a>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= View::e($m->addedAt) ?></td>
          <td class="actions">
            <a href="<?= $b ?>/admin/kim/<?= View::e((string) $m->id) ?>/edit"
               class="btn btn-secondary btn-sm">Edit</a>
            <a href="<?= $b ?>/admin/kim/<?= View::e((string) $m->id) ?>/email-member"
               class="btn btn-secondary btn-sm">Email</a>
            <form method="POST" action="<?= $b ?>/admin/kim/<?= View::e((string) $m->id) ?>/delete"
                  style="display:inline"
                  onsubmit="return confirm('Delete this KIM entry for <?= View::e(addslashes($m->name)) ?>?')">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
