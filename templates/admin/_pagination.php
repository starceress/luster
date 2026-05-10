<?php
/**
 * Shared admin pagination partial.
 *
 * Required variables (set before including):
 *   $pg_current  (int)      — current page number
 *   $pg_total    (int)      — total number of pages
 *   $pg_url      (callable) — fn(int $page): string — returns URL for that page
 *
 * Pattern: 1 page before current · current · 1 page after current
 *          with leading "1 …" and trailing "… last" when there is a gap.
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
