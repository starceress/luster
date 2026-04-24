<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing[] $listings */
/** @var array<int,int>         $member_counts */
/** @var array<int,int>         $pending_counts */
/** @var array<int,string|null> $images */
/** @var array<int,string>      $listing_types */
/** @var array<int,string>      $category_names */
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

$flFilterQs = http_build_query(array_filter([
    'search'   => $search          ?? '',
    'network'  => $filter_network  ?? '',
    'category' => $filter_cat      ?? '',
    'type'     => $filter_type     ?? '',
], static fn(string $v): bool => $v !== ''));
$flPageUrl = static function (int $p) use ($b, $flFilterQs): string {
    $qs = $flFilterQs !== '' ? $flFilterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/listings?' . $qs;
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
//   nothing â€ â€™ "All Listings"
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

$flSortUrl = static function (string $col) use ($b, $flSort, $flDir, $search, $filter_network, $filter_cat, $filter_type): string {
    $newDir = ($flSort === $col && $flDir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
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
            aria-expanded="<?= $filtersActive ? 'true' : 'false' ?>"
            aria-controls="filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div>
    <a href="<?= $b ?>/admin/listings/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Listing</a>
    <a href="<?= $b ?>/admin/listings/collective-templates" class="btn btn-secondary btn-sm">Collective Templates</a>
    <a href="<?= $b ?>/admin/listings/settings" class="btn btn-quaternary btn-sm">Display Settings</a>
  </div>
</div>

<div class="filter-panel<?= $filtersActive ? ' is-open' : '' ?>" id="filter-panel">
  <form method="GET" action="" class="filter-row" id="fl-filter-form">
    <div class="select-wrap">
    <select id="fl-net-sel" name="network" >
      <option value="">All Networks</option>
      <?php foreach ($allNetworks as $net): ?>
        <option value="<?= View::e($net) ?>"
                <?= ($filter_network ?? '') === $net ? 'selected' : '' ?>>
          <?= View::e($net) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="select-wrap">
    <select id="fl-cat-sel" name="category" 
            data-tfl='<?= $dataTfl ?>'
            data-tafl='<?= $dataTafl ?>'
            data-custom='<?= $dataCustom ?>'>
      <option value="">All Categories</option>
      <?php foreach ($activeCats as $c): ?>
        <option value="<?= View::e($c['name']) ?>"
                <?= ($filter_cat ?? '') === $c['name'] ? 'selected' : '' ?>>
          <?= View::e($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="select-wrap">
    <select name="type" class="js-autosubmit">
      <option value="">All Types</option>
      <?php
        $_typeLabels = ['listing' => 'Fanlisting', 'clique' => 'Clique', 'collective' => 'Collective', 'shrine' => 'Shrine', 'webring' => 'Webring', 'other' => 'Other'];
      ?>
      <?php foreach ($type_options ?? [] as $to): ?>
        <option value="<?= View::e($to) ?>"
                <?= ($filter_type ?? '') === $to ? 'selected' : '' ?>>
          <?= View::e($_typeLabels[$to] ?? ucfirst($to)) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="form"
           placeholder="Search&hellip;" value="<?= View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-quaternary btn-sm">Search</button>
    <a href="?" class="btn btn-quaternary btn-sm">Reset</a>

  </form>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<?php if (empty($listings)): ?>
  <div class="card">
    <div class="empty">
      <span class="empty-icon"><?= View::icon('star', ['width' => 32, 'height' => 32]) ?></span>
      No listings yet.
      <a href="<?= $b ?>/admin/listings/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Listing</a>
    </div>
  </div>
<?php else: ?>

<form method="POST" action="<?= $b ?>/admin/listings/bulk" id="bulk-form"
      data-del-bulk data-del-entity="listing">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="bulk">
    <label for="bulk-action">Bulk Actions:</label>
    <div class="select-wrap">
    <select id="bulk-action" name="action" >
      <option value="" disabled selected>&mdash; Select &mdash;</option>
      <option value="delete">Delete</option>
    </select>
    </div>
    <button type="submit" class="btn btn-quaternary btn-sm">Apply</button>
  </div>

  <?php $flFrom = ($page - 1) * $per_page + 1; $flTo = ($page - 1) * $per_page + count($listings); ?>

  <div class="card">
    <div class="card-head">
      <span><?= $tableTitle ?></span>
      <span class="row-sub">
        Showing <?= $flFrom ?>&#8211;<?= $flTo ?> of <?= number_format((int) $total) ?> listing<?= (int) $total !== 1 ? 's' : '' ?><?php if ($total_pages > 1): ?> &mdash; page <?= (int) $page ?> of <?= (int) $total_pages ?><?php endif; ?>
      </span>
    </div>
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="fl-select-all"></th>
          <th><a href="<?= View::e($flSortUrl('id')) ?>" class="sort-th<?= $flSort === 'id' ? ' sort-active' : '' ?>"># <?= View::sortIcon('id', $flSort, $flDir) ?></a></th>
          <th><a href="<?= View::e($flSortUrl('subject')) ?>" class="sort-th<?= $flSort === 'subject' ? ' sort-active' : '' ?>">Subject <?= View::sortIcon('subject', $flSort, $flDir) ?></a></th>
          <th><a href="<?= View::e($flSortUrl('name')) ?>" class="sort-th<?= $flSort === 'name' ? ' sort-active' : '' ?>">Title <?= View::sortIcon('name', $flSort, $flDir) ?></a></th>
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
            <input type="checkbox" name="selected[]" value="<?= (int) $fl->id ?>" class="fl-row-cb" data-name="<?= View::e($fl->name) ?>">
          </td>

          <!-- Listing # -->
          <td class="text-dim" data-label="ID"><?= (int) $fl->id ?></td>

          <!-- Subject -->
          <td data-label="Subject">
            <?php if ($fl->url !== ''): ?>
              <a href="<?= View::e($fl->url) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($fl->subject) ?></a>
            <?php else: ?>
              <?= View::e($fl->subject) ?>
            <?php endif; ?>
          </td>

          <!-- Title -->
          <td data-label="Title">
            <a href="<?= $b ?>/admin/listings/<?= (int) $fl->id ?>/edit" class="link"><?= View::e($fl->name) ?></a>
          </td>

          <!-- Category -->
          <td class="text-muted" data-label="Category"><?= View::e($category_names[$fl->id] ?? '-') ?></td>

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
                <a href="<?= $b ?>/admin/members/<?= (int) $fl->id ?>?status=pending"
                   class="badge badge-pending badge-inline-sm">+ <?= $pend ?></a>
            </div>
            <?php endif; ?>
          </td>

          <!-- Actions -->
          <td class="act-col" data-label="">
            <div class="data-actions">
              <a href="<?= $b ?>/admin/listings/<?= (int) $fl->id ?>/edit"
                 class="btn btn-tertiary btn-sm">Edit</a>
              <a href="<?= $b ?>/admin/members/<?= (int) $fl->id ?>"
                 class="btn btn-quaternary btn-sm">Members</a>
              <button type="submit" form="del-listing-<?= (int) $fl->id ?>" class="btn btn-danger btn-sm">Delete</button>
            </div>
          </td>

        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</form>

<?php if (($total_pages ?? 1) > 1): ?>
<nav class="pg" aria-label="Pagination">
  <a href="<?= View::e($flPageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
     <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>&lsaquo; Prev</a>

  <?php
    $window = 2;
    $start  = max(1, $page - $window);
    $end    = min($total_pages, $page + $window);
    if ($start > 1): ?>
      <a href="<?= View::e($flPageUrl(1)) ?>" class="pg-link">1</a>
      <?php if ($start > 2): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="<?= View::e($flPageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"
       <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $total_pages): ?>
    <?php if ($end < $total_pages - 1): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
    <a href="<?= View::e($flPageUrl($total_pages)) ?>" class="pg-link"><?= $total_pages ?></a>
  <?php endif; ?>

  <a href="<?= View::e($flPageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $total_pages ? ' disabled' : '' ?>"
     <?= $page >= $total_pages ? 'aria-disabled="true"' : '' ?>>Next &rsaquo;</a>
</nav>
<?php endif; ?>

<?php endif; ?>
