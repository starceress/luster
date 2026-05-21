<?php
use Luster\Core\View;
/** @var string  $source    'enthusiast'|'listing-admin' */
/** @var string  $schema */
/** @var array[] $listings */
/** @var string  $base_url */
/** @var string  $csrf_token */
$b           = rtrim(View::e($base_url), '/');
$sourceName  = $source === 'listing-admin' ? 'Listing Admin' : 'Enthusiast';
$importSlug  = $source === 'listing-admin' ? 'listing-admin' : 'enthusiast';
?>

<div class="page-topbar" style="display:flex;align-items:center;gap:.75rem">
  <a href="<?= $b ?>/admin/legacy-source" class="link">&larr; Back</a>
  <span class="badge badge-info"><?= View::e($sourceName) ?></span>
  <span class="badge badge-inactive"><?= View::e($schema) ?></span>
</div>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-meta">
      <div class="pc-head-title">
        <?= View::e($sourceName) ?> Listings
        <span class="badge badge-inactive" style="margin-left:.5rem"><?= count($listings) ?></span>
      </div>
      <div class="pc-head-sub">Read-only view. Nothing is imported until you use the Import Wizard.</div>
    </div>
  </div>

  <?php if (empty($listings)): ?>
  <div class="pc-section">
    <p class="form-hint">No listings found in this source.</p>
  </div>
  <?php else: ?>
  <div class="pc-section" style="padding:0">
    <table class="data-table">
      <thead>
        <tr>
          <th>Listing</th>
          <th>Subject</th>
          <th class="table-num">Members</th>
          <th class="table-num">Pending</th>
          <th class="table-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($listings as $row): ?>
        <tr>
          <td>
            <a href="<?= View::e($row['url'] ?? '#') ?>" target="_blank" rel="noopener" class="link">
              <?= View::e($row['title'] ?? '') ?>
            </a>
          </td>
          <td><?= View::e($row['subject'] ?? '') ?></td>
          <td class="table-num"><?= number_format((int) ($row['member_count'] ?? 0)) ?></td>
          <td class="table-num">
            <?php $pen = (int) ($row['pending_count'] ?? 0); ?>
            <?= $pen > 0 ? '<span class="badge badge-warn">' . $pen . '</span>' : '0' ?>
          </td>
          <td class="table-actions">
            <a href="<?= $b ?>/admin/legacy-source/listing/<?= (int) $row['id'] ?>"
               class="btn btn-ghost btn-xs">Details</a>
            <a href="<?= $b ?>/admin/import/<?= View::e($importSlug) ?>/connect"
               class="btn btn-primary btn-xs">Import &rarr;</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
