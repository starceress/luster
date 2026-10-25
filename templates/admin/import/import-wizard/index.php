<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import wizard step 1 -- source selection. Displays cards for each supported
 import source (Enthusiast, Listing Admin, FanUpdate, CodeSort, BellaBuffs,
 Fanatically) so the admin can choose which legacy script to import data from.
*/
use Luster\Core\View;
$b = rtrim(View::e($base_url), '/');
?>

<div class="toolbar import-wizard-toolbar">
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/import/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?>Import Settings</a>
  </div>
</div>

<div class="import-wizard import-wizard--start">

<div class="wizard-steps">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress wizard-steps-progress--start"></div>
  <div class="wizard-step-node wizard-step-node--active">
    <div class="wizard-step-bubble">1</div>
    <div class="wizard-step-label" data-step-sub="Pick a source">Source</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">2</div>
    <div class="wizard-step-label" data-step-sub="Database or upload">Connect</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">3</div>
    <div class="wizard-step-label" data-step-sub="Choose what to import">Options</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">4</div>
    <div class="wizard-step-label" data-step-sub="Review your umport">Preview</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">5</div>
    <div class="wizard-step-label" data-step-sub="Results and summary">Done</div>
  </div>
</div>

<div class="callout callout-info"><?= View::icon('notes') ?> <strong>Only moving members into one existing listing?</strong> <a href="<?= $b ?>/admin/import-members" class="link">Use the Members-Only Importer instead.</a></div>

<div class="stats-grid">

  <?php if ($import_show_enthusiast ?? true): ?>
  <a href="<?= $b ?>/admin/import/enthusiast/connect" class="import-source-card">
    <div class="import-source-header" style="color:var(--card-border-1)">
      <span class="panel-header-title">Enthusiast</span>
    </div>

    <ul class="star-list">
      <li>Owned & Joined Listings</li>
      <li>Members</li>
      <li>Assigned Categories</li>
      <li>Affiliates</li>
      <li>Per-Listing settings</li>
      <li>Email Templates</li>
      <li>Other Saved Templates</li>
    </ul>
  </a>
  <?php endif; ?>

  <?php if ($import_show_listing_admin ?? true): ?>
  <a href="<?= $b ?>/admin/import/listing-admin/connect" class="import-source-card">
    <div class="import-source-header" style="color:var(--card-border-2)">
      <span class="panel-header-title">Listing Admin</span>
    </div>
    <ul class="star-list">
      <li>Owned & Joined Listings</li>
      <li>Joined listings</li>
      <li>Members</li>
      <li>Assigned Categories</li>
      <li>Affiliates</li>
      <li>News Feed</li>
      <li>Wishlist</li>
      <li>KIM List</li>
      <li>Join Rules</li>
    </ul>
  </a>
  <?php endif; ?>


  <?php if ($import_show_bellabuffs ?? true): ?>
  <a href="<?= $b ?>/admin/import/bellabuffs/connect" class="import-source-card">
    <div class="import-source-header" style="color:var(--card-border-3)">
      <span class="panel-header-title">BellaBuffs</span>
    </div>
    <ul class="star-list">
      <li>Members</li>
      <li>Affiliates</li>
      <li>Codes</li>
    </ul>
  </a>
  <?php endif; ?>

  <?php if ($import_show_fanatically ?? true): ?>
  <a href="<?= $b ?>/admin/import/fanatically/connect" class="import-source-card">
    <div class="import-source-header" style="color:var(--card-border-1)">
      <span class="panel-header-title">Fanatically</span>
    </div>
    <ul class="star-list">
      <li>Owned & Joined Listings</li>
      <li>Members</li>
      <li>Assigned Categories</li>
      <li>Affiliates</li>
      <li>Codes</li>
      <li>Wishlist</li>
      <li>KIM List</li>
      <li>Adoptions</li>
      <li>Site Settings</li>
    </ul>
  </a>
  <?php endif; ?>

  <?php if ($import_show_codesort ?? true): ?>
  <a href="<?= $b ?>/admin/import/codesort/connect" class="import-source-card">
    <div class="import-source-header" style="color:var(--card-border-4)">
      <span class="panel-header-title">CodeSort</span>
    </div>
    <ul class="star-list">
      <li>Codes</li>
      <li>Assigned Categories, Sizes & Donors</li>
    </ul>
  </a>
  <?php endif; ?>

  <?php if ($import_show_fanupdate ?? true): ?>
  <a href="<?= $b ?>/admin/import/fanupdate/connect" class="import-source-card">
    <div class="import-source-header" style="color:var(--card-border-3)">
      <span class="panel-header-title">FanUpdate</span>
    </div>
    <ul class="star-list">
      <li>Blog Entries</li>
      <li>Per-Fanlisting Assignments, matched by title</li>
    </ul>
  </a>
  <?php endif; ?>

</div>
</div>
