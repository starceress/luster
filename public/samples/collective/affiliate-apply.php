<?php
/**
 * Sample collective — affiliate application form.
 *
 * SETUP
 *   Enable collective affiliate applications in Admin → Settings.
 */

$page_title = 'Affiliate Application';
require 'header.php';
?>

  <h1>Affiliate Application</h1>

  <?php
  $luster_fanlisting_id = 0;   // 0 = collective affiliate application
  require $luster_root . '/public/embed/affiliate-apply.php';
  ?>

<?php require 'footer.php'; ?>
