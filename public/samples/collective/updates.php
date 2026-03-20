<?php
/**
 * Sample collective — updates feed.
 * See index.php for setup instructions.
 *
 * Requires the Updates add-on to be installed.
 */

$_embedPath = dirname(__DIR__, 3) . '/public/embed';

$_siteName  = 'Collective Name';
$_navActive = 'updates';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?> &mdash; Updates</title>
  <link rel="stylesheet" href="../../assets/default/style.css">
</head>
<body>

<header class="fl-site-header">
  <div class="fl-header-inner">
    <p class="fl-site-title"><a href="index.php"><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?></a></p>
    <nav class="fl-nav" aria-label="Collective navigation">
      <a href="index.php">Home</a>
      <a href="listings.php">Listings</a>
      <a href="joined.php">Joined</a>
      <a href="updates.php" class="active">Updates</a>
      <a href="affiliates.php">Affiliates</a>
    </nav>
  </div>
</header>

<main class="fl-site-main">
<?php
/*
 * Renders the site-wide (collective) updates feed.
 *
 * Options (set before require):
 *   $luster_per_page  = 10;   // optional; default from config per_page
 *   $luster_show_cats = true; // optional bool; show category tags per entry
 */
$luster_show_cats = true;
$luster_return    = true;
echo require $_embedPath . '/collective-updates.php';
?>
</main>

<footer class="fl-site-footer">
  <p>Powered by <a href="https://github.com/sjlabels/luster">Luster</a></p>
</footer>

</body>
</html>
