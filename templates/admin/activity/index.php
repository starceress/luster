<?php
/** @var array[]  $entries */
/** @var int      $total */
/** @var int      $page */
/** @var int      $pages */
/** @var string   $filter_action */
/** @var string[] $actions */
/** @var string   $success */
/** @var string   $base_url */
/** @var string   $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>

<!-- Page header -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
  <h1 style="margin:0;font-size:1.25rem;font-weight:700;color:var(--text)">
    Activity Log
    <?php if (($total ?? 0) > 0): ?>
      <span style="font-size:.8rem;font-weight:400;color:var(--text-muted);margin-left:.4rem">(<?= (int) $total ?> entries)</span>
    <?php endif; ?>
  </h1>
  <?php if (($total ?? 0) > 0): ?>
    <form method="POST"
          action="<?= $b ?>/admin/activity/flush"
          onsubmit="return confirm('Permanently delete all activity log entries from the database? This cannot be undone.')">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger">Flush Log</button>
    </form>
  <?php endif; ?>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<!-- Filter toolbar -->
<form method="GET" action="<?= $b ?>/admin/activity">
  <div class="neb-filter-row" style="margin-bottom:1rem">
    <div class="select-wrap" style="max-width:220px">
      <select name="action" class="form-select">
        <option value="">All actions</option>
        <?php foreach ($actions as $act): ?>
          <option value="<?= \Luster\Core\View::e($act) ?>"
                  <?= $filter_action === $act ? 'selected' : '' ?>>
            <?= \Luster\Core\View::e($act) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($filter_action !== ''): ?>
      <a href="<?= $b ?>/admin/activity" class="btn btn-ghost">Clear</a>
    <?php endif; ?>
  </div>
</form>

<?php if (empty($entries)): ?>
  <p class="text-muted" style="font-size:.875rem">No log entries<?= $filter_action !== '' ? ' matching that filter' : '' ?>.</p>
<?php else: ?>

<p class="text-muted" style="font-size:.8rem;margin-bottom:.5rem">
  Showing <?= count($entries) ?> of <?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?> &mdash; page <?= (int) $page ?> of <?= (int) $pages ?>
</p>

<div class="neb-card">
  <table class="data-table">
    <thead>
      <tr>
        <th>#</th>
        <th>Action</th>
        <th>Description</th>
        <th>User</th>
        <th>IP</th>
        <th>Time</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($entries as $entry): ?>
      <tr>
        <td class="text-dim"><?= (int) $entry['id'] ?></td>
        <td><span class="neb-badge badge-info"><?= \Luster\Core\View::e((string) $entry['action']) ?></span></td>
        <td><?= \Luster\Core\View::e((string) ($entry['description'] ?? '')) ?></td>
        <td><?= \Luster\Core\View::e((string) ($entry['username'] ?? '—')) ?></td>
        <td style="font-family:'DM Mono',monospace;font-size:.75rem" class="text-muted">
          <?= \Luster\Core\View::e((string) ($entry['ip_address'] ?? '')) ?>
        </td>
        <td style="white-space:nowrap" class="text-muted">
          <?= \Luster\Core\View::e((string) ($entry['created_at'] ?? '')) ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="neb-pagination">
  <?php for ($p = 1; $p <= $pages; $p++): ?>
    <?php $qs = http_build_query(array_filter(['page' => $p, 'action' => $filter_action])); ?>
    <?php if ($p === (int) $page): ?>
      <span class="pg-link active"><?= $p ?></span>
    <?php else: ?>
      <a href="<?= $b ?>/admin/activity?<?= $qs ?>" class="pg-link"><?= $p ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>
