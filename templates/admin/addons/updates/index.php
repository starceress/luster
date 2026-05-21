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
 * @var int                                                                         $page
 * @var int                                                                         $pages
 * @var int                                                                         $per_page
 * @var int                                                                         $total_listings
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

$pageUrl = static function (int $p) use ($b, $sort, $dir): string {
    $qs = http_build_query(array_filter([
        'sort' => $sort !== 'id' ? $sort : '',
        'dir'  => $dir !== 'asc' ? $dir : '',
        'page' => $p > 1 ? $p : 0,
    ], static fn($v): bool => (string) $v !== '' && $v !== 0));
    return $b . '/admin/updates' . ($qs !== '' ? '?' . $qs : '');
};

$rangeFirst = $total_listings === 0 ? 0 : ($page - 1) * $per_page + 1;
$rangeLast  = min($page * $per_page, $total_listings);
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-head">Listing Updates
    <span class="row-sub">Showing <?= $rangeFirst ?>-<?= $rangeLast ?> of <?= $total_listings ?> Listing<?= $total_listings === 1 ? '' : 's' ?></span>
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
<!-- ──── COLLECTIVE ROW ─────────────────────────────────────────────── -->
        <tr>
          <td class="text-dim">0</td>
          <td>
            <div class="ctx-cell">
              <span><?= View::e($site_name) ?></span>
              <span class="badge badge-info">Collective</span>
            </div>
          </td>
          <td>
            <?php if ($collective_cnt > 0): ?>
              <span><?= (int) $collective_cnt ?></span>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="act-col">
            <div class="data-actions">
              <a href="<?= $b ?>/admin/updates/collective" class="btn btn-primary btn-sm">View</a>
            </div>
          </td>
        </tr>
<!-- ──── PER-FANLISTING ROWS ────────────────────────────────────────── -->
        <?php foreach ($listings as $listing): ?>
          <?php
            $fid   = (int) $listing->id;
            $entry = $latest_per_fl[$fid] ?? null;
            $cnt   = (int) ($entry['count'] ?? 0);
          ?>
          <tr>
            <td class="text-dim"><?= $fid ?></td>
            <td class="<?= $cnt === 0 ? 'text-muted' : '' ?>"><a href="<?= $b ?>/admin/listings/<?= $fid ?>/edit" class="link"><?= View::e($listing->name) ?></a></td>
            <td>
              <?php if ($cnt > 0): ?>
                <span><?= $cnt ?></span>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="act-col">
              <div class="data-actions">
                <a href="<?= $b ?>/admin/updates/<?= $fid ?>" class="btn btn-primary btn-sm">View</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($listings)): ?>
          <tr>
            <td colspan="4">
              <div class="empty">
                <div class="empty-icon"><?= View::icon('updates', ['width' => 32, 'height' => 32]) ?></div>
                <div class="empty-title">No listings found.</div>
                <div>Add a listing first to post per-listing updates.</div>
              </div>
            </td>
          </tr>
        <?php endif; ?>

      </tbody>
    </table>
  </div>
  <?php $pg_current = $page; $pg_total = $pages; $pg_url = $pageUrl;
        require __DIR__ . '/../../_pagination.php'; ?>
</div>
