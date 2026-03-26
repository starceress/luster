<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var string $listing_name
 * @var string $subject
 * @var int    $sent
 * @var int    $total
 * @var array  $failed    email addresses that failed
 * @var string $base_url
 */

$b = rtrim(View::e($base_url ?? ''), '/');
$allOk = empty($failed);
?>

<div class="card" style="padding:1.75rem 2rem">
  <?php if ($allOk): ?>
    <div class="alert alert-success" style="margin-bottom:1.25rem">
      Mass email sent successfully.
    </div>
  <?php else: ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem">
      Send completed with errors â€” <?= count($failed) ?> message<?= count($failed) !== 1 ? 's' : '' ?> failed.
    </div>
  <?php endif; ?>

  <div class="db-info-grid" style="margin-bottom:1.25rem">
    <div>
      <div class="form-label" style="margin-bottom:.25rem">Sent to</div>
      <div><?= View::e($listing_name) ?></div>
    </div>
    <div>
      <div class="form-label" style="margin-bottom:.25rem">Subject</div>
      <div><?= View::e($subject) ?></div>
    </div>
    <div>
      <div class="form-label" style="margin-bottom:.25rem">Delivered</div>
      <div><strong><?= (int) $sent ?></strong> / <?= (int) $total ?></div>
    </div>
    <?php if (!$allOk): ?>
    <div>
      <div class="form-label" style="margin-bottom:.25rem">Failed</div>
      <div><?= count($failed) ?></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$allOk): ?>
  <div style="margin-bottom:1.25rem">
    <div class="form-label" style="margin-bottom:.5rem">Failed addresses</div>
    <ul style="margin:0;padding-left:1.25rem;color:var(--text-muted);font-size:.875rem">
      <?php foreach ($failed as $addr): ?>
        <li><?= View::e($addr) ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="form-hint">Check your SMTP configuration or the error log for details.</p>
  </div>
  <?php endif; ?>

  <div class="btn-row">
    <a href="<?= $b ?>/admin/emails?tab=bulk" class="btn btn-primary">Compose another</a>
    <a href="<?= $b ?>/admin/activitylog" class="btn btn-quaternary">View activity log</a>
  </div>
</div>
