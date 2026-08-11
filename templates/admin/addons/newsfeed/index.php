<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Newsfeed admin overview. Lists all listings with their entry counts and
 most recent post date, plus a collective news section. Supports search, sortable
 columns, and pagination. Each row links to the per-listing or collective entry list.
*/

declare(strict_types=1);

/**
 * @var \Luster\Models\Listing[]                                                          $listings
 * @var array<int, array{count: int, latest: \Luster\Addons\Newsfeed\NewsfeedEntry|null}> $latest_per_fl
 * @var \Luster\Addons\Newsfeed\NewsfeedEntry|null                                        $latest_coll
 * @var int                                                                               $collective_cnt
 * @var string                                                                            $site_name
 * @var string                                                                            $search
 * @var string                                                                            $sort
 * @var string                                                                            $dir
 * @var string                                                                            $success
 * @var string                                                                            $error
 * @var string                                                                            $base_url
 * @var string                                                                            $csrf_token
 * @var int                                                                               $page
 * @var int                                                                               $pages
 * @var int                                                                               $per_page
 * @var int                                                                               $total_listings
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
    return $b . '/admin/newsfeed' . ($qs !== '' ? '?' . $qs : '');
};

$pageUrl = static function (int $p) use ($b, $sort, $dir, $search): string {
    $qs = http_build_query(array_filter([
        'search' => $search,
        'sort'   => $sort !== 'id' ? $sort : '',
        'dir'    => $dir !== 'asc' ? $dir : '',
        'page'   => $p > 1 ? $p : 0,
    ], static fn($v): bool => (string) $v !== '' && $v !== 0));
    return $b . '/admin/newsfeed' . ($qs !== '' ? '?' . $qs : '');
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
    <a href="<?= $b ?>/admin/newsfeed/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?>News Feed Settings</a>
  </div>
</div>

<!-- ──── FILTER PANEL ────────────────────────────────────────────────── -->
<div class="filter-panel" id="upd-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/newsfeed" class="filter-row filter-row--compact">
    <?php if ($sort !== 'id'): ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>"><?php endif; ?>
    <?php if ($dir !== 'asc'): ?><input type="hidden" name="dir"  value="<?= View::e($dir)  ?>"><?php endif; ?>
    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search by subject&hellip;"
             value="<?= View::e($search) ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/newsfeed" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>

<!-- ──── TABLE ───────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">News Feed Index
    <span class="card-head-sub">Showing <?= $rangeFirst ?>–<?= $rangeLast ?> of <?= $total_listings ?> Listing<?= $total_listings === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data-table mem-table">
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
          <th>Total Entries</th>
          <th class="actions-col">Actions</th>
        </tr>
      </thead>
      <tbody>
<!-- ──── COLLECTIVE ROW ─────────────────────────────────────────────── -->
        <?php if ($search === ''): ?>
        <tr style="background: rgb(from var(--brand-tertiary) r g b / .06);">
          <td class="text-dim" data-label="#">0</td>
          <td data-label="Listing">
            <div class="ctx-cell">
              <a href="<?= $b ?>/admin/newsfeed/collective" class="link"><b><?= View::e($site_name) ?></b></a>
              <span class="badge badge-info3">Collective</span>
            </div>
          </td>
          <td class="text-dim" data-label="Last Updated">
            <?= $collLastDate !== null ? View::e($collLastDate) : '<span class="text-dim">&mdash;</span>' ?>
          </td>
          <td data-label="Total Entries">
            <?php if ($collective_cnt > 0): ?>
              <span><?= (int) $collective_cnt ?></span>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="actions-col" data-label="">
            <div class="actions-row">
              <a href="<?= $b ?>/admin/newsfeed/collective" class="btn btn-primary btn-xs">View</a>
              <a href="<?= $b ?>/admin/newsfeed/collective/add" class="btn btn-secondary btn-xs">Add</a>
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
          <tr class="<?= ($lifecycle_map[$fid] ?? null) ? 'row-archived' : '' ?>">
            <td class="text-dim" data-label="#"><?= $fid ?></td>
            <td class="<?= $cnt === 0 ? 'text-muted' : '' ?>" data-label="Listing">
              <div class="subject-title-stack">
                <a href="<?= $b ?>/admin/listings/<?= $fid ?>/edit" class="link"><b><?= View::e($listing->subject ?: $listing->title) ?></b></a>
                <?php if ($listing->subject !== ''): ?>
                  <span class="mem-url"><?= View::e($listing->title) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="text-dim" data-label="Last Updated">
              <?= $lastDate !== null ? View::e($lastDate) : '<span class="text-dim">&mdash;</span>' ?>
            </td>
            <td data-label="Total Entries">
              <?php if ($cnt > 0): ?>
                <span><?= $cnt ?></span>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="actions-col" data-label="">
              <div class="actions-row">
                <a href="<?= $b ?>/admin/newsfeed/<?= $fid ?>" class="btn btn-primary btn-xs">View</a>
                <a href="<?= $b ?>/admin/newsfeed/<?= $fid ?>/add" class="btn btn-secondary btn-xs">Add</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (empty($listings)): ?>
          <tr>
            <td colspan="5">
              <div class="empty">
                <?= View::icon('updates', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
                <?php if ($search !== ''): ?>
                  <div><strong>No listings match &ldquo;<?= View::e($search) ?>&rdquo;.</strong></div>
                <?php else: ?>
                  <div><strong>No listings found.</strong></div>
                  <div>Add a listing first to post per-listing newsfeed entries.</div>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endif; ?>

      </tbody>
    </table>
  </div>
</div>
<?php $pg_current = $page; $pg_total = $pages; $pg_url = $pageUrl;
      require __DIR__ . '/../../_pagination.php'; ?>
