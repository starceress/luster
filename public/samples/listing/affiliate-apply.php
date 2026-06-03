<?php
/**
 * Sample listing — affiliate application form.
 *
 * SETUP
 *   In Admin → Listings → Edit → Affiliates, enable affiliate applications and set:
 *     Application form URL:  https://yourdomain.com/fanlisting/affiliate-apply.php
 *     After-submit redirect: https://yourdomain.com/fanlisting/affiliate-apply.php
 */

$page_title = 'Affiliate Application';
require 'header.php';
?>

  <h1>Affiliate Application</h1>

  <?php
  $luster_fanlisting_id = $listing_id;
  require $luster_root . '/public/embed/affiliate-apply.php';
  ?>

<?php require 'footer.php'; ?>
