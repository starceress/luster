<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sample header for a fanlisting collective. Loads configuration from Luster,
 reads the collective name from config, and outputs the opening HTML with a
 navigation bar and demo-site switcher. Every other sample collective page
 includes this file at the top. Customize the HTML, navigation links, and
 styling to match your own design.
*/

// The only line you need to change.
$site_tagline = 'A fanlisting collective';

// Everything else comes from your Luster installation automatically.
$luster_root = dirname(__DIR__, 3);
$_cfg        = require $luster_root . '/config/app.php';
$site_name   = $_cfg['site_name'] ?? 'My Collective';
$owner_name  = $_cfg['owner_name']  ?? '';
$owner_email = $_cfg['owner_email'] ?? '';
unset($_cfg);

$e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$current_page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$nav_items = [
    'index.php'      => 'Home',
    'owned.php'      => 'Owned',
    'joined.php'     => 'Joined',
    'kim.php'        => 'Keep In Mind',
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
<body data-sample="collective">

<div class="site-shell">
  <div class="demo-topbar">
    <div class="demo-switcher" aria-label="Choose sample site">
      <a class="demo-switch" href="../listing/index.php">Fanlisting</a>
      <a class="demo-switch active" href="../collective/index.php">Collective</a>
    </div>
  </div>

  <div class="sample-site">
    <header class="sample-masthead">
      <div class="sample-logo-glow" aria-hidden="true"></div>
      <p class="sample-title">
        <a href="index.php"><?= $e($site_name) ?></a>
      </p>
      <?php if (!empty($site_tagline)): ?>
        <p class="sample-tagline"><?= $e($site_tagline) ?></p>
      <?php endif; ?>
    </header>

    <nav class="sample-nav" aria-label="Collective navigation">
      <?php foreach ($nav_items as $href => $label): ?>
        <a href="<?= $e($href) ?>"<?= $current_page === $href ? ' class="active"' : '' ?>><?= $e($label) ?></a>
      <?php endforeach; ?>
    </nav>

    <main class="sample-card">
      <div class="sample-card-inner">
