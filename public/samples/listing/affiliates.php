<?php
const LISTING_ID = 1;   // ← your listing ID

$_embedPath  = dirname(__DIR__, 3) . '/public/embed';
$_lusterRoot = dirname(__DIR__, 3);

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);
require_once $_lusterRoot . '/vendor/autoload.php';

$_sampleApp = require $_lusterRoot . '/bootstrap.php';
$_sampleL   = (new \Luster\Repositories\ListingRepo(
    $_sampleApp->getDatabase(),
    $_sampleApp->getConfig(),
))->getById(LISTING_ID);

$_siteName   = $_sampleL?->name ?? 'Listing Name';
$_ownerName  = (string) ($_sampleApp->getConfig()->get('owner_name',  '') ?: 'Your Name');
$_ownerEmail = (string) ($_sampleApp->getConfig()->get('owner_email', '') ?: 'you@example.com');
$_navActive  = 'affiliates';
$_pageTitle  = 'Affiliates';

require 'header.php';
?>

  <h1>Affiliates</h1>

  <?php
  $luster_fanlisting_id   = LISTING_ID;
  $luster_affiliates_base = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '';
  $luster_return          = true;
  echo require $_embedPath . '/affiliates.php';
  ?>

<?php require 'footer.php'; ?>
