<?php
use Luster\Core\View;
/** @var array[] $entries */
/** @var int     $total */
/** @var int     $page */
/** @var int     $pages */
/** @var string  $success */
/** @var string  $base_url */
/** @var string  $csrf_token */
$b = rtrim(View::e($base_url), '/');
$pageUrl = static fn(int $p): string => $b . '/admin/activitylog?page=' . $p;
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<?php if (($total ?? 0) > 0): ?>
<div class="toolbar">
  <div class="actions-row">
    <a href="<?= $b ?>/admin/activitylog/export" class="btn btn-quaternary btn-sm"><?= View::icon('download') ?> Export CSV</a>
    <form method="POST" action="<?= $b ?>/admin/activitylog/flush" id="flush-activity-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <button type="button" class="btn btn-danger btn-sm" id="flush-activity-btn"><?= View::icon('delete') ?> Flush Logs</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (empty($entries)): ?>
  <div class="card">
    <div class="empty">
      <span class="empty-icon"><?= View::icon('notes', ['width' => 32, 'height' => 32]) ?></span>
      No activity logged yet.
    </div>
  </div>
<?php else: ?>

<div class="card">
  <div class="table-wrap">
    <div class="card-head">Activity Log
      <span class="row-sub">(Showing <?= count($entries) ?> of <?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?>)</span>
    </div>
    <table class="data-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Time</th>
          <th>Action</th>
          <th>User</th>
          <th>IP Address</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $entry):
          $ts = ($entry['created_at'] ?? '') !== '' ? strtotime((string) $entry['created_at']) : null;
        ?>
        <tr>
          <td class="text-dim nowrap">
            <?= $ts !== null ? View::e(date($admin_date_format, $ts)) : '' ?>
          </td>
          <td class="text-dim nowrap">
            <?= $ts !== null ? View::e(date($admin_time_format, $ts)) : '' ?>
          </td>
          <td><?= View::e((string) ($entry['description'] ?? '')) ?></td>
          <td class="text-muted"><?= View::e((string) ($entry['username'] ?? '&mdash;')) ?></td>
          <td>
            <span class="text-dim text-mono-xs">
              <?= View::e((string) ($entry['ip_address'] ?? '')) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($pages > 1):
  $window = 2;
  $start  = max(2, $page - $window);
  $end    = min($pages - 1, $page + $window);
?>
<nav class="pg" aria-label="Pagination">
  <a href="<?= View::e($pageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>" aria-label="Previous">&lsaquo;</a>

  <a href="<?= View::e($pageUrl(1)) ?>"
     class="pg-link<?= $page === 1 ? ' active' : '' ?>">1</a>

  <?php if ($start > 2): ?>
    <span class="pg-link disabled">&hellip;</span>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="<?= View::e($pageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $pages - 1): ?>
    <span class="pg-link disabled">&hellip;</span>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
    <a href="<?= View::e($pageUrl($pages)) ?>"
       class="pg-link<?= $page === $pages ? ' active' : '' ?>"><?= $pages ?></a>
  <?php endif; ?>

  <a href="<?= View::e($pageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $pages ? ' disabled' : '' ?>" aria-label="Next">&rsaquo;</a>
</nav>
<?php endif; ?>

<?php endif; ?>

<!-- Flush activity log confirmation modal -->
<div id="flush-activity-modal" class="modal-backdrop is-hidden"
     role="dialog" aria-modal="true" aria-labelledby="flush-activity-modal-title">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="flush-activity-modal-title">Flush Activity Log</h3>
    </div>
    <div class="modal-body">
      <p>You are about to permanently delete all activity log entries.</p>
      <div class="modal-warning">
        <strong>Warning:</strong> This action cannot be undone.
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-danger" id="flush-activity-confirm">Flush Log</button>
      <button type="button" class="btn btn-quaternary" id="flush-activity-cancel">Cancel</button>
    </div>
  </div>
</div>
