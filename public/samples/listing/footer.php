<?php
/**
 * footer.php — shared page footer.
 *
 * Set these variables before including header.php (they flow through to here):
 *   string $_siteName   — listing name
 *   string $_ownerName  — your name (linked to email)
 *   string $_ownerEmail — your contact email address
 */

$_e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>

</main>

<hr class="footer-divider">
<footer>
  <?= $_e($_siteName) ?> &copy;
  <a href="mailto:<?= $_e($_ownerEmail) ?>"><?= $_e($_ownerName) ?></a>.
  Powered by <a href="https://luster.starceress.net/">Luster</a>.
</footer>

</body>
</html>
