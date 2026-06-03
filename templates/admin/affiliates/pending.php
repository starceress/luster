<?php

use Luster\Core\View;

/** @var \Luster\Models\Affiliate[]        $pending */
/** @var array<int,\Luster\Models\Listing> $listing_map */
/** @var \Luster\Models\Listing[]          $listings */
/** @var array<int,true>                   $conflict_ids */
/** @var string                            $scope */
/** @var string                            $success */
/** @var string                            $error */
/** @var string                            $base_url */
/** @var string                            $csrf_token */

$b = rtrim(View::e($base_url), '/');

?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/affiliates" class="link">&larr; Back to Affiliates</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- Scope filter -->
<form method="GET" action="<?= $b ?>/admin/affiliates/pending" class="filter-row">
  <div class="select-wrap">
    <select name="scope" class="form form-sm">
      <option value="all"       <?= ($scope ?? 'all') === 'all'       ? 'selected' : '' ?>>All listings</option>
      <option value="collective"<?= ($scope ?? 'all') === 'collective' ? 'selected' : '' ?>>Collective only</option>
      <?php foreach (($listings ?? []) as $fl): ?>
        <option value="<?= (int) $fl->id ?>"
                <?= ($scope ?? 'all') === (string) $fl->id ? 'selected' : '' ?>>
          <?= View::e($fl->name) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn btn-ghost btn-sm">Filter</button>
</form>

<?php if (empty($pending)): ?>
  <div class="gradient-card">
    <div class="empty">
      No pending affiliate applications<?= ($scope ?? 'all') !== 'all' ? ' for this scope' : '' ?>.
    </div>
  </div>
<?php else: ?>

<div class="card">
  <div class="card-head">
    <span>Pending Applications</span>
    <span class="row-sub"><?= count($pending) ?> application<?= count($pending) !== 1 ? 's' : '' ?></span>
  </div>
  <div class="table-wrap">
  <table class="data-table">
    <thead>
      <tr>
        <th>Listing</th>
        <th>Site name</th>
        <th>URL</th>
        <th>Email</th>
        <th>Image</th>
        <th>Message</th>
        <th>Submitted</th>
        <th class="act-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pending as $aff): ?>
      <?php $hasDuplicate = isset(($conflict_ids ?? [])[$aff->id]); ?>
      <tr<?= $hasDuplicate ? ' class="row-warning"' : '' ?>>
        <td data-label="Listing" class="text-muted">
          <?php $fl = $listing_map[$aff->listingId] ?? null; ?>
          <?php if ($fl !== null): ?>
            <?= View::e($fl->name) ?>
          <?php elseif ($aff->listingId === null): ?>
            <span class="text-muted">Collective</span>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td data-label="Site name">
          <?= View::e($aff->name) ?>
          <?php if ($hasDuplicate): ?>
            <span class="badge badge-warning" title="An active affiliate with this URL already exists in the same scope.">Duplicate URL</span>
          <?php endif; ?>
        </td>
        <td data-label="URL">
          <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($aff->url) ?></a>
        </td>
        <td data-label="Email" class="text-muted">
          <?php if ($aff->email !== null && $aff->email !== ''): ?>
            <a href="mailto:<?= View::e($aff->email) ?>" class="link"><?= View::emailWrap($aff->email) ?></a>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td data-label="Image">
          <?php if ($aff->image !== null && $aff->image !== ''): ?>
            <img src="<?= $b ?>/uploads/affiliates/<?= View::e($aff->image) ?>"
                 alt="" class="thumbnail-sm">
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td data-label="Message">
          <?php if ($aff->applicationMessage !== null && $aff->applicationMessage !== ''): ?>
            <span title="<?= View::e($aff->applicationMessage) ?>" class="cursor-help">
              <?= View::e(mb_strimwidth($aff->applicationMessage, 0, 60, '…')) ?>
            </span>
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td data-label="Submitted" class="text-muted"><?= View::e($aff->createdAt !== '' ? date('Y-m-d', strtotime($aff->createdAt)) : '—') ?></td>
        <td class="act-col" data-label="">
          <div class="data-actions">
            <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/approve">
              <input type="hidden" name="_csrf"          value="<?= View::e($csrf_token) ?>">
              <input type="hidden" name="from_pending"   value="1">
              <input type="hidden" name="pending_scope"  value="<?= View::e($scope ?? 'all') ?>">
              <button type="submit" class="btn btn-primary btn-sm">Approve</button>
            </form>
            <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/reject"
                  data-del-modal data-del-entity="application"
                  data-del-name="<?= View::e($aff->name) ?>">
              <input type="hidden" name="_csrf"          value="<?= View::e($csrf_token) ?>">
              <input type="hidden" name="from_pending"   value="1">
              <input type="hidden" name="pending_scope"  value="<?= View::e($scope ?? 'all') ?>">
              <button type="submit" class="btn btn-danger btn-sm">Reject</button>
            </form>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php endif; ?>
