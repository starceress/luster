<?php

declare(strict_types=1);

/**
 * Updates add-on - update list (per-fanlisting or collective).
 *
 * @var \Luster\Addons\Updates\Update[] $updates
 * @var int                             $total
 * @var int                             $page
 * @var int                             $pages
 * @var bool                            $is_collective
 * @var \Luster\Models\Listing|null     $listing
 * @var string                          $success
 * @var string                          $error
 * @var string                          $base_url
 * @var string                          $csrf_token
 * @var string                          $admin_date_format
 * @var string                          $admin_time_format
 * @var int                             $per_page
 * @var string                          $search
 * @var string                          $filter_category
 * @var string[]                        $known_categories
 */

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

$listBase = $is_collective
    ? $b . '/admin/updates/collective'
    : $b . '/admin/updates/' . (int) $listing->id;

$addUrl = $listBase . '/add';

$filtersActive = $search !== '' || $filter_category !== '';

$pageUrl = static function (int $p) use ($listBase, $search, $filter_category): string {
    $q = array_filter(['page' => $p, 'search' => $search, 'category' => $filter_category]);
    return $listBase . '?' . http_build_query($q);
};

$latestDate = !empty($updates) ? date($admin_date_format, strtotime($updates[0]->postedAt)) : null;
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/updates" class="link">&larr; Back to Updates Feed</a>
  <div class="topbar-actions">
    <a href="<?= View::e($addUrl) ?>" class="btn btn-primary btn-sm"><?= View::icon('add') ?>Add Update</a>
  </div>
</div>

<!-- ──── TOOLBAR ─────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle"
            id="upd-filter-toggle"
            aria-expanded="<?= $filtersActive ? 'true' : 'false' ?>"
            aria-controls="upd-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
</div>

<!-- ──── FILTER PANEL ────────────────────────────────────────────────── -->
<div class="filter-panel<?= $filtersActive ? ' is-open' : '' ?>" id="upd-filter-panel">
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

    <input type="text" name="search" class="form" placeholder="Search title or body&hellip;"
           value="<?= View::e($search) ?>">
    <button type="submit" class="btn btn-quaternary btn-sm">Search</button>
    <?php if ($filtersActive): ?>
      <a href="<?= View::e($listBase) ?>" class="btn btn-quaternary btn-sm">Reset</a>
    <?php endif; ?>

  </form>
</div>

<?php if (empty($updates)): ?>

  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('updates', ['width' => 32, 'height' => 32]) ?></div>
      <?php if ($filtersActive): ?>
        <div class="empty-title">No updates match those filters.</div>
        <a href="<?= View::e($listBase) ?>" class="btn btn-tertiary btn-sm">Clear Filters</a>
      <?php else: ?>
        <div class="empty-title">No updates posted yet.</div>
        <div>Post your first update to let visitors know what&rsquo;s new.</div>
        <a href="<?= View::e($addUrl) ?>" class="btn btn-primary btn-sm">
          <?= View::icon('add') ?> Add Update
        </a>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>

<!-- ──── STATS STRIP ────────────────────────────────────────────────── -->
<?php if (!$filtersActive): ?>
  <div class="stats-grid stats-grid--compact">
    <div class="stat-card">
      <div class="stat-header">Total Updates</div>
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

<!-- ──── COMPACT ROWS ───────────────────────────────────────────────── -->
  <div class="card">
    <div class="card-head"><?= $is_collective ? 'Collective Updates' : 'Updates &mdash; ' . View::e($listing->name) ?>
    <?php $range_start = $total === 0 ? 0 : ($page - 1) * $per_page + 1; $range_end = min($page * $per_page, $total); ?>
    <span class="row-sub">Showing <?= $range_start ?>–<?= $range_end ?> of <?= $total ?> <?= $total === 1 ? 'entry' : 'entries' ?></span>
    </div>
    <div class="updates-timeline">
      <?php foreach ($updates as $u): ?>
        <?php
          $dt        = strtotime($u->postedAt);
          $dateLabel = date($admin_date_format, $dt);
          $time      = date($admin_time_format, $dt);
          $snip      = mb_strimwidth(str_replace(["\r\n", "\r", "\n"], ' ', $u->body), 0, 280, '…');
        ?>
        <div class="update-row">
          <div class="update-date">
            <strong><?= View::e($dateLabel) ?></strong>
            <?= View::e($time) ?>
          </div>
          <div>
            <div class="update-title"><?= View::e($u->title) ?></div>
            <div class="update-snip"><?= View::e($snip) ?></div>
            <?php if (!empty($u->categories())): ?>
              <div class="update-cats">
                <?php foreach ($u->categories() as $cat): ?>
                  <span class="badge badge-info-gold"><?= View::e($cat) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="data-actions">
            <a href="<?= $b ?>/admin/updates/entry/<?= (int) $u->id ?>/edit"
               class="btn btn-tertiary btn-sm">Edit</a>
            <form method="POST"
                  action="<?= $b ?>/admin/updates/entry/<?= (int) $u->id ?>/delete"
                  class="action-form-inline"
                  data-del-modal
                  data-del-entity="update"
                  data-del-name="<?= View::e($u->title) ?>">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php $pg_current = $page; $pg_total = $pages; $pg_url = $pageUrl;
  include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>

<?php endif; ?>
