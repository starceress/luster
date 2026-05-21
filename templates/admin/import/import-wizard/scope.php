<?php
use Luster\Core\View;
/** @var string $source */
/** @var string $source_name */
/** @var array  $old */
/** @var bool   $zip_ready */
/** @var string $zip_url */
/** @var array  $enth_networks */
/** @var string $base_url */
/** @var string $csrf_token */
$b   = rtrim(View::e($base_url), '/');
$src = View::e($source);

function scopeOld(array $old, string $key, string $default = ''): string {
    return View::e((string) ($old[$key] ?? $default));
}
function scopeChecked(array $old, string $key, bool $default = true): string {
    if (!isset($old[$key]) && empty($old)) return $default ? 'checked' : '';
    return !empty($old[$key]) ? 'checked' : '';
}

$conflictMode = $old['conflict_strategy'] ?? 'skip';
$imagesOn     = !empty($old['import_images']) || empty($old);
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
    <div class="wizard-step-label" data-step-sub="Connect or upload data">Connect</div>
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

<form id="import-scope-form" method="POST"
      action="<?= $b ?>/admin/import/<?= $src ?>/scope">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Import Options</div>
        <div class="pc-head-sub">Choose what data to import and how to handle existing records.</div>
      </div>
    </div>

    <!-- ── Source-specific settings ──────────────────────────── -->
    <?php if ($source === 'fanupdate'): ?>
    <div class="pc-section">
      <div class="pc-section-label">Blog Table Name</div>
      <div class="form-group">
        <input id="fu_prefix" type="text" name="fu_prefix" class="form import-input-medium"
               placeholder="blog" value="<?= scopeOld($old, 'fu_prefix', 'blog') ?>">
        <p class="field-hint-sm">Base table name from <code>blog-config.php</code> (default: <code>blog</code>).</p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($source === 'listing-admin'): ?>
    <div class="pc-section">
      <div class="pc-section-label">Table Prefix</div>
      <div class="form-group">
        <input id="la_table_prefix" type="text" name="la_table_prefix" class="form import-input-medium"
               placeholder="(leave blank for default install)"
               value="<?= scopeOld($old, 'la_table_prefix') ?>">
        <p class="field-hint-sm">Leave blank if LA uses default table names (e.g. <code>main</code>, <code>members</code>). Enter a prefix (e.g. <code>trex_</code>) if configured.</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Conflict strategy ─────────────────────────────────── -->
    <div class="pc-section">
      <div class="pc-section-label">If a record already exists in Luster</div>
      <div class="import-choice">
        <label class="import-choice-item">
          <input type="radio" name="conflict_strategy" value="skip"
                 <?= $conflictMode === 'skip' ? 'checked' : '' ?>>
          <div>
            <div class="import-choice-title">Skip &mdash; keep existing</div>
            <div class="import-choice-sub">Safe for re-runs. Records already in Luster are not modified.</div>
          </div>
        </label>
        <label class="import-choice-item">
          <input type="radio" name="conflict_strategy" value="overwrite"
                 <?= $conflictMode === 'overwrite' ? 'checked' : '' ?>>
          <div>
            <div class="import-choice-title">Overwrite &mdash; replace with source values</div>
            <div class="import-choice-sub">Existing Luster records are updated. Cannot be undone.</div>
          </div>
        </label>
      </div>
    </div>

    <!-- ── Data to import ────────────────────────────────────── -->
    <div class="pc-section">
      <div class="pc-section-label">
        Data to Import
        <span style="font-size:var(--fs-sm);text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-dim);margin-left:.4rem">(all selected by default)</span>
      </div>

      <?php /* WARNING ABOVE checkboxes for order-dependent sources */ ?>
      <?php if ($source === 'listing-admin'): ?>
      <div class="callout callout-inline" style="margin-bottom:.875rem">
        <?= View::icon('alert', ['width' => 16, 'height' => 16, 'style' => 'color:var(--warn);flex-shrink:0']) ?>
        <span><strong>Order matters:</strong> Listings must be imported before Members. Member records are placed into per-listing tables created during the listings import.</span>
      </div>
      <?php elseif ($source === 'codesort'): ?>
      <div class="callout callout-inline" style="margin-bottom:.875rem">
        <?= View::icon('alert', ['width' => 16, 'height' => 16, 'style' => 'color:var(--warn);flex-shrink:0']) ?>
        <span>Fanlistings are matched by title between CodeSort and Luster. Codes for unmatched fanlistings are skipped with a warning.</span>
      </div>
      <?php endif; ?>

      <div class="import-chk-list">

        <?php if ($source === 'enthusiast'): ?>
          <div class="import-chk">
            <input type="checkbox" name="import_owned_listings" value="1" id="ck-listings" <?= scopeChecked($old, 'import_owned_listings') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl" for="ck-listings">Owned listings</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_joined" value="1" <?= scopeChecked($old, 'import_joined') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Joined listings</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_categories" value="1" <?= scopeChecked($old, 'import_categories') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Assigned categories</label>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_members" value="1" id="ck-members" <?= scopeChecked($old, 'import_members') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl" for="ck-members">Members</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_affiliates" value="1" <?= scopeChecked($old, 'import_affiliates') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Affiliates</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_settings" value="1" <?= scopeChecked($old, 'import_settings') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Per-listing settings</label>
              <div class="import-chk-sub">Includes listing banner images — required for owned image migration.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_templates" value="1" <?= scopeChecked($old, 'import_templates') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Email templates</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_saved_templates" value="1" <?= scopeChecked($old, 'import_saved_templates', false) ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Saved email templates</label>
              <div class="import-chk-sub">Imports the reusable template library (<code>emailtemplate</code> table). Only present in Erin&rsquo;s fork of Enthusiast.</div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($source === 'listing-admin'): ?>
          <div class="import-chk">
            <input type="checkbox" name="import_listings" value="1" id="ck-listings" <?= scopeChecked($old, 'import_listings') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl" for="ck-listings">Owned listings</label>
              <div class="import-chk-sub">Creates per-listing member tables in the target database.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_joined" value="1" <?= scopeChecked($old, 'import_joined') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Joined listings</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_categories" value="1" <?= scopeChecked($old, 'import_categories') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Categories</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_affiliates" value="1" <?= scopeChecked($old, 'import_affiliates') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Affiliates</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_members" value="1" id="ck-members" <?= scopeChecked($old, 'import_members') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl" for="ck-members">Members</label>
              <div class="import-dep-warn" id="js-mem-dep-warn">
                <?= View::icon('alert', ['width' => 12, 'height' => 12]) ?>
                Listings must also be selected &mdash; members import into per-listing tables.
              </div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_updates" value="1" <?= scopeChecked($old, 'import_updates', false) ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Updates</label>
              <div class="import-chk-sub">Requires the Updates Feed add-on to be installed.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_wishlist" value="1" <?= scopeChecked($old, 'import_wishlist', false) ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Wishlist</label>
              <div class="import-chk-sub">Owner&rsquo;s personal wishlist of fanlistings to open. Requires the Wishlist add-on.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_kim" value="1" <?= scopeChecked($old, 'import_kim', false) ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">KIM List</label>
              <div class="import-chk-sub">Fan adoption-interest registries. Requires the KIM add-on.</div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($source === 'fanupdate'): ?>
          <div class="import-chk">
            <input type="checkbox" name="import_updates" value="1" <?= scopeChecked($old, 'import_updates') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Blog entries &rarr; Updates Feed</label>
              <div class="import-chk-sub">Requires the Updates Feed add-on to be installed.</div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($source === 'codesort'): ?>
          <div class="import-chk">
            <input type="checkbox" name="import_code_categories" value="1" <?= scopeChecked($old, 'import_code_categories') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Code categories</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_code_sizes" value="1" <?= scopeChecked($old, 'import_code_sizes') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Code sizes</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_code_donors" value="1" <?= scopeChecked($old, 'import_code_donors') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Code donors</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_codes" value="1" <?= scopeChecked($old, 'import_codes') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Codes (fan graphics)</label></div>
          </div>
        <?php endif; ?>

      </div><!-- /.import-chk-list -->
    </div>


  <!-- ── Image Migration ──────────────────────────────────────────── -->
  <?php if ($source !== 'fanupdate'): ?>
    <?php $alwaysAuto = in_array($source, ['enthusiast', 'listing-admin']); ?>
    <div class="pc-section import-image-section">

      <?php if ($alwaysAuto): ?>
        <div class="pc-section-label import-section-label-inline">
          Image Migration
          <span class="badge badge-current import-optional-badge">Automatic</span>
        </div>
        <div class="import-section-sub" style="margin-bottom:.75rem">
          Images are always migrated. Paths are read from
          <?= $source === 'enthusiast' ? "Enthusiast&rsquo;s <code>settings</code>" : "Listing Admin&rsquo;s options" ?>
          table automatically &mdash; no input needed in most cases.
          If you&rsquo;re importing from a remote server, enter your live site URL as the fallback
          and Luster will fetch images over HTTP. If outbound HTTP is blocked on your host,
          upload a ZIP of your image folder below instead &mdash; any ZIP structure works,
          files are matched by filename only.
        </div>
        <div style="display:flex;flex-direction:column;gap:.5rem">
          <input type="text" name="image_path" class="form import-input-wide"
                 placeholder="Server path (optional) — e.g. /home/user/old-install"
                 value="<?= scopeOld($old, 'image_path') ?>">
          <input type="url" name="image_url" class="form import-input-wide"
                 placeholder="Live site URL (optional) — e.g. https://example.com/old-install"
                 value="<?= scopeOld($old, 'image_url') ?>">
        </div>

      <?php else: ?>
        <div class="import-section-topline">
          <div>
            <div class="pc-section-label import-section-label-inline">
              Image Migration
              <span class="badge badge-inactive import-optional-badge">Optional</span>
            </div>
            <div class="import-section-sub">Copy code images into Luster&rsquo;s uploads directory. Original files are never deleted.</div>
          </div>
          <label class="import-section-toggle">
            <input type="checkbox" name="import_images" value="1" id="js-images-toggle"
                   <?= $imagesOn ? 'checked' : '' ?>>
            Migrate images
          </label>
        </div>
        <div id="js-image-options"<?= $imagesOn ? '' : ' class="is-hidden"' ?>>
          <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:.625rem">
            <input type="text" name="image_path" class="form import-input-wide"
                   placeholder="images_folder from codes-config.php — e.g. /home/user/codesort/images"
                   value="<?= scopeOld($old, 'image_path') ?>">
            <input type="url" name="image_url" class="form import-input-wide"
                   placeholder="Base URL fallback (optional) — e.g. https://example.com/codesort/images"
                   value="<?= scopeOld($old, 'image_url') ?>">
          </div>
          <p class="field-hint-sm" style="margin-top:.375rem">Enter <code>images_folder</code> as the path (not the install root). Luster tries the path first, then the URL.</p>
        </div>
      <?php endif; ?>

      <div style="display:flex;gap:.625rem;align-items:center;flex-wrap:wrap;margin-top:.75rem">
        <input id="js-zip-file-input" type="file" accept=".zip" class="form" style="flex:1;min-width:0">
        <button type="button" id="js-zip-upload-btn" class="btn btn-quaternary btn-sm"
                data-upload-url="<?= View::e($zip_url) ?>"
                data-csrf="<?= View::e($csrf_token) ?>">
          Upload ZIP
        </button>
        <span id="js-zip-upload-result" style="font-size:var(--fs-sm);display:none"></span>
      </div>
      <?php if ($zip_ready): ?>
        <p class="field-hint-sm" style="margin-top:.25rem;color:var(--success)"><?= View::icon('check') ?> ZIP uploaded — takes precedence over path and URL.</p>
      <?php else: ?>
        <p class="field-hint-sm" style="margin-top:.25rem">Or upload a ZIP of your image folder (max 50&thinsp;MB). You can zip the entire admin folder &mdash; no need to separate subdirectories. Files are matched by filename only.</p>
      <?php endif; ?>

    </div>
  <?php endif; ?>

    <div class="composer-footer">
      <a href="<?= $b ?>/admin/import/<?= $src ?>/connect" class="btn btn-ghost">&#8592; Back</a>
      <button type="submit" class="btn btn-primary">Preview Import &#8594;</button>
    </div>
  </div>

</form>

</div>
