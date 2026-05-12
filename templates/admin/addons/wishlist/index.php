<?php
use Luster\Core\View;
/** @var \Luster\Addons\Wishlist\Wishlist[] $entries */
/** @var string   $filter_type */
/** @var array    $type_counts    ['wanted'=>int, 'top'=>int, 'granted'=>int, 'upcoming'=>int] */
/** @var string[] $categories     distinct category values for the filter dropdown */
/** @var string   $search */
/** @var string   $filter_cat */
/** @var bool     $filters_active */
/** @var string   $success */
/** @var string   $error */
/** @var string   $base_url */
/** @var string   $csrf_token */
$b = rtrim(View::e($base_url), '/');

// Build URL that preserves type tab when filter form is submitted
$typeQs = $filter_type !== 'all' ? '?type=' . View::e($filter_type) : '';

$tabTotal = array_sum($type_counts);
$tabs = [
    'all'      => ['label' => 'All',        'count' => $tabTotal],
    'wanted'   => ['label' => 'Wanted',     'count' => $type_counts['wanted']   ?? 0],
    'top'      => ['label' => 'Top Wanted', 'count' => $type_counts['top']      ?? 0],
    'granted'  => ['label' => 'Granted',    'count' => $type_counts['granted']  ?? 0],
    'upcoming' => ['label' => 'Upcoming',   'count' => $type_counts['upcoming'] ?? 0],
];
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TOOLBAR ────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="wl-filter-toggle"
            aria-expanded="<?= ($filters_active ?? false) ? 'true' : 'false' ?>"
            aria-controls="wl-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filters_active ?? false): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/wishlist/add" class="btn btn-primary btn-sm">
      <?= View::icon('add') ?> Add Wishlist Entry
    </a>
  </div>
</div>

<!-- ──── COLLAPSIBLE FILTER PANEL ───────────────────────────────────── -->
<div class="filter-panel<?= ($filters_active ?? false) ? ' is-open' : '' ?>" id="wl-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/wishlist" class="filter-row">
    <?php if ($filter_type !== 'all'): ?>
      <input type="hidden" name="type" value="<?= View::e($filter_type) ?>">
    <?php endif; ?>

    <?php if (!empty($categories)): ?>
    <div class="select-wrap">
      <select name="category">
        <option value="">All Categories</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= View::e($cat) ?>"<?= ($filter_cat ?? '') === $cat ? ' selected' : '' ?>>
            <?= View::e($cat) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <input type="text" name="search" class="form" placeholder="Search subject&hellip;"
           value="<?= View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-quaternary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/wishlist<?= $typeQs ?>" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>

<!-- ──── TYPE TABS ───────────────────────────────────────────────────── -->
<div class="tabs-wrap">
  <div class="tabs">
    <?php foreach ($tabs as $key => $tab): ?>
      <a href="<?= $b ?>/admin/wishlist<?= $key !== 'all' ? '?type=' . View::e($key) : '' ?>"
         class="tabs-btn<?= $filter_type === $key ? ' active' : '' ?>">
        <?= View::e($tab['label']) ?>
        <?php if ($tab['count'] > 0): ?>
          <span class="badge badge-inactive badge-inline-sm"><?= (int) $tab['count'] ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<!-- ──── TABLE ──────────────────────────────────────────────────────── -->
<?php if (empty($entries)): ?>
<div class="card">
  <div class="empty">
    <span class="empty-icon"><?= View::icon('wishlist', ['width' => 32, 'height' => 32]) ?></span>
    <?php if (($filters_active ?? false) || $filter_type !== 'all'): ?>
      No entries match those filters.
      <a href="<?= $b ?>/admin/wishlist" class="btn btn-tertiary btn-sm">Clear filters</a>
    <?php else: ?>
      Your wishlist is empty.
      <a href="<?= $b ?>/admin/wishlist/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add an entry</a>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<div class="card">
  <div class="card-head">
    <span>
      <?= $filter_type !== 'all' ? View::e($tabs[$filter_type]['label']) : 'All Entries' ?>
      (<?= (int) count($entries) ?>)
    </span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Subject</th>
          <th>Category</th>
          <th>Type</th>
          <th>URL</th>
          <th>Added</th>
          <th>Visible</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
        <tr class="<?= $entry->active ? '' : 'row-inactive' ?>">

          <td data-label="Subject">
            <span class="text-strong"><?= View::e($entry->subject) ?></span>
            <?php if ($entry->description !== null): ?>
              <div class="text-dim" style="font-size:var(--fs-sm);margin-top:.15rem;line-height:1.4">
                <?= View::e(mb_strimwidth($entry->description, 0, 80, '…')) ?>
              </div>
            <?php endif; ?>
          </td>

          <td class="text-muted" data-label="Category">
            <?= $entry->category !== '' ? View::e($entry->category) : '<span class="text-dim">&mdash;</span>' ?>
          </td>

          <td data-label="Type">
            <?php $badgeClass = match ($entry->type) {
              'top'      => 'badge-pending',
              'granted'  => 'badge-current',
              'upcoming' => 'badge-upcoming',
              default    => 'badge-inactive',
            }; ?>
            <span class="badge <?= $badgeClass ?>"><?= View::e($entry->typeLabel()) ?></span>
          </td>

          <td data-label="URL">
            <?php if ($entry->url !== ''): ?>
              <a href="<?= View::e($entry->url) ?>" target="_blank" rel="noopener noreferrer"
                 class="link text-dim" style="font-size:var(--fs-sm);word-break:break-all">
                <?= View::e(mb_strimwidth($entry->url, 0, 50, '…')) ?>
              </a>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>

          <td class="text-muted" data-label="Added">
            <?= $entry->addedAt !== null ? View::e($entry->addedAt) : '<span class="text-dim">&mdash;</span>' ?>
          </td>

          <td data-label="Visible">
            <form method="POST"
                  action="<?= $b ?>/admin/wishlist/<?= (int) $entry->id ?>/toggle"
                  class="lstr-toggle-form">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button type="submit"
                      class="toggle-pill<?= $entry->active ? ' toggle-pill--on' : '' ?>"
                      title="<?= $entry->active ? 'Click to hide' : 'Click to show' ?>">
                <?= $entry->active ? 'Yes' : 'No' ?>
              </button>
            </form>
          </td>

          <td class="act-col" data-label="">
            <div class="data-actions">
              <a href="<?= $b ?>/admin/wishlist/<?= (int) $entry->id ?>/edit"
                 class="btn btn-tertiary btn-sm">Edit</a>
              <button type="submit"
                      form="del-wishlist-<?= (int) $entry->id ?>"
                      class="btn btn-danger btn-sm">Delete</button>
            </div>
          </td>

        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($entries as $entry): ?>
<form id="del-wishlist-<?= (int) $entry->id ?>"
      method="POST"
      action="<?= $b ?>/admin/wishlist/<?= (int) $entry->id ?>/delete"
      data-del-modal
      data-del-entity="wishlist entry"
      data-del-name="<?= View::e($entry->subject) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
</form>
<?php endforeach; ?>

<?php endif; ?>
