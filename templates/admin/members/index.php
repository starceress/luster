<?php
/** @var \Luster\Models\Listing       $listing */
/** @var \Luster\Models\Member[]         $members */
/** @var \Luster\Models\AdditionalField[] $fields */
/** @var array    $all_listings */
/** @var int      $total */
/** @var int      $page */
/** @var int      $pages */
/** @var string   $search */
/** @var string   $status */
/** @var string   $success */
/** @var string   $error */
/** @var int      $per_page */
/** @var int      $pending_count */
/** @var string   $base_url */
/** @var string   $csrf_token */
$b    = rtrim(\Luster\Core\View::e($base_url), '/');
$id   = (int) $listing->id;
$sort = $sort ?? 'name';
$dir  = $dir  ?? 'asc';

$memSortUrl = static function (string $col) use ($b, $id, $sort, $dir, $search, $status): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'search' => $search ?? '',
        'status' => $status ?? '',
        'sort'   => $col,
        'dir'    => $newDir,
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/members/' . $id . ($qs !== '' ? '?' . $qs : '');
};

$memSortIcon = static function (string $col) use ($sort, $dir): string {
    if ($sort !== $col) {
        return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="opacity:.35;flex-shrink:0"><path d="M5 0l3 4H2zM5 14l-3-4h6z"/></svg>';
    }
    if ($dir === 'asc') {
        return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="flex-shrink:0"><path d="M5 0l3 4H2z"/><path d="M5 14l-3-4h6z" fill-opacity=".25"/></svg>';
    }
    return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="flex-shrink:0"><path d="M5 0l3 4H2z" fill-opacity=".25"/><path d="M5 14l-3-4h6z"/></svg>';
};
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<!-- Toolbar -->
<div class="neb-toolbar">
  <form method="GET" action="<?= $b ?>/admin/members/<?= $id ?>" class="neb-filter-row" id="mem-filter-form">

    <div class="toolbar-select-wrap">
    <select class="neb-select js-nav-select">
      <option value="<?= $b ?>/admin/members"<?= !isset($listing) ? ' selected' : '' ?>>All pending members</option>
      <?php foreach ($all_listings as $fl): ?>
        <option value="<?= $b ?>/admin/members/<?= (int) $fl->id ?>?status=all"
                <?= (int) $fl->id === (int) ($listing->id ?? 0) ? 'selected' : '' ?>>
          <?= \Luster\Core\View::e($fl->name) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="neb-input" placeholder="Search&hellip;"
           value="<?= \Luster\Core\View::e($search ?? '') ?>">

    <div class="toolbar-select-wrap">
    <select name="status" class="neb-select">
      <option value="all"      <?= $status === 'all'      ? 'selected' : '' ?>>All</option>
      <option value="pending"  <?= $status === 'pending'  ? 'selected' : '' ?>>Pending</option>
      <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
    </select>
    </div>

    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <a href="?" class="btn btn-secondary btn-sm">Reset</a>

  </form>

  <div class="neb-actions">
    <a href="<?= $b ?>/admin/members/<?= $id ?>/import" class="btn btn-secondary btn-sm">&#8593; Import CSV</a>
    <a href="<?= $b ?>/admin/members/<?= $id ?>/export" class="btn btn-secondary btn-sm">&#8595; Export CSV</a>
  </div>
</div>


<?php if (($pending_count ?? 0) > 0 && ($status ?? 'all') !== 'pending'): ?>
<div class="pending-banner">
  <div class="pending-banner-left">
    <span class="pending-banner-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="20" height="20"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </span>
    <div>
      <span class="pending-banner-title">Action required</span>
      <span class="pending-banner-msg"><?= (int) $pending_count ?> member<?= $pending_count !== 1 ? 's are' : ' is' ?> awaiting approval</span>
    </div>
  </div>
  <div style="display:flex;gap:.5rem;align-items:center">
    <a href="?status=pending" class="btn btn-ghost btn-sm">View Pending</a>
    <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/approve-all-pending"
          data-confirm="Approve all <?= (int) $pending_count ?> pending member(s)?">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-success btn-sm">Approve All &rarr;</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (empty($members)): ?>
  <div class="neb-card">
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <?php if (($search ?? '') !== '' || ($status ?? '') !== '' && $status !== 'all'): ?>
        No members match those filters.
        <a href="?" class="btn btn-secondary btn-sm">Clear Filters</a>
      <?php else: ?>
        No members yet — they'll appear here after the first join.
        <a href="<?= $b ?>/admin/members/<?= $id ?>/import" class="btn btn-secondary btn-sm">&#8593; Import CSV</a>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

<?php $from = ($page - 1) * $per_page + 1; $to = ($page - 1) * $per_page + count($members); ?>
<p class="result-count">
  Showing <?= $from ?>&#8211;<?= $to ?> of <?= number_format((int) $total) ?> members<?php if ($pages > 1): ?> &mdash; page <?= (int) $page ?> of <?= (int) $pages ?><?php endif; ?>
</p>

<form method="POST" id="bulk-form" action="<?= $b ?>/admin/members/<?= $id ?>/bulk">
  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

  <!-- Bulk action bar -->
  <div class="neb-bulk">
    <label for="bulk-action-sel">Bulk action:</label>
    <div class="toolbar-select-wrap">
    <select id="bulk-action-sel" name="bulk_action" class="neb-select">
      <option value="" disabled selected>— Select —</option>
      <option value="approve">Approve</option>
      <option value="pending">Pending</option>
      <option value="delete">Delete</option>
      <option value="email_selected">Email</option>
    </select>
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
  </div>

  <div class="neb-card">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="check-all"></th>
          <th><a href="<?= \Luster\Core\View::e($memSortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Name <?= $memSortIcon('name') ?></a></th>
          <th><a href="<?= \Luster\Core\View::e($memSortUrl('email')) ?>" class="sort-th<?= $sort === 'email' ? ' sort-active' : '' ?>">Email <?= $memSortIcon('email') ?></a></th>
          <th><a href="<?= \Luster\Core\View::e($memSortUrl('country')) ?>" class="sort-th<?= $sort === 'country' ? ' sort-active' : '' ?>">Country <?= $memSortIcon('country') ?></a></th>
          <th>URL</th>
          <?php foreach ($fields as $field): ?>
            <th><?= \Luster\Core\View::e($field->label) ?></th>
          <?php endforeach; ?>
          <th><a href="<?= \Luster\Core\View::e($memSortUrl('status')) ?>" class="sort-th<?= $sort === 'status' ? ' sort-active' : '' ?>">Status <?= $memSortIcon('status') ?></a></th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): ?>
        <tr>
          <td class="cb-col" data-label="">
            <input type="checkbox" name="emails[]" value="<?= \Luster\Core\View::e($m->email) ?>">
          </td>
          <td class="text-strong" data-label="Name"><?= \Luster\Core\View::e($m->name) ?></td>
          <td data-label="Email"><?= \Luster\Core\View::e($m->email) ?></td>
          <td class="text-muted" data-label="Country"><?= \Luster\Core\View::e($m->country) ?></td>
          <td data-label="URL">
            <?php if ($m->url): ?>
              <a href="<?= \Luster\Core\View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                 class="neb-link text-dim"><?= \Luster\Core\View::e($m->url) ?></a>
            <?php else: ?>
              <span class="text-dim">—</span>
            <?php endif; ?>
          </td>
          <?php foreach ($fields as $field): ?>
            <td class="text-muted" data-label="<?= \Luster\Core\View::e($field->label) ?>"><?= \Luster\Core\View::e($m->additionalFields[$field->name] ?? '') ?></td>
          <?php endforeach; ?>
          <td data-label="Status">
            <span class="neb-badge <?= $m->approved ? 'badge-current' : 'badge-pending' ?>">
              <?= $m->approved ? 'Approved' : 'Pending' ?>
            </span>
          </td>
          <td class="act-col" data-label="">
            <div class="row-actions">
              <?php if (!$m->approved): ?>
                <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/approve" style="display:contents">
                  <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
                  <input type="hidden" name="_page"        value="<?= (int) $page ?>">
                  <button type="submit" class="btn btn-success btn-sm">Approve</button>
                </form>
              <?php endif; ?>
              <a href="<?= $b ?>/admin/members/<?= $id ?>/edit?email=<?= urlencode($m->email) ?>"
                 class="btn btn-secondary btn-sm">Edit</a>
              <a href="<?= $b ?>/admin/members/<?= $id ?>/emailmember?email=<?= urlencode($m->email) ?>"
                 class="btn btn-secondary btn-sm">Email</a>
              <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/delete" style="display:contents"
                    data-confirm="Delete this member permanently?">
                <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
                <input type="hidden" name="_page"        value="<?= (int) $page ?>">
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

<!-- Pagination -->
<?php
$memFilterQs = http_build_query(array_filter([
    'search' => $search ?? '',
    'status' => $status ?? '',
], static fn(string $v): bool => $v !== ''));
$memPageUrl = static function (int $p) use ($b, $id, $memFilterQs): string {
    $qs = $memFilterQs !== '' ? $memFilterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/members/' . $id . '?' . $qs;
};
?>
<?php if ($pages > 1): ?>
<nav class="neb-pagination" aria-label="Pagination">
  <a href="<?= \Luster\Core\View::e($memPageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
     <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>&lsaquo; Prev</a>

  <?php
    $window = 2;
    $start  = max(1, $page - $window);
    $end    = min($pages, $page + $window);
    if ($start > 1): ?>
      <a href="<?= \Luster\Core\View::e($memPageUrl(1)) ?>" class="pg-link">1</a>
      <?php if ($start > 2): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="<?= \Luster\Core\View::e($memPageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"
       <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $pages): ?>
    <?php if ($end < $pages - 1): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
    <a href="<?= \Luster\Core\View::e($memPageUrl($pages)) ?>" class="pg-link"><?= $pages ?></a>
  <?php endif; ?>

  <a href="<?= \Luster\Core\View::e($memPageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $pages ? ' disabled' : '' ?>"
     <?= $page >= $pages ? 'aria-disabled="true"' : '' ?>>Next &rsaquo;</a>
</nav>
<?php endif; ?>

<?php endif; ?>

