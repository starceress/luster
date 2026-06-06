<?php
/**
 * Sample listing — update membership form.
 *
 * SETUP
 *   In Admin → Listings → Edit → Embed, set:
 *     Form URL — Update:     https://yourdomain.com/fanlisting/update.php
 *     Redirect after Update: https://yourdomain.com/fanlisting/update.php
 */

$page_title = 'Update';
require 'header.php';
?>

  <h1>Update</h1>

  <?php
  $luster_fanlisting_id     = 1;
  $luster_lost_password_url = 'reset.php';
  require $luster_root . '/public/embed/update.php';
  ?>

<?php require 'footer.php'; ?>
