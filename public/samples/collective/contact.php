<?php
/**
 * Sample collective — contact form.
 *
 * SETUP
 *   Make sure "Collective contact form" is enabled in Admin → Settings → General.
 *   Optionally set $luster_contact_action if the form should POST to a
 *   different URL (e.g. when the embed is on an external page).
 */

$_lusterRoot  = dirname(__DIR__, 3);
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_navActive   = 'contact';
$_pageTitle   = 'Contact';
require __DIR__ . '/_setup.php';
require 'header.php';
?>

  <h1 class="page-heading">Contact</h1>

  <?php if (isset($_GET['sent'])): ?>
    <div class="notice notice--success">
      Your message has been sent. Thank you for getting in touch!
    </div>
    <a class="back-link" href="index.php">&larr; Back to home</a>
  <?php else: ?>
    <?php
    $luster_return = true;
    echo require $_embedPath . '/collective-contact.php';
    ?>
  <?php endif; ?>

<?php require 'footer.php'; ?>
