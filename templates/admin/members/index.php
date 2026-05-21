<?php
use Luster\Core\View;
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
$b    = rtrim(View::e($base_url), '/');
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
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TOOLBAR ────────────────────────────────────────────────────── -->
<div class="toolbar">
  <form method="GET" action="<?= $b ?>/admin/members/<?= $id ?>" id="mem-filter-form" class="filter-row member-toolbar-form">

    <div class="select-wrap">
    <select class="js-nav-select">
      <option value="<?= $b ?>/admin/members"<?= !isset($listing) ? ' selected' : '' ?>>All pending members</option>
      <?php foreach ($all_listings as $fl): ?>
        <option value="<?= $b ?>/admin/members/<?= (int) $fl->id ?>?status=all"
                <?= (int) $fl->id === (int) ($listing->id ?? 0) ? 'selected' : '' ?>>
          <?= View::e($fl->name) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form toolbar-input-sm" placeholder="Search&hellip;"
             value="<?= View::e($search ?? '') ?>">
    </div>

    <div class="select-wrap">
    <select name="status" >
      <option value="all"      <?= $status === 'all'      ? 'selected' : '' ?>>All</option>
      <option value="pending"  <?= $status === 'pending'  ? 'selected' : '' ?>>Pending</option>
      <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
    </select>
    </div>

    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <a href="?" class="btn btn-quaternary btn-sm">Reset</a>

  </form>

  <div class="actions-row">
    <a href="<?= $b ?>/admin/members/<?= $id ?>/import" class="btn btn-quaternary btn-sm">&#8595; Import CSV</a>
    <a href="<?= $b ?>/admin/members/<?= $id ?>/export" class="btn btn-quaternary btn-sm">&#8593; Export CSV</a>
  </div>
</div>

<?php if (($pending_count ?? 0) > 0 && ($status ?? 'all') !== 'pending'): ?>
<div class="reminder-banner">
  <div class="reminder-banner-head">
    <div class="reminder-banner-left">
      <span class="reminder-banner-icon"><?= View::icon('bell') ?></span>
      <div>
        <span class="reminder-banner-title">Action Required!</span>
        <span class="reminder-banner-msg"><?= (int) $pending_count ?> member<?= $pending_count !== 1 ? 's are' : ' is' ?> awaiting approval</span>
      </div>
    </div>
    <div class="inline-actions-row">
      <a href="?status=pending" class="btn btn-preview btn-sm">View Pending &rarr;</a>
      <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/approve-all-pending"
            data-confirm="Approve all <?= (int) $pending_count ?> pending member(s)?">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <button type="submit" class="btn btn-success btn-sm">Approve All &rarr;</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (empty($members)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('members', ['width' => 32, 'height' => 32]) ?></div>
      <?php if (($search ?? '') !== '' || ($status ?? '') !== '' && $status !== 'all'): ?>
        <div class="empty-title">No members match those filters.</div>
        <a href="?" class="btn btn-ghost btn-sm">Clear Filters</a>
      <?php else: ?>
        <div class="empty-title">No members yet.</div>
        <div>They'll appear here after the first fan joins.</div>
        <a href="<?= $b ?>/admin/members/<?= $id ?>/import" class="btn btn-primary btn-sm"><?= View::icon('import') ?> Import CSV</a>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

<?php $from = ($page - 1) * $per_page + 1; $to = ($page - 1) * $per_page + count($members); ?>
<p class="result-count">
  Showing <?= $from ?>&#8211;<?= $to ?> of <?= number_format((int) $total) ?> members<?php if ($pages > 1): ?> &mdash; page <?= (int) $page ?> of <?= (int) $pages ?><?php endif; ?>
</p>

<form method="POST" id="bulk-form" action="<?= $b ?>/admin/members/<?= $id ?>/bulk"
      data-del-bulk data-del-entity="member"
      data-del-context="<?= View::e($listing->name) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="bulk">
    <label for="bulk-action-sel">Bulk Actions:</label>
    <div class="select-wrap">
    <select id="bulk-action-sel" name="bulk_action" >
      <option value="" disabled selected>&mdash; Select &mdash;</option>
      <option value="approve">Approve</option>
      <option value="pending">Pending</option>
      <option value="delete">Delete</option>
      <option value="email_selected">Email</option>
    </select>
    </div>
    <button type="submit" class="btn btn-quaternary btn-sm">Apply</button>
  </div>

  <div class="card">
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="check-all"></th>
          <th><a href="<?= View::e($memSortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($memSortUrl('email')) ?>" class="sort-th<?= $sort === 'email' ? ' sort-active' : '' ?>">Email <?= View::sortIcon('email', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($memSortUrl('country')) ?>" class="sort-th<?= $sort === 'country' ? ' sort-active' : '' ?>">Country <?= View::sortIcon('country', $sort, $dir) ?></a></th>
          <th>URL</th>
          <?php foreach ($fields as $field): ?>
            <th><?= View::e($field->label) ?></th>
          <?php endforeach; ?>
          <th><a href="<?= View::e($memSortUrl('status')) ?>" class="sort-th<?= $sort === 'status' ? ' sort-active' : '' ?>">Status <?= View::sortIcon('status', $sort, $dir) ?></a></th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): ?>
        <tr>
          <td class="cb-col" data-label="">
            <input type="checkbox" name="emails[]" value="<?= View::e($m->email) ?>"
                   data-name="<?= View::e($m->name) ?>">
          </td>
          <td class="text-strong" data-label="Name"><?= View::e($m->name) ?></td>
          <td data-label="Email"><?= View::e($m->email) ?></td>
          <td class="text-muted" data-label="Country"><?= View::e($m->country) ?></td>
          <td data-label="URL">
            <?php if ($m->url): ?>
              <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                 class="link text-dim link-break"><?= View::e($m->url) ?></a>
            <?php else: ?>
              <span class="text-dim">-</span>
            <?php endif; ?>
          </td>
          <?php foreach ($fields as $field): ?>
            <td class="text-muted" data-label="<?= View::e($field->label) ?>"><?= View::e($m->additionalFields[$field->name] ?? '') ?></td>
          <?php endforeach; ?>
          <td data-label="Status">
            <span class="badge <?= $m->approved ? 'badge-current' : 'badge-pending' ?>">
              <?= $m->approved ? 'Approved' : 'Pending' ?>
            </span>
          </td>
          <td class="act-col" data-label="">
            <div class="data-actions">
              <?php if (!$m->approved): ?>
                <button type="submit" form="approve-member-<?= md5($m->email) ?>" class="btn btn-success btn-sm">Approve</button>
              <?php endif; ?>
              <a href="<?= $b ?>/admin/members/<?= $id ?>/edit?email=<?= urlencode($m->email) ?>"
                 class="btn btn-tertiary btn-sm">Edit</a>
              <a href="<?= $b ?>/admin/members/<?= $id ?>/emailmember?email=<?= urlencode($m->email) ?>"
                 class="btn btn-quaternary btn-sm">Email</a>
              <button type="submit" form="del-member-<?= md5($m->email) ?>" class="btn btn-danger btn-sm">Delete</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</form>

<?php foreach ($members as $m): ?>
  <?php if (!$m->approved): ?>
  <form id="approve-member-<?= md5($m->email) ?>" method="POST"
        action="<?= $b ?>/admin/members/<?= $id ?>/approve" class="action-form-inline">
    <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
    <input type="hidden" name="_page"        value="<?= (int) $page ?>">
  </form>
  <?php endif; ?>
  <form id="del-member-<?= md5($m->email) ?>" method="POST"
        action="<?= $b ?>/admin/members/<?= $id ?>/delete"
        data-del-modal data-del-entity="member"
        data-del-name="<?= View::e($m->name) ?>"
        data-del-context="<?= View::e($listing->name) ?>">
    <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
    <input type="hidden" name="_page"        value="<?= (int) $page ?>">
  </form>
<?php endforeach; ?>

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
<?php $pg_current = $page; $pg_total = $pages; $pg_url = $memPageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>

<?php endif; ?>