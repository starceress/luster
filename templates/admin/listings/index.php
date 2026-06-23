<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing[] $listings */
/** @var array<int,int>         $member_counts */
/** @var array<int,int>         $pending_counts */
/** @var array<int,string|null> $images */
/** @var array<int,string>      $listing_types */
/** @var array<int,string>      $category_names */
/** @var array<int,array<array{network:string,name:string}>> $category_data */
/** @var array                  $cfg */
/** @var string                 $base_url */
/** @var string                 $csrf_token */
/** @var string                 $success */
/** @var string                 $search */
/** @var string                 $filter_network */
/** @var string                 $filter_cat */
/** @var array                  $tfl_cats */
/** @var array                  $tafl_cats */
/** @var string[]               $custom_networks */
/** @var array<string,array>    $custom_cats */
/** @var string                 $filter_type */
/** @var string[]               $type_options */
/** @var int                    $page */
/** @var int                    $total_pages */
/** @var int                    $total */
/** @var int                    $per_page */
$b = rtrim(View::e($base_url), '/');

$flTab = $tab ?? 'all';

$flFilterQs = http_build_query(array_filter([
    'tab'      => $flTab,
    'search'   => $search          ?? '',
    'network'  => $filter_network  ?? '',
    'category' => $filter_cat      ?? '',
    'type'     => $filter_type     ?? '',
], static fn(string $v): bool => $v !== ''));
$flPageUrl = static function (int $p) use ($b, $flFilterQs): string {
    $qs = $flFilterQs !== '' ? $flFilterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/listings?' . $qs;
};

$flTabUrl = static function (string $t) use ($b, $search, $filter_network, $filter_cat, $filter_type): string {
    $qs = http_build_query(array_filter([
        'tab'      => $t,
        'search'   => $search          ?? '',
        'network'  => $filter_network  ?? '',
        'category' => $filter_cat      ?? '',
        'type'     => $filter_type     ?? '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/listings' . ($qs !== '' ? '?' . $qs : '');
};

$sizeMap   = ['small' => '50px', 'medium' => '100px', 'large' => '150px'];
$sizeKey   = (string) ($cfg['image_preview_size'] ?? 'medium');
$imgHeight = $sizeMap[$sizeKey] ?? '100px';

$dataTfl    = json_encode($tfl_cats    ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl   = json_encode($tafl_cats   ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom = json_encode($custom_cats ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);

$fn = $filter_network ?? '';
if ($fn === 'TFL')      $activeCats = $tfl_cats ?? [];
elseif ($fn === 'TAFL') $activeCats = $tafl_cats ?? [];
elseif ($fn !== '')     $activeCats = ($custom_cats ?? [])[$fn] ?? [];
else {
    $activeCats = array_merge($tfl_cats ?? [], $tafl_cats ?? []);
    foreach ($custom_cats ?? [] as $netCats) {
        $activeCats = array_merge($activeCats, $netCats);
    }
    usort($activeCats, static fn($a, $b) => strcmp($a['name'], $b['name']));
}

$allNetworks  = array_merge(['TFL', 'TAFL'], $custom_networks ?? []);
usort($allNetworks, static fn($a, $b) => strcasecmp($a, $b));
$filtersActive = ($search ?? '') !== '' || ($filter_network ?? '') !== ''
              || ($filter_cat ?? '') !== '' || ($filter_type ?? '') !== '';

//  Dynamic card-head title 
// Combinations:
//   network [: category] [ - type] [ - "search"]
//   type only, search only, or any subset thereof
//   nothing → "All Listings"
$_fn  = $filter_network ?? '';
$_fc  = $filter_cat     ?? '';
$_ft  = $filter_type    ?? '';
$_fs  = $search         ?? '';

$_titleParts = [];
if ($_fn !== '') {
    $_scope = View::e($_fn);
    if ($_fc !== '') {
        $_scope .= ': ' . View::e($_fc);
    }
    $_titleParts[] = $_scope;
}
if ($_ft !== '') {
    $_titleParts[] = View::e(ucfirst($_ft));
}
if ($_fs !== '') {
    $_titleParts[] = '&#8220;' . View::e($_fs) . '&#8221;';
}
$tableTitle = !empty($_titleParts) ? implode(' &mdash; ', $_titleParts) : 'All Listings';

$flSort = $sort ?? '';
$flDir  = $dir  ?? 'asc';

$flSortUrl = static function (string $col) use ($b, $flSort, $flDir, $flTab, $search, $filter_network, $filter_cat, $filter_type): string {
    $newDir = ($flSort === $col && $flDir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'tab'      => $flTab,
        'search'   => $search          ?? '',
        'network'  => $filter_network  ?? '',
        'category' => $filter_cat      ?? '',
        'type'     => $filter_type     ?? '',
        'sort'     => $col,
        'dir'      => $newDir,
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/listings' . ($qs !== '' ? '?' . $qs : '');
};

?>

<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="fl-filter-toggle"
            aria-expanded="true"
            aria-controls="filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div>
    <a href="<?= $b ?>/admin/listings/add" class="btn btn-primary btn-sm"><?= View::icon('add', ['width' => 20, 'height' => 20]) ?> Add Listing</a>
    <a href="<?= $b ?>/admin/listings/global-templates" class="btn btn-secondary btn-sm"><?= View::icon('template') ?> Global Templates</a>
    <a href="<?= $b ?>/admin/listings/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?> Owned Settings</a>
  </div>
</div>

<div class="filter-panel" id="filter-panel">
  <form method="GET" action="" class="filter-row" id="fl-filter-form">
    <input type="hidden" name="tab" value="<?= View::e($flTab) ?>">
    <div class="select-wrap">
    <select id="fl-net-sel" name="network" >
      <?php $_netCounts = $network_counts ?? []; $_netTotal = $total_before_filter ?? array_sum($_netCounts); ?>
      <option value=""><?= 'All Networks' . ($_netTotal > 0 ? ' (' . $_netTotal . ')' : '') ?></option>
      <?php foreach ($allNetworks as $net): ?>
        <?php $_nc = (int) ($_netCounts[strtoupper($net)] ?? 0); ?>
        <option value="<?= View::e($net) ?>"
                <?= ($filter_network ?? '') === $net ? 'selected' : '' ?>>
          <?= View::e($net) ?><?= $_nc > 0 ? ' (' . $_nc . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="select-wrap">
    <select id="fl-cat-sel" name="category"
            data-tfl='<?= $dataTfl ?>'
            data-tafl='<?= $dataTafl ?>'
            data-custom='<?= $dataCustom ?>'>
      <?php $_catCounts = $category_counts ?? []; $_catTotal = $total_after_network_filter ?? $total_before_filter ?? array_sum($_catCounts); ?>
      <option value=""><?= 'All Categories' . ($_catTotal > 0 ? ' (' . $_catTotal . ')' : '') ?></option>
      <?php if ($fn === ''): ?>
        <?php if (!empty($tfl_cats)): ?>
          <optgroup label="TFL">
            <?php foreach ($tfl_cats as $c): ?>
              <?php $_ccc = (int) ($_catCounts[$c['name']] ?? 0); ?>
              <option value="<?= View::e($c['name']) ?>"
                      <?= ($filter_cat ?? '') === $c['name'] ? 'selected' : '' ?>>
                <?= View::e($c['name']) ?><?= $_ccc > 0 ? ' (' . $_ccc . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
        <?php if (!empty($tafl_cats)): ?>
          <optgroup label="TAFL">
            <?php foreach ($tafl_cats as $c): ?>
              <?php $_ccc = (int) ($_catCounts[$c['name']] ?? 0); ?>
              <option value="<?= View::e($c['name']) ?>"
                      <?= ($filter_cat ?? '') === $c['name'] ? 'selected' : '' ?>>
                <?= View::e($c['name']) ?><?= $_ccc > 0 ? ' (' . $_ccc . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
        <?php foreach ($custom_cats ?? [] as $netName => $netCats): ?>
          <?php if (!empty($netCats)): ?>
            <optgroup label="<?= View::e($netName) ?>">
              <?php foreach ($netCats as $c): ?>
                <?php $_ccc = (int) ($_catCounts[$c['name']] ?? 0); ?>
                <option value="<?= View::e($c['name']) ?>"
                        <?= ($filter_cat ?? '') === $c['name'] ? 'selected' : '' ?>>
                  <?= View::e($c['name']) ?><?= $_ccc > 0 ? ' (' . $_ccc . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach ($activeCats as $c): ?>
          <?php $_ccc = (int) ($_catCounts[$c['name']] ?? 0); ?>
          <option value="<?= View::e($c['name']) ?>"
                  <?= ($filter_cat ?? '') === $c['name'] ? 'selected' : '' ?>>
            <?= View::e($c['name']) ?><?= $_ccc > 0 ? ' (' . $_ccc . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      <?php endif; ?>
    </select>
    </div>

    <div class="select-wrap">
    <select name="type">
      <?php $_typeLabels = ['listing' => 'Fanlisting', 'clique' => 'Clique', 'collective' => 'Collective', 'shrine' => 'Shrine', 'webring' => 'Webring', 'other' => 'Other']; ?>
      <?php $_typeCounts = $type_counts ?? []; $_typeTotal = array_sum($_typeCounts); ?>
      <option value=""><?= 'All Types' . ($_typeTotal > 0 ? ' (' . $_typeTotal . ')' : '') ?></option>
      <?php foreach ($type_options ?? [] as $to): ?>
        <?php $_tc = (int) ($_typeCounts[$to] ?? 0); ?>
        <option value="<?= View::e($to) ?>"
                <?= ($filter_type ?? '') === $to ? 'selected' : '' ?>>
          <?= View::e($_typeLabels[$to] ?? ucfirst($to)) ?><?= $_tc > 0 ? ' (' . $_tc . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form"
             placeholder="Search&hellip;" value="<?= View::e($search ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php $flResetUrl = $b . '/admin/listings?tab=' . urlencode($flTab); ?>
    <a href="<?= View::e($flResetUrl) ?>" class="btn btn-quaternary btn-sm">Reset</a>

  </form>
</div>

<?php
$_flStatusTabs = [
    'all'      => ['label' => 'All',      'badge' => 'badge-inactive'],
    'pending'  => ['label' => 'Pending',  'badge' => 'badge-pending'],
    'current'  => ['label' => 'Current',  'badge' => 'badge-current'],
    'upcoming' => ['label' => 'Upcoming', 'badge' => 'badge-upcoming'],
];
$_flTabCounts = $tab_counts ?? [];
?>
<div class="tabs-wrap">
  <div class="tabs">
    <?php foreach ($_flStatusTabs as $_flKey => $_flTabData): ?>
      <a href="<?= View::e($flTabUrl($_flKey)) ?>"
         class="tabs-btn<?= $flTab === $_flKey ? ' active' : '' ?>">
        <?= View::e($_flTabData['label']) ?>
        <?php $_flCnt = (int) ($_flTabCounts[$_flKey] ?? 0); ?>
        <?php if ($_flCnt > 0): ?>
          <span class="badge <?= $_flTabData['badge'] ?> badge-inline-sm"><?= $_flCnt ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">Status</span>
  <div class="select-wrap flex-fill">
    <select onchange="window.location.href=this.value">
      <?php foreach ($_flStatusTabs as $_flKey => $_flTabData): ?>
        <option value="<?= View::e($flTabUrl($_flKey)) ?>"<?= $flTab === $_flKey ? ' selected' : '' ?>>
          <?= View::e($_flTabData['label']) ?> (<?= (int) ($_flTabCounts[$_flKey] ?? 0) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<?php if (empty($listings)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('star', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No listings yet.</div>
      <div>Add your first listing to get started.</div>
      <a href="<?= $b ?>/admin/listings/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Listing</a>
    </div>
  </div>
<?php else: ?>

<form method="POST" action="<?= $b ?>/admin/listings/bulk" id="bulk-form"
      data-del-bulk data-del-entity="listing">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="select_all" value="0">
  <input type="hidden" name="sa_filter_tab" value="<?= View::e($flTab) ?>">
  <input type="hidden" name="sa_filter_search" value="<?= View::e($search ?? '') ?>">
  <input type="hidden" name="sa_filter_network" value="<?= View::e($filter_network ?? '') ?>">
  <input type="hidden" name="sa_filter_cat" value="<?= View::e($filter_cat ?? '') ?>">
  <input type="hidden" name="sa_filter_type" value="<?= View::e($filter_type ?? '') ?>">

  <?php $flFrom = ($page - 1) * $per_page + 1; $flTo = ($page - 1) * $per_page + count($listings); ?>

  <div class="card">
    <div class="card-head">
      <span><?= $tableTitle ?></span>
      <span class="card-head-sub">
        Showing <?= $flFrom ?>&#8211;<?= $flTo ?> of <?= number_format((int) $total) ?> listing<?= (int) $total !== 1 ? 's' : '' ?><?php if ($total_pages > 1): ?> &mdash; page <?= (int) $page ?> of <?= (int) $total_pages ?><?php endif; ?>
      </span>
    </div>
    <div class="bulk bulk--flush">
      Bulk Actions:
      <div class="select-wrap">
      <select id="bulk-action" name="action" class="select-xs">
        <option value="" disabled selected>&mdash; Select &mdash;</option>
        <option value="mark_updated">Mark as Updated</option>
        <option value="delete">Delete</option>
      </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>
    <div class="select-all-banner" id="fl-select-all-sa-banner" data-total="<?= (int) ($total ?? 0) ?>" style="display:none">
      <span class="sa-page">All <strong class="sa-page-count">0</strong> items on this page are selected.&ensp;<button type="button" class="link sa-select-all-btn">Select all <strong class="sa-total-count">0</strong> listings</button></span>
      <span class="sa-all" style="display:none">All <strong class="sa-total-count">0</strong> listings are selected.&ensp;<button type="button" class="link sa-clear-btn">Clear selection</button></span>
    </div>
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="fl-select-all"></th>
          <th><a href="<?= View::e($flSortUrl('id')) ?>" class="sort-th<?= $flSort === 'id' ? ' sort-active' : '' ?>"># <?= View::sortIcon('id', $flSort, $flDir) ?></a></th>
          <th><a href="<?= View::e($flSortUrl('subject')) ?>" class="sort-th<?= $flSort === 'subject' ? ' sort-active' : '' ?>">Subject / Title <?= View::sortIcon('subject', $flSort, $flDir) ?></a></th>
          <th><a href="<?= View::e($flSortUrl('category')) ?>" class="sort-th<?= $flSort === 'category' ? ' sort-active' : '' ?>">Category <?= View::sortIcon('category', $flSort, $flDir) ?></a></th>
          <th><a href="<?= View::e($flSortUrl('status')) ?>" class="sort-th<?= $flSort === 'status' ? ' sort-active' : '' ?>">Status <?= View::sortIcon('status', $flSort, $flDir) ?></a></th>
          <th>Members</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($listings as $fl): ?>
        <tr>

          <!-- Checkbox -->
          <td class="cb-col" data-label="">
            <input type="checkbox" name="selected[]" value="<?= (int) $fl->id ?>" class="fl-row-cb" data-name="<?= View::e($fl->title) ?>">
          </td>

          <!-- Listing # -->
          <td class="text-dim" data-label="ID"><?= (int) $fl->id ?></td>

          <!-- Subject / Title -->
          <td data-label="Subject / Title">
            <div class="subject-title-stack">
              <?php if ($fl->url !== ''): ?>
                <a href="<?= View::e($fl->url) ?>" target="_blank" rel="noopener noreferrer" class="link"><b><?= View::e($fl->subject) ?></b></a>
              <?php else: ?>
                <?= View::e($fl->subject) ?>
              <?php endif; ?>
              <span class="mem-url"><?= View::e($fl->title) ?></span>
            </div>
          </td>

          <!-- Category -->
          <td data-label="Category">
            <?php $catItems = $category_data[$fl->id] ?? []; ?>
            <?php if (empty($catItems)): ?>
              <span class="text-muted">—</span>
            <?php else: ?>
              <?php foreach ($catItems as $i => $cat): ?>
                <?php if ($i > 0): ?><span class="text-muted">, </span><?php endif; ?>
                <?php
                  $netClass = match (strtolower($cat['network'])) {
                      'tfl'  => 'badge-tfl',
                      'tafl' => 'badge-tafl',
                      default => 'badge-info',
                  };
                ?>
                <span class="cat-net-item"><span class="badge <?= $netClass ?>"><?= View::e($cat['network']) ?></span><?= View::e($cat['name']) ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>

          <!-- Status -->
          <td data-label="Status">
            <?php if ($fl->upcoming): ?>
              <span class="badge badge-upcoming">Upcoming</span>
            <?php elseif ($fl->trouble): ?>
              <span class="badge badge-pending">Pending</span>
            <?php else: ?>
              <span class="badge badge-current">Current</span>
            <?php endif; ?>
          </td>

          <!-- Members -->
          <td data-label="Members">
            <?php
              $app  = (int) ($member_counts[$fl->id]  ?? 0);
              $pend = (int) ($pending_counts[$fl->id] ?? 0);
            ?>
            <div><?= $app ?>
            <?php if ($pend > 0): ?>
                <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $fl->id ?>&status=pending"
                   class="badge badge-pending badge-inline-sm">+ <?= $pend ?></a>
            </div>
            <?php endif; ?>
          </td>
<!-- ──── ACTIONS ────────────────────────────────────────────────────── -->
          <td class="act-col" data-label="">
            <div class="actions-row">
              <a href="<?= $b ?>/admin/listings/<?= (int) $fl->id ?>/edit"
                 class="btn btn-tertiary btn-xs">Edit</a>
              <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $fl->id ?>&status=all"
                 class="btn btn-quaternary btn-xs">Members</a>
              <button type="submit" form="del-listing-<?= (int) $fl->id ?>" class="btn btn-danger btn-xs">Delete</button>
            </div>
          </td>

        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</form>

<?php foreach ($listings as $fl): ?>
<form id="del-listing-<?= (int) $fl->id ?>" method="POST"
      action="<?= $b ?>/admin/listings/<?= (int) $fl->id ?>/delete"
      data-del-modal data-del-entity="listing"
      data-del-name="<?= View::e($fl->title) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
</form>
<?php endforeach; ?>

<?php $pg_current = $page; $pg_total = $total_pages; $pg_url = $flPageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>

<?php endif; ?>
