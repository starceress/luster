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
/** @var string   $base_url */
/** @var string   $csrf_token */
$b  = rtrim(\Luster\Core\View::e($base_url), '/');
$id = (int) $listing->id;
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
    <a href="<?= $b ?>/admin/members/<?= $id ?>/import" class="btn btn-secondary btn-sm">&#8593; Import CSV</a>
    <a href="<?= $b ?>/admin/members/<?= $id ?>/export" class="btn btn-secondary btn-sm">&#8595; Export CSV</a>
  </div>
</div>

<?php if (empty($members)): ?>
  <div class="neb-card">
    <p class="neb-empty">No members found.</p>
  </div>
<?php else: ?>

<p class="result-count">
  Showing <?= count($members) ?> of <?= (int) $total ?> member(s) &mdash; page <?= (int) $page ?> of <?= (int) $pages ?>
</p>

<form method="POST" id="bulk-form" action="<?= $b ?>/admin/members/<?= $id ?>/bulk">
  <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

  <div class="neb-card">
    <table class="data-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="check-all"></th>
          <th>Name</th>
          <th>Email</th>
          <th>Country</th>
          <th>URL</th>
          <?php foreach ($fields as $field): ?>
            <th><?= \Luster\Core\View::e($field->label) ?></th>
          <?php endforeach; ?>
          <th>Status</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): ?>
        <tr>
          <td class="cb-col">
            <input type="checkbox" name="emails[]" value="<?= \Luster\Core\View::e($m->email) ?>">
          </td>
          <td class="text-strong"><?= \Luster\Core\View::e($m->name) ?></td>
          <td><?= \Luster\Core\View::e($m->email) ?></td>
          <td class="text-muted"><?= \Luster\Core\View::e($m->country) ?></td>
          <td>
            <?php if ($m->url): ?>
              <a href="<?= \Luster\Core\View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                 class="neb-link text-dim" style="word-break:break-all"><?= \Luster\Core\View::e($m->url) ?></a>
            <?php else: ?>
              <span class="text-dim">—</span>
            <?php endif; ?>
          </td>
          <?php foreach ($fields as $field): ?>
            <td class="text-muted"><?= \Luster\Core\View::e($m->additionalFields[$field->name] ?? '') ?></td>
          <?php endforeach; ?>
          <td>
            <span class="neb-badge <?= $m->approved ? 'badge-current' : 'badge-pending' ?>">
              <?= $m->approved ? 'Approved' : 'Pending' ?>
            </span>
          </td>
          <td class="act-col">
            <a href="<?= $b ?>/admin/members/<?= $id ?>/edit?email=<?= urlencode($m->email) ?>"
               class="btn btn-primary btn-sm">Edit</a>
            <a href="<?= $b ?>/admin/members/<?= $id ?>/emailmember?email=<?= urlencode($m->email) ?>"
               class="btn btn-secondary btn-sm">Email</a>

            <?php if (!$m->approved): ?>
            <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/approve" style="display:inline">
              <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
              <button type="submit" class="btn btn-primary btn-sm">Approve</button>
            </form>
            <?php endif; ?>

            <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/delete" style="display:inline"
                  data-confirm="Delete this member permanently?">
              <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

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
      <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
    </div>
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

