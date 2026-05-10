<?php
/**
 * KIM List admin index — all fanlistings with KIM counts.
 *
 * Variables:
 *   $fanlistings_data  array   [{listing: Listing, pending: int, approved: int}]
 *   $base_url          string
 *   $csrf_token        string
 *   $success           string
 *   $error             string
 */

declare(strict_types=1);

use Luster\Core\View;

$b    = rtrim(View::e($base_url), '/');
$sort = $sort ?? 'id';
$dir  = $dir  ?? 'asc';

$rangeFirst = ($total_listings ?? 0) === 0 ? 0 : (($page ?? 1) - 1) * ($per_page ?? 10) + 1;
$rangeLast  = min(($page ?? 1) * ($per_page ?? 10), $total_listings ?? 0);

$sortUrl = static function (string $col) use ($b, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'sort' => $col !== 'id' ? $col : '',
        'dir'  => $newDir !== 'asc' ? $newDir : '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/kim' . ($qs !== '' ? '?' . $qs : '');
};

$pageUrl = static function (int $p) use ($b, $sort, $dir): string {
    $qs = http_build_query(array_filter([
        'sort' => $sort !== 'id' ? $sort : '',
        'dir'  => $dir !== 'asc' ? $dir : '',
        'page' => $p > 1 ? $p : 0,
    ], static fn($v): bool => (string) $v !== '' && $v !== 0));
    return $b . '/admin/kim' . ($qs !== '' ? '?' . $qs : '');
};
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <div class="toolbar-left"></div>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/kim/settings" class="btn btn-quaternary btn-sm">Display Settings</a>
  </div>
</div>

<?php if (empty($fanlistings_data)): ?>
  <div class="card">
    <div class="empty"><span class="empty-icon"><?= View::icon('kim') ?></span>
      No fanlistings found &mdash; add fanlistings first to use KIM List.
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">Keep In Mind List
      <span class="row-sub">Showing <?= $rangeFirst ?>-<?= $rangeLast ?> of <?= $total_listings ?> Listing<?= $total_listings === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th>
            <a href="<?= View::e($sortUrl('id')) ?>" class="sort-th<?= $sort === 'id' ? ' sort-active' : '' ?>"># <?= View::sortIcon('id', $sort, $dir) ?></a>
          </th>
          <th>
            <a href="<?= View::e($sortUrl('subject')) ?>" class="sort-th<?= $sort === 'subject' ? ' sort-active' : '' ?>">Listing Subject <?= View::sortIcon('subject', $sort, $dir) ?></a>
          </th>
          <th>Pending</th>
          <th>Approved</th>
          <th class="act-col"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fanlistings_data as $entry):
          /** @var \Luster\Models\Listing $listing */
          $listing  = $entry['listing'];
          $pending  = (int) $entry['pending'];
          $approved = (int) $entry['approved'];
        ?>
        <tr>
          <td class="text-dim" data-label="#"><?= (int) $listing->id ?></td>
          <td data-label="Listing Subject"><a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" class="link"><?= View::e($listing->subject) ?></a></td>
          <td data-label="Pending">
            <?php if ($pending > 0): ?>
              <span class="badge badge-pending"><?= (int) $pending ?></span>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td data-label="Approved">
            <?php if ($approved > 0): ?>
              <span class="badge badge-current"><?= (int) $approved ?></span>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="act-col" data-label="">
            <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/show"
               class="btn btn-primary btn-sm">View</a>
            <?php if ($approved > 0): ?>
              <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/bulk-email"
                 class="btn btn-quaternary btn-sm">Bulk Email</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php $pg_current = $page ?? 1; $pg_total = $pages ?? 1; $pg_url = $pageUrl;
        require __DIR__ . '/../../_pagination.php'; ?>
  </div>
<?php endif; ?>
