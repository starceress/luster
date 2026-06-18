<?php
use Luster\Core\View;
/** @var string $base_url */
/** @var string $csrf_token */
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
    <div class="wizard-step-label" data-step-sub="Pick an import source">Source</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">2</div>
    <div class="wizard-step-label" data-step-sub="Connect or upload data">Connect</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">3</div>
    <div class="wizard-step-label" data-step-sub="Choose what to import">Options</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">4</div>
    <div class="wizard-step-label" data-step-sub="Review the dry run">Preview</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">5</div>
    <div class="wizard-step-label" data-step-sub="Results and summary">Done</div>
  </div>
</div>

<div class="callout callout-info"><?= View::icon('notes') ?> <strong>Only moving members into one existing listing?</strong> <a href="<?= $b ?>/admin/import-members" class="link">Use the members-only importer instead.</a></div>

<div class="stats-grid">

  <?php if ($import_show_enthusiast ?? true): ?>
  <a href="<?= $b ?>/admin/import/enthusiast/connect" class="import-source-card">
    <div class="import-source-header import-source-header--1">
      <span class="pc-head-title">Enthusiast</span>
    </div>

    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Owned listings</li>
      <li><?= View::icon('check') ?> Joined listings</li>
      <li><?= View::icon('check') ?> Members</li>
      <li><?= View::icon('check') ?> Assigned categories</li>
      <li><?= View::icon('check') ?> Affiliates</li>
      <li><?= View::icon('check') ?> Per-listing settings</li>
      <li><?= View::icon('check') ?> Other saved templates</li>
    </ul>
    <div class="import-source-note">
      If Enthusiast shares Luster's database, Luster can already read the member tables.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

  <?php if ($import_show_listing_admin ?? true): ?>
  <a href="<?= $b ?>/admin/import/listing-admin/connect" class="import-source-card">
    <div class="import-source-header import-source-header--2">
      <span class="pc-head-title">Listing Admin</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Owned listings</li>
      <li><?= View::icon('check') ?> Joined listings</li>
      <li><?= View::icon('check') ?> Members</li>
      <li><?= View::icon('check') ?> Assigned categories</li>
      <li><?= View::icon('check') ?> Affiliates</li>
      <li><?= View::icon('check') ?> Updates <code>Needs Updates Add-On</code></li>
      <li><?= View::icon('check') ?> Wishlist <code>Needs Wishlist Add-On</code></li>
      <li><?= View::icon('check') ?> KIM List <code>Needs KIM Add-On</code></li>
    </ul>
    <div class="import-source-note">
      Listing Admin's shared tables are split into Luster's per-listing structure.
      <span class="import-source-arrow">&rarr;</span>
    </div>
  </a>
  <?php endif; ?>


  <?php if ($import_show_bellabuffs ?? true): ?>
  <a href="<?= $b ?>/admin/import/bellabuffs/connect" class="import-source-card">
    <div class="import-source-header import-source-header--5">
      <span class="pc-head-title">BellaBuffs</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Members</li>
      <li><?= View::icon('check') ?> Affiliates</li>
      <li><?= View::icon('check') ?> Codes </li>
    </ul>
    <div class="import-source-note">
      Reads BellaBuffs text files and saves the data into Luster.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

  <?php if ($import_show_fanatically ?? true): ?>
  <a href="<?= $b ?>/admin/import/fanatically/connect" class="import-source-card">
    <div class="import-source-header import-source-header--6">
      <span class="pc-head-title">Fanatically</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Owned listings</li>
      <li><?= View::icon('check') ?> Joined listings</li>
      <li><?= View::icon('check') ?> Members</li>
      <li><?= View::icon('check') ?> Assigned categories</li>
      <li><?= View::icon('check') ?> Affiliates</li>
      <li><?= View::icon('check') ?> Codes (fan graphics)</li>
      <li><?= View::icon('check') ?> Wishlist <code>Needs Wishlist Add-On</code></li>
      <li><?= View::icon('check') ?> KIM List <code>Needs KIM Add-On</code></li>
      <li><?= View::icon('check') ?> Adoptions <code>Needs Adoption Add-On</code></li>
    </ul>
    <div class="import-source-note">
      <span>SMTP credentials and API keys are never imported.</span>
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

  <?php if ($import_show_codesort ?? true): ?>
  <a href="<?= $b ?>/admin/import/codesort/connect" class="import-source-card">
    <div class="import-source-header import-source-header--4">
      <span class="pc-head-title">CodeSort</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Codes, code categories, sizes & donors</li>
      <li><?= View::icon('check') ?> Optional image file migration</li>
    </ul>
    <div class="import-source-note">
      Default installs match codes by listing ID. Custom-prefix installs match by listing title.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

  <?php if ($import_show_fanupdate ?? true): ?>
  <a href="<?= $b ?>/admin/import/fanupdate/connect" class="import-source-card">
    <div class="import-source-header import-source-header--3">
      <span class="pc-head-title">FanUpdate</span>
      <span class="badge badge-pending">Beta</span>
    </div>
    <ul class="import-source-list">
      <li><code>Needs Updates Add-On</code></li>
      <li><?= View::icon('check') ?> Blog entries</li>
      <li><?= View::icon('check') ?> Per-fanlisting assignment, matched by title</li>
    </ul>
    <div class="import-source-note">
      Only blog entries are imported; other features (e.g. news, events) are not imported.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

</div>
</div>
