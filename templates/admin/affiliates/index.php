<?php
/** @var \Luster\Models\Affiliate[]  $affiliates */
/** @var \Luster\Models\Listing[] $listings */
/** @var array<int,string>           $fan_map */
/** @var string                      $filter_fid */
/** @var string                      $search */
/** @var int                         $page */
/** @var int                         $per_page */
/** @var int                         $total_pages */
/** @var int                         $total */
/** @var string                      $site_name */
/** @var string                      $success */
/** @var string                      $error */
/** @var string                      $base_url */
/** @var string                      $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

// Pagination URL builder
$filterQs = http_build_query(array_filter([
    'fanlisting_id' => ($filter_fid ?? 'collective') !== 'collective' ? ($filter_fid ?? '') : '',
    'search'        => $search ?? '',
], static fn(string $v): bool => $v !== ''));
$pageUrl = static function (int $p) use ($b, $filterQs): string {
    $qs = $filterQs !== '' ? $filterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/affiliates?' . $qs;
};
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<!-- Toolbar -->
<div class="neb-toolbar">
  <form method="GET" action="<?= $b ?>/admin/affiliates" class="neb-filter-row">

    <div class="toolbar-select-wrap">
    <select name="fanlisting_id" class="neb-select" onchange="this.form.submit()">
      <option value="collective"<?= ($filter_fid ?? 'collective') === 'collective' ? ' selected' : '' ?>><?= \Luster\Core\View::e(($site_name ?? 'Collective') . ' (Collective) Affiliates') ?></option>
      <?php foreach ($listings as $fl): ?>
        <option value="<?= (int) $fl->id ?>"
                <?= ($filter_fid ?? '') === (string) $fl->id ? ' selected' : '' ?>>
          <?= \Luster\Core\View::e($fl->name) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="neb-input" placeholder="Search name, URL, email&hellip;"
           value="<?= \Luster\Core\View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-primary btn-sm">Search</button>
    <a href="?" class="btn btn-secondary btn-sm">Reset</a>

  </form>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm">+ Add Affiliate</a>
  </div>
</div>

<?php if (empty($affiliates)): ?>
  <div class="neb-card" style="padding:24px;color:var(--text-muted);font-size:.875rem">
    No affiliates found.
  </div>
<?php else: ?>

<div class="neb-card">
  <table class="data-table">
    <thead>
      <tr>
        <th style="width:80px">Image</th>
        <th style="width:40px">#</th>
        <th>Name</th>
        <th>URL</th>
        <th>Email</th>
        <th>Status</th>
        <th class="act-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php $loop = 0; foreach ($affiliates as $aff): $loop++; ?>
      <tr>
        <td>
          <?php if ($aff->image !== null && $aff->image !== ''): ?>
            <img src="<?= $b ?>/uploads/affiliates/<?= \Luster\Core\View::e($aff->image) ?>"
                 alt="" class="fl-image" style="max-height:50px">
          <?php else: ?>
            <span class="text-dim">&mdash;</span>
          <?php endif; ?>
        </td>
        <td class="text-dim"><?= $loop ?></td>
        <td class="text-strong"><?= \Luster\Core\View::e($aff->name) ?></td>
        <td>
          <?php if ($aff->url !== ''): ?>
            <a href="<?= \Luster\Core\View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
               class="neb-link text-dim" style="word-break:break-all"><?= \Luster\Core\View::e($aff->url) ?></a>
          <?php else: ?>
            <span class="text-dim">&mdash;</span>
          <?php endif; ?>
        </td>
        <td class="text-muted">
          <?= ($aff->email !== null && $aff->email !== '') ? \Luster\Core\View::e($aff->email) : '<span class="text-dim">&mdash;</span>' ?>
        </td>
        <td>
          <?php if ($aff->active): ?>
            <span class="neb-badge badge-current">Active</span>
          <?php else: ?>
            <span class="neb-badge badge-inactive">Inactive</span>
          <?php endif; ?>
        </td>
        <td class="act-col">
          <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit"
             class="btn btn-secondary btn-sm">Edit</a>

          <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                style="display:inline">
            <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
            <button type="submit" class="btn btn-secondary btn-sm"><?= $aff->active ? 'Disable' : 'Enable' ?></button>
          </form>

          <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                style="display:inline"
                onsubmit="return confirm('Delete this affiliate?')">
            <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

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
