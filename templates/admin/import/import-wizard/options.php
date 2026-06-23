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
      action="<?= $b ?>/admin/import/<?= $src ?>/options">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card card--gradient">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Import Options</div>
        <div class="pc-head-sub">Pick the old data you want to bring into Luster.</div>
      </div>
    </div>

    <!-- ── Source-specific settings ──────────────────────────── -->
    <?php if ($source === 'fanupdate'): ?>
    <div class="card-body">
      <div class="pc-section-label">Blog Table Name</div>
      <div class="card-body">
        <input id="fu_prefix" type="text" name="fu_prefix" class="form import-input-medium"
               placeholder="blog" value="<?= scopeOld($old, 'fu_prefix', 'blog') ?>">
        <p class="field-hint-sm">The main FanUpdate table name from <code>blog-config.php</code>. Most installs use <code>blog</code>.</p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($source === 'listing-admin'): ?>
    <div class="card-body">
      <div class="pc-section-label">Table Prefix</div>
      <div class="card-body">
        <input id="la_table_prefix" type="text" name="la_table_prefix" class="form import-input-medium"
               placeholder="(leave blank for default install)"
               value="<?= scopeOld($old, 'la_table_prefix') ?>">
        <p class="field-hint-sm">Leave blank for a normal Listing Admin install. If your table names start with extra text, like <code>trex_members</code>, enter that extra text here: <code>trex_</code>.</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Conflict strategy ─────────────────────────────────── -->
    <div class="card-body">
      <div class="pc-section-label">If a record already exists in Luster</div>
      <div class="import-choice card-body">
        <label class="import-choice-item">
          <input type="radio" name="conflict_strategy" value="skip"
                 <?= $conflictMode === 'skip' ? 'checked' : '' ?>>
          <div>
            <div class="import-choice-title">Skip - keep existing</div>
            <div class="import-choice-sub">Adds new records only. This is the safest choice when you are re-running an import.</div>
          </div>
        </label>
        <label class="import-choice-item">
          <input type="radio" name="conflict_strategy" value="overwrite"
                 <?= $conflictMode === 'overwrite' ? 'checked' : '' ?>>
          <div>
            <div class="import-choice-title">Overwrite - replace with source values</div>
            <div class="import-choice-sub">Changes matching Luster records to the old script's values. Use this only when you intentionally want the old data to win.</div>
          </div>
        </label>
      </div>
    </div>

    <!-- ── Data to import ────────────────────────────────────── -->
    <div class="card-body">
      <div class="pc-section-label">
        Data to Import
        <span style="font-size:var(--fs-sm);text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-dim);margin-left:.4rem">(all selected by default)</span>
      </div>

      <?php /* WARNING ABOVE checkboxes for order-dependent sources */ ?>
      <?php if ($source === 'fanatically'): ?>
      <div class="callout callout-warn">
        <?= View::icon('alert') ?>
        <div><strong>Order matters:</strong> Listings must be imported before Members. Member records are placed into per-listing tables created during the listings import.</div>
      </div>
      <?php elseif ($source === 'listing-admin'): ?>
      <div class="callout callout-warn">
        <?= View::icon('alert') ?>
        <div><strong>Order matters:</strong> Listings must be imported before Members. Member records are placed into per-listing tables created during the listings import.</div>
      </div>
      <?php elseif ($source === 'codesort'): ?>
        <p>Fanlistings are matched by title between CodeSort and Luster. Codes for unmatched fanlistings are skipped with a warning.</p>
      <?php endif; ?>

      <div class="import-chk-list card-body">

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
            <div class="import-chk-body">
              <label class="import-chk-lbl" for="ck-members">Members</label>
              <div class="import-chk-sub">Copies all fan members into Luster&rsquo;s database. After a successful copy you can delete the old Enthusiast database. If both databases are the same, Luster already reads the tables directly &mdash; nothing is copied.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_affiliates" value="1" <?= scopeChecked($old, 'import_affiliates') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Affiliates</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_settings" value="1" <?= scopeChecked($old, 'import_settings') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Per-listing settings</label>
              <div class="import-chk-sub">Includes each listing's saved options and banner image paths. Needed if you want owned listing images copied too.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_templates" value="1" <?= scopeChecked($old, 'import_templates') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Email templates</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_saved_templates" value="1" <?= scopeChecked($old, 'import_saved_templates') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Saved email templates</label>
              <div class="import-chk-sub">Imports reusable saved email drafts if your Enthusiast install has them.</div>
            </div>
          </div>
        <?php endif; ?>

          <?php if ($source === 'fanatically'): ?>
          <div class="import-chk">
            <input type="checkbox" name="import_listings" value="1" id="ck-listings" <?= scopeChecked($old, 'import_listings') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl" for="ck-listings">Owned listings</label>
              <div class="import-chk-sub">Creates per-listing member tables. Must be imported before Members.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_members" value="1" id="ck-members" <?= scopeChecked($old, 'import_members') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl" for="ck-members">Members</label>
              <div class="import-dep-warn" id="js-mem-dep-warn">
                <?= View::icon('alert', ['width' => 12, 'height' => 12]) ?>
                Listings must also be selected because members are saved inside each listing's member table.
              </div>
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
            <input type="checkbox" name="import_codes" value="1" <?= scopeChecked($old, 'import_codes') ?>>
            <div class="import-chk-body"><label class="import-chk-lbl">Codes (fan graphics)</label></div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_wishlist" value="1" <?= scopeChecked($old, 'import_wishlist') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Wishlist</label>
              <div class="import-chk-sub">Owner's personal wishlist of fanlistings to open. Requires the Wishlist add-on.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_kim" value="1" <?= scopeChecked($old, 'import_kim') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">KIM List</label>
              <div class="import-chk-sub">Fan adoption-interest entries. Requires the KIM add-on.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="fn_import_adoption" value="1" <?= scopeChecked($old, 'fn_import_adoption') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Adoption Applications</label>
              <div class="import-chk-sub">Listing adoption requests from fans. Requires the Adoption add-on.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="fn_import_site_settings" value="1" <?= scopeChecked($old, 'fn_import_site_settings') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Site Settings</label>
              <div class="import-chk-sub">Collective name, descriptions, and display options. SMTP credentials, admin passwords, and API keys are never imported.</div>
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
                Listings must also be selected because members are saved inside each listing's member table.
              </div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_updates" value="1" <?= scopeChecked($old, 'import_updates') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Updates</label>
              <div class="import-chk-sub">Requires the News Feed add-on to be installed.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_wishlist" value="1" <?= scopeChecked($old, 'import_wishlist') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Wishlist</label>
              <div class="import-chk-sub">Owner's personal wishlist of fanlistings to open. Requires the Wishlist add-on.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_kim" value="1" <?= scopeChecked($old, 'import_kim') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">KIM List</label>
              <div class="import-chk-sub">Fan adoption-interest registries. Requires the KIM add-on.</div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_rules" value="1" id="ck-rules" <?= scopeChecked($old, 'import_rules') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl" for="ck-rules">Join Rules</label>
              <div class="import-chk-sub">Per-listing "Join Rules" content shown before the join form. Requires the Join Rules add-on.</div>
              <div class="import-dep-warn" id="js-rules-dep-warn">
                <?= View::icon('alert', ['width' => 12, 'height' => 12]) ?>
                Owned listings must also be selected because join rules are linked to each imported listing.
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($source === 'fanupdate'): ?>
          <div class="import-chk">
            <input type="checkbox" name="import_updates" value="1" <?= scopeChecked($old, 'import_updates') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl">Blog entries &rarr; News Feed</label>
              <div class="import-chk-sub">Requires the News Feed add-on to be installed.</div>
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

    <!-- ── Fanatically Options ──────────────────────────────────── -->
    <?php if ($source === 'fanatically'): ?>
    <div class="card-body">
      <div class="pc-section-label">Fanatically Options</div>
      <div class="field-group">
        <label class="field-label" for="fn_kim_status">KIM List Import Status</label>
        <div class="import-choice" style="margin-top:.375rem">
          <label class="import-choice-item">
            <input type="radio" name="fn_kim_status" value="active"
                   <?= ($old['fn_kim_status'] ?? 'active') !== 'pending' ? 'checked' : '' ?>>
            <div>
              <div class="import-choice-title">Active</div>
              <div class="import-choice-sub">KIM entries are imported as active and visible on the public list. Preserves Fanatically&rsquo;s default behavior.</div>
            </div>
          </label>
          <label class="import-choice-item">
            <input type="radio" name="fn_kim_status" value="pending"
                   <?= ($old['fn_kim_status'] ?? '') === 'pending' ? 'checked' : '' ?>>
            <div>
              <div class="import-choice-title">Pending (requires approval)</div>
              <div class="import-choice-sub">KIM entries need to be approved before appearing publicly. Use if you prefer manual review after import.</div>
            </div>
          </label>
        </div>
      </div>
    </div>
    <?php endif; ?>

  <!-- ── Image Migration ──────────────────────────────────────────── -->
  <?php if ($source !== 'fanupdate'): ?>
    <?php $alwaysAuto = in_array($source, ['enthusiast', 'listing-admin', 'fanatically', 'codesort']); ?>
    <div class="card-body import-image-section">

      <?php if ($alwaysAuto): ?>
        <div class="pc-section-label import-section-label-inline">
          Image Migration
          <span class="badge badge-inactive">Automatic</span>
        </div>
        <p>
          Luster will try to copy images automatically. It first reads image paths from
          <?php if ($source === 'enthusiast'): ?>Enthusiast's <code>settings</code> table
          <?php elseif ($source === 'listing-admin'): ?>Listing Admin's options table
          <?php elseif ($source === 'codesort'): ?>CodeSort's <code>codes-config.php</code>
          <?php else: ?>Fanatically's <code>settings</code> table
          <?php endif; ?>
          so you usually do not need to fill anything in.
          If the old site is on another server, enter its public URL so Luster can download images.
          If your host blocks remote downloads, upload a ZIP of the image folder instead. Luster matches files by filename.
        </p>
        <div class="card-body">
          <div class="field-row field-row--top">
            <label class="field-label" for="img-path">Server Path</label>
            <div>
              <input id="img-path" type="text" name="image_path" class="form"
                     placeholder="e.g. /home/user/old-install (optional)"
                     value="<?= scopeOld($old, 'image_path') ?>">
              <p class="form-hint">Optional. Use this only when the old image files are on the same server as Luster.</p>
            </div>
          </div>
          <div class="field-row field-row--top">
            <label class="field-label" for="img-url">Live Site URL</label>
            <div>
              <input id="img-url" type="url" name="image_url" class="form"
                     placeholder="e.g. https://example.com/old-install (optional)"
                     value="<?= scopeOld($old, 'image_url') ?>">
              <p class="form-hint">Optional. Use the old public site URL when Luster needs to download images from another server.</p>
            </div>
          </div>
          <div class="field-row field-row--top">
            <label class="field-label" for="js-zip-file-input">Upload File</label>
            <div>
              <label class="file-drop-zone" for="js-zip-file-input">
                <input id="js-zip-file-input" type="file" accept=".zip"
                       data-upload-url="<?= View::e($zip_url) ?>"
                       data-csrf="<?= View::e($csrf_token) ?>">
                <span class="file-drop-icon"><?= View::icon('import') ?></span>
                <strong>Drag &amp; drop or click to browse</strong>
                <span class="form-hint">.ZIP &mdash; max 50 MB &mdash; uploads automatically on selection</span>
              </label>
              <span id="js-zip-upload-result" style="font-size:var(--fs-sm);display:none;margin-top:.375rem;display:block"></span>
              <?php if ($zip_ready): ?>
                <p class="form-hint" style="margin-top:.375rem;color:var(--success)"><?= View::icon('check') ?> ZIP uploaded &mdash; takes precedence over path and URL.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>

      <?php else: ?>
        <div class="import-section-topline">
          <div>
            <div class="pc-section-label import-section-label-inline">
              Image Migration
              <span class="badge badge-inactive import-optional-badge">Optional</span>
            </div>
            <p class="import-section-sub">Copy code images into Luster&rsquo;s uploads directory. Original files are never deleted.</p>
          </div>
          <label class="import-section-toggle">
            <input type="checkbox" name="import_images" value="1" id="js-images-toggle"
                   <?= $imagesOn ? 'checked' : '' ?>>
            Migrate images
          </label>
        </div>
        <div id="js-image-options"<?= $imagesOn ? '' : ' class="is-hidden"' ?>>
          <div class="card-body" style="margin-top:.625rem">
            <div class="field-row field-row--top">
              <label class="field-label" for="img-path">Server Path</label>
              <div>
                <input id="img-path" type="text" name="image_path" class="form"
                       placeholder="e.g. /home/user/codesort/images"
                       value="<?= scopeOld($old, 'image_path') ?>">
                <p class="form-hint">Enter the folder that contains the CodeSort images, not the whole CodeSort install. Luster tries this first, then the URL.</p>
              </div>
            </div>
            <div class="field-row field-row--top">
              <label class="field-label" for="img-url">Live Site URL</label>
              <div>
                <input id="img-url" type="url" name="image_url" class="form"
                       placeholder="e.g. https://example.com/codesort/images (optional)"
                       value="<?= scopeOld($old, 'image_url') ?>">
                <p class="form-hint">Optional. Use this when Luster must download CodeSort images from the old public site.</p>
              </div>
            </div>
            <div class="field-row field-row--top">
              <label class="field-label" for="js-zip-file-input">Upload File</label>
              <div>
                <label class="file-drop-zone" for="js-zip-file-input">
                  <input id="js-zip-file-input" type="file" accept=".zip"
                         data-upload-url="<?= View::e($zip_url) ?>"
                         data-csrf="<?= View::e($csrf_token) ?>">
                  <span class="file-drop-icon"><?= View::icon('import') ?></span>
                  <strong>Drag &amp; drop or click to browse</strong>
                  <span class="form-hint">.ZIP &mdash; max 50 MB &mdash; uploads automatically on selection</span>
                </label>
                <span id="js-zip-upload-result" style="font-size:var(--fs-sm);margin-top:.375rem;display:none"></span>
                <?php if ($zip_ready): ?>
                  <p class="form-hint" style="margin-top:.375rem;color:var(--success)"><?= View::icon('check') ?> ZIP uploaded &mdash; takes precedence over path and URL.</p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

    </div>
  <?php endif; ?>

    <div class="composer-footer">
      <a href="<?= $b ?>/admin/import/<?= $src ?>/connect" class="btn btn-quaternary">&#8592; Back</a>
      <button type="submit" class="btn btn-primary">Preview Import &#8594;</button>
    </div>
  </div>

</form>

</div>
