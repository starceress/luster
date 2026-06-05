<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Adoptions admin — per-listing applicant blocks.
 *
 * @var \Luster\Models\Listing $listing
 * @var array[]  $new_applications    Applications with status='pending'
 * @var array[]  $other_applications  Applications with any other status
 * @var array{all:int,pending:int,approved:int,rejected:int,granted:int} $counts
 * @var string   $success
 * @var string   $error
 * @var string   $base_url
 * @var string   $csrf_token
 */

$b = rtrim(View::e($base_url), '/');

$statusLabels = [
    'pending'  => 'Pending',
    'new'      => 'Pending',  // legacy fallback
    'reviewed' => 'Reviewed',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'granted'  => 'Granted',
];
$statusBadge = [
    'pending'  => 'badge-pending',
    'new'      => 'badge-pending',  // legacy fallback
    'reviewed' => 'badge-upcoming',
    'approved' => 'badge-current',
    'rejected' => 'badge-error',
    'granted'  => 'badge-redirect',
];
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/adoptions" class="link">&larr; All Adoptions</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── AWAITING REVIEW ──────────────────────────────────────────── -->
<div class="overview-section-head">
  <div class="overview-section-title">
    <?= View::icon('pending') ?> Awaiting Review
  </div>
  <div class="overview-section-sub">New applications that have not yet been reviewed.</div>
</div>

<?php if (empty($new_applications)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('check-badge', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No new applications.</div>
      <div>All caught up.</div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <span><?= View::e($listing->title) ?></span>
      <div class="row-sub">
        <span class="badge badge-pending"><?= count($new_applications) ?> pending</span>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th>Applicant</th>
            <th>Message</th>
            <th class="nowrap">Applied</th>
            <th class="act-col"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($new_applications as $app): ?>
          <?php $appId = (int) $app['id']; ?>
          <tr>
            <td data-label="Applicant">
              <div class="text-strong"><?= View::e($app['name']) ?></div>
              <div class="form-hint">
                <a href="mailto:<?= View::e($app['email']) ?>" class="link"><?= View::emailWrap($app['email']) ?></a>
              </div>
              <?php if ($app['url'] !== ''): ?>
              <div class="form-hint">
                <a href="<?= View::e($app['url']) ?>" target="_blank" rel="noopener" class="link link-break"><?= View::e($app['url']) ?></a>
              </div>
              <?php endif; ?>
            </td>
            <td class="cell-truncate" data-label="Message">
              <?php $msg = (string) ($app['message'] ?? ''); ?>
              <?php if ($msg !== ''): ?>
                <?php if (mb_strlen($msg) > 120): ?>
                  <?= View::e(mb_strimwidth($msg, 0, 120, '')) ?>&hellip;<details class="msg-expand">
                    <summary>View full</summary>
                    <?= nl2br(View::e($msg)) ?>
                  </details>
                <?php else: ?>
                  <?= nl2br(View::e($msg)) ?>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="nowrap text-dim" data-label="Applied">
              <?= View::e(date('Y-m-d', strtotime((string) $app['applied_at']))) ?>
            </td>
            <td class="act-col" data-label="">
              <div class="data-actions">
                <form method="POST" action="<?= $b ?>/admin/adoptions/<?= $appId ?>/approve"
                      class="action-form-inline">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <button type="submit" class="btn btn-success btn-xs">Approve</button>
                </form>
                <form method="POST" action="<?= $b ?>/admin/adoptions/<?= $appId ?>/reject"
                      class="action-form-inline"
                      data-confirm="Reject the application from <?= View::e($app['name']) ?>?">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <button type="submit" class="btn btn-ghost btn-xs">Reject</button>
                </form>
                <a href="<?= $b ?>/admin/adoptions/<?= $appId ?>/edit" class="btn btn-primary btn-xs">Review</a>
                <a href="mailto:<?= View::e($app['email']) ?>" class="btn btn-secondary btn-xs">Email</a>
                <form method="POST" action="<?= $b ?>/admin/adoptions/<?= $appId ?>/delete"
                      class="action-form-inline"
                      data-confirm="Delete this application from <?= View::e($app['name']) ?>?">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <button type="submit" class="btn btn-danger btn-xs">Delete</button>
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

<!-- ──── PREVIOUSLY REVIEWED ─────────────────────────────────────── -->
<div class="overview-section-divider"></div>
<div class="overview-section-head">
  <div class="overview-section-title">
    <?= View::icon('approved') ?> Previously Reviewed
  </div>
  <div class="overview-section-sub">Applications that have already been reviewed, approved, or rejected.</div>
</div>

<?php if (empty($other_applications)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('adoptions', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No reviewed applications yet.</div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <span><?= View::e($listing->title) ?></span>
      <div class="row-sub">
        <?php if (($counts['granted'] ?? 0) > 0): ?>
          <span class="badge badge-redirect"><?= (int) $counts['granted'] ?> granted</span>
        <?php endif; ?>
        <?php if (($counts['approved'] ?? 0) > 0): ?>
          <span class="badge badge-current"><?= (int) $counts['approved'] ?> approved</span>
        <?php endif; ?>
        <?php if (($counts['rejected'] ?? 0) > 0): ?>
          <span class="badge badge-inactive"><?= (int) $counts['rejected'] ?> rejected</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th>Applicant</th>
            <th>Status</th>
            <th>Message</th>
            <th class="nowrap">Applied</th>
            <th class="act-col"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($other_applications as $app): ?>
          <?php $appId = (int) $app['id']; ?>
          <tr>
            <td data-label="Applicant">
              <div class="text-strong"><?= View::e($app['name']) ?></div>
              <div class="form-hint">
                <a href="mailto:<?= View::e($app['email']) ?>" class="link"><?= View::emailWrap($app['email']) ?></a>
              </div>
              <?php if ($app['url'] !== ''): ?>
              <div class="form-hint">
                <a href="<?= View::e($app['url']) ?>" target="_blank" rel="noopener" class="link link-break"><?= View::e($app['url']) ?></a>
              </div>
              <?php endif; ?>
            </td>
            <td data-label="Status">
              <span class="badge <?= $statusBadge[$app['status']] ?? 'badge-inactive' ?>">
                <?= View::e($statusLabels[$app['status']] ?? $app['status']) ?>
              </span>
            </td>
            <td class="cell-truncate" data-label="Message">
              <?php $msg = (string) ($app['message'] ?? ''); ?>
              <?php if ($msg !== ''): ?>
                <?php if (mb_strlen($msg) > 120): ?>
                  <?= View::e(mb_strimwidth($msg, 0, 120, '')) ?>&hellip;<details class="msg-expand">
                    <summary>View full</summary>
                    <?= nl2br(View::e($msg)) ?>
                  </details>
                <?php else: ?>
                  <?= nl2br(View::e($msg)) ?>
                <?php endif; ?>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="nowrap text-dim" data-label="Applied">
              <?= View::e(date('Y-m-d', strtotime((string) $app['applied_at']))) ?>
            </td>
            <td class="act-col" data-label="">
              <div class="data-actions">
                <a href="<?= $b ?>/admin/adoptions/<?= $appId ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
                <a href="mailto:<?= View::e($app['email']) ?>" class="btn btn-secondary btn-xs">Email</a>
                <form method="POST" action="<?= $b ?>/admin/adoptions/<?= $appId ?>/delete"
                      class="action-form-inline"
                      data-confirm="Delete this application from <?= View::e($app['name']) ?>?">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <button type="submit" class="btn btn-danger btn-xs">Delete</button>
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
