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
    <select name="fanlisting_id" class="neb-select js-autosubmit">
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
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="?" class="btn btn-secondary btn-sm">Reset</a>

  </form>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm">+ Add Affiliate</a>
  </div>
</div>

<?php if (empty($affiliates)): ?>
  <div class="neb-card">
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
      No affiliates yet.
      <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm">+ Add Affiliate</a>
    </div>
  </div>
<?php else: ?>

<div class="neb-card">
  <table class="data-table mem-table">
    <thead>
      <tr>
        <th style="width:72px">Image</th>
        <th style="width:36px">#</th>
        <th>Name</th>
        <th>Scope</th>
        <th>Email</th>
        <th>Status</th>
        <th class="act-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php $loop = 0; foreach ($affiliates as $aff): $loop++; ?>
      <?php
        // Resolve scope label
        $scopeLabel = ($aff->listingId !== null && isset($fan_map[$aff->listingId]))
            ? \Luster\Core\View::e($fan_map[$aff->listingId])
            : \Luster\Core\View::e($site_name ?? 'Collective');
      ?>
      <tr>
        <td data-label="Image">
          <?php if ($aff->image !== null && $aff->image !== ''): ?>
            <?php if ($aff->url !== ''): ?>
              <a href="<?= \Luster\Core\View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer">
                <img src="<?= $b ?>/uploads/affiliates/<?= \Luster\Core\View::e($aff->image) ?>"
                     alt="" class="fl-image" style="max-height:50px;display:block">
              </a>
            <?php else: ?>
              <img src="<?= $b ?>/uploads/affiliates/<?= \Luster\Core\View::e($aff->image) ?>"
                   alt="" class="fl-image" style="max-height:50px;display:block">
            <?php endif; ?>
          <?php else: ?>
            <span class="text-dim">&mdash;</span>
          <?php endif; ?>
        </td>
        <td class="text-dim" data-label=""><?= $loop ?></td>
        <td data-label="Name">
          <?php if ($aff->url !== ''): ?>
            <a href="<?= \Luster\Core\View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
               class="neb-link text-strong" style="display:inline-flex;align-items:center;gap:.3rem">
              <?= \Luster\Core\View::e($aff->name) ?>
              <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5"
                   style="width:11px;height:11px;flex-shrink:0;opacity:.45">
                <path d="M5 2H2a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V7M8 1h3m0 0v3m0-3L5 7"/>
              </svg>
            </a>
          <?php else: ?>
            <span class="text-strong"><?= \Luster\Core\View::e($aff->name) ?></span>
          <?php endif; ?>
        </td>
        <td class="text-muted" data-label="Scope">
          <?php if ($aff->listingId !== null): ?>
            <span class="neb-badge badge-current" style="font-size:.75rem"><?= $scopeLabel ?></span>
          <?php else: ?>
            <span class="neb-badge" style="font-size:.75rem;background:var(--bg-subtle);color:var(--text-muted)">Collective</span>
          <?php endif; ?>
        </td>
        <td class="text-muted" data-label="Email">
          <?= ($aff->email !== null && $aff->email !== '') ? \Luster\Core\View::e($aff->email) : '<span class="text-dim">&mdash;</span>' ?>
        </td>
        <td data-label="Status">
          <?php if ($aff->active): ?>
            <span class="neb-badge badge-current">Active</span>
          <?php else: ?>
            <span class="neb-badge badge-inactive">Inactive</span>
          <?php endif; ?>
        </td>
        <td class="act-col" data-label="">
          <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit"
             class="btn btn-primary btn-sm">Edit</a>

          <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                style="display:contents">
            <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
            <button type="submit" class="btn btn-secondary btn-sm"><?= $aff->active ? 'Disable' : 'Enable' ?></button>
          </form>

          <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                style="display:contents"
                data-confirm="Delete this affiliate?">
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
