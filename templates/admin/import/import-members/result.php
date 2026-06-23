<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import members result page. Displays the outcome of a member-only import,
 showing how many members were imported and how many were skipped as duplicates.
*/
use Luster\Core\View;
$b          = rtrim(View::e($base_url), '/');
$imported   = (int) ($result['imported']     ?? 0);
$skipped    = (int) ($result['skipped']      ?? 0);
$listingId  = (int) ($result['listing_id']   ?? 0);
$listingName = (string) ($result['listing_title'] ?? '');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/import-members" class="link">&larr; Import Members</a>
</div>

<div class="card card--gradient" style="margin-bottom:1.5rem">
  <div class="panel-header">
    <div class="panel-header-icon panel-header-icon--filled"><?= View::icon('check') ?></div>
    <div class="panel-header-meta">
      <div class="panel-header-title">Import Complete</div>
      <div class="panel-header-sub">Luster finished adding members to <strong><?= View::e($listingName) ?></strong>.</div>
    </div>
  </div>

  <div class="card-body">
    <div class="stat-row">
      <div class="stat-tile stat-tile--success">
        <span class="stat-label">Imported</span>
        <div class="stat-value"><?= number_format($imported) ?></div>
      </div>
      <div class="stat-tile">
        <span class="stat-label">Skipped</span>
        <div class="stat-value"><?= number_format($skipped) ?></div>
        <div class="stat-pills"><span class="badge badge-inactive">Already existed</span></div>
      </div>
    </div>
  </div>

  <div class="composer-footer">
    <?php if ($listingId > 0): ?>
    <a href="<?= $b ?>/admin/members?listing_id=<?= $listingId ?>" class="btn btn-primary">
      View Members &rarr;
    </a>
    <?php endif; ?>
    <a href="<?= $b ?>/admin/import-members" class="btn btn-ghost">Run Another Import</a>
  </div>
</div>
