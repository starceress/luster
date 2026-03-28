<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var \Luster\Models\Listing $listing
 * @var object[]               $codes            Codes for display (may be status-filtered)
 * @var object[]               $categories       Category groups (CodeCategoriesRepo result objects)
 * @var object[]               $donors           Donors (CodeDonorsRepo result objects)
 * @var array                  $grouped          ['WxH' => ['label' => string, 'codes' => object[]]]
 * @var array<int,int[]>       $code_categories  code_id → [category_id, ...]
 * @var array<int,object>      $category_map     category_id → category object
 * @var array<int,object>      $donor_map        donor_id → donor object
 * @var int                    $edit_cat_id      Which category row to show in edit mode (0 = none)
 * @var int                    $edit_donor_id    Which donor row to show in edit mode (0 = none)
 * @var string                 $status_filter    Active status filter ('' = all)
 * @var array<string,int>      $status_counts    Per-status totals for all codes in listing
 * @var int                    $total_codes      Total codes regardless of filter
 * @var string                 $uploads_url      Base URL for /uploads/codes (no trailing slash)
 * @var string                 $success
 * @var string                 $error
 * @var string                 $base_url
 * @var string                 $csrf_token
 */

$b            = rtrim(View::e($base_url), '/');
$fid          = (int) $listing->id;
$hasCats      = !empty($categories);
$hasDonors    = !empty($donors);
$pendingCount = $status_counts['pending'] ?? 0;
$approvedCount = $status_counts['approved'] ?? 0;

// Non-empty groups for count display
$nonEmptyGroups = array_filter($grouped, fn($g) => !empty($g['codes']));
$groupCount = count($nonEmptyGroups);

// Separate top-level categories from children for the add-category parent dropdown
$topLevelCats = array_filter($categories, fn($c) => $c->parentId === null);

// Settings panel is open if any cats/donors already exist
$settingsOpen = $hasCats || $hasDonors;

// Summary badge for Code Settings
if ($hasCats || $hasDonors) {
    $_settingsSummary = count($categories) . ' categor' . (count($categories) !== 1 ? 'ies' : 'y')
        . ' &middot; ' . count($donors) . ' donor' . (count($donors) !== 1 ? 's' : '');
} else {
    $_settingsSummary = 'categories &amp; donors';
}
?>

<!-- ── Page topbar ──────────────────────────────────────────────────────── -->
<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings/<?= $fid ?>/edit" class="back-link">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    Back to <?= View::e($listing->name) ?>
  </a>
  <div class="topbar-actions">
    <span class="badge badge-muted"><?= $total_codes ?> code<?= $total_codes !== 1 ? 's' : '' ?></span>
    <?php if ($approvedCount > 0): ?>
    <a href="<?= $b ?>/admin/listings/<?= $fid ?>/codes/export" class="btn btn-quaternary btn-sm">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
      Export ZIP
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ── Pending codes banner ─────────────────────────────────────────────── -->
<?php if ($pendingCount > 0 && $status_filter !== 'pending'): ?>
<div class="pending-banner" style="margin-bottom:1.25rem">
  <div class="pending-banner-left">
    <span class="pending-banner-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="20" height="20">
        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
    </span>
    <div>
      <span class="pending-banner-title">Codes awaiting review</span>
      <span class="pending-banner-msg"><?= $pendingCount ?> code<?= $pendingCount !== 1 ? 's are' : ' is' ?> pending approval</span>
    </div>
  </div>
  <a href="?status_filter=pending" class="btn btn-primary btn-sm">Review pending</a>
</div>
<?php endif; ?>

<!-- ── Code Settings collapsible band ──────────────────────────────────── -->
<details class="card codes-settings-band" <?= $settingsOpen ? 'open' : '' ?> id="settingsBand">
  <summary>
    <div class="card-head-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </div>
    <span class="card-head-title">Code Settings</span>
    <span class="badge badge-muted" style="font-size:.58rem"><?= $_settingsSummary ?></span>
  </summary>

  <div class="settings-band-grid">

    <!-- ── Categories ── -->
    <div class="settings-band-col">
      <div class="pc-section-label" style="margin-bottom:.875rem">Categories</div>

      <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/categories/add"
            style="display:flex;gap:.5rem;align-items:flex-end;margin-bottom:.875rem;flex-wrap:wrap">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <div style="flex:1;min-width:120px">
          <input class="form-input" type="text" name="name" placeholder="Category name&hellip;" required>
        </div>
        <?php if (!empty($topLevelCats)): ?>
        <div class="toolbar-select-wrap" style="min-width:130px">
          <select name="parent_id" class="select">
            <option value="">None (top-level)</option>
            <?php foreach ($topLevelCats as $tlc): ?>
              <option value="<?= (int)$tlc->id ?>"><?= View::e($tlc->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-quaternary btn-sm" style="flex-shrink:0">+ Add</button>
      </form>

      <?php if ($hasCats): ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Amount</th>
              <th>Parent</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $cat): ?>
            <?php $isCatEditing = $edit_cat_id === $cat->id; ?>
            <tr <?= $isCatEditing ? 'style="display:none"' : '' ?>>
              <td class="text-strong"><?= View::e($cat->name) ?></td>
              <td>
                <?php
                  $catCodeCount = count(array_filter($code_categories, fn($ids) => in_array($cat->id, $ids, true)));
                ?>
                <span class="badge badge-info"><?= $catCodeCount ?> code<?= $catCodeCount !== 1 ? 's' : '' ?></span>
              </td>
              <td>
                <?php if ($cat->parentId !== null && isset($category_map[$cat->parentId])): ?>
                  <span class="text-dim"><?= View::e($category_map[$cat->parentId]->name) ?></span>
                <?php else: ?>
                  <span class="text-dim">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="act-col">
                <a href="?edit_cat=<?= (int)$cat->id ?>" class="btn btn-tertiary btn-sm">Edit</a>
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/categories/<?= (int)$cat->id ?>/delete"
                      style="display:contents"
                      data-del-modal data-del-entity="code_category"
                      data-del-name="<?= View::e($cat->name) ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <button class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <tr <?= !$isCatEditing ? 'style="display:none"' : '' ?>>
              <td colspan="4">
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/categories/<?= (int)$cat->id ?>/edit"
                      style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <div style="flex:1;min-width:100px">
                    <input class="form-input" type="text" name="name" value="<?= View::e($cat->name) ?>" required>
                  </div>
                  <div>
                    <div class="toolbar-select-wrap">
                      <select name="parent_id" class="select" style="font-size:.8rem">
                        <option value="">None (top-level)</option>
                        <?php foreach ($categories as $pcat): ?>
                          <?php if ($pcat->id === $cat->id || $pcat->parentId !== null) continue; ?>
                          <option value="<?= (int)$pcat->id ?>" <?= $cat->parentId === $pcat->id ? 'selected' : '' ?>>
                            <?= View::e($pcat->name) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  <button class="btn btn-primary btn-sm">Save</button>
                  <a href="?" class="btn btn-quaternary btn-sm">Cancel</a>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <p style="font-size:.82rem;color:var(--text-dim)">No categories yet. Use these to tag codes by theme.</p>
      <?php endif; ?>
    </div>

    <!-- ── Donors ── -->
    <div class="settings-band-col">
      <div class="pc-section-label" style="margin-bottom:.875rem">Donors</div>

      <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/donors/add"
            style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.5rem;align-items:end;margin-bottom:.875rem">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <input type="text"  class="form-input" name="name"  placeholder="Display name&hellip;" required>
        <input type="email" class="form-input" name="email" placeholder="Email (optional)">
        <input type="url"   class="form-input" name="url"   placeholder="URL (optional)">
        <button type="submit" class="btn btn-quaternary btn-sm" style="white-space:nowrap">+ Add</button>
      </form>

      <?php if ($hasDonors): ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>URL</th>
              <th># Donated</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($donors as $dn): ?>
            <?php $isDnEditing = $edit_donor_id === $dn->id; ?>
            <tr <?= $isDnEditing ? 'style="display:none"' : '' ?>>
              <td class="text-strong"><?= View::e($dn->name) ?></td>
              <td class="text-dim"><?= $dn->email !== null ? View::e($dn->email) : '&mdash;' ?></td>
              <td class="text-dim">
                <?php if ($dn->url !== null): ?>
                  <?php $_dnHost = parse_url($dn->url, PHP_URL_HOST) ?? $dn->url; ?>
                  <a href="<?= View::e($dn->url) ?>" target="_blank" rel="noopener noreferrer"
                     title="<?= View::e($dn->url) ?>" class="link"><?= View::e($_dnHost) ?></a>
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>
              <td>
                <span class="badge badge-info"><?= count(array_filter($codes, fn($c) => $c->donorId === $dn->id)) ?></span>
              </td>
              <td class="act-col">
                <a href="?edit_donor=<?= (int)$dn->id ?>" class="btn btn-tertiary btn-sm">Edit</a>
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/donors/<?= (int)$dn->id ?>/delete"
                      style="display:contents"
                      data-del-modal data-del-entity="code_donor"
                      data-del-name="<?= View::e($dn->name) ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <button class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
            <tr <?= !$isDnEditing ? 'style="display:none"' : '' ?>>
              <td colspan="5" style="padding:.6rem .75rem;background:var(--bg-subtle)">
                <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/donors/<?= (int)$dn->id ?>/edit"
                      style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <div style="flex:1;min-width:100px">
                    <input class="form-input" type="text" name="name" value="<?= View::e($dn->name) ?>" required>
                  </div>
                  <div style="flex:1;min-width:100px">
                    <input class="form-input" type="email" name="email" value="<?= View::e($dn->email ?? '') ?>" placeholder="Email">
                  </div>
                  <div style="flex:1;min-width:100px">
                    <input class="form-input" type="url" name="url" value="<?= View::e($dn->url ?? '') ?>" placeholder="URL">
                  </div>
                  <button class="btn btn-primary btn-sm">Save</button>
                  <a href="?" class="btn btn-quaternary btn-sm">Cancel</a>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <p style="font-size:.82rem;color:var(--text-dim)">No donors yet. Credit the people who made codes for this listing.</p>
      <?php endif; ?>
    </div>

  </div><!-- /.settings-band-grid -->
</details>

<!-- ── Out-of-flow forms & dialogs ─────────────────────────────────────── -->

<!-- Bulk form -->
<form id="codes-bulk-form" method="POST"
      action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/bulk">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
</form>

<!-- Reorder form -->
<form id="codes-reorder-form" method="POST"
      action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/reorder">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  <input type="hidden" id="reorder-ids" name="ordered_ids" value="">
</form>

<!-- Per-code delete forms + edit dialogs -->
<?php foreach ($codes as $code): ?>
<?php
  $dDonor = ($code->donorId !== null && isset($donor_map[$code->donorId])) ? $donor_map[$code->donorId] : null;
  $dLabel = ($code->name !== null && $code->name !== '') ? $code->name : null;
?>
<form id="del-<?= (int)$code->id ?>" method="POST"
      action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/<?= (int)$code->id ?>/delete"
      data-del-modal data-del-entity="code"
      data-del-name="<?= View::e($dLabel !== null ? $dLabel : 'Code #' . (int)$code->id) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
</form>

<dialog id="edit-dialog-<?= (int)$code->id ?>" class="code-edit-dialog">
  <div class="code-edit-dialog-header">
    <span class="code-edit-dialog-title">
      Edit code<?= $dLabel !== null ? ' &mdash; ' . View::e($dLabel) : ' #' . (int)$code->id ?>
    </span>
    <button type="button" class="code-edit-dialog-close" data-close-dialog aria-label="Close">&times;</button>
  </div>
  <div class="code-edit-dialog-body">
    <div style="text-align:center;margin-bottom:.875rem">
      <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
           alt="Code #<?= (int)$code->id ?>"
           style="max-width:100%;max-height:120px;object-fit:contain;border-radius:6px">
      <?php if ($code->width > 0 && $code->height > 0): ?>
      <div style="font-size:.7rem;color:var(--text-dim);margin-top:.3rem">
        <?= (int)$code->width ?>&times;<?= (int)$code->height ?>px
      </div>
      <?php endif; ?>
    </div>

    <form method="POST"
          action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/<?= (int)$code->id ?>/update"
          style="display:flex;flex-direction:column;gap:.6rem">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

      <div>
        <label class="form-label" style="font-size:.8rem">Label (optional)</label>
        <input class="form-input" type="text" name="name"
               value="<?= View::e($code->name ?? '') ?>"
               placeholder="e.g. Animated version">
      </div>

      <div>
        <label class="form-label" style="font-size:.8rem">Status</label>
        <div class="toolbar-select-wrap">
          <select name="status" class="select" style="width:100%">
            <option value="approved" <?= $code->status === 'approved' ? 'selected' : '' ?>>&#10003; Live</option>
            <option value="pending"  <?= $code->status === 'pending'  ? 'selected' : '' ?>>&#8987; Pending</option>
          </select>
        </div>
      </div>

      <?php if ($hasDonors): ?>
      <div>
        <label class="form-label" style="font-size:.8rem">Donor</label>
        <div class="toolbar-select-wrap">
          <select name="donor_id" class="select" style="width:100%">
            <option value="">No donor</option>
            <?php foreach ($donors as $dn): ?>
              <option value="<?= (int)$dn->id ?>" <?= $code->donorId === $dn->id ? 'selected' : '' ?>>
                <?= View::e($dn->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($hasCats): ?>
      <div>
        <label class="form-label" style="font-size:.8rem">Category</label>
        <div class="toolbar-select-wrap">
          <select name="cat_ids[]" class="select" style="width:100%">
            <option value="">None</option>
            <?php
              $dCatIds = $code_categories[$code->id] ?? [];
              foreach ($categories as $cat):
            ?>
              <option value="<?= (int)$cat->id ?>" <?= in_array($cat->id, $dCatIds, true) ? 'selected' : '' ?>>
                <?= $cat->parentId !== null ? '└ ' : '' ?><?= View::e($cat->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($code->submitterName !== null): ?>
      <div>
        <label class="form-label" style="font-size:.8rem">Submitter website</label>
        <input class="form-input" type="url" name="submitter_url"
               value="<?= View::e($code->submitterUrl ?? '') ?>"
               placeholder="https://example.com">
      </div>
      <?php endif; ?>

      <button class="btn btn-primary" style="width:100%">Save changes</button>
    </form>

    <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border-soft)">
      <button type="submit" form="del-<?= (int)$code->id ?>"
              class="btn btn-danger btn-sm" style="width:100%">
        Delete this code
      </button>
    </div>
  </div>
</dialog>
<?php endforeach; ?>

<!-- ── Split layout ─────────────────────────────────────────────────────── -->
<div class="codes-split">

  <!-- ── Left: Upload panel ── -->
  <div class="codes-panel-col">
    <div class="panel-card">
      <div class="card-head">
        <div class="card-head-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
        </div>
        <span class="card-head-title">Upload Codes</span>
      </div>
      <div class="panel-body">
        <form method="POST"
              action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/upload"
              enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

          <!-- Drop zone -->
          <div class="codes-drop" style="margin-bottom:.875rem">
            <input type="file" id="codes-input" name="codes[]"
                   multiple accept="image/jpeg,image/png,image/gif,image/webp">
            <svg class="codes-drop-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
            <span class="codes-drop-label">Click or drop images</span>
            <span class="codes-drop-hint">JPEG, PNG, GIF, WebP &mdash; max 500 KB each</span>
          </div>

          <!-- Donor + Status -->
          <div class="field-row-2" style="margin-bottom:.625rem">
            <div class="panel-field" style="margin-bottom:0">
              <label class="panel-label" for="upload-donor">Donor</label>
              <div class="select-wrap">
                <select id="upload-donor" name="donor_id" class="panel-select">
                  <option value="">None</option>
                  <?php foreach ($donors as $dn): ?>
                    <option value="<?= (int)$dn->id ?>"><?= View::e($dn->name) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="panel-field" style="margin-bottom:0">
              <label class="panel-label" for="upload-status">Status</label>
              <div class="select-wrap">
                <select id="upload-status" name="upload_status" class="panel-select">
                  <option value="approved">Live</option>
                  <option value="pending">Pending</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Category -->
          <?php if ($hasCats): ?>
          <div class="panel-field">
            <label class="panel-label">Category</label>
            <div class="select-wrap">
              <select name="cat_ids[]" class="panel-select">
                <option value="">None</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat->id ?>">
                    <?= $cat->parentId !== null ? '└ ' : '' ?><?= View::e($cat->name) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary btn-sm" style="width:100%;justify-content:center;margin-top:.25rem">
            Upload
          </button>
          <p class="panel-hint" style="margin-top:.5rem">Dimensions are detected automatically. Attributes above apply to all files in this batch.</p>
        </form>
      </div>
    </div>
  </div><!-- /.codes-panel-col -->

  <!-- ── Right: Gallery ── -->
  <div class="main-card" style="margin-bottom:0">

    <!-- Info row -->
    <div class="codes-info-row">
      <div style="font-size:.825rem;color:var(--text-muted)">
        <strong style="color:var(--text)"><?= $approvedCount ?> approved code<?= $approvedCount !== 1 ? 's' : '' ?></strong>
        <?php if ($groupCount > 0): ?>
          across <?= $groupCount ?> size group<?= $groupCount !== 1 ? 's' : '' ?> &mdash; drag tiles to reorder.
        <?php endif; ?>
      </div>
      <?php if ($total_codes > 0 && $pendingCount === 0): ?>
        <span class="badge badge-current">All Live</span>
      <?php elseif ($pendingCount > 0): ?>
        <span class="badge badge-upcoming"><?= $pendingCount ?> Pending</span>
      <?php endif; ?>
    </div>

    <!-- Status tabs -->
    <div class="codes-tabs">
      <a href="?" class="neb-tab-btn<?= $status_filter === '' ? ' active' : '' ?>">
        All <span class="badge badge-muted" style="font-size:.55rem;padding:1px 6px;vertical-align:middle"><?= $total_codes ?></span>
      </a>
      <a href="?status_filter=pending" class="neb-tab-btn<?= $status_filter === 'pending' ? ' active' : '' ?>">
        Pending <span class="badge badge-<?= $pendingCount > 0 ? 'upcoming' : 'muted' ?>" style="font-size:.55rem;padding:1px 6px;vertical-align:middle"><?= $pendingCount ?></span>
      </a>
    </div>

    <!-- Bulk toolbar -->
    <div class="codes-bulk">
      <label style="display:flex;align-items:center;gap:5px;font-size:.78rem;color:var(--text-dim);cursor:pointer;flex-shrink:0">
        <input type="checkbox" id="codes-select-all" style="accent-color:var(--va-pink);width:13px;height:13px"> Select all
      </label>
      <div class="toolbar-select-wrap">
        <select id="bulk-action-sel" class="select" style="font-size:.78rem;padding:4px 28px 4px 9px">
          <option value="delete">Delete</option>
          <option value="approve">Approve</option>
          <option value="reject">Reject</option>
          <?php if ($hasCats): ?>
            <option value="categorize">Add to categories&hellip;</option>
          <?php endif; ?>
        </select>
      </div>
      <?php if ($hasCats): ?>
      <div id="bulk-cats-wrap" style="display:none">
        <select id="bulk-cats-sel" name="bulk_cat_ids[]" multiple
                size="<?= min(4, count($categories)) ?>" class="form-input"
                style="height:auto;font-size:.8rem;min-height:50px">
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat->id ?>">
              <?= $cat->parentId !== null ? '  └ ' : '' ?><?= View::e($cat->name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <button type="button" id="bulk-apply-btn" class="btn btn-quaternary btn-sm">Apply to selected</button>
      <div class="codes-bulk-right">
        <button type="button" id="save-order-btn" class="btn btn-quaternary btn-sm" style="display:none">Save order</button>
      </div>
    </div>

    <!-- Gallery content -->
    <?php if ($total_codes === 0): ?>

      <div class="card-section" style="text-align:center;padding:2.5rem">
        <div class="empty">
          <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
          No codes yet &mdash; upload images using the panel on the left.
        </div>
      </div>

    <?php elseif (empty($codes)): ?>

      <div class="card-section" style="text-align:center;padding:2.5rem">
        <div class="empty">
          <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          No codes match this filter &mdash; <a href="?" class="link">clear filter</a> to see all <?= $total_codes ?>.
        </div>
      </div>

    <?php else: ?>

      <div class="card-section">
        <?php foreach ($grouped as $group): ?>
          <?php if (empty($group['codes'])) continue; ?>
          <?php
            $firstCode = reset($group['codes']);
            $gridMin   = max((int)$firstCode->width + 32, 80) . 'px';
          ?>

          <div class="dim-head">
            <span class="dim-label"><?= View::e($group['label']) ?></span>
            <span class="dim-count"><?= count($group['codes']) ?> code<?= count($group['codes']) !== 1 ? 's' : '' ?></span>
          </div>

          <div class="code-grid" style="grid-template-columns:repeat(auto-fill,minmax(<?= $gridMin ?>,1fr))">
            <?php foreach ($group['codes'] as $code): ?>
            <?php
              $codeCatIds = $code_categories[$code->id] ?? [];
              $codeDonor  = ($code->donorId !== null && isset($donor_map[$code->donorId])) ? $donor_map[$code->donorId] : null;
              $codeLabel  = ($code->name !== null && $code->name !== '') ? $code->name : null;
              $isDonated  = $codeDonor !== null || $code->submitterName !== null;
            ?>
            <div class="code-tile code-card"
                 data-code-id="<?= (int)$code->id ?>"
                 <?= $isDonated ? 'style="border-color:rgba(249,115,22,.3)"' : '' ?>>

              <input type="checkbox" form="codes-bulk-form" name="selected[]"
                     value="<?= (int)$code->id ?>"
                     class="code-tile-cb code-check">

              <span class="code-drag-handle drag-handle" title="Drag to reorder">&#8942;&#8942;</span>

              <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
                   alt="<?= (int)$code->width ?>&times;<?= (int)$code->height ?>px"
                   style="max-width:100%;object-fit:contain"
                   loading="lazy">

              <?php if ($codeLabel !== null): ?>
              <div style="font-size:.68rem;font-weight:600;color:var(--text);text-align:center;word-break:break-word;line-height:1.3">
                <?= View::e($codeLabel) ?>
              </div>
              <?php endif; ?>

              <?php if ($codeDonor !== null): ?>
              <div class="code-tile-donor">&hearts; <?= View::e($codeDonor->name) ?></div>
              <?php elseif ($code->submitterName !== null): ?>
              <div class="code-tile-donor">&hearts; <?= View::e($code->submitterName) ?></div>
              <?php endif; ?>

              <?php if ($code->status === 'pending'): ?>
              <span class="badge badge-upcoming" style="font-size:.58rem;padding:1px 7px">Pending</span>
              <?php endif; ?>

              <button type="button" class="btn btn-quaternary btn-sm code-tile-btn code-edit-btn"
                      data-dialog="edit-dialog-<?= (int)$code->id ?>">
                &#9881; Edit
              </button>

            </div>
            <?php endforeach; ?>
          </div>

        <?php endforeach; ?>
      </div><!-- /.card-section -->

    <?php endif; ?>

  </div><!-- /.main-card -->

</div><!-- /.codes-split -->
