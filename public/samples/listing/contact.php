<?php
/**
 * Sample listing — contact form.
 *
 * SETUP
 *   Set LISTING_ID to your listing's ID in the admin panel.
 *   Optionally set $luster_contact_action if the form should POST to a
 *   different URL (e.g. when the embed is on an external page).
 */

const LISTING_ID = 1;           // ← your listing ID
$_lusterRoot = dirname(__DIR__, 3);
$_navActive  = 'contact';
$_pageTitle  = 'Contact';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1>Contact</h1>

  <?php if (isset($_GET['sent'])): ?>
    <div class="notice notice--success">
      Your message has been sent. Thank you for getting in touch!
    </div>
    <a class="back-link" href="index.php">&larr; Back to home</a>
  <?php else: ?>
    <?php
    $luster_fanlisting_id = LISTING_ID;
    $luster_return        = true;
    echo require $_embedPath . '/contact.php';
    ?>
  <?php endif; ?>

<?php require 'footer.php'; ?>
