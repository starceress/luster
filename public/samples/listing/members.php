<?php
/**
 * Sample listing — members list.
 *
 * Sort (?sort=name or ?sort=country) and pagination (?page=N) work automatically.
 * $luster_members_base tells Luster which URL to use for those links.
 */

$page_title = 'Members';
require 'header.php';
?>

  <h1>Members</h1>

  <?php
  $luster_fanlisting_id = 1;
  $luster_members_base  = strtok($_SERVER['REQUEST_URI'], '?');
  require $luster_root . '/public/embed/members.php';
  ?>

<?php require 'footer.php'; ?>
