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

<div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
  <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem">Script</div>
  <div class="sysinfo-grid">
    <span class="sysinfo-label">Name</span>
    <span class="sysinfo-value"><?= \Luster\Core\View::e($script_name) ?></span>

    <span class="sysinfo-label">Version</span>
    <span class="sysinfo-value"><?= \Luster\Core\View::e($script_version) ?></span>
  </div>
</div>

<div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
  <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem">Server</div>
  <div class="sysinfo-grid">
    <span class="sysinfo-label">PHP Version</span>
    <span class="sysinfo-value"><?= \Luster\Core\View::e($php_version) ?></span>

    <span class="sysinfo-label">Database</span>
    <span class="sysinfo-value"><?= \Luster\Core\View::e($db_version) ?></span>
  </div>
</div>

<div class="neb-card" style="padding:1.25rem 1.5rem">
  <div class="neb-card-header" style="margin:-1.25rem -1.5rem 1rem;padding:.75rem 1.5rem">Diagnostics</div>
  <div class="sysinfo-grid">
    <span class="sysinfo-label">Error Log</span>
    <span class="sysinfo-value">
      <?php if ($error_log_count > 0): ?>
        <span class="neb-badge badge-error"><?= (int) $error_log_count ?> entries</span>
        <a href="<?= $b ?>/admin/errorlog" class="neb-link" style="margin-left:.5rem;font-size:.8125rem">View &rarr;</a>
      <?php else: ?>
        <span class="neb-badge badge-inactive">0 entries</span>
      <?php endif; ?>
    </span>
  </div>
</div>
