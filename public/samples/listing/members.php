<?php
/**
 * Sample listing — members list.
 *
 * Sort (?sort=name or ?sort=country) and pagination (?page=N) work automatically
 * and always link back to this page.
 */

$page_title = 'Members';
require 'header.php';
?>

  <h1>Members</h1>

  <?php
  $luster_fanlisting_id = 2;
  require $luster_root . '/public/embed/members.php';
  ?>

<?php require 'footer.php'; ?>
