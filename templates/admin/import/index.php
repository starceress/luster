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

<div class="import-source-grid">

  <?php if ($import_show_enthusiast ?? true): ?>
  <a href="<?= $b ?>/admin/import/enthusiast/connect" class="import-source-card import-source-card--enth">
    <div class="import-source-header import-source-header--1">
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
    <div class="import-source-prereqs">
      <div class="import-prereq-label">What you&rsquo;ll need</div>
      <div class="import-prereq-badges">
        <span class="badge badge-info">DB credentials</span>
        <span class="badge badge-inactive">or SQL dump</span>
      </div>
      <div class="import-prereq-note">Same-database installs: leave credentials blank &mdash; member tables are already readable.</div>
    </div>
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
      <li><?= View::icon('check') ?> Members (central &rarr; per-listing)</li>
      <li><?= View::icon('check') ?> Assigned Categories</li>
      <li><?= View::icon('check') ?> Affiliates (collective &amp; per-listing)</li>
      <li><?= View::icon('check') ?> Updates (requires Updates Feed add-on)</li>
    </ul>
    <div class="import-source-prereqs">
      <div class="import-prereq-label">What you&rsquo;ll need</div>
      <div class="import-prereq-badges">
        <span class="badge badge-info">DB credentials</span>
        <span class="badge badge-inactive">or SQL dump</span>
      </div>
      <div class="import-prereq-note">Import listings before members &mdash; member tables are created from listing data.</div>
    </div>
    <div class="import-source-note">
      Listing Admin&rsquo;s centralized data is migrated into per-listing data within Luster.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

  <?php if ($import_show_fanupdate ?? true): ?>
  <a href="<?= $b ?>/admin/import/fanupdate/connect" class="import-source-card import-source-card--fu">
    <div class="import-source-header import-source-header--3">
      <span class="pc-head-title">FanUpdate</span>
      <span class="badge badge-current">Supported</span>
    </div>
    <ul class="import-source-list">
      <li><?= View::icon('check') ?> Blog entries &rarr; Updates Feed</li>
      <li><?= View::icon('check') ?> Per-fanlisting assignment (matched by title)</li>
    </ul>
    <div class="import-source-prereqs">
      <div class="import-prereq-label">What you&rsquo;ll need</div>
      <div class="import-prereq-badges">
        <span class="badge badge-info">DB credentials</span>
        <span class="badge badge-inactive">or SQL dump</span>
        <span class="badge badge-pending">Updates Feed add-on</span>
      </div>
    </div>
    <div class="import-source-note">
      Requires Updates Feed add-on to be installed.
      <span class="import-source-arrow badge badge-inactive">&#8594;</span>
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
      <li><?= View::icon('check') ?> Code categories &amp; sizes</li>
      <li><?= View::icon('check') ?> Donors (per-listing)</li>
      <li><?= View::icon('check') ?> Optional image file migration</li>
    </ul>
    <div class="import-source-prereqs">
      <div class="import-prereq-label">What you&rsquo;ll need</div>
      <div class="import-prereq-badges">
        <span class="badge badge-info">DB credentials</span>
        <span class="badge badge-inactive">or SQL dump</span>
      </div>
      <div class="import-prereq-note">Have the value of <code>images_folder</code> from <code>codes-config.php</code> ready if migrating images.</div>
    </div>
    <div class="import-source-note">
      Fanlistings are matched by title against CodeSort&rsquo;s registry.
      <span class="import-source-arrow">&#8594;</span>
    </div>
  </a>
  <?php endif; ?>

</div>

<p class="import-wizard-note">
  FanUpdate and CodeSort match existing Luster listings by title. Unmatched entries are imported as collective-level data or skipped with a warning when a listing match is required.
</p>

</div>
