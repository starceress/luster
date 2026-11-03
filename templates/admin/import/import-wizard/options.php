<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import wizard step 3 -- import options for SQL-based sources. Presents
 checkboxes for each data type (categories, affiliates, joined listings,
 settings, email templates, codes) and image migration settings.
*/
use Luster\Core\View;
$b   = rtrim(View::e($base_url), '/');
$src = View::e($source);

// Guarded so the template can be rendered more than once in a process (tests do).
if (!function_exists('scopeOld')) {
    function scopeOld(array $old, string $key, string $default = ''): string {
        return View::e((string) ($old[$key] ?? $default));
    }
}
if (!function_exists('scopeChecked')) {
    function scopeChecked(array $old, string $key, bool $default = true): string {
        if (!isset($old[$key]) && empty($old)) return $default ? 'checked' : '';
        return !empty($old[$key]) ? 'checked' : '';
    }
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
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('import') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Import Options</div>
        <div class="panel-header-sub">Pick the old data you want to bring into Luster.</div>
      </div>
    </div>

    <!-- ── Source-specific settings ──────────────────────────── -->
    <?php if ($source === 'fanupdate'): ?>
    <div class="card-body">
      <div class="section-label">Blog Table Name</div>
      <div class="card-body">
        <input id="fu_prefix" type="text" name="fu_prefix" class="form" style="max-width:220px"
               placeholder="blog" value="<?= scopeOld($old, 'fu_prefix', 'blog') ?>">
        <p class="form-hint" style="margin-top:.35rem">The main FanUpdate table name from <code>blog-config.php</code>. Most installs use <code>blog</code>.</p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($source === 'listing-admin'): ?>
    <?php
      // Installs found in the source (ListingAdminImportSource::detectInstalls()). A prefix
      // the admin already chose wins over them when they come back to this step.
      $laInstalls = $la_installs ?? [];
      $laChosen   = array_key_exists('la_table_prefix', $old) ? (string) $old['la_table_prefix'] : null;
      $laOther    = \Luster\Import\ImportOptions::LA_PREFIX_OTHER;
    ?>
    <div class="card-body">
      <div class="section-label">Table Prefix</div>
      <div class="card-body">
        <?php if (!empty($scope_error)): ?>
        <div class="callout callout-err">
          <?= View::icon('alert') ?>
          <div><?= View::e($scope_error) ?></div>
        </div>
        <?php endif; ?>

        <?php if (count($laInstalls) > 1): ?>
          <?php
            $laPrefixes  = array_column($laInstalls, 'prefix');
            $laPick      = $laChosen ?? $laPrefixes[0];
            $laOtherText = (string) ($old['la_table_prefix_other'] ?? '');
            if ($laPick !== $laOther && !in_array($laPick, $laPrefixes, true)) {
                $laOtherText = $laPick;
                $laPick      = $laOther;
            }
          ?>
          <p class="form-hint" style="margin-top:0">Luster found more than one Listing Admin install in this database. Pick the one to import.</p>
          <div style="display:flex;flex-direction:column;gap:8px">
            <?php foreach ($laInstalls as $install): ?>
            <label class="surface import-choice-item">
              <input type="radio" name="la_table_prefix" value="<?= View::e($install['prefix']) ?>"
                     <?= $laPick === $install['prefix'] ? 'checked' : '' ?>>
              <div>
                <strong><?= $install['prefix'] !== '' ? View::e($install['prefix']) : 'No prefix' ?></strong>
                <span class="form-hint" style="margin:2px 0 0"><?= (int) $install['listings'] ?> listing<?= (int) $install['listings'] === 1 ? '' : 's' ?> &mdash; tables <code><?= View::e($install['registry']) ?></code>, <code><?= View::e($install['prefix']) ?>members</code>, &hellip;</span>
              </div>
            </label>
            <?php endforeach; ?>
            <label class="surface import-choice-item">
              <input type="radio" name="la_table_prefix" value="<?= View::e($laOther) ?>"
                     <?= $laPick === $laOther ? 'checked' : '' ?>>
              <div>
                <strong>Something else</strong>
                <input type="text" name="la_table_prefix_other" class="form" style="max-width:220px;margin-top:.35rem"
                       placeholder="trex_" aria-label="Other table prefix" value="<?= View::e($laOtherText) ?>">
              </div>
            </label>
          </div>
        <?php else: ?>
          <?php $laFound = $laInstalls[0] ?? null; ?>
          <input id="la_table_prefix" type="text" name="la_table_prefix" class="form" style="max-width:220px"
                 placeholder="trex_"
                 value="<?= View::e($laChosen ?? ($laFound['prefix'] ?? '')) ?>">
          <?php if ($laFound !== null && $laFound['prefix'] !== ''): ?>
            <p class="form-hint" style="margin-top:.35rem">Found in your Listing Admin tables (<code><?= View::e($laFound['registry']) ?></code>, <code><?= View::e($laFound['prefix']) ?>members</code>, &hellip;) &mdash; <?= (int) $laFound['listings'] ?> listing<?= (int) $laFound['listings'] === 1 ? '' : 's' ?>.</p>
          <?php elseif ($laFound !== null): ?>
            <p class="form-hint" style="margin-top:.35rem">Found Listing Admin tables with no prefix (<code>main</code>, <code>members</code>, &hellip;) &mdash; <?= (int) $laFound['listings'] ?> listing<?= (int) $laFound['listings'] === 1 ? '' : 's' ?>. Leave this blank.</p>
          <?php else: ?>
            <p class="form-hint" style="margin-top:.35rem">Luster couldn't find Listing Admin's tables automatically. Enter the start of your Listing Admin table names &mdash; for example, if you have <code>trex_members</code>, enter <code>trex_</code>. You can also find it in Listing Admin's <code>rats.inc.php</code>, on the <code>$_ST['prefix']</code> line. Leave it blank only if your tables have no prefix at all (<code>main</code>, <code>members</code>, &hellip;).</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card-body">
      <div class="section-label">Source ID <span style="font-size:var(--fs-sm);text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-dim);margin-left:.4rem">(optional)</span></div>
      <div class="card-body">
        <input id="la_source_id" type="text" name="la_source_id" class="form" style="max-width:220px"
               placeholder="old-collective" maxlength="36" autocomplete="off"
               <?= !empty($la_known_sources) ? 'list="la-known-sources"' : '' ?>
               value="<?= scopeOld($old, 'la_source_id') ?>">
        <p class="form-hint" style="margin-top:.35rem">Usually leave this blank &mdash; Luster works it out from your Listing Admin install's web address. Fill it in if that address changed since an earlier import, or if you're importing more than one Listing Admin install. Lowercase letters, numbers, and hyphens, up to 36 characters.</p>
        <?php if (!empty($la_known_sources)): ?>
        <datalist id="la-known-sources">
          <?php foreach ($la_known_sources as $knownSource): ?>
            <option value="<?= View::e($knownSource['key']) ?>">
          <?php endforeach; ?>
        </datalist>
        <p class="form-hint">Used by earlier imports:
          <?php foreach ($la_known_sources as $i => $knownSource): ?>
            <?php
              $knownCounts = [];
              if ((int) $knownSource['listings'] > 0) {
                  $knownCounts[] = (int) $knownSource['listings'] . ' listing' . ((int) $knownSource['listings'] === 1 ? '' : 's');
              }
              if ((int) ($knownSource['codes'] ?? 0) > 0) {
                  $knownCounts[] = (int) $knownSource['codes'] . ' code' . ((int) $knownSource['codes'] === 1 ? '' : 's');
              }
            ?>
            <?= $i > 0 ? '&middot;' : '' ?> <code><?= View::e($knownSource['key']) ?></code><?= $knownCounts !== [] ? ' (' . View::e(implode(', ', $knownCounts)) . ')' : '' ?>
          <?php endforeach; ?>
        </p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($source === 'codesort'): ?>
    <?php $csKnownSources = $cs_known_sources ?? []; ?>
    <div class="card-body">
      <div class="section-label">Source ID <span style="font-size:var(--fs-sm);text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-dim);margin-left:.4rem">(optional)</span></div>
      <div class="card-body">
        <input id="cs_source_id" type="text" name="cs_source_id" class="form" style="max-width:220px"
               placeholder="old-collective" maxlength="36" autocomplete="off"
               <?= !empty($csKnownSources) ? 'list="cs-known-sources"' : '' ?>
               value="<?= scopeOld($old, 'cs_source_id') ?>">
        <p class="form-hint" style="margin-top:.35rem">Usually leave this blank &mdash; Luster works it out from your CodeSort install's image web address. Fill it in if there isn't one, if it changed since an earlier import, or if you're importing more than one CodeSort install. Lowercase letters, numbers, and hyphens, up to 36 characters.</p>
        <?php if (!empty($csKnownSources)): ?>
        <datalist id="cs-known-sources">
          <?php foreach ($csKnownSources as $knownSource): ?>
            <option value="<?= View::e($knownSource['key']) ?>">
          <?php endforeach; ?>
        </datalist>
        <p class="form-hint">Used by earlier imports:
          <?php foreach ($csKnownSources as $i => $knownSource): ?>
            <?= $i > 0 ? '&middot;' : '' ?> <code><?= View::e($knownSource['key']) ?></code> (<?= (int) $knownSource['codes'] ?> code<?= (int) $knownSource['codes'] === 1 ? '' : 's' ?>)
          <?php endforeach; ?>
        </p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Conflict strategy ─────────────────────────────────── -->
    <div class="card-body">
      <div class="section-label">If a record already exists in Luster</div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:8px">
        <label class="surface import-choice-item">
          <input type="radio" name="conflict_strategy" value="skip"
                 <?= $conflictMode === 'skip' ? 'checked' : '' ?>>
          <div>
            <strong>Keep existing (recommended)</strong>
            <span class="form-hint" style="margin:2px 0 0">Keep values already in Luster and add records that are missing. Luster may still fill in a few blank linked details, such as a missing image or last-updated date.</span>
          </div>
        </label>
        <label class="surface import-choice-item">
          <input type="radio" name="conflict_strategy" value="overwrite"
                 <?= $conflictMode === 'overwrite' ? 'checked' : '' ?>>
          <div>
            <strong>Update matching records</strong>
            <span class="form-hint" style="margin:2px 0 0">Applies the source's available values to records Luster can match — including passwords, where applicable. Blank fields in the source generally leave your existing Luster data alone. Use this to correct a failed import or intentionally refresh from the source; review the preview carefully first.</span>
          </div>
        </label>
      </div>
    </div>

    <!-- ── Data to import ────────────────────────────────────── -->
    <div class="card-body">
      <div class="section-label">
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
              <div class="import-dep-warn text-danger" id="js-mem-dep-warn">
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
              <div class="import-dep-warn text-danger" id="js-mem-dep-warn">
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
              <div class="import-dep-warn text-danger" id="js-rules-dep-warn">
                <?= View::icon('alert', ['width' => 12, 'height' => 12]) ?>
                Owned listings must also be selected because join rules are linked to each imported listing.
              </div>
            </div>
          </div>
          <div class="import-chk">
            <input type="checkbox" name="import_codes" value="1" id="ck-codes" <?= scopeChecked($old, 'import_codes') ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl" for="ck-codes">Codes (fan graphics)</label>
              <div class="import-chk-sub">Code images with their sizes, donors, and categories. Codes go to listings that are already in Luster or come over in this import; any others are skipped, and the preview tells you which.</div>
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
          <div class="import-chk">
            <?php /* Off by default, unlike the boxes above: only the admin knows where earlier codes came from. */ ?>
            <input type="checkbox" name="cs_match_earlier" value="1" id="ck-cs-match-earlier" <?= !empty($old['cs_match_earlier']) ? 'checked' : '' ?>>
            <div class="import-chk-body">
              <label class="import-chk-lbl" for="ck-cs-match-earlier">Match codes from an earlier CodeSort import</label>
              <div class="import-chk-sub">Codes imported before this update don't record which CodeSort install they came from. Tick this only if this Luster's earlier CodeSort codes came from <em>this</em> CodeSort install. Luster then links each one it can pair up exactly (same listing, same file) instead of adding it again. The preview lists them first.</div>
            </div>
          </div>
        <?php endif; ?>

      </div><!-- /.import-chk-list -->
    </div>

    <!-- ── Fanatically Options ──────────────────────────────────── -->
    <?php if ($source === 'fanatically'): ?>
    <div class="card-body">
      <div class="section-label">Fanatically Options</div>
      <div class="field-group">
        <label class="field-label" for="fn_kim_status">KIM List Import Status</label>
        <div style="display:flex;flex-direction:column;gap:8px" style="margin-top:.375rem">
          <?php $fnKimStatus = in_array($old['fn_kim_status'] ?? '', ['keep', 'pending', 'active'], true) ? $old['fn_kim_status'] : 'keep'; ?>
          <label class="surface import-choice-item">
            <input type="radio" name="fn_kim_status" value="keep"
                   <?= $fnKimStatus === 'keep' ? 'checked' : '' ?>>
            <div>
              <strong>Same as in Fanatically</strong>
              <span class="form-hint" style="margin:2px 0 0">Entries you approved in Fanatically show on the public list; entries still waiting there stay pending in Luster.</span>
            </div>
          </label>
          <label class="surface import-choice-item">
            <input type="radio" name="fn_kim_status" value="pending"
                   <?= $fnKimStatus === 'pending' ? 'checked' : '' ?>>
            <div>
              <strong>All pending (requires approval)</strong>
              <span class="form-hint" style="margin:2px 0 0">Every entry waits for your approval before appearing publicly. Use if you prefer to review them all after import.</span>
            </div>
          </label>
          <label class="surface import-choice-item">
            <input type="radio" name="fn_kim_status" value="active"
                   <?= $fnKimStatus === 'active' ? 'checked' : '' ?>>
            <div>
              <strong>All active</strong>
              <span class="form-hint" style="margin:2px 0 0">Every entry shows on the public list right away, including ones you hadn&rsquo;t approved in Fanatically.</span>
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
        <div class="section-label">
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
          <div class="field-row">
            <label class="field-label" for="img-path">Server Path</label>
            <div>
              <input id="img-path" type="text" name="image_path" class="form"
                     placeholder="e.g. /home/user/old-install (optional)"
                     value="<?= scopeOld($old, 'image_path') ?>">
              <p class="form-hint">Optional. Use this only when the old image files are on the same server as Luster.<?php if ($source === 'listing-admin'): ?> Also tried when the folder saved in Listing Admin doesn't work.<?php endif; ?></p>
            </div>
          </div>
          <div class="field-row">
            <label class="field-label" for="img-url">Live Site URL</label>
            <div>
              <input id="img-url" type="url" name="image_url" class="form"
                     placeholder="e.g. https://example.com/old-install (optional)"
                     value="<?= scopeOld($old, 'image_url') ?>">
              <p class="form-hint">Optional. Use the old public site URL when Luster needs to download images from another server.<?php if ($source === 'listing-admin'): ?> Also tried when the address saved in Listing Admin doesn't work.<?php endif; ?></p>
            </div>
          </div>
          <div class="field-row">
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
        <div class="toolbar" style="margin-bottom:.875rem;align-items:flex-start">
          <div>
            <div class="section-label">
              Image Migration
              <span class="badge badge-inactive">Optional</span>
            </div>
            <p class="form-hint" style="max-width:720px;margin:0">Copy code images into Luster&rsquo;s uploads directory. Original files are never deleted.</p>
          </div>
          <label class="form-check" style="flex-shrink:0;white-space:nowrap">
            <input type="checkbox" name="import_images" value="1" id="js-images-toggle"
                   <?= $imagesOn ? 'checked' : '' ?>>
            Migrate images
          </label>
        </div>
        <div id="js-image-options"<?= $imagesOn ? '' : ' class="is-hidden"' ?>>
          <div class="card-body" style="margin-top:.625rem">
            <div class="field-row">
              <label class="field-label" for="img-path">Server Path</label>
              <div>
                <input id="img-path" type="text" name="image_path" class="form"
                       placeholder="e.g. /home/user/codesort/images"
                       value="<?= scopeOld($old, 'image_path') ?>">
                <p class="form-hint">Enter the folder that contains the CodeSort images, not the whole CodeSort install. Luster tries this first, then the URL.</p>
              </div>
            </div>
            <div class="field-row">
              <label class="field-label" for="img-url">Live Site URL</label>
              <div>
                <input id="img-url" type="url" name="image_url" class="form"
                       placeholder="e.g. https://example.com/codesort/images (optional)"
                       value="<?= scopeOld($old, 'image_url') ?>">
                <p class="form-hint">Optional. Use this when Luster must download CodeSort images from the old public site.</p>
              </div>
            </div>
            <div class="field-row">
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
