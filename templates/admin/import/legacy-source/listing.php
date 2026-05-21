<?php
use Luster\Core\View;
/** @var string $source  'enthusiast'|'listing-admin' */
/** @var array  $detail  title, subject, url, member_count, pending_count, samples[], dbtable */
/** @var string $base_url */
$b           = rtrim(View::e($base_url), '/');
$sourceName  = $source === 'listing-admin' ? 'Listing Admin' : 'Enthusiast';
$importSlug  = $source === 'listing-admin' ? 'listing-admin' : 'enthusiast';
$memberCount = (int) ($detail['member_count']  ?? 0);
$pendingCount= (int) ($detail['pending_count'] ?? 0);
$samples     = (array) ($detail['samples']     ?? []);
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/legacy-source/listings" class="link">&larr; Back to listings</a>
</div>

<div class="gradient-card" style="margin-bottom:1.5rem">
  <div class="pc-head">
    <div class="pc-head-meta">
      <div class="pc-head-title"><?= View::e($detail['title'] ?? '') ?></div>
      <div class="pc-head-sub">
        <a href="<?= View::e($detail['url'] ?? '#') ?>" target="_blank" rel="noopener" class="link">
          <?= View::e($detail['url'] ?? '') ?>
        </a>
      </div>
    </div>
  </div>

  <div class="pc-section">
    <div class="stat-row" style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-bottom:1rem">
      <div class="stat-tile">
        <span class="stat-label">Approved members</span>
        <div class="stat-value"><?= number_format($memberCount - $pendingCount) ?></div>
      </div>
      <div class="stat-tile">
        <span class="stat-label">Pending members</span>
        <div class="stat-value"><?= number_format($pendingCount) ?></div>
      </div>
    </div>

    <?php if (!empty($detail['subject'])): ?>
    <div class="listing-field-row">
      <span class="listing-field-label">Subject</span>
      <div><?= View::e($detail['subject']) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($detail['dbtable'])): ?>
    <div class="listing-field-row">
      <span class="listing-field-label">Member table</span>
      <div><code><?= View::e($detail['dbtable']) ?></code></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($samples)): ?>
  <div class="pc-section">
    <div class="pc-section-label">Sample members (approved, up to 5)</div>
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <?php foreach (array_keys($samples[0]) as $col): ?>
            <th><?= View::e($col) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($samples as $row): ?>
          <tr>
            <?php foreach ($row as $val): ?>
            <td><?= View::e((string) $val) ?></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="composer-footer">
    <a href="<?= $b ?>/admin/import/<?= View::e($importSlug) ?>/connect"
       class="btn btn-primary">Import this listing with the wizard &rarr;</a>
    <a href="<?= $b ?>/admin/legacy-source/listings" class="btn btn-ghost">Back to list</a>
  </div>
</div>
