<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample code donation page for a fanlisting collective. Embeds the Luster
 collective code submission form so fans can upload a button or banner for the
 collective. Submitted codes go to a pending queue for admin approval. Customize
 the HTML to match your design.
*/

$page_title = 'Donate a Code';
require 'header.php';
?>

  <h1>Donate a code</h1>

  <p>Made a button or banner for the collective? Submit it here and it may be added to the codes page.</p>

  <?php require $luster_root . '/public/embed/collective-codes-submit.php'; ?>

<?php require 'footer.php'; ?>
