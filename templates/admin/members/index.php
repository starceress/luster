<?php
/** @var \Luster\Models\Fanlisting   $fanlisting */
/** @var \Luster\Models\Member[]      $members */
/** @var \Luster\Models\AdditionalField[] $fields */
/** @var int    $total */
/** @var int    $page */
/** @var int    $pages */
/** @var string $search */
/** @var string $status */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
$b  = rtrim(\Luster\Core\View::e($base_url), '/');
$id = (int) $fanlisting->id;
?>
<style>
  .toolbar { display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem; }
  .toolbar input[type=text] { padding:.45rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;min-width:220px; }
  .toolbar select { padding:.45rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem; }
  .btn { display:inline-block;padding:.45rem 1rem;border-radius:6px;font-size:.8125rem;font-weight:500;text-decoration:none;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-sm { padding:.3rem .75rem;font-size:.75rem; }
  .btn-green { background:#16a34a;color:#fff; }
  .btn-red { background:#dc2626;color:#fff; }
  .btn-yellow { background:#d97706;color:#fff; }
  .btn-gray { background:#f3f4f6;color:#374151;border:1px solid #d1d5db; }
  .member-table { width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden; }
  .member-table th { background:#f9fafb;text-align:left;padding:.6rem .85rem;font-size:.75rem;color:#6b7280;border-bottom:1px solid #e5e7eb; }
  .member-table td { padding:.6rem .85rem;font-size:.8rem;color:#374151;border-bottom:1px solid #f3f4f6;vertical-align:middle; }
  .member-table tr:last-child td { border-bottom:none; }
  .member-table tr:hover td { background:#fafafa; }
  .member-table input[type=checkbox] { cursor:pointer; }
  .pagination { display:flex;gap:.35rem;align-items:center;margin-top:1rem; }
  .pagination a, .pagination span { padding:.3rem .7rem;border-radius:5px;font-size:.8rem;text-decoration:none;border:1px solid #d1d5db;color:#374151; }
  .pagination a:hover { background:#f3f4f6; }
  .pagination .cur { background:#4f46e5;color:#fff;border-color:#4f46e5; }
  .meta { font-size:.8rem;color:#6b7280;margin-bottom:.5rem; }
  .alert-success { background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.6rem 1rem;margin-bottom:.75rem;color:#15803d;font-size:.8125rem; }
  .alert-error   { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.6rem 1rem;margin-bottom:.75rem;color:#b91c1c;font-size:.8125rem; }
  .bulk-row { display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;padding:.5rem 0; }
</style>

<p style="margin-bottom:.75rem">
  <a href="<?= $b ?>/admin/fanlistings/<?= $id ?>" style="color:#6b7280;font-size:.8125rem;text-decoration:none">&larr; <?= \Luster\Core\View::e($fanlisting->name) ?></a>
</p>

<?php if ($success !== ''): ?>
  <div class="alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<!-- Toolbar: search + filter + export -->
<form method="GET" action="<?= $b ?>/admin/members/<?= $id ?>" style="margin-bottom:.75rem">
  <div class="toolbar">
    <input type="text" name="search" placeholder="Search name, email, URL&hellip;"
           value="<?= \Luster\Core\View::e($search) ?>">
    <select name="status">
      <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
      <option value="pending"  <?= $status === 'pending'  ? 'selected' : '' ?>>Pending</option>
      <option value="all"      <?= $status === 'all'      ? 'selected' : '' ?>>All</option>
    </select>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($search !== ''): ?>
      <a href="<?= $b ?>/admin/members/<?= $id ?>" class="btn btn-gray">Clear</a>
    <?php endif; ?>
    <a href="<?= $b ?>/admin/members/<?= $id ?>/export" class="btn btn-gray" style="margin-left:auto">&#8595; Export CSV</a>
  </div>
</form>

<?php if (empty($members)): ?>
  <p style="color:#6b7280;font-size:.875rem">No members found.</p>
<?php else: ?>

<p class="meta">Showing <?= count($members) ?> of <?= (int) $total ?> member(s) &mdash; page <?= (int) $page ?> of <?= (int) $pages ?></p>

<form method="POST" id="bulk-form">
  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

  <div class="bulk-row">
    <input type="checkbox" id="check-all" title="Select all"> <label for="check-all" style="font-size:.8rem;margin-right:.5rem">Select all</label>
    <span style="font-size:.8rem;color:#6b7280;margin-right:.5rem">Bulk:</span>
    <button type="button" class="btn btn-sm btn-green"
      onclick="bulkSubmit('<?= $b ?>/admin/members/<?= $id ?>/bulk','approve')">Approve</button>
    <button type="button" class="btn btn-sm btn-yellow"
      onclick="bulkSubmit('<?= $b ?>/admin/members/<?= $id ?>/bulk','reject')">Reject</button>
    <button type="button" class="btn btn-sm btn-red"
      onclick="if(confirm('Delete selected?')) bulkSubmit('<?= $b ?>/admin/members/<?= $id ?>/bulk','delete')">Delete</button>
    <input type="hidden" name="bulk_action" id="bulk-action">
  </div>

  <table class="member-table">
    <thead>
      <tr>
        <th style="width:32px"></th>
        <th>Name</th>
        <th>Email</th>
        <th>Country</th>
        <?php foreach ($fields as $field): ?>
          <th><?= \Luster\Core\View::e($field->label) ?></th>
        <?php endforeach; ?>
        <th>Added</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($members as $m): ?>
      <tr>
        <td><input type="checkbox" name="emails[]" value="<?= \Luster\Core\View::e($m->email) ?>"></td>
        <td><?= \Luster\Core\View::e($m->name) ?></td>
        <td><?= \Luster\Core\View::e($m->email) ?></td>
        <td><?= \Luster\Core\View::e($m->country) ?></td>
        <?php foreach ($fields as $field): ?>
          <td><?= \Luster\Core\View::e($m->additionalFields[$field->name] ?? '') ?></td>
        <?php endforeach; ?>
        <td style="white-space:nowrap;color:#6b7280"><?= \Luster\Core\View::e($m->added) ?></td>
        <td><?= $m->approved ? '<span style="color:#16a34a;font-size:.75rem">Approved</span>' : '<span style="color:#d97706;font-size:.75rem">Pending</span>' ?></td>
        <td style="white-space:nowrap">
          <a href="<?= $b ?>/admin/members/<?= $id ?>/edit?email=<?= urlencode($m->email) ?>"
             style="color:#4f46e5;font-size:.75rem;text-decoration:none;margin-right:.5rem">Edit</a>

          <?php if (!$m->approved): ?>
          <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/approve" style="display:inline">
            <input type="hidden" name="_csrf"         value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
            <input type="hidden" name="member_email"  value="<?= \Luster\Core\View::e($m->email) ?>">
            <button type="submit" class="btn btn-sm btn-green">Approve</button>
          </form>
          <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/reject" style="display:inline;margin-left:.25rem">
            <input type="hidden" name="_csrf"         value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
            <input type="hidden" name="member_email"  value="<?= \Luster\Core\View::e($m->email) ?>">
            <button type="submit" class="btn btn-sm btn-yellow"
                    onclick="return confirm('Reject this member?')">Reject</button>
          </form>
          <?php endif; ?>

          <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/delete" style="display:inline;margin-left:.25rem">
            <input type="hidden" name="_csrf"         value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
            <input type="hidden" name="member_email"  value="<?= \Luster\Core\View::e($m->email) ?>">
            <button type="submit" class="btn btn-sm btn-red"
                    onclick="return confirm('Delete this member permanently?')">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</form>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="pagination">
  <?php for ($p = 1; $p <= $pages; $p++): ?>
    <?php
    $qArgs = array_filter(['page' => $p, 'search' => $search, 'status' => $status]);
    $qs    = http_build_query($qArgs);
    ?>
    <?php if ($p === (int) $page): ?>
      <span class="cur"><?= $p ?></span>
    <?php else: ?>
      <a href="<?= $b ?>/admin/members/<?= $id ?>?<?= $qs ?>"><?= $p ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
document.getElementById('check-all').addEventListener('change', function () {
  document.querySelectorAll('#bulk-form input[name="emails[]"]').forEach(function (cb) {
    cb.checked = document.getElementById('check-all').checked;
  });
});
function bulkSubmit(action, bulkAction) {
  var checked = document.querySelectorAll('#bulk-form input[name="emails[]"]:checked');
  if (!checked.length) { alert('No members selected.'); return; }
  document.getElementById('bulk-action').value = bulkAction;
  document.getElementById('bulk-form').action = action;
  document.getElementById('bulk-form').submit();
}
</script>
