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
 * @var string                                                                      $search
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

$b    = rtrim(View::e($base_url), '/');
$sort = $sort ?? 'id';
$dir  = $dir  ?? 'asc';
$dateFmt = $admin_date_format ?? 'm-d-Y';

$filtersActive = $search !== '';

$sortUrl = static function (string $col) use ($b, $sort, $dir, $search): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'search' => $search,
        'sort'   => $col !== 'id' ? $col : '',
        'dir'    => $newDir !== 'asc' ? $newDir : '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/updates' . ($qs !== '' ? '?' . $qs : '');
};

$pageUrl = static function (int $p) use ($b, $sort, $dir, $search): string {
    $qs = http_build_query(array_filter([
        'search' => $search,
        'sort'   => $sort !== 'id' ? $sort : '',
        'dir'    => $dir !== 'asc' ? $dir : '',
        'page'   => $p > 1 ? $p : 0,
    ], static fn($v): bool => (string) $v !== '' && $v !== 0));
    return $b . '/admin/updates' . ($qs !== '' ? '?' . $qs : '');
};

$rangeFirst = $total_listings === 0 ? 0 : ($page - 1) * $per_page + 1;
$rangeLast  = min($page * $per_page, $total_listings);

$collLastDate = $latest_coll !== null
    ? date($dateFmt, strtotime($latest_coll->postedAt))
    : null;
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TOOLBAR ─────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle"
            id="upd-filter-toggle"
            aria-expanded="true"
            aria-controls="upd-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/updates/settings" class="btn btn-quaternary btn-sm">Display Settings</a>
  </div>
</div>

<!-- ──── FILTER PANEL ────────────────────────────────────────────────── -->
<div class="filter-panel" id="upd-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/updates" class="filter-row">
    <?php if ($sort !== 'id'): ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>"><?php endif; ?>
    <?php if ($dir !== 'asc'): ?><input type="hidden" name="dir"  value="<?= View::e($dir)  ?>"><?php endif; ?>
    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search by subject&hellip;"
             value="<?= View::e($search) ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/updates" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>

<!-- ──── TABLE ───────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">Updates Index
    <span class="row-sub">Showing <?= $rangeFirst ?>–<?= $rangeLast ?> of <?= $total_listings ?> Listing<?= $total_listings === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>
            <a href="<?= View::e($sortUrl('id')) ?>"
               class="sort-th<?= $sort === 'id' ? ' sort-active' : '' ?>">
              # <?= View::sortIcon('id', $sort, $dir) ?>
            </a>
          </th>
          <th>
            <a href="<?= View::e($sortUrl('subject')) ?>"
               class="sort-th<?= $sort === 'subject' ? ' sort-active' : '' ?>">
              Listing <?= View::sortIcon('subject', $sort, $dir) ?>
            </a>
          </th>
          <th>
            <a href="<?= View::e($sortUrl('latest')) ?>"
               class="sort-th<?= $sort === 'latest' ? ' sort-active' : '' ?>">
              Last Updated <?= View::sortIcon('latest', $sort, $dir) ?>
            </a>
          </th>
          <th>Total Updates</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
<!-- ──── COLLECTIVE ROW ─────────────────────────────────────────────── -->
        <?php if ($search === ''): ?>
        <tr>
          <td class="text-dim">0</td>
          <td>
            <div class="ctx-cell">
              <a href="<?= $b ?>/admin/updates/collective" class="link"><?= View::e($site_name) ?></a>
              <span class="badge badge-info-gold">Collective</span>
            </div>
          </td>
          <td class="text-dim">
            <?= $collLastDate !== null ? View::e($collLastDate) : '<span class="text-dim">&mdash;</span>' ?>
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
              <a href="<?= $b ?>/admin/updates/collective/add" class="btn btn-secondary btn-sm">Add</a>
            </div>
          </td>
        </tr>
        <?php endif; ?>
<!-- ──── PER-FANLISTING ROWS ────────────────────────────────────────── -->
        <?php foreach ($listings as $listing): ?>
          <?php
            $fid      = (int) $listing->id;
            $entry    = $latest_per_fl[$fid] ?? null;
            $cnt      = (int) ($entry['count'] ?? 0);
            $latest   = $entry['latest'] ?? null;
            $lastDate = $latest !== null
                ? date($dateFmt, strtotime($latest->postedAt))
                : null;
          ?>
          <tr>
            <td class="text-dim"><?= $fid ?></td>
            <td class="<?= $cnt === 0 ? 'text-muted' : '' ?>">
              <a href="<?= $b ?>/admin/listings/<?= $fid ?>/edit" class="link"><?= View::e($listing->subject) ?></a>
            </td>
            <td class="text-dim">
              <?= $lastDate !== null ? View::e($lastDate) : '<span class="text-dim">&mdash;</span>' ?>
            </td>
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
                <a href="<?= $b ?>/admin/updates/<?= $fid ?>/add" class="btn btn-secondary btn-sm">Add</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($listings)): ?>
          <tr>
            <td colspan="5">
              <div class="empty">
                <div class="empty-icon"><?= View::icon('updates', ['width' => 32, 'height' => 32]) ?></div>
                <?php if ($search !== ''): ?>
                  <div class="empty-title">No listings match &ldquo;<?= View::e($search) ?>&rdquo;.</div>
                <?php else: ?>
                  <div class="empty-title">No listings found.</div>
                  <div>Add a listing first to post per-listing updates.</div>
                <?php endif; ?>
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
