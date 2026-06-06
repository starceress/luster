<?php
// Edit these three lines.
$listing_id   = 1;               // your listing's ID (Admin -> Listings)
$site_name    = 'My Fanlisting'; // your listing's name
$site_subject = 'Subject Name';  // what your fanlisting is about

// Everything else comes from your Luster installation automatically.
$luster_root = dirname(__DIR__, 3);
$_cfg        = require $luster_root . '/config/app.php';
$owner_name  = $_cfg['owner_name']  ?? '';
$owner_email = $_cfg['owner_email'] ?? '';
unset($_cfg);

$e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$current_page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$nav_items = [
    'index.php'      => 'Home',
    'join.php'       => 'Join',
    'update.php'     => 'Update',
    'remove.php'     => 'Remove',
    'members.php'    => 'Members',
    'codes.php'      => 'Codes',
    'affiliates.php' => 'Affiliates',
    'contact.php'    => 'Contact',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($site_name) ?><?= isset($page_title) ? ' - ' . $e($page_title) : '' ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body data-sample="listing">

<div class="site-shell">
  <div class="demo-topbar">
    <div class="demo-switcher" aria-label="Choose sample site">
      <a class="demo-switch active" href="../listing/index.php">Fanlisting</a>
      <a class="demo-switch" href="../collective/index.php">Collective</a>
    </div>
  </div>

  <div class="sample-site">
    <header class="sample-masthead">
      <div class="sample-logo-glow" aria-hidden="true"></div>
      <p class="sample-title">
        <a href="index.php"><?= $e($site_name) ?></a>
      </p>
      <p class="sample-tagline">A sample fanlisting dedicated to <?= $e($site_subject) ?>.</p>
    </header>

    <nav class="sample-nav" aria-label="Listing navigation">
      <?php foreach ($nav_items as $href => $label): ?>
        <a href="<?= $e($href) ?>"<?= $current_page === $href ? ' class="active"' : '' ?>><?= $e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <main class="sample-card">
      <div class="sample-card-inner">
