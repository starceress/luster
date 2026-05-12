<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing $listing */
/** @var array[]  $applications  Each row: id, fanlisting_id, name, email, url, message, applied_at, status */
/** @var string   $status_filter */
/** @var string[] $valid_statuses */
/** @var int      $count_new */
/** @var string   $success */
/** @var string   $base_url */
/** @var string   $csrf_token */
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
    'approved' => 'badge-active',
    'rejected' => 'badge-inactive',
];
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/adoptions" class="link">&larr; All Adoptions</a>
</div>

<?php if ($success !== ''): ?>
<div class="callout callout-success" style="margin-bottom:1.25rem">
  <?= View::icon('check') ?>
  <div><?= View::e($success) ?></div>
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

  <div class="pc-section" style="padding-bottom:0">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.75rem">
      <?php foreach (['all', 'new', 'reviewed', 'approved', 'rejected'] as $s): ?>
        <a href="?status=<?= $s ?>"
           class="btn btn-sm <?= $status_filter === $s ? 'btn-primary' : 'btn-ghost' ?>">
          <?= ucfirst($s) ?>
          <?php if ($s === 'new' && $count_new > 0): ?>
            <span class="badge badge-pending" style="margin-left:.35rem"><?= $count_new ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if (empty($applications)): ?>
  <div class="pc-section">
    <p class="form-hint">No applications<?= $status_filter !== 'all' ? ' with status &ldquo;' . View::e($status_filter) . '&rdquo;' : '' ?>.</p>
  </div>
  <?php else: ?>
  <div class="pc-section" style="padding:0">
    <table class="data-table">
      <thead>
        <tr>
          <th>Applicant</th>
          <th>Message</th>
          <th>Applied</th>
          <th>Status</th>
          <th class="table-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($applications as $app): ?>
        <tr>
          <td>
            <div><?= View::e($app['name']) ?></div>
            <div class="form-hint">
              <a href="mailto:<?= View::e($app['email']) ?>" class="link"><?= View::e($app['email']) ?></a>
            </div>
            <?php if ($app['url'] !== ''): ?>
            <div class="form-hint">
              <a href="<?= View::e($app['url']) ?>" target="_blank" rel="noopener" class="link"><?= View::e($app['url']) ?></a>
            </div>
            <?php endif; ?>
          </td>
          <td style="max-width:320px">
            <?php if ($app['message'] !== null && $app['message'] !== ''): ?>
              <span title="<?= View::e($app['message']) ?>">
                <?= View::e(mb_strimwidth((string) $app['message'], 0, 120, '&hellip;')) ?>
              </span>
            <?php else: ?>
              <span class="form-hint">&mdash;</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap"><?= View::e(date('Y-m-d', strtotime((string) $app['applied_at']))) ?></td>
          <td>
            <span class="badge <?= $statusBadge[$app['status']] ?? 'badge-inactive' ?>">
              <?= View::e($statusLabels[$app['status']] ?? $app['status']) ?>
            </span>
          </td>
          <td class="table-actions">
            <form method="POST" action="<?= $b ?>/admin/adoptions/<?= (int) $app['id'] ?>/status"
                  style="display:inline">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <div class="select-wrap" style="display:inline-block;width:120px">
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
            <form method="POST" action="<?= $b ?>/admin/adoptions/<?= (int) $app['id'] ?>/delete"
                  style="display:inline"
                  data-confirm="Delete this application from <?= View::e($app['name']) ?>?">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button type="submit" class="btn btn-danger btn-xs">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
