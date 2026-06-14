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
/** @var int      $total */
/** @var string   $status_tab */
/** @var array{all:int,approved:int,pending:int,hidden:int} $tab_counts */
/** @var array    $network_categories */
/** @var string   $sort */
/** @var string   $dir */
/** @var string   $success */
/** @var string   $error */
/** @var string   $base_url */
/** @var string   $csrf_token */

$b         = rtrim(View::e($base_url), '/');
$statusTab = $status_tab ?? 'all';
$tabCounts = $tab_counts ?? ['all' => 0, 'approved' => 0, 'pending' => 0, 'hidden' => 0];
$sort      = $sort ?? '';
$dir       = $dir  ?? 'asc';
$filtersActive = ($search ?? '') !== '' || ($filter_network ?? '') !== '' || ($filter_cat ?? '') !== '';

$buildUrl = static function (array $overrides) use ($b, $statusTab, $search, $filter_network, $filter_cat, $per_page, $page, $sort, $dir): string {
    $p = [
        'status_tab' => $statusTab !== 'all' ? $statusTab : '',
        'search'     => $search         ?? '',
        'network'    => $filter_network ?? '',
        'category'   => $filter_cat     ?? '',
        'per_page'   => ($per_page ?? 10) !== 10 ? (string) ($per_page ?? 10) : '',
        'sort'       => $sort !== '' ? $sort : '',
        'dir'        => ($dir !== 'asc' && $sort !== '') ? $dir : '',
        'page'       => ($page ?? 1) > 1 ? ($page ?? 1) : '',
    ];
    $merged = array_merge($p, $overrides);
    $qs = http_build_query(array_filter($merged, static fn($v) => $v !== '' && $v !== 0 && $v !== null));
    return $b . '/admin/joined' . ($qs !== '' ? '?' . $qs : '');
};

$tabUrl  = static fn(string $t) => $buildUrl(['status_tab' => $t !== 'all' ? $t : '', 'page' => '']);
$pageUrl = static fn(int $p)    => $buildUrl(['page' => $p > 1 ? $p : '']);
$sortUrl = static function (string $col) use ($buildUrl, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $buildUrl(['sort' => $col, 'dir' => $newDir !== 'asc' ? $newDir : '', 'page' => '']);
};
$resetUrl = $b . '/admin/joined' . ($statusTab !== 'all' ? '?status_tab=' . urlencode($statusTab) : '');

$tabDefs = [
    'all'      => ['label' => 'All',      'badge' => 'badge-inactive'],
    'approved' => ['label' => 'Approved', 'badge' => 'badge-current'],
    'pending'  => ['label' => 'Pending',  'badge' => 'badge-pending'],
    'hidden'   => ['label' => 'Hidden',   'badge' => 'badge-inactive'],
];

$bulkOptions = match ($statusTab) {
    'approved' => ['pending' => 'Mark Pending', 'hide' => 'Hide', 'delete' => 'Delete'],
    'pending'  => ['approve' => 'Approve', 'hide' => 'Hide', 'delete' => 'Delete'],
    'hidden'   => ['unhide'  => 'Unhide',  'delete' => 'Delete'],
    default    => ['approve' => 'Approve', 'pending' => 'Mark Pending', 'hide' => 'Hide', 'unhide' => 'Unhide', 'delete' => 'Delete'],
};
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
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="fl-filter-toggle"
            aria-expanded="true"
            aria-controls="filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/joined/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Joined Listing</a>
    <a href="<?= $b ?>/admin/joined/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?> Joined Settings</a>
  </div>
</div>

<!-- ──── FILTER PANEL ───────────────────────────────────────────────── -->
<div class="filter-panel" id="filter-panel">
  <form method="GET" action="<?= $b ?>/admin/joined" class="filter-row">
    <?php if ($statusTab !== 'all'): ?>
      <input type="hidden" name="status_tab" value="<?= View::e($statusTab) ?>">
    <?php endif; ?>

    <div class="select-wrap">
    <select id="net-sel" name="network" data-net-cats='<?= json_encode($network_categories ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) ?>'>
      <?php $_netCounts = $network_counts ?? []; $_netJoinedTotal = array_sum($_netCounts); ?>
      <option value=""><?= $_netJoinedTotal > 0 ? 'All Networks (' . $_netJoinedTotal . ')' : 'All Networks' ?></option>
      <?php $_tflN = (int) ($_netCounts['TFL'] ?? 0); ?>
      <option value="TFL"<?= ($filter_network ?? '') === 'TFL' ? ' selected' : '' ?>>TFL<?= $_tflN > 0 ? ' (' . $_tflN . ')' : '' ?></option>
      <?php $_taflN = (int) ($_netCounts['TAFL'] ?? 0); ?>
      <option value="TAFL"<?= ($filter_network ?? '') === 'TAFL' ? ' selected' : '' ?>>TAFL<?= $_taflN > 0 ? ' (' . $_taflN . ')' : '' ?></option>
      <?php foreach (array_keys($network_categories ?? []) as $net):
            if ($net === 'TFL' || $net === 'TAFL') { continue; }
            $_cn = (int) ($_netCounts[$net] ?? 0); ?>
        <option value="<?= View::e($net) ?>"
                <?= ($filter_network ?? '') === $net ? ' selected' : '' ?>>
          <?= View::e($net) ?><?= $_cn > 0 ? ' (' . $_cn . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="select-wrap">
    <?php $_jCatCounts = $category_counts ?? []; ?>
    <select id="cat-sel" name="category">
      <option value="">All Categories</option>
      <?php if (($filter_network ?? '') === ''): ?>
        <?php foreach (['TFL', 'TAFL'] as $fixedNet): ?>
          <?php $netCatList = ($network_categories ?? [])[$fixedNet] ?? []; ?>
          <?php if (!empty($netCatList)): ?>
            <optgroup label="<?= View::e($fixedNet) ?>">
              <?php foreach ($netCatList as $cat): ?>
                <?php $_jCc = (int) ($_jCatCounts[$cat] ?? 0); ?>
                <option value="<?= View::e($cat) ?>"
                        <?= ($filter_cat ?? '') === $cat ? 'selected' : '' ?>><?= View::e($cat) ?><?= $_jCc > 0 ? ' (' . $_jCc . ')' : '' ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php foreach ($network_categories ?? [] as $net => $netCatList): ?>
          <?php if ($net === 'TFL' || $net === 'TAFL' || empty($netCatList)) { continue; } ?>
          <optgroup label="<?= View::e($net) ?>">
            <?php foreach ($netCatList as $cat): ?>
              <?php $_jCc = (int) ($_jCatCounts[$cat] ?? 0); ?>
              <option value="<?= View::e($cat) ?>"
                      <?= ($filter_cat ?? '') === $cat ? 'selected' : '' ?>><?= View::e($cat) ?><?= $_jCc > 0 ? ' (' . $_jCc . ')' : '' ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach ($categories ?? [] as $cat): ?>
          <?php $_jCc = (int) ($_jCatCounts[$cat] ?? 0); ?>
          <option value="<?= View::e($cat) ?>"
                  <?= ($filter_cat ?? '') === $cat ? 'selected' : '' ?>><?= View::e($cat) ?><?= $_jCc > 0 ? ' (' . $_jCc . ')' : '' ?></option>
        <?php endforeach; ?>
      <?php endif; ?>
    </select>
    </div>

    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search&hellip;"
             value="<?= View::e($search ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= View::e($resetUrl) ?>" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>

<!-- ──── STATUS TABS ─────────────────────────────────────────────────── -->
<div class="tabs-wrap">
  <div class="tabs">
    <?php foreach ($tabDefs as $key => $td): ?>
      <a href="<?= View::e($tabUrl($key)) ?>"
         class="tabs-btn<?= $statusTab === $key ? ' active' : '' ?>">
        <?= View::e($td['label']) ?>
        <?php $cnt = (int) ($tabCounts[$key] ?? 0); ?>
        <?php if ($cnt > 0): ?>
          <span class="badge <?= $td['badge'] ?> badge-inline-sm"><?= $cnt ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">Status</span>
  <div class="select-wrap flex-fill">
    <select onchange="window.location.href=this.value">
      <?php foreach ($tabDefs as $key => $td): ?>
        <option value="<?= View::e($tabUrl($key)) ?>"<?= $statusTab === $key ? ' selected' : '' ?>>
          <?= View::e($td['label']) ?> (<?= (int) ($tabCounts[$key] ?? 0) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<!-- ──── TABLE ──────────────────────────────────────────────────────── -->
<?php if (empty($joined)): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('heart', ['width' => 32, 'height' => 32]) ?></div>
    <?php if ($filtersActive || $statusTab !== 'all'): ?>
      <div class="empty-title">No joined listings match those filters.</div>
      <a href="<?= View::e($resetUrl) ?>" class="btn btn-ghost btn-sm">Clear Filters</a>
    <?php else: ?>
      <div class="empty-title">No joined listings yet.</div>
      <div>Track the listings you&rsquo;re a member of.</div>
      <a href="<?= $b ?>/admin/joined/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Joined Listing</a>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<?php
  $from = (int) ($total ?? 0) > 0 ? ($page - 1) * ($per_page ?? 10) + 1 : 0;
  $to   = ($page - 1) * ($per_page ?? 10) + count($joined);
?>

<form method="POST" action="<?= $b ?>/admin/joined/bulk" id="bulk-form"
      data-del-bulk data-del-entity="joined">
  <input type="hidden" name="_csrf"                value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="_status_tab"          value="<?= View::e($statusTab) ?>">
  <input type="hidden" name="select_all"           value="0">
  <input type="hidden" name="sa_filter_search"     value="<?= View::e($search ?? '') ?>">
  <input type="hidden" name="sa_filter_network"    value="<?= View::e($filter_network ?? '') ?>">
  <input type="hidden" name="sa_filter_cat"        value="<?= View::e($filter_cat ?? '') ?>">
  <input type="hidden" name="sa_filter_status_tab" value="<?= View::e($statusTab) ?>">

  <div class="card">
    <div class="card-head">
      <?= match ($statusTab) {
          'approved' => 'Approved Joined Listings',
          'pending'  => 'Pending Joined Listings',
          'hidden'   => 'Hidden Joined Listings',
          default    => 'All Joined Listings',
      } ?>
      <span class="row-sub">
        Showing <?= $from ?>&#8211;<?= $to ?> of <?= number_format((int) ($total ?? 0)) ?> listing<?= (int) ($total ?? 0) !== 1 ? 's' : '' ?>
      </span>
    </div>

    <div class="bulk bulk--flush">
      Bulk Actions:
      <div class="select-wrap">
        <select id="bulk-action-sel" class="select-xs" name="bulk_action">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <?php foreach ($bulkOptions as $val => $label): ?>
            <option value="<?= View::e($val) ?>"><?= View::e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>

    <div class="select-all-banner" id="sel-all-sa-banner" data-total="<?= (int) ($total ?? 0) ?>" style="display:none">
      <span class="sa-page">All <strong class="sa-page-count">0</strong> items on this page are selected.&ensp;<button type="button" class="link sa-select-all-btn">Select all <strong class="sa-total-count">0</strong> items</button></span>
      <span class="sa-all" style="display:none">All <strong class="sa-total-count">0</strong> items are selected.&ensp;<button type="button" class="link sa-clear-btn">Clear selection</button></span>
    </div>

    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="sel-all" title="Select all"></th>
          <th class="table-col-image">Image</th>
          <th class="table-col-xs">#</th>
          <th><a href="<?= View::e($sortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Subject / URL <?= View::sortIcon('name', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($sortUrl('category')) ?>" class="sort-th<?= $sort === 'category' ? ' sort-active' : '' ?>">Category <?= View::sortIcon('category', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($sortUrl('date_joined')) ?>" class="sort-th<?= $sort === 'date_joined' ? ' sort-active' : '' ?>">Date Joined <?= View::sortIcon('date_joined', $sort, $dir) ?></a></th>
          <?php if ($statusTab === 'all'): ?>
          <th><a href="<?= View::e($sortUrl('status')) ?>" class="sort-th<?= $sort === 'status' ? ' sort-active' : '' ?>">Status <?= View::sortIcon('status', $sort, $dir) ?></a></th>
          <?php endif; ?>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($joined as $loop => $jf): ?>
        <tr>
          <td class="cb-col" data-label=""><input type="checkbox" name="ids[]" value="<?= (int) $jf->id ?>"
               data-name="<?= View::e($jf->name) ?>"></td>
          <td data-label="Image">
            <?php if ($jf->image !== null && $jf->image !== ''): ?>
              <img src="<?= $b ?>/uploads/joined/<?= View::e($jf->image) ?>"
                   alt="" class="thumbnail">
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="text-dim" data-label=""><?= $loop + 1 ?></td>
          <td data-label="Subject">
            <a href="<?= View::e($jf->url) ?>" target="_blank" rel="noopener noreferrer"
               class="link"><b><?= View::e($jf->name) ?></b></a>
          </td>
          <td data-label="Category">
            <?php
              $cats = ($jf->categoriesJson !== null && $jf->categoriesJson !== '')
                ? json_decode($jf->categoriesJson, true)
                : [];
              if (!empty($cats) && is_array($cats)):
                $parts = [];
                foreach ($cats as $c) {
                    if (!isset($c['network'], $c['name']) || $c['network'] === '' || $c['name'] === '') { continue; }
                    $netClass = match (strtolower($c['network'])) {
                        'tfl'  => 'badge-tfl',
                        'tafl' => 'badge-tafl',
                        default => 'badge-info',
                    };
                    $parts[] = '<span class="cat-net-item"><span class="badge ' . $netClass . '">'
                             . View::e($c['network']) . '</span>' . View::e($c['name']) . '</span>';
                }
                if ($parts !== []):
                    echo implode('<span class="text-muted">, </span>', $parts);
                elseif ($jf->category !== ''):
                    echo View::e($jf->category);
                else:
                    echo '<span class="text-dim">&mdash;</span>';
                endif;
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
          <?php if ($statusTab === 'all'): ?>
          <td data-label="Status">
            <?php $statusVal = $jf->status ?? 'approved'; ?>
            <span class="badge <?= $statusVal === 'pending' ? 'badge-pending' : 'badge-current' ?>">
              <?= $statusVal === 'pending' ? 'Pending' : 'Approved' ?>
            </span>
          </td>
          <?php endif; ?>
          <td class="act-col" data-label="">
            <div class="data-actions">
              <a href="<?= $b ?>/admin/joined/<?= (int) $jf->id ?>/edit"
                 class="btn btn-tertiary btn-xs">Edit</a>
              <button type="submit" form="del-joined-<?= (int) $jf->id ?>" class="btn btn-danger btn-xs">Delete</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</form>

<?php foreach ($joined as $jf): ?>
<form id="del-joined-<?= (int) $jf->id ?>" method="POST"
      action="<?= $b ?>/admin/joined/<?= (int) $jf->id ?>/delete"
      data-del-modal data-del-entity="joined"
      data-del-name="<?= View::e($jf->name) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
</form>
<?php endforeach; ?>

<?php endif; ?>

<?php $pg_current = $page; $pg_total = $total_pages; $pg_url = $pageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
