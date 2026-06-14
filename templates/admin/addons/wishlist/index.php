<?php
use Luster\Core\View;
/** @var \Luster\Addons\Wishlist\Wishlist[] $entries */
/** @var string   $filter_type */
/** @var array    $type_counts    ['wanted'=>int,'top'=>int,'granted'=>int] */
/** @var int      $hidden_count */
/** @var string[] $categories     distinct category strings stored in wishlist entries */
/** @var string   $search */
/** @var string   $filter_cat */
/** @var bool     $filters_active */
/** @var string   $success */
/** @var string   $error */
/** @var string   $base_url */
/** @var string   $csrf_token */
/** @var string   $sort */
/** @var string   $dir */
/** @var int      $total */
/** @var int      $page */
/** @var int      $per_page */
/** @var int      $total_pages */
/** @var array    $tfl_cats        [{id,name}] */
/** @var array    $tafl_cats       [{id,name}] */
/** @var string[] $custom_networks */
/** @var array    $custom_cats     network => [{id,name}] */

$b = rtrim(View::e($base_url), '/');

// ── Network lookup: category name → network key ──────────────────────────────
$catNetwork = [];
foreach ($tfl_cats ?? [] as $c) { $catNetwork[$c['name']] = 'tfl'; }
foreach ($tafl_cats ?? [] as $c) { $catNetwork[$c['name']] = 'tafl'; }
foreach ($custom_cats ?? [] as $net => $cats) {
    foreach ($cats as $c) { $catNetwork[$c['name']] = (string) $net; }
}

$networkBadge = static function (string $net): string {
    return match ($net) {
        'tfl'   => '<span class="badge badge-tfl">TFL</span>',
        'tafl'  => '<span class="badge badge-tafl">TAFL</span>',
        default => '<span class="badge badge-inactive">' . htmlspecialchars(strtoupper($net), ENT_QUOTES, 'UTF-8') . '</span>',
    };
};

// ── Group distinct-used categories for the filter <optgroup> dropdown ────────
$tflFilter    = [];
$taflFilter   = [];
$customFilter = [];
$otherFilter  = [];
foreach ($categories as $cat) {
    $net = $catNetwork[$cat] ?? null;
    if ($net === 'tfl')    { $tflFilter[]          = $cat; }
    elseif ($net === 'tafl') { $taflFilter[]        = $cat; }
    elseif ($net !== null) { $customFilter[$net][] = $cat; }
    else                   { $otherFilter[]         = $cat; }
}
$hasGroups = !empty($tflFilter) || !empty($taflFilter) || !empty($customFilter);

// ── Sort helpers ─────────────────────────────────────────────────────────────
$sort = $sort ?? 'subject';
$dir  = $dir  ?? 'asc';

$sortUrl = static function (string $col) use ($b, $sort, $dir, $filter_type, $search, $filter_cat): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'type'     => $filter_type !== 'all' ? $filter_type : '',
        'search'   => $search,
        'category' => $filter_cat,
        'sort'     => $col !== 'subject' ? $col : '',
        'dir'      => $newDir !== 'asc' ? $newDir : '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/wishlist' . ($qs !== '' ? '?' . $qs : '');
};

// ── Tab data ─────────────────────────────────────────────────────────────────
$tabTotal = array_sum($type_counts);
$tabs = [
    'all'      => ['label' => 'All',        'count' => $tabTotal,                      'badge' => 'badge-inactive'],
    'top'      => ['label' => 'Top Wanted', 'count' => $type_counts['top']      ?? 0,  'badge' => 'badge-info-pink'],
    'wanted'   => ['label' => 'Wanted',     'count' => $type_counts['wanted']   ?? 0,  'badge' => 'badge-current'],
    'granted'  => ['label' => 'Granted',    'count' => $type_counts['granted']  ?? 0,  'badge' => 'badge-redirect'],
    'hidden'   => ['label' => 'Hidden',     'count' => $hidden_count            ?? 0,  'badge' => 'badge-inactive'],
];
$typeTabs = ['wanted' => true, 'top' => true, 'granted' => true];

$typeQs = $filter_type !== 'all' ? '?type=' . View::e($filter_type) : '';

// ── Drag-to-reorder state ─────────────────────────────────────────────────
// Enabled only on a type tab, sorted by sort_order ASC, with no active filters.
// Filters are excluded because sort_order is per-type-global; reordering a
// filtered subset would silently shuffle hidden items.
$dragEnabled  = isset($typeTabs[$filter_type])
             && ($sort ?? 'subject') === 'sort_order'
             && ($dir  ?? 'asc')     === 'asc'
             && ($search     ?? '') === ''
             && ($filter_cat ?? '') === '';
$showDragHint = isset($typeTabs[$filter_type])
             && !$dragEnabled
             && ($search     ?? '') === ''
             && ($filter_cat ?? '') === '';
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TOOLBAR ────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="wl-filter-toggle"
            aria-expanded="true"
            aria-controls="wl-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filters_active ?? false): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/wishlist/add" class="btn btn-primary btn-sm">
      <?= View::icon('add') ?> Add Wishlist Entry
    </a>
    <a href="<?= $b ?>/admin/wishlist/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?>Wishlist Settings</a>
  </div>
</div>

<!-- ──── FILTER PANEL ────────────────────────────────────────────────── -->
<div class="filter-panel" id="wl-filter-panel">
<form method="GET" action="<?= $b ?>/admin/wishlist" id="wl-filter-row" class="filter-row">
    <?php if ($filter_type !== 'all'): ?>
      <input type="hidden" name="type" value="<?= View::e($filter_type) ?>">
    <?php endif; ?>
    <?php if ($sort !== 'subject'): ?>
      <input type="hidden" name="sort" value="<?= View::e($sort) ?>">
    <?php endif; ?>
    <?php if ($dir !== 'asc'): ?>
      <input type="hidden" name="dir" value="<?= View::e($dir) ?>">
    <?php endif; ?>

    <?php if (!empty($categories)): ?>
    <div class="select-wrap">
      <?php $_wlCc = $category_counts ?? []; ?>
      <select name="category" id="wl-category-sel">
        <option value="">All Categories</option>
        <?php if ($hasGroups): ?>
          <?php if (!empty($tflFilter)): ?>
          <optgroup label="TFL">
            <?php foreach ($tflFilter as $cat): ?>
              <?php $_wlN = (int) ($_wlCc[$cat] ?? 0); ?>
              <option value="<?= View::e($cat) ?>"<?= ($filter_cat ?? '') === $cat ? ' selected' : '' ?>><?= View::e($cat) ?><?= $_wlN > 0 ? ' (' . $_wlN . ')' : '' ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php if (!empty($taflFilter)): ?>
          <optgroup label="TAFL">
            <?php foreach ($taflFilter as $cat): ?>
              <?php $_wlN = (int) ($_wlCc[$cat] ?? 0); ?>
              <option value="<?= View::e($cat) ?>"<?= ($filter_cat ?? '') === $cat ? ' selected' : '' ?>><?= View::e($cat) ?><?= $_wlN > 0 ? ' (' . $_wlN . ')' : '' ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php foreach ($customFilter as $net => $cats): ?>
          <optgroup label="<?= View::e(strtoupper((string) $net)) ?>">
            <?php foreach ($cats as $cat): ?>
              <?php $_wlN = (int) ($_wlCc[$cat] ?? 0); ?>
              <option value="<?= View::e($cat) ?>"<?= ($filter_cat ?? '') === $cat ? ' selected' : '' ?>><?= View::e($cat) ?><?= $_wlN > 0 ? ' (' . $_wlN . ')' : '' ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
          <?php if (!empty($otherFilter)): ?>
          <optgroup label="Other">
            <?php foreach ($otherFilter as $cat): ?>
              <?php $_wlN = (int) ($_wlCc[$cat] ?? 0); ?>
              <option value="<?= View::e($cat) ?>"<?= ($filter_cat ?? '') === $cat ? ' selected' : '' ?>><?= View::e($cat) ?><?= $_wlN > 0 ? ' (' . $_wlN . ')' : '' ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        <?php else: ?>
          <?php foreach ($categories as $cat): ?>
            <?php $_wlN = (int) ($_wlCc[$cat] ?? 0); ?>
            <option value="<?= View::e($cat) ?>"<?= ($filter_cat ?? '') === $cat ? ' selected' : '' ?>><?= View::e($cat) ?><?= $_wlN > 0 ? ' (' . $_wlN . ')' : '' ?></option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search subject&hellip;"
             value="<?= View::e($search ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/wishlist<?= $typeQs ?>" class="btn btn-quaternary btn-sm">Reset</a>
</form>
</div>

<!-- ──── TYPE TABS ───────────────────────────────────────────────────── -->
<div class="tabs-wrap">
  <div class="tabs">
    <?php foreach ($tabs as $key => $tab): ?>
      <a href="<?= $b ?>/admin/wishlist<?= $key !== 'all' ? '?type=' . View::e($key) : '' ?>"
         class="tabs-btn<?= $filter_type === $key ? ' active' : '' ?>">
        <?= View::e($tab['label']) ?>
        <?php if ($tab['count'] > 0): ?>
          <span class="badge <?= $tab['badge'] ?> badge-inline-sm"><?= (int) $tab['count'] ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="tabs-select-wrap">
    <span class="overview-section-title">Status</span>
    <div class="select-wrap flex-fill">
      <select id="wl-type-select" onchange="window.location.href=this.value">
        <?php foreach ($tabs as $key => $tab): ?>
          <option value="<?= View::e($b . '/admin/wishlist' . ($key !== 'all' ? '?type=' . $key : '')) ?>"
                  <?= $filter_type === $key ? 'selected' : '' ?>>
            <?= View::e($tab['label']) ?> (<?= (int) $tab['count'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<!-- ──── TABLE ──────────────────────────────────────────────────────── -->
<?php if (empty($entries)): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('wishlist', ['width' => 32, 'height' => 32]) ?></div>
    <?php if ($filter_type === 'hidden'): ?>
      <div class="empty-title">There are no hidden wishlist entries.</div>
      <a href="<?= $b ?>/admin/wishlist/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add an Entry</a>
    <?php elseif (($filters_active ?? false) || $filter_type !== 'all'): ?>
      <div class="empty-title">There are no wishlist entries yet for this type.</div>
      <a href="<?= $b ?>/admin/wishlist/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add an Entry</a>
    <?php else: ?>
      <div class="empty-title">Your wishlist is empty.</div>
      <div>Add a listing to your wishlist to track what you want next.</div>
      <a href="<?= $b ?>/admin/wishlist/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add an Entry</a>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<form id="wl-bulk-form" method="POST"
      action="<?= $b ?>/admin/wishlist/bulk"
      data-del-bulk
      data-del-entity="wishlist_entry">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card">
    <div class="card-head">
      <?= $filter_type !== 'all' ? View::e($tabs[$filter_type]['label']) : 'All Entries' ?>
      <?php
        $rangeStart = min($total, ($page - 1) * $per_page + 1);
        $rangeEnd   = min($total, $page * $per_page);
      ?>
      <span class="row-sub">Showing <?= $rangeStart ?>&ndash;<?= $rangeEnd ?> of <?= $total ?> <?= $total === 1 ? 'entry' : 'entries' ?></span>
      <?php if ($dragEnabled): ?>
        <a href="<?= View::e($b . '/admin/wishlist?type=' . urlencode($filter_type)) ?>" class="btn btn-preview btn-xs actions-row--right"><?= View::icon('grip') ?> Exit reorder</a>
      <?php elseif ($showDragHint): ?>
        <a href="<?= View::e($sortUrl('sort_order')) ?>" class="btn btn-preview btn-xs actions-row--right"><?= View::icon('grip') ?> Click to Reorder</a>
      <?php endif; ?>
    </div>

    <div class="bulk bulk--flush">
      Bulk Actions:
      <div class="select-wrap">
        <select name="bulk_action" class="select-xs" id="wl-bulk-action">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="show">Show</option>
          <option value="hide">Hide</option>
          <option value="move">Move</option>
          <option value="delete">Delete</option>
        </select>
      </div>
      <span id="wl-move-to-wrap" style="display:none;align-items:center;gap:.5rem">
        <span style="font-size:var(--fs-sm);color:var(--text-muted)">to:</span>
        <div class="select-wrap">
          <select name="move_to_type" class="select-xs">
            <?php if ($filter_type !== 'wanted'): ?>
              <option value="wanted">Wanted</option>
            <?php endif; ?>
            <?php if ($filter_type !== 'top'): ?>
              <option value="top">Top Wanted</option>
            <?php endif; ?>
            <?php if ($filter_type !== 'granted'): ?>
              <option value="granted">Granted</option>
            <?php endif; ?>
          </select>
        </div>
      </span>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>

    <div class="table-wrap">
      <table class="data-table"
        <?php if ($dragEnabled): ?>
          data-wl-reorder-url="<?= View::e($b . '/admin/wishlist/reorder') ?>"
          data-wl-csrf="<?= View::e($csrf_token) ?>"
          data-wl-type="<?= View::e($filter_type) ?>"
          data-wl-page="<?= (int) $page ?>"
          data-wl-per-page="<?= (int) $per_page ?>"
        <?php endif; ?>>
        <thead>
          <tr>
            <?php if ($dragEnabled): ?><th class="table-col-xs"></th><?php endif; ?>
            <th class="cb-col"><input type="checkbox" id="wl-select-all" title="Select all"></th>
            <th>Image</th>
            <th>
              <a href="<?= View::e($sortUrl('subject')) ?>"
                 class="sort-th<?= $sort === 'subject' ? ' sort-active' : '' ?>">
                Subject <?= View::sortIcon('subject', $sort, $dir) ?>
              </a>
            </th>
            <th>
              <a href="<?= View::e($sortUrl('category')) ?>"
                 class="sort-th<?= $sort === 'category' ? ' sort-active' : '' ?>">
                Category <?= View::sortIcon('category', $sort, $dir) ?>
              </a>
            </th>
            <?php if ($filter_type === 'all'): ?>
            <th>
              <a href="<?= View::e($sortUrl('type')) ?>"
                 class="sort-th<?= $sort === 'type' ? ' sort-active' : '' ?>">
                Status <?= View::sortIcon('type', $sort, $dir) ?>
              </a>
            </th>
            <?php endif; ?>
            <th>
              <a href="<?= View::e($sortUrl('added_at')) ?>"
                 class="sort-th<?= $sort === 'added_at' ? ' sort-active' : '' ?>">
                Added <?= View::sortIcon('added_at', $sort, $dir) ?>
              </a>
            </th>
            <th>Visible</th>
            <th class="act-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entries as $entry): ?>
          <tr class="<?= $entry->active ? '' : 'row-inactive' ?>"
            <?php if ($dragEnabled): ?> draggable="true" data-id="<?= (int) $entry->id ?>"<?php endif; ?>>

            <?php if ($dragEnabled): ?>
            <td class="table-col-xs table-cell-center" data-label="">
              <span class="drag-handle" title="Drag to reorder"><?= View::icon('grip') ?></span>
            </td>
            <?php endif; ?>
            <td class="cb-col" data-label="">
              <input type="checkbox" name="ids[]"
                     value="<?= (int) $entry->id ?>"
                     data-name="<?= View::e($entry->subject) ?>">
            </td>

            <td data-label="">
              <?php if ($entry->image !== null && $entry->image !== ''): ?>
                <img src="<?= View::e($b . '/uploads/wishlist/' . $entry->image) ?>"
                     style="max-width:200px;max-height:200px;display:block;border-radius:4px;object-fit:contain"
                     alt="">
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>

            <td data-label="Subject">
              <a href="<?= $b ?>/admin/wishlist/<?= (int) $entry->id ?>/edit" class="link"><b><?= View::e($entry->subject) ?></b></a>
              <?php if ($entry->url !== ''): ?>
                  <a href="<?= View::e($entry->url) ?>" target="_blank" rel="noopener noreferrer"
                     class="mem-url">
                    <?= View::e(mb_strimwidth($entry->url, 0, 60, '…')) ?>
                  </a>
              <?php endif; ?>
            </td>

            <td data-label="Category">
              <?php
              $_wlEntCats = $entry->category !== ''
                  ? array_filter(array_map('trim', explode(',', $entry->category)))
                  : [];
              if (!empty($_wlEntCats)):
                  $_wlFirst = true;
                  foreach ($_wlEntCats as $_wlCat):
                      if (!$_wlFirst) echo '<span class="text-muted">, </span>';
                      $_wlFirst = false;
                      $_wlNet = $catNetwork[$_wlCat] ?? null;
                      $_wlBadge = $_wlNet !== null ? $networkBadge($_wlNet) : '';
              ?>
                <span class="cat-net-item"><?= $_wlBadge ?><?= View::e($_wlCat) ?></span>
              <?php
                  endforeach;
              else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>

            <?php if ($filter_type === 'all'): ?>
            <td data-label="Status">
              <?php $badgeClass = match ($entry->type) {
                'wanted'  => 'badge-current',
                'top'     => 'badge-info-pink',
                'granted' => 'badge-redirect',
                default   => 'badge-inactive',
              }; ?>
              <span class="badge <?= $badgeClass ?>"><?= View::e($entry->typeLabel()) ?></span>
            </td>
            <?php endif; ?>

            <td class="text-muted" data-label="Added">
              <?= $entry->addedAt !== null ? View::e($entry->addedAt) : '<span class="text-dim">&mdash;</span>' ?>
            </td>

            <td data-label="Visible">
              <?= $entry->active ? 'Public' : '<span class="text-muted">Hidden</span>' ?>
            </td>

            <td class="act-col" data-label="">
              <div class="data-actions">
                <a href="<?= $b ?>/admin/wishlist/<?= (int) $entry->id ?>/edit"
                   class="btn btn-tertiary btn-xs">Edit</a>
                <button type="submit"
                        form="del-wishlist-<?= (int) $entry->id ?>"
                        class="btn btn-danger btn-xs">Delete</button>
              </div>
            </td>

          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?php foreach ($entries as $entry): ?>
<form id="del-wishlist-<?= (int) $entry->id ?>"
      method="POST"
      action="<?= $b ?>/admin/wishlist/<?= (int) $entry->id ?>/delete"
      data-del-modal
      data-del-entity="wishlist_entry"
      data-del-name="<?= View::e($entry->subject) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
</form>
<?php endforeach; ?>

<?php if ($total_pages > 1):
    $pgUrl = static function (int $p) use ($b, $filter_type, $search, $filter_cat, $sort, $dir): string {
        $qs = http_build_query(array_filter([
            'type'     => $filter_type !== 'all' ? $filter_type : '',
            'search'   => $search,
            'category' => $filter_cat,
            'sort'     => $sort !== 'subject' ? $sort : '',
            'dir'      => $dir !== 'asc' ? $dir : '',
            'page'     => $p > 1 ? (string) $p : '',
        ], static fn(string $v): bool => $v !== ''));
        return $b . '/admin/wishlist' . ($qs !== '' ? '?' . $qs : '');
    };
    $pg_current = $page; $pg_total = $total_pages; $pg_url = $pgUrl;
    include LUSTER_BASE . '/templates/admin/_pagination.php';
endif; ?>

<?php endif; ?>
