<?php

declare(strict_types=1);

/** @var array                       $grouped         [{fanlisting: Fanlisting, members: Member[], approved_count: int}] */
/** @var \Luster\Models\Fanlisting[] $all_fanlistings */
/** @var string                      $success */
/** @var string                      $error */
/** @var string                      $base_url */
/** @var string                      $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="neb-toolbar">
  <div class="neb-filter-row">
    <span style="font-weight:600;color:var(--text)">Manage:</span>
    <div class="select-wrap">
      <select class="form-select" onchange="location=this.value">
        <option value="">All pending members</option>
        <?php foreach ($all_fanlistings as $fl): ?>
          <option value="<?= $b ?>/admin/members/<?= (int) $fl->id ?>?status=all">
            <?= \Luster\Core\View::e($fl->name) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <form method="GET" style="display:flex;gap:.5rem;align-items:center">
      <input type="text" name="search" value="<?= \Luster\Core\View::e($search ?? '') ?>"
             placeholder="Search pending..." class="form-input" style="max-width:220px">
      <button type="submit" class="btn btn-primary">Search</button>
    </form>
  </div>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/members/export-pending" class="btn btn-ghost">&#8595; Export CSV</a>
    <a href="<?= $b ?>/admin/members/settings" class="btn btn-ghost">Settings</a>
  </div>
</div>

<?php if (empty($grouped)): ?>
  <div class="neb-card">
    <p class="text-muted" style="padding:1.25rem;font-size:.875rem">No pending members across any fanlisting.</p>
  </div>
<?php else: ?>
  <?php foreach ($grouped as $g): ?>
  <?php
    $fl             = $g['fanlisting'];
    $flId           = (int) $fl->id;
    $pendingMembers = $g['members'];
    $pendingCount   = count($pendingMembers);
    $approvedCount  = (int) ($g['approved_count'] ?? 0);
  ?>
  <div class="neb-card" style="margin-bottom:1.25rem;overflow:hidden">
    <div class="neb-card-header">
      <a href="<?= $b ?>/admin/members/<?= $flId ?>" class="neb-link"><?= \Luster\Core\View::e($fl->name) ?></a>
      <span class="text-dim" style="font-weight:400;text-transform:none;letter-spacing:0;margin-left:.25rem">
        &mdash; <?= $pendingCount ?> pending<?= $approvedCount > 0 ? ', ' . $approvedCount . ' approved' : '' ?>
      </span>
    </div>
    <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/bulk">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="from" value="overview">
    <table class="data-table">
      <thead>
        <tr>
          <th class="cb-col">
            <input type="checkbox" class="sel-all" data-fid="<?= $flId ?>" title="Select all">
          </th>
          <th>Name</th>
          <th>Email</th>
          <th>Country</th>
          <th>URL</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingMembers as $m): ?>
        <tr>
          <td class="cb-col">
            <input type="checkbox" name="emails[]" class="row-cb-<?= $flId ?>"
                   value="<?= \Luster\Core\View::e($m->email) ?>">
          </td>
          <td class="text-strong"><?= \Luster\Core\View::e($m->name) ?></td>
          <td><?= \Luster\Core\View::e($m->email) ?></td>
          <td><?= \Luster\Core\View::e($m->country) ?></td>
          <td>
            <?php if ($m->url !== ''): ?>
              <a href="<?= \Luster\Core\View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                 class="neb-link" style="font-size:.8rem"><?= \Luster\Core\View::e($m->url) ?></a>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="act-col">
            <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/approve"
                  style="display:inline">
              <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
              <button type="submit" class="btn btn-primary btn-sm">Approve</button>
            </form>
            <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/delete"
                  style="display:inline;margin-left:.25rem">
              <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
              <button type="submit" class="btn btn-danger btn-sm"
                      onclick="return confirm('Reject this member?')">Reject</button>
            </form>
            <a href="<?= $b ?>/admin/members/<?= $flId ?>/emailmember?email=<?= urlencode($m->email) ?>"
               class="btn btn-secondary btn-sm" style="margin-left:.25rem">Email</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="neb-bulk">
      <span style="font-weight:600;margin-right:.5rem;color:var(--text)">Bulk:</span>
      <div class="select-wrap">
        <select name="bulk_action" class="form-select" style="padding:.3rem .6rem;font-size:.8125rem">
          <option value="approve">Approve</option>
          <option value="delete">Delete</option>
        </select>
      </div>
      <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirmBulk()">Apply</button>
    </div>
    </form>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.sel-all').forEach(function (selAll) {
  selAll.addEventListener('change', function () {
    var fid = this.dataset.fid;
    document.querySelectorAll('.row-cb-' + fid).forEach(function (cb) {
      cb.checked = selAll.checked;
    });
  });
});

window.confirmBulk = function () {
  var action = event.target.closest('form').querySelector('select[name="bulk_action"]').value;
  var checked = event.target.closest('form').querySelectorAll('input[name="emails[]"]:checked');
  if (!checked.length) { alert('No members selected.'); return false; }
  if (action === 'delete') {
    return confirm('Delete ' + checked.length + ' selected member(s)? This cannot be undone.');
  }
  return true;
};
</script>
