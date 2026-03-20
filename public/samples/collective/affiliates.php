<?php
/**
 * Sample collective — site-wide affiliates.
 * See index.php for setup instructions.
 *
 * Uses $luster_fanlisting_id = 0 to show collective (site-wide) affiliates —
 * those with no specific listing assigned.
 * Change to a real listing ID to show that listing's affiliates instead.
 */

$_embedPath = dirname(__DIR__, 3) . '/public/embed';

$_siteName  = 'Collective Name';
$_navActive = 'affiliates';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?> &mdash; Affiliates</title>
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
      <a href="updates.php">Updates</a>
      <a href="affiliates.php" class="active">Affiliates</a>
    </nav>
  </div>
</header>

<main class="fl-site-main">
<?php
$luster_fanlisting_id = 0;   // 0 = collective/site-wide affiliates
$luster_per_page      = 50;
$luster_return        = true;
echo require $_embedPath . '/affiliates.php';
?>
</main>

<footer class="fl-site-footer">
  <p>Powered by <a href="https://github.com/sjlabels/luster">Luster</a></p>
</footer>

</body>
</html>
