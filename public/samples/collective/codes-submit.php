<?php
/**
 * Sample collective — fan code submission.
 */

$_embedPath = dirname(__DIR__, 3) . '/public/embed';

$_siteName    = 'Collective Name';
$_siteTagline = 'A fanlisting collective';
$_ownerName   = 'Your Name';
$_ownerEmail  = 'you@example.com';
$_navActive   = 'codes';
$_pageTitle   = 'Donate a Code';

require 'header.php';
?>

  <h1 class="page-heading">Donate a code</h1>

  <p class="text-muted">Made a button or banner for the collective? Submit it here and it may be added to the codes page.</p>

  <?php
  $luster_fanlisting_id = 0;
  $luster_return        = true;
  echo require $_embedPath . '/codes-submit.php';
  ?>

<?php require 'footer.php'; ?>
