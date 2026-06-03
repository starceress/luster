<?php
/**
 * Sample listing — remove membership form.
 *
 * SETUP
 *   In Admin → Listings → Edit → Embed, set:
 *     Form URL — Remove:     https://yourdomain.com/fanlisting/remove.php
 *     Redirect after Remove: https://yourdomain.com/fanlisting/remove.php
 */

$page_title = 'Remove';
require 'header.php';
?>

  <h1>Remove</h1>

  <?php
  $luster_fanlisting_id     = $listing_id;
  $luster_lost_password_url = 'reset.php';
  require $luster_root . '/public/embed/remove.php';
  ?>

<?php require 'footer.php'; ?>
