<?php
use Luster\Core\View;
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<?php if ($error !== ''): ?>
<div class="callout callout-error" style="margin-bottom:1.25rem">
  <?= View::icon('alert') ?>
  <div><?= View::e($error) ?></div>
</div>
<?php endif; ?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Legacy Source Browser</div>
      <div class="pc-head-sub">
        Connect to an Enthusiast or Listing Admin database to browse its listings
        and member counts without importing anything.
      </div>
    </div>
  </div>

  <form method="POST" action="<?= $b ?>/admin/legacy-source/connect" class="pc-section">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="listing-field-row">
      <label class="listing-field-label">Source Type</label>
      <div>
        <label class="choice-item" style="margin-bottom:.5rem">
          <input type="radio" name="source" value="enthusiast" checked>
          <span class="choice-label">Enthusiast</span>
        </label>
        <label class="choice-item">
          <input type="radio" name="source" value="listing-admin">
          <span class="choice-label">Listing Admin</span>
        </label>
      </div>
    </div>

    <div class="listing-field-row">
      <label class="listing-field-label" for="ls-host">DB Host <span class="req">*</span></label>
      <div>
        <input id="ls-host" type="text" name="db_host" class="form" value="localhost"
               maxlength="255" placeholder="localhost">
      </div>
    </div>

    <div class="listing-field-row">
      <label class="listing-field-label" for="ls-name">Database Name <span class="req">*</span></label>
      <div>
        <input id="ls-name" type="text" name="db_name" class="form" maxlength="255">
      </div>
    </div>

    <div class="listing-field-row">
      <label class="listing-field-label" for="ls-user">DB User <span class="req">*</span></label>
      <div>
        <input id="ls-user" type="text" name="db_user" class="form" maxlength="255"
               autocomplete="username">
      </div>
    </div>

    <div class="listing-field-row">
      <label class="listing-field-label" for="ls-pass">DB Password</label>
      <div>
        <input id="ls-pass" type="password" name="db_pass" class="form" maxlength="255"
               autocomplete="current-password">
      </div>
    </div>

    <div class="listing-field-row">
      <label class="listing-field-label" for="ls-prefix">Table Prefix</label>
      <div>
        <input id="ls-prefix" type="text" name="db_prefix" class="form" maxlength="50"
               placeholder="Leave blank for standard tables">
        <p class="form-hint">Only needed for non-standard Enthusiast table prefix installs.</p>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Browse Source &rarr;</button>
    </div>
  </form>
</div>

<div class="callout callout-inline" style="margin-top:1rem">
  <?= View::icon('notes') ?>
  <div>
    This browser is <strong>read-only</strong> — it never writes to the source database.
    To import listings or members, use the
    <a href="<?= $b ?>/admin/import" class="link">Import Wizard</a>.
  </div>
</div>
