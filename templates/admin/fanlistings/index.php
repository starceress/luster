<?php
/** @var \Luster\Models\Fanlisting[] $fanlistings */
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
$b = rtrim(\Luster\Core\View::e($base_url), '/');

$flFilterQs = http_build_query(array_filter([
    'search'   => $search          ?? '',
    'network'  => $filter_network  ?? '',
    'category' => $filter_cat      ?? '',
    'type'     => $filter_type     ?? '',
], static fn(string $v): bool => $v !== ''));
$flPageUrl = static function (int $p) use ($b, $flFilterQs): string {
    $qs = $flFilterQs !== '' ? $flFilterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/fanlistings?' . $qs;
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

$allNetworks = array_merge(['TFL', 'TAFL'], $custom_networks ?? []);
usort($allNetworks, static fn($a, $b) => strcasecmp($a, $b));
?>

<!-- Toolbar -->
<div class="neb-toolbar">
  <form method="GET" action="" class="neb-filter-row" id="fl-filter-form">

    <div class="toolbar-select-wrap">
    <select id="fl-net-sel" name="network" class="neb-select"
            onchange="flNetChange(this.value);this.form.submit()">
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
    <select name="type" class="neb-select" onchange="this.form.submit()">
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
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
    <a href="?" class="btn btn-secondary btn-sm">Reset</a>

  </form>

  <div class="neb-actions">
    <a href="<?= $b ?>/admin/fanlistings/add" class="btn btn-primary btn-sm">+ Add Listing</a>
    <a href="<?= $b ?>/admin/fanlistings/settings" class="btn btn-secondary btn-sm">Settings</a>
  </div>
</div>

<script>
(function () {
  var catSel    = document.getElementById('fl-cat-sel');
  var tflCats   = JSON.parse(catSel.dataset.tfl);
  var taflCats  = JSON.parse(catSel.dataset.tafl);
  var customMap = JSON.parse(catSel.dataset.custom);

  function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  window.flNetChange = function (net) {
    var cats;
    if (net === 'TFL')       cats = tflCats;
    else if (net === 'TAFL') cats = taflCats;
    else if (net !== '')     cats = customMap[net] || [];
    else {
      cats = tflCats.concat(taflCats);
      Object.values(customMap).forEach(function (arr) { cats = cats.concat(arr); });
      cats.sort(function (a, b) { return a.name.localeCompare(b.name); });
    }
    var html = '<option value="">All Categories</option>';
    cats.forEach(function (c) { html += '<option value="' + esc(c.name) + '">' + esc(c.name) + '</option>'; });
    catSel.innerHTML = html;
    document.getElementById('fl-filter-form').submit();
  };
}());
</script>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<?php if (empty($fanlistings)): ?>
  <div class="neb-card" style="padding:24px;color:var(--text-muted);font-size:.875rem">
    No fanlistings found. <a href="<?= $b ?>/admin/fanlistings/add" class="neb-link">Add one to get started</a>.
  </div>
<?php else: ?>

<form method="POST" action="<?= $b ?>/admin/fanlistings/bulk" id="bulk-form">
  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

  <div class="neb-card">
    <table class="data-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="fl-select-all"></th>
          <th style="width:100px">Image</th>
          <th>#</th>
          <th>Subject</th>
          <th>Title</th>
          <th>Category</th>
          <th>Status</th>
          <th>Members</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fanlistings as $fl): ?>
        <tr>

          <!-- Checkbox -->
          <td class="cb-col">
            <input type="checkbox" name="selected[]" value="<?= (int) $fl->id ?>" class="fl-row-cb">
          </td>

          <!-- Image -->
          <td>
            <?php $flImage = $images[$fl->id] ?? null; ?>
            <?php if ($flImage !== null && $flImage !== ''): ?>
              <img src="<?= $b ?>/uploads/fanlistings/<?= \Luster\Core\View::e($flImage) ?>"
                   alt="" class="fl-image" style="max-height:<?= \Luster\Core\View::e($imgHeight) ?>">
            <?php else: ?>
              <div class="img-placeholder">No image</div>
            <?php endif; ?>
          </td>

          <!-- Listing # -->
          <td class="text-dim"><?= (int) $fl->id ?></td>

          <!-- Subject -->
          <td class="text-strong">
            <?php if ($fl->url !== ''): ?>
              <a href="<?= \Luster\Core\View::e($fl->url) ?>" target="_blank" rel="noopener noreferrer"
                 class="neb-link"><?= \Luster\Core\View::e($fl->subject) ?></a>
            <?php else: ?>
              <?= \Luster\Core\View::e($fl->subject) ?>
            <?php endif; ?>
          </td>

          <!-- Title -->
          <td>
            <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fl->id ?>/edit"
               class="neb-link"><?= \Luster\Core\View::e($fl->name) ?></a>
          </td>

          <!-- Category -->
          <td class="text-muted"><?= \Luster\Core\View::e($category_names[$fl->id] ?? '—') ?></td>

          <!-- Status -->
          <td>
            <?php if ($fl->upcoming): ?>
              <span class="neb-badge badge-upcoming">Upcoming</span>
            <?php elseif ($fl->trouble): ?>
              <span class="neb-badge badge-trouble">Trouble</span>
            <?php else: ?>
              <span class="neb-badge badge-current">Current</span>
            <?php endif; ?>
          </td>

          <!-- Members -->
          <td>
            <?php
              $app  = (int) ($member_counts[$fl->id]  ?? 0);
              $pend = (int) ($pending_counts[$fl->id] ?? 0);
            ?>
            <?= $app ?> Approved<?php if ($pend > 0): ?>,
              <a href="<?= $b ?>/admin/members/<?= (int) $fl->id ?>?status=pending"
                 class="neb-link"><?= $pend ?> Pending</a>
            <?php endif; ?>
          </td>

          <!-- Actions -->
          <td class="act-col">
            <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fl->id ?>/edit"
               class="btn btn-primary btn-sm">Edit</a>
            <a href="<?= $b ?>/admin/members/<?= (int) $fl->id ?>"
               class="btn btn-secondary btn-sm">Members</a>
            <form method="POST"
                  action="<?= $b ?>/admin/fanlistings/<?= (int) $fl->id ?>/delete"
                  style="display:inline"
                  onsubmit="return confirm('Delete this fanlisting and all its members? This cannot be undone.')">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>

        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Bulk action bar -->
    <div class="neb-bulk">
      <label for="bulk-action">Bulk action:</label>
      <div class="toolbar-select-wrap">
      <select id="bulk-action" name="action" class="neb-select">
        <option value="">— Select —</option>
        <option value="delete">Delete selected</option>
      </select>
      </div>
      <button type="submit" class="btn btn-secondary btn-sm"
              onclick="return document.querySelector('.fl-row-cb:checked') ? confirm('Apply bulk action to selected fanlistings?') : (alert('No fanlistings selected.'), false)">
        Apply
      </button>
    </div>
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

<script>
document.getElementById('fl-select-all').addEventListener('change', function () {
  document.querySelectorAll('.fl-row-cb').forEach(cb => cb.checked = this.checked);
});
</script>

<?php endif; ?>
