<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin error log page. Displays a paginated table of application errors with
 timestamps, messages, and stack trace details. Supports CSV export and log flushing.
*/
use Luster\Core\View;
$b = rtrim(View::e($base_url), '/');
$pageUrl = static fn(int $p): string => $b . '/admin/errorlog?page=' . $p;
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<?php if ($total > 0): ?>
<div class="toolbar">
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/errorlog/export" class="btn btn-quaternary btn-sm"><?= View::icon('download') ?> Export Log</a>
    <form method="POST" action="<?= $b ?>/admin/errorlog/flush" id="flush-logs-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <button type="button" class="btn btn-danger btn-sm" id="flush-logs-btn"><?= View::icon('delete') ?> Flush Log</button>
    </form>
  </div>
</div>
<?php endif; ?>

  <?php if (empty($rows)): ?>
  <div class="card empty">
    <?= View::icon('caution', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
    <div><strong>No errors logged yet.</strong></div>
    <div>Errors encountered by the application will be recorded here.</div>
  </div>
  <?php else: ?>
  
  <div class="card">
    <?php
      $rangeFirst = $total === 0 ? 0 : ($page - 1) * $per_page + 1;
      $rangeLast  = min($page * $per_page, $total);
    ?>
    <div class="card-head">Error Log
      <span class="card-head-sub">Showing <?= $rangeFirst ?>-<?= $rangeLast ?> of <?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?></span>
    </div>
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
          <?php foreach ($rows as $row):
            $ts = $row['created_at'] !== null ? strtotime((string) $row['created_at']) : null;
          ?>
          <tr>
            <td class="text-dim nowrap">
              <?= $ts !== null ? View::e(date($admin_date_format, $ts)) : '' ?>
            </td>
            <td class="text-dim nowrap">
              <?= $ts !== null ? View::e(date($admin_time_format, $ts)) : '' ?>
            </td>
            <td>
              <?php if (($row['source'] ?? '') !== ''): ?>
                <code><?= View::e((string) $row['source']) ?></code>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td><?= View::e((string) $row['message']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php $pg_current = $page; $pg_total = $pages; $pg_url = $pageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>

<div id="flush-logs-modal" class="modal-backdrop is-hidden"
     role="dialog" aria-modal="true" aria-labelledby="flush-modal-title">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="flush-modal-title">Flush Error Logs</h3>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
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
