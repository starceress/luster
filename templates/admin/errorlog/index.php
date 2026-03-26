<?php
use Luster\Core\View;
/** @var array<int,array<string,mixed>> $rows */
/** @var int    $total */
/** @var int    $page */
/** @var int    $pages */
/** @var int    $per_page */
/** @var string $success */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(View::e($base_url), '/');

$pageUrl = static fn(int $p): string => $b . '/admin/errorlog?page=' . $p;
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<?php if ($total > 0): ?>
<div class="toolbar">
  <span class="text-muted"><?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?> logged</span>
  <div class="actions">
    <a href="<?= $b ?>/admin/errorlog/export" class="btn btn-quaternary btn-sm">&#8595; Export CSV</a>
    <form method="POST" action="<?= $b ?>/admin/errorlog/flush" id="flush-logs-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <button type="button" class="btn btn-danger btn-sm" id="flush-logs-btn">Flush Logs</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty">
      <?= View::icon('check-badge', ['width' => '40', 'height' => '40']) ?>
      All clear â€” no errors have been logged.
    </div>
  <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Time</th>
          <th>Source</th>
          <th>Message</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <?php $ts = $row['created_at'] !== null ? strtotime((string) $row['created_at']) : null; ?>
        <tr>
          <td class="text-muted"><?= $ts !== null ? View::e(date($admin_date_format, $ts)) : '' ?></td>
          <td class="text-muted"><?= $ts !== null ? View::e(date($admin_time_format, $ts)) : '' ?></td>
          <td class="text-muted">
            <?= $row['source'] !== null && $row['source'] !== ''
                ? View::e((string) $row['source'])
                : '<span class="text-dim">&mdash;</span>' ?>
          </td>
          <td><?= View::e((string) $row['message']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
<?php
$window = 2;
$start  = max(2, $page - $window);
$end    = min($pages - 1, $page + $window);
?>
<nav class="pg" aria-label="Pagination">
  <a href="<?= View::e($pageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
     aria-label="Previous"><?= '&lsaquo;' ?></a>

  <a href="<?= View::e($pageUrl(1)) ?>"
     class="pg-link<?= $page === 1 ? ' active' : '' ?>">1</a>

  <?php if ($start > 2): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="<?= View::e($pageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $pages - 1): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>

  <?php if ($pages > 1): ?>
    <a href="<?= View::e($pageUrl($pages)) ?>"
       class="pg-link<?= $page === $pages ? ' active' : '' ?>"><?= $pages ?></a>
  <?php endif; ?>

  <a href="<?= View::e($pageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $pages ? ' disabled' : '' ?>"
     aria-label="Next"><?= '&rsaquo;' ?></a>
</nav>
<?php endif; ?>

<!-- Flush logs confirmation modal -->
<div id="flush-logs-modal" class="modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="flush-modal-title">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="flush-modal-title">Flush Error Logs</h3>
    </div>
    <div class="modal-body">
      <p>You are about to permanently delete all error log entries.</p>
      <div class="modal-warning">
        <strong>Warning:</strong> This action cannot be undone.
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-danger" id="flush-modal-confirm">Flush Logs</button>
      <button type="button" class="btn btn-quaternary" id="flush-modal-cancel">Cancel</button>
    </div>
  </div>
</div>

