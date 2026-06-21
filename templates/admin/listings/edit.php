<?php
use Luster\Core\View;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Support\RulesBodyRenderer;
/** @var \Luster\Models\Listing    $listing */
/** @var string                       $error */
/** @var string                       $success */
/** @var array                        $old */
/** @var array                        $field_errors */
/** @var array                        $cfg */
/** @var string|null                  $current_image */
/** @var int                          $approved */
/** @var int                          $pending */
/** @var \Luster\Models\Affiliate[]        $affiliates */
/** @var \Luster\Models\AdditionalField[]  $fields */
/** @var array                        $templates */
/** @var \Luster\Models\Category[]    $categories */
/** @var int|null                     $current_category_id */
/** @var string                       $base_url */
/** @var string                       $csrf_token */
$b  = rtrim(View::e($base_url), '/');
$fe = $field_errors ?? [];

$sizeMap   = ['small' => '50px', 'medium' => '100px', 'large' => '150px'];
$sizeKey   = (string) ($cfg['image_preview_size'] ?? 'medium');
$imgHeight = $sizeMap[$sizeKey] ?? '100px';

// Resolve display values: prefer $old (re-populates on validation fail) then model
$oldTitle  = $old['title']  ?? $listing->title;
$oldUrl    = $old['url']    ?? $listing->url;
$oldEmail  = $old['email']  ?? $listing->email;

// Resolve current status string
if ($listing->upcoming) {
    $currentStatus = 'upcoming';
} elseif ($listing->trouble) {
    $currentStatus = 'pending';
} else {
    $currentStatus = 'current';
}
$oldStatus = $old['status'] ?? $currentStatus;

// Cat-widget JSON data
$dataTfl       = json_encode($tfl_cats       ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataTafl      = json_encode($tafl_cats      ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustom    = json_encode($custom_networks ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
$dataCustomCats = json_encode($custom_cats   ?? [], JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);

// Build category lookup from all available sources
$catLookup = [];
foreach ($tfl_cats  ?? [] as $c) { $catLookup[(int) $c['id']] = ['network' => 'TFL',  'name' => $c['name']]; }
foreach ($tafl_cats ?? [] as $c) { $catLookup[(int) $c['id']] = ['network' => 'TAFL', 'name' => $c['name']]; }
foreach ($custom_cats ?? [] as $net => $cats) {
    foreach ($cats as $c) { $catLookup[(int) $c['id']] = ['network' => $net, 'name' => $c['name']]; }
}

// Pre-populate cat-widget: posted arrays (validation error) → junction table → catid fallback
$existingCats = [];
$oldNets  = $old['cat_network']       ?? null;
$oldNames = $old['cat_name']          ?? null;
$oldOther = $old['cat_network_other'] ?? [];
if (is_array($oldNets) && is_array($oldNames)) {
    foreach ($oldNets as $i => $net) {
        $net = trim((string) $net);
        $nm  = trim((string) ($oldNames[$i] ?? ''));
        if ($net === 'other') {
            $net = trim((string) ($oldOther[$i] ?? ''));
        }
        if ($net !== '' && $nm !== '') {
            $existingCats[] = ['network' => $net, 'name' => $nm];
        }
    }
} elseif (!empty($current_category_ids ?? [])) {
    foreach ($current_category_ids as $cid) {
        if (isset($catLookup[(int) $cid])) {
            $existingCats[] = $catLookup[(int) $cid];
        }
    }
} else {
    $catidRaw = (string) ($listing->catid ?? '');
    $catIds   = array_filter(explode('|', $catidRaw), static fn($v) => $v !== '');
    foreach ($catIds as $cid) {
        if (isset($catLookup[(int) $cid])) {
            $existingCats[] = $catLookup[(int) $cid];
        }
    }
}
$dataExisting = json_encode($existingCats, JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings" class="link">
    &larr; Back to Owned Listings
  </a>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $listing->id ?>&status=all" class="btn btn-quaternary btn-sm">Member List</a>
    <a href="<?= $b ?>/admin/emails?tab=bulk&listing=<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">Bulk Email</a>
    <?php if (in_array('Kim', $nav_installed_addons ?? [], true)): ?>
      <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/show" class="btn btn-quaternary btn-sm">KIM List</a>
    <?php endif; ?>
    <?php if (in_array('Updates', $nav_installed_addons ?? [], true)): ?>
      <a href="<?= $b ?>/admin/updates/<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">Updates Feed</a>
    <?php endif; ?>
    <form method="post"
          action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/delete"
          class="action-form-inline"
          data-del-modal data-del-entity="listing"
          data-del-name="<?= View::e($listing->title) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger btn-sm"><?= View::icon('delete') ?> Delete Listing</button>
    </form>
  </div>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="stats-grid stats-grid-row">
<?php $pl = View::e($b . '/admin/members?listing_id=' . (int) $listing->id . '&status=pending'); ?>
<div class="stat-card">
  <div class="stat-header">Total Members</div>
  <div class="stat-value"><?= (int) $approved ?></div>
</div>
<div class="stat-card">
  <div class="stat-header">Pending</div>
  <div class="stat-value"><?= $pending > 0 ? '<a href="' . $pl . '" class="link pending-link">' . $pending . '</a>' : $pending ?></div>
</div>
<div class="stat-card">
  <div class="stat-header">Total Countries</div>
  <div class="stat-value"><?= (int) ($countries ?? 0) ?></div>
</div>
<div class="stat-card">
  <div class="stat-header">Last Updated</div>
  <div class="stat-value"><?= View::e(($fl_last_updated ?? '') !== '' ? date($admin_date_format, strtotime($fl_last_updated)) : '-') ?></div>
</div>
</div>

<div class="tabs-wrap">
<div class="tabs">
  <button class="tabs-btn active" data-tab="general">General</button>
  <button class="tabs-btn" data-tab="fields">Additional Fields</button>
  <button class="tabs-btn" data-tab="codes">Codes</button>
  <button class="tabs-btn" data-tab="affiliates">Affiliates<?php if (!empty($affiliates)): ?> <span class="badge badge-inactive badge-inline-sm"><?= count($affiliates) ?></span><?php endif; ?></button>
  <button class="tabs-btn" data-tab="templates">Templates</button>
  <?php if ($rules_addon_installed ?? false): ?>
  <button class="tabs-btn" data-tab="rules">Rules</button>
  <?php endif; ?>
  <button class="tabs-btn" data-tab="embed">Embed</button>
  <button class="tabs-btn" data-tab="advanced">Advanced</button>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">Section</span>
  <div class="select-wrap flex-fill">
    <select class="tabs-select">
      <option value="general">General</option>
      <option value="fields">Additional Fields</option>
      <option value="codes">Codes</option>
      <option value="affiliates">Affiliates<?= !empty($affiliates) ? ' (' . count($affiliates) . ')' : '' ?></option>
      <option value="templates">Templates</option>
      <?php if ($rules_addon_installed ?? false): ?>
      <option value="rules">Rules</option>
      <?php endif; ?>
      <option value="embed">Embed</option>
      <option value="advanced">Advanced</option>
    </select>
  </div>
</div>
</div>
<!-- ──── TAB: GENERAL ───────────────────────────────────────────────── -->
<div id="tab-general" class="tabs-panel active">
  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" enctype="multipart/form-data" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('star') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">General Details</div>
          <div class="pc-head-sub">Manage the listing's details, image, status, and public form settings.</div>
        </div>
      </div>
      <div class="pc-section">
        <div class="pc-section-label">Details</div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Network &amp; Category</label>
          <div>
            <div class="cat-widget" id="cat-widget"
                 data-tfl='<?= $dataTfl ?>'
                 data-tafl='<?= $dataTafl ?>'
                 data-custom='<?= $dataCustom ?>'
                 data-custom-cats='<?= $dataCustomCats ?>'
                 data-existing='<?= $dataExisting ?>'>
              <div id="cat-rows" class="select-auto"></div>
              <a href="#" class="link btn btn-sm" id="cat-add"><?= View::icon('add') ?> Add another category</a>
            </div>
            <p class="form-hint">The network(s) and category(ies) this listing is listed under.</p>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-subject">Subject</label>
          <div>
            <input id="fl-subject" type="text" name="subject" class="form"
                   value="<?= View::e($listing->subject ?? '') ?>" maxlength="255">
            <p class="form-hint form-hint-inline">The subject of your listing (e.g. character name, show title).</p>
          </div>
        </div>

        <div class="listing-field-row<?= !empty($fe['title']) ? ' has-error' : '' ?>">
          <label class="listing-field-label" for="fl-title">Title</label>
          <div>
            <input id="fl-title" type="text" name="title" class="form"
                   value="<?= View::e((string) $oldTitle) ?>"
                   maxlength="255" required>
            <p class="form-hint">Optional display name for your listing. If you leave it blank, Luster uses the subject.</p>
            <?php if (!empty($fe['title'])): ?><p class="field-error"><?= View::e($fe['title'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="listing-field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
          <label class="listing-field-label" for="fl-url">Website URL <span class="req">*</span></label>
          <div>
            <input id="fl-url" type="url" name="url" class="form"
                   value="<?= View::e((string) $oldUrl) ?>"
                   maxlength="500" placeholder="https://" required>
            <?php if (!empty($fe['url'])): ?><p class="field-error"><?= View::e($fe['url'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top<?= !empty($fe['email']) ? ' has-error' : '' ?>">
          <label class="listing-field-label" for="fl-email">Contact Email</label>
          <div>
            <input id="fl-email" type="email" name="email" class="form"
                   value="<?= View::e((string) $oldEmail) ?>"
                   maxlength="255" required>
            <p class="form-hint form-hint-inline">Luster uses this address as the sender for emails from this listing.</p>
            <?php if (!empty($fe['email'])): ?><p class="field-error"><?= View::e($fe['email'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-description">Description</label>
          <div>
            <textarea id="fl-description" name="description" class="form" rows="4"
                      maxlength="65535"
                      placeholder="A short description of this listing&hellip;"><?= View::e($description ?? '') ?></textarea>
            <p class="form-hint">Optional. Available to embed templates via the <code>{listing_description}</code> token.</p>
          </div>
        </div>

        <div class="listing-field-row">
          <label class="listing-field-label">Status</label>
          <div class="status-pill-row" id="status-pills">
            <label class="status-pill status-pill--current">
              <input type="radio" name="status" value="current"  <?= $oldStatus === 'current'  ? 'checked' : '' ?>> Current
            </label>
            <label class="status-pill status-pill--upcoming">
              <input type="radio" name="status" value="upcoming" <?= $oldStatus === 'upcoming' ? 'checked' : '' ?>> Upcoming
            </label>
            <label class="status-pill status-pill--pending">
              <input type="radio" name="status" value="pending"  <?= $oldStatus === 'pending'  ? 'checked' : '' ?>> Pending
            </label>
          </div>
        </div>

        <div class="listing-field-row" id="opened-row">
          <label class="listing-field-label" for="fl-opened">Opened On</label>
          <div>
            <?php
              $openedRaw = (string) ($listing->opened ?? '');
              $openedDateVal = strlen($openedRaw) >= 10 ? substr($openedRaw, 0, 10) : '';
            ?>
            <input id="fl-opened" type="date" name="opened" class="form form--compact"
                   value="<?= View::e($openedDateVal) ?>">
            <input type="hidden" id="fl-opened-tba" name="opened" value="TBA" disabled>
            <span id="fl-opened-tba-text" class="tba-note">TBA &mdash; date will be set when the listing opens.</span>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-last-updated">Last Updated</label>
          <div>
            <div class="actions-row actions-row--wrap">
              <input id="fl-last-updated" type="date" name="fl_last_updated" class="form form--compact"
                     value="<?= View::e($fl_last_updated ?? '') ?>">
              <button type="button" class="btn btn-tertiary btn-sm" id="set-today-btn">Set Today's Date</button>
              <button type="button" class="btn btn-quaternary btn-sm" id="clear-date-btn">Reset</button>
            </div>
          </div>
        </div>
      </div>


      <div class="pc-section">
        <div class="pc-section-label">Listing Image</div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Image</label>
          <div>
            <?php if (isset($current_image) && $current_image !== null && $current_image !== ''): ?>
              <div class="existing-image-row upload-panel-row">
                <div class="existing-img-placeholder">
                  <img src="<?= $b ?>/uploads/listings/<?= View::e($current_image) ?>"
                       alt="<?= View::e($current_image) ?>">
                </div>
                <div>
                  <div class="file-meta-name"><?= View::e($current_image) ?></div>
                  <label class="del-img-check">
                    <input type="checkbox" id="fl-delete-image" name="delete_image" value="1">
                    Delete current image
                  </label>
                </div>
              </div>
              <p class="replace-note">Replace with new image</p>
            <?php endif; ?>
            <label class="file-drop-zone">
              <input type="file" id="thumbnail" name="image" accept="image/jpeg,image/png,image/gif,image/webp"><span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Drag &amp; drop or click to browse</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
            </label>
          </div>
        </div>
      </div>


      <div class="pc-section">
        <div class="pc-section-label">Preferences</div>
        <?php $lt = $listing_type ?? 'listing'; ?>
        <div class="listing-field-row">
          <label class="listing-field-label" for="fl-listing-type">Type</label>
          <div class="select-wrap select-auto">
            <select id="fl-listing-type" name="listing_type" >
              <option value="listing"    <?= $lt === 'listing'    ? 'selected' : '' ?>>Fanlisting</option>
              <option value="clique"     <?= $lt === 'clique'     ? 'selected' : '' ?>>Clique</option>
              <option value="collective" <?= $lt === 'collective' ? 'selected' : '' ?>>Collective</option>
              <option value="shrine"     <?= $lt === 'shrine'     ? 'selected' : '' ?>>Shrine</option>
              <option value="webring"    <?= $lt === 'webring'    ? 'selected' : '' ?>>Webring</option>
              <option value="other"      <?= $lt === 'other'      ? 'selected' : '' ?>>Other</option>
            </select>
          </div>
        </div>

        <?php $ab = $approved_by ?? ''; ?>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-approved-by">Directory Approval</label>
          <div>
            <div class="select-wrap select-auto">
              <select id="fl-approved-by" name="approved_by">
                <option value=""     <?= $ab === ''     ? 'selected' : '' ?>>Not approved / N/A</option>
                <option value="tfl"  <?= $ab === 'tfl'  ? 'selected' : '' ?>>Approved by TFL</option>
                <option value="tafl" <?= $ab === 'tafl' ? 'selected' : '' ?>>Approved by TAFL</option>
              </select>
            </div>
            <p class="form-hint">Mark whether this fanlisting is officially approved by The Fanlistings Network (TFL) or The Anime Fanlistings Network (TAFL).</p>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="fl-members-per-page">Members per page</label>
          <div>
            <input id="fl-members-per-page" type="number" name="members_per_page" class="form form--compact"
                   min="5" max="200" value="<?= (int) ($members_per_page ?? 25) ?>">
            <p class="form-hint">How many members to show on each admin members page for this listing. Choose 5&ndash;200.</p>
          </div>
        </div>

        <div class="listing-field-row">
          <label class="listing-field-label">Contact Form</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="contact_enabled" value="1"
                     <?= ($contact_enabled ?? true) ? 'checked' : '' ?>>
              <span class="toggle-label">Enable contact form for this listing</span>
            </label>
          </div>
        </div>


        <?php if ($adoption_addon_installed ?? false): ?>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Adoption</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="for_adoption" value="1" id="fl-for-adoption"
                     <?= ($for_adoption ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Accept adoption applications</span>
            </label>
            <p class="form-hint">Make this listing available in the collective adoption form and collect applications in the admin panel.</p>
            <?php if (($for_adoption ?? false) && ($adoption_count ?? 0) > 0): ?>
            <p class="form-hint">
              <a href="<?= $b ?>/admin/adoptions/<?= (int) $listing->id ?>" class="link">
                View <?= (int) $adoption_count ?> application<?= $adoption_count !== 1 ? 's' : '' ?> &rarr;
              </a>
            </p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Code Donations</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="codes_submit_enabled" value="1" id="fl-codes-submit-enabled"
                     <?= ($embed_settings['codes_submit_enabled'] ?? true) ? 'checked' : '' ?>>
              <span class="toggle-label">Allow fans to donate codes for this listing</span>
            </label>
            <p class="form-hint">Enables the public code submission form. Submissions appear in the Codes tab for review.</p>
            <?php if ($embed_settings['codes_submit_enabled'] ?? true): ?>
            <div style="margin-top:.75rem">
              <?php
                $_cae = $code_approval_email ?? null;
                $_caeVal = $_cae === null ? '' : ($_cae ? '1' : '0');
              ?>
              <div class="select-wrap select-auto">
                <select name="code_approval_email">
                  <option value=""  <?= $_caeVal === ''  ? 'selected' : '' ?>>Follow global setting (email donor on approval)</option>
                  <option value="1" <?= $_caeVal === '1' ? 'selected' : '' ?>>Always email donor on approval</option>
                  <option value="0" <?= $_caeVal === '0' ? 'selected' : '' ?>>Never email donor on approval</option>
                </select>
              </div>
              <p class="form-hint">Controls the <em>Code Donation Approved</em> email for this listing. The global setting is configured in Settings &rarr; General.</p>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Affiliate Applications</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="affiliate_apply_enabled" value="1" id="fl-affiliate-apply-enabled"
                     <?= ($affiliate_apply_enabled ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Accept affiliate applications for this listing</span>
            </label>
            <p class="form-hint">Enables a public application form. Submissions appear in
              <a href="<?= $b ?>/admin/affiliates/pending" class="link">Affiliates &rarr; Pending</a>.
            </p>
          </div>
        </div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label">Join Settings</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="auto_approve_joins" value="1" id="fl-auto-approve-joins"
                     <?= ($auto_approve_joins ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Auto-approve new joins for this listing</span>
            </label>
            <p class="form-hint">New joins are held for manual approval by default. Enable to approve them automatically for this listing only.</p>
          </div>
        </div>

        <div class="listing-field-row">
          <label class="listing-field-label">Update Settings</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="queue_updates" value="1" id="fl-queue-updates"
                     <?= ($queue_updates ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Hold updates for review</span>
            </label>
            <p class="form-hint">Name, email, URL, country, and custom field changes wait for admin approval. Password changes always apply immediately. Review pending updates in the Members page.</p>
          </div>
        </div>
      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="button" class="btn btn-ghost js-form-reset">Reset</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>
    </div>
  </form>
</div>


<?php
// Templates tab — local helpers
$hasUpdatesAddon  = (bool) ($updates_addon_installed ?? false);
$hasCodesSubmit   = (bool) ($embed_settings['codes_submit_enabled'] ?? true);
$hasRulesAddon    = (bool) ($rules_addon_installed ?? false) && (bool) ($rules_enabled_for_listing ?? false);

// For templates that have known code defaults, treat a DB value that
// matches any recognised old default format as absent (null) so the
// current code default is shown instead. This lets default changes
// flow through to the textarea without a manual per-listing migration.
$_isOldStatsTemplate = static function (?string $v): bool {
    return $v !== null && str_starts_with(ltrim($v), '<div class="fl-stats">');
};

$_isOldMembersTemplate = static function (?string $v): bool {
    if ($v === null || trim($v) === '') return false;
    // Pre-sort-bar default: starts with {if_members} and has no sort link tokens
    if (str_starts_with(ltrim($v), '{if_members}') && !str_contains($v, '{sort_link_all}')) return true;
    return false;
};
// When no per-listing override exists, show the global default if one is set, otherwise the built-in.
// This keeps the textarea contents and reset-button targets consistent with the badge showing 'Global'.
$_gFallback = static function (string $builtIn, string $globalKey) use ($global_templates): string {
    $g = ($global_templates ?? [])[$globalKey] ?? null;
    return ($g !== null && trim($g) !== '') ? $g : $builtIn;
};

$tplMembersVal   = ($_isOldMembersTemplate($templates['members_template'] ?? null) || ($templates['members_template'] ?? '') === '')
    ? $_gFallback(ListingSettingsRepo::defaultMembersTemplate(), 'listing_default_members_template')
    : ($templates['members_template'] ?? '');
$_isOldAffTemplate = static function (?string $v): bool {
    // Pre-heading default: starts with {if_affiliates} and has no <h1>
    return $v !== null && trim($v) !== ''
        && str_starts_with(ltrim($v), '{if_affiliates}')
        && !str_contains($v, '<h1>');
};
$tplAffVal       = ($_isOldAffTemplate($templates['affiliates_section_template'] ?? null) || ($templates['affiliates_section_template'] ?? '') === '')
    ? $_gFallback(ListingSettingsRepo::defaultAffiliatesSectionTemplate(), 'listing_default_affiliates_section_template')
    : ($templates['affiliates_section_template'] ?? '');
$_statsNeedsReset = static function (?string $v) use ($_isOldStatsTemplate): bool {
    if ($v === null || trim($v) === '') return true;
    if ($_isOldStatsTemplate($v)) return true;
    // Migrate templates that still use the old {stat_newmembers_label} token
    if (str_contains($v, '{stat_newmembers_label}')) return true;
    // Migrate templates that still use the retired {stat_last_updated} or {stat_newmembers_oxford} tokens
    if (str_contains($v, '{stat_last_updated}') || str_contains($v, '{stat_newmembers_oxford}')) return true;
    // Migrate templates that look like the old built-in default (no h1, no stat_countries)
    if (str_starts_with(ltrim($v), '<p>Members:') && !str_contains($v, '{stat_countries}')) return true;
    // Migrate previous defaults that used "<b>Newest:</b>" or lacked internal newlines
    if (str_contains($v, '<b>Newest:</b>') && !str_contains($v, '<b>Newest Members:</b>')) return true;
    if (str_contains($v, '<b>Newest Members:</b>') && !str_contains($v, "\n")) return true;
    return false;
};
$tplStatsVal     = $_statsNeedsReset($templates['stats_template'] ?? null)
    ? $_gFallback(ListingSettingsRepo::defaultStatsTemplate(), 'listing_default_stats_template')
    : ($templates['stats_template'] ?? '');
$tplUpdatesVal      = ($templates['updates_template']            ?? '') ?: $_gFallback(ListingSettingsRepo::defaultUpdatesTemplate(),              'listing_default_updates_template');
$tplCodesFormVal    = ($templates['codes_submit_form_template']    ?? '') ?: $_gFallback(ListingSettingsRepo::defaultCodesSubmitFormTemplate(),    'listing_default_codes_submit_form_template');
$tplAffApplyFormVal = ($templates['affiliate_apply_form_template'] ?? '') ?: $_gFallback(ListingSettingsRepo::defaultAffiliateApplyFormTemplate(), 'listing_default_affiliate_apply_form_template');
$tplJoinFormVal     = ($templates['join_form_template']              ?? '') ?: $_gFallback(ListingSettingsRepo::defaultJoinFormTemplate(),          'listing_default_join_form_template');
$tplCodesVal        = ($templates['codes_template']                  ?? '') ?: $_gFallback(ListingSettingsRepo::defaultCodesTemplate(),           'listing_default_codes_template');
$tplUpdateFormVal   = ($templates['update_form_template']             ?? '') ?: $_gFallback(ListingSettingsRepo::defaultUpdateFormTemplate(), 'listing_default_update_form_template');
$tplResetFormVal    = ($templates['reset_form_template']              ?? '') ?: $_gFallback(ListingSettingsRepo::defaultResetFormTemplate(),  'listing_default_reset_form_template');
$tplContactFormVal  = ($templates['contact_form_template']           ?? '') ?: $_gFallback(ListingSettingsRepo::defaultContactFormTemplate(), 'listing_default_contact_form_template');
$tplContactSentVal  = ($templates['contact_sent_template']           ?? '') ?: $_gFallback(ListingSettingsRepo::defaultContactSentTemplate(), 'listing_default_contact_sent_template');

$activeGroup = (string) ($_GET['group'] ?? 'all');
$_validGroups = ['all', 'members', 'join-form', 'update-form', 'reset-form', 'statistics', 'codes', 'codes-form', 'affiliates', 'affiliate-form', 'updates', 'contact-form', 'contact-sent'];
if (!in_array($activeGroup, $_validGroups, true)) { $activeGroup = 'all'; }
$_grp = static fn(string $g) => ($activeGroup !== 'all' && $activeGroup !== $g) ? 'hidden' : '';

// Source badge helper for Templates tab
$tplSrc = $template_sources ?? [];
$srcBadge = static function (string $key) use ($tplSrc): string {
    return match ($tplSrc[$key] ?? 'built_in') {
        'custom'   => '<span class="badge badge-current badge-inline-sm">Custom</span>',
        'global'   => '<span class="badge badge-info badge-inline-sm">Global</span>',
        default    => '<span class="badge badge-inactive badge-inline-sm">Built-in</span>',
    };
};

// tok() helper — matches the collective templates page pattern
$ltok = static fn(string $ta, string $token, string $label, string $desc): string =>
    '<tr class="tok-row" tabindex="0" role="button"'
    . ' title="'          . View::e($desc)  . '"'
    . ' aria-label="Insert ' . View::e($label) . '. ' . View::e($desc) . '"'
    . ' data-textarea="'  . View::e($ta)    . '"'
    . ' data-token="'     . View::e($token) . '">'
    . '<td class="token-cell" data-desc="' . View::e($desc) . '"><code>' . View::e($label) . '</code></td>'
    . '</tr>' . "\n";
?>
<div id="tab-templates" class="tabs-panel">

<div class="tpl-group-selector-bar">
  <div class="select-wrap">
    <select id="tpl-group-select" class="form">
      <option value="all"<?= $activeGroup === 'all' ? ' selected' : '' ?>>All Templates</option>
      <option value="members"<?= $activeGroup === 'members' ? ' selected' : '' ?>>Members List</option>
      <optgroup label="Membership Forms">
        <option value="join-form"<?= $activeGroup === 'join-form' ? ' selected' : '' ?>>Join Form</option>
        <option value="update-form"<?= $activeGroup === 'update-form' ? ' selected' : '' ?>>Update Membership Form</option>
        <option value="reset-form"<?= $activeGroup === 'reset-form' ? ' selected' : '' ?>>Lost Password Form</option>
      </optgroup>
      <option value="statistics"<?= $activeGroup === 'statistics' ? ' selected' : '' ?>>Statistics</option>
      <optgroup label="Codes &amp; Donation Form">
        <option value="codes"<?= $activeGroup === 'codes' ? ' selected' : '' ?>>Codes Display</option>
        <option value="codes-form"<?= $activeGroup === 'codes-form' ? ' selected' : '' ?>>Code Donation Form</option>
      </optgroup>
      <optgroup label="Affiliates">
        <option value="affiliates"<?= $activeGroup === 'affiliates' ? ' selected' : '' ?>>Affiliates Display</option>
        <option value="affiliate-form"<?= $activeGroup === 'affiliate-form' ? ' selected' : '' ?>>Affiliate Application</option>
      </optgroup>
      <optgroup label="Contact Form">
        <option value="contact-form"<?= $activeGroup === 'contact-form' ? ' selected' : '' ?>>Contact Form</option>
        <option value="contact-sent"<?= $activeGroup === 'contact-sent' ? ' selected' : '' ?>>Contact Confirmation</option>
      </optgroup>
      <option value="updates"<?= $activeGroup === 'updates' ? ' selected' : '' ?>>Updates Feed</option>
    </select>
  </div>
</div>

<form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/templates" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="return_group" id="tpl-return-group" value="<?= View::e($activeGroup) ?>">

  <div class="composer-grid">

    <!-- ── Left column: template editors ──────────────────────────────── -->
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('embed') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">Listing HTML Templates</div>
          <div class="pc-head-sub">HTML that Luster prints for this listing's members, affiliates, stats, and public forms. Badges show whether each template is a custom override, inherited from <a href="<?= $b ?>/admin/listings/global-templates" class="link">global defaults</a>, or the built-in fallback.</div>
        </div>
      </div>

      <div class="pc-section">

        <div class="tpl-group-section" data-tpl-group="members" <?= $_grp('members') ?>>
          <div class="pc-section-label">Members List <?= $srcBadge('members_template') ?></div>
          <div class="form-group">
            <textarea id="members_template" name="members_template" class="form form-mono" spellcheck="false"><?= View::e($tplMembersVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="members" data-textarea="members_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="members_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="affiliates" <?= $_grp('affiliates') ?>>
          <div class="pc-section-label">Affiliates Section <?= $srcBadge('affiliates_section_template') ?></div>
          <p class="form-hint mb-sm">Controls the full affiliates block for this listing. Use <code>{each_affiliate}&hellip;{/each_affiliate}</code> to design each row.</p>
          <div class="form-group">
            <textarea id="affiliates_section_template" name="affiliates_section_template" class="form form-mono" spellcheck="false"><?= View::e($tplAffVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="affiliates_section" data-textarea="affiliates_section_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="affiliates_section_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="statistics" <?= $_grp('statistics') ?>>
          <div class="pc-section-label">Statistics <?= $srcBadge('stats_template') ?></div>
          <div class="form-group">
            <textarea id="stats_template" name="stats_template" class="form form-mono" spellcheck="false"><?= View::e($tplStatsVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="stats" data-textarea="stats_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="stats_template">Reset to Default</button>
          </div>
        </div>

        <?php if ($hasUpdatesAddon): ?>
        <div class="tpl-group-section" data-tpl-group="updates" <?= $_grp('updates') ?>>
          <div class="pc-section-label">Updates Feed <?= $srcBadge('updates_template') ?></div>
          <p class="form-hint mb-sm">Controls the rendered updates list on <code>/updates/<?= (int) $listing->id ?></code> and the embed. Wrap each post in <code>{each_update}&hellip;{/each_update}</code>.</p>
          <div class="form-group">
            <textarea id="updates_template" name="updates_template" class="form form-mono" spellcheck="false"><?= View::e($tplUpdatesVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="updates" data-textarea="updates_template"><?= View::icon('eye-on') ?>  Preview</button>&nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="updates_template">Reset to Default</button>
          </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="updates_template" value="<?= View::e((string) ($tplUpdatesVal ?? '')) ?>">
        <?php endif; ?>

        <div class="tpl-group-section" data-tpl-group="join-form" <?= $_grp('join-form') ?>>
          <div class="pc-section-label">Join Form <?= $srcBadge('join_form_template') ?></div>
          <p class="form-hint mb-sm">Controls the full layout of the join form, including every field. Write any introductory text directly at the top of the template. Use the tokens below to arrange fields in any order.</p>
          <div class="form-group">
            <textarea id="join_form_template" name="join_form_template" class="form form-mono" spellcheck="false"><?= View::e($tplJoinFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="join_form" data-textarea="join_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="join_form_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="update-form" <?= $_grp('update-form') ?>>
          <div class="pc-section-label">Update Membership Form <?= $srcBadge('update_form_template') ?></div>
          <p class="form-hint mb-sm">Controls the full layout of the update form. Use <code>{if_not_signed_in}&hellip;{/if_not_signed_in}</code> for the sign-in step and <code>{if_signed_in}&hellip;{/if_signed_in}</code> for the update fields step.</p>
          <div class="form-group">
            <textarea id="update_form_template" name="update_form_template" class="form form-mono" spellcheck="false"><?= View::e($tplUpdateFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="update_form" data-textarea="update_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="update_form_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="reset-form" <?= $_grp('reset-form') ?>>
          <div class="pc-section-label">Lost Password Form <?= $srcBadge('reset_form_template') ?></div>
          <p class="form-hint mb-sm">Controls the full layout of the password reset form. Use <code>{if_step_email}&hellip;{/if_step_email}</code> for the email-entry step and <code>{if_step_confirm}&hellip;{/if_step_confirm}</code> for the new-password step.</p>
          <div class="form-group">
            <textarea id="reset_form_template" name="reset_form_template" class="form form-mono" spellcheck="false"><?= View::e($tplResetFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="reset_form" data-textarea="reset_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="reset_form_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="codes" <?= $_grp('codes') ?>>
          <div class="pc-section-label">Codes Display <?= $srcBadge('codes_template') ?></div>
          <p class="form-hint mb-sm">Controls how this listing's approved fan codes are displayed. Use <code>{each_size_group}&hellip;{/each_size_group}</code> to group codes by dimensions, or a single <code>{each_code}&hellip;{/each_code}</code> loop for a flat gallery.</p>
          <div class="form-group">
            <textarea id="codes_template" name="codes_template" class="form form-mono" spellcheck="false"><?= View::e($tplCodesVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="codes" data-textarea="codes_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="codes_template">Reset to Default</button>
          </div>
        </div>

        <?php if ($hasCodesSubmit): ?>
        <div class="tpl-group-section" data-tpl-group="codes-form" <?= $_grp('codes-form') ?>>
          <div class="pc-section-label">Code Donation Form <?= $srcBadge('codes_submit_form_template') ?></div>
          <p class="form-hint mb-sm">Controls the full layout of the code donation form. Write any introductory text directly at the top of the template. Use the tokens below to arrange fields in any order.</p>
          <div class="form-group">
            <textarea id="codes_submit_form_template" name="codes_submit_form_template" class="form form-mono" spellcheck="false"><?= View::e($tplCodesFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="codes_submit_form" data-textarea="codes_submit_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="codes_submit_form_template">Reset to Default</button>
          </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="codes_submit_form_template" value="<?= View::e((string) ($tplCodesFormVal ?? '')) ?>">
        <?php endif; ?>

        <?php if ($affiliate_apply_enabled ?? false): ?>
        <div class="tpl-group-section" data-tpl-group="affiliate-form" <?= $_grp('affiliate-form') ?>>
          <div class="pc-section-label">Affiliate Application Form <?= $srcBadge('affiliate_apply_form_template') ?></div>
          <p class="form-hint mb-sm">Controls the full layout of the affiliate application form. Write any introductory text directly at the top of the template. Use the tokens below to arrange fields in any order.</p>
          <div class="form-group">
            <textarea id="affiliate_apply_form_template" name="affiliate_apply_form_template" class="form form-mono" spellcheck="false"><?= View::e($tplAffApplyFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="affiliate_apply_form" data-textarea="affiliate_apply_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="affiliate_apply_form_template">Reset to Default</button>
          </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="affiliate_apply_form_template" value="<?= View::e((string) ($tplAffApplyFormVal ?? '')) ?>">
        <?php endif; ?>

        <div class="tpl-group-section" data-tpl-group="contact-form" <?= $_grp('contact-form') ?>>
          <div class="pc-section-label">Contact Form <?= $srcBadge('contact_form_template') ?></div>
          <p class="form-hint mb-sm">Controls the full layout of the contact form. Write any introductory text directly at the top of the template. Use the tokens below to arrange fields in any order.</p>
          <div class="form-group">
            <textarea id="contact_form_template" name="contact_form_template" class="form form-mono" spellcheck="false"><?= View::e($tplContactFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="contact_form" data-textarea="contact_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="contact_form_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="contact-sent" <?= $_grp('contact-sent') ?>>
          <div class="pc-section-label">Contact Sent Message <?= $srcBadge('contact_sent_template') ?></div>
          <p class="form-hint mb-sm">The confirmation message shown after a visitor submits the contact form.</p>
          <div class="form-group">
            <textarea id="contact_sent_template" name="contact_sent_template" class="form form-mono" spellcheck="false"><?= View::e($tplContactSentVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="contact_sent" data-textarea="contact_sent_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="contact_sent_template">Reset to Default</button>
          </div>
        </div>

      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Templates</button>
        <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>" class="btn btn-ghost">Cancel</a>
      </div>
    </div>

    <!-- ── Right column: token reference ──────────────────────────────── -->
    <div class="composer-side composer-side--sticky">

      <div class="search-field-wrap token-search-wrap">
        <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
        <input type="search"
               id="collective-token-search"
               data-token-search
               class="form"
               placeholder="Search embed tokens"
               autocomplete="off"
               aria-label="Search embed tokens">
        <button type="button"
                id="collective-token-search-clear"
                data-token-search-clear
                class="token-search-clear"
                aria-label="Clear embed token search"
                title="Clear search"
                hidden><?= View::icon('x', ['aria-hidden' => 'true']) ?></button>
      </div>

      <p id="collective-token-search-empty" data-token-search-empty class="form-hint token-search-empty" hidden>No tokens found.</p>

      <!-- Members List -->
      <details class="card chevron" data-token-card data-tpl-group="members" <?= $_grp('members') ?>>
        <summary class="chevron-head">Members List Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Wrap your row design in {each_member}...{/each_member}. Use {if_no_members} outside the loop for an empty state.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('members_template', '{each_member}{/each_member}',   '{each_member}...{/each_member}',   'Repeats the HTML inside it once for each approved member shown.') ?>
            <?= $ltok('members_template', '{fan_name}',    '{fan_name}',    'The member\'s display name.') ?>
            <?= $ltok('members_template', '{fan_email}',   '{fan_email}',   'The member\'s email. Only use this if you want emails visible publicly.') ?>
            <?= $ltok('members_template', '{fan_url}',     '{fan_url}',     'The member\'s website URL.') ?>
            <?= $ltok('members_template', '{fan_country}', '{fan_country}', 'The member\'s country.') ?>
            <?= $ltok('members_template', '{fan_FIELDNAME}', '{fan_FIELDNAME}', 'Additional field value. Replace FIELDNAME with the field\'s internal name, e.g. {fan_fave_song}.') ?>
            <?= $ltok('members_template', '{if_fan_url}{/if_fan_url}',             '{if_fan_url}...{/if_fan_url}',             'Shows only when the member has a website URL.') ?>
            <?= $ltok('members_template', '{if_no_fan_url}{/if_no_fan_url}',       '{if_no_fan_url}...{/if_no_fan_url}',       'Shows only when the member has no website URL.') ?>
            <?= $ltok('members_template', '{if_fan_country}{/if_fan_country}',     '{if_fan_country}...{/if_fan_country}',     'Shows only when the member has a country saved.') ?>
            <?= $ltok('members_template', '{if_no_fan_country}{/if_no_fan_country}','{if_no_fan_country}...{/if_no_fan_country}','Shows only when the member has no country saved.') ?>
            <?= $ltok('members_template', '{if_members}{/if_members}',       '{if_members}...{/if_members}',       'Shows when there is at least one member. Place outside {each_member}.') ?>
            <?= $ltok('members_template', '{if_no_members}{/if_no_members}', '{if_no_members}...{/if_no_members}', 'Shows when the list is empty (empty state). Place outside {each_member}.') ?>
            <?= $ltok('members_template', '{join_url}',     '{join_url}',     'URL of the join form. Useful inside {if_no_members} for a &ldquo;Want to join?&rdquo; link.') ?>
            <?= $ltok('members_template', '{member_count}', '{member_count}', 'Total approved member count. Place outside {each_member}.') ?>
            <?= $ltok('members_template', '{stats_line}',   '{stats_line}',   'The stats summary line: &ldquo;11 member(s) &mdash; page 1 of 2&rdquo; or &ldquo;5 countries represented&rdquo;. Lets you relocate or wrap it inside your custom layout.') ?>
            <?= $ltok('members_template', '{sort_link_all}',     '{sort_link_all}',     'The &ldquo;View All&rdquo; sort link, rendered as &lt;strong&gt; when active or &lt;a&gt; otherwise.') ?>
            <?= $ltok('members_template', '{sort_link_name}',    '{sort_link_name}',    'The &ldquo;Name&rdquo; sort link, rendered as &lt;strong&gt; when active or &lt;a&gt; otherwise.') ?>
            <?= $ltok('members_template', '{sort_link_country}', '{sort_link_country}', 'The &ldquo;Country&rdquo; sort link, rendered as &lt;strong&gt; when active or &lt;a&gt; otherwise.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Affiliates Section -->
      <details class="card chevron" data-token-card data-tpl-group="affiliates" <?= $_grp('affiliates') ?>>
        <summary class="chevron-head">Affiliates Section Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {each_affiliate}...{/each_affiliate} to design each row yourself.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('affiliates_section_template', '{each_affiliate}{/each_affiliate}', '{each_affiliate}...{/each_affiliate}', 'Repeats the HTML inside it once for each affiliate.') ?>
            <?= $ltok('affiliates_section_template', '{aff_name}',      '{aff_name}',      'The affiliate site name. Use inside {each_affiliate}.') ?>
            <?= $ltok('affiliates_section_template', '{aff_url}',       '{aff_url}',       'The affiliate site link. Use inside {each_affiliate}.') ?>
            <?= $ltok('affiliates_section_template', '{aff_image_url}', '{aff_image_url}', 'The affiliate image URL. Empty when there is no image. Use inside {each_affiliate}.') ?>
            <?= $ltok('affiliates_section_template', '{affiliates_count}', '{affiliates_count}', 'How many active affiliates this listing has.') ?>
            <?= $ltok('affiliates_section_template', '{affiliate_apply_url}',                '{affiliate_apply_url}',                   'URL of the affiliate application form for this listing.') ?>
            <?= $ltok('affiliates_section_template', '{if_affiliates}{/if_affiliates}',       '{if_affiliates}...{/if_affiliates}',       'Shows only when this listing has at least one affiliate.') ?>
            <?= $ltok('affiliates_section_template', '{if_no_affiliates}{/if_no_affiliates}', '{if_no_affiliates}...{/if_no_affiliates}', 'Shows only when this listing has no affiliates.') ?>
            <?= $ltok('affiliates_section_template', '{if_aff_image}{/if_aff_image}',       '{if_aff_image}...{/if_aff_image}',       'Inside {each_affiliate}, shows only when the affiliate has an image.') ?>
            <?= $ltok('affiliates_section_template', '{if_no_aff_image}{/if_no_aff_image}', '{if_no_aff_image}...{/if_no_aff_image}', 'Inside {each_affiliate}, shows only when the affiliate has no image.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Statistics -->
      <details class="card chevron" data-token-card data-tpl-group="statistics" <?= $_grp('statistics') ?>>
        <summary class="chevron-head">Statistics Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>These tokens fill in the public stats block for this listing.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('stats_template', '{stat_opened}',       '{stat_opened}',       'The date you opened the listing.') ?>
            <?= $ltok('stats_template', '{stat_updated}',      '{stat_updated}',      'The most recent activity date: whichever is more recent of the newest approved member\'s join date and the Last Updated date you set. Shows &ldquo;N/A&rdquo; if neither is set.') ?>
            <?= $ltok('stats_template', '{stat_approved}',     '{stat_approved}',     'How many approved members this listing has.') ?>
            <?= $ltok('stats_template', '{stat_pending}',      '{stat_pending}',      'How many members are waiting for approval.') ?>
            <?= $ltok('stats_template', '{stat_countries}',    '{stat_countries}',    'Number of distinct countries represented among approved members.') ?>
            <?= $ltok('stats_template', '{stat_newmembers}',        '{stat_newmembers}',        'Up to 5 newest approved members, linked to their websites when available. Shows &ldquo;&hellip;and more&rdquo; when there are more than 5 total.') ?>
            <?= $ltok('stats_template', '{if_stat_pending}{/if_stat_pending}', '{if_stat_pending}...{/if_stat_pending}', 'Shows only when at least one member is awaiting approval.') ?>
            <?= $ltok('stats_template', '{stat_growth}',            '{stat_growth}',            'Average members per day, with unit (e.g. &ldquo;0.15 members/day&rdquo;).') ?>
            <?= $ltok('stats_template', '{stat_growth_rate}',       '{stat_growth_rate}',       'Numeric growth rate only (e.g. &ldquo;0.15&rdquo;). Use this to build your own formatting.') ?>
            <?= $ltok('stats_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $ltok('stats_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $ltok('stats_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
            <?= $ltok('stats_template', '{approved_by}',       '{approved_by}',       'Network acronym: &ldquo;TFL&rdquo;, &ldquo;TAFL&rdquo;, or empty. Plain text, no link.') ?>
            <?= $ltok('stats_template', '{network_name}',      '{network_name}',      'Full network name: &ldquo;The Fanlistings Network&rdquo;, &ldquo;The Anime Fanlistings Network&rdquo;, or empty.') ?>
            <?= $ltok('stats_template', '{tfl_url}',           '{tfl_url}',           'TFL website URL: <code>https://thefanlistings.org/</code>') ?>
            <?= $ltok('stats_template', '{tafl_url}',          '{tafl_url}',          'TAFL website URL: <code>https://www.animefanlistings.com/</code>') ?>
            <?= $ltok('stats_template', '{if_approved_by}{/if_approved_by}', '{if_approved_by}...{/if_approved_by}', 'Shows only when an approved-by network is saved.') ?>
            <?= $ltok('stats_template', '{if_tfl}{/if_tfl}',             '{if_tfl}...{/if_tfl}',             'Shows only when this listing is TFL-approved.') ?>
            <?= $ltok('stats_template', '{if_tafl}{/if_tafl}',           '{if_tafl}...{/if_tafl}',           'Shows only when this listing is TAFL-approved.') ?>
          </tbody></table>
        </div>
      </details>

      <?php if ($hasUpdatesAddon): ?>
      <!-- Updates Feed -->
      <details class="card chevron" data-token-card data-tpl-group="updates" <?= $_grp('updates') ?>>
        <summary class="chevron-head">Updates Feed Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Wrap each post in {each_update}...{/each_update}. Luster repeats it once per update entry.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('updates_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $ltok('updates_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $ltok('updates_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
            <?= $ltok('updates_template', '{if_updates}{/if_updates}',       '{if_updates}...{/if_updates}',       'Shows only when there is at least one update on the current page.') ?>
            <?= $ltok('updates_template', '{if_no_updates}{/if_no_updates}', '{if_no_updates}...{/if_no_updates}', 'Shows when the current page has no updates (use for an empty state message).') ?>
            <?= $ltok('updates_template', '{each_update}{/each_update}', '{each_update}...{/each_update}', 'Repeats the HTML inside it once for each update post shown.') ?>
            <?= $ltok('updates_template', '{update_title}',      '{update_title}',      'The update post title.') ?>
            <?= $ltok('updates_template', '{update_date}',       '{update_date}',       'The posted date in human-readable format, e.g. June 1, 2025.') ?>
            <?= $ltok('updates_template', '{update_author}',     '{update_author}',     'The author name. Empty when not set.') ?>
            <?= $ltok('updates_template', '{update_categories}', '{update_categories}', 'Category names, comma-separated. Empty when not set.') ?>
            <?= $ltok('updates_template', '{update_body_html}',  '{update_body_html}',  'The update body text, with line breaks converted to HTML.') ?>
            <?= $ltok('updates_template', '{if_author}{/if_author}',         '{if_author}...{/if_author}',         'Shows only when the update has an author set.') ?>
            <?= $ltok('updates_template', '{if_categories}{/if_categories}', '{if_categories}...{/if_categories}', 'Shows only when the update has categories set.') ?>
          </tbody></table>
        </div>
      </details>
      <?php endif; ?>

      <!-- Join Form -->
      <details class="card chevron" data-token-card data-tpl-group="join-form" <?= $_grp('join-form') ?>>
        <summary class="chevron-head">Join Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Arrange and label every field of the join form, and write any introductory text directly into the template. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('join_form_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $ltok('join_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $ltok('join_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
            <?= $ltok('join_form_template', '{field_name}',       '{field_name}',       'The Name field, with label and wrapper.') ?>
            <?= $ltok('join_form_template', '{field_name_input}', '{field_name_input}', 'Just the Name input, no label or wrapper.') ?>
            <?= $ltok('join_form_template', '{field_email}',       '{field_email}',       'The Email field, with label and wrapper.') ?>
            <?= $ltok('join_form_template', '{field_email_input}', '{field_email_input}', 'Just the Email input, no label or wrapper.') ?>
            <?= $ltok('join_form_template', '{field_url}',       '{field_url}',       'The Website URL field, with label and wrapper.') ?>
            <?= $ltok('join_form_template', '{field_url_input}', '{field_url_input}', 'Just the Website URL input, no label or wrapper.') ?>
            <?= $ltok('join_form_template', '{field_country}',       '{field_country}',       'The Country field, with label and wrapper. Empty when the country field is disabled for this listing.') ?>
            <?= $ltok('join_form_template', '{field_country_input}', '{field_country_input}', 'Just the Country select, no label or wrapper. Empty when the country field is disabled for this listing.') ?>
            <?= $ltok('join_form_template', '{additional_fields}',       '{additional_fields}',       'All admin-defined additional fields, with labels and wrappers, in order.') ?>
            <?= $ltok('join_form_template', '{additional_fields_input}', '{additional_fields_input}', 'All admin-defined additional fields as bare inputs only, in order.') ?>
            <?= $ltok('join_form_template', '{field_password}',       '{field_password}',       'The Password field, with label and wrapper.') ?>
            <?= $ltok('join_form_template', '{field_password_input}', '{field_password_input}', 'Just the Password input, no label or wrapper.') ?>
            <?= $ltok('join_form_template', '{field_password_confirm}',       '{field_password_confirm}',       'The Confirm Password field, with label and wrapper.') ?>
            <?= $ltok('join_form_template', '{field_password_confirm_input}', '{field_password_confirm_input}', 'Just the Confirm Password input, no label or wrapper.') ?>
            <?= $ltok('join_form_template', '{captcha_block}',       '{captcha_block}',       'The captcha question field, with label and wrapper.') ?>
            <?= $ltok('join_form_template', '{captcha_block_input}', '{captcha_block_input}', 'Just the captcha answer input, no label or wrapper.') ?>
            <?= $ltok('join_form_template', '{captcha_question}', '{captcha_question}', 'The captcha question text on its own, e.g. for use inside a custom label.') ?>
            <?= $ltok('join_form_template', '{submit_button}', '{submit_button}', 'The "Join Listing" submit button.') ?>
            <?= $ltok('join_form_template', '{clear_button}',  '{clear_button}',  'The "Clear Form" button.') ?>
          </tbody></table>
        </div>
      </details>


      <!-- Update Membership Form -->
      <details class="card chevron" data-token-card data-tpl-group="update-form" <?= $_grp('update-form') ?>>
        <summary class="chevron-head">Update Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {if_not_signed_in}...{/if_not_signed_in} for the sign-in step and {if_signed_in}...{/if_signed_in} for the update fields step. Both conditionals can share the same template.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('update_form_template', '{if_not_signed_in}{/if_not_signed_in}', '{if_not_signed_in}...{/if_not_signed_in}', 'Shows the sign-in step (email + password). Shown on first visit before authentication.') ?>
            <?= $ltok('update_form_template', '{if_signed_in}{/if_signed_in}',         '{if_signed_in}...{/if_signed_in}',         'Shows the update form step. Shown after the member signs in.') ?>
            <?= $ltok('update_form_template', '{field_signin_email}',    '{field_signin_email}',    'Sign-in step: the Email field, with label and wrapper.') ?>
            <?= $ltok('update_form_template', '{field_signin_password}', '{field_signin_password}', 'Sign-in step: the Password field, with label and wrapper.') ?>
            <?= $ltok('update_form_template', '{lost_password_url}',     '{lost_password_url}',     'Sign-in step: URL of the password reset page. Use for a "Forgot your password?" link.') ?>
            <?= $ltok('update_form_template', '{field_new_name}',    '{field_new_name}',    'Update step: the New Name field, with label and wrapper.') ?>
            <?= $ltok('update_form_template', '{field_new_email}',   '{field_new_email}',   'Update step: the New Email field, with label and wrapper.') ?>
            <?= $ltok('update_form_template', '{field_new_url}',     '{field_new_url}',     'Update step: the New Website URL field (includes Remove URL checkbox), with label and wrapper.') ?>
            <?= $ltok('update_form_template', '{field_new_country}', '{field_new_country}', 'Update step: the Country selector, with label and wrapper.') ?>
            <?= $ltok('update_form_template', '{additional_fields}', '{additional_fields}', 'Update step: all admin-defined additional fields, with labels and wrappers, in order.') ?>
            <?= $ltok('update_form_template', '{field_password}',         '{field_password}',         'Update step: the New Password field, with label and wrapper.') ?>
            <?= $ltok('update_form_template', '{field_password_confirm}', '{field_password_confirm}', 'Update step: the Confirm New Password field, with label and wrapper.') ?>

            <?= $ltok('update_form_template', '{field_remove}',     '{field_remove}',     'Update step: the "Delete my membership" checkbox block.') ?>
            <?= $ltok('update_form_template', '{field_email}',      '{field_email}',      'Update step: the authenticated member\'s email address (display only, not editable).') ?>
            <?= $ltok('update_form_template', '{sign_out_url}',     '{sign_out_url}',     'Update step: URL for the "Sign out" link.') ?>
            <?= $ltok('update_form_template', '{submit_button}',    '{submit_button}',    'The submit button.') ?>
            <?= $ltok('update_form_template', '{clear_button}',     '{clear_button}',     'The "Clear" reset button.') ?>
            <?= $ltok('update_form_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $ltok('update_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $ltok('update_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Lost Password Form -->
      <details class="card chevron" data-token-card data-tpl-group="reset-form" <?= $_grp('reset-form') ?>>
        <summary class="chevron-head">Lost Password Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {if_step_email}...{/if_step_email} for the email-entry step and {if_step_confirm}...{/if_step_confirm} for the new-password step. Use {if_token_valid} or {if_token_invalid} to handle expired links.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('reset_form_template', '{if_step_email}{/if_step_email}',     '{if_step_email}...{/if_step_email}',     'Shows when the visitor is on the email-entry step.') ?>
            <?= $ltok('reset_form_template', '{if_step_confirm}{/if_step_confirm}', '{if_step_confirm}...{/if_step_confirm}', 'Shows when the visitor has clicked a reset link and is setting a new password.') ?>
            <?= $ltok('reset_form_template', '{if_token_valid}{/if_token_valid}',   '{if_token_valid}...{/if_token_valid}',   'Inside {if_step_confirm}: shows when the reset token is valid.') ?>
            <?= $ltok('reset_form_template', '{if_token_invalid}{/if_token_invalid}', '{if_token_invalid}...{/if_token_invalid}', 'Inside {if_step_confirm}: shows when the reset token has expired or is invalid.') ?>
            <?= $ltok('reset_form_template', '{field_email}',               '{field_email}',               'Email-entry step: the Email field, with label and wrapper.') ?>

            <?= $ltok('reset_form_template', '{field_new_password}',        '{field_new_password}',        'Confirm step: the New Password field, with label and wrapper.') ?>
            <?= $ltok('reset_form_template', '{field_new_password_confirm}', '{field_new_password_confirm}', 'Confirm step: the Confirm New Password field, with label and wrapper.') ?>
            <?= $ltok('reset_form_template', '{reset_request_url}',         '{reset_request_url}',         'URL of the email-entry form. Use inside {if_token_invalid} for a "request a new one" link.') ?>
            <?= $ltok('reset_form_template', '{submit_button}',             '{submit_button}',             'The submit button (label changes per step).') ?>
            <?= $ltok('reset_form_template', '{clear_button}',              '{clear_button}',              'The "Clear" reset button.') ?>
            <?= $ltok('reset_form_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $ltok('reset_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $ltok('reset_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Codes Display -->
      <details class="card chevron" data-token-card data-tpl-group="codes" <?= $_grp('codes') ?>>
        <summary class="chevron-head">Codes Display Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Use {each_size_group}...{/each_size_group} (with an inner {each_code}...{/each_code}) to group codes by dimensions, or a single {each_code}...{/each_code} loop for a flat gallery.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('codes_template', '{each_size_group}{/each_size_group}', '{each_size_group}...{/each_size_group}', 'Repeats the HTML inside it once per group of same-sized codes. Wrap an {each_code}...{/each_code} loop inside it.') ?>
            <?= $ltok('codes_template', '{group_size}',       '{group_size}',       'The group label, e.g. "88×31px" or "Other". Use inside {each_size_group}.') ?>
            <?= $ltok('codes_template', '{group_code_count}', '{group_code_count}', 'How many codes are in this size group. Use inside {each_size_group}.') ?>
            <?= $ltok('codes_template', '{each_code}{/each_code}', '{each_code}...{/each_code}', 'Repeats the HTML inside it once for each approved code shown.') ?>
            <?= $ltok('codes_template', '{code_url}',        '{code_url}',        'The full image URL for the code.') ?>
            <?= $ltok('codes_template', '{code_width}',      '{code_width}',      'The code\'s width in pixels. Empty when dimensions are unknown.') ?>
            <?= $ltok('codes_template', '{code_height}',     '{code_height}',     'The code\'s height in pixels. Empty when dimensions are unknown.') ?>
            <?= $ltok('codes_template', '{code_size}',       '{code_size}',       'The code\'s dimensions as &ldquo;WxH&rdquo;, e.g. &ldquo;88×31&rdquo;. Empty when unknown.') ?>
            <?= $ltok('codes_template', '{code_name}',       '{code_name}',       'The code\'s saved name/title. Empty when not set.') ?>
            <?= $ltok('codes_template', '{code_donor_name}', '{code_donor_name}', 'The donor\'s name. Empty when the code was not donated.') ?>
            <?= $ltok('codes_template', '{code_donor_url}',  '{code_donor_url}',  'The donor\'s website URL. Empty when not set.') ?>
            <?= $ltok('codes_template', '{if_size}{/if_size}',           '{if_size}...{/if_size}',           'Shows only when width and height are known.') ?>
            <?= $ltok('codes_template', '{if_size_changed}{/if_size_changed}', '{if_size_changed}...{/if_size_changed}', 'Flat mode only. Shows when the code size differs from the previous code &mdash; useful for size headings.') ?>
            <?= $ltok('codes_template', '{if_donor}{/if_donor}',         '{if_donor}...{/if_donor}',         'Shows only when the code was donated by a named donor.') ?>
            <?= $ltok('codes_template', '{if_no_donor}{/if_no_donor}',   '{if_no_donor}...{/if_no_donor}',   'Shows only when the code has no donor.') ?>
            <?= $ltok('codes_template', '{if_donor_url}{/if_donor_url}', '{if_donor_url}...{/if_donor_url}', 'Shows only when the donor has a website URL saved.') ?>
            <?= $ltok('codes_template', '{if_no_donor_url}{/if_no_donor_url}', '{if_no_donor_url}...{/if_no_donor_url}', 'Shows only when the donor has no website URL.') ?>
            <?= $ltok('codes_template', '{if_code_name}{/if_code_name}',   '{if_code_name}...{/if_code_name}',   'Shows only when the code has a saved name.') ?>
            <?= $ltok('codes_template', '{if_codes}{/if_codes}',       '{if_codes}...{/if_codes}',       'Shows when this listing has at least one approved code. Place outside the loop.') ?>
            <?= $ltok('codes_template', '{if_no_codes}{/if_no_codes}', '{if_no_codes}...{/if_no_codes}', 'Shows when this listing has no approved codes (empty state). Place outside the loop.') ?>
          </tbody></table>
        </div>
      </details>

      <?php if ($hasCodesSubmit): ?>
      <!-- Code Donation Form -->
      <details class="card chevron" data-token-card data-tpl-group="codes-form" <?= $_grp('codes-form') ?>>
        <summary class="chevron-head">Code Donation Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Arrange and label every field of the code donation form. Write any introductory text directly into the template. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('codes_submit_form_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $ltok('codes_submit_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $ltok('codes_submit_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
            <?= $ltok('codes_submit_form_template', '{field_code_image}',       '{field_code_image}',       'The file upload field, with label and wrapper.') ?>
            <?= $ltok('codes_submit_form_template', '{field_code_image_input}', '{field_code_image_input}', 'Just the file input, no label or wrapper.') ?>
            <?= $ltok('codes_submit_form_template', '{field_submitter_name}',       '{field_submitter_name}',       'The Name field, with label and wrapper.') ?>
            <?= $ltok('codes_submit_form_template', '{field_submitter_name_input}', '{field_submitter_name_input}', 'Just the Name input, no label or wrapper.') ?>
            <?= $ltok('codes_submit_form_template', '{field_submitter_email}',       '{field_submitter_email}',       'The Email field, with label and wrapper.') ?>
            <?= $ltok('codes_submit_form_template', '{field_submitter_email_input}', '{field_submitter_email_input}', 'Just the Email input, no label or wrapper.') ?>
            <?= $ltok('codes_submit_form_template', '{field_submitter_url}',       '{field_submitter_url}',       'The Website field, with label and wrapper.') ?>
            <?= $ltok('codes_submit_form_template', '{field_submitter_url_input}', '{field_submitter_url_input}', 'Just the Website input, no label or wrapper.') ?>
            <?= $ltok('codes_submit_form_template', '{captcha_block}',       '{captcha_block}',       'The captcha question field, with label and wrapper.') ?>
            <?= $ltok('codes_submit_form_template', '{captcha_block_input}', '{captcha_block_input}', 'Just the captcha answer input, no label or wrapper.') ?>
            <?= $ltok('codes_submit_form_template', '{captcha_question}', '{captcha_question}', 'The captcha question text on its own, e.g. for use inside a custom label.') ?>
            <?= $ltok('codes_submit_form_template', '{submit_button}', '{submit_button}', 'The "Submit Code" submit button.') ?>
            <?= $ltok('codes_submit_form_template', '{clear_button}',  '{clear_button}',  'The "Clear" reset button.') ?>
          </tbody></table>
        </div>
      </details>
      <?php endif; ?>

      <?php if ($affiliate_apply_enabled ?? false): ?>
      <!-- Affiliate Application Form -->
      <details class="card chevron" data-token-card data-tpl-group="affiliate-form" <?= $_grp('affiliate-form') ?>>
        <summary class="chevron-head">Affiliate Application Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Arrange and label every field of the affiliate application form. Write any introductory text directly into the template. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('affiliate_apply_form_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $ltok('affiliate_apply_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $ltok('affiliate_apply_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
            <?= $ltok('affiliate_apply_form_template', '{field_name}',       '{field_name}',       'The Site Name field, with label and wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{field_name_input}', '{field_name_input}', 'Just the Site Name input, no label or wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{field_url}',       '{field_url}',       'The Site URL field, with label and wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{field_url_input}', '{field_url_input}', 'Just the URL input, no label or wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{field_email}',       '{field_email}',       'The Email field (optional), with label and wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{field_email_input}', '{field_email_input}', 'Just the Email input, no label or wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{field_image}',       '{field_image}',       'The site button / image upload field, with label and wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{field_image_input}', '{field_image_input}', 'Just the file input (styled drop zone), no label or wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{field_message}',       '{field_message}',       'The Message textarea (optional), with label and wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{field_message_input}', '{field_message_input}', 'Just the Message textarea, no label or wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{captcha_block}',       '{captcha_block}',       'The captcha question field, with label and wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{captcha_block_input}', '{captcha_block_input}', 'Just the captcha answer input, no label or wrapper.') ?>
            <?= $ltok('affiliate_apply_form_template', '{captcha_question}', '{captcha_question}', 'The captcha question text on its own, e.g. for use inside a custom label.') ?>
            <?= $ltok('affiliate_apply_form_template', '{submit_button}', '{submit_button}', 'The "Submit Application" submit button.') ?>
            <?= $ltok('affiliate_apply_form_template', '{clear_button}',  '{clear_button}',  'The "Clear" reset button.') ?>
          </tbody></table>
        </div>
      </details>
      <?php endif; ?>

      <!-- Contact Form -->
      <details class="card chevron" data-token-card data-tpl-group="contact-form" <?= $_grp('contact-form') ?>>
        <summary class="chevron-head">Contact Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Arrange and label every field of the contact form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('contact_form_template', '{listing_title}',         '{listing_title}',         'The listing\'s display name.') ?>
            <?= $ltok('contact_form_template', '{listing_subject}',       '{listing_subject}',       'What this fanlisting is about (e.g. "Archeology", "Marilyn Monroe").') ?>
            <?= $ltok('contact_form_template', '{listing_url}',           '{listing_url}',           'The link to the listing.') ?>
            <?= $ltok('contact_form_template', '{field_name}',            '{field_name}',            'The Name field, with label and wrapper.') ?>
            <?= $ltok('contact_form_template', '{field_name_input}',      '{field_name_input}',      'Just the Name input, no label or wrapper.') ?>
            <?= $ltok('contact_form_template', '{field_email}',           '{field_email}',           'The Email field, with label and wrapper.') ?>
            <?= $ltok('contact_form_template', '{field_email_input}',     '{field_email_input}',     'Just the Email input, no label or wrapper.') ?>
            <?= $ltok('contact_form_template', '{field_url}',             '{field_url}',             'The Website URL field (optional), with label and wrapper.') ?>
            <?= $ltok('contact_form_template', '{field_url_input}',       '{field_url_input}',       'Just the Website URL input, no label or wrapper.') ?>
            <?= $ltok('contact_form_template', '{field_subject}',         '{field_subject}',         'The Subject dropdown, with label and wrapper.') ?>
            <?= $ltok('contact_form_template', '{field_subject_input}',   '{field_subject_input}',   'Just the Subject select element, no label or wrapper.') ?>
            <?= $ltok('contact_form_template', '{field_message}',         '{field_message}',         'The Message textarea, with label and wrapper.') ?>
            <?= $ltok('contact_form_template', '{field_message_input}',   '{field_message_input}',   'Just the Message textarea, no label or wrapper.') ?>
            <?= $ltok('contact_form_template', '{captcha_block}',         '{captcha_block}',         'The captcha field, with label and wrapper.') ?>
            <?= $ltok('contact_form_template', '{captcha_block_input}',   '{captcha_block_input}',   'Just the captcha answer input, no label or wrapper.') ?>
            <?= $ltok('contact_form_template', '{captcha_question}',      '{captcha_question}',      'The captcha question text on its own.') ?>
            <?= $ltok('contact_form_template', '{submit_button}',         '{submit_button}',         'The "Send Message" submit button.') ?>
            <?= $ltok('contact_form_template', '{clear_button}',          '{clear_button}',          'The "Clear" reset button.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Contact Sent -->
      <details class="card chevron" data-token-card data-tpl-group="contact-sent" <?= $_grp('contact-sent') ?>>
        <summary class="chevron-head">Contact Sent Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint mb-sm" data-token-search-help>Shown after a visitor submits the contact form.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('contact_sent_template', '{sender_name}',   '{sender_name}',   'The name the visitor entered on the contact form.') ?>
            <?= $ltok('contact_sent_template', '{sender_email}',  '{sender_email}',  'The email address the visitor entered.') ?>
            <?= $ltok('contact_sent_template', '{listing_title}', '{listing_title}', 'The listing\'s display name.') ?>
            <?= $ltok('contact_sent_template', '{listing_url}',   '{listing_url}',   'The link to the listing.') ?>
            <?= $ltok('contact_sent_template', '{site_name}',     '{site_name}',     'The site/collective name.') ?>
          </tbody></table>
        </div>
      </details>

    </div><!-- /.composer-side -->
  </div><!-- /.composer-grid -->
</form>

</div><!-- /#tab-templates -->

<script type="application/json" id="luster-coltpl-defaults">
<?= json_encode([
    'members_template'              => $_gFallback(ListingSettingsRepo::defaultMembersTemplate(),              'listing_default_members_template'),
    'affiliates_section_template'   => $_gFallback(ListingSettingsRepo::defaultAffiliatesSectionTemplate(),   'listing_default_affiliates_section_template'),
    'stats_template'                => $_gFallback(ListingSettingsRepo::defaultStatsTemplate(),                'listing_default_stats_template'),
    'updates_template'              => $_gFallback(ListingSettingsRepo::defaultUpdatesTemplate(),              'listing_default_updates_template'),
    'codes_submit_form_template'    => $_gFallback(ListingSettingsRepo::defaultCodesSubmitFormTemplate(),      'listing_default_codes_submit_form_template'),
    'join_form_template'            => $_gFallback(ListingSettingsRepo::defaultJoinFormTemplate(),             'listing_default_join_form_template'),
    'update_form_template'          => $_gFallback(ListingSettingsRepo::defaultUpdateFormTemplate(),           'listing_default_update_form_template'),
    'reset_form_template'           => $_gFallback(ListingSettingsRepo::defaultResetFormTemplate(),            'listing_default_reset_form_template'),
    'affiliate_apply_form_template' => $_gFallback(ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),  'listing_default_affiliate_apply_form_template'),
    'codes_template'                => $_gFallback(ListingSettingsRepo::defaultCodesTemplate(),               'listing_default_codes_template'),
    'contact_form_template'         => $_gFallback(ListingSettingsRepo::defaultContactFormTemplate(),         'listing_default_contact_form_template'),
    'contact_sent_template'         => $_gFallback(ListingSettingsRepo::defaultContactSentTemplate(),         'listing_default_contact_sent_template'),
    'rules-body'                    => RulesBodyRenderer::defaultBody(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<script type="application/json" id="luster-coltpl-meta"><?= json_encode([
    'previewUrl' => $b . '/admin/listings/' . (int) $listing->id . '/templates/preview',
    'csrf'       => $csrf_token ?? '',
], JSON_UNESCAPED_SLASHES) ?></script>

<div id="coltpl-prev-modal" class="modal-backdrop is-hidden" style="display:none" role="dialog" aria-modal="true" aria-label="Template preview">
  <div class="modal modal--preview" style="max-width:680px;height:68vh">
    <div class="modal-header">
      <span id="coltpl-prev-title" class="modal-title">Preview</span>
      <div style="display:flex;align-items:center;gap:.5rem">
        <span id="coltpl-prev-spinner" style="display:none;font-size:.8rem;color:var(--text-muted)">Loading&hellip;</span>
        <button type="button" id="coltpl-prev-close" class="btn btn-tertiary btn-sm" aria-label="Close preview">&times;&ensp;Close</button>
      </div>
    </div>
    <div class="modal-body--iframe">
      <iframe id="coltpl-prev-iframe" title="Template preview" sandbox=""></iframe>
    </div>
  </div>
</div>
<?php if ($rules_addon_installed ?? false): ?>
<!-- ──── TAB: RULES ─────────────────────────────────────────────────── -->
<div id="tab-rules" class="tabs-panel">

<?php
$listing_id      = (int) $listing->id;
$_rules_embedded = true;
include LUSTER_BASE . '/templates/admin/addons/rules/edit.php';
unset($listing_id, $_rules_embedded, $defaultBody, $bodyVal, $currentGateType, $defaultGateLabel);
?>
</div><!-- /#tab-rules -->
<?php endif; ?>
<!-- ──── TAB: ADDITIONAL FIELDS ─────────────────────────────────────── -->
<div id="tab-fields" class="tabs-panel">

  <?php if ($listing->dbprefix === ''): ?>
  <div class="card">
    <p class="text-muted">Additional fields can be added after Luster creates the member storage for this listing.</p>
  </div>
  <?php else: ?>

  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/add" id="fields-add-form" class="js-warn-unsaved">
    <input type="hidden" name="_csrf"     value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="from_edit" value="1">
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('add') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">Add New Field</div>
          <div class="pc-head-sub">Create an extra member field for this listing.</div>
        </div>
      </div>
      <div class="listing-field-section">
        <div class="callout callout-info"><?= View::icon('notes') ?><div><strong>Rules:</strong> Use letters, numbers, and underscores only. No spaces. Keep it under 30 characters, and choose a name this listing does not already use.</div></div>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="af-name">Field Name <span class="req">*</span></label>
          <div>
            <input type="text" class="form" id="af-name" name="name"
                   maxlength="30" pattern="[a-zA-Z0-9_]+"
                   placeholder="e.g. fave_song" required
                   title="Letters, numbers and underscores only">
            <p class="form-hint">Short internal name for this field. Members will not see this exact name; they see the label below.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="af-label">Label <span class="req">*</span></label>
          <div>
            <input type="text" class="form" id="af-label" name="label"
                   maxlength="100" placeholder="e.g. Favourite Song" required>
            <p class="form-hint">The label members will see on your join form and members pages.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="af-type">Type</label>
          <div>
            <div class="select-wrap">
              <select class="form" id="af-type" name="type">
                <option value="text">Text Field</option>
                <option value="dropdown">Dropdown</option>
                <option value="checkbox">Checkbox</option>
                <option value="radio">Radio Button</option>
              </select>
            </div>
          </div>
        </div>
        <div id="af-options-row" class="listing-field-row listing-field-row--top is-hidden">
          <label class="listing-field-label" for="af-options">Options</label>
          <div>
            <textarea class="form" id="af-options" name="options" rows="2"
                      placeholder="e.g. Option A, Option B, Option C"></textarea>
            <p class="form-hint">For dropdown, checkbox, and radio fields, write the choices separated by commas.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <span class="listing-field-label">Required</span>
          <div>
            <div class="flex-row">
              <label class="form-check">
                <input type="radio" name="required" value="1">
                <span class="form-check-label">Yes</span>
              </label>
              <label class="form-check">
                <input type="radio" name="required" value="0" checked>
                <span class="form-check-label">No</span>
              </label>
            </div>
            <p class="form-hint">Choose Yes when members must answer this before they can submit the form.</p>
          </div>
        </div>
      </div>
      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Add Field</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
      </div>
    </div>
  </form>

  <div class="card">
    <div class="card-head">Current Fields</div>
    <?php if (empty($fields)): ?>
      <div class="empty">
        <span class="empty-icon"><?= View::icon('tag', ['width' => 32, 'height' => 32]) ?></span>
        No additional fields defined yet.
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Label</th>
              <th>Field Name</th>
              <th>Type</th>
              <th>Required</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fields as $field): ?>
            <?php $_typeLabels = ['text' => 'Text Field', 'dropdown' => 'Dropdown', 'checkbox' => 'Checkbox', 'radio' => 'Radio Button']; ?>
            <tr>
              <td class="text-strong"><?= View::e($field->label) ?></td>
              <td><code><?= View::e($field->name) ?></code></td>
              <td class="text-muted"><?= View::e($_typeLabels[$field->type] ?? ucfirst($field->type)) ?></td>
              <td>
                <?php if ($field->required): ?>
                  <span class="badge badge-current">Yes</span>
                <?php else: ?>
                  <span class="badge badge-inactive">No</span>
                <?php endif; ?>
              </td>
              <td class="act-col">
                <div class="data-actions">
                  <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/<?= urlencode($field->name) ?>/edit"
                     class="btn btn-tertiary btn-sm">Edit</a>
                  <form method="POST"
                        action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/<?= urlencode($field->name) ?>/delete"
                        class="action-form-inline"
                        data-del-modal data-del-entity="field"
                        data-del-name="<?= View::e($field->name) ?>">
                    <input type="hidden" name="_csrf"     value="<?= View::e($csrf_token ?? '') ?>">
                    <input type="hidden" name="from_edit" value="1">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  </form>
  <?php endif; ?>
</div>
<!-- ──── TAB: CODES ─────────────────────────────────────────────────── -->
<div id="tab-codes" class="tabs-panel">
<?php
// Unpack code variables for convenience
$_fid          = (int) $listing->id;
$_hasCats      = !empty($code_cats);
$_hasDonors    = !empty($code_donors);
$_pendingCount  = $code_status_counts['pending']  ?? 0;
$_approvedCount = $code_status_counts['approved'] ?? 0;
$_rejectedCount = $code_status_counts['rejected'] ?? 0;
$_nonEmptyGroups = array_filter($code_grouped, fn($g) => !empty($g['codes']));
$_groupCount   = count($_nonEmptyGroups);
$_topLevelCats = array_filter($code_cats, fn($c) => $c->parentId === null);
$_tabBase      = '?tab=codes';
if ($_hasCats || $_hasDonors) {
    $_settingsSummary = count($code_cats) . ' categor' . (count($code_cats) !== 1 ? 'ies' : 'y')
        . ' &middot; ' . count($code_donors) . ' donor' . (count($code_donors) !== 1 ? 's' : '');
} else {
    $_settingsSummary = 'categories &amp; donors';
}
$_settingsOpen = $_hasCats || $_hasDonors;
?>

<?php if ($_pendingCount > 0): ?>
<div class="reminder-banner banner-spaced" data-codes-pending-banner>
  <div class="reminder-banner-head">
    <div class="reminder-banner-left">
      <span class="reminder-banner-icon"><?= View::icon('bell') ?></span>
      <div>
        <span class="reminder-banner-title" data-codes-pending-title><?= $code_status_filter === 'pending' ? 'Showing pending codes' : 'Codes awaiting review' ?></span>
        <span class="reminder-banner-msg" data-codes-pending-msg"><?= $_pendingCount ?> code<?= $_pendingCount !== 1 ? 's are' : ' is' ?> pending approval</span>
      </div>
    </div>
    <a href="<?= $code_status_filter === 'pending' ? $_tabBase . '&status_filter=approved' : $_tabBase . '&status_filter=pending' ?>" class="btn btn-tertiary btn-sm" data-code-filter="<?= $code_status_filter === 'pending' ? 'approved' : 'pending' ?>" data-codes-pending-action><?= $code_status_filter === 'pending' ? '&larr; Show approved' : 'Review pending &rarr;' ?></a>
  </div>
</div>
<?php endif; ?>

<details class="card chevron is-hidden" id="settingsBand">
  <summary class="card-head">Code Categories & Donors</summary>

  <div class="settings-band-grid">
    <div class="settings-band-col">
      <div class="pc-section-label">Categories</div>
      <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/categories/add" class="codes-form-inline">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <div class="codes-form-grow">
          <input class="form" type="text" name="name" placeholder="Category name&hellip;" required>
        </div>
        <?php if (!empty($_topLevelCats)): ?>
        <div class="select-wrap codes-select-wrap">
          <select name="parent_id" >
            <option value="">None (top-level)</option>
            <?php foreach ($_topLevelCats as $tlc): ?>
              <option value="<?= (int)$tlc->id ?>"><?= View::e($tlc->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary"><?= View::icon('add') ?> Add</button>
      </form>
      <?php if ($_hasCats): ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr>
              <th>Name</th>
              <th>Amount</th>
              <th>Parent</th>
              <th>Actions</th>
            </tr></thead>
          <tbody>
            <?php foreach ($code_cats as $cat): ?>
            <?php $_isCatEditing = $code_edit_cat_id === $cat->id; ?>
            <tr<?= $_isCatEditing ? ' class="table-row-hidden"' : '' ?>>
              <td class="text-strong"><?= View::e($cat->name) ?></td>
              <td>
                <?php $_catCodeCount = count(array_filter($code_category_ids, fn($ids) => in_array($cat->id, $ids, true))); ?>
                <span class="badge badge-info"><?= $_catCodeCount ?> code<?= $_catCodeCount !== 1 ? 's' : '' ?></span>
              </td>
              <td>
                <?php if ($cat->parentId !== null && isset($code_cat_map[$cat->parentId])): ?>
                  <span class="text-dim"><?= View::e($code_cat_map[$cat->parentId]->name) ?></span>
                <?php else: ?>
                  <span class="text-dim">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="act-col">
                <a href="<?= $_tabBase ?>&edit_cat=<?= (int)$cat->id ?>" class="btn btn-tertiary btn-sm">Edit</a>
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/categories/<?= (int)$cat->id ?>/delete"
                      class="action-form-inline" data-del-modal data-del-entity="code_category" data-del-name="<?= View::e($cat->name) ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <button class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <tr<?= !$_isCatEditing ? ' class="table-row-hidden"' : '' ?>>
              <td colspan="4">
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/categories/<?= (int)$cat->id ?>/edit" class="codes-form-actions">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <div class="codes-form-grow-sm">
                    <input class="form" type="text" name="name" value="<?= View::e($cat->name) ?>" required>
                  </div>
                  <div>
                    <div class="select-wrap">
                      <select name="parent_id" class="select-xs">
                        <option value="">None (top-level)</option>
                        <?php foreach ($code_cats as $pcat): ?>
                          <?php if ($pcat->id === $cat->id || $pcat->parentId !== null) continue; ?>
                          <option value="<?= (int)$pcat->id ?>" <?= $cat->parentId === $pcat->id ? 'selected' : '' ?>>
                            <?= View::e($pcat->name) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <button class="btn btn-primary btn-sm">Save</button>
                  <a href="<?= $_tabBase ?>" class="btn btn-quaternary btn-sm">Cancel</a>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <div class="settings-band-col">
      <div class="pc-section-label codes-section-label">Donors</div>
      <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/donors/add" class="codes-form-inline">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <div class="codes-form-grow-sm"><input type="text" class="form" name="name" placeholder="Display name&hellip;" required></div>
        <div class="codes-form-grow-sm"><input type="email" class="form" name="email" placeholder="Email (optional)"></div>
        <div class="codes-form-grow-sm"><input type="url" class="form" name="url" placeholder="URL (optional)"></div>
        <button type="submit" class="btn btn-primary"><?= View::icon('add') ?> Add</button>
      </form>
      <?php if ($_hasDonors): ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Name</th><th>Email</th><th>URL</th><th># Donated</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($code_donors as $dn): ?>
            <?php $_isDnEditing = $code_edit_donor_id === $dn->id; ?>
            <tr<?= $_isDnEditing ? ' class="table-row-hidden"' : '' ?>>
              <td class="text-strong"><?= View::e($dn->name) ?></td>
              <td class="text-dim"><?= $dn->email !== null ? View::emailWrap($dn->email) : '&mdash;' ?></td>
              <td class="text-dim">
                <?php if ($dn->url !== null): ?>
                  <?php $_dnHost = parse_url($dn->url, PHP_URL_HOST) ?? $dn->url; ?>
                  <a href="<?= View::e($dn->url) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($_dnHost) ?></a>
                <?php else: ?>&mdash;<?php endif; ?>
              </td>
              <td><span class="badge badge-info"><?= count(array_filter($codes, fn($c) => $c->donorId === $dn->id)) ?></span></td>
              <td class="act-col">
                <a href="<?= $_tabBase ?>&edit_donor=<?= (int)$dn->id ?>" class="btn btn-tertiary btn-sm">Edit</a>
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/donors/<?= (int)$dn->id ?>/delete"
                      class="action-form-inline" data-del-modal data-del-entity="code_donor" data-del-name="<?= View::e($dn->name) ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <button class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <tr<?= !$_isDnEditing ? ' class="table-row-hidden"' : '' ?>>
              <td colspan="5" class="table-edit-cell">
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/donors/<?= (int)$dn->id ?>/edit" class="codes-form-actions">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <div class="codes-form-grow-sm"><input class="form" type="text" name="name" value="<?= View::e($dn->name) ?>" required></div>
                  <div class="codes-form-grow-sm"><input class="form" type="email" name="email" value="<?= View::e($dn->email ?? '') ?>" placeholder="Email"></div>
                  <div class="codes-form-grow-sm"><input class="form" type="url" name="url" value="<?= View::e($dn->url ?? '') ?>" placeholder="URL"></div>
                  <button class="btn btn-primary btn-sm">Save</button>
                  <a href="<?= $_tabBase ?>" class="btn btn-quaternary btn-sm">Cancel</a>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</details>

<?php
$_from             = 'edit';
$_groupedCodes     = $code_grouped;
$_nonEmptyGroups   = array_filter($code_grouped, fn($g) => !empty($g['codes']));
$_groupCount       = count($_nonEmptyGroups);
$_codesBaseUrl     = null;
$_codeStatusFilter = $code_status_filter;
$_totalCodes       = $total_codes;
$_codeCatIds       = $code_category_ids;
$_codeDonorMap     = $code_donor_map;
$_codeDonors       = $code_donors;
$_codeCats         = $code_cats;
$_uploadSubmitters = $code_submitter_names ?? [];
require LUSTER_BASE . '/templates/admin/codes/_listing_panel.php';
?>

</div>
<!-- ──── TAB: AFFILIATES ────────────────────────────────────────────── -->
<div id="tab-affiliates" class="tabs-panel">
<div class="toolbar" style="justify-content:flex-end;margin-bottom:1rem">
  <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/affiliates/add"
     class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add New Affiliate</a>
</div>

<?php
  $_affFid       = (int) $listing->id;
  // Filter out rejected affiliates for the embedded table
  $_affFiltered  = array_values(array_filter($affiliates, fn($a) => $a->status !== 'rejected'));
  // Variables expected by affiliates/index.php
  $affiliates         = $_affFiltered;
  $tab                = 'all';
  $scope              = (string) $_affFid;
  $search             = '';
  $sort               = 'sort_order';
  $dir                = 'asc';
  $page               = 1;
  $per_page           = max(1, count($affiliates));
  $total              = count($affiliates);
  $total_pages        = 1;
  $rangeStart         = $total > 0 ? 1 : 0;
  $rangeEnd           = $total;
  $counts             = ['all' => $total, 'pending' => 0, 'approved' => 0, 'inactive' => 0, 'rejected' => 0];
  $conflict_ids       = [];
  $listing_map        = [$_affFid => $listing];
  $listings           = [];
  $filters_active     = false;
  $scope_counts       = [];
  $returnUrl          = $b . '/admin/listings/' . $_affFid . '?tab=affiliates';
  $_aff_table_only    = true;
  include LUSTER_BASE . '/templates/admin/affiliates/index.php';
  unset($affiliates, $tab, $scope, $search, $sort, $dir, $page, $per_page, $total, $total_pages,
        $rangeStart, $rangeEnd, $counts, $conflict_ids, $listing_map, $listings,
        $filters_active, $scope_counts, $returnUrl, $_aff_table_only, $_affFiltered, $_tableOnly);
  $affiliates = $affiliates ?? [];  // restore for any subsequent use
?>
</div>
<!-- ──── TAB: EMBED ─────────────────────────────────────────────────── -->
<div id="tab-embed" class="tabs-panel">
<?php
/** @var array  $embed_settings */
/** @var array  $cfg */
$embedSettings  = $embed_settings ?? [];
$installPath    = rtrim((string) ($cfg['install_path']   ?? ''), '/');
$embedBaseUrl   = rtrim((string) ($cfg['embed_base_url'] ?? ''), '/') ?: rtrim((string) ($cfg['site_url'] ?? ''), '/');
?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('embed') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Embed Settings</div>
      <div class="pc-head-sub">Tell Luster which public pages hold this listing's forms, and get the include snippets for your site.</div>
    </div>
  </div>
  
    <div class="pc-section">
    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/embed" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

      <div class="pc-section-label">Form Page URLs</div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-join">Join Form</label>
        <div>
          <input id="fl-form-join" type="text" name="form_url_join" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_join'] !== '' ? $embedSettings['form_url_join'] : 'join.php') ?>">
          <p class="form-hint">The public page on your site where visitors can join this listing.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-update">Update Membership</label>
        <div>
          <input id="fl-form-update" type="text" name="form_url_update" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_update'] !== '' ? $embedSettings['form_url_update'] : 'update.php') ?>">
          <p class="form-hint">The public page where members can update their information, or permanently delete their membership.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-reset">Password Reset</label>
        <div>
          <input id="fl-form-reset" type="text" name="form_url_reset" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_reset'] !== '' ? $embedSettings['form_url_reset'] : 'reset.php') ?>">
          <p class="form-hint">The public page where members can request or finish a password reset.</p>
        </div>
      </div>

      <div class="listing-field-row" id="fl-aff-form-url-row"
           <?= ($affiliate_apply_enabled ?? false) ? '' : 'style="display:none"' ?>>
        <label class="listing-field-label" for="fl-form-url-aff-apply">Affiliate Applications</label>
        <div>
          <input id="fl-form-url-aff-apply" type="text" name="form_url_affiliate_apply" class="form"
                 maxlength="500"
                 value="<?= View::e(($form_url_affiliate_apply ?? '') !== '' ? $form_url_affiliate_apply : 'affiliate-apply.php') ?>">
          <p class="form-hint">The public page where other sites can apply to affiliate with this listing. Leave blank to use Luster's built-in application page.</p>
        </div>
      </div>

      <div class="listing-field-row" id="fl-codes-submit-embed-row"
           <?= ($embedSettings['codes_submit_enabled'] ?? true) ? '' : 'style="display:none"' ?>>
        <label class="listing-field-label" for="fl-form-codes-submit">Code Donation</label>
        <div>
          <input id="fl-form-codes-submit" type="text" name="form_url_codes_submit" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_codes_submit'] !== '' ? $embedSettings['form_url_codes_submit'] : 'codes-submit.php') ?>">
          <p class="form-hint">The public page where visitors can donate codes for this listing.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-form-contact">Contact Form</label>
        <div>
          <input id="fl-form-contact" type="text" name="form_url_contact" class="form"
                 maxlength="200"
                 value="<?= View::e(($form_url_contact ?? '') !== '' ? $form_url_contact : 'contact.php') ?>">
          <p class="form-hint">The public page where visitors can send you a message about this listing.</p>
        </div>
      </div>
    </div>

  <div class="composer-footer">
    <button type="submit" class="btn btn-primary">Save Embed Settings</button>
    <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
  </div>
  </form>
  </div>

<!-- ──── EMBED SNIPPETS ───────────────────────────────────────────── -->
<details class="chevron card" style="margin-bottom:1.75rem">
  <summary class="card-head">Embed Snippets</summary>
  <div class="card-body">
    <?php if ($installPath !== ''): ?>
      <?php
        $_es = $embedSettings ?? [];
        $_snipPath = View::e($installPath);
        $_snipId   = (int) $listing->id;
        // Helper to render one snippet row
        $snip = static function (string $label, string $file) use ($_snipPath, $_snipId, $b): void {
            echo '<div class="listing-field-row listing-field-row--top">';
            echo '<span class="listing-field-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '<div><div class="ro-field embed-snippet">';
            echo '$luster_fanlisting_id = ' . $_snipId . ';<br>';
            echo "require '" . $_snipPath . '/public/embed/' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . "';";
            echo '</div></div></div>';
        };
      ?>

      <details class="snip-section" open>
        <summary class="pc-section-label snip-section-summary">Member Pages</summary>
        <?php $snip('Join', 'join.php') ?>
        <?php $snip('Members list', 'members.php') ?>
        <?php $snip('Update membership', 'update.php') ?>
        <?php $snip('Password reset', 'reset.php') ?>
      </details>

      <details class="snip-section" open>
        <summary class="pc-section-label snip-section-summary">Stats &amp; Extras</summary>
        <?php $snip('Stats', 'stats.php') ?>
        <?php $snip('Codes', 'codes.php') ?>
        <?php $snip('Affiliates', 'affiliates.php') ?>
        <?php if ($contact_enabled ?? false): ?>
          <?php $snip('Contact form', 'contact.php') ?>
        <?php endif; ?>
      </details>

      <?php if (in_array('Updates', $nav_installed_addons ?? [], true)): ?>
      <details class="snip-section" open>
        <summary class="pc-section-label snip-section-summary">Updates Feed</summary>
        <?php $snip('Updates feed', 'updates.php') ?>
      </details>
      <?php endif; ?>


      <?php if (in_array('Kim', $nav_installed_addons ?? [], true)): ?>
      <details class="snip-section" open>
        <summary class="pc-section-label snip-section-summary">Keep In Mind</summary>
        <?php $snip('KIM sign-up', 'kim-signup.php') ?>
        <?php $snip('KIM member list', 'kim-list.php') ?>
        <?php $snip('KIM update', 'kim-update.php') ?>
        <?php $snip('KIM password reset', 'kim-reset.php') ?>
      </details>
      <?php endif; ?>

    <?php else: ?>
      <p class="form-hint">
        Set the <strong>Installation Path</strong> in
        <a href="<?= $b ?>/admin/settings" class="link">Settings &rarr; Server Paths</a>
        to show ready-to-use snippets here.
      </p>
    <?php endif; ?>
  </div>
</details>


<!-- ──── COMPAT WRAPPERS ──────────────────────────────────────────── -->
<details class="chevron card" style="margin-bottom:1.75rem" id="compat-wrappers">
  <summary class="card-head">Compatibility Wrappers</summary>
  <div class="card-body">
    <p class="form-hint mb-md">
      To migrate your existing fanlisting pages to Luster, download a compatibility bridge
      from the <a href="<?= $b ?>/admin/collective" class="link">Collective</a> page.
      Bridges are available for Enthusiast, Listing Admin, and CodeSort.
    </p>
  </div>
</details>
</div>

<!-- ──── TAB: ADVANCED ──────────────────────────────────────────────── -->
<div id="tab-advanced" class="tabs-panel">

  <div class="callout callout-err">
    <?= View::icon('alert') ?>
    <div>
      <strong>Advanced settings - proceed with caution!</strong>
      <span>Only change these if you are intentionally moving where this listing stores or reads members. Wrong values can make the listing lose track of its members.</span>
    </div>
  </div>

  <details class="card chevron">
    <summary class="card-head">Member Storage Name
      <div class="card-head-sub">The internal name Luster uses for this listing's member records. Most users never need to change this.</div>
    </summary>
    <div class="card-body">
      <div class="listining-field-row">
        <input type="text" class="form mono" value="<?= View::e($listing->dbprefix) ?>"
               readonly>
        <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit-prefix"
           class="btn btn-danger">Rename</a>
      </div>
    </div>
  </details>

  <details class="card chevron">
    <summary class="card-head">Separate Database Login
      <div class="card-head-sub">Use this only if this listing's members live in a different database from the rest of Luster. Leave everything blank for the normal setup.</div>
    </summary>
    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/embed" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <?php
      /** @var array $embed_settings */
      /** @var array $cfg */
      $advEmbedSettings = $embed_settings ?? [];
      ?>
      <div class="listing-field-section">
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="adv-db-host">DB Host</label>
          <div>
            <input id="adv-db-host" type="text" name="db_host" class="form"
                   maxlength="255" placeholder="localhost"
                   value="<?= View::e($advEmbedSettings['db_host'] ?? '') ?>">
            <p class="form-hint">The database server name, usually <code>localhost</code>.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="adv-db-name">DB Name</label>
          <div>
            <input id="adv-db-name" type="text" name="db_name" class="form"
                   maxlength="255" placeholder="my_database"
                   value="<?= View::e($advEmbedSettings['db_name'] ?? '') ?>">
            <p class="form-hint">The database name that contains this listing's member records.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="adv-db-user">DB User</label>
          <div>
            <input id="adv-db-user" type="text" name="db_user" class="form"
                   maxlength="255" autocomplete="off"
                   value="<?= View::e($advEmbedSettings['db_user'] ?? '') ?>">
            <p class="form-hint">The username Luster should use for that separate database.</p>
          </div>
        </div>
        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="adv-db-pass">DB Password</label>
          <div>
            <div class="input-icon-wrap">
              <input id="adv-db-pass" type="password" name="db_pass" class="form"
                     maxlength="500" autocomplete="new-password"
                     placeholder="Leave blank to keep current">
              <button type="button" data-pw-toggle aria-label="Show password" tabindex="-1" class="input-icon-btn">
                <?= View::icon('eye-on') ?>
                <?= View::icon('eye-off') ?>
              </button>
            </div>
            <p class="form-hint">Only fill this in when you want to set or replace the saved password.</p>
          </div>
        </div>
      </div>
      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Settings</button>
        <button type="reset" class="btn btn-ghost">Reset to Default</button>
        <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </details>

</div>
