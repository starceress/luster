<?php

use Luster\Core\View;

/** @var \Luster\Models\Affiliate[]    $pending */
/** @var array<int,\Luster\Models\Listing> $listing_map */
/** @var string                        $success */
/** @var string                        $error */
/** @var string                        $base_url */
/** @var string                        $csrf_token */

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

<?php if (empty($pending)): ?>
  <div class="card">
    <div class="empty">
      No pending affiliate applications.
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
      <tr>
        <td data-label="Listing" class="text-muted">
          <?php $fl = $listing_map[$aff->listingId] ?? null; ?>
          <?= $fl !== null ? View::e($fl->name) : '—' ?>
        </td>
        <td data-label="Site name"><?= View::e($aff->name) ?></td>
        <td data-label="URL">
          <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($aff->url) ?></a>
        </td>
        <td data-label="Email" class="text-muted"><?= $aff->email !== null ? View::e($aff->email) : '—' ?></td>
        <td data-label="Image">
          <?php if ($aff->image !== null && $aff->image !== ''): ?>
            <img src="<?= $b ?>/uploads/affiliates/<?= View::e($aff->image) ?>"
                 alt="" style="max-height:40px;max-width:80px;border-radius:2px;">
          <?php else: ?>
            <span class="text-muted">—</span>
          <?php endif; ?>
        </td>
        <td data-label="Message">
          <?php if ($aff->applicationMessage !== null && $aff->applicationMessage !== ''): ?>
            <span title="<?= View::e($aff->applicationMessage) ?>" style="cursor:help">
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
              <input type="hidden" name="_csrf"         value="<?= View::e($csrf_token) ?>">
              <input type="hidden" name="from_pending"  value="1">
              <button type="submit" class="btn btn-primary btn-sm">Approve</button>
            </form>
            <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/reject"
                  data-del-modal data-del-entity="application"
                  data-del-name="<?= View::e($aff->name) ?>">
              <input type="hidden" name="_csrf"         value="<?= View::e($csrf_token) ?>">
              <input type="hidden" name="from_pending"  value="1">
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
