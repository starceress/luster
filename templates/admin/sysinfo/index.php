<?php
/** @var string $php_version */
/** @var string $db_version */
/** @var string $script_name */
/** @var string $script_version */
/** @var int    $error_log_count */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>
<style>
  .info-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.25rem;margin-bottom:1.25rem; }
  .info-card h2 { font-size:.8125rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:1rem; }
  .info-grid { display:grid;grid-template-columns:180px 1fr;row-gap:.6rem; }
  .info-label { font-size:.8125rem;font-weight:600;color:#6b7280; }
  .info-value { font-size:.875rem;color:#111827; }
  .badge { display:inline-block;padding:.15rem .55rem;border-radius:9999px;font-size:.7rem;font-weight:600; }
  .badge-red { background:#fef2f2;color:#b91c1c; }
  .badge-grey { background:#f3f4f6;color:#6b7280; }
</style>

<h1 style="font-size:1.25rem;font-weight:700;color:#111827;margin-bottom:1.25rem">System Info</h1>

<div class="info-card">
  <h2>Script</h2>
  <div class="info-grid">
    <span class="info-label">Name</span>
    <span class="info-value"><?= \Luster\Core\View::e($script_name) ?></span>

    <span class="info-label">Version</span>
    <span class="info-value"><?= \Luster\Core\View::e($script_version) ?></span>
  </div>
</div>

<div class="info-card">
  <h2>Server</h2>
  <div class="info-grid">
    <span class="info-label">PHP Version</span>
    <span class="info-value"><?= \Luster\Core\View::e($php_version) ?></span>

    <span class="info-label">Database</span>
    <span class="info-value"><?= \Luster\Core\View::e($db_version) ?></span>
  </div>
</div>

<div class="info-card">
  <h2>Diagnostics</h2>
  <div class="info-grid">
    <span class="info-label">Error Log</span>
    <span class="info-value">
      <?php if ($error_log_count > 0): ?>
        <span class="badge badge-red"><?= (int) $error_log_count ?> entries</span>
        <a href="<?= $b ?>/admin/errorlog"
           style="font-size:.8125rem;color:#4f46e5;margin-left:.5rem;text-decoration:none">View &rarr;</a>
      <?php else: ?>
        <span class="badge badge-grey">0 entries</span>
      <?php endif; ?>
    </span>
  </div>
</div>
