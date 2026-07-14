<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Newsfeed admin entry list for a single listing or the collective. Shows all
 entries in a paginated table with search, category filtering, and inline
 delete. Each row links to the edit form. The "Add Entry" button creates a new entry.
*/

declare(strict_types=1);

/**
 * @var \Luster\Addons\Newsfeed\NewsfeedEntry[] $updates
 * @var int                                     $total
 * @var int                                     $page
 * @var int                                     $pages
 * @var bool                                    $is_collective
 * @var string                                  $ctx_name
 * @var \Luster\Models\Listing|null             $listing
 * @var string                                  $success
 * @var string                                  $error
 * @var string                                  $base_url
 * @var string                                  $csrf_token
 * @var string                                  $admin_date_format
 * @var string                                  $admin_time_format
 * @var int                                     $per_page
 * @var string                                  $search
 * @var string                                  $filter_category
 * @var string[]                                $known_categories
 */

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

$listBase = $is_collective
    ? $b . '/admin/newsfeed/collective'
    : $b . '/admin/newsfeed/' . (int) $listing->id;

$addUrl = $listBase . '/add';

$filtersActive = $search !== '' || $filter_category !== '';

$pageUrl = static function (int $p) use ($listBase, $search, $filter_category): string {
    $q = array_filter(['page' => $p, 'search' => $search, 'category' => $filter_category]);
    return $listBase . '?' . http_build_query($q);
};

$latestDate = !empty($updates) ? date($admin_date_format ?? 'm-d-Y', strtotime($updates[0]->postedAt)) : null;

$returnQuery = array_filter([
    'page'     => $page > 1 ? $page : 0,
    'search'   => $search,
    'category' => $filter_category,
], static fn($v): bool => (string) $v !== '' && $v !== 0);
$returnUrl = $listBase . ($returnQuery !== [] ? '?' . http_build_query($returnQuery) : '');
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/newsfeed" class="link">&larr; Back to News Feed</a>
</div>

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
    <a href="<?= View::e($addUrl) ?>" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Entry</a>
  </div>
</div>

<!-- ──── FILTER PANEL ────────────────────────────────────────────────── -->
<div class="filter-panel" id="upd-filter-panel">
  <form method="GET" action="<?= View::e($listBase) ?>" class="filter-row">

    <?php if (!empty($known_categories)): ?>
    <div class="select-wrap">
      <select name="category">
        <option value="">All Categories</option>
        <?php foreach ($known_categories as $cat): ?>
          <option value="<?= View::e($cat) ?>"<?= $filter_category === $cat ? ' selected' : '' ?>>
            <?= View::e($cat) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search title or body&hellip;"
             value="<?= View::e($search) ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
      <a href="<?= View::e($listBase) ?>" class="btn btn-quaternary btn-sm">Reset</a>

  </form>
</div>

<?php if (empty($updates)): ?>

<div class="card empty">
    <?= View::icon('updates', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
    <?php if ($filtersActive): ?>
      <div><strong>No entries match those filters.</strong></div>
      <a href="<?= View::e($listBase) ?>" class="btn btn-ghost btn-sm">Clear Filters</a>
    <?php else: ?>
      <div><strong>No entries posted yet.</strong></div>
      <div>Post your first entry to let visitors know what's new.</div>
      <a href="<?= View::e($addUrl) ?>" class="btn btn-primary btn-sm">
        <?= View::icon('add') ?> Add Entry
      </a>
    <?php endif; ?>
  </div>

<?php else: ?>

<!-- ──── STATS STRIP ─────────────────────────────────────────────────── -->
<?php if (!$filtersActive): ?>
  <div class="stats-grid stats-grid-plain stats-grid--compact">
    <div class="stat-card">
      <div class="stat-header">Total Entries</div>
      <div class="stat-value-sm"><?= (int) $total ?></div>
    </div>
    <?php if ($latestDate !== null): ?>
    <div class="stat-card">
      <div class="stat-header">Last Updated</div>
      <div class="stat-value-sm"><?= View::e($latestDate) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
    <div class="stat-card">
      <div class="stat-header">Page</div>
      <div class="stat-value-sm"><?= (int) $page ?> / <?= (int) $pages ?></div>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="overview-section-divider"></div>

<!-- ──── COMPACT ROWS ───────────────────────────────────────────────── -->
<form method="POST" action="<?= $b ?>/admin/newsfeed/bulk"
      data-del-bulk data-del-entity="entry">
  <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_return" value="<?= View::e($returnUrl) ?>">

  <div class="card">
    <?php $range_start = $total === 0 ? 0 : ($page - 1) * $per_page + 1;
          $range_end   = min($page * $per_page, $total); ?>
    <div class="card-head"><?= View::e($ctx_name) ?>
      <span class="card-head-sub">Showing <?= $range_start ?>–<?= $range_end ?> of <?= $total ?> <?= $total === 1 ? 'entry' : 'entries' ?></span>
    </div>

    <div class="bulk bulk--flush">
      <input type="checkbox" class="sel-all" data-fid="upd-list" title="Select all">
      Bulk Actions:
      <div class="select-wrap">
        <select name="bulk_action" class="select-xs">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="delete">Delete</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>

    <?php
      $grouped = [];
      foreach ($updates as $u) {
          $grouped[date('F Y', strtotime($u->postedAt))][] = $u;
      }
    ?>
    <div class="updates-timeline">
      <?php foreach ($grouped as $monthYear => $monthUpdates): ?>
        <div class="timeline-month-header"><?= View::e($monthYear) ?></div>
        <?php foreach ($monthUpdates as $u): ?>
          <?php
            $dt   = strtotime($u->postedAt);
            $dateStr = date($admin_date_format ?? 'm-d-Y', $dt);
            $timeStr = date($admin_time_format ?? 'g:i A', $dt);
            $snip = mb_strimwidth(str_replace(["\r\n", "\r", "\n"], ' ', $u->body), 0, 280, '…');
          ?>
          <div class="update-row">
            <div>
              <input type="checkbox" class="row-cb-upd-list" name="ids[]"
                     value="<?= (int) $u->id ?>" data-name="<?= View::e($u->title) ?>">
            </div>
            <div class="update-date"><?= View::e($dateStr) ?> &mdash; <?= View::e($timeStr) ?></div>
            <div>
              <div class="update-title"><?= View::e($u->title) ?></div>
              <div class="update-snip"><?= View::e($snip) ?></div>
              <?php if (!empty($u->categories())): ?>
                <div class="update-cats">
                  <?php foreach ($u->categories() as $cat): ?>
                    <span class="badge badge-inactive"><?= View::e($cat) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="actions-row">
              <a href="<?= $b ?>/admin/newsfeed/entry/<?= (int) $u->id ?>/edit"
                 class="btn btn-tertiary btn-xs">Edit</a>
              <button type="submit" form="del-newsfeed-<?= (int) $u->id ?>" class="btn btn-danger btn-xs">Delete</button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>
  </div>
</form>

<?php $pg_current = $page; $pg_total = $pages; $pg_url = $pageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>

<!-- Out-of-flow: must stay outside the bulk-delete form above -- nested form
     elements are invalid HTML and get silently dropped by the parser, so each
     row's Delete button would submit the bulk form instead. -->
<?php foreach ($updates as $u): ?>
  <form id="del-newsfeed-<?= (int) $u->id ?>" method="POST"
        action="<?= $b ?>/admin/newsfeed/entry/<?= (int) $u->id ?>/delete"
        data-del-modal
        data-del-entity="entry"
        data-del-name="<?= View::e($u->title) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  </form>
<?php endforeach; ?>

<?php endif; ?>
