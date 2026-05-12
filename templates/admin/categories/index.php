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

$filtersActive = ($source ?? '') !== '' || ($search ?? '') !== '';

$catFilterQs = http_build_query(array_filter([
    'source' => $source ?? '',
    'search' => $search ?? '',
    'sort'   => $sort !== 'source' ? $sort : '',
    'dir'    => $dir  !== 'asc'   ? $dir  : '',
], static fn(string $v): bool => $v !== ''));
$catPageUrl = static function (int $p) use ($b, $catFilterQs): string {
    $qs = $catFilterQs !== '' ? $catFilterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/categories?' . $qs;
};

$catSortUrl = static function (string $col) use ($b, $sort, $dir, $source, $search): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'source' => $source ?? '',
        'search' => $search ?? '',
        'sort'   => $col,
        'dir'    => $newDir,
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
<?php
$sh      = $seed_health ?? ['tfl_expected' => 0, 'tfl_present' => 0, 'tafl_expected' => 0, 'tafl_present' => 0];
$tflOk   = $sh['tfl_present']  >= $sh['tfl_expected'];
$taflOk  = $sh['tafl_present'] >= $sh['tafl_expected'];
?>
<div class="gradient-card" style="margin-bottom:var(--sp-4)">
  <div class="listing-field-section">
    <div class="listing-field-row listing-field-row--top">
      <span class="listing-field-label">TFL categories</span>
      <div>
        <span class="badge <?= $tflOk ? 'badge-current' : 'badge-pending' ?>">
          <?= (int) $sh['tfl_present'] ?>&thinsp;/&thinsp;<?= (int) $sh['tfl_expected'] ?>
          <?php if (!$tflOk): ?>&mdash; <?= (int) ($sh['tfl_expected'] - $sh['tfl_present']) ?> missing<?php endif; ?>
        </span>
        <p class="form-hint">The Fanlistings Network &mdash; <?= (int) $sh['tfl_expected'] ?> official categories.</p>
      </div>
    </div>
    <div class="listing-field-row">
      <span class="listing-field-label">TAFL categories</span>
      <div>
        <span class="badge <?= $taflOk ? 'badge-current' : 'badge-pending' ?>">
          <?= (int) $sh['tafl_present'] ?>&thinsp;/&thinsp;<?= (int) $sh['tafl_expected'] ?>
          <?php if (!$taflOk): ?>&mdash; <?= (int) ($sh['tafl_expected'] - $sh['tafl_present']) ?> missing<?php endif; ?>
        </span>
        <p class="form-hint">The Anime Fanlistings Network &mdash; <?= (int) $sh['tafl_expected'] ?> official categories.</p>
      </div>
    </div>
  </div>
  <div class="composer-footer">
    <form method="POST" action="<?= $b ?>/admin/categories/seed" style="display:flex;gap:var(--sp-2)">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <button type="submit" name="include_tafl" value="0" class="btn btn-secondary">Seed TFL</button>
      <button type="submit" name="include_tafl" value="1" class="btn btn-primary">Seed TFL + TAFL</button>
    </form>
  </div>
</div>

<!-- ──── TOOLBAR ────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="fl-filter-toggle"
            aria-expanded="<?= $filtersActive ? 'true' : 'false' ?>"
            aria-controls="filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/categories/add" class="btn btn-primary btn-sm">+ Add New Category</a>
  </div>
</div>
<!-- ──── COLLAPSIBLE FILTER PANEL ───────────────────────────────────── -->
<div class="filter-panel<?= $filtersActive ? ' is-open' : '' ?>" id="filter-panel">
  <form method="GET" action="<?= $b ?>/admin/categories" class="filter-row">
    <?php if ($sort !== 'source'): ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>"><?php endif; ?>
    <?php if ($dir !== 'asc'):    ?><input type="hidden" name="dir"  value="<?= View::e($dir) ?>"><?php endif; ?>

    <div class="select-wrap">
    <select name="source" class="js-autosubmit">
      <option value="">All Networks</option>
      <option value="tfl"  <?= ($source ?? '') === 'tfl'  ? 'selected' : '' ?>>TFL</option>
      <option value="tafl" <?= ($source ?? '') === 'tafl' ? 'selected' : '' ?>>TAFL</option>
      <?php foreach ($custom_sources ?? [] as $cs): ?>
        <option value="<?= View::e($cs) ?>"
                <?= ($source ?? '') === $cs ? 'selected' : '' ?>>
          <?= View::e($cs) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="form" placeholder="Search&hellip;"
           value="<?= View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-quaternary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/categories" class="btn btn-quaternary btn-sm">Reset</a>

  </form>
</div>

<?php if (empty($items ?? [])): ?>
<div class="card">
  <div class="empty">
    <?php if (($search ?? '') !== '' || ($source ?? '') !== ''): ?>
      <div class="empty-icon"><?= View::icon('tag', ['width' => 32, 'height' => 32]) ?></div>
      No categories match your search.
      <a href="<?= $b ?>/admin/categories" class="btn btn-quaternary btn-sm">Reset</a>
    <?php else: ?>
      <div class="empty-icon"><?= View::icon('tag', ['width' => 32, 'height' => 32]) ?></div>
      No categories yet. Add your first to start organising fanlistings.
      <a href="<?= $b ?>/admin/categories/add" class="btn btn-primary btn-sm">+ Add New Category</a>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<p class="result-count">
  Showing <?= count($items ?? []) ?> of <?= (int) ($total ?? 0) ?> categor<?= (int) ($total ?? 0) === 1 ? 'y' : 'ies' ?>
  <?php if (($source ?? '') !== ''): ?>
    in <strong><?= View::e(strtoupper($source ?? '')) ?></strong>
  <?php endif; ?>
</p>

<div class="card">
  <div class="table-wrap">
  <table class="data-table mem-table">
    <thead>
      <tr>
        <th><a href="<?= View::e($catSortUrl('id')) ?>" class="sort-th<?= $sort === 'id' ? ' sort-active' : '' ?>">ID <?= View::sortIcon('id', $sort, $dir) ?></a></th>
        <th><a href="<?= View::e($catSortUrl('source')) ?>" class="sort-th<?= $sort === 'source' ? ' sort-active' : '' ?>">Network <?= View::sortIcon('source', $sort, $dir) ?></a></th>
        <th><a href="<?= View::e($catSortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Category Name <?= View::sortIcon('name', $sort, $dir) ?></a></th>
        <th>Parent</th>
        <th class="act-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      // Build a quick idâ€ â€™name lookup for parent display
      $catIndex = [];
      foreach ($all_cats ?? [] as $_ac) { $catIndex[$_ac->id] = $_ac->name; }
      ?>
      <?php foreach ($items ?? [] as $cat): ?>
      <tr>
        <td class="text-dim" data-label="ID"><?= (int) $cat->id ?></td>
        <td data-label="Network"><?= $sourceBadge($cat->source ?? 'custom') ?></td>
        <td data-label="Name">
          <?php if ($cat->parentId !== null): ?>
            <span class="tree-prefix">&#x2514;&#x2514;</span>
          <?php endif; ?>
          <?= View::e($cat->name ?? '') ?>
        </td>
        <td class="text-dim text-dim-sm" data-label="Parent">
          <?php if ($cat->parentId !== null && isset($catIndex[$cat->parentId])): ?>
            <?= View::e($catIndex[$cat->parentId]) ?>
          <?php else: ?>
            -
          <?php endif; ?>
        </td>
        <td class="act-col" data-label="">
          <a href="<?= $b ?>/admin/categories/<?= (int) $cat->id ?>/edit"
             class="btn btn-tertiary btn-sm">Edit</a>
          <?php if (!in_array($cat->source ?? '', ['tfl', 'tafl'], true)): ?>
            <form method="POST" action="<?= $b ?>/admin/categories/<?= (int) $cat->id ?>/delete"
                  class="action-form-inline"
                  data-del-modal data-del-entity="category"
                  data-del-name="<?= View::e($cat->name ?? '') ?>">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php $pg_current = $page; $pg_total = $total_pages; $pg_url = $catPageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
