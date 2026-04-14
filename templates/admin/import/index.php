<?php
use Luster\Core\View;
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="wizard-steps">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress" style="width:0%"></div>
  <div class="wizard-step-node wizard-step-node--active">
    <div class="wizard-step-bubble">1</div>
    <div class="wizard-step-label">Choose Source</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">2</div>
    <div class="wizard-step-label">Configure</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">3</div>
    <div class="wizard-step-label">Preview</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">4</div>
    <div class="wizard-step-label">Import</div>
  </div>
</div>

<div class="callout">
  <?= View::icon('alert') ?>
  <span><strong>Images are not automatically migrated.</strong> Only database records are imported. You'll have the option to configure image migration on the next step.</span>
</div>

<div class="import-source-grid">

  <a href="<?= $b ?>/admin/import/enthusiast/configure" class="import-source-card import-source-card--enth">
    <div class="import-source-header import-source-header-en">
      <span class="pc-head-title">Enthusiast</span>
      <span class="badge badge-current">Supported</span>
    </div>
  
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Owned listings</li>
      <li><?= View::icon('check') ?> Joined listings</li>
      <li><?= View::icon('check') ?> Assigned categories</li>
      <li><?= View::icon('check') ?> Members</li>
      <li><?= View::icon('check') ?> Affiliates (collective &amp; per-listing)</li>
      <li><?= View::icon('check') ?> Per-listing settings &amp; templates</li>
      <li><?= View::icon('check') ?> Email templates</li>
    </ul>
    <div class="import-source-note">
      If Enthusiast is on the same database on luster, member data should already be readable.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>

  <a href="<?= $b ?>/admin/import/listing-admin/configure" class="import-source-card import-source-card--la">
    <div class="import-source-header import-source-header-la">
      <span class="pc-head-title">Listing Admin</span>
      <span class="badge badge-current">Supported</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Owned listings</li>
      <li><?= View::icon('check') ?> Joined listings</li>
      <li><?= View::icon('check') ?> Members (central &rarr; per-listing)</li>
      <li><?= View::icon('check') ?> Assigned Categories</li>
      <li><?= View::icon('check') ?> Affiliates (collective &amp; per-listing)</li>
      <li><?= View::icon('check') ?> Updates (requires Updates Feed add-on)</li>
    </ul>
    <div class="import-source-note">
      Listing Admin's centralized data is migrated into per=listing data within Luster.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>

  <a href="<?= $b ?>/admin/import/fanupdate/configure" class="import-source-card import-source-card--fu">
    <div class="import-source-header import-source-header-fu">
      <span class="pc-head-title">FanUpdate</span>
      <span class="badge badge-current">Supported</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Blog entries &rarr; Updates Feed</li>
      <li><?= View::icon('check') ?> Per-fanlisting assignment (matched by title)</li>
    </ul>
    <div class="import-source-note">
      Requires Updates Feed add-on to be installed.
      <span class="import-source-arrow badge badge-inactive">&#8594;</span>
    </div>
  </a>

  <a href="<?= $b ?>/admin/import/codesort/configure" class="import-source-card import-source-card--cs">
    <div class="import-source-header import-source-header-cs">
      <span class="pc-head-title">CodeSort</span>
      <span class="badge badge-current">Supported</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Code categories & sizes</li>
      <li><?= View::icon('check') ?> Donors (per-listing)</li>
      <li><?= View::icon('check') ?> Optional image file migration</li>
    </ul>
    <div class="import-source-note">
      Fanlistings are matched by title against CodeSort'&rsquo;'s registry.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>

</div>
