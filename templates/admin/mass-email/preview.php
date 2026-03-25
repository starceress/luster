<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var int    $listing_id
 * @var string $listing_name
 * @var string $subject
 * @var string $body
 * @var int    $total          recipient count
 * @var array  $sample         up to 5 email addresses
 * @var string $base_url
 * @var string $csrf_token
 */

$b = rtrim(View::e($base_url ?? ''), '/');
?>

<div class="neb-card" style="padding:1.75rem 2rem">
  <h2 class="card-title" style="margin-bottom:1rem">Confirm send</h2>

  <div class="db-info-grid" style="margin-bottom:1.25rem">
    <div>
      <div class="form-label" style="margin-bottom:.25rem">Send to</div>
      <div><?= View::e($listing_name) ?></div>
    </div>
    <div>
      <div class="form-label" style="margin-bottom:.25rem">Recipients</div>
      <div><strong><?= (int) $total ?></strong> unique address<?= $total !== 1 ? 'es' : '' ?></div>
    </div>
    <div style="grid-column:1/-1">
      <div class="form-label" style="margin-bottom:.25rem">Subject</div>
      <div><?= View::e($subject) ?></div>
    </div>
  </div>

  <?php if (!empty($sample)): ?>
  <div style="margin-bottom:1.25rem">
    <div class="form-label" style="margin-bottom:.5rem">Sample recipients</div>
    <ul style="margin:0;padding-left:1.25rem;color:var(--text-muted);font-size:.875rem">
      <?php foreach ($sample as $addr): ?>
        <li><?= View::e($addr) ?></li>
      <?php endforeach; ?>
      <?php if ($total > count($sample)): ?>
        <li style="list-style:none;margin-top:.25rem">
          &hellip; and <?= $total - count($sample) ?> more
        </li>
      <?php endif; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($total === 0): ?>
    <div class="alert alert-error" style="margin-bottom:1rem">
      No approved recipients found for this listing. Nothing will be sent.
    </div>
  <?php endif; ?>

  <details style="margin-bottom:1.25rem">
    <summary style="cursor:pointer;color:var(--text-muted);font-size:.875rem;user-select:none">
      Preview message body
    </summary>
    <pre style="margin:.75rem 0 0;padding:.75rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;white-space:pre-wrap;font-size:.8rem;color:var(--text);overflow-x:auto"><?= htmlspecialchars($body, ENT_QUOTES, 'UTF-8') ?></pre>
  </details>

  <div class="btn-row">
    <?php if ($total > 0): ?>
    <form method="POST" action="<?= $b ?>/admin/emails/send" id="send-form">
      <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="listing_id"  value="<?= (int) $listing_id ?>">
      <input type="hidden" name="subject"     value="<?= View::e($subject) ?>">
      <input type="hidden" name="body"        value="<?= htmlspecialchars($body, ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" class="btn btn-primary" data-once-submit="Sending…">
        Send to <?= (int) $total ?> recipient<?= $total !== 1 ? 's' : '' ?>
      </button>
    </form>
    <?php endif; ?>
    <a href="<?= $b ?>/admin/emails?tab=bulk" class="btn btn-tertiary">
      &larr; Back to compose
    </a>
  </div>
</div>
