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
  <div class="wizard-steps-progress" style="width:33%"></div>
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
  <div class="alert alert-error" style="margin-bottom:1.25rem"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/import/<?= $src ?>/configure">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

 
  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('database') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Source Database</div>
        <div class="pc-head-sub"> 
        Use <b>"Test Connection"</b> to confirm the correct database credentials before continuing.<br>
      <?php if ($source === 'enthusiast'): ?>
        If Enthusiast is installed in the <b>same MySQL database</b> as Luster, leave all fields blank. If it is in a <b>separate database</b>, enter those credentials here.<br>
    <?php endif; ?></div>
      </div>
    </div>
    <div class="pc-section">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="db_host">Database Host</label>
          <input id="db_host" type="text" name="db_host" class="form-input"
                 placeholder="localhost (blank = use Luster's DB)"
                 value="<?= importOld($old, 'db_host') ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="db_name">Database Name</label>
          <input id="db_name" type="text" name="db_name" class="form-input"
                 value="<?= importOld($old, 'db_name') ?>">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="db_user">Database User</label>
          <input id="db_user" type="text" name="db_user" class="form-input"
                 value="<?= importOld($old, 'db_user') ?>">
        </div>
        <div class="form-group">
          <label class="form-label" for="db_pass">Database Password</label>
          <div style="position:relative">
            <input id="db_pass" type="password" name="db_pass" class="form-input"
                   style="padding-right:42px" autocomplete="new-password">
            <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1"
                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--text-dim);padding:4px;line-height:0"
                    onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-dim)'">
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
      <button type="reset" class="btn btn-ghost" id="clear-form-btn">Clear Form</button>
      <span id="js-test-result" style="display:none;font-size:var(--fs-base);font-weight:600"></span>
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
        <input id="fu_prefix" type="text" name="fu_prefix" class="form-input"
               placeholder="blog"
               value="<?= importOld($old, 'fu_prefix', 'blog') ?>"
               style="max-width:320px">
        <p class="help-text">The base table name configured in <code>blog-config.php</code> (default: <code>blog</code>). Luster will look for <code>{name}</code>, <code>{name}_category</code>, and <code>{name}_catjoin</code>.</p>
      </div>
    </div>
    <?php endif; ?>

    <div class="pc-section">
      <div class="pc-section-label">Conflict Strategy</div>
      <div style="display:flex;flex-direction:column;gap:.5rem;margin-left:1rem">
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
      <div style="display:flex;flex-direction:column;gap:.5rem;margin-left:1rem">


      <?php if ($source === 'enthusiast'): ?>
        <label class="form-check">
          <input type="checkbox" name="import_owned_listings" value="1" <?= importChecked($old, 'import_owned_listings') ?>>Owned listings</label>
        <label class="form-check">
          <input type="checkbox" name="import_joined" value="1" <?= importChecked($old, 'import_joined') ?>>Joined listings</label>
        <label class="form-check">
          <input type="checkbox" name="import_categories" value="1" <?= importChecked($old, 'import_categories') ?>>Assigned categories</label>
        <?php if (!empty($enth_networks)): ?>
        <div style="margin-left:1.75rem;margin-top:.25rem">
          <label class="form-label" for="category_parent_id" style="font-size:.8rem;margin-bottom:.3rem">Place top-level categories under network</label>
          <div class="select-wrap" style="max-width:280px"><select id="category_parent_id" name="category_parent_id" class="form-select">
            <option value="">— Leave as top-level —</option>
            <?php foreach ($enth_networks as $net): ?>
              <option value="<?= View::e((string) $net['id']) ?>"
                <?= (string) ($old['category_parent_id'] ?? '') === (string) $net['id'] ? 'selected' : '' ?>>
                <?= View::e($net['name']) ?>
              </option>
            <?php endforeach; ?>
          </select></div>
          <p class="field-hint-sm" style="margin-top:.3rem">Enthusiast has no network concept. Select a parent network (e.g. TFL) to import its categories as children rather than top-level entries.</p>
        </div>
        <?php endif; ?>
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
          <input type="checkbox" name="import_updates" value="1" <?= importChecked($old, 'import_updates', false) ?>>Updates <span class="text-muted">(requires Updates Feed add-on to be installed)</span></label>
        <?php endif; ?>
        <?php if ($source === 'fanupdate'): ?>
        <label class="form-check">
          <input type="checkbox" name="import_updates" value="1" <?= importChecked($old, 'import_updates') ?>>
          Blog entries &rarr; Updates Feed (requires Updates Feed add-on)
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
      <div class="callout" style="margin-bottom: 0;">
        <?= View::icon('alert', ['width' => 20, 'height' => 20, 'style' => 'color:var(--pending)']) ?>
        <strong>IMPORTANT:</strong> Listings must be imported <i><u>before</u></i> members &mdash; members are distributed to per-listing tables created during the listings import.
      </div>
      <?php elseif ($source === 'codesort'): ?>
      <div class="callout" style="margin-top:1rem;margin-bottom:0">
        <?= View::icon('alert') ?>
        <span>Fanlistings are matched by title between CodeSort&rsquo;s <code>collective</code> table and Luster&rsquo;s listings. Codes for unmatched fanlistings are skipped.</span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($source !== 'fanupdate'): ?>
  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('image') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Image Migration <span class="badge badge-inactive" style="font-family: 'DM Sans', sans-serif; margin-left: 1rem;">Optional</span></div>
        <div class="pc-head-sub"> Copy affiliate logos, joined listing images, and listing images to Luster&rsquo;s uploads directory. Original files are not deleted.</div>
      </div>
    </div>
    <div class="pc-section">
      <label class="form-check">
        <input type="checkbox" name="import_images" value="1" id="js-images-toggle"
               <?= importChecked($old, 'import_images', true) ?>>Migrate image files
      </label>
      <div id="js-image-options" style="border-left:2px solid var(--border);padding-left:1.25rem;margin-left:.25rem;margin-top:.5rem;display:<?= (!empty($old['import_images']) || empty($old)) ? 'flex' : 'none' ?>;flex-direction:column;gap:.5rem">
        <label class="form-check">
          <input type="radio" name="image_mode" value="path" id="img-mode-path"
                 <?= (($old['image_mode'] ?? 'path') === 'path') ? 'checked' : '' ?>>Same-server path &mdash; <span class="text-muted">Luster will <code>copy()</code> files directly</span>
        </label>
        <div id="js-img-path-field" style="padding-left:1.375rem;margin-top:-.125rem;<?= (($old['image_mode'] ?? 'path') !== 'path') ? 'display:none' : '' ?>">
          <input type="text" name="image_path" class="form-input"
                 placeholder="/absolute/path/to/old-install"
                 value="<?= importOld($old, 'image_path') ?>"
                 style="max-width:480px">
          <p class="field-hint-sm">Absolute server path to the root of your old installation (no trailing slash).
            <?php if ($source === 'codesort'): ?>Code images are expected in the <code>codes/</code> subdirectory.<?php else: ?>Enthusiast images are expected under <code>images/</code>.<?php endif; ?>
          </p>
        </div>
        <label class="form-check">
          <input type="radio" name="image_mode" value="url" id="img-mode-url"
                 <?= (($old['image_mode'] ?? '') === 'url') ? 'checked' : '' ?>>
          Remote URL &mdash; <span class="text-muted">Luster will fetch files via HTTP</span>
        </label>
        <div id="js-img-url-field" style="padding-left:1.375rem;margin-top:-.125rem;<?= (($old['image_mode'] ?? '') !== 'url') ? 'display:none' : '' ?>">
          <input type="url" name="image_url" class="form-input"
                 placeholder="https://example.com/old-install"
                 value="<?= importOld($old, 'image_url') ?>"
                 style="max-width:480px">
          <p class="field-hint-sm">Base URL of your old installation (no trailing slash). Luster will append
            <?php if ($source === 'codesort'): ?><code>/codes/{filename}</code><?php else: ?>the standard image sub-paths<?php endif; ?>.</p>
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

  <div class="btn-row" style="align-items:center;flex-wrap:wrap">
    <a href="<?= $b ?>/admin/import" class="btn btn-ghost">&#8592; Back</a>
    <button type="submit" class="btn btn-primary">Preview Import &#8594;</button>
  </div>

</form>

