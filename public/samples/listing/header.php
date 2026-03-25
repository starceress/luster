<?php
/**
 * header.php — shared page header.
 *
 * Set these variables BEFORE including this file:
 *   string $_siteName   — listing name (loaded from DB)
 *   string $_navActive  — key matching one of the nav entries below
 *   string $_pageTitle  — short page title shown in <title> tag
 */

$_e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$_fullTitle = isset($_pageTitle)
    ? $_e($_siteName) . ' &mdash; ' . $_e($_pageTitle)
    : $_e($_siteName);

$_navLinks = [
    'home'       => ['href' => 'index.php',       'label' => 'Home'],
    'join'       => ['href' => 'join.php',         'label' => 'Join'],
    'update'     => ['href' => 'update.php',       'label' => 'Update'],
    'remove'     => ['href' => 'remove.php',       'label' => 'Remove'],
    'members'    => ['href' => 'members.php',      'label' => 'Members'],
    'codes'      => ['href' => 'codes.php',        'label' => 'Codes'],
    'affiliates' => ['href' => 'affiliates.php',   'label' => 'Affiliates'],
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
  <nav aria-label="Listing navigation">
    <?php foreach ($_navLinks as $key => $link): ?>
      <a href="<?= $link['href'] ?>"<?= ($_navActive === $key ? ' class="active"' : '') ?>>
        <?= $link['label'] ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <hr class="divider">
</header>

<main class="main">
