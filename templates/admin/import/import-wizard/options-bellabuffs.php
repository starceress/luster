<?php
use Luster\Core\View;
/** @var string $source */
/** @var string $source_name */
/** @var array  $listings        list of ['listingid'=>int, 'dbtable'=>string, 'label'=>string] */
/** @var string $bb_mode         'path' | 'upload' */
/** @var bool   $has_newbies     newbies.txt is available */
/** @var bool   $has_affiliates  affiliates.txt is available (path mode only) */
/** @var bool   $has_buttons     buttons.txt is available (path mode only) */
/** @var array  $old             previously submitted scope values (for back-navigation) */
/** @var string $base_url */
/** @var string $csrf_token */
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

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">BellaBuffs Import Options</div>
        <div class="pc-head-sub">Choose which Luster listing to import into and what data to migrate.</div>
      </div>
    </div>

    <!-- ── Target listing ───────────────────────────────────────────── -->
    <div class="pc-section">
      <div class="pc-section-label">Target Luster Listing <span class="req">*</span></div>
      
      <div class="pc-section">
      <div class="form-group">
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
        <p class="field-hint-sm">
          BellaBuffs manages one fanlisting per install. All imported members will be placed
          in this listing's member table.
        </p>
      </div>
    </div>
            </div>

    <!-- ── Data to import ──────────────────────────────────────────── -->
    <div class="pc-section">
      <div class="pc-section-label">Data to Import</div>

      <?php if ($bb_mode === 'upload'): ?>
      <div class="callout callout-warn">
        <?= View::icon('alert') ?>
        <div><strong>Upload mode: only member data can be imported.</strong> Affiliates and codes require a server folder path.</div>
      </div>
      <?php endif; ?>

      <div class="import-chk-list pc-section">

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
              Imports from <code>newbies.txt</code> as unapproved members (pending&nbsp;=&nbsp;1).
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
              Imports <code>affiliates.txt</code> into <code>lstr_affiliates</code> for the selected listing.
              Affiliate button images are copied from <code>buttons/</code>.
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
              Imports <code>buttons.txt</code> into <code>lstr_codes</code>. Code images are copied
              from <code>buttons/</code>. Size records and donor records are created automatically.
              Donated codes are linked to their creators.
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>

    <div class="composer-footer">
      <a href="<?= $b ?>/admin/import/bellabuffs/connect" class="btn btn-quaternary">&#8592; Back</a>
      <button type="submit" class="btn btn-primary">Preview Import &#8594;</button>
    </div>
  </div>

</form>

</div>
