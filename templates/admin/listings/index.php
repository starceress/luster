<?php
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
$b = rtrim(\Luster\Core\View::e($base_url), '/');

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

$flSortIcon = static function (string $col) use ($flSort, $flDir): string {
    if ($flSort !== $col) {
        return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="opacity:.35;flex-shrink:0"><path d="M5 0l3 4H2zM5 14l-3-4h6z"/></svg>';
    }
    if ($flDir === 'asc') {
        return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="flex-shrink:0"><path d="M5 0l3 4H2z"/><path d="M5 14l-3-4h6z" fill-opacity=".25"/></svg>';
    }
    return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="flex-shrink:0"><path d="M5 0l3 4H2z" fill-opacity=".25"/><path d="M5 14l-3-4h6z"/></svg>';
};
?>

<!-- Toolbar -->
<div class="neb-toolbar">
  <div class="neb-actions">
    <button type="button" class="btn btn-secondary btn-sm fl-filter-toggle" id="fl-filter-toggle"
            aria-expanded="<?= $filtersActive ? 'true' : 'false' ?>"
            aria-controls="fl-filter-panel">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
      Filter<?php if ($filtersActive): ?><span class="fl-filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/listings/add" class="btn btn-primary btn-sm">+ Add Listing</a>
    <a href="<?= $b ?>/admin/listings/settings" class="btn btn-secondary btn-sm">Display Settings</a>
  </div>
</div>

<!-- Collapsible filter panel -->
<div class="fl-filter-panel<?= $filtersActive ? ' is-open' : '' ?>" id="fl-filter-panel">
  <form method="GET" action="" class="neb-filter-row" id="fl-filter-form">

    <div class="toolbar-select-wrap">
    <select id="fl-net-sel" name="network" class="neb-select">
      <option value="">All Networks</option>
      <?php foreach ($allNetworks as $net): ?>
        <option value="<?= \Luster\Core\View::e($net) ?>"
                <?= ($filter_network ?? '') === $net ? 'selected' : '' ?>>
          <?= \Luster\Core\View::e($net) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="toolbar-select-wrap">
    <select id="fl-cat-sel" name="category" class="neb-select"
            data-tfl='<?= $dataTfl ?>'
            data-tafl='<?= $dataTafl ?>'
            data-custom='<?= $dataCustom ?>'>
      <option value="">All Categories</option>
      <?php foreach ($activeCats as $c): ?>
        <option value="<?= \Luster\Core\View::e($c['name']) ?>"
                <?= ($filter_cat ?? '') === $c['name'] ? 'selected' : '' ?>>
          <?= \Luster\Core\View::e($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="toolbar-select-wrap">
    <select name="type" class="neb-select js-autosubmit">
      <option value="">All Types</option>
      <?php foreach ($type_options ?? [] as $to): ?>
        <option value="<?= \Luster\Core\View::e($to) ?>"
                <?= ($filter_type ?? '') === $to ? 'selected' : '' ?>>
          <?= \Luster\Core\View::e(ucfirst($to)) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="neb-input"
           placeholder="Search&hellip;" value="<?= \Luster\Core\View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="?" class="btn btn-secondary btn-sm">Reset</a>

  </form>
</div>


<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<?php if (empty($listings)): ?>
  <div class="neb-card">
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>
      No listings yet.
      <a href="<?= $b ?>/admin/listings/add" class="btn btn-primary btn-sm">+ Add Listing</a>
    </div>
  </div>
<?php else: ?>

<form method="POST" action="<?= $b ?>/admin/listings/bulk" id="bulk-form">
  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

  <!-- Bulk action bar -->
  <div class="neb-bulk">
    <label for="bulk-action">Bulk action:</label>
    <div class="toolbar-select-wrap">
    <select id="bulk-action" name="action" class="neb-select">
      <option value="" disabled selected>— Select —</option>
      <option value="delete">Delete</option>
    </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
  </div>

  <?php $flFrom = ($page - 1) * $per_page + 1; $flTo = ($page - 1) * $per_page + count($listings); ?>
  <p class="result-count">Showing <?= $flFrom ?>&#8211;<?= $flTo ?> of <?= number_format((int) $total) ?> listings<?php if ($total_pages > 1): ?> &mdash; page <?= (int) $page ?> of <?= (int) $total_pages ?><?php endif; ?></p>

  <div class="neb-card">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="fl-select-all"></th>
          <th><a href="<?= \Luster\Core\View::e($flSortUrl('id')) ?>" class="sort-th<?= $flSort === 'id' ? ' sort-active' : '' ?>"># <?= $flSortIcon('id') ?></a></th>
          <th><a href="<?= \Luster\Core\View::e($flSortUrl('subject')) ?>" class="sort-th<?= $flSort === 'subject' ? ' sort-active' : '' ?>">Subject <?= $flSortIcon('subject') ?></a></th>
          <th><a href="<?= \Luster\Core\View::e($flSortUrl('name')) ?>" class="sort-th<?= $flSort === 'name' ? ' sort-active' : '' ?>">Title <?= $flSortIcon('name') ?></a></th>
          <th><a href="<?= \Luster\Core\View::e($flSortUrl('category')) ?>" class="sort-th<?= $flSort === 'category' ? ' sort-active' : '' ?>">Category <?= $flSortIcon('category') ?></a></th>
          <th><a href="<?= \Luster\Core\View::e($flSortUrl('status')) ?>" class="sort-th<?= $flSort === 'status' ? ' sort-active' : '' ?>">Status <?= $flSortIcon('status') ?></a></th>
          <th>Members</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($listings as $fl): ?>
        <tr>

          <!-- Checkbox -->
          <td class="cb-col" data-label="">
            <input type="checkbox" name="selected[]" value="<?= (int) $fl->id ?>" class="fl-row-cb">
          </td>

          <!-- Listing # -->
          <td class="text-dim" data-label="ID"><?= (int) $fl->id ?></td>

          <!-- Subject -->
          <td class="text-strong" data-label="Subject">
            <?php if ($fl->url !== ''): ?>
              <a href="<?= \Luster\Core\View::e($fl->url) ?>" target="_blank" rel="noopener noreferrer"
                 class="neb-link"><?= \Luster\Core\View::e($fl->subject) ?></a>
            <?php else: ?>
              <?= \Luster\Core\View::e($fl->subject) ?>
            <?php endif; ?>
          </td>

          <!-- Title -->
          <td data-label="Title">
            <a href="<?= $b ?>/admin/listings/<?= (int) $fl->id ?>/edit"
               class="neb-link"><?= \Luster\Core\View::e($fl->name) ?></a>
          </td>

          <!-- Category -->
          <td class="text-muted" data-label="Category"><?= \Luster\Core\View::e($category_names[$fl->id] ?? '—') ?></td>

          <!-- Status -->
          <td data-label="Status">
            <?php if ($fl->upcoming): ?>
              <span class="neb-badge badge-upcoming">Upcoming</span>
            <?php elseif ($fl->trouble): ?>
              <span class="neb-badge badge-pending">Pending</span>
            <?php else: ?>
              <span class="neb-badge badge-current">Current</span>
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
                   class="nav-badge" style="text-decoration:none">(+ <?= $pend ?>)</a>
            </div>
            <?php endif; ?>
          </td>

          <!-- Actions -->
          <td class="act-col" data-label="">
            <div class="row-actions">
              <a href="<?= $b ?>/admin/listings/<?= (int) $fl->id ?>/edit"
                 class="btn btn-primary btn-sm">Edit</a>
              <a href="<?= $b ?>/admin/members/<?= (int) $fl->id ?>"
                 class="btn btn-secondary btn-sm">Members</a>
              <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $fl->id ?>/delete" style="display:contents"
                    data-confirm="Delete this listing and all its members? This cannot be undone.">
                <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </div>
          </td>

        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  </div><!-- /.neb-card -->

</form>

<?php if (($total_pages ?? 1) > 1): ?>
<nav class="neb-pagination" aria-label="Pagination">
  <a href="<?= \Luster\Core\View::e($flPageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
     <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>&lsaquo; Prev</a>

  <?php
    $window = 2;
    $start  = max(1, $page - $window);
    $end    = min($total_pages, $page + $window);
    if ($start > 1): ?>
      <a href="<?= \Luster\Core\View::e($flPageUrl(1)) ?>" class="pg-link">1</a>
      <?php if ($start > 2): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="<?= \Luster\Core\View::e($flPageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"
       <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $total_pages): ?>
    <?php if ($end < $total_pages - 1): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
    <a href="<?= \Luster\Core\View::e($flPageUrl($total_pages)) ?>" class="pg-link"><?= $total_pages ?></a>
  <?php endif; ?>

  <a href="<?= \Luster\Core\View::e($flPageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $total_pages ? ' disabled' : '' ?>"
     <?= $page >= $total_pages ? 'aria-disabled="true"' : '' ?>>Next &rsaquo;</a>
</nav>
<?php endif; ?>


<?php endif; ?>
