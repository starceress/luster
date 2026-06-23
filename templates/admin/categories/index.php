<?php declare(strict_types=1);
use Luster\Core\View;
/** @var array  $items */
/** @var int    $total */
/** @var int    $page */
/** @var int    $total_pages */
/** @var string $source */
/** @var string $search */
/** @var array  $custom_sources */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var array  $seed_health */
$b = rtrim(View::e($base_url ?? ''), '/');

$sourceBadge = static function (string $s): string {
    return match ($s) {
        'tfl'   => '<span class="badge badge-tfl">TFL</span>',
        'tafl'  => '<span class="badge badge-tafl">TAFL</span>',
        default => '<span class="badge badge-inactive">' . htmlspecialchars(ucfirst($s), ENT_QUOTES, 'UTF-8') . '</span>',
    };
};

$sort = $sort ?? 'source';
$dir  = $dir  ?? 'asc';

$catName       = $cat_name ?? '';
$filtersActive = ($source ?? '') !== '' || ($search ?? '') !== '' || $catName !== '';

$catFilterQs = http_build_query(array_filter([
    'source'   => $source   ?? '',
    'search'   => $search   ?? '',
    'cat_name' => $catName,
    'sort'     => $sort !== 'source' ? $sort : '',
    'dir'      => $dir  !== 'asc'   ? $dir  : '',
], static fn(string $v): bool => $v !== ''));
$catPageUrl = static function (int $p) use ($b, $catFilterQs): string {
    $qs = $catFilterQs !== '' ? $catFilterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/categories?' . $qs;
};

$catSortUrl = static function (string $col) use ($b, $sort, $dir, $source, $search, $catName): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'source'   => $source ?? '',
        'search'   => $search ?? '',
        'cat_name' => $catName,
        'sort'     => $col,
        'dir'      => $newDir,
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/categories?' . $qs;
};

?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success ?? '') ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error ?? '') ?></div>
<?php endif; ?>
<!-- ──── TOOLBAR ────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="fl-filter-toggle"
            aria-expanded="true"
            aria-controls="filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/categories/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add New Category</a>
    <form method="POST" action="<?= $b ?>/admin/categories/seed" class="action-form-inline">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <button type="submit" name="include_tafl" value="0" class="btn btn-secondary btn-sm">Seed TFL</button>
      <button type="submit" name="include_tafl" value="1" class="btn btn-secondary btn-sm">Seed TAFL</button>
    </form>
    <a href="<?= $b ?>/admin/categories/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?>Category Settings</a>
  </div>
</div>
<!-- ──── COLLAPSIBLE FILTER PANEL ───────────────────────────────────── -->
<div class="filter-panel" id="filter-panel">
  <form method="GET" action="<?= $b ?>/admin/categories" class="filter-row" id="cat-filter-row">
    <?php if ($sort !== 'source'): ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>"><?php endif; ?>
    <?php if ($dir !== 'asc'):    ?><input type="hidden" name="dir"  value="<?= View::e($dir) ?>"><?php endif; ?>

    <div class="select-wrap">
    <select id="cat-net-sel" name="source">
      <?php $_srcCounts = $source_counts ?? []; $_srcTotal = array_sum($_srcCounts); ?>
      <option value="">All Networks</option>
      <?php $_tflSrc = (int) ($_srcCounts['tfl'] ?? 0); ?>
      <option value="tfl"  <?= ($source ?? '') === 'tfl'  ? 'selected' : '' ?>>TFL<?= $_tflSrc > 0 ? ' (' . $_tflSrc . ')' : '' ?></option>
      <?php $_taflSrc = (int) ($_srcCounts['tafl'] ?? 0); ?>
      <option value="tafl" <?= ($source ?? '') === 'tafl' ? 'selected' : '' ?>>TAFL<?= $_taflSrc > 0 ? ' (' . $_taflSrc . ')' : '' ?></option>
      <?php foreach ($custom_sources ?? [] as $cs): ?>
        <?php $_csSrc = (int) ($_srcCounts[$cs] ?? 0); ?>
        <option value="<?= View::e($cs) ?>"
                <?= ($source ?? '') === $cs ? 'selected' : '' ?>>
          <?= View::e($cs) ?><?= $_csSrc > 0 ? ' (' . $_csSrc . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="select-wrap">
    <select id="cat-cat-sel" name="cat_name"
            data-tfl='<?= $tfl_json ?? '[]' ?>'
            data-tafl='<?= $tafl_json ?? '[]' ?>'
            data-custom='<?= $custom_json ?? '{}' ?>'>
      <option value="">All Categories</option>
      <?php if ($catName !== ''): ?>
        <?php
          // Find the category id for the selected name to look up its listing count
          $_selCatId = null;
          foreach ($allCats ?? [] as $_ac) {
              if (($_ac->name ?? '') === $catName) { $_selCatId = $_ac->id; break; }
          }
          $_selCount = $_selCatId !== null ? (int) ($listingCounts[$_selCatId] ?? 0) : 0;
        ?>
        <option value="<?= View::e($catName) ?>" selected><?= View::e($catName) ?><?= $_selCount > 0 ? ' (' . $_selCount . ')' : '' ?></option>
      <?php endif; ?>
    </select>
    </div>

    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search by name&hellip;"
             value="<?= View::e($search ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/categories" class="btn btn-quaternary btn-sm">Reset</a>

  </form>
</div>

<?php if (empty($items ?? [])): ?>
<div class="card">
  <div class="empty">
    <?php if (($search ?? '') !== '' || ($source ?? '') !== ''): ?>
      <div class="empty-icon"><?= View::icon('tag', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No categories match your search.</div>
      <a href="<?= $b ?>/admin/categories" class="btn btn-ghost btn-sm">Clear Search</a>
    <?php else: ?>
      <div class="empty-icon"><?= View::icon('tag', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No categories yet.</div>
      <div>Add your first to start organising listings.</div>
      <a href="<?= $b ?>/admin/categories/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Category</a>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<form method="POST" action="<?= $b ?>/admin/categories/bulk" id="bulk-form"
      data-del-bulk data-del-entity="category">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="select_all" value="0">
  <input type="hidden" name="sa_filter_source" value="<?= View::e($source ?? '') ?>">
  <input type="hidden" name="sa_filter_search" value="<?= View::e($search ?? '') ?>">

  <div class="card">
    <div class="card-head">
      <?php if (($source ?? '') !== ''): ?>
        <?= View::e(strtoupper($source ?? '')) ?> Categories
      <?php else: ?>
        Categories
      <?php endif; ?>
      <?php
        $_catTotal = (int) ($total ?? 0);
        $_catPP    = (int) ($per_page ?? 10);
        $_catFrom  = $_catTotal > 0 ? ($page - 1) * $_catPP + 1 : 0;
        $_catTo    = min($_catFrom + $_catPP - 1, $_catTotal);
      ?>
      <span class="card-head-sub">
        Showing <?= $_catFrom ?>&ndash;<?= $_catTo ?> of <?= $_catTotal ?> categor<?= $_catTotal === 1 ? 'y' : 'ies' ?>
      </span>
    </div>
    <div class="bulk bulk--flush">
      Bulk Actions:
      <div class="select-wrap">
        <select id="bulk-action-sel" name="bulk_action">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="delete">Delete</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>
    <div class="select-all-banner" id="sel-all-sa-banner" data-total="<?= (int) ($total ?? 0) ?>" style="display:none">
      <span class="sa-page">All <strong class="sa-page-count">0</strong> items on this page are selected.&ensp;<button type="button" class="link sa-select-all-btn">Select all <strong class="sa-total-count">0</strong> categories</button></span>
      <span class="sa-all" style="display:none">All <strong class="sa-total-count">0</strong> categories are selected.&ensp;<button type="button" class="link sa-clear-btn">Clear selection</button></span>
    </div>
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="sel-all" title="Select all"></th>
          <th><a href="<?= View::e($catSortUrl('source')) ?>" class="sort-th<?= $sort === 'source' ? ' sort-active' : '' ?>">Network <?= View::sortIcon('source', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($catSortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Category Name <?= View::sortIcon('name', $sort, $dir) ?></a></th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items ?? [] as $cat): ?>
        <tr>
          <td class="cb-col" data-label=""><input type="checkbox" name="ids[]" value="<?= (int) $cat->id ?>"
               data-name="<?= View::e($cat->name ?? '') ?>"></td>
          <td data-label="Network"><?= $sourceBadge($cat->source ?? 'custom') ?></td>
          <td data-label="Name"><?= View::e($cat->name ?? '') ?></td>
          <td class="act-col" data-label="">
            <a href="<?= $b ?>/admin/categories/<?= (int) $cat->id ?>/edit"
               class="btn btn-tertiary btn-sm">Edit</a>
            <form method="POST" action="<?= $b ?>/admin/categories/<?= (int) $cat->id ?>/delete"
                  class="action-form-inline"
                  data-del-modal data-del-entity="category"
                  data-del-name="<?= View::e($cat->name ?? '') ?>">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</form>
<?php endif; ?>

<?php $pg_current = $page; $pg_total = $total_pages; $pg_url = $catPageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
