<?php

declare(strict_types=1);

/**
 * Updates add-on - admin overview.
 *
 * @var \Luster\Models\Listing[]                                                    $listings
 * @var array<int, array{count: int, latest: \Luster\Addons\Updates\Update|null}>  $latest_per_fl
 * @var \Luster\Addons\Updates\Update|null                                          $latest_coll
 * @var int                                                                         $collective_cnt
 * @var string                                                                      $site_name
 * @var string                                                                      $sort
 * @var string                                                                      $dir
 * @var string                                                                      $success
 * @var string                                                                      $error
 * @var string                                                                      $base_url
 * @var string                                                                      $csrf_token
 */

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

$sort = $sort ?? 'id';
$dir  = $dir  ?? 'asc';

$sortUrl = static function (string $col) use ($b, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'sort' => $col !== 'id' ? $col : '',
        'dir'  => $newDir !== 'asc' ? $newDir : '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/updates' . ($qs !== '' ? '?' . $qs : '');
};

$totalUpdates = (int) $collective_cnt;
foreach ($latest_per_fl as $entry) {
    $totalUpdates += (int) ($entry['count'] ?? 0);
}
$listingCount = count($listings) + 1; // +1 for collective
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <span>All Listings</span>
    <span class="text-dim"><?= $listingCount ?> listing<?= $listingCount === 1 ? '' : 's' ?> &middot; <?= $totalUpdates ?> update<?= $totalUpdates === 1 ? '' : 's' ?> total</span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>
            <a href="<?= View::e($sortUrl('id')) ?>" class="sort-th<?= $sort === 'id' ? ' sort-active' : '' ?>"># <?= View::sortIcon('id', $sort, $dir) ?></a>
          </th>
          <th>
            <a href="<?= View::e($sortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Listing <?= View::sortIcon('name', $sort, $dir) ?></a>
          </th>
          <th>Updates</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>

        <!-- Collective row -->
        <tr>
          <td class="text-dim">0</td>
          <td>
            <div class="ctx-cell">
              <span class="badge badge-info">Collective</span>
              <span><?= View::e($site_name) ?></span>
            </div>
          </td>
          <td>
            <span class="update-count <?= $collective_cnt > 0 ? 'has-updates' : 'no-updates' ?>"><?= (int) $collective_cnt ?></span>
          </td>
          <td class="act-col">
            <div class="data-actions">
              <a href="<?= $b ?>/admin/updates/collective" class="btn btn-quaternary btn-sm">View All</a>
              <a href="<?= $b ?>/admin/updates/collective/add" class="btn btn-primary btn-sm">+ Add Update</a>
            </div>
          </td>
        </tr>

        <!-- Per-fanlisting rows -->
        <?php foreach ($listings as $listing): ?>
          <?php
            $fid   = (int) $listing->id;
            $entry = $latest_per_fl[$fid] ?? null;
            $cnt   = (int) ($entry['count'] ?? 0);
          ?>
          <tr>
            <td class="text-dim"><?= $fid ?></td>
            <td class="<?= $cnt === 0 ? 'text-muted' : '' ?>"><?= View::e($listing->name) ?></td>
            <td>
              <span class="update-count <?= $cnt > 0 ? 'has-updates' : 'no-updates' ?>"><?= $cnt ?></span>
            </td>
            <td class="act-col">
              <div class="data-actions">
                <a href="<?= $b ?>/admin/updates/<?= $fid ?>" class="btn btn-quaternary btn-sm">View All</a>
                <a href="<?= $b ?>/admin/updates/<?= $fid ?>/add" class="btn btn-primary btn-sm">+ Add Update</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($listings)): ?>
          <tr>
            <td colspan="4">
              <div class="empty">
                <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg>
                No listings found. Add a listing first to post per-listing updates.
              </div>
            </td>
          </tr>
        <?php endif; ?>

      </tbody>
    </table>
  </div>
</div>
