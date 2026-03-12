<?php
/**
 * Sample fanlisting — join form.
 * See index.php for setup instructions.
 *
 * REDIRECT SETUP
 *   In Admin → Fanlisting → Edit → Embed, set:
 *     Form URL — Join:      https://yourdomain.com/fanlisting/join.php
 *     Redirect after Join:  https://yourdomain.com/fanlisting/join.php?joined=1
 *   (adjust URLs to match where you host these files)
 */

const FANLISTING_ID = 1;   // ← your fanlisting ID
$_embedPath = dirname(__DIR__, 3) . '/public/embed';

$_siteName  = 'Fanlisting Name';
$_navActive = 'join';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?> &mdash; Join</title>
  <link rel="stylesheet" href="../../assets/default/style.css">
</head>
<body>

<header class="fl-site-header">
  <div class="fl-header-inner">
    <p class="fl-site-title"><a href="index.php"><?= htmlspecialchars($_siteName, ENT_QUOTES, 'UTF-8') ?></a></p>
    <nav class="fl-nav" aria-label="Fanlisting navigation">
      <a href="index.php">Home</a>
      <a href="members.php">Members</a>
      <a href="join.php" class="active">Join</a>
      <a href="update.php">Update</a>
      <a href="remove.php">Remove</a>
    </nav>
  </div>
</header>

<main class="fl-site-main">
<?php
if (isset($_GET['joined'])) {
    echo '<div class="fl-wrap"><div class="fl-success">Thank you for joining! Your membership may need to be approved before it appears in the list.</div></div>';
} else {
    $luster_fanlisting_id = FANLISTING_ID;
    $luster_return        = true;
    echo require $_embedPath . '/join.php';
}
?>
</main>

<footer class="fl-site-footer">
  <p>Powered by <a href="https://github.com/sjlabels/luster">Luster</a></p>
</footer>

</body>
</html>
