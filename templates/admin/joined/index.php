<?php
/** @var \Luster\Models\JoinedFanlisting[] $joined */
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
$b = rtrim(\Luster\Core\View::e($base_url), '/');

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

$displayMode = $display_mode ?? 'all';
if ($displayMode === 'all') { usort($joined, fn($a, $b) => $a->id <=> $b->id); }
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
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<!-- Toolbar -->
<div class="neb-toolbar">
  <form method="GET" action="<?= $b ?>/admin/joined" class="neb-filter-row">

    <div class="toolbar-select-wrap">
    <select id="net-sel" name="network" class="neb-select">
      <option value="">All Networks</option>
      <option value="TFL"<?= ($filter_network ?? '') === 'TFL'  ? ' selected' : '' ?>>TFL</option>
      <option value="TAFL"<?= ($filter_network ?? '') === 'TAFL' ? ' selected' : '' ?>>TAFL</option>
      <?php foreach ($custom_networks ?? [] as $net): ?>
        <option value="<?= \Luster\Core\View::e($net) ?>"
                <?= ($filter_network ?? '') === $net ? ' selected' : '' ?>>
          <?= \Luster\Core\View::e($net) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="toolbar-select-wrap">
    <select id="cat-sel" name="category" class="neb-select">
      <option value="">All Categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= \Luster\Core\View::e($cat) ?>"
                <?= ($filter_cat ?? '') === $cat ? ' selected' : '' ?>>
          <?= \Luster\Core\View::e($cat) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="neb-input" placeholder="Search&hellip;"
           value="<?= \Luster\Core\View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
    <a href="?" class="btn btn-secondary btn-sm">Reset</a>

  </form>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/joined/settings" class="btn btn-secondary btn-sm">Settings</a>
    <a href="<?= $b ?>/admin/joined/add" class="btn btn-primary btn-sm">+ Add Joined FL</a>
  </div>
</div>

<?php if (empty($joined)): ?>
  <div class="neb-card" style="padding:24px;color:var(--text-muted);font-size:.875rem">
    No joined fanlistings found<?= ($search ?? '') !== '' || ($filter_network ?? '') !== '' ? ' matching your filters' : '' ?>.
  </div>
<?php else: ?>

<form method="POST" action="<?= $b ?>/admin/joined/bulk" id="bulk-form">
  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

  <?php $loop = 0; $firstGroup = true; foreach ($grouped as $catName => $items): ?>
  <div class="neb-card" style="margin-bottom:1.25rem">
    <div class="neb-card-header"><?= \Luster\Core\View::e($catName) ?> (<?= count($items) ?>)</div>
    <table class="data-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" <?= $firstGroup ? 'id="sel-all"' : '' ?> title="Select all"><?php $firstGroup = false; ?></th>
          <th style="width:70px">Image</th>
          <th style="width:40px">#</th>
          <th>Subject / URL</th>
          <th>Category</th>
          <th>Date Joined</th>
          <th>Status</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $jf): $loop++; ?>
        <tr>
          <td class="cb-col"><input type="checkbox" name="ids[]" value="<?= (int) $jf->id ?>"></td>
          <td>
            <?php if ($jf->image !== null && $jf->image !== ''): ?>
              <img src="<?= $b ?>/uploads/joined/<?= \Luster\Core\View::e($jf->image) ?>"
                   alt="" class="fl-image" style="max-height:50px">
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="text-dim"><?= $loop ?></td>
          <td>
            <a href="<?= \Luster\Core\View::e($jf->url) ?>" target="_blank" rel="noopener noreferrer"
               class="neb-link"><?= \Luster\Core\View::e($jf->name) ?></a>
          </td>
          <td class="text-muted">
            <?php
              $cats = ($jf->categoriesJson !== null && $jf->categoriesJson !== '')
                ? json_decode($jf->categoriesJson, true)
                : [];
              if (!empty($cats) && is_array($cats)):
                $parts = [];
                foreach ($cats as $c) {
                    if (isset($c['network'], $c['name']) && $c['network'] !== '' && $c['name'] !== '') {
                        $parts[] = \Luster\Core\View::e($c['network']) . ': ' . \Luster\Core\View::e($c['name']);
                    }
                }
                echo $parts !== [] ? implode(', ', $parts) : \Luster\Core\View::e($jf->category);
              elseif ($jf->category !== ''):
                echo \Luster\Core\View::e($jf->category);
              else:
            ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="text-muted">
            <?= $jf->dateJoined !== null ? \Luster\Core\View::e($jf->dateJoined) : '<span class="text-dim">&mdash;</span>' ?>
          </td>
          <td>
            <?php $statusVal = $jf->status ?? 'joined'; ?>
            <span class="neb-badge <?= $statusVal === 'pending' ? 'badge-pending' : 'badge-current' ?>">
              <?= $statusVal === 'pending' ? 'Pending' : 'Joined' ?>
            </span>
          </td>
          <td class="act-col">
            <a href="<?= $b ?>/admin/joined/<?= (int) $jf->id ?>/edit"
               class="btn btn-primary btn-sm">Edit</a>
            <form method="POST"
                  action="<?= $b ?>/admin/joined/<?= (int) $jf->id ?>/delete"
                  style="display:inline"
                  onsubmit="return confirm('Delete this joined fanlisting?')">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>

  <!-- Bulk action bar -->
  <div class="neb-bulk">
    <label for="bulk-action-sel">Bulk action:</label>
    <div class="toolbar-select-wrap">
    <select id="bulk-action-sel" name="bulk_action" class="neb-select">
      <option value="approve">Approve</option>
      <option value="pending">Set Pending</option>
      <option value="delete">Delete</option>
    </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm"
            onclick="return confirmBulk()">Apply to selected</button>
  </div>

</form>
<?php endif; ?>

<script>
(function () {
  var selAll = document.getElementById('sel-all');
  if (selAll) {
    selAll.addEventListener('change', function () {
      document.querySelectorAll('#bulk-form input[name="ids[]"]').forEach(function (cb) {
        cb.checked = selAll.checked;
      });
    });
    document.querySelectorAll('#bulk-form input[name="ids[]"]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var all  = document.querySelectorAll('#bulk-form input[name="ids[]"]');
        var checked = document.querySelectorAll('#bulk-form input[name="ids[]"]:checked');
        selAll.checked = all.length === checked.length;
        selAll.indeterminate = checked.length > 0 && checked.length < all.length;
      });
    });
  }

  window.confirmBulk = function () {
    var action = document.querySelector('#bulk-form select[name="bulk_action"]');
    var checked = document.querySelectorAll('#bulk-form input[name="ids[]"]:checked');
    if (checked.length === 0) {
      alert('No items selected.');
      return false;
    }
    if (action && action.value === 'delete') {
      return confirm('Delete ' + checked.length + ' selected item(s)? This cannot be undone.');
    }
    return true;
  };
}());
</script>

<script>
(function () {
  var netCats = <?= json_encode($network_categories ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  document.getElementById('net-sel').addEventListener('change', function () {
    var net = this.value, catSel = document.getElementById('cat-sel');
    catSel.innerHTML = '<option value="">All Categories</option>';
    if (net && netCats[net]) {
      netCats[net].forEach(function (c) {
        var o = document.createElement('option');
        o.value = c;
        o.textContent = c;
        catSel.appendChild(o);
      });
    }
    this.form.submit();
  });
}());
</script>

<?php if (($total_pages ?? 1) > 1): ?>
<nav class="neb-pagination" aria-label="Pagination">
  <a href="<?= \Luster\Core\View::e($pageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
     <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>&lsaquo; Prev</a>

  <?php for ($i = 1; $i <= $total_pages; $i++): ?>
    <a href="<?= \Luster\Core\View::e($pageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"
       <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>

  <a href="<?= \Luster\Core\View::e($pageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $total_pages ? ' disabled' : '' ?>"
     <?= $page >= $total_pages ? 'aria-disabled="true"' : '' ?>>Next &rsaquo;</a>
</nav>
<?php endif; ?>
