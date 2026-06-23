<?php
use Luster\Core\View;
/** @var string $source */
/** @var string $source_name */
/** @var string $error */
/** @var array  $old */
/** @var string $base_url */
/** @var string $csrf_token */
$b   = rtrim(View::e($base_url), '/');
$src = View::e($source);

function importOld(array $old, string $key, string $default = ''): string {
    return View::e((string) ($old[$key] ?? $default));
}
function importChecked(array $old, string $key, bool $default = true): string {
    if (!isset($old[$key]) && empty($old)) return $default ? 'checked' : '';
    return !empty($old[$key]) ? 'checked' : '';
}
?>

<div class="wizard-steps">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress wizard-steps-progress--configure"></div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble">&#10003;</div>
    <div class="wizard-step-label">Choose Source</div>
  </div>
  <div class="wizard-step-node wizard-step-node--active">
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

<?php if ($error !== ''): ?>
  <div class="alert alert-error import-stack-gap"><?= View::e($error) ?></div>
<?php endif; ?>

<form id="import-configure-form" method="POST"
      action="<?= $b ?>/admin/import/<?= $src ?>/configure"
      data-live-url="<?= $b ?>/admin/import/<?= $src ?>/configure"
      data-dump-url="<?= $b ?>/admin/import/<?= $src ?>/upload-dump"
      enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('database') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Connection Method</div>
        <div class="pc-head-sub">Connect to the source database directly, or upload a <code>.sql</code> dump file.</div>
      </div>
    </div>
    <div class="pc-section">
      <div class="import-choice-list">
        <label class="form-check">
          <input type="radio" name="_conn_mode" value="live" id="js-mode-live" checked>
          Live database &mdash; <span class="text-muted">Connect directly via credentials</span>
        </label>
        <label class="form-check">
          <input type="radio" name="_conn_mode" value="dump" id="js-mode-dump">
          Upload SQL dump &mdash; <span class="text-muted">Upload a <code>.sql</code> mysqldump file (max 50 MB)</span>
        </label>
      </div>
    </div>
  </div>

  <div id="js-live-db-section">
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('database') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">Source Database</div>
          <div class="pc-head-sub">
            Use <b>"Test Connection"</b> to confirm the correct database credentials before continuing.<br>
            <?php if ($source === 'enthusiast'): ?>
            If Enthusiast is installed in the <b>same MySQL database</b> as Luster, leave all fields blank. If it is in a <b>separate database</b>, enter those credentials here.<br>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="pc-section">
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="db_host">Database Host</label>
            <input id="db_host" type="text" name="db_host" class="form"
                   placeholder="localhost (blank = use Luster's DB)"
                   value="<?= importOld($old, 'db_host') ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="db_name">Database Name</label>
            <input id="db_name" type="text" name="db_name" class="form"
                   value="<?= importOld($old, 'db_name') ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label" for="db_user">Database User</label>
            <input id="db_user" type="text" name="db_user" class="form"
                   value="<?= importOld($old, 'db_user') ?>">
          </div>
          <div class="form-group">
            <label class="form-label" for="db_pass">Database Password</label>
            <div class="input-icon-wrap">
              <input id="db_pass" type="password" name="db_pass" class="form"
                     autocomplete="new-password">
              <button type="button" class="input-icon-btn" data-pw-toggle aria-label="Show password" tabindex="-1">
                <?= View::icon('eye-on') ?>
                <?= View::icon('eye-off') ?>
              </button>
            </div>
          </div>
        </div>
      </div>
      <div class="composer-footer">
        <button type="button" id="js-test-conn" class="btn btn-secondary"
                data-action="<?= $b ?>/admin/import/<?= $src ?>/test-connection">Test Connection</button>
        <button type="reset" class="btn btn-ghost" id="clear-form-btn">Clear</button>
        <span id="js-test-result" class="form-label--tight is-hidden"></span>
      </div>
    </div>
  </div>

  <div id="js-dump-section" class="is-hidden">
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">Upload SQL Dump</div>
          <div class="pc-head-sub">Upload a <code>.sql</code> file exported by <code>mysqldump</code>. Only <code>CREATE TABLE</code> and <code>INSERT INTO</code> statements are executed; all other SQL is ignored for safety.</div>
        </div>
      </div>
      <div class="pc-section">
        <div class="form-group">
          <label class="form-label" for="sql_dump">SQL File <span class="text-muted">(max 50 MB)</span></label>
          <label class="file-drop-zone" for="sql_dump">
            <input id="sql_dump" type="file" name="sql_dump" accept=".sql,text/plain,text/x-sql,application/sql">
            <span class="file-drop-icon"><?= View::icon('import') ?></span>
            <strong>Drag &amp; drop or click to browse</strong>
            <span class="form-hint">mysqldump export &mdash; max 50 MB</span>
          </label>
          <p class="field-hint-sm">Export your source database with <code>mysqldump --no-tablespaces &lt;dbname&gt; &gt; export.sql</code> and upload the resulting file. The source schema is detected automatically.</p>
        </div>
      </div>
    </div>
  </div>

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Import Options</div>
        <div class="pc-head-sub">Choose what data to import and how to handle records that already exist in Luster.</div>
      </div>
    </div>

    <?php if ($source === 'fanupdate'): ?>
    <div class="pc-section">
      <div class="pc-section-label">Blog Table Name</div>
      <div class="form-group">
        <input id="fu_prefix" type="text" name="fu_prefix" class="form import-input-medium"
               placeholder="blog"
               value="<?= importOld($old, 'fu_prefix', 'blog') ?>">
        <p class="help-text">The main FanUpdate table name from <code>blog-config.php</code>. Most installs use <code>blog</code>. If you enter <code>blog</code>, Luster looks for <code>blog</code>, <code>blog_category</code>, and <code>blog_catjoin</code>.</p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($source === 'listing-admin'): ?>
    <div class="pc-section">
      <div class="pc-section-label">Table Prefix</div>
      <div class="form-group">
        <input id="la_table_prefix" type="text" name="la_table_prefix" class="form import-input-medium"
               placeholder="(leave blank for default install)"
               value="<?= importOld($old, 'la_table_prefix') ?>">
        <p class="help-text">Leave blank for a normal Listing Admin install. If your table names start with extra text, like <code>trex_members</code>, enter that extra text here: <code>trex_</code>. Luster also uses that prefix to find the registry table.</p>
      </div>
    </div>
    <?php endif; ?>

    <div class="pc-section">
      <div class="pc-section-label">Conflict Strategy</div>
      <div class="import-choice-list">
        <label class="form-check">
          <input type="radio" name="conflict_strategy" value="skip"
                 <?= (($old['conflict_strategy'] ?? 'skip') === 'skip') ? 'checked' : '' ?>>
          Skip existing &mdash; <span class="text-muted">Do not overwrite records that are already in Luster</span>
        </label>
        <label class="form-check">
          <input type="radio" name="conflict_strategy" value="overwrite"
                 <?= (($old['conflict_strategy'] ?? '') === 'overwrite') ? 'checked' : '' ?>>
          Overwrite &mdash; <span class="text-muted">Update existing records with source values</span>
        </label>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Data to Import</div>
      <div class="import-choice-list">


      <?php if ($source === 'enthusiast'): ?>
        <label class="form-check">
          <input type="checkbox" name="import_owned_listings" value="1" <?= importChecked($old, 'import_owned_listings') ?>>Owned listings</label>
        <label class="form-check">
          <input type="checkbox" name="import_joined" value="1" <?= importChecked($old, 'import_joined') ?>>Joined listings</label>
        <label class="form-check">
          <input type="checkbox" name="import_categories" value="1" <?= importChecked($old, 'import_categories') ?>>Assigned categories</label>
        <label class="form-check">
          <input type="checkbox" name="import_members" value="1" <?= importChecked($old, 'import_members') ?>>Members</label>
        <label class="form-check">
          <input type="checkbox" name="import_affiliates" value="1" <?= importChecked($old, 'import_affiliates') ?>>Affiliates</label>
        <label class="form-check">
          <input type="checkbox" name="import_settings" value="1" <?= importChecked($old, 'import_settings') ?>>Per-listing settings</label>
        <label class="form-check">
          <input type="checkbox" name="import_templates" value="1" <?= importChecked($old, 'import_templates') ?>>Email templates</label>
        <?php endif; ?>

        <?php if ($source === 'listing-admin'): ?>
        <label class="form-check">
          <input type="checkbox" name="import_listings" value="1" <?= importChecked($old, 'import_listings') ?>>
          Owned listings <span class="text-muted">(creates per-listing member tables in target DB)</span></label>
        <label class="form-check">
          <input type="checkbox" name="import_joined" value="1" <?= importChecked($old, 'import_joined') ?>>Joined listings</label>
        <label class="form-check">
          <input type="checkbox" name="import_categories" value="1" <?= importChecked($old, 'import_categories') ?>>Categories</label>
        <label class="form-check">
          <input type="checkbox" name="import_affiliates" value="1" <?= importChecked($old, 'import_affiliates') ?>>Affiliates</label>
        <label class="form-check">
          <input type="checkbox" name="import_members" value="1" <?= importChecked($old, 'import_members') ?>>Members <span class="text-muted">(requires owned listings to be imported first)</span></label>
        <label class="form-check">
          <input type="checkbox" name="import_updates" value="1" <?= importChecked($old, 'import_updates', false) ?>>Updates <span class="text-muted">(requires News Feed add-on to be installed)</span></label>
        <?php endif; ?>
        <?php if ($source === 'fanupdate'): ?>
        <label class="form-check">
          <input type="checkbox" name="import_updates" value="1" <?= importChecked($old, 'import_updates') ?>>
          Blog entries &rarr; News Feed (requires News Feed add-on)
        </label>
        <?php endif; ?>
        <?php if ($source === 'codesort'): ?>
        <label class="form-check">
          <input type="checkbox" name="import_code_categories" value="1" <?= importChecked($old, 'import_code_categories') ?>>
          Code categories
        </label>
        <label class="form-check">
          <input type="checkbox" name="import_code_sizes" value="1" <?= importChecked($old, 'import_code_sizes') ?>>
          Code sizes (created per-listing for each matched fanlisting)
        </label>
        <label class="form-check">
          <input type="checkbox" name="import_code_donors" value="1" <?= importChecked($old, 'import_code_donors') ?>>
          Code donors
        </label>
        <label class="form-check">
          <input type="checkbox" name="import_codes" value="1" <?= importChecked($old, 'import_codes') ?>>
          Codes (fan graphics)
        </label>
        <?php endif; ?>
      </div>
      <?php if ($source === 'listing-admin'): ?>
      <div class="callout callout-warn" style="margin-top: 0;">
        <?= View::icon('alert') ?>
        <div><strong>IMPORTANT:</strong> Listings must be imported <i><u>before</u></i> members &mdash; members are distributed to per-listing tables created during the listings import.</div>
      </div>
      <?php elseif ($source === 'codesort'): ?>
      <div class="callout callout-info" style="margin-top: 1rem;">
        <?= View::icon('notes') ?>
        <div>Fanlistings are matched by title between CodeSort's <code>collective</code> table and Luster's listings. Codes for unmatched fanlistings are skipped.</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($source !== 'fanupdate'): ?>
  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('image') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Image Migration <span class="badge badge-inactive import-optional-badge">Optional</span></div>
        <div class="pc-head-sub"> Copy affiliate logos, joined listing images, and listing images to Luster's uploads directory. Original files are not deleted.</div>
      </div>
    </div>
    <div class="pc-section">
      <label class="form-check">
        <input type="checkbox" name="import_images" value="1" id="js-images-toggle"
               <?= importChecked($old, 'import_images', true) ?>>Migrate image files
      </label>
      <div id="js-image-options" class="import-image-options<?= (!empty($old['import_images']) || empty($old)) ? '' : ' is-hidden' ?>">
        <label class="form-check">
          <input type="radio" name="image_mode" value="path" id="img-mode-path"
                 <?= (($old['image_mode'] ?? 'path') === 'path') ? 'checked' : '' ?>>Same-server path &mdash; <span class="text-muted">Luster will <code>copy()</code> files directly</span>
        </label>
        <div id="js-img-path-field" class="import-choice-list--indented<?= (($old['image_mode'] ?? 'path') !== 'path') ? ' is-hidden' : '' ?>">
          <input type="text" name="image_path" class="form import-input-wide"
                 placeholder="/absolute/path/to/old-install"
                 value="<?= importOld($old, 'image_path') ?>">
          <p class="field-hint-sm">Absolute server path to the root of your old installation (no trailing slash).
            <?php if ($source === 'codesort'): ?>Code images are expected in the <code>codes/</code> subdirectory.<?php else: ?>Enthusiast images are expected under <code>images/</code>.<?php endif; ?>
          </p>
        </div>
        <label class="form-check">
          <input type="radio" name="image_mode" value="url" id="img-mode-url"
                 <?= (($old['image_mode'] ?? '') === 'url') ? 'checked' : '' ?>>
          Remote URL &mdash; <span class="text-muted">Luster will fetch files via HTTP</span>
        </label>
        <div id="js-img-url-field" class="import-choice-list--indented<?= (($old['image_mode'] ?? '') !== 'url') ? ' is-hidden' : '' ?>">
          <input type="url" name="image_url" class="form import-input-wide"
                 placeholder="https://example.com/old-install"
                 value="<?= importOld($old, 'image_url') ?>">
          <p class="field-hint-sm">Base URL of your old installation (no trailing slash). Luster adds the needed image path after it.
            <?php if ($source === 'codesort'): ?>For CodeSort, that means <code>/codes/</code> plus the code image filename.<?php else: ?>For other imports, Luster uses the standard image subfolders.<?php endif; ?></p>
        </div>
        <label class="form-check">
          <input type="radio" name="image_mode" value="zip"
                 <?= (($old['image_mode'] ?? '') === 'zip') ? 'checked' : '' ?>>
          Skip now &mdash; <span class="text-muted">I'll upload a ZIP after import</span>
        </label>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="actions-row actions-row--wrap" style="justify-content:space-between;margin-top:1.5rem">
    <a href="<?= $b ?>/admin/import" class="btn btn-ghost">&#8592; Back</a>
    <button type="submit" id="js-submit-btn" class="btn btn-primary">Preview Import &#8594;</button>
  </div>

</form>
