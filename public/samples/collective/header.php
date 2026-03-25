<?php
/**
 * header.php — shared collective page header.
 *
 * Set these variables BEFORE including this file:
 *   string $_siteName    — collective name
 *   string $_siteTagline — short tagline shown below the name
 *   string $_navActive   — key matching one of the nav entries below
 *   string $_pageTitle   — short page title for the <title> tag
 */

$_e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$_fullTitle = isset($_pageTitle)
    ? $_e($_siteName) . ' &mdash; ' . $_e($_pageTitle)
    : $_e($_siteName);

$_navLinks = [
    'home'       => ['href' => 'index.php',      'label' => 'Home'],
    'owned'      => ['href' => 'owned.php',       'label' => 'Owned'],
    'joined'     => ['href' => 'joined.php',      'label' => 'Joined'],
    'codes'      => ['href' => 'codes.php',       'label' => 'Codes'],
    'affiliates' => ['href' => 'affiliates.php',  'label' => 'Affiliates'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $_fullTitle ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
  <p class="title">
    <a href="index.php"><?= $_e($_siteName) ?></a>
  </p>
  <?php if (!empty($_siteTagline)): ?>
    <p class="tagline"><?= $_e($_siteTagline) ?></p>
  <?php endif; ?>
  <nav aria-label="Collective navigation">
    <?php foreach ($_navLinks as $key => $link): ?>
      <a href="<?= $link['href'] ?>"<?= ($_navActive === $key ? ' class="active"' : '') ?>>
        <?= $link['label'] ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <hr class="divider">
</header>

<main>
