<?php
use Luster\Core\View;
/** @var \Luster\Models\JoinedListing[] $joined */
/** @var string[] $categories */
/** @var string   $filter_cat */
/** @var string   $filter_network */
/** @var string   $search */
/** @var int      $per_page */
/** @var int      $page */
/** @var int      $total_pages */
/** @var array    $tfl_cats */
/** @var array    $tafl_cats */
/** @var string[] $custom_networks */
/** @var array    $network_categories */
/** @var string   $display_mode */
/** @var string   $success */
/** @var string   $error */
/** @var string   $base_url */
/** @var string   $csrf_token */
$b = rtrim(View::e($base_url), '/');

$filtersActive = ($search ?? '') !== '' || ($filter_network ?? '') !== '' || ($filter_cat ?? '') !== '';

// Build a base query string (current filters minus page) for pagination links
$filterQs = http_build_query(array_filter([
    'search'   => $search          ?? '',
    'network'  => $filter_network  ?? '',
    'category' => $filter_cat      ?? '',
    'per_page' => ($per_page ?? 10) !== 10 ? (string) ($per_page ?? 10) : '',
], static fn(string $v): bool => $v !== ''));
$pageUrl = static function (int $p) use ($b, $filterQs): string {
    $qs = $filterQs !== '' ? $filterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/joined?' . $qs;
};

$sort = $sort ?? '';
$dir  = $dir  ?? 'asc';

$joinedSortUrl = static function (string $col) use ($b, $sort, $dir, $search, $filter_network, $filter_cat, $per_page): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'search'   => $search          ?? '',
        'network'  => $filter_network  ?? '',
        'category' => $filter_cat      ?? '',
        'per_page' => ($per_page ?? 10) !== 10 ? (string) ($per_page ?? 10) : '',
        'sort'     => $col,
        'dir'      => $newDir,
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/joined?' . $qs;
};

$displayMode = $display_mode ?? 'all';
if ($displayMode === 'all' && $sort === '') { usort($joined, fn($a, $b) => $a->id <=> $b->id); }
$grouped = [];
foreach ($joined as $jf) {
    if ($displayMode === 'network') {
        $cats = ($jf->categoriesJson) ? json_decode($jf->categoriesJson, true) : [];
        $key = 'Uncategorised';
        foreach ($cats as $c) { if (!empty($c['network'])) { $key = $c['network']; break; } }
        if ($key === 'Uncategorised' && $jf->category !== '') { $key = $jf->category; }
    } elseif ($displayMode === 'category') {
        $cats = ($jf->categoriesJson) ? json_decode($jf->categoriesJson, true) : [];
        $key = 'Uncategorised';
        foreach ($cats as $c) { if (!empty($c['name'])) { $key = $c['name']; break; } }
        if ($key === 'Uncategorised' && $jf->category !== '') { $key = $jf->category; }
    } elseif ($displayMode === 'status') {
        $key = ucfirst($jf->status ?? 'joined');
    } else {
        $key = 'All Joined';
    }
    $grouped[$key][] = $jf;
}
ksort($grouped);
if ($displayMode === 'category') {
    foreach ($grouped as $k => &$items) { usort($items, fn($a, $b) => strcmp($a->name, $b->name)); }
    unset($items);
}
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- Toolbar -->
<div class="toolbar">
  <div class="actions">
    <button type="button" class="btn btn-quaternary btn-sm fl-filter-toggle" id="fl-filter-toggle"
            aria-expanded="<?= $filtersActive ? 'true' : 'false' ?>"
            aria-controls="filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions">
    <a href="<?= $b ?>/admin/joined/add" class="btn btn-primary btn-sm">+ Add Joined FL</a>
    <a href="<?= $b ?>/admin/joined/settings" class="btn btn-quaternary btn-sm">Display Settings</a>
  </div>
</div>

<!-- Collapsible filter panel -->
<div class="filter-panel<?= $filtersActive ? ' is-open' : '' ?>" id="filter-panel">
  <form method="GET" action="<?= $b ?>/admin/joined" class="filter-row">

    <div class="toolbar-select-wrap">
    <select id="net-sel" name="network" class="select" data-net-cats='<?= json_encode($network_categories ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>'>
      <option value="">All Networks</option>
      <option value="TFL"<?= ($filter_network ?? '') === 'TFL'  ? ' selected' : '' ?>>TFL</option>
      <option value="TAFL"<?= ($filter_network ?? '') === 'TAFL' ? ' selected' : '' ?>>TAFL</option>
      <?php foreach ($custom_networks ?? [] as $net): ?>
        <option value="<?= View::e($net) ?>"
                <?= ($filter_network ?? '') === $net ? ' selected' : '' ?>>
          <?= View::e($net) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="toolbar-select-wrap">
    <select id="cat-sel" name="category" class="select">
      <option value="">All Categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= View::e($cat) ?>"
                <?= ($filter_cat ?? '') === $cat ? ' selected' : '' ?>>
          <?= View::e($cat) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="input" placeholder="Search&hellip;"
           value="<?= View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-quaternary btn-sm">Search</button>
    <a href="?" class="btn btn-quaternary btn-sm">Reset</a>

  </form>
</div>

<?php if (empty($joined)): ?>
  <div class="card">
    <div class="empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      <?php if (($search ?? '') !== '' || ($filter_network ?? '') !== '' || ($filter_cat ?? '') !== ''): ?>
        No joined listings match those filters.
        <a href="<?= $b ?>/admin/joined" class="btn btn-quaternary btn-sm">Clear Filters</a>
      <?php else: ?>
        No joined listings yet.
        <a href="<?= $b ?>/admin/joined/add" class="btn btn-primary btn-sm">+ Add a Listing</a>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

<form method="POST" action="<?= $b ?>/admin/joined/bulk" id="bulk-form"
      data-del-bulk data-del-entity="joined">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <!-- Bulk action bar -->
  <div class="bulk">
    <label for="bulk-action-sel">Bulk action:</label>
    <div class="toolbar-select-wrap">
    <select id="bulk-action-sel" name="bulk_action" class="select">
      <option value="" disabled selected>â€” Select â€”</option>
      <option value="approve">Approve</option>
      <option value="pending">Pending</option>
      <option value="delete">Delete</option>
    </select>
    </div>
    <button type="submit" class="btn btn-quaternary btn-sm">Apply</button>
  </div>

  <?php $loop = 0; $firstGroup = true; foreach ($grouped as $catName => $items): ?>
  <div class="card" style="margin-bottom:1.25rem">
    <div class="card-head">
      <span class="card-head-title"><?= View::e($catName) ?> (<?= count($items) ?>)</span>
    </div>
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" <?= $firstGroup ? 'id="sel-all"' : '' ?> title="Select all"><?php $firstGroup = false; ?></th>
          <th style="width:70px">Image</th>
          <th style="width:40px">#</th>
          <th><a href="<?= View::e($joinedSortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Subject / URL <?= View::sortIcon('name', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($joinedSortUrl('category')) ?>" class="sort-th<?= $sort === 'category' ? ' sort-active' : '' ?>">Category <?= View::sortIcon('category', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($joinedSortUrl('date_joined')) ?>" class="sort-th<?= $sort === 'date_joined' ? ' sort-active' : '' ?>">Date Joined <?= View::sortIcon('date_joined', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($joinedSortUrl('status')) ?>" class="sort-th<?= $sort === 'status' ? ' sort-active' : '' ?>">Status <?= View::sortIcon('status', $sort, $dir) ?></a></th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $jf): $loop++; ?>
        <tr>
          <td class="cb-col" data-label=""><input type="checkbox" name="ids[]" value="<?= (int) $jf->id ?>"
               data-name="<?= View::e($jf->name) ?>"></td>
          <td data-label="Image">
            <?php if ($jf->image !== null && $jf->image !== ''): ?>
              <img src="<?= $b ?>/uploads/joined/<?= View::e($jf->image) ?>"
                   alt="" class="fl-image" style="max-height:50px">
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="text-dim" data-label=""><?= $loop ?></td>
          <td data-label="Subject">
            <a href="<?= View::e($jf->url) ?>" target="_blank" rel="noopener noreferrer"
               class="link"><?= View::e($jf->name) ?></a>
          </td>
          <td class="text-muted" data-label="Category">
            <?php
              $cats = ($jf->categoriesJson !== null && $jf->categoriesJson !== '')
                ? json_decode($jf->categoriesJson, true)
                : [];
              if (!empty($cats) && is_array($cats)):
                $parts = [];
                foreach ($cats as $c) {
                    if (isset($c['network'], $c['name']) && $c['network'] !== '' && $c['name'] !== '') {
                        $parts[] = View::e($c['network']) . ': ' . View::e($c['name']);
                    }
                }
                echo $parts !== [] ? implode(', ', $parts) : View::e($jf->category);
              elseif ($jf->category !== ''):
                echo View::e($jf->category);
              else:
            ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="text-muted" data-label="Date">
            <?= $jf->dateJoined !== null ? View::e(date($admin_date_format, strtotime($jf->dateJoined))) : '<span class="text-dim">&mdash;</span>' ?>
          </td>
          <td data-label="Status">
            <?php $statusVal = $jf->status ?? 'joined'; ?>
            <span class="badge <?= $statusVal === 'pending' ? 'badge-pending' : 'badge-current' ?>">
              <?= $statusVal === 'pending' ? 'Pending' : 'Joined' ?>
            </span>
          </td>
          <td class="act-col" data-label="">
            <a href="<?= $b ?>/admin/joined/<?= (int) $jf->id ?>/edit"
               class="btn btn-primary btn-sm">Edit</a>
            <button type="submit" form="del-jf-<?= (int) $jf->id ?>" class="btn btn-danger btn-sm">Delete</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endforeach; ?>


</form>

<?php foreach ($joined as $jf): ?>
<form id="del-jf-<?= (int) $jf->id ?>" method="POST"
      action="<?= $b ?>/admin/joined/<?= (int) $jf->id ?>/delete"
      style="display:none"
      data-del-modal data-del-entity="joined"
      data-del-name="<?= View::e($jf->name) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
</form>
<?php endforeach; ?>

<?php endif; ?>


<?php if (($total_pages ?? 1) > 1): ?>
<nav class="pg" aria-label="Pagination">
  <a href="<?= View::e($pageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
     <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>&lsaquo; Prev</a>

  <?php for ($i = 1; $i <= $total_pages; $i++): ?>
    <a href="<?= View::e($pageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"
       <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>

  <a href="<?= View::e($pageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $total_pages ? ' disabled' : '' ?>"
     <?= $page >= $total_pages ? 'aria-disabled="true"' : '' ?>>Next &rsaquo;</a>
</nav>
<?php endif; ?>
