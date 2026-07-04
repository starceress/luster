<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin bulk email preview page. Shows a confirmation screen before sending a
 bulk email, displaying recipient count, subject, body preview, and a sample
 of recipient addresses.
*/

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url ?? ''), '/');
?>

<div class="card card--gradient">
  <div class="panel-header">
    <div class="panel-header-icon"><?= View::icon('send') ?></div>
    <div class="panel-header-meta">
      <div class="panel-header-title">Confirm Send</div>
      <div class="panel-header-sub">Review the recipient count and email content before sending this bulk message.</div>
    </div>
  </div>

  <div class="db-info-grid summary-grid">
    <div>
      <div class="field-label summary-label">Send to</div>
      <div><?= View::e($listing_title) ?></div>
    </div>
    <div>
      <div class="field-label summary-label">Recipients</div>
      <div><strong><?= (int) $total ?></strong> unique address<?= $total !== 1 ? 'es' : '' ?></div>
    </div>
    <div class="summary-span-full">
      <div class="field-label summary-label">Subject</div>
      <div><?= View::e($subject) ?></div>
    </div>
  </div>

  <?php if (!empty($sample)): ?>
  <div class="summary-stack">
    <div class="field-label summary-label">Sample recipients</div>
    <ul class="summary-list">
      <?php foreach ($sample as $addr): ?>
        <li><?= View::e($addr) ?></li>
      <?php endforeach; ?>
      <?php if ($total > count($sample)): ?>
        <li class="summary-list-more">
          &hellip; and <?= $total - count($sample) ?> more
        </li>
      <?php endif; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($total === 0): ?>
    <div class="alert alert-error">
      No approved recipients found for this listing. Nothing will be sent.
    </div>
  <?php endif; ?>

  <details class="summary-preview">
    <summary>Preview message body</summary>
    <pre><?= htmlspecialchars($body, ENT_QUOTES, 'UTF-8') ?></pre>
  </details>

  <div class="composer-footer">
    <?php if ($total > 0): ?>
    <form method="POST" action="<?= $b ?>/admin/emails/send" id="send-form">
      <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="listing_id"  value="<?= (int) $listing_id ?>">
      <input type="hidden" name="subject"     value="<?= View::e($subject) ?>">
      <input type="hidden" name="body"        value="<?= htmlspecialchars($body, ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" class="btn btn-primary" data-once-submit="Sending...">
        Send to <?= (int) $total ?> recipient<?= $total !== 1 ? 's' : '' ?>
      </button>
    </form>
    <?php endif; ?>
    <a href="<?= $b ?>/admin/emails?tab=bulk" class="btn btn-quaternary">
      &larr; Back to compose
    </a>
  </div>
</div>
