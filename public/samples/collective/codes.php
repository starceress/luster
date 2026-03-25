<?php
/**
 * Sample collective — collective-level codes.
 */

$_embedPath = dirname(__DIR__, 3) . '/public/embed';

$_siteName    = 'Collective Name';
$_siteTagline = 'A fanlisting collective';
$_ownerName   = 'Your Name';
$_ownerEmail  = 'you@example.com';
$_navActive   = 'codes';
$_pageTitle   = 'Codes';

require 'header.php';
?>

  <h1 class="page-heading">Codes</h1>

  <p class="text-muted">
    Collective codes made by fans. Right-click to save, then link back to
    <?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?>.
  </p>

  <?php
  // Collective codes use fanlisting_id = 0 (site-wide)
  $luster_fanlisting_id = 0;
  $luster_return        = true;
  echo require $_embedPath . '/codes.php';
  ?>

  <p style="margin-top: 1.25rem">
    <a class="back-link" href="codes-submit.php">Donate a code &rarr;</a>
  </p>

<?php require 'footer.php'; ?>
