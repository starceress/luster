<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin bulk email result page. Shows the outcome after a bulk email send,
 including how many messages were delivered successfully and which addresses
 (if any) failed.
*/

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url ?? ''), '/');
$allOk = empty($failed);
?>

<div class="card card--gradient">
  <?php if ($allOk): ?>
    <div class="alert alert-success">
      Bulk email sent successfully.
    </div>
  <?php else: ?>
    <div class="alert alert-error">
      Send completed with errors - <?= count($failed) ?> message<?= count($failed) !== 1 ? 's' : '' ?> failed.
    </div>
  <?php endif; ?>

  <div class="panel-header">
    <div class="panel-header-icon panel-header-icon-filled">
      <?= View::icon($allOk ? 'check-badge' : 'caution') ?>
    </div>
    <div class="panel-header-meta">
      <div class="panel-header-title">Bulk Email Result</div>
      <div class="panel-header-sub">See how many emails were delivered and whether any addresses failed.</div>
    </div>
  </div>

  <div class="db-info-grid summary-grid">
    <div>
      <div class="field-label summary-label">Sent to</div>
      <div><?= View::e($listing_title) ?></div>
    </div>
    <div>
      <div class="field-label summary-label">Subject</div>
      <div><?= View::e($subject) ?></div>
    </div>
    <div>
      <div class="field-label summary-label">Delivered</div>
      <div><strong><?= (int) $sent ?></strong> / <?= (int) $total ?></div>
    </div>
    <?php if (!$allOk): ?>
    <div>
      <div class="field-label summary-label">Failed</div>
      <div><?= count($failed) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$allOk): ?>
  <div class="summary-stack">
    <div class="field-label summary-label">Failed addresses</div>
    <ul class="summary-list">
      <?php foreach ($failed as $addr): ?>
        <li><?= View::e($addr) ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="form-hint">Check the error log for details.</p>
  </div>
  <?php endif; ?>

  <div class="composer-footer">
    <a href="<?= $b ?>/admin/emails?tab=bulk" class="btn btn-primary">Compose another</a>
    <a href="<?= $b ?>/admin/activitylog" class="btn btn-quaternary">View activity log</a>
  </div>
</div>
