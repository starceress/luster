<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample codes page for a fanlisting. Displays the fan-made graphics (buttons,
 banners, etc.) that visitors can save and use to link back to the listing. If
 code donations are enabled, a link to the submission page is shown. Customize
 the HTML to match your fanlisting's design.
*/

$page_title = 'Codes';
require 'header.php';
?>

  <h1>Codes</h1>

  <?php
  $_app = require $luster_root . '/bootstrap.php';
  $_settingsRepo = new \Luster\Repositories\ListingSettingsRepo($_app->getDatabase());
  $_embedSettings = $_settingsRepo->getEmbedSettings($listing_id);
  $_codesSubmitEnabled = $_embedSettings['codes_submit_enabled'] ?? true;
  ?>

  <p>Right-click to save, then link back to this listing.
  <?php if ($_codesSubmitEnabled): ?>
  <a class="back-link" href="codes-submit.php">Want to donate a code?</a>
  <?php endif; ?></p>

  <?php
  $luster_fanlisting_id  = $listing_id;
  $luster_append_submit  = false; // submission form is on its own codes-submit.php page
  require $luster_root . '/public/embed/codes.php';
  ?>

<?php require 'footer.php'; ?>
