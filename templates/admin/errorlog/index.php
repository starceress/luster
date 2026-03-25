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

$pageUrl = static fn(int $p): string => $b . '/admin/errorlog?page=' . $p;
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<?php if ($total > 0): ?>
<div class="neb-toolbar">
  <span class="text-muted" style="font-size:.8125rem"><?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?> logged</span>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/errorlog/export" class="btn btn-tertiary btn-sm">&#8595; Export CSV</a>
    <form method="POST" action="<?= $b ?>/admin/errorlog/flush" id="flush-logs-form">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="button" class="btn btn-danger btn-sm" id="flush-logs-btn">Flush Logs</button>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="neb-card">
  <?php if (empty($rows)): ?>
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      All clear — no errors have been logged.
    </div>
  <?php else: ?>
    <div class="neb-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th style="width:160px">Date</th>
          <th style="width:200px">Source</th>
          <th>Message</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td class="text-muted" style="white-space:nowrap"><?= \Luster\Core\View::e($row['created_at'] !== null ? date($admin_date_format . ' ' . $admin_time_format, strtotime((string) $row['created_at'])) : '') ?></td>
          <td class="text-muted">
            <?= $row['source'] !== null && $row['source'] !== ''
                ? \Luster\Core\View::e((string) $row['source'])
                : '<span class="text-dim">&mdash;</span>' ?>
          </td>
          <td style="max-width:500px;word-break:break-word"><?= \Luster\Core\View::e((string) $row['message']) ?></td>
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
<nav class="neb-pagination" aria-label="Pagination">
  <a href="<?= \Luster\Core\View::e($pageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
     aria-label="Previous"><?= '&lsaquo;' ?></a>

  <a href="<?= \Luster\Core\View::e($pageUrl(1)) ?>"
     class="pg-link<?= $page === 1 ? ' active' : '' ?>">1</a>

  <?php if ($start > 2): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="<?= \Luster\Core\View::e($pageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $pages - 1): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>

  <?php if ($pages > 1): ?>
    <a href="<?= \Luster\Core\View::e($pageUrl($pages)) ?>"
       class="pg-link<?= $page === $pages ? ' active' : '' ?>"><?= $pages ?></a>
  <?php endif; ?>

  <a href="<?= \Luster\Core\View::e($pageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $pages ? ' disabled' : '' ?>"
     aria-label="Next"><?= '&rsaquo;' ?></a>
</nav>
<?php endif; ?>

<!-- Flush logs confirmation modal -->
<div id="flush-logs-modal" class="neb-modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="flush-modal-title">
  <div class="neb-modal">
    <div class="neb-modal-header">
      <h3 class="neb-modal-title" id="flush-modal-title">Flush Error Logs</h3>
    </div>
    <div class="neb-modal-body">
      <p>You are about to permanently delete all error log entries.</p>
      <div class="addon-modal-warning">
        <strong>Warning:</strong> This action cannot be undone.
      </div>
    </div>
    <div class="neb-modal-footer">
      <button type="button" class="btn btn-danger" id="flush-modal-confirm">Flush Logs</button>
      <button type="button" class="btn btn-tertiary" id="flush-modal-cancel">Cancel</button>
    </div>
  </div>
</div>

