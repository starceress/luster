<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import wizard settings page. Controls which import source cards are visible on
 the wizard's first step, letting admins hide sources they have already finished
 migrating from.
*/
declare(strict_types=1);
use Luster\Core\View;

$b = rtrim(View::e($base_url ?? ''), '/');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/import" class="link">&larr; Back to Import Wizard</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/import/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon panel-header-icon-filled"><?= View::icon('import') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Import Wizard Settings</div>
        <div class="panel-header-sub">Choose which import options appear on the first wizard screen.</div>
      </div>
    </div>

    <!-- ── Config file reader ──────────────────────────────────────── -->
    <div class="card-body">
      <div class="section-label">Config File Reader</div>

      <div class="field-row">
        <label class="field-label">Auto-detect</label>
        <div>
          <label class="toggle-wrap">
            <input type="checkbox" name="import_allow_config_reader" value="1"
                   <?= ($settings['import_allow_config_reader'] ?? '0') === '1' ? 'checked' : '' ?>>
            <span class="toggle-label">Allow auto-detect from <code>config.php</code> path</span>
          </label>
          <p class="form-hint">
            Turn this on if you want Luster to read the old script's <code>config.php</code> file and fill in database details for you.
            Luster reads the file as text; it does not run the file. Leave this off unless you trust the old config file and want the shortcut.
          </p>
        </div>
      </div>
    </div>

    <!-- ── Source card visibility ──────────────────────────────────── -->
    <div class="card-body">
      <div class="section-label">Source Card Visibility</div>
      <p class="form-hint" style="margin-bottom:1rem">
        After you finish moving data from an old script, you can hide that script's card.
        This only hides the card from the wizard; it does not delete any imported data.
      </p>

      <div class="field-row">
        <label class="field-label">Visible sources</label>
        <div>
          <div class="field-group">
            <label class="toggle-wrap">
              <input type="checkbox" name="import_show_enthusiast" value="1"
                     <?= ($settings['import_show_enthusiast'] ?? '1') === '1' ? 'checked' : '' ?>>
              <span class="toggle-label">Show Enthusiast card</span>
            </label>
          </div>
          <div class="field-group">
            <label class="toggle-wrap">
              <input type="checkbox" name="import_show_listing_admin" value="1"
                     <?= ($settings['import_show_listing_admin'] ?? '1') === '1' ? 'checked' : '' ?>>
              <span class="toggle-label">Show Listing Admin card</span>
            </label>
          </div>
          <div class="field-group">
            <label class="toggle-wrap">
              <input type="checkbox" name="import_show_fanupdate" value="1"
                     <?= ($settings['import_show_fanupdate'] ?? '1') === '1' ? 'checked' : '' ?>>
              <span class="toggle-label">Show FanUpdate card</span>
            </label>
          </div>
          <div class="field-group">
            <label class="toggle-wrap">
              <input type="checkbox" name="import_show_codesort" value="1"
                     <?= ($settings['import_show_codesort'] ?? '1') === '1' ? 'checked' : '' ?>>
              <span class="toggle-label">Show CodeSort card</span>
            </label>
          </div>
          <div class="field-group">
            <label class="toggle-wrap">
              <input type="checkbox" name="import_show_bellabuffs" value="1"
                     <?= ($settings['import_show_bellabuffs'] ?? '1') === '1' ? 'checked' : '' ?>>
              <span class="toggle-label">Show BellaBuffs card</span>
            </label>
          </div>
          <div class="field-group">
            <label class="toggle-wrap">
              <input type="checkbox" name="import_show_fanatically" value="1"
                     <?= ($settings['import_show_fanatically'] ?? '1') === '1' ? 'checked' : '' ?>>
              <span class="toggle-label">Show Fanatically card</span>
            </label>
          </div>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>

  </div>
</form>
