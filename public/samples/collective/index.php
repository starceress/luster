<?php
/**
 * Sample collective — home page.
 *
 * SETUP
 *   1. Update $_embedPath to the absolute server path of Luster's
 *      public/embed/ directory.
 *   2. Fill in $_siteName, $_siteTagline, $_ownerName, $_ownerEmail below.
 */

$_embedPath = dirname(__DIR__, 3) . '/public/embed';   // ← adjust if needed

$_siteName    = 'Collective Name';          // ← your collective name
$_siteTagline = 'A fanlisting collective';  // ← your tagline
$_ownerName   = 'Your Name';               // ← your name
$_ownerEmail  = 'you@example.com';         // ← your email address
$_navActive   = 'home';
$_pageTitle   = 'Home';

require 'header.php';
?>

  <h1 class="page-heading">Welcome</h1>

  <p class="text-muted">
    Welcome to <?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?>.
    Edit this text to introduce your collective.
    Browse the owned listings, see which listings I've joined,
    or check out my affiliates.
  </p>

  <?php
  // Collective stats block
  $luster_cache_ttl = 300;   // cache for 5 minutes (0 = disabled)
  $luster_count     = 3;     // newest/random listings to fetch
  $luster_return    = true;
  $statsHtml = require $_embedPath . '/collective-stats.php';
  if ($statsHtml) echo $statsHtml;
  ?>

  <p class="form-actions" style="margin-top: 1.25rem">
    <a class="btn" href="owned.php">Browse listings</a>
    <a class="back-link" href="joined.php">Joined listings &rarr;</a>
  </p>

  <?php
  // Collective updates feed — requires the Updates add-on (blank when not installed)
  $luster_show_cats = true;
  $luster_return    = true;
  $updatesHtml = require $_embedPath . '/collective-updates.php';
  if ($updatesHtml): ?>
    <p class="section-heading">Updates</p>
    <?= $updatesHtml ?>
  <?php endif; ?>

<?php require 'footer.php'; ?>
