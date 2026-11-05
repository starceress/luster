<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import wizard step 3 -- BellaBuffs import options. Provides a target listing
 selector and checkboxes for importing approved members, pending members,
 affiliates, and codes from BellaBuffs flat files.
*/
use Luster\Core\View;
$b   = rtrim(View::e($base_url), '/');
$src = View::e($source);

$oldListingId = (int) ($old['bb_target_listing_id'] ?? 0);

function bbScopeChecked(array $old, string $key, bool $default = true): string {
    if (!isset($old[$key]) && empty($old)) {
        return $default ? 'checked' : '';
    }
    return !empty($old[$key]) ? 'checked' : '';
}
?>

<div class="import-wizard">

<div class="wizard-steps">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress wizard-steps-progress--scope"></div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble"><?= View::icon('check') ?></div>
    <div class="wizard-step-label" data-step-sub="Pick an import source">Source</div>
  </div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble"><?= View::icon('check') ?></div>
    <div class="wizard-step-label" data-step-sub="Choose files or folder">Connect</div>
  </div>
  <div class="wizard-step-node wizard-step-node--active">
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

<form id="import-bb-scope-form" method="POST"
      action="<?= $b ?>/admin/import/bellabuffs/options">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('import') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">BellaBuffs Import Options</div>
        <div class="panel-header-sub">Choose the Luster listing that should receive this BellaBuffs data.</div>
      </div>
    </div>

    <!-- ── Target listing ───────────────────────────────────────────── -->
    <div class="card-body">
      <div class="section-label">Target Luster Listing <span class="req">*</span></div>
      
      <div class="card-body">
      <div class="field-group">
        <div class="select-wrap">
          <select name="bb_target_listing_id" id="bb-target-listing" required>
            <option value="">— Select Listing —</option>
            <?php foreach ($listings as $lst): ?>
              <option value="<?= (int) $lst['listingid'] ?>"
                      <?= $oldListingId === (int) $lst['listingid'] ? 'selected' : '' ?>>
                <?= View::e((string) ($lst['label'] ?? $lst['dbtable'] ?? '')) ?>
                <span style="opacity:.6"> &mdash; <?= View::e((string) $lst['dbtable']) ?></span>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="form-hint" style="margin-top:.35rem">
          BellaBuffs manages one fanlisting per install. Pick the matching Luster listing here.
          Imported members, affiliates, and codes will be attached to this listing.
        </p>
      </div>
    </div>
            </div>

    <!-- ── Data to import ──────────────────────────────────────────── -->
    <div class="card-body">
      <div class="section-label">Data to Import</div>

      <?php if ($bb_mode === 'upload'): ?>
      <div class="callout callout-warn">
        <?= View::icon('alert') ?>
        <div><strong>Upload mode imports members only.</strong> Use the server folder path if you also want affiliates, fan codes, or button images.</div>
      </div>
      <?php endif; ?>

      <div class="import-chk-list card-body">

        <div class="import-chk">
          <input type="checkbox" name="bb_import_pending" value="1" id="ck-bb-pending"
                 <?= bbScopeChecked($old, 'bb_import_pending') ?>
                 <?= !$has_newbies ? 'disabled' : '' ?>>
          <div class="import-chk-body">
            <label class="import-chk-lbl" for="ck-bb-pending">
              Pending members
              <?php if (!$has_newbies): ?>
                <span class="badge badge-inactive">newbies.txt not found</span>
              <?php endif; ?>
            </label>
            <div class="import-chk-sub">
              Imports <code>newbies.txt</code> as members waiting for approval.
            </div>
          </div>
        </div>

        <?php if ($bb_mode === 'path'): ?>
        <div class="import-chk">
          <input type="checkbox" name="bb_import_affiliates" value="1" id="ck-bb-affiliates"
                 <?= bbScopeChecked($old, 'bb_import_affiliates', $has_affiliates) ?>
                 <?= !$has_affiliates ? 'disabled' : '' ?>>
          <div class="import-chk-body">
            <label class="import-chk-lbl" for="ck-bb-affiliates">
              Affiliates
              <?php if (!$has_affiliates): ?>
                <span class="badge badge-inactive">affiliates.txt not found</span>
              <?php endif; ?>
            </label>
            <div class="import-chk-sub">
              Imports affiliates for the selected listing.
              Affiliate button images are copied from the BellaBuffs <code>buttons/</code> folder.
            </div>
          </div>
        </div>

        <div class="import-chk">
          <input type="checkbox" name="bb_import_codes" value="1" id="ck-bb-codes"
                 <?= bbScopeChecked($old, 'bb_import_codes', $has_buttons) ?>
                 <?= !$has_buttons ? 'disabled' : '' ?>>
          <div class="import-chk-body">
            <label class="import-chk-lbl" for="ck-bb-codes">
              Fan codes / buttons
              <?php if (!$has_buttons): ?>
                <span class="badge badge-inactive">buttons.txt not found</span>
              <?php endif; ?>
            </label>
            <div class="import-chk-sub">
              Imports fan code records from <code>buttons.txt</code>. Code images are copied
              from the BellaBuffs <code>buttons/</code> folder. Luster creates the needed sizes and donors automatically.
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>

    <?php if ($bb_mode === 'path' && $has_buttons): ?>
    <!-- ── If a fan code is already there ──────────────────────────── -->
    <div class="card-body">
      <div class="section-label">If a fan code already exists in Luster</div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
        <label class="surface import-choice-item">
          <input type="radio" name="conflict_strategy" value="skip"
                 <?= ($old['conflict_strategy'] ?? 'skip') !== 'overwrite' ? 'checked' : '' ?>>
          <div>
            <strong>Keep existing (recommended)</strong>
            <span class="form-hint" style="margin:2px 0 0">Leave codes already in Luster alone and add the ones that are missing.</span>
          </div>
        </label>
        <label class="surface import-choice-item">
          <input type="radio" name="conflict_strategy" value="overwrite"
                 <?= ($old['conflict_strategy'] ?? 'skip') === 'overwrite' ? 'checked' : '' ?>>
          <div>
            <strong>Update matching codes</strong>
            <span class="form-hint" style="margin:2px 0 0">Replace a matching code's image and donor with what's in BellaBuffs. Its name, status and position stay as they are in Luster. Members and affiliates that already exist are always kept.</span>
          </div>
        </label>
      </div>
    </div>
    <?php endif; ?>

    <div class="composer-footer">
      <a href="<?= $b ?>/admin/import/bellabuffs/connect" class="btn btn-quaternary">&#8592; Back</a>
      <button type="submit" class="btn btn-primary">Preview Import &#8594;</button>
    </div>
  </div>

</form>

</div>
