<?php
/**
 * Sample listing — members list.
 *
 * Sort (?sort=name or ?sort=country) and pagination (?page=N) work automatically.
 * $luster_members_base keeps those links pointing back to this page.
 */

const LISTING_ID = 1;           // ← your listing ID
$_lusterRoot = dirname(__DIR__, 3);
$_navActive  = 'members';
$_pageTitle  = 'Members';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1>Members</h1>

  <?php
  $luster_fanlisting_id = LISTING_ID;
  $luster_members_base  = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
  $luster_return        = true;
  echo require $_embedPath . '/members.php';
  ?>

<?php require 'footer.php'; ?>
