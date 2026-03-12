<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Codes list — content fragment.
 *
 * Variables injected by embed/codes.php or a CodesController:
 *   $fanlisting_name  string
 *   $fanlisting_url   string    target URL for code links
 *   $codes            object[]  CodesRepo rows (filename, width, height)
 *   $uploads_url      string    base URL for /uploads/codes (no trailing slash)
 *   $join_url         string    URL of the join form (empty string if not applicable)
 *   $base_url         string
 */
?>
<div class="fl-wrap">
  <h2 class="fl-title">Codes for <?= View::e($fanlisting_name) ?></h2>

  <?php if ($join_url !== ''): ?>
    <p><a class="fl-link" href="<?= View::e($join_url) ?>">Join this fanlisting</a></p>
  <?php endif; ?>

  <?php if (empty($codes)): ?>
    <p class="fl-empty">No codes available yet.</p>
  <?php else: ?>
    <p class="fl-muted" style="font-size:.875rem">
      <?= count($codes) ?> code<?= count($codes) !== 1 ? 's' : '' ?> available.
      Right-click an image to save, or link directly.
    </p>

    <ul class="fl-code-list">
      <?php foreach ($codes as $code): ?>
        <li class="fl-code-item">
          <a class="fl-code-link"
             href="<?= View::e($fanlisting_url !== '' ? $fanlisting_url : '#') ?>"
             target="_blank" rel="noopener noreferrer">
            <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
                 alt="<?= View::e($fanlisting_name) ?> code"
                 <?php if ($code->width > 0): ?>width="<?= (int) $code->width ?>"<?php endif; ?>
                 <?php if ($code->height > 0): ?>height="<?= (int) $code->height ?>"<?php endif; ?>
                 loading="lazy">
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
