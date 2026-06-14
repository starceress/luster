<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var array<int,array{listing_id:int,listing_title:string,listing_subject:string,listing_type:string,rules_id:int|null,rules_enabled:bool|null,rules_version:int|null,rules_updated_at:string|null}> $rows
 * @var int      $total
 * @var int      $page
 * @var int      $pages
 * @var int      $per_page
 * @var string[] $subjects
 * @var string   $success
 * @var string   $error
 * @var string   $sort
 * @var string   $dir
 * @var string   $search
 * @var string   $type
 * @var string   $base_url
 * @var string   $csrf_token
 */

$b = rtrim(View::e($base_url), '/');

$_typeLabels = [
    'listing'    => 'Fanlisting',
    'clique'     => 'Clique',
    'collective' => 'Collective',
    'shrine'     => 'Shrine',
    'webring'    => 'Webring',
    'other'      => 'Other',
];

$filtersActive = $search !== '' || $type !== '';

$sortUrl = static function (string $col) use ($b, $sort, $dir, $search, $type): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'type'   => $type,
        'search' => $search,
        'sort'   => $col !== 'id' ? $col : '',
        'dir'    => $newDir !== 'asc' ? $newDir : '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/rules' . ($qs !== '' ? '?' . $qs : '');
};

$pageUrl = static function (int $p) use ($b, $sort, $dir, $search, $type): string {
    $qs = http_build_query(array_filter([
        'type'   => $type,
        'search' => $search,
        'sort'   => $sort !== 'id' ? $sort : '',
        'dir'    => $dir !== 'asc' ? $dir : '',
        'page'   => $p > 1 ? $p : 0,
    ], static fn($v): bool => (string) $v !== '' && $v !== 0));
    return $b . '/admin/rules' . ($qs !== '' ? '?' . $qs : '');
};

$rangeFirst = $total === 0 ? 0 : ($page - 1) * $per_page + 1;
$rangeLast  = min($page * $per_page, $total);
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TOOLBAR ─────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle"
            id="rules-filter-toggle"
            aria-expanded="<?= $filtersActive ? 'true' : 'false' ?>"
            aria-controls="rules-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/rules/global" class="btn btn-primary btn-sm">Global Rules Template</a>
    <a href="<?= $b ?>/admin/rules/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?>Join Rules Settings</a>
  </div>
</div>

<!-- ──── FILTER PANEL ────────────────────────────────────────────────── -->
<div class="filter-panel<?= $filtersActive ? '' : ' is-hidden' ?>" id="rules-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/rules" class="filter-row">
    <?php if ($sort !== 'id'): ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>"><?php endif; ?>
    <?php if ($dir !== 'asc'): ?><input type="hidden" name="dir"  value="<?= View::e($dir)  ?>"><?php endif; ?>
    <div class="select-wrap">
      <select name="type" class="form" id="rules-type-select">
        <option value="">All Types</option>
        <?php foreach ($_typeLabels as $val => $label): ?>
          <option value="<?= View::e($val) ?>"<?= $type === $val ? ' selected' : '' ?>><?= View::e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search by subject&hellip;"
             value="<?= View::e($search) ?>" list="rules-subjects-list" autocomplete="on">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/rules" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>

<?php if (!empty($subjects)): ?>
<datalist id="rules-subjects-list">
  <?php foreach ($subjects as $s): ?>
    <option value="<?= View::e($s) ?>">
  <?php endforeach; ?>
</datalist>
<?php endif; ?>

<!-- ──── TABLE ───────────────────────────────────────────────────────── -->
<?php if ($total === 0 && !$filtersActive): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('rules', ['width' => 32, 'height' => 32]) ?></div>
    <div class="empty-title">No listings found.</div>
    <p>Add a listing first, then come back here to write join rules for it.</p>
  </div>
</div>
<?php else: ?>


<form method="POST" action="<?= $b ?>/admin/rules/bulk" id="rules-bulk-form"
      data-del-bulk data-del-entity="rules">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card">
    <div class="card-head">All Listings
      <span class="row-sub">Showing <?= $rangeFirst ?>&ndash;<?= $rangeLast ?> of <?= (int) $total ?> Listing<?= $total === 1 ? '' : 's' ?></span>
    </div>

    <div class="bulk bulk--flush">
      Bulk Actions:
      <div class="select-wrap">
        <select id="rules-bulk-sel" class="select-xs" name="bulk_action">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="enable">Enable</option>
          <option value="disable">Disable</option>
          <option value="delete">Delete</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>

    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th class="cb-col">
              <input type="checkbox" id="rules-sel-all" title="Select all">
            </th>
            <th style="width:3.5rem">
              <a href="<?= View::e($sortUrl('id')) ?>"
                 class="sort-th<?= $sort === 'id' ? ' sort-active' : '' ?>">
                # <?= View::sortIcon('id', $sort, $dir) ?>
              </a>
            </th>
            <th>
              <a href="<?= View::e($sortUrl('subject')) ?>"
                 class="sort-th<?= $sort === 'subject' ? ' sort-active' : '' ?>">
                Listing <?= View::sortIcon('subject', $sort, $dir) ?>
              </a>
            </th>
            <th>Status</th>
            <th>
              <a href="<?= View::e($sortUrl('updated_at')) ?>"
                 class="sort-th<?= $sort === 'updated_at' ? ' sort-active' : '' ?>">
                Last Updated <?= View::sortIcon('updated_at', $sort, $dir) ?>
              </a>
            </th>
            <th class="act-col">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
          <tr>
            <td colspan="6" class="text-dim" style="text-align:center;padding:1.5rem">
              No listings match the current filters.
            </td>
          </tr>
          <?php endif; ?>
          <?php foreach ($rows as $row): ?>
          <tr>
            <td class="cb-col" data-label="">
              <?php if ($row['rules_id'] !== null): ?>
                <input type="checkbox" class="rules-row-cb" name="ids[]" value="<?= (int) $row['listing_id'] ?>"
                       data-name="<?= View::e($row['listing_subject']) ?>">
              <?php endif; ?>
            </td>

            <td data-label="#" class="text-muted"><?= (int) $row['listing_id'] ?></td>

            <td data-label="Listing">
              <div class="ctx-cell">
                <a class="text-strong link" href="<?= $b ?>/admin/rules/<?= (int) $row['listing_id'] ?>/edit"><?= View::e($row['listing_subject']) ?></a>
                <?php if ($row['listing_type'] !== '' && $row['listing_type'] !== 'listing'): ?>
                  <span class="badge badge-inactive"><?= View::e($_typeLabels[$row['listing_type']] ?? ucfirst($row['listing_type'])) ?></span>
                <?php endif; ?>
              </div>
            </td>

            <td data-label="Status">
              <?php if ($row['rules_id'] === null): ?>
                <span class="badge badge-inactive">No rules</span>
              <?php elseif ($row['rules_enabled']): ?>
                <span class="badge badge-current">Enabled</span>
              <?php else: ?>
                <span class="badge badge-pending">Disabled</span>
              <?php endif; ?>
            </td>

            <td data-label="Last Updated" class="text-muted">
              <?php if ($row['rules_updated_at'] !== null): ?>
                <?= View::e(date($admin_date_format, strtotime($row['rules_updated_at']))) ?>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>

            <td class="act-col" data-label="">
              <div class="data-actions">
                <a href="<?= $b ?>/admin/rules/<?= (int) $row['listing_id'] ?>/edit"
                   class="btn btn-tertiary btn-xs">Edit</a>

                <?php if ($row['rules_id'] !== null): ?>
                  <button type="submit" form="toggle-rules-<?= (int) $row['listing_id'] ?>"
                          class="btn btn-quaternary btn-xs">
                    <?= $row['rules_enabled'] ? 'Disable' : 'Enable' ?>
                  </button>
                  <button type="submit" form="del-rules-<?= (int) $row['listing_id'] ?>"
                          class="btn btn-danger btn-xs">Delete</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?php $pg_current = $page; $pg_total = $pages; $pg_url = $pageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>

<?php foreach ($rows as $row): if ($row['rules_id'] === null) continue; ?>
<form id="toggle-rules-<?= (int) $row['listing_id'] ?>"
      method="POST"
      action="<?= $b ?>/admin/rules/<?= (int) $row['listing_id'] ?>/toggle">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
</form>
<form id="del-rules-<?= (int) $row['listing_id'] ?>"
      method="POST"
      action="<?= $b ?>/admin/rules/<?= (int) $row['listing_id'] ?>/delete"
      data-del-modal
      data-del-entity="rules"
      data-del-name="<?= View::e($row['listing_subject']) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
</form>
<?php endforeach; ?>

<?php endif; ?>
