<?php
use Luster\Core\View;
/** @var array  $result  listing_id, listing_title, imported, skipped */
/** @var string $base_url */
$b          = rtrim(View::e($base_url), '/');
$imported   = (int) ($result['imported']     ?? 0);
$skipped    = (int) ($result['skipped']      ?? 0);
$listingId  = (int) ($result['listing_id']   ?? 0);
$listingName = (string) ($result['listing_title'] ?? '');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/import-members" class="link">&larr; Import Members</a>
</div>

<div class="gradient-card" style="margin-bottom:1.5rem">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('check') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Import Complete</div>
      <div class="pc-head-sub">Luster finished adding members to <strong><?= View::e($listingName) ?></strong>.</div>
    </div>
  </div>

  <div class="pc-section">
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
