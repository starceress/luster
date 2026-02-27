<?php
/** @var array[]  $entries */
/** @var int      $total */
/** @var int      $page */
/** @var int      $pages */
/** @var string   $filter_action */
/** @var string[] $actions */
/** @var string   $base_url */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>
<style>
  .toolbar { display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem; }
  .toolbar select { padding:.45rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem; }
  .btn { display:inline-block;padding:.45rem 1rem;border-radius:6px;font-size:.8125rem;font-weight:500;text-decoration:none;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-gray { background:#f3f4f6;color:#374151;border:1px solid #d1d5db; }
  .log-table { width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden; }
  .log-table th { background:#f9fafb;text-align:left;padding:.6rem 1rem;font-size:.75rem;color:#6b7280;border-bottom:1px solid #e5e7eb; }
  .log-table td { padding:.6rem 1rem;font-size:.8rem;color:#374151;border-bottom:1px solid #f3f4f6;vertical-align:middle; }
  .log-table tr:last-child td { border-bottom:none; }
  .badge { display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:.7rem;font-weight:600;background:#eff6ff;color:#1d4ed8; }
  .meta { font-size:.8rem;color:#6b7280;margin-bottom:.5rem; }
  .pagination { display:flex;gap:.35rem;align-items:center;margin-top:1rem;flex-wrap:wrap; }
  .pagination a, .pagination span { padding:.3rem .7rem;border-radius:5px;font-size:.8rem;text-decoration:none;border:1px solid #d1d5db;color:#374151; }
  .pagination a:hover { background:#f3f4f6; }
  .pagination .cur { background:#4f46e5;color:#fff;border-color:#4f46e5; }
</style>

<!-- Filter toolbar -->
<form method="GET" action="<?= $b ?>/admin/activity">
  <div class="toolbar">
    <select name="action">
      <option value="">All actions</option>
      <?php foreach ($actions as $act): ?>
        <option value="<?= \Luster\Core\View::e($act) ?>"
                <?= $filter_action === $act ? 'selected' : '' ?>>
          <?= \Luster\Core\View::e($act) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Filter</button>
    <?php if ($filter_action !== ''): ?>
      <a href="<?= $b ?>/admin/activity" class="btn btn-gray">Clear</a>
    <?php endif; ?>
  </div>
</form>

<?php if (empty($entries)): ?>
  <p style="color:#6b7280;font-size:.875rem">No log entries<?= $filter_action !== '' ? ' matching that filter' : '' ?>.</p>
<?php else: ?>

<p class="meta">Showing <?= count($entries) ?> of <?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?> &mdash; page <?= (int) $page ?> of <?= (int) $pages ?></p>

<table class="log-table">
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
      <td style="color:#9ca3af"><?= (int) $entry['id'] ?></td>
      <td><span class="badge"><?= \Luster\Core\View::e((string) $entry['action']) ?></span></td>
      <td><?= \Luster\Core\View::e((string) ($entry['description'] ?? '')) ?></td>
      <td><?= \Luster\Core\View::e((string) ($entry['username'] ?? '—')) ?></td>
      <td style="font-family:monospace;font-size:.75rem;color:#6b7280">
        <?= \Luster\Core\View::e((string) ($entry['ip_address'] ?? '')) ?>
      </td>
      <td style="white-space:nowrap;color:#6b7280">
        <?= \Luster\Core\View::e((string) ($entry['created_at'] ?? '')) ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div class="pagination">
  <?php for ($p = 1; $p <= $pages; $p++): ?>
    <?php $qs = http_build_query(array_filter(['page' => $p, 'action' => $filter_action])); ?>
    <?php if ($p === (int) $page): ?>
      <span class="cur"><?= $p ?></span>
    <?php else: ?>
      <a href="<?= $b ?>/admin/activity?<?= $qs ?>"><?= $p ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>
