<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Shared pagination partial included by admin list pages. Renders a compact numbered
 page navigator with previous/next links and an ellipsis window around the current page.
 Expects $pg_current, $pg_total, and a $pg_url callable to be set before inclusion.
*/

if (($pg_total ?? 1) <= 1) return;

use Luster\Core\View;

$_pgStart        = max(1, $pg_current - 1);
$_pgEnd          = min($pg_total, $_pgStart + 2);
$_pgWindow       = range($_pgStart, $_pgEnd);
$_pgLeading      = $_pgStart > 2;
$_pgTrailing     = $_pgEnd < $pg_total;
$_pgTrailingDots = $_pgEnd < $pg_total - 1;
?>
<nav class="pg" aria-label="Pagination">

  <a href="<?= View::e($pg_url($pg_current - 1)) ?>"
     class="pg-link<?= $pg_current <= 1 ? ' disabled' : '' ?>"
     <?= $pg_current <= 1 ? 'aria-disabled="true"' : '' ?>>&lsaquo; Prev</a>

  <?php if ($_pgLeading): ?>
    <a href="<?= View::e($pg_url(1)) ?>" class="pg-link">1</a>
    <span class="pg-ellipsis">&hellip;</span>
  <?php endif; ?>

  <?php foreach ($_pgWindow as $_i): ?>
    <a href="<?= View::e($pg_url($_i)) ?>"
       class="pg-link<?= $_i === $pg_current ? ' active' : '' ?>"
       <?= $_i === $pg_current ? 'aria-current="page"' : '' ?>><?= $_i ?></a>
  <?php endforeach; ?>

  <?php if ($_pgTrailing): ?>
    <?php if ($_pgTrailingDots): ?><span class="pg-ellipsis">&hellip;</span><?php endif; ?>
    <a href="<?= View::e($pg_url($pg_total)) ?>" class="pg-link"><?= $pg_total ?></a>
  <?php endif; ?>

  <a href="<?= View::e($pg_url($pg_current + 1)) ?>"
     class="pg-link<?= $pg_current >= $pg_total ? ' disabled' : '' ?>"
     <?= $pg_current >= $pg_total ? 'aria-disabled="true"' : '' ?>>Next &rsaquo;</a>

</nav>
