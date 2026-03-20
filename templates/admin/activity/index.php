<?php
/** @var array[]  $entries */
/** @var int      $total */
/** @var int      $page */
/** @var int      $pages */
/** @var string   $success */
/** @var string   $base_url */
/** @var string   $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

$pageUrl = static fn(int $p): string => $b . '/admin/activity?page=' . $p;
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<?php if (($total ?? 0) > 0): ?>
<div class="neb-toolbar">
  <span class="text-muted" style="font-size:.8125rem"><?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?> logged</span>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/activity/export" class="btn btn-secondary btn-sm">&#8595; Export CSV</a>
    <form method="POST" action="<?= $b ?>/admin/activity/flush" id="flush-activity-form">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="button" class="btn btn-danger btn-sm" id="flush-activity-btn">Flush Log</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (empty($entries)): ?>
<div class="neb-card">
  <div class="neb-empty">
    <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 0 2-2h2a2 2 0 0 0 2 2"/></svg>
    No activity logged yet.
  </div>
</div>
<?php else: ?>

<div class="neb-card">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:160px">Date &amp; Time</th>
        <th>Action</th>
        <th style="width:140px">User</th>
        <th style="width:140px">IP</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($entries as $entry): ?>
      <tr>
        <td class="text-muted" style="white-space:nowrap">
          <?= \Luster\Core\View::e((string) ($entry['created_at'] ?? '')) ?>
        </td>
        <td><?= \Luster\Core\View::e((string) ($entry['description'] ?? '')) ?></td>
        <td class="text-muted"><?= \Luster\Core\View::e((string) ($entry['username'] ?? '—')) ?></td>
        <td style="font-family:'DM Mono',monospace;font-size:.75rem" class="text-muted">
          <?= \Luster\Core\View::e((string) ($entry['ip_address'] ?? '')) ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
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

<?php endif; ?>

<!-- Flush activity log confirmation modal -->
<div id="flush-activity-modal" class="neb-modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="flush-activity-modal-title">
  <div class="neb-modal">
    <div class="neb-modal-header">
      <h3 class="neb-modal-title" id="flush-activity-modal-title">Flush Activity Log</h3>
    </div>
    <div class="neb-modal-body">
      <p>You are about to permanently delete all activity log entries.</p>
      <div class="addon-modal-warning">
        <strong>Warning:</strong> This action cannot be undone.
      </div>
    </div>
    <div class="neb-modal-footer">
      <button type="button" class="btn btn-danger" id="flush-activity-confirm">Flush Log</button>
      <button type="button" class="btn btn-secondary" id="flush-activity-cancel">Cancel</button>
    </div>
  </div>
</div>
