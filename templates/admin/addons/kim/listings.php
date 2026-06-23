<?php
/**
 * KIM List admin — listings overview.
 *
 * Variables:
 *   $fanlistings_data  array  [{listing: Listing, pending: int, approved: int, granted: int}]
 *   $total_listings    int
 *   $sort              string
 *   $dir               string
 *   $page              int
 *   $pages             int
 *   $per_page          int
 *   $global_pending    int
 *   $kim_section       string
 *   $success           string
 *   $error             string
 *   $base_url          string
 *   $csrf_token        string
 */

declare(strict_types=1);

use Luster\Core\View;

$b    = rtrim(View::e($base_url), '/');
$sort = $sort ?? 'subject';
$dir  = $dir  ?? 'asc';

$rangeFirst = ($total_listings ?? 0) === 0 ? 0 : (($page ?? 1) - 1) * ($per_page ?? 10) + 1;
$rangeLast  = min(($page ?? 1) * ($per_page ?? 10), $total_listings ?? 0);

$sortUrl = static function (string $col) use ($b, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'sort' => $col !== 'subject' ? $col : '',
        'dir'  => $newDir !== 'asc'  ? $newDir : '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/kim/listings' . ($qs !== '' ? '?' . $qs : '');
};

$pageUrl = static function (int $p) use ($b, $sort, $dir): string {
    $qs = http_build_query(array_filter([
        'sort' => $sort !== 'subject' ? $sort : '',
        'dir'  => $dir !== 'asc'      ? $dir  : '',
        'page' => $p > 1              ? $p    : 0,
    ], static fn($v): bool => (string) $v !== '' && $v !== 0));
    return $b . '/admin/kim/listings' . ($qs !== '' ? '?' . $qs : '');
};
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <div class="actions-row"></div>
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/kim" class="btn btn-primary btn-sm">Member View</a>
    <a href="<?= $b ?>/admin/kim/settings" class="btn btn-quaternary btn-sm">Display Settings</a>
  </div>
</div>

<?php if (empty($fanlistings_data)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('kim', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No listings found.</div>
      <div>Add a listing first to use the KIM list.</div>
      <a href="<?= $b ?>/admin/listings/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Listing</a>
    </div>
  </div>
<?php else: ?>

  <div class="card">
    <div class="card-head">Listings
      <span class="card-head-sub">Showing <?= $rangeFirst ?>&ndash;<?= $rangeLast ?> of <?= (int) $total_listings ?></span>
    </div>
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th>
            <a href="<?= View::e($sortUrl('subject')) ?>"
               class="sort-th<?= $sort === 'subject' ? ' sort-active' : '' ?>">
              Listing <?= View::sortIcon('subject', $sort, $dir) ?>
            </a>
          </th>
          <th>
            <a href="<?= View::e($sortUrl('pending')) ?>"
               class="sort-th<?= $sort === 'pending' ? ' sort-active' : '' ?>">
              Pending <?= View::sortIcon('pending', $sort, $dir) ?>
            </a>
          </th>
          <th>Approved</th>
          <th>Granted</th>
          <th class="act-col"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fanlistings_data as $entry):
          /** @var \Luster\Models\Listing $listing */
          $listing  = $entry['listing'];
          $pending  = (int) $entry['pending'];
          $approved = (int) $entry['approved'];
          $granted  = (int) $entry['granted'];
          $canEmail = ($approved + $granted) > 0;
        ?>
        <tr>
          <td data-label="Listing">
            <div class="subject-title-stack">
              <a href="<?= $b ?>/admin/kim?listing_id=<?= (int) $listing->id ?>" class="link"><b><?= View::e($listing->subject ?: $listing->title) ?></b></a>
              <?php if ($listing->subject !== ''): ?>
                <span class="mem-url"><?= View::e($listing->title) ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Pending">
            <?php if ($pending > 0): ?>
              <a href="<?= $b ?>/admin/kim?listing_id=<?= (int) $listing->id ?>&tab=pending"
                 class="badge badge-pending"><?= $pending ?></a>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td data-label="Approved">
            <?php if ($approved > 0): ?>
              <a href="<?= $b ?>/admin/kim?listing_id=<?= (int) $listing->id ?>&tab=approved"
                 class="badge badge-current"><?= $approved ?></a>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td data-label="Granted">
            <?php if ($granted > 0): ?>
              <a href="<?= $b ?>/admin/kim?listing_id=<?= (int) $listing->id ?>&tab=granted"
                 class="badge badge-redirect"><?= $granted ?></a>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="act-col" data-label="">
            <a href="<?= $b ?>/admin/kim?listing_id=<?= (int) $listing->id ?>"
               class="btn btn-primary btn-xs">View</a>
            <?php if ($canEmail): ?>
              <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/bulk-email"
                 class="btn btn-secondary btn-xs">Email All</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php $pg_current = $page ?? 1; $pg_total = $pages ?? 1; $pg_url = $pageUrl;
        include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
<?php endif; ?>
