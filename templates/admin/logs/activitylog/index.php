<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin activity log page. Displays a chronological, paginated table of all admin
 actions recorded in the audit trail. Supports CSV export and log flushing.
*/
use Luster\Core\View;
$b = rtrim(View::e($base_url), '/');
$pageUrl = static fn(int $p): string => $b . '/admin/activitylog?page=' . $p;
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<?php if (($total ?? 0) > 0): ?>
<div class="toolbar">
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/activitylog/export" class="btn btn-quaternary btn-sm"><?= View::icon('download') ?> Export Log</a>
    <form method="POST" action="<?= $b ?>/admin/activitylog/flush" id="flush-activity-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <button type="button" class="btn btn-danger btn-sm" id="flush-activity-btn"><?= View::icon('delete') ?> Flush Log</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (empty($entries)): ?>
  <div class="card empty">
    <?= View::icon('notes', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
    <div><strong>No activity logged yet.</strong></div>
    <div>Actions you take in the admin panel will appear here.</div>
  </div>
<?php else: ?>

<div class="card">
  <div class="table-wrap">
    <?php
      $rangeFirst = $total === 0 ? 0 : ($page - 1) * $per_page + 1;
      $rangeLast  = min($page * $per_page, $total);
    ?>
    <div class="card-head">Activity Log
      <span class="card-head-sub">Showing <?= $rangeFirst ?>-<?= $rangeLast ?> of <?= (int) $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?></span>
    </div>
    <table class="data-table">
      <colgroup>
        <col style="width:7rem">
        <col style="width:5.5rem">
        <col>
        <col style="width:7rem">
        <col style="width:9rem">
      </colgroup>
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
          $ts  = ($entry['created_at'] ?? '') !== '' ? strtotime((string) $entry['created_at']) : null;
          $ip  = (string) ($entry['ip_address'] ?? '');
        ?>
        <tr>
          <td class="text-dim nowrap">
            <?= $ts !== null ? View::e(date($admin_date_format, $ts)) : '' ?>
          </td>
          <td class="text-dim nowrap">
            <?= $ts !== null ? View::e(date($admin_time_format, $ts)) : '' ?>
          </td>
          <td><?= View::e((string) ($entry['description'] ?? '')) ?></td>
          <td class="text-muted nowrap"><?= View::e((string) ($entry['username'] ?? '')) ?></td>
          <td class="nowrap">
            <?php if ($ip !== ''): ?>
              <code><?= View::e($ip) ?></code>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php $pg_current = $page; $pg_total = $pages; $pg_url = $pageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>

<?php endif; ?>

<!-- Flush activity log confirmation modal -->
<div id="flush-activity-modal" class="modal-backdrop is-hidden"
     role="dialog" aria-modal="true" aria-labelledby="flush-activity-modal-title">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="flush-activity-modal-title">Flush Activity Log</h3>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
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
