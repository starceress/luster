<?php
/**
 * Sample collective — joined listings.
 * See index.php for setup instructions.
 */

$_embedPath = dirname(__DIR__, 3) . '/public/embed';

$_siteName  = 'Collective Name';
$_navActive = 'joined';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?> &mdash; Joined Listings</title>
  <link rel="stylesheet" href="../../assets/default/style.css">
</head>
<body>

<header class="fl-site-header">
  <div class="fl-header-inner">
    <p class="fl-site-title"><a href="index.php"><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?></a></p>
    <nav class="fl-nav" aria-label="Collective navigation">
      <a href="index.php">Home</a>
      <a href="listings.php">Listings</a>
      <a href="joined.php" class="active">Joined</a>
      <a href="affiliates.php">Affiliates</a>
    </nav>
  </div>
</header>

<main class="fl-site-main">
<?php
/*
 * Renders joined listings with a category selector.
 *
 * Options (set before require):
 *   $luster_category  = '';     // '' = dropdown selector (reads $_GET['cat'])
 *                               // 'all' = all listings grouped by category heading
 *                               // 'Anime' = only show a specific category by name
 *   $luster_show_list = false;  // show categories as a bulleted link list vs dropdown
 */
$luster_category = '';     // change to 'all' to show everything grouped, no selector
$luster_return   = true;
echo require $_embedPath . '/joined.php';
?>
</main>

<footer class="fl-site-footer">
  <p>Powered by <a href="https://github.com/sjlabels/luster">Luster</a></p>
</footer>

</body>
</html>
