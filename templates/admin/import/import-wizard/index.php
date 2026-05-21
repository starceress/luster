<?php
use Luster\Core\View;
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="import-wizard">

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

<div class="stats-grid">

  <?php if ($import_show_enthusiast ?? true): ?>
  <a href="<?= $b ?>/admin/import/enthusiast/connect" class="import-source-card">
    <div class="import-source-header import-source-header--1">
      <span class="pc-head-title">Enthusiast</span>
      <span class="badge badge-current">Supported</span>
    </div>

    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Owned listings</li>
      <li><?= View::icon('check') ?> Joined listings</li>
      <li><?= View::icon('check') ?> Assigned categories
      <li><?= View::icon('check') ?> Members</li>
      <li><?= View::icon('check') ?> Affiliates</li>
      <li><?= View::icon('check') ?> Per-listing settings & email templates</li>
      <li><?= View::icon('check') ?> Other saved templates
    </ul>
    <div class="import-source-note">
      If Enthusiast is on the same database as Luster, member data is already accessible.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

  <?php if ($import_show_listing_admin ?? true): ?>
  <a href="<?= $b ?>/admin/import/listing-admin/connect" class="import-source-card import-source-card--la">
    <div class="import-source-header import-source-header--2">
      <span class="pc-head-title">Listing Admin</span>
      <span class="badge badge-current">Supported</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Owned listings</li>
      <li><?= View::icon('check') ?> Joined listings</li>
      <li><?= View::icon('check') ?> Members</li>
      <li><?= View::icon('check') ?> Assigned categories</li>
      <li><?= View::icon('check') ?> Affiliates</li>
      <li><?= View::icon('check') ?> Updates <span class="badge badge-info">Requires Updates add-on</span></li>
      <li><?= View::icon('check') ?> Wishlist <span class="badge badge-info-pink">Requires Wishlist add-on</span></li>
      <li><?= View::icon('check') ?> KIM List <span class="badge badge-info-gold">Requires KIM add-on</span></li>
    </ul>
    <div class="import-source-note">
      LA's centralized data is migrated into per-listing data within Luster.
      <span class="import-source-arrow">&rarr;</span>
    </div>
  </a>
  <?php endif; ?>


  <?php if ($import_show_codesort ?? true): ?>
  <a href="<?= $b ?>/admin/import/codesort/connect" class="import-source-card import-source-card--cs">
    <div class="import-source-header import-source-header--4">
      <span class="pc-head-title">CodeSort</span>
      <span class="badge badge-current">Supported</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Codes, code categories, sizes & donors</li>
      <li><?= View::icon('check') ?> Optional image file migration</li>
    </ul>

    <div class="import-source-note">
      Default installs match by listing ID. Custom-prefix installs match by title.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

  
  <?php if ($import_show_fanupdate ?? true): ?>
  <a href="<?= $b ?>/admin/import/fanupdate/connect" class="import-source-card import-source-card--fu">
    <div class="import-source-header import-source-header--3">
      <span class="pc-head-title">FanUpdate</span>
      <span class="badge badge-pending">Beta</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Blog entries</li>
      <li><?= View::icon('check') ?> Per-fanlisting assignment  matched by title</li>
    </ul>
    <div class="import-source-note">
      <span class="badge badge-info">Requires Updates add-on</span>
      <span class="import-source-arrow badge badge-inactive">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

  <?php if ($import_show_bellabuffs ?? true): ?>
  <a href="<?= $b ?>/admin/import/bellabuffs/connect" class="import-source-card import-source-card--bb">
    <div class="import-source-header import-source-header--5">
      <span class="pc-head-title">BellaBuffs</span>
      <span class="badge badge-current">Supported</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Members (approved &amp; pending)</li>
      <li><?= View::icon('check') ?> Affiliates <span class="badge badge-inactive" style="margin-left:.25rem">Server path only</span></li>
      <li><?= View::icon('check') ?> Buttons &rarr; Codes <span class="badge badge-inactive" style="margin-left:.25rem">Server path only</span></li>
    </ul>
    <div class="import-source-note">
      Reads flat files (<code>members.txt</code>, <code>affiliates.txt</code>, <code>buttons.txt</code>). No database required.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

</div>
</div>
