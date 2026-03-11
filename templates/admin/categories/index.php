<?php declare(strict_types=1);
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
$b = rtrim(\Luster\Core\View::e($base_url ?? ''), '/');

$sourceBadge = static function (string $s): string {
    return match ($s) {
        'tfl'   => '<span class="neb-badge badge-tfl">TFL</span>',
        'tafl'  => '<span class="neb-badge badge-tafl">TAFL</span>',
        default => '<span class="neb-badge badge-inactive">' . htmlspecialchars(ucfirst($s), ENT_QUOTES, 'UTF-8') . '</span>',
    };
};

$catFilterQs = http_build_query(array_filter([
    'source' => $source ?? '',
    'search' => $search ?? '',
], static fn(string $v): bool => $v !== ''));
$catPageUrl = static function (int $p) use ($b, $catFilterQs): string {
    $qs = $catFilterQs !== '' ? $catFilterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/categories?' . $qs;
};
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success ?? '') ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error ?? '') ?></div>
<?php endif; ?>

<!-- Toolbar -->
<div class="neb-toolbar">
  <form method="GET" action="<?= $b ?>/admin/categories" class="neb-filter-row">

    <div class="toolbar-select-wrap">
    <select name="source" class="neb-select" onchange="this.form.submit()">
      <option value="">All Networks</option>
      <option value="tfl"  <?= ($source ?? '') === 'tfl'  ? 'selected' : '' ?>>TFL</option>
      <option value="tafl" <?= ($source ?? '') === 'tafl' ? 'selected' : '' ?>>TAFL</option>
      <?php foreach ($custom_sources ?? [] as $cs): ?>
        <option value="<?= \Luster\Core\View::e($cs) ?>"
                <?= ($source ?? '') === $cs ? 'selected' : '' ?>>
          <?= \Luster\Core\View::e($cs) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="neb-input" placeholder="Search&hellip;"
           value="<?= \Luster\Core\View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/categories" class="btn btn-secondary btn-sm">Reset</a>

  </form>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/categories/add" class="btn btn-primary btn-sm">+ Add New Category</a>
  </div>
</div>

<p class="text-muted" style="font-size:.8rem;margin-bottom:.5rem">
  Showing <?= count($items ?? []) ?> of <?= (int) ($total ?? 0) ?> categor<?= (int) ($total ?? 0) === 1 ? 'y' : 'ies' ?>
  <?php if (($source ?? '') !== ''): ?>
    in <strong><?= \Luster\Core\View::e(strtoupper($source ?? '')) ?></strong>
  <?php endif; ?>
</p>

<div class="neb-card">
  <table class="data-table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Network</th>
        <th>Category Name</th>
        <th class="act-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items ?? [] as $cat): ?>
      <tr>
        <td class="text-dim"><?= (int) $cat->id ?></td>
        <td><?= $sourceBadge($cat->source ?? 'custom') ?></td>
        <td class="text-strong"><?= \Luster\Core\View::e($cat->name ?? '') ?></td>
        <td class="act-col">
          <a href="<?= $b ?>/admin/categories/<?= (int) $cat->id ?>/edit"
             class="btn btn-primary btn-sm">Edit</a>
          <?php if (!in_array($cat->source ?? '', ['tfl', 'tafl'], true)): ?>
            <form method="POST" action="<?= $b ?>/admin/categories/<?= (int) $cat->id ?>/delete"
                  style="display:inline"
                  onsubmit="return confirm('Delete this category?')">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (($total_pages ?? 1) > 1): ?>
<nav class="neb-pagination" aria-label="Pagination">
  <a href="<?= \Luster\Core\View::e($catPageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
     <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>&lsaquo; Prev</a>

  <?php
    $window = 2;
    $start  = max(1, $page - $window);
    $end    = min($total_pages, $page + $window);
    if ($start > 1): ?>
      <a href="<?= \Luster\Core\View::e($catPageUrl(1)) ?>" class="pg-link">1</a>
      <?php if ($start > 2): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="<?= \Luster\Core\View::e($catPageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"
       <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $total_pages): ?>
    <?php if ($end < $total_pages - 1): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
    <a href="<?= \Luster\Core\View::e($catPageUrl($total_pages)) ?>" class="pg-link"><?= $total_pages ?></a>
  <?php endif; ?>

  <a href="<?= \Luster\Core\View::e($catPageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $total_pages ? ' disabled' : '' ?>"
     <?= $page >= $total_pages ? 'aria-disabled="true"' : '' ?>>Next &rsaquo;</a>
</nav>
<?php endif; ?>
