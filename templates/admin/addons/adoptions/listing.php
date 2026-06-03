<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Adoptions admin — per-listing applicant blocks.
 *
 * @var \Luster\Models\Listing $listing
 * @var array[]  $applications  Each row: id, fanlisting_id, name, email, url, message, applied_at, status
 * @var string   $status_filter
 * @var array{all:int,new:int,reviewed:int,approved:int,rejected:int} $counts
 * @var string   $success
 * @var string   $error
 * @var string   $base_url
 * @var string   $csrf_token
 */

$b = rtrim(View::e($base_url), '/');

$statusLabels = [
    'new'      => 'New',
    'reviewed' => 'Reviewed',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];
$statusBadge = [
    'new'      => 'badge-pending',
    'reviewed' => 'badge-info',
    'approved' => 'badge-current',
    'rejected' => 'badge-inactive',
];
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/adoptions" class="link">&larr; All Adoptions</a>
</div>

<?php if ($success !== ''): ?>
<div class="alert alert-success">
  <?= View::icon('check') ?><div><?= View::e($success) ?></div>
</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="alert alert-error">
  <?= View::icon('warning') ?><div><?= View::e($error) ?></div>
</div>
<?php endif; ?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-meta">
      <div class="pc-head-title"><?= View::e($listing->name) ?></div>
      <div class="pc-head-sub">
        <a href="<?= View::e($listing->url) ?>" target="_blank" rel="noopener" class="link">
          <?= View::e($listing->url) ?>
        </a>
      </div>
    </div>
  </div>

  <!-- Filter tabs -->
  <div class="pc-section pc-section--flush-b">
    <div class="pill-filter-row">
      <?php foreach (['all', 'new', 'reviewed', 'approved', 'rejected'] as $s): ?>
        <a href="?status=<?= $s ?>"
           class="btn btn-sm <?= $status_filter === $s ? 'btn-primary' : 'btn-ghost' ?>">
          <?= ucfirst($s) ?>
          <?php if ($s !== 'all' && $counts[$s] > 0): ?>
            <span class="badge <?= $statusBadge[$s] ?? '' ?>" style="margin-left:.2rem"><?= $counts[$s] ?></span>
          <?php elseif ($s === 'all' && $counts['all'] > 0): ?>
            <span class="badge badge-inactive" style="margin-left:.2rem"><?= $counts['all'] ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (empty($applications)): ?>
  <div class="empty">
    <div class="empty-icon"><?= View::icon('adoptions', ['width' => 32, 'height' => 32]) ?></div>
    <?php if ($status_filter !== 'all'): ?>
      <div class="empty-title">No <?= View::e($status_filter) ?> applications.</div>
      <a href="?" class="btn btn-ghost btn-sm">Clear Filter</a>
    <?php else: ?>
      <div class="empty-title">No applications yet.</div>
      <div>Applications submitted by fans will appear here.</div>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <?php foreach ($applications as $app): ?>
  <?php
    $msg    = (string) ($app['message'] ?? '');
    $isLong = mb_strlen($msg) > 250;
    $appId  = (int) $app['id'];
    $fid    = (int) $app['fanlisting_id'];
  ?>
  <div class="adopt-app">
    <!-- Head: identity + aside -->
    <div class="adopt-app-head">
      <div class="adopt-app-ident">
        <div class="adopt-app-name"><?= View::e($app['name']) ?></div>
        <div class="adopt-app-sub">
          <span>
            <a href="mailto:<?= View::e($app['email']) ?>" class="link"><?= View::emailWrap($app['email']) ?></a>
          </span>
          <?php if ($app['url'] !== ''): ?>
          <span>
            <a href="<?= View::e($app['url']) ?>" target="_blank" rel="noopener" class="link link-break"><?= View::e($app['url']) ?></a>
          </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="adopt-app-aside">
        <div class="adopt-app-date"><?= View::e(date('Y-m-d', strtotime((string) $app['applied_at']))) ?></div>
        <span class="badge <?= $statusBadge[$app['status']] ?? 'badge-inactive' ?>">
          <?= View::e($statusLabels[$app['status']] ?? $app['status']) ?>
        </span>
      </div>
    </div>

    <!-- Message -->
    <?php if ($msg !== ''): ?>
    <div class="adopt-app-msg">
      <?php if (!$isLong): ?>
        <?= nl2br(View::e($msg)) ?>
      <?php else: ?>
        <?= View::e(mb_strimwidth($msg, 0, 250, '')) ?>&hellip;<details class="msg-expand">
          <summary>View full</summary>
          <?= nl2br(View::e($msg)) ?>
        </details>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div class="adopt-app-foot">
      <?php if ($app['status'] !== 'approved'): ?>
      <form method="POST" action="<?= $b ?>/admin/adoptions/<?= $appId ?>/approve"
            class="action-form-inline">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <button type="submit" class="btn btn-success btn-xs">Approve</button>
      </form>
      <?php endif; ?>

      <?php if ($app['status'] !== 'rejected'): ?>
      <form method="POST" action="<?= $b ?>/admin/adoptions/<?= $appId ?>/reject"
            class="action-form-inline"
            data-confirm="Reject the application from <?= View::e($app['name']) ?>?">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <button type="submit" class="btn btn-ghost btn-xs">Reject</button>
      </form>
      <?php endif; ?>

      <a href="mailto:<?= View::e($app['email']) ?>"
         class="btn btn-secondary btn-xs">Email</a>

      <!-- Status dropdown — secondary workflow -->
      <form method="POST" action="<?= $b ?>/admin/adoptions/<?= $appId ?>/status"
            class="action-form-inline">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <div class="select-wrap select-wrap--sm">
          <select name="status" onchange="this.form.submit()" class="form form--compact">
            <?php foreach ($statusLabels as $sv => $sl): ?>
              <option value="<?= View::e($sv) ?>"
                      <?= $app['status'] === $sv ? 'selected' : '' ?>>
                <?= View::e($sl) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>

      <!-- Delete — deemphasised, right-aligned -->
      <form method="POST" action="<?= $b ?>/admin/adoptions/<?= $appId ?>/delete"
            class="action-form-inline adopt-app-del"
            data-confirm="Delete this application from <?= View::e($app['name']) ?>?">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <button type="submit" class="btn btn-ghost btn-xs" style="color:var(--danger)">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div>
