<?php
/** @var array<int,array<string,mixed>> $rows */
/** @var int    $total */
/** @var int    $page */
/** @var int    $pages */
/** @var int    $per_page */
/** @var string $success */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>
<style>
  .toolbar { display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem; }
  .card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden; }
  table { width:100%;border-collapse:collapse; }
  th { background:#f9fafb;text-align:left;padding:.6rem 1rem;font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e5e7eb; }
  td { padding:.6rem 1rem;font-size:.8125rem;color:#374151;border-bottom:1px solid #f3f4f6;vertical-align:top; }
  tr:last-child td { border-bottom:none; }
  .badge { display:inline-block;padding:.15rem .55rem;border-radius:9999px;font-size:.7rem;font-weight:600;white-space:nowrap; }
  .badge-error   { background:#fef2f2;color:#b91c1c; }
  .badge-warning { background:#fffbeb;color:#b45309; }
  .badge-notice  { background:#f3f4f6;color:#6b7280; }
  .badge-default { background:#f3f4f6;color:#6b7280; }
  .btn { display:inline-block;padding:.45rem 1rem;border-radius:6px;font-size:.875rem;font-weight:500;text-decoration:none;cursor:pointer;border:none; }
  .btn-danger { background:#dc2626;color:#fff; }
  .btn-danger:hover { background:#b91c1c; }
  .pagination { display:flex;gap:.35rem;margin-top:1rem;flex-wrap:wrap; }
  .pagination a, .pagination span { display:inline-block;padding:.3rem .65rem;border-radius:5px;font-size:.8125rem;text-decoration:none; }
  .pagination a { border:1px solid #d1d5db;color:#374151; }
  .pagination a:hover { background:#f3f4f6; }
  .pagination span { border:1px solid #6366f1;background:#6366f1;color:#fff; }
  .empty { padding:1.5rem 1rem;color:#6b7280;font-size:.875rem; }
  .alert-success { background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#15803d;font-size:.875rem; }
  .msg-cell { max-width:500px;word-break:break-word; }
</style>

<?php if ($success !== ''): ?>
  <div class="alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<div class="toolbar">
  <h1 style="font-size:1.25rem;font-weight:700;color:#111827;margin:0">
    Error Log
    <?php if ($total > 0): ?>
      <span style="font-size:.8rem;font-weight:400;color:#6b7280;margin-left:.4rem">(<?= (int) $total ?> entries)</span>
    <?php endif; ?>
  </h1>
  <?php if ($total > 0): ?>
    <form method="POST"
          action="<?= $b ?>/admin/errorlog/flush"
          onsubmit="return confirm('Clear all error logs? This cannot be undone.')">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger">Flush Logs</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <p class="empty">No errors logged.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th style="width:160px">Date</th>
          <th style="width:90px">Level</th>
          <th style="width:200px">Source</th>
          <th>Message</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td style="color:#6b7280;white-space:nowrap"><?= \Luster\Core\View::e((string) $row['created_at']) ?></td>
          <td>
            <?php
            $lvl = strtolower((string) ($row['level'] ?? 'error'));
            $badgeClass = match ($lvl) {
                'error'   => 'badge-error',
                'warning' => 'badge-warning',
                'notice'  => 'badge-notice',
                default   => 'badge-default',
            };
            ?>
            <span class="badge <?= $badgeClass ?>"><?= \Luster\Core\View::e($lvl) ?></span>
          </td>
          <td style="color:#6b7280">
            <?= $row['source'] !== null && $row['source'] !== ''
                ? \Luster\Core\View::e((string) $row['source'])
                : '<span style="color:#d1d5db">&mdash;</span>' ?>
          </td>
          <td class="msg-cell"><?= \Luster\Core\View::e((string) $row['message']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<div class="pagination">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php if ($i === $page): ?>
      <span><?= $i ?></span>
    <?php else: ?>
      <a href="<?= $b ?>/admin/errorlog?page=<?= $i ?>"><?= $i ?></a>
    <?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>
