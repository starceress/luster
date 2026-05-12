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
// On first visit, default to same-server path mode. On return visits, restore the
// previously chosen mode.
$imageMode    = empty($old) ? 'path' : ($old['image_mode'] ?? 'path');
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
              <?php if (!empty($enth_networks)): ?>
              <div style="margin-top:.5rem;display:flex;flex-direction:column;gap:.3rem">
                <label class="form-label" for="category_parent_id" style="font-size:var(--fs-sm)">Place top-level categories under network</label>
                <div class="select-wrap import-select-narrow">
                  <select id="category_parent_id" name="category_parent_id">
                    <option value="">&mdash; Leave as top-level &mdash;</option>
                    <?php foreach ($enth_networks as $net): ?>
                      <option value="<?= View::e((string) $net['id']) ?>"
                        <?= (string) ($old['category_parent_id'] ?? '') === (string) $net['id'] ? 'selected' : '' ?>>
                        <?= View::e($net['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <p class="field-hint-sm import-hint-tight">Enthusiast has no network concept. Choose a parent to import categories as children instead of top-level entries.</p>
              </div>
              <?php endif; ?>
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
            <div class="import-chk-body"><label class="import-chk-lbl">Per-listing settings</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_templates" value="1" <?= scopeChecked($old, 'import_templates') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Email templates</label></div>
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
    <div class="pc-section import-image-section">
      <div class="import-section-topline">
        <div>
          <div class="pc-section-label import-section-label-inline">
            Image Migration
            <span class="badge badge-inactive import-optional-badge">Optional</span>
          </div>
          <div class="import-section-sub">Copy affiliate logos, joined listing images, and listing images into Luster&rsquo;s uploads directory. Original files are never deleted.</div>
        </div>
        <label class="import-section-toggle">
          <input type="checkbox" name="import_images" value="1" id="js-images-toggle"
                 <?= $imagesOn ? 'checked' : '' ?>>
          Migrate images
        </label>
      </div>

      <div id="js-image-options"<?= $imagesOn ? '' : ' class="is-hidden"' ?>>
        <div class="import-choice import-choice--stacked">

          <div class="import-choice-option">
          <label class="import-choice-item">
            <input type="radio" name="image_mode" value="path" id="img-mode-path"
                   <?= $imageMode === 'path' ? 'checked' : '' ?>>
            <div>
              <div class="import-choice-title">Same server</div>
              <div class="import-choice-sub">Copy files via absolute path on this server.</div>
            </div>
          </label>
          <div id="js-img-path-field" class="import-mode-panel<?= $imageMode === 'path' ? '' : ' is-hidden' ?>">
            <input type="text" name="image_path" class="form import-input-wide"
                   placeholder="/absolute/path/to/old-install"
                   value="<?= scopeOld($old, 'image_path') ?>">
            <p class="field-hint-sm">
              <?php if ($source === 'enthusiast'): ?>
                Luster reads image paths from Enthusiast&rsquo;s <code>settings</code> table automatically.
                Enter your Enthusiast install root only as a fallback if those settings are blank.
                Leave blank if Luster and Enthusiast share the same database.
              <?php elseif ($source === 'listing-admin'): ?>
                Luster reads image paths from Listing Admin&rsquo;s options table automatically (<code>aff_path</code>, <code>img_path</code>, <code>jnd_path</code>).
                If those options are blank, enter the direct image folder path here &mdash; not the install root.
              <?php elseif ($source === 'codesort'): ?>
                Enter the value of <code>images_folder</code> from <code>codes-config.php</code> &mdash;
                the direct folder where code images are stored. Do <strong>not</strong> enter the install root.
              <?php endif; ?>
            </p>
          </div>
          </div>

          <div class="import-choice-option">
          <label class="import-choice-item">
            <input type="radio" name="image_mode" value="url" id="img-mode-url"
                   <?= $imageMode === 'url' ? 'checked' : '' ?>>
            <div>
              <div class="import-choice-title">Remote URL</div>
              <div class="import-choice-sub">Fetch files over HTTP. Useful when Luster is on a different server.</div>
            </div>
          </label>
          <div id="js-img-url-field" class="import-mode-panel<?= $imageMode === 'url' ? '' : ' is-hidden' ?>">
            <input type="url" name="image_url" class="form import-input-wide"
                   placeholder="https://example.com/old-install"
                   value="<?= scopeOld($old, 'image_url') ?>">
            <p class="field-hint-sm">Base URL of your old installation (no trailing slash). Luster appends standard image sub-paths.</p>
          </div>
          </div>

          <div class="import-choice-option">
          <label class="import-choice-item">
            <input type="radio" name="image_mode" value="zip" id="img-mode-zip"
                   <?= $imageMode === 'zip' ? 'checked' : '' ?>>
            <div>
              <div class="import-choice-title">Upload ZIP</div>
              <div class="import-choice-sub">Upload a ZIP of your image directories before the import runs. Files are matched by filename &mdash; subdirectory paths inside the ZIP are ignored.</div>
            </div>
          </label>
          <div id="js-img-zip-field" class="import-mode-panel<?= $imageMode === 'zip' ? '' : ' is-hidden' ?>">
            <?php if ($zip_ready): ?>
              <div class="alert alert-info" style="margin-bottom:.5rem">
                <?= View::icon('check') ?> ZIP already uploaded &mdash; images will be extracted during the import run.
              </div>
            <?php endif; ?>
            <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
              <input id="js-zip-file-input" type="file" accept=".zip" class="form" style="flex:1;min-width:180px">
              <button type="button" id="js-zip-upload-btn" class="btn btn-secondary btn-sm"
                      data-upload-url="<?= View::e($zip_url) ?>"
                      data-csrf="<?= View::e($csrf_token) ?>">
                Upload ZIP
              </button>
              <span id="js-zip-upload-result" style="font-size:var(--fs-sm);display:none"></span>
            </div>
            <p class="field-hint-sm" style="margin-top:.4rem">Max 50 MB · .zip only. Subdirectory paths inside the ZIP are ignored.</p>
          </div>
          </div>

        </div><!-- /.import-choice -->
      </div><!-- /#js-image-options -->
    </div>
  <?php endif; ?>

    <div class="composer-footer">
      <a href="<?= $b ?>/admin/import/<?= $src ?>/connect" class="btn btn-ghost">&#8592; Back</a>
      <button type="submit" class="btn btn-primary">Preview Import &#8594;</button>
    </div>
  </div>

</form>

</div>
