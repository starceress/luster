<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Edit listing page. A multi-tab view for managing a single owned listing, with tabs
 for details (title, subject, URL, categories, image), additional fields, affiliates,
 per-listing templates (members row, stats, affiliates section, codes, forms),
 joining rules, and a danger zone for deletion. Also shows member/affiliate counts
 and quick links.
*/
use Luster\Core\View;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Support\RulesBodyRenderer;
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
if ($listing->lifecycle !== null) {
    $currentStatus = $listing->lifecycle;
} elseif ($listing->upcoming) {
    $currentStatus = 'upcoming';
} elseif ($listing->trouble) {
    $currentStatus = 'pending';
} else {
    $currentStatus = 'current';
}
$oldStatus = $old['status'] ?? $currentStatus;
$oldAdoptedToName = $old['adopted_to_name'] ?? ($lifecycle_meta['adopted_to_name'] ?? '');
$oldAdoptedToUrl  = $old['adopted_to_url']  ?? ($lifecycle_meta['adopted_to_url']  ?? '');
$oldArchiveNote   = $old['archive_note']    ?? ($lifecycle_meta['archive_note']    ?? '');

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

<div class="toolbar">
  <a href="<?= $b ?>/admin/listings" class="link">
    &larr; Back to Owned Listings
  </a>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $listing->id ?>&status=all" class="btn btn-quaternary btn-sm">Member List</a>
    <a href="<?= $b ?>/admin/emails?tab=bulk&listing=<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">Bulk Email</a>
    <?php if (in_array('Newsfeed', $nav_installed_addons ?? [], true)): ?>
      <a href="<?= $b ?>/admin/newsfeed/<?= (int) $listing->id ?>" class="btn btn-quaternary btn-sm">News Feed</a>
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
  <?php if ($newest_member ?? null): ?>
    <div class="stat-extra"><strong>Newest Member:</strong> <?= $newest_member['url'] !== '' ? '<a href="' . View::e($newest_member['url']) . '" target="_blank">' . View::e($newest_member['name']) . '</a>' : View::e($newest_member['name']) ?></div>
  <?php endif; ?>
</div>
<div class="stat-card">
  <div class="stat-header">Pending</div>
  <div class="stat-value"><?= $pending > 0 ? '<a href="' . $pl . '" class="link pending-link">' . $pending . '</a>' : $pending ?></div>
  <?php if ($pending > 0): ?>
    <div class="stat-extra"><a href="<?= $pl ?>">Review Pending &rarr;</a></div>
  <?php endif; ?>
</div>
<div class="stat-card">
  <div class="stat-header">Total Countries</div>
  <div class="stat-value"><?= (int) ($countries ?? 0) ?></div>
  <?php if (!empty($top_countries)): ?>
    <div class="stat-extra"><strong>Top 3 Countries:</strong> <?php
      $parts = [];
      foreach ($top_countries as $_tc) {
          $pct = $approved > 0 ? round($_tc['fan_count'] / $approved * 100) : 0;
          $parts[] = View::e($_tc['country']) . ' (' . $pct . '%)';
      }
      echo implode(', ', $parts);
    ?></div>
  <?php endif; ?>
</div>
<div class="stat-card">
  <div class="stat-header">Last Updated</div>
  <div class="stat-value"><?= View::e(($fl_last_updated ?? '') !== '' ? date($admin_date_format, strtotime($fl_last_updated)) : '-') ?></div>
  <?php
    $_opened = (string) ($listing->opened ?? '');
    $_openedTs = $_opened !== '' && $_opened !== 'TBA' ? strtotime($_opened) : false;
  ?>
  <?php if ($_openedTs !== false && $_openedTs > 0): ?>
    <div class="stat-extra"><strong>Opened On:</strong> <?= View::e(date($admin_date_format, $_openedTs)) ?></div>
  <?php else: ?>
    <div class="stat-extra"><strong>Opened:</strong> TBA &mdash; date will be set when the listing opens.</div>
  <?php endif; ?>
</div>
</div>

<div class="tabs-wrap">
<div class="tabs">
  <button class="tabs-btn active" data-tab="general">General</button>
  <button class="tabs-btn" data-tab="fields">Additional Fields</button>
  <button class="tabs-btn" data-tab="codes">Codes<?php
    $_codesApproved = $code_status_counts['approved'] ?? 0;
    $_codesPending  = $code_status_counts['pending'] ?? 0;
    if ($_codesApproved > 0): ?> <span class="badge badge-current badge-inline-sm"><?= $_codesApproved ?></span><?php endif;
    if ($_codesPending > 0): ?> <span class="badge badge-pending badge-inline-sm"><?= $_codesPending ?></span><?php endif;
  ?></button>
  <button class="tabs-btn" data-tab="affiliates">Affiliates<?php
    $_affApproved = 0; $_affPending = 0;
    foreach ($affiliates ?? [] as $_aff) { if ($_aff->status === 'approved') { $_affApproved++; } else { $_affPending++; } }
    if ($_affApproved > 0): ?> <span class="badge badge-current badge-inline-sm"><?= $_affApproved ?></span><?php endif;
    if ($_affPending > 0): ?> <span class="badge badge-pending badge-inline-sm"><?= $_affPending ?></span><?php endif;
  ?></button>
  <?php if ($rules_addon_installed ?? false): ?>
  <button class="tabs-btn" data-tab="rules">Rules</button>
  <?php endif; ?>
  <button class="tabs-btn" data-tab="templates">Templates</button>
  <button class="tabs-btn" data-tab="embed">Form URLs</button>
  <button class="tabs-btn" data-tab="snippets">Code Snippets</button>
  <button class="tabs-btn" data-tab="advanced">Advanced</button>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">Section</span>
  <div class="select-wrap flex-fill">
    <select class="tabs-select">
      <option value="general">General</option>
      <option value="fields">Additional Fields</option>
      <option value="codes">Codes<?= $_codesApproved > 0 || $_codesPending > 0 ? ' (' . $_codesApproved . '/' . $_codesPending . ')' : '' ?></option>
      <option value="affiliates">Affiliates<?= $_affApproved > 0 || $_affPending > 0 ? ' (' . $_affApproved . '/' . $_affPending . ')' : '' ?></option>
      <?php if ($rules_addon_installed ?? false): ?>
      <option value="rules">Rules</option>
      <?php endif; ?>
      <option value="templates">Templates</option>
      <option value="embed">Form URLs</option>
      <option value="snippets">Code Snippets</option>
      <option value="advanced">Advanced</option>
    </select>
  </div>
</div>
</div>
<!-- ──── TAB: GENERAL ───────────────────────────────────────────────── -->
<div id="tab-general" class="tabs-panel active">
  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" enctype="multipart/form-data" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="card card--gradient">
      <div class="panel-header">
        <div class="panel-header-icon"><?= View::icon('star') ?></div>
        <div class="panel-header-meta">
          <div class="panel-header-title">General Details</div>
          <div class="panel-header-sub">Manage the listing's details, image, status, and public form settings.</div>
        </div>
      </div>
      <div class="card-body">
        <div class="section-label">Details</div>
        <?php $lt = $listing_type ?? 'listing'; ?>
        <div class="field-row">
          <label class="field-label" for="fl-listing-type">Listing Type</label>
          <div>
            <div class="select-wrap select-auto">
              <select id="fl-listing-type" name="listing_type">
                <option value="listing"    <?= $lt === 'listing'    ? 'selected' : '' ?>>Fanlisting</option>
                <option value="clique"     <?= $lt === 'clique'     ? 'selected' : '' ?>>Clique</option>
                <option value="collective" <?= $lt === 'collective' ? 'selected' : '' ?>>Collective</option>
                <option value="shrine"     <?= $lt === 'shrine'     ? 'selected' : '' ?>>Shrine</option>
                <option value="webring"    <?= $lt === 'webring'    ? 'selected' : '' ?>>Webring</option>
                <option value="other"      <?= $lt === 'other'      ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
            <p class="form-hint">Helps categorize your listing by type (e.g. fanlisting, clique, shrine).</p>
          </div>
        </div>

        <div class="field-row field-row--top">
          <label class="field-label">Network &amp; Category</label>
          <div>
            <div class="cat-widget" id="cat-widget"
                 data-tfl='<?= $dataTfl ?>'
                 data-tafl='<?= $dataTafl ?>'
                 data-custom='<?= $dataCustom ?>'
                 data-custom-cats='<?= $dataCustomCats ?>'
                 data-existing='<?= $dataExisting ?>'>
              <div id="cat-rows"></div>
              <a href="#" class="link btn btn-sm" id="cat-add"><?= View::icon('add') ?> Add another category</a>
            </div>
            <p class="form-hint">The network(s) and category(ies) this listing is listed under.</p>
          </div>
        </div>

        <div class="field-row<?= !empty($fe['subject']) ? ' has-error' : '' ?> field-row--top">
          <label class="field-label" for="fl-subject">Subject <span class="req">*</span></label>
          <div>
            <input id="fl-subject" type="text" name="subject" class="form"
                   value="<?= View::e($old['subject'] ?? $listing->subject ?? '') ?>" maxlength="255" required>
            <p class="form-hint">The subject of your listing (e.g. character name, show title).</p>
            <?php if (!empty($fe['subject'])): ?><p class="field-error"><?= View::e($fe['subject'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="field-row<?= !empty($fe['title']) ? ' has-error' : '' ?>">
          <label class="field-label" for="fl-title">Title</label>
          <div>
            <input id="fl-title" type="text" name="title" class="form"
                   value="<?= View::e((string) $oldTitle) ?>"
                   maxlength="255">
            <p class="form-hint">Optional display name for your listing. If you leave it blank, Luster uses the subject.</p>
            <?php if (!empty($fe['title'])): ?><p class="field-error"><?= View::e($fe['title'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
          <label class="field-label" for="fl-url">Website URL <span class="req">*</span></label>
          <div>
            <input id="fl-url" type="url" name="url" class="form"
                   value="<?= View::e((string) $oldUrl) ?>"
                   maxlength="255" placeholder="https://" required>
            <?php if (!empty($fe['url'])): ?><p class="field-error"><?= View::e($fe['url'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="field-row field-row--top<?= !empty($fe['email']) ? ' has-error' : '' ?>">
          <label class="field-label" for="fl-email">Email</label>
          <div>
            <input id="fl-email" type="email" name="email" class="form"
                   value="<?= View::e((string) $oldEmail) ?>"
                   maxlength="255" required>
            <p class="form-hint">Luster uses this address as the sender for emails from this listing.</p>
            <?php if (!empty($fe['email'])): ?><p class="field-error"><?= View::e($fe['email'][0]) ?></p><?php endif; ?>
          </div>
        </div>

        <div class="field-row field-row--top">
          <label class="field-label" for="fl-description">Description</label>
          <div>
            <textarea id="fl-description" name="description" class="form" rows="4"
                      maxlength="65535"
                      placeholder="A short description of this listing&hellip;"><?= View::e($description ?? '') ?></textarea>
            <p class="form-hint">Optional. Available in embed templates as <code>{listing_description}</code> and on your site pages as <code>&lt;?= $luster_description ?&gt;</code> (via the stats embed).</p>
          </div>
        </div>

        <div class="field-row">
          <label class="field-label">Status</label>
          <div>
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
              <label class="status-pill status-pill--archived">
                <input type="radio" name="status" value="closed"  <?= $oldStatus === 'closed'  ? 'checked' : '' ?>> Closed
              </label>
              <label class="status-pill status-pill--archived">
                <input type="radio" name="status" value="adopted_out"  <?= $oldStatus === 'adopted_out'  ? 'checked' : '' ?>> Adopted Out
              </label>
            </div>
            <p class="form-hint">Closed and Adopted Out archive the listing: it stops accepting joins/updates/contact/codes on its public forms, and its URL is no longer required. Member, code, and affiliate data are kept and remain manageable here.</p>
          </div>
        </div>

        <div class="field-row field-row--top" id="archive-note-row">
          <label class="field-label" for="fl-archive-note">Archive Note</label>
          <div>
            <textarea id="fl-archive-note" name="archive_note" class="form" rows="2"
                      placeholder="Optional internal note about why this listing was archived&hellip;"><?= View::e((string) $oldArchiveNote) ?></textarea>
          </div>
        </div>

        <div class="field-row" id="adopted-to-name-row">
          <label class="field-label" for="fl-adopted-to-name">Adopted By (Name)</label>
          <div>
            <input id="fl-adopted-to-name" type="text" name="adopted_to_name" class="form"
                   value="<?= View::e((string) $oldAdoptedToName) ?>" maxlength="255"
                   placeholder="Name of the new owner or successor listing">
          </div>
        </div>

        <div class="field-row" id="adopted-to-url-row">
          <label class="field-label" for="fl-adopted-to-url">Adopted By (URL)</label>
          <div>
            <input id="fl-adopted-to-url" type="url" name="adopted_to_url" class="form"
                   value="<?= View::e((string) $oldAdoptedToUrl) ?>" maxlength="500"
                   placeholder="https://">
          </div>
        </div>

        <div class="field-row" id="opened-row">
          <label class="field-label" for="fl-opened">Opened On</label>
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

        <div class="field-row field-row--top">
          <label class="field-label" for="fl-last-updated">Last Updated</label>
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


      <div class="card-body">
        <div class="section-label">Listing Image</div>
        <div class="field-row field-row--top">
          <label class="field-label">Image</label>
          <?php if (isset($current_image) && $current_image !== null && $current_image !== ''): ?>
          <div class="img-upload-panel img-upload-panel--two-col">
            <div class="img-current-card" id="fl-img-current-card">
              <div class="img-current-preview">
                <img src="<?= $b ?>/uploads/listings/<?= View::e($current_image) ?>"
                     alt="<?= View::e($current_image) ?>">
              </div>
              <div class="img-current-footer">
                <span class="img-current-name"><?= View::e($current_image) ?></span>
                <button type="button" class="btn btn-danger btn-sm img-delete-btn" id="fl-delete-image-btn"
                        data-delete-url="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/delete-image"
                        data-csrf="<?= View::e($csrf_token ?? '') ?>">Delete Image</button>
              </div>
            </div>
            <div class="img-replace-col">
              <div class="panel-label">Replace with a new image</div>
              <label class="file-drop-zone file-drop-zone--sm">
                <input type="file" id="thumbnail" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
                <span class="file-drop-icon"><?= View::icon('image') ?></span>
                <strong>Drag &amp; drop or click to browse</strong>
                <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
              </label>
            </div>
          </div>
          <?php else: ?>
          <div class="img-upload-panel">
            <label class="file-drop-zone">
              <input type="file" id="thumbnail" name="image" accept="image/jpeg,image/png,image/gif,image/webp">
              <span class="file-drop-icon"><?= View::icon('image') ?></span>
              <strong>Drag &amp; drop or click to browse</strong>
              <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 2 MB</span>
            </label>
          </div>
          <?php endif; ?>
        </div>
      </div>


      <div class="card-body">
        <div class="section-label">Public Display Preferences</div>
        <?php $ab = $approved_by ?? ''; ?>
        <div class="field-row field-row--top">
          <label class="field-label" for="fl-approved-by">Approved By</label>
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

        <?php $aurl = $approval_url ?? ''; ?>
        <div class="field-row field-row--top" id="fl-approval-url-row" <?= $ab === '' ? 'style="display:none"' : '' ?>>
          <label class="field-label" for="fl-approval-url">Approval Page URL</label>
          <div>
            <div style="display:flex;gap:.5rem;align-items:flex-start">
              <input id="fl-approval-url" type="url" name="approval_url" class="form"
                     value="<?= View::e($aurl) ?>"
                     placeholder="https://thefanlistings.org/tfl/all/..."
                     style="flex:1">
              <button type="button" id="fl-tfl-lookup-btn" class="btn btn-quaternary"
                      data-listing-id="<?= (int) ($listing->id ?? 0) ?>"
                      data-endpoint="<?= $b ?>/admin/tfl-lookup">Look up</button>
            </div>
            <p class="form-hint" id="fl-tfl-lookup-hint">
              The specific TFL or TAFL page where this fanlisting is listed. Used for the <code>{tfl_url}</code> / <code>{tafl_url}</code> stats token. Click &ldquo;Look up&rdquo; to search the network by this listing&rsquo;s subject, then save to confirm.
            </p>
            <div id="fl-tfl-lookup-result" style="display:none;margin-top:.5rem"></div>
          </div>
        </div>

        <div class="field-row field-row--top">
          <label class="field-label">Allow Affiliate Applications</label>
          <div>
            <input type="hidden" name="affiliate_apply_enabled" value="0">
            <label class="toggle-wrap">
              <input type="checkbox" name="affiliate_apply_enabled" value="1" id="fl-affiliate-apply-enabled"
                     <?= ($affiliate_apply_enabled ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Accept affiliate applications for this listing</span>
            </label>
            <p class="form-hint">When enabled, the <code>{affiliate_apply_toggle}</code> token in your affiliates template shows a trigger that reveals the <code>{affiliate_apply_form}</code> application form.</p>
          </div>
        </div>

        <div class="field-row field-row--top">
          <label class="field-label">Allow Codes Donations</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="codes_submit_enabled" value="1" id="fl-codes-submit-enabled"
                     <?= ($embed_settings['codes_submit_enabled'] ?? true) ? 'checked' : '' ?>>
              <span class="toggle-label">Allow fans to donate codes for this listing</span>
            </label>
            <p class="form-hint">Enables the public code submission form. The <code>{codes_submit_toggle}</code> token in your codes template shows a trigger that reveals the <code>{codes_submit_form}</code> donation form. Submissions appear in the <a href="#" class="link" data-jump-tab="codes">Codes</a> tab for review.</p>
            <?php if ($embed_settings['codes_submit_enabled'] ?? true): ?>
            <div style="margin-top:.75rem">
              <?php
                $_cae = $code_approval_email ?? null;
                $_caeVal = $_cae === null ? '' : ($_cae ? '1' : '0');
              ?>
              <div class="select-wrap select-auto">
                <select name="code_approval_email">
                  <option value=""  <?= $_caeVal === ''  ? 'selected' : '' ?>>Use default (email donor on approval)</option>
                  <option value="1" <?= $_caeVal === '1' ? 'selected' : '' ?>>Always email donor on approval</option>
                  <option value="0" <?= $_caeVal === '0' ? 'selected' : '' ?>>Never email donor on approval</option>
                </select>
              </div>
              <p class="form-hint">Toggles whether to email the donor when their submitted code is approved for this listing.</p>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="field-row">
          <label class="field-label">Enable Contact Form</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="contact_enabled" value="1"
                     <?= ($contact_enabled ?? true) ? 'checked' : '' ?>>
              <span class="toggle-label">Enable contact form for this listing</span>
            </label>

          <p class="form-hint">Enable a contact form for this specific listing.</p>
          </div>
          <div>
          </div>
        </div>


        <?php if ($adoption_addon_installed ?? false): ?>
        <div class="field-row field-row--top">
          <label class="field-label">Put Up For Adoption</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="for_adoption" value="1" id="fl-for-adoption"
                     <?= ($for_adoption ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Accept adoption applications</span>
            </label>
            <p class="form-hint">Make this listing available in the collective adoption form. Pending applications can be reviewed at the <a href="<?= $b ?>/admin/adoptions?listing_id=<?= (int) $listing->id ?>" class="link">Adoptions</a> page.</p>
            <?php if (($for_adoption ?? false) && ($adoption_count ?? 0) > 0): ?>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <div class="field-row field-row--top">
          <label class="field-label">Auto Approve Joins</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="auto_approve_joins" value="1" id="fl-auto-approve-joins"
                     <?= ($auto_approve_joins ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Auto-approve new joins for this listing</span>
            </label>
            <p class="form-hint">New joins are held for manual approval by default. Enabling this will approve new joins automatically (for this listing only).</p>
          </div>
        </div>

        <div class="field-row">
          <label class="field-label">Queue Member Updates for Review</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" name="queue_updates" value="1" id="fl-queue-updates"
                     <?= ($queue_updates ?? false) ? 'checked' : '' ?>>
              <span class="toggle-label">Hold updates for review</span>
            </label>
            <p class="form-hint">Name, email, URL, country, and custom field changes wait for admin approval. Password changes always apply immediately. Review pending updates in the <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $listing->id ?>&amp;status=pending" class="link">Members page</a>.</p>
          </div>
        </div>
      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="button" class="btn btn-quaternary js-form-reset">Reset</button>
      </div>
    </div>
  </form>
</div>


<?php
// Templates tab — local helpers
$hasNewsfeedAddon = (bool) ($newsfeed_addon_installed ?? false);
$hasCodesSubmit   = (bool) ($embed_settings['codes_submit_enabled'] ?? true);
$hasRulesAddon    = (bool) ($rules_addon_installed ?? false) && (bool) ($rules_enabled_for_listing ?? false);

// For templates that have known code defaults, treat a DB value that
// matches any recognised old default format as absent (null) so the
// current code default is shown instead. This lets default changes
// flow through to the textarea without a manual per-listing migration.
$_isOldStatsTemplate = static function (?string $v): bool {
    return $v !== null && str_starts_with(ltrim($v), '<div class="fl-stats">');
};

// When no per-listing override exists, show the global default if one is set, otherwise the built-in.
// This keeps the textarea contents and reset-button targets consistent with the badge showing 'Global'.
$_gFallback = static function (string $builtIn, string $globalKey) use ($global_templates): string {
    $g = ($global_templates ?? [])[$globalKey] ?? null;
    return ($g !== null && trim($g) !== '') ? $g : $builtIn;
};

$tplMembersVal   = (ListingSettingsRepo::isLegacyMembersTemplate($templates['members_template'] ?? null) || ($templates['members_template'] ?? '') === '')
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
$tplUpdatesVal      = ($templates['newsfeed_template']            ?? '') ?: $_gFallback(ListingSettingsRepo::defaultNewsfeedTemplate(),              'listing_default_newsfeed_template');
$tplCodesFormVal    = ($templates['codes_submit_form_template']    ?? '') ?: $_gFallback(ListingSettingsRepo::defaultCodesSubmitFormTemplate(),    'listing_default_codes_submit_form_template');
$tplAffApplyFormVal = ($templates['affiliate_apply_form_template'] ?? '') ?: $_gFallback(ListingSettingsRepo::defaultAffiliateApplyFormTemplate(), 'listing_default_affiliate_apply_form_template');
$tplJoinFormVal     = ($templates['join_form_template']              ?? '') ?: $_gFallback(ListingSettingsRepo::defaultJoinFormTemplate(),          'listing_default_join_form_template');
$tplCodesVal        = ($templates['codes_template']                  ?? '') ?: $_gFallback(ListingSettingsRepo::defaultCodesTemplate(),           'listing_default_codes_template');
$tplUpdateFormVal   = ($templates['update_form_template']             ?? '') ?: $_gFallback(ListingSettingsRepo::defaultUpdateFormTemplate(), 'listing_default_update_form_template');
$tplResetFormVal    = ($templates['reset_form_template']              ?? '') ?: $_gFallback(ListingSettingsRepo::defaultResetFormTemplate(),  'listing_default_reset_form_template');
$tplContactFormVal  = ($templates['contact_form_template']           ?? '') ?: $_gFallback(ListingSettingsRepo::defaultContactFormTemplate(), 'listing_default_contact_form_template');

$activeGroup = (string) ($_GET['group'] ?? 'all');
$_validGroups = ['all', 'members', 'join-form', 'update-form', 'reset-form', 'statistics', 'codes', 'codes-form', 'affiliates', 'affiliate-form', 'newsfeed', 'contact-form'];
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
<?php
$_tplBaseEndpoint = $b . '/admin/listings/' . (int) $listing->id;
$_tplCsrf = View::e($csrf_token ?? '');
?>
<div id="tab-templates" class="tabs-panel"
     data-tpl-base="<?= View::e($_tplBaseEndpoint) ?>"
     data-tpl-csrf="<?= $_tplCsrf ?>">

<div class="filter-panel is-open">
  <div class="filter-row" id="tpl-filter-row">
    <label for="tpl-group-select" class="tpl-filter-label">Select Template:</label>
    <div class="select-wrap">
      <select id="tpl-group-select" class="form">
        <option value="all"<?= $activeGroup === 'all' ? ' selected' : '' ?>>All Templates</option>
        <option value="affiliate-form"<?= $activeGroup === 'affiliate-form' ? ' selected' : '' ?>>Affiliate Application Form</option>
        <option value="affiliates"<?= $activeGroup === 'affiliates' ? ' selected' : '' ?>>Affiliates Display</option>
        <option value="codes"<?= $activeGroup === 'codes' ? ' selected' : '' ?>>Codes Display</option>
        <option value="codes-form"<?= $activeGroup === 'codes-form' ? ' selected' : '' ?>>Codes Donation Form</option>
        <option value="contact-form"<?= $activeGroup === 'contact-form' ? ' selected' : '' ?>>Contact Form</option>
        <option value="join-form"<?= $activeGroup === 'join-form' ? ' selected' : '' ?>>Join Form</option>
        <option value="reset-form"<?= $activeGroup === 'reset-form' ? ' selected' : '' ?>>Lost Password Form</option>
        <option value="members"<?= $activeGroup === 'members' ? ' selected' : '' ?>>Members List</option>
        <option value="newsfeed"<?= $activeGroup === 'newsfeed' ? ' selected' : '' ?>>News Feed</option>
        <option value="statistics"<?= $activeGroup === 'statistics' ? ' selected' : '' ?>>Statistics</option>
        <option value="update-form"<?= $activeGroup === 'update-form' ? ' selected' : '' ?>>Update Membership Form</option>
      </select>
    </div>
    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="search" id="tpl-group-search" class="form" placeholder="Search templates&hellip;" autocomplete="off">
    </div>
    <button type="button" class="btn btn-secondary btn-sm" id="tpl-group-search-btn">Search</button>
    <button type="button" class="btn btn-quaternary btn-sm" id="tpl-group-search-reset">Reset</button>
  </div>
</div>

<form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/templates" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="return_group" id="tpl-return-group" value="<?= View::e($activeGroup) ?>">

  <div class="composer-grid">

    <!-- ── Left column: template editors ──────────────────────────────── -->
    <div class="card card--gradient">
      <div class="panel-header">
        <div class="panel-header-icon"><?= View::icon('embed') ?></div>
        <div class="panel-header-meta">
          <div class="panel-header-title">Listing HTML Templates</div>
          <div class="panel-header-sub">HTML that Luster prints for this listing's members, affiliates, stats, and public forms. Badges show whether each template is a custom override, inherited from <a href="<?= $b ?>/admin/listings/global-templates" class="link">global defaults</a>, or the built-in fallback.</div>
        </div>
      </div>

      <div class="tpl-reset-all-row card-body" data-tpl-group="all" <?= $activeGroup !== 'all' ? 'hidden' : '' ?>>
        <div class="tpl-actions-row">
          <button type="button" class="btn btn-tertiary btn-sm"
                  id="fl-reset-all-templates"
                  data-action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/templates/reset-all"
                  data-csrf="<?= View::e($csrf_token ?? '') ?>"
                  data-confirm="Reset every template for this listing to the global default?&#10;&#10;Any per-listing customisations will be lost.">Reset All Templates to Default</button>
          <button type="button" class="btn btn-secondary btn-sm"
                  id="fl-tpack-export-btn"
                  data-endpoint="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/template-pack/export"
                  data-csrf="<?= View::e($csrf_token ?? '') ?>">Export as JSON</button>
          <button type="button" class="btn btn-secondary btn-sm"
                  id="fl-tpack-export-zip-btn"
                  data-endpoint="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/template-pack/export-zip"
                  data-csrf="<?= View::e($csrf_token ?? '') ?>">Export as ZIP</button>
          <button type="button" class="btn btn-secondary btn-sm"
                  id="fl-tpack-import-btn"
                  data-endpoint="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/template-pack/import-preview"
                  data-apply-endpoint="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/template-pack/import-apply"
                  data-csrf="<?= View::e($csrf_token ?? '') ?>">Import Templates (.json)</button>
          <button type="button" class="btn btn-secondary btn-sm"
                  id="fl-tpack-copy-btn"
                  data-listings-endpoint="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/template-pack/listings-for-copy"
                  data-source-endpoint="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/template-pack/copy-source"
                  data-apply-endpoint="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/template-pack/copy-apply"
                  data-csrf="<?= View::e($csrf_token ?? '') ?>">Copy From&hellip;</button>
        </div>
        <input type="file" id="fl-tpack-file-input" accept=".json" style="display:none">
      </div>

      <div class="card-body">

        <div class="tpl-group-section" data-tpl-group="members" <?= $_grp('members') ?>>
          <div class="section-label">Members List <?= $srcBadge('members_template') ?></div>
          <p class="form-hint form-hint--spaced">Wrap your row design in <code>{each_member}&hellip;{/each_member}</code>.</p>
          <div class="field-row" style="margin-bottom: 1em">
            <label class="field-label" for="fl-members-per-page">Members Per Page</label>
            <div>
              <input id="fl-members-per-page" type="number" name="members_per_page" class="form form--compact"
                     min="10" max="100" placeholder="25" value="<?= $members_per_page !== null ? (int) $members_per_page : '' ?>">
              <p class="form-hint">Leave blank to use the global default (25 unless changed in Collective &rarr; Templates). Choose 10&ndash;100 to override for this listing only.</p>
            </div>
          </div>
          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label">Display style</label>
            <div>
              <button type="button" class="btn btn-quaternary btn-xs" data-textarea="members_template" data-layout-preset="members_template__list">List</button>
              <button type="button" class="btn btn-quaternary btn-xs" data-textarea="members_template" data-layout-preset="members_template__table">Table</button>
              <p class="form-hint">Switches the template between a list layout and a table layout.</p>
            </div>
          </div>
          <div class="field-group">
            <textarea id="members_template" name="members_template" class="form form-mono" spellcheck="false"><?= View::e($tplMembersVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="members" data-textarea="members_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="members_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="join-form" <?= $_grp('join-form') ?>>
          <div class="section-label">Join Form <?= $srcBadge('join_form_template') ?></div>
          <p class="form-hint form-hint--spaced">Controls the full layout of the join form, including every field. Write any introductory text directly at the top of the template. Use the tokens below to arrange fields in any order.</p>
          <div class="field-group">
            <textarea id="join_form_template" name="join_form_template" class="form form-mono" spellcheck="false"><?= View::e($tplJoinFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="join_form" data-textarea="join_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="join_form_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="update-form" <?= $_grp('update-form') ?>>
          <div class="section-label">Update Membership Form <?= $srcBadge('update_form_template') ?></div>
          <p class="form-hint form-hint--spaced">Controls the full layout of the update form. Use <code>{if_not_signed_in}&hellip;{/if_not_signed_in}</code> for the sign-in step and <code>{if_signed_in}&hellip;{/if_signed_in}</code> for the update fields step.</p>
          <div class="field-group">
            <textarea id="update_form_template" name="update_form_template" class="form form-mono" spellcheck="false"><?= View::e($tplUpdateFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="update_form" data-textarea="update_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="update_form_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="reset-form" <?= $_grp('reset-form') ?>>
          <div class="section-label">Lost Password Form <?= $srcBadge('reset_form_template') ?></div>
          <p class="form-hint form-hint--spaced">Controls the full layout of the password reset form. Use <code>{if_step_email}&hellip;{/if_step_email}</code> for the email-entry step and <code>{if_step_confirm}&hellip;{/if_step_confirm}</code> for the new-password step.</p>
          <div class="field-group">
            <textarea id="reset_form_template" name="reset_form_template" class="form form-mono" spellcheck="false"><?= View::e($tplResetFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="reset_form" data-textarea="reset_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="reset_form_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="statistics" <?= $_grp('statistics') ?>>
          <div class="section-label">Statistics <?= $srcBadge('stats_template') ?></div>

          <?php $_nmlVal = ($stat_newmembers_limit ?? null) !== null && ($stat_newmembers_limit ?? '') !== '' ? (int) $stat_newmembers_limit : null; ?>
          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label" for="fl-newmembers-limit">Newest member names</label>
            <div>
              <div class="select-wrap select-auto">
                <select id="fl-newmembers-limit" name="stat_newmembers_limit" class="form">
                  <option value=""<?= $_nmlVal === null ? ' selected' : '' ?>>Use global default</option>
                  <option value="0"<?= $_nmlVal === 0 ? ' selected' : '' ?>>Show all</option>
                  <option value="5"<?= $_nmlVal !== null && $_nmlVal > 0 ? ' selected' : '' ?>>Truncate after 5</option>
                </select>
              </div>
              <p class="form-hint">Controls how the <code>{stat_newmembers}</code> token displays names. &ldquo;Show all&rdquo; lists every approved member who joined on the most recent join date. &ldquo;Truncate&rdquo; shows the 5 most recent and adds &ldquo;&hellip;and more&rdquo;. Per-listing overrides the global default.</p>
            </div>
          </div>

          <div class="field-group">
            <textarea id="stats_template" name="stats_template" class="form form-mono" spellcheck="false"><?= View::e($tplStatsVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="stats" data-textarea="stats_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="stats_template">Reset to Default</button>
          </div>
        </div>

        <div class="tpl-group-section" data-tpl-group="codes" <?= $_grp('codes') ?>>
          <div class="section-label">Codes Display <?= $srcBadge('codes_template') ?></div>
          <p class="form-hint form-hint--spaced">Controls how this listing's approved fan codes are displayed. Use <code>{each_size_group}&hellip;{/each_size_group}</code> to group codes by dimensions, or a single <code>{each_code}&hellip;{/each_code}</code> loop for a flat gallery.</p>

          <?php if ($embed_settings['codes_submit_enabled'] ?? true): ?>
          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label" for="fl-cs-toggle-style">Toggle style</label>
            <div>
              <div class="select-wrap select-auto">
                <select id="fl-cs-toggle-style" name="codes_submit_style">
                  <option value="" <?= ($codes_submit_style ?? '') === '' ? 'selected' : '' ?>>Use global default</option>
                  <option value="link" <?= ($codes_submit_style ?? '') === 'link' ? 'selected' : '' ?>>Text link</option>
                  <option value="button" <?= ($codes_submit_style ?? '') === 'button' ? 'selected' : '' ?>>Button</option>
                </select>
              </div>
              <p class="form-hint">How the <code>{codes_submit_toggle}</code> appears &mdash; as a plain underlined link or a styled button.</p>
            </div>
          </div>

          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label" for="fl-cs-toggle-label">Toggle label</label>
            <div>
              <input id="fl-cs-toggle-label" type="text" name="codes_submit_toggle_label" class="form" maxlength="200"
                     value="<?= View::e($codes_submit_toggle_label ?? '') ?>"
                     placeholder="Donate a code?">
              <p class="form-hint">The text shown on the toggle trigger. Leave blank for the default.</p>
            </div>
          </div>
          <?php endif; ?>

          <div class="field-group">
            <textarea id="codes_template" name="codes_template" class="form form-mono" spellcheck="false"><?= View::e($tplCodesVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="codes" data-textarea="codes_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="codes_template">Reset to Default</button>
          </div>
        </div>

        <?php if ($hasCodesSubmit): ?>
        <div class="tpl-group-section" data-tpl-group="codes-form" <?= $_grp('codes-form') ?>>
          <div class="section-label">Codes Donation Form <?= $srcBadge('codes_submit_form_template') ?></div>
          <p class="form-hint form-hint--spaced">Controls the full layout of the Codes Donation form. Write any introductory text directly at the top of the template. Use the tokens below to arrange fields in any order.</p>
          <div class="field-group">
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

        <div class="tpl-group-section" data-tpl-group="affiliates" <?= $_grp('affiliates') ?>>
          <div class="section-label">Affiliates Display <?= $srcBadge('affiliates_section_template') ?></div>
          <p class="form-hint form-hint--spaced">Controls the full affiliates block for this listing. Use <code>{each_affiliate}&hellip;{/each_affiliate}</code> to design each row.</p>

          <?php if ($affiliate_apply_enabled ?? false): ?>
          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label" for="fl-aff-apply-style">Toggle style</label>
            <div>
              <div class="select-wrap select-auto">
                <select id="fl-aff-apply-style" name="affiliate_apply_style">
                  <option value="" <?= ($affiliate_apply_style ?? '') === '' ? 'selected' : '' ?>>Use global default</option>
                  <option value="link" <?= ($affiliate_apply_style ?? '') === 'link' ? 'selected' : '' ?>>Text link</option>
                  <option value="button" <?= ($affiliate_apply_style ?? '') === 'button' ? 'selected' : '' ?>>Button</option>
                </select>
              </div>
              <p class="form-hint">How the <code>{affiliate_apply_toggle}</code> appears &mdash; as a plain underlined link or a styled button.</p>
            </div>
          </div>

          <div class="field-row field-row--top" style="margin-bottom: 1em">
            <label class="field-label" for="fl-aff-toggle-label">Toggle label</label>
            <div>
              <input id="fl-aff-toggle-label" type="text" name="affiliate_apply_toggle_label" class="form" maxlength="200"
                     value="<?= View::e($affiliate_apply_toggle_label ?? '') ?>"
                     placeholder="Apply to become an affiliate?">
              <p class="form-hint">The text shown on the toggle trigger. Leave blank for the default.</p>
            </div>
          </div>
          <?php endif; ?>

          <div class="field-group">
            <textarea id="affiliates_section_template" name="affiliates_section_template" class="form form-mono" spellcheck="false"><?= View::e($tplAffVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="affiliates_section" data-textarea="affiliates_section_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="affiliates_section_template">Reset to Default</button>
          </div>
        </div>

        <?php if ($affiliate_apply_enabled ?? false): ?>
        <div class="tpl-group-section" data-tpl-group="affiliate-form" <?= $_grp('affiliate-form') ?>>
          <div class="section-label">Affiliate Application Form <?= $srcBadge('affiliate_apply_form_template') ?></div>
          <p class="form-hint form-hint--spaced">Controls the full layout of the affiliate application form. Write any introductory text directly at the top of the template. Use the tokens below to arrange fields in any order.</p>
          <div class="field-group">
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
          <div class="section-label">Contact Form <?= $srcBadge('contact_form_template') ?></div>
          <p class="form-hint form-hint--spaced">Controls the full layout of the contact form. Write any introductory text directly at the top of the template. Use the tokens below to arrange fields in any order.</p>
          <div class="field-group">
            <textarea id="contact_form_template" name="contact_form_template" class="form form-mono" spellcheck="false"><?= View::e($tplContactFormVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="contact_form" data-textarea="contact_form_template"><?= View::icon('eye-on') ?> Preview</button> &nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="contact_form_template">Reset to Default</button>
          </div>
        </div>

        <?php if ($hasNewsfeedAddon): ?>
        <div class="tpl-group-section" data-tpl-group="newsfeed" <?= $_grp('newsfeed') ?>>
          <div class="section-label">News Feed <?= $srcBadge('newsfeed_template') ?></div>
          <p class="form-hint form-hint--spaced">Controls the rendered news feed in the embed. Wrap each entry in <code>{each_newsfeed_entry}&hellip;{/each_newsfeed_entry}</code>.</p>
          <div class="field-group">
            <textarea id="newsfeed_template" name="newsfeed_template" class="form form-mono" spellcheck="false"><?= View::e($tplUpdatesVal) ?></textarea>
          </div>
          <div class="tpl-reset-row">
            <button type="button" class="btn btn-preview btn-xs coltpl-preview-btn" data-tpltype="newsfeed" data-textarea="newsfeed_template"><?= View::icon('eye-on') ?> Preview</button>&nbsp;
            <button type="button" class="btn btn-quaternary btn-xs" data-textarea="newsfeed_template">Reset to Default</button>
          </div>
        </div>
        <?php else: ?>
        <input type="hidden" name="newsfeed_template" value="<?= View::e((string) ($tplUpdatesVal ?? '')) ?>">
        <?php endif; ?>

      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Save Templates</button>
      </div>
    </div>

    <!-- ── Right column: token reference ──────────────────────────────── -->
    <div class="composer-side composer-side--sticky">
    <div class="filter-panel is-open token-search-wrap">
      <div class="search-field-wrap">
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
                title="Clear Search"
                hidden><?= View::icon('x', ['aria-hidden' => 'true']) ?></button>
      </div>
    </div>

      <p id="collective-token-search-empty" data-token-search-empty class="form-hint token-search-empty" hidden>No tokens found.</p>

      <!-- Members List -->
      <details class="card chevron" data-token-card data-tpl-group="members" <?= $_grp('members') ?>>
        <summary class="chevron-head">Members List Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Wrap your row design in {each_member}...{/each_member}. Use {if_no_members} outside the loop for an empty state.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('members_template', '{each_member}{/each_member}',   '{each_member}...{/each_member}',   'Repeats the HTML inside it once for each approved member shown.') ?>
            <?= $ltok('members_template', '{fan_name}',    '{fan_name}',    'The member\'s display name.') ?>
            <?= $ltok('members_template', '{fan_email}',   '{fan_email}',   'The member\'s email. Only use this if you want emails visible publicly.') ?>
            <?= $ltok('members_template', '{fan_url}',     '{fan_url}',     'The member\'s website URL.') ?>
            <?= $ltok('members_template', '{fan_country}', '{fan_country}', 'The member\'s country.') ?>
            <?= $ltok('members_template', '{fan_added}', '{fan_added}', 'The date the member joined, formatted using the Date Format set in Settings &rarr; General.') ?>
            <?= $ltok('members_template', '{fan_FIELDNAME}', '{fan_FIELDNAME}', 'Additional field value. Replace FIELDNAME with the field\'s internal name, e.g. {fan_fave_song}.') ?>
            <?= $ltok('members_template', '{fan_additional_fields}', '{fan_additional_fields}', 'All of the member\'s additional field values at once, pre-formatted as label: value pairs. Use this instead of listing every {fan_FIELDNAME} by hand.') ?>
            <?= $ltok('members_template', '{fan_additional_field_cells}', '{fan_additional_field_cells}', 'Table-layout variant of {fan_additional_fields} &mdash; value only, no inline label. Pair with {additional_field_headers} for the header row.') ?>
            <?= $ltok('members_template', '{additional_field_headers}', '{additional_field_headers}', 'One header cell per additional field, for table-style layouts. Place outside {each_member}, rendered once.') ?>
            <?= $ltok('members_template', '{if_fan_url}{/if_fan_url}',             '{if_fan_url}...{/if_fan_url}',             'Shows only when the member has a website URL.') ?>
            <?= $ltok('members_template', '{if_no_fan_url}{/if_no_fan_url}',       '{if_no_fan_url}...{/if_no_fan_url}',       'Shows only when the member has no website URL.') ?>
            <?= $ltok('members_template', '{if_fan_country}{/if_fan_country}',     '{if_fan_country}...{/if_fan_country}',     'Shows only when the member has a country saved.') ?>
            <?= $ltok('members_template', '{if_no_fan_country}{/if_no_fan_country}','{if_no_fan_country}...{/if_no_fan_country}','Shows only when the member has no country saved.') ?>
            <?= $ltok('members_template', '{if_fan_additional_fields}{/if_fan_additional_fields}', '{if_fan_additional_fields}...{/if_fan_additional_fields}', 'Shows only when the member has at least one additional field value saved.') ?>
            <?= $ltok('members_template', '{if_no_fan_additional_fields}{/if_no_fan_additional_fields}', '{if_no_fan_additional_fields}...{/if_no_fan_additional_fields}', 'Shows only when the member has no additional field values saved.') ?>
            <?= $ltok('members_template', '{if_members}{/if_members}',       '{if_members}...{/if_members}',       'Shows when there is at least one member. Place outside {each_member}.') ?>
            <?= $ltok('members_template', '{if_no_members}{/if_no_members}', '{if_no_members}...{/if_no_members}', 'Shows when the list is empty (empty state). Place outside {each_member}.') ?>
            <?= $ltok('members_template', '{join_url}',     '{join_url}',     'URL of the join form. Useful inside {if_no_members} for a &ldquo;Want to join?&rdquo; link.') ?>
            <?= $ltok('members_template', '{member_count}', '{member_count}', 'Total approved member count. Place outside {each_member}.') ?>
            <?= $ltok('members_template', '{stats_line}',   '{stats_line}',   'The stats summary line: &ldquo;11 member(s) &mdash; page 1 of 2&rdquo; or &ldquo;5 countries represented&rdquo;. Lets you relocate or wrap it inside your custom layout.') ?>
            <?= $ltok('members_template', '{sort_link_date}',     '{sort_link_date}',     'The &ldquo;Date Joined&rdquo; sort link, rendered as &lt;strong&gt; when active or &lt;a&gt; otherwise.') ?>
            <?= $ltok('members_template', '{sort_link_name}',    '{sort_link_name}',    'The &ldquo;Name&rdquo; sort link, rendered as &lt;strong&gt; when active or &lt;a&gt; otherwise.') ?>
            <?= $ltok('members_template', '{sort_link_country}', '{sort_link_country}', 'The &ldquo;Country&rdquo; sort link, rendered as &lt;strong&gt; when active or &lt;a&gt; otherwise.') ?>
            <?= $ltok('members_template', '{if_sort_date}{/if_sort_date}',       '{if_sort_date}...{/if_sort_date}',       'Shows only when the list is currently sorted by Date Joined. Works inside or outside {each_member} — use it to hide the join date when sorting by Name or Country instead.') ?>
            <?= $ltok('members_template', '{if_no_sort_date}{/if_no_sort_date}', '{if_no_sort_date}...{/if_no_sort_date}', 'Shows only when the list is NOT currently sorted by Date Joined.') ?>
            <?= $ltok('members_template', '{if_show_search}{/if_show_search}',       '{if_show_search}...{/if_show_search}',       'Shows only when sort/filter controls are enabled for this embed. Place outside {each_member}.') ?>
            <?= $ltok('members_template', '{if_no_show_search}{/if_no_show_search}', '{if_no_show_search}...{/if_no_show_search}', 'Shows only when sort/filter controls are hidden for this embed. Place outside {each_member}.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Join Form -->
      <details class="card chevron" data-token-card data-tpl-group="join-form" <?= $_grp('join-form') ?>>
        <summary class="chevron-head">Join Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Arrange and label every field of the join form, and write any introductory text directly into the template. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
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
        <summary class="chevron-head">Update Membership Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Controls only the sign-in step (email + password). The step where a member actually edits or deletes their membership is fixed and cannot be customized, so a mistake here can never lock anyone out of updating their info.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('update_form_template', '{field_signin_email}',    '{field_signin_email}',    'The Email field, with label and wrapper.') ?>
            <?= $ltok('update_form_template', '{field_signin_password}', '{field_signin_password}', 'The Password field, with label and wrapper.') ?>
            <?= $ltok('update_form_template', '{lost_password_url}',     '{lost_password_url}',     'URL of the password reset page. Use for a "Forgot your password?" link.') ?>
            <?= $ltok('update_form_template', '{submit_button}',    '{submit_button}',    'The submit button.') ?>
            <?= $ltok('update_form_template', '{clear_button}',     '{clear_button}',     'The "Clear Form" reset button.') ?>
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
          <p class="form-hint form-hint--spaced" data-token-search-help>Controls only the email-entry step. The step where a member actually sets their new password is fixed and cannot be customized, so a mistake here can never lock anyone out of resetting their password.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('reset_form_template', '{field_email}',   '{field_email}',   'The Email field, with label and wrapper.') ?>
            <?= $ltok('reset_form_template', '{submit_button}', '{submit_button}', 'The submit button.') ?>
            <?= $ltok('reset_form_template', '{clear_button}',  '{clear_button}',  'The "Clear Form" reset button.') ?>
            <?= $ltok('reset_form_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $ltok('reset_form_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $ltok('reset_form_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Statistics -->
      <details class="card chevron" data-token-card data-tpl-group="statistics" <?= $_grp('statistics') ?>>
        <summary class="chevron-head">Statistics Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>These tokens fill in the public stats block for this listing.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('stats_template', '{stat_opened}',       '{stat_opened}',       'The date you opened the listing.') ?>
            <?= $ltok('stats_template', '{stat_updated}',      '{stat_updated}',      'The most recent activity date: whichever is more recent of the newest approved member\'s join date and the Last Updated date you set. Shows &ldquo;N/A&rdquo; if neither is set.') ?>
            <?= $ltok('stats_template', '{stat_approved}',     '{stat_approved}',     'How many approved members this listing has.') ?>
            <?= $ltok('stats_template', '{stat_pending}',      '{stat_pending}',      'How many members are waiting for approval.') ?>
            <?= $ltok('stats_template', '{stat_countries}',    '{stat_countries}',    'Number of distinct countries represented among approved members.') ?>
            <?= $ltok('stats_template', '{stat_newmembers}',        '{stat_newmembers}',        'Newest approved members, linked to their websites when available. Count is set in the Statistics template section (per-listing) or Global Templates (global default). In Truncate mode, shows &ldquo;&hellip;and more&rdquo; when there are more total.') ?>
            <?= $ltok('stats_template', '{if_stat_pending}{/if_stat_pending}', '{if_stat_pending}...{/if_stat_pending}', 'Shows only when at least one member is awaiting approval.') ?>
            <?= $ltok('stats_template', '{stat_growth}',            '{stat_growth}',            'Average members per day, with unit (e.g. &ldquo;0.15 members/day&rdquo;).') ?>
            <?= $ltok('stats_template', '{stat_growth_rate}',       '{stat_growth_rate}',       'Numeric growth rate only (e.g. &ldquo;0.15&rdquo;). Use this to build your own formatting.') ?>
            <?= $ltok('stats_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $ltok('stats_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $ltok('stats_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
            <?= $ltok('stats_template', '{approved_by}',       '{approved_by}',       'Network acronym: &ldquo;TFL&rdquo;, &ldquo;TAFL&rdquo;, or empty. Plain text, no link.') ?>
            <?= $ltok('stats_template', '{network_name}',      '{network_name}',      'Full network name: &ldquo;The Fanlistings Network&rdquo;, &ldquo;The Anime Fanlistings Network&rdquo;, or empty.') ?>
            <?= $ltok('stats_template', '{tfl_url}',           '{tfl_url}',           'TFL approval page URL (from &ldquo;Approval Page URL&rdquo; field above when set, otherwise <code>https://thefanlistings.org/</code>). Use inside <code>{if_tfl}...{/if_tfl}</code>.') ?>
            <?= $ltok('stats_template', '{tafl_url}',          '{tafl_url}',          'TAFL approval page URL (from &ldquo;Approval Page URL&rdquo; field above when set, otherwise <code>https://www.animefanlistings.com/</code>). Use inside <code>{if_tafl}...{/if_tafl}</code>.') ?>
            <?= $ltok('stats_template', '{approval_url}',      '{approval_url}',      'The raw approval page URL as saved, or empty when not set. Use this if you want to build a custom link with your own text.') ?>
            <?= $ltok('stats_template', '{if_approved_by}{/if_approved_by}', '{if_approved_by}...{/if_approved_by}', 'Shows only when an approved-by network is saved.') ?>
            <?= $ltok('stats_template', '{if_tfl}{/if_tfl}',             '{if_tfl}...{/if_tfl}',             'Shows only when this listing is TFL-approved.') ?>
            <?= $ltok('stats_template', '{if_tafl}{/if_tafl}',           '{if_tafl}...{/if_tafl}',           'Shows only when this listing is TAFL-approved.') ?>
          </tbody></table>
        </div>
      </details>

      <!-- Codes Display -->
      <details class="card chevron" data-token-card data-tpl-group="codes" <?= $_grp('codes') ?>>
        <summary class="chevron-head">Codes Display Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Use {each_size_group}...{/each_size_group} (with an inner {each_code}...{/each_code}) to group codes by dimensions, or a single {each_code}...{/each_code} loop for a flat gallery.</p>
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
            <?php if ($hasCodesSubmit): ?>
            <?= $ltok('codes_template', '{codes_submit_toggle}', '{codes_submit_toggle}', 'Clickable trigger (link or button) that shows/hides the code donation form. Label and style are configurable above.') ?>
            <?= $ltok('codes_template', '{codes_submit_form}', '{codes_submit_form}', 'The code donation form itself. Place anywhere - it starts hidden and is revealed by clicking {codes_submit_toggle}.') ?>
            <?php endif; ?>
          </tbody></table>
        </div>
      </details>

      <?php if ($hasCodesSubmit): ?>
      <!-- Codes Donation Form -->
      <details class="card chevron" data-token-card data-tpl-group="codes-form" <?= $_grp('codes-form') ?>>
        <summary class="chevron-head">Codes Donation Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Arrange and label every field of the Codes Donation form. Write any introductory text directly into the template. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
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
            <?= $ltok('codes_submit_form_template', '{clear_button}',  '{clear_button}',  'The "Clear Form" reset button.') ?>
          </tbody></table>
        </div>
      </details>
      <?php endif; ?>

      <!-- Affiliates Display -->
      <details class="card chevron" data-token-card data-tpl-group="affiliates" <?= $_grp('affiliates') ?>>
        <summary class="chevron-head">Affiliates Display Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Use {each_affiliate}...{/each_affiliate} to design each row yourself.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('affiliates_section_template', '{each_affiliate}{/each_affiliate}', '{each_affiliate}...{/each_affiliate}', 'Repeats the HTML inside it once for each affiliate.') ?>
            <?= $ltok('affiliates_section_template', '{aff_name}',      '{aff_name}',      'The affiliate site name. Use inside {each_affiliate}.') ?>
            <?= $ltok('affiliates_section_template', '{aff_url}',       '{aff_url}',       'The affiliate site link. Use inside {each_affiliate}.') ?>
            <?= $ltok('affiliates_section_template', '{aff_image_url}', '{aff_image_url}', 'The affiliate image URL. Empty when there is no image. Use inside {each_affiliate}.') ?>
            <?= $ltok('affiliates_section_template', '{affiliates_count}', '{affiliates_count}', 'How many active affiliates this listing has.') ?>
            <?php if ($affiliate_apply_enabled ?? false): ?>
            <?= $ltok('affiliates_section_template', '{affiliate_apply_url}',                '{affiliate_apply_url}',                   'URL of the affiliate application form for this listing.') ?>
            <?= $ltok('affiliates_section_template', '{affiliate_apply_toggle}',              '{affiliate_apply_toggle}',                'Clickable trigger (link or button) that shows/hides the affiliate application form. Label and style are configurable above.') ?>
            <?= $ltok('affiliates_section_template', '{affiliate_apply_form}', '{affiliate_apply_form}', 'The affiliate application form itself. Place anywhere - it starts hidden and is revealed by clicking {affiliate_apply_toggle}.') ?>
            <?php endif; ?>
            <?= $ltok('affiliates_section_template', '{if_affiliates}{/if_affiliates}',       '{if_affiliates}...{/if_affiliates}',       'Shows only when this listing has at least one affiliate.') ?>
            <?= $ltok('affiliates_section_template', '{if_no_affiliates}{/if_no_affiliates}', '{if_no_affiliates}...{/if_no_affiliates}', 'Shows only when this listing has no affiliates.') ?>
            <?= $ltok('affiliates_section_template', '{if_aff_image}{/if_aff_image}',       '{if_aff_image}...{/if_aff_image}',       'Inside {each_affiliate}, shows only when the affiliate has an image.') ?>
            <?= $ltok('affiliates_section_template', '{if_no_aff_image}{/if_no_aff_image}', '{if_no_aff_image}...{/if_no_aff_image}', 'Inside {each_affiliate}, shows only when the affiliate has no image.') ?>
          </tbody></table>
        </div>
      </details>

      <?php if ($affiliate_apply_enabled ?? false): ?>
      <!-- Affiliate Application Form -->
      <details class="card chevron" data-token-card data-tpl-group="affiliate-form" <?= $_grp('affiliate-form') ?>>
        <summary class="chevron-head">Affiliate Application Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Arrange and label every field of the affiliate application form. Write any introductory text directly into the template. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
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
            <?= $ltok('affiliate_apply_form_template', '{clear_button}',  '{clear_button}',  'The "Clear Form" reset button.') ?>
          </tbody></table>
        </div>
      </details>
      <?php endif; ?>

      <!-- Contact Form -->
      <details class="card chevron" data-token-card data-tpl-group="contact-form" <?= $_grp('contact-form') ?>>
        <summary class="chevron-head">Contact Form Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Arrange and label every field of the contact form. Each field token has a "_input" version that prints just the bare input (no label/wrapper) for custom layouts.</p>
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
            <?= $ltok('contact_form_template', '{clear_button}',          '{clear_button}',          'The "Clear Form" reset button.') ?>
          </tbody></table>
        </div>
      </details>

      <?php if ($hasNewsfeedAddon): ?>
      <!-- Newsfeed -->
      <details class="card chevron" data-token-card data-tpl-group="newsfeed" <?= $_grp('newsfeed') ?>>
        <summary class="chevron-head">News Feed Tokens</summary>
        <div class="chevron-body">
          <p class="form-hint form-hint--spaced" data-token-search-help>Wrap each entry in {each_newsfeed_entry}...{/each_newsfeed_entry}. Luster repeats it once per news feed entry.</p>
          <table class="et-token-table et-token-table--compact"><tbody>
            <?= $ltok('newsfeed_template', '{listing_title}',   '{listing_title}',   'The listing\'s display name.') ?>
            <?= $ltok('newsfeed_template', '{listing_subject}', '{listing_subject}', 'What the listing is about (the subject/topic, e.g. "Archaeology").') ?>
            <?= $ltok('newsfeed_template', '{listing_url}',     '{listing_url}',     'The link to the listing.') ?>
            <?= $ltok('newsfeed_template', '{if_newsfeed}{/if_newsfeed}',       '{if_newsfeed}...{/if_newsfeed}',       'Shows only when there is at least one entry on the current page.') ?>
            <?= $ltok('newsfeed_template', '{if_no_newsfeed}{/if_no_newsfeed}', '{if_no_newsfeed}...{/if_no_newsfeed}', 'Shows when the current page has no entries (use for an empty state message).') ?>
            <?= $ltok('newsfeed_template', '{each_newsfeed_entry}{/each_newsfeed_entry}', '{each_newsfeed_entry}...{/each_newsfeed_entry}', 'Repeats the HTML inside it once for each entry shown.') ?>
            <?= $ltok('newsfeed_template', '{newsfeed_title}',      '{newsfeed_title}',      'The entry title.') ?>
            <?= $ltok('newsfeed_template', '{newsfeed_date}',       '{newsfeed_date}',       'The posted date in human-readable format, e.g. June 1, 2025.') ?>
            <?= $ltok('newsfeed_template', '{newsfeed_author}',     '{newsfeed_author}',     'The author name. Empty when not set.') ?>
            <?= $ltok('newsfeed_template', '{newsfeed_categories}', '{newsfeed_categories}', 'Category names, comma-separated. Empty when not set.') ?>
            <?= $ltok('newsfeed_template', '{newsfeed_body_html}',  '{newsfeed_body_html}',  'The entry body text, with line breaks converted to HTML.') ?>
            <?= $ltok('newsfeed_template', '{if_author}{/if_author}',         '{if_author}...{/if_author}',         'Shows only when the entry has an author set.') ?>
            <?= $ltok('newsfeed_template', '{if_categories}{/if_categories}', '{if_categories}...{/if_categories}', 'Shows only when the entry has categories set.') ?>
          </tbody></table>
        </div>
      </details>
      <?php endif; ?>

    </div><!-- /.composer-side -->
  </div><!-- /.composer-grid -->
</form>

</div><!-- /#tab-templates -->

<script type="application/json" id="luster-coltpl-defaults">
<?= json_encode([
    'members_template'              => $_gFallback(ListingSettingsRepo::defaultMembersTemplate(),              'listing_default_members_template'),
    'members_template__list'        => ListingSettingsRepo::defaultMembersTemplate(),
    'members_template__table'       => ListingSettingsRepo::defaultMembersTableTemplate(),
    'affiliates_section_template'   => $_gFallback(ListingSettingsRepo::defaultAffiliatesSectionTemplate(),   'listing_default_affiliates_section_template'),
    'stats_template'                => $_gFallback(ListingSettingsRepo::defaultStatsTemplate(),                'listing_default_stats_template'),
    'newsfeed_template'             => $_gFallback(ListingSettingsRepo::defaultNewsfeedTemplate(),              'listing_default_newsfeed_template'),
    'codes_submit_form_template'    => $_gFallback(ListingSettingsRepo::defaultCodesSubmitFormTemplate(),      'listing_default_codes_submit_form_template'),
    'join_form_template'            => $_gFallback(ListingSettingsRepo::defaultJoinFormTemplate(),             'listing_default_join_form_template'),
    'update_form_template'          => $_gFallback(ListingSettingsRepo::defaultUpdateFormTemplate(),           'listing_default_update_form_template'),
    'reset_form_template'           => $_gFallback(ListingSettingsRepo::defaultResetFormTemplate(),            'listing_default_reset_form_template'),
    'affiliate_apply_form_template' => $_gFallback(ListingSettingsRepo::defaultAffiliateApplyFormTemplate(),  'listing_default_affiliate_apply_form_template'),
    'codes_template'                => $_gFallback(ListingSettingsRepo::defaultCodesTemplate(),               'listing_default_codes_template'),
    'contact_form_template'         => $_gFallback(ListingSettingsRepo::defaultContactFormTemplate(),         'listing_default_contact_form_template'),
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
      <iframe id="coltpl-prev-iframe" title="Template preview" sandbox="allow-scripts"></iframe>
    </div>
  </div>
</div>

<div id="tpl-hist-modal" class="modal-backdrop is-hidden" role="dialog" aria-modal="true" aria-label="Revision history">
  <div class="modal modal--wide">
    <div class="modal-header">
      <span id="tpl-hist-title" class="modal-title">Revision History</span>
      <div class="modal-header-actions">
        <button type="button" id="tpl-hist-close" class="btn btn-tertiary btn-sm" aria-label="Close">&times;&ensp;Close</button>
      </div>
    </div>
    <div class="modal-body" id="tpl-hist-body"></div>
    <div class="modal-footer">
      <button type="button" id="tpl-hist-flush" class="btn btn-danger btn-sm">Flush History</button>
      <button type="button" id="tpl-hist-cancel" class="btn btn-ghost btn-sm">Close</button>
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
</div>

<?php endif; ?>
<!-- ──── TAB: ADDITIONAL FIELDS ─────────────────────────────────────── -->
<div id="tab-fields" class="tabs-panel">

  <?php if ($listing->dbprefix === ''): ?>
  <div class="card">
    <div class="card-body"><p>Additional fields can be added after Luster creates the member storage for this listing.</p></div>
  </div>
  <?php else: ?>

  <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/fields/add" id="fields-add-form" class="js-warn-unsaved">
    <input type="hidden" name="_csrf"     value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="from_edit" value="1">
    <div class="card card--gradient">
      <div class="panel-header">
        <div class="panel-header-icon"><?= View::icon('form') ?></div>
        <div class="panel-header-meta">
          <div class="panel-header-title">Add New Field</div>
          <div class="panel-header-sub">Create an extra member field for this listing.</div>
        </div>
      </div>
      <div class="card-body">
        <div class="field-row field-row--top">
          <label class="field-label" for="af-name">Field Name <span class="req">*</span></label>
          <div>
            <input type="text" class="form" id="af-name" name="name"
                   maxlength="30" pattern="[a-zA-Z0-9_]+"
                   placeholder="e.g. fave_song" required
                   title="Letters, numbers and underscores only">
            <p class="form-hint">Use letters, numbers, and underscores only. <strong>No spaces - use underscores instead</strong> (e.g. "fave movie" becomes <code>fav_movie</code>). Each additional field <em>for this listing</em> must have a unique field name. Members will not see this.</p>
          </div>
        </div>
        <div class="field-row field-row--top">
          <label class="field-label" for="af-label">Label <span class="req">*</span></label>
          <div>
            <input type="text" class="form" id="af-label" name="label"
                   maxlength="100" placeholder="e.g. Favourite Song" required>
            <p class="form-hint">The label members will see on your join form and members pages.</p>
          </div>
        </div>
        <div class="field-row field-row--top">
          <label class="field-label" for="af-type">Type</label>
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
        <div id="af-options-row" class="field-row field-row--top is-hidden">
          <label class="field-label" for="af-options">Options</label>
          <div>
            <textarea class="form" id="af-options" name="options" rows="2"
                      placeholder="e.g. Option A, Option B, Option C"></textarea>
            <p class="form-hint">For dropdown, checkbox, and radio fields, write the choices separated by commas.</p>
          </div>
        </div>
        <div class="field-row field-row--top">
          <span class="field-label">Required?</span>
          <div>
            <div class="flex-row">
              <label class="form-check">
                <input type="radio" name="required" value="1">
                <span class="toggle-label">Yes</span>
              </label>
              <label class="form-check">
                <input type="radio" name="required" value="0" checked>
                <span class="toggle-label">No</span>
              </label>
            </div>
            <p class="form-hint">Choose Yes when members must answer this before they can submit the form.</p>
          </div>
        </div>
      </div>
      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Add Field</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear Form</button>
      </div>
    </div>
  </form>

  <div class="card">
    <div class="card-head">Current Fields</div>
    <?php if (empty($fields)): ?>
      <div class="empty">
        <span class="empty-icon"><?= View::icon('form', ['width' => 32, 'height' => 32]) ?></span>
        No additional fields defined yet. Add one above!
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
              <td class="actions-col">
                <div class="actions-row">
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

<details class="card chevron is-hidden" id="settingsBand">
  <summary class="card-head">Code Categories & Donors</summary>

  <div class="settings-band-grid">
    <div class="settings-band-col">
      <div class="section-label">Categories</div>
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
              <td class="actions-col">
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
      <div class="section-label codes-section-label">Donors</div>
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
              <td class="actions-col">
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
<!-- ──── TAB: FORM URLS ─────────────────────────────────────────────── -->
<div id="tab-embed" class="tabs-panel">
<?php
/** @var array  $embed_settings */
/** @var array  $cfg */
/** @var array  $global_form_urls */
$embedSettings  = $embed_settings ?? [];
$_gfUrls        = $global_form_urls ?? [];
$installPath    = rtrim((string) ($cfg['install_path']   ?? ''), '/');
$embedBaseUrl   = rtrim((string) ($cfg['embed_base_url'] ?? ''), '/') ?: rtrim((string) ($cfg['site_url'] ?? ''), '/');
?>

<div>

<div class="card card--gradient">
  <div class="panel-header">
    <div class="panel-header-icon"><?= View::icon('embed') ?></div>
    <div class="panel-header-meta">
      <div class="panel-header-title">Embed Form URLs</div>
      <div class="panel-header-sub">Tell Luster where your host pages are so it can build email links and redirects.</div>
    </div>
  </div>
  
    <div class="card-body">
    <form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/embed" class="js-warn-unsaved">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

      <div class="field-row">
        <label class="field-label" for="fl-form-join">Join Form</label>
        <div>
          <input id="fl-form-join" type="text" name="form_url_join" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_join']) ?>"
                 placeholder="<?= View::e(($_gfUrls['global_form_url_join'] ?? '') ?: 'join.php') ?>">
          <p class="form-hint">The public page on your site where visitors can join this listing. Leave blank to use the default.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="fl-form-update">Update Form</label>
        <div>
          <input id="fl-form-update" type="text" name="form_url_update" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_update']) ?>"
                 placeholder="<?= View::e(($_gfUrls['global_form_url_update'] ?? '') ?: 'update.php') ?>">
          <p class="form-hint">The public page where members can update their information, or permanently delete their membership. Leave blank to use the default.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="fl-form-reset">Lost Password Form</label>
        <div>
          <input id="fl-form-reset" type="text" name="form_url_reset" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_reset']) ?>"
                 placeholder="<?= View::e(($_gfUrls['global_form_url_reset'] ?? '') ?: 'reset.php') ?>">
          <p class="form-hint">The page on your site where members can reset their password. Luster links to this page in reset emails. Leave blank to use the default.</p>
        </div>
      </div>

      <div class="field-row" id="fl-codes-submit-embed-row"
           <?= ($embedSettings['codes_submit_enabled'] ?? true) ? '' : 'style="display:none"' ?>>
        <label class="field-label" for="fl-form-codes-submit">Codes Donation Form</label>
        <div>
          <input id="fl-form-codes-submit" type="text" name="form_url_codes_submit" class="form"
                 maxlength="200"
                 value="<?= View::e($embedSettings['form_url_codes_submit']) ?>"
                 placeholder="<?= View::e(($_gfUrls['global_form_url_codes_submit'] ?? '') ?: 'codes-submit.php') ?>">
          <p class="form-hint">The public page where visitors can donate codes for this listing. Leave blank to use the default.</p>
        </div>
      </div>

      <div class="field-row" id="fl-aff-form-url-row"
           <?= ($affiliate_apply_enabled ?? false) ? '' : 'style="display:none"' ?>>
        <label class="field-label" for="fl-form-aff-apply">Affiliate Application</label>
        <div>
          <input id="fl-form-aff-apply" type="text" name="form_url_affiliate_apply" class="form"
                 maxlength="200"
                 value="<?= View::e($form_url_affiliate_apply ?? '') ?>"
                 placeholder="<?= View::e(($_gfUrls['global_form_url_affiliate_apply'] ?? '') ?: 'affiliates.php') ?>">
          <p class="form-hint">The public page where other sites can apply to affiliate with this listing. Leave blank to use the default.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="fl-form-contact">Contact Form</label>
        <div>
          <input id="fl-form-contact" type="text" name="form_url_contact" class="form"
                 maxlength="200"
                 value="<?= View::e($form_url_contact ?? '') ?>"
                 placeholder="<?= View::e(($_gfUrls['global_form_url_contact'] ?? '') ?: 'contact.php') ?>">
          <p class="form-hint">The public page where visitors can send you a message about this listing. Leave blank to use the default.</p>
        </div>
      </div>
    </div>

  <div class="composer-footer">
    <button type="submit" class="btn btn-primary">Save Form URLs</button>
  </div>
  </form>
  </div>


</div>
</div><!-- /#tab-embed -->

<!-- ──── TAB: CODE SNIPPETS ────────────────────────────────────────── -->
<div id="tab-snippets" class="tabs-panel">

<div class="card card--gradient">
  <div class="panel-header">
    <div class="panel-header-icon"><?= View::icon('embed') ?></div>
    <div class="panel-header-meta">
      <div class="panel-header-title">Code Snippets</div>
      <div class="panel-header-sub">Ready-to-use PHP include snippets for your site pages.</div>
    </div>
  </div>
  <div class="card-body">
    <?php if ($installPath !== ''): ?>
      <?php
        $_es = $embedSettings ?? [];
        $_snipPathRaw = $installPath;
        $_snipPath    = View::e($installPath);
        $_snipId      = (int) $listing->id;
        // Helper to render one snippet row
        $snip = static function (string $label, string $file) use ($_snipPathRaw, $_snipPath, $_snipId, $b): void {
            $plain = "<?php\n\$luster_fanlisting_id = {$_snipId};\nrequire '{$_snipPathRaw}/public/embed/{$file}';\n?>";
            echo '<div class="field-row field-row--top">';
            echo '<span class="field-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
            echo '<div class="embed-snippet-wrap">';
            echo '<div class="readonly-field embed-snippet">';
            echo '&lt;?php<br>';
            echo '$luster_fanlisting_id = ' . $_snipId . ';<br>';
            echo "require '" . $_snipPath . '/public/embed/' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . "';";
            echo '<br>?&gt;';
            echo '</div>';
            echo '<button type="button" class="btn btn-tertiary btn-xs embed-copy-btn" data-copy="' . htmlspecialchars($plain, ENT_QUOTES, 'UTF-8') . '">Copy</button>';
            echo '</div></div>';
        };
      ?>

      <?php $snip('Members List', 'members.php') ?>
      <?php if (($rules_addon_installed ?? false) && ($rules_enabled_for_listing ?? false)): ?>
        <?php $snip('Rules', 'rules-gate.php') ?>
      <?php endif; ?>
      <?php $snip('Join Form', 'join.php') ?>
      <?php $snip('Update Form', 'update.php') ?>
      <?php $snip('Password Reset Form', 'reset.php') ?>
      <?php $snip('Listing Statistics', 'stats.php') ?>
      <?php $snip('Codes Display', 'codes.php') ?>
      <?php $snip('Codes Donation Form', 'codes-submit.php') ?>
      <?php $snip('Affiliates Section', 'affiliates.php') ?>
      <?php if ($affiliate_apply_enabled ?? false): ?>
        <?php $snip('Affiliate Application Form', 'affiliate-apply.php') ?>
      <?php endif; ?>
      <?php if ($contact_enabled ?? false): ?>
        <?php $snip('Contact Form', 'contact.php') ?>
      <?php endif; ?>
      <?php if (in_array('Newsfeed', $nav_installed_addons ?? [], true)): ?>
        <?php $snip('News Feed', 'newsfeed.php') ?>
      <?php endif; ?>


    <?php else: ?>
      <p class="form-hint">
        Set the <strong>Installation Path</strong> in <code>config/app.php</code> (<code>install_path</code> key)
        to show ready-to-use snippets here.
      </p>
    <?php endif; ?>
  </div>
</div>

</div><!-- /#tab-snippets -->


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
      <div class="card-body">
        <div class="field-row field-row--top">
          <label class="field-label" for="adv-db-host">DB Host</label>
          <div>
            <input id="adv-db-host" type="text" name="db_host" class="form"
                   maxlength="255" placeholder="localhost"
                   value="<?= View::e($advEmbedSettings['db_host'] ?? '') ?>">
            <p class="form-hint">The database server name, usually <code>localhost</code>.</p>
          </div>
        </div>
        <div class="field-row field-row--top">
          <label class="field-label" for="adv-db-name">DB Name</label>
          <div>
            <input id="adv-db-name" type="text" name="db_name" class="form"
                   maxlength="255" placeholder="my_database"
                   value="<?= View::e($advEmbedSettings['db_name'] ?? '') ?>">
            <p class="form-hint">The database name that contains this listing's member records.</p>
          </div>
        </div>
        <div class="field-row field-row--top">
          <label class="field-label" for="adv-db-user">DB User</label>
          <div>
            <input id="adv-db-user" type="text" name="db_user" class="form"
                   maxlength="255" autocomplete="off"
                   value="<?= View::e($advEmbedSettings['db_user'] ?? '') ?>">
            <p class="form-hint">The username Luster should use for that separate database.</p>
          </div>
        </div>
        <div class="field-row field-row--top">
          <label class="field-label" for="adv-db-pass">DB Password</label>
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
        <button type="reset" class="btn btn-quaternary">Reset to Default</button>
      </div>
    </form>
  </details>

</div>
