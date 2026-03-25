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

// Separate top-level categories from children for the add-category parent dropdown
$topLevelCats = array_filter($categories, fn($c) => $c->parentId === null);

// Settings panel is open if any cats/donors already exist
$settingsOpen = $hasCats || $hasDonors;
?>

<!-- ── Header ────────────────────────────────────────────────────────────── -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
  <a href="<?= $b ?>/admin/listings/<?= $fid ?>/edit" class="neb-link">
    &larr; Back to <?= View::e($listing->name) ?>
  </a>
  <div style="display:flex;align-items:center;gap:.75rem">
    <?php if (($status_counts['approved'] ?? 0) > 0): ?>
    <a href="<?= $b ?>/admin/listings/<?= $fid ?>/codes/export"
       class="btn btn-tertiary btn-sm">&#8659; Export ZIP</a>
    <?php endif; ?>
    <span style="font-size:.8rem;color:var(--text-dim)">
      <?= $total_codes ?> code<?= $total_codes !== 1 ? 's' : '' ?>
    </span>
  </div>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ── Pending codes banner ──────────────────────────────────────────────── -->
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

<!-- ── Upload ──────────────────────────────────────────────────────────────── -->
<div class="neb-card" style="padding:1.25rem 1.5rem;margin-bottom:1.25rem">
  <h2 class="card-title" style="margin-bottom:1rem">Upload Codes</h2>
  <form method="POST"
        action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/upload"
        enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">

      <div style="flex:1;min-width:220px">
        <label class="form-label" for="codes-input">
          Images
          <span style="font-weight:400;color:var(--text-dim)">(JPEG, PNG, GIF, WebP — max 500 KB each)</span>
        </label>
        <input class="form-input" type="file" id="codes-input" name="codes[]"
               accept="image/jpeg,image/png,image/gif,image/webp" multiple>
      </div>

      <?php if ($hasDonors): ?>
      <div>
        <label class="form-label" for="upload-donor">Donor</label>
        <div class="toolbar-select-wrap">
          <select id="upload-donor" name="donor_id" class="neb-select">
            <option value="">None</option>
            <?php foreach ($donors as $dn): ?>
              <option value="<?= (int)$dn->id ?>"><?= View::e($dn->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>

      <div>
        <label class="form-label" for="upload-status">Status</label>
        <div class="toolbar-select-wrap">
          <select id="upload-status" name="upload_status" class="neb-select">
            <option value="approved">Live</option>
            <option value="pending">Pending</option>
          </select>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" style="white-space:nowrap">Upload</button>
    </div>

    <?php if ($hasCats): ?>
    <div style="margin-top:.75rem">
      <label class="form-label" style="margin-bottom:.35rem">Categories</label>
      <select name="cat_ids[]" multiple size="<?= min(5, count($categories)) ?>"
              class="form-input" style="height:auto;min-height:60px">
        <?php foreach ($categories as $cat): ?>
          <option value="<?= (int)$cat->id ?>">
            <?= $cat->parentId !== null ? '└ ' : '' ?><?= View::e($cat->name) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="form-hint" style="margin-top:.3rem">Hold Ctrl / Cmd to select multiple.</p>
    </div>
    <?php endif; ?>

    <p class="form-hint" style="margin-top:.5rem">
      Select multiple files at once. Dimensions are detected automatically.
      Attributes above apply to all files in this batch.
    </p>
  </form>
</div>


<!-- ── Code Settings (Categories, Donors) ───────────────────────────────── -->
<details <?= $settingsOpen ? 'open' : '' ?> style="margin-bottom:1.25rem">
  <summary style="cursor:pointer;padding:.6rem .75rem;border-radius:8px;background:var(--bg-card);border:1px solid var(--border);font-size:.85rem;font-weight:600;color:var(--text);list-style:none;display:flex;align-items:center;gap:.5rem;user-select:none">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="15" height="15">
      <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
    </svg>
    Code Settings
    <span style="font-weight:400;color:var(--text-muted);font-size:.78rem">
      <?php if ($hasCats || $hasDonors): ?>
        <?= count($categories) ?> categor<?= count($categories) !== 1 ? 'ies' : 'y' ?>,
        <?= count($donors) ?> donor<?= count($donors) !== 1 ? 's' : '' ?>
      <?php else: ?>
        categories &amp; donors
      <?php endif; ?>
    </span>
    <svg class="code-settings-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14" style="margin-left:auto;transition:transform .2s;flex-shrink:0">
      <polyline points="6 9 12 15 18 9"/>
    </svg>
  </summary>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:1rem">

    <!-- ── Categories ────────────────────────────────────────────────────── -->
    <div class="neb-card">
      <div class="panel-header" style="border-bottom:1px solid var(--border)">
        <div class="panel-title" style="font-size:.875rem">
          <svg class="panel-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          Categories
        </div>
      </div>

      <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border-soft)">
        <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/categories/add"
              style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
          <div style="flex:1;min-width:100px">
            <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">Name</label>
            <input class="form-input" type="text" name="name" placeholder="e.g. Animated" required>
          </div>
          <?php if (!empty($topLevelCats)): ?>
          <div style="min-width:100px">
            <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">Parent</label>
            <div class="toolbar-select-wrap">
              <select name="parent_id" class="neb-select" style="font-size:.8rem">
                <option value="">None (top-level)</option>
                <?php foreach ($topLevelCats as $tlc): ?>
                  <option value="<?= (int)$tlc->id ?>"><?= View::e($tlc->name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:1px;white-space:nowrap">+ Add</button>
        </form>
      </div>

      <?php if ($hasCats): ?>
      <div class="neb-table-wrap">
      <table class="data-table" style="margin:0;font-size:.82rem">
        <tbody>
          <?php foreach ($categories as $cat): ?>
          <?php $isCatEditing = $edit_cat_id === $cat->id; ?>
          <tr <?= $isCatEditing ? 'style="display:none"' : '' ?>>
            <td style="padding:.5rem .75rem">
              <?php if ($cat->parentId !== null): ?>
                <span style="color:var(--text-dim);margin-right:.2rem">└</span>
              <?php endif; ?>
              <span class="text-strong"><?= View::e($cat->name) ?></span>
            </td>
            <td class="text-dim" style="padding:.5rem .25rem;text-align:center">
              <?php
                $catCodeCount = count(array_filter($code_categories, fn($ids) => in_array($cat->id, $ids, true)));
              ?>
              <?= $catCodeCount ?>
            </td>
            <td class="act-col" style="padding:.5rem .75rem">
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
            <td colspan="3" style="padding:.6rem .75rem;background:var(--bg-subtle)">
              <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/categories/<?= (int)$cat->id ?>/edit"
                    style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                <div style="flex:1;min-width:100px">
                  <input class="form-input" type="text" name="name" value="<?= View::e($cat->name) ?>" required>
                </div>
                <div style="min-width:100px">
                  <div class="toolbar-select-wrap">
                    <select name="parent_id" class="neb-select" style="font-size:.8rem">
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
                <a href="?" class="btn btn-tertiary btn-sm">Cancel</a>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?>
      <p style="padding:.75rem 1rem;color:var(--text-dim);font-size:.8rem;margin:0">
        No categories yet. Use these to tag codes by theme.
      </p>
      <?php endif; ?>
    </div>

    <!-- ── Donors ────────────────────────────────────────────────────────── -->
    <div class="neb-card">
      <div class="panel-header" style="border-bottom:1px solid var(--border)">
        <div class="panel-title" style="font-size:.875rem">
          <svg class="panel-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
          Donors
        </div>
      </div>

      <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border-soft)">
        <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/donors/add"
              style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
          <div style="flex:1;min-width:100px">
            <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">Name</label>
            <input class="form-input" type="text" name="name" placeholder="Display name" required>
          </div>
          <div style="flex:1;min-width:100px">
            <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">Email</label>
            <input class="form-input" type="email" name="email" placeholder="optional">
          </div>
          <div style="flex:1;min-width:100px">
            <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">URL</label>
            <input class="form-input" type="url" name="url" placeholder="optional">
          </div>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:1px;white-space:nowrap">+ Add</button>
        </form>
      </div>

      <?php if ($hasDonors): ?>
      <div class="neb-table-wrap">
      <table class="data-table" style="margin:0;font-size:.82rem">
        <tbody>
          <?php foreach ($donors as $dn): ?>
          <?php $isDnEditing = $edit_donor_id === $dn->id; ?>
          <tr <?= $isDnEditing ? 'style="display:none"' : '' ?>>
            <td style="padding:.5rem .75rem">
              <?php if ($dn->url !== null): ?>
                <a href="<?= View::e($dn->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="neb-link text-strong"><?= View::e($dn->name) ?></a>
              <?php else: ?>
                <span class="text-strong"><?= View::e($dn->name) ?></span>
              <?php endif; ?>
            </td>
            <td class="text-dim" style="padding:.5rem .25rem;font-size:.75rem">
              <?= $dn->email !== null ? View::e($dn->email) : '—' ?>
            </td>
            <td class="text-dim" style="padding:.5rem .25rem;text-align:center">
              <?= count(array_filter($codes, fn($c) => $c->donorId === $dn->id)) ?>
            </td>
            <td class="act-col" style="padding:.5rem .75rem">
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
            <td colspan="4" style="padding:.6rem .75rem;background:var(--bg-subtle)">
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
                <a href="?" class="btn btn-tertiary btn-sm">Cancel</a>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?>
      <p style="padding:.75rem 1rem;color:var(--text-dim);font-size:.8rem;margin:0">
        No donors yet. Credit the people who made codes for this listing.
      </p>
      <?php endif; ?>
    </div>

  </div><!-- /settings grid -->
</details>


<!-- ── Status filter tabs ─────────────────────────────────────────────────── -->
<?php
$filterTabs = [
    ''         => 'All (' . $total_codes . ')',
    'approved' => '✓ Live (' . ($status_counts['approved'] ?? 0) . ')',
    'pending'  => '⏳ Pending (' . ($status_counts['pending'] ?? 0) . ')',
    'rejected' => '✗ Rejected (' . ($status_counts['rejected'] ?? 0) . ')',
];
?>
<div class="neb-tabs-wrap" style="margin-bottom:1.25rem">
  <div class="neb-tabs">
    <?php foreach ($filterTabs as $sf => $label): ?>
      <?php $href = $sf === '' ? '?' : '?status_filter=' . rawurlencode($sf); ?>
      <a href="<?= $href ?>" class="neb-tab-btn<?= $status_filter === $sf ? ' active' : '' ?>">
        <?= $label ?>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="neb-tabs-select-wrap">
    <span class="neb-tabs-select-label">Filter:</span>
    <div class="toolbar-select-wrap">
      <select class="neb-tabs-select neb-select js-nav-select">
        <?php foreach ($filterTabs as $sf => $label): ?>
          <?php $href = $sf === '' ? '?' : '?status_filter=' . rawurlencode($sf); ?>
          <option value="<?= $href ?>"<?= $status_filter === $sf ? ' selected' : '' ?>>
            <?= $label ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>


<!-- ── Codes grid ──────────────────────────────────────────────────────────── -->

<?php if ($total_codes === 0): ?>

  <div class="neb-card" style="padding:2.5rem;text-align:center">
    <div style="font-size:1.75rem;margin-bottom:.75rem">🖼️</div>
    <div style="font-weight:600;color:var(--text);margin-bottom:.35rem">No codes yet</div>
    <div style="font-size:.85rem;color:var(--text-dim)">
      Upload code images above. Members will link to
      <a class="neb-link" href="<?= View::e($listing->url !== '' ? $listing->url : '#') ?>"
         target="_blank" rel="noopener noreferrer"><?= View::e($listing->name) ?></a>
      using these images.
    </div>
  </div>

<?php elseif (empty($codes)): ?>

  <div class="neb-card" style="padding:2.5rem;text-align:center">
    <div style="font-size:1.25rem;margin-bottom:.5rem">&#128269;</div>
    <div style="font-weight:600;color:var(--text);margin-bottom:.35rem">No codes match this filter</div>
    <div style="font-size:.85rem;color:var(--text-dim)">
      <a href="?" class="neb-link">Clear filter</a> to see all <?= $total_codes ?> code<?= $total_codes !== 1 ? 's' : '' ?>.
    </div>
  </div>

<?php else: ?>

  <!-- Standalone bulk form -->
  <form id="codes-bulk-form" method="POST"
        action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/bulk">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  </form>

  <!-- Standalone delete forms + edit dialogs (one per code) -->
  <?php foreach ($codes as $code): ?>
  <?php
    $dCatIds  = $code_categories[$code->id] ?? [];
    $dDonor   = ($code->donorId !== null && isset($donor_map[$code->donorId])) ? $donor_map[$code->donorId] : null;
    $dLabel   = ($code->name !== null && $code->name !== '') ? $code->name : null;
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
        Edit code<?= $dLabel !== null ? ' — ' . View::e($dLabel) : ' #' . (int)$code->id ?>
      </span>
      <button type="button" class="code-edit-dialog-close" data-close-dialog aria-label="Close">&times;</button>
    </div>
    <div class="code-edit-dialog-body">
      <!-- Preview -->
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

      <!-- Update form -->
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
            <select name="status" class="neb-select" style="width:100%">
              <option value="approved" <?= $code->status === 'approved' ? 'selected' : '' ?>>&#10003; Live</option>
              <option value="pending"  <?= $code->status === 'pending'  ? 'selected' : '' ?>>&#8987; Pending</option>
              <option value="rejected" <?= $code->status === 'rejected' ? 'selected' : '' ?>>&#10007; Rejected</option>
            </select>
          </div>
        </div>

        <?php if ($hasDonors): ?>
        <div>
          <label class="form-label" style="font-size:.8rem">Donor</label>
          <div class="toolbar-select-wrap">
            <select name="donor_id" class="neb-select" style="width:100%">
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
          <label class="form-label" style="font-size:.8rem">Categories</label>
          <select name="cat_ids[]" multiple size="<?= min(5, count($categories)) ?>"
                  class="form-input" style="height:auto;width:100%">
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat->id ?>" <?= in_array($cat->id, $dCatIds, true) ? 'selected' : '' ?>>
                <?= $cat->parentId !== null ? '└ ' : '' ?><?= View::e($cat->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="form-hint" style="margin-top:.25rem">Hold Ctrl / Cmd to select multiple.</p>
        </div>
        <?php endif; ?>

        <button class="btn btn-primary" style="width:100%">Save changes</button>
      </form>

      <!-- Delete -->
      <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border-soft)">
        <button type="submit" form="del-<?= (int)$code->id ?>"
                class="btn btn-danger btn-sm" style="width:100%">
          Delete this code
        </button>
      </div>
    </div>
  </dialog>
  <?php endforeach; ?>

  <!-- Reorder form -->
  <form id="codes-reorder-form" method="POST"
        action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/reorder">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <input type="hidden" id="reorder-ids" name="ordered_ids" value="">
  </form>

  <!-- Bulk toolbar -->
  <div class="neb-toolbar" style="margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
    <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem;color:var(--text-dim);cursor:pointer">
      <input type="checkbox" id="codes-select-all"> Select all
    </label>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
      <div class="toolbar-select-wrap">
        <select id="bulk-action-sel" class="neb-select" style="min-width:130px">
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
      <button type="button" id="bulk-apply-btn" class="btn btn-tertiary btn-sm">Apply to selected</button>
      <button type="button" id="save-order-btn" class="btn btn-tertiary btn-sm" style="display:none">Save order</button>
    </div>
  </div>

  <!-- Grid, grouped by detected dimensions -->
  <?php $showGroupHeadings = count($grouped) > 1; ?>

  <?php foreach ($grouped as $group): ?>
    <?php if (empty($group['codes'])) continue; ?>

    <?php if ($showGroupHeadings): ?>
    <div style="display:flex;align-items:baseline;gap:.75rem;margin:1.25rem 0 .6rem">
      <span style="font-weight:600;color:var(--text);font-size:.95rem"><?= View::e($group['label']) ?></span>
      <span style="font-size:.75rem;color:var(--text-dim)"><?= count($group['codes']) ?> code<?= count($group['codes']) !== 1 ? 's' : '' ?></span>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:1.25rem">
      <?php foreach ($group['codes'] as $code): ?>
      <?php
        $codeCatIds = $code_categories[$code->id] ?? [];
        $codeDonor  = ($code->donorId !== null && isset($donor_map[$code->donorId])) ? $donor_map[$code->donorId] : null;
        $codeLabel  = ($code->name !== null && $code->name !== '') ? $code->name : null;

        [$statusColor, $statusDot] = match ($code->status) {
            'approved' => ['#22c55e', '&#10003; Live'],
            'pending'  => ['#f59e0b', '&#8987; Pending'],
            'rejected' => ['var(--error,#ef4444)', '&#10007; Rejected'],
            default    => ['var(--text-dim)', View::e($code->status)],
        };
      ?>
      <div class="neb-card code-card" data-code-id="<?= (int)$code->id ?>"
           style="padding:.75rem;display:flex;flex-direction:column;gap:.4rem">

        <!-- Top row: drag + checkbox -->
        <div style="display:flex;align-items:center;justify-content:space-between">
          <span class="code-drag-handle" title="Drag to reorder"
                style="cursor:grab;color:var(--text-dim);line-height:1;padding:.25rem .5rem;margin:-.25rem -.5rem;font-size:1rem;letter-spacing:1px">&#8942;&#8942;</span>
          <input type="checkbox" form="codes-bulk-form" name="selected[]"
                 value="<?= (int)$code->id ?>" class="code-check" style="cursor:pointer">
        </div>

        <!-- Preview image -->
        <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
             alt="Code #<?= (int)$code->id ?>"
             style="max-width:100%;max-height:90px;object-fit:contain;display:block;margin:0 auto"
             loading="lazy">

        <!-- Name label (if set) -->
        <?php if ($codeLabel !== null): ?>
        <div style="font-size:.72rem;font-weight:600;color:var(--text);text-align:center;word-break:break-word;line-height:1.3">
          <?= View::e($codeLabel) ?>
        </div>
        <?php endif; ?>

        <!-- Dimensions (shown only when there's one size group so we don't double-display) -->
        <?php if ($code->width > 0 && $code->height > 0 && !$showGroupHeadings): ?>
        <div style="font-size:.7rem;color:var(--text-dim);text-align:center">
          <?= (int)$code->width ?>&times;<?= (int)$code->height ?>
        </div>
        <?php endif; ?>

        <!-- Category badges -->
        <?php if (!empty($codeCatIds)): ?>
        <div style="display:flex;flex-wrap:wrap;gap:3px;justify-content:center">
          <?php foreach ($codeCatIds as $catId): ?>
            <?php if (isset($category_map[$catId])): ?>
            <span class="neb-badge" style="font-size:.64rem;background:var(--accent-dim,var(--bg-subtle));color:var(--accent)">
              <?= View::e($category_map[$catId]->name) ?>
            </span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Donor + status row -->
        <div style="font-size:.68rem;color:var(--text-dim);text-align:center;line-height:1.6">
          <?php if ($codeDonor !== null): ?>&hearts; <?= View::e($codeDonor->name) ?><br><?php endif; ?>
          <span style="color:<?= $statusColor ?>;font-weight:600"><?= $statusDot ?></span>
        </div>

        <!-- Submitter info (pending codes only) -->
        <?php if ($code->status === 'pending' && ($code->submitterName !== null || $code->submitterEmail !== null)): ?>
        <div style="font-size:.65rem;color:var(--text-dim);text-align:center;line-height:1.5;word-break:break-all;border-top:1px solid var(--border-soft);padding-top:.3rem">
          <?php if ($code->submitterName !== null): ?>
            <div><?= View::e($code->submitterName) ?></div>
          <?php endif; ?>
          <?php if ($code->submitterEmail !== null): ?>
            <div><a class="neb-link" href="mailto:<?= View::e($code->submitterEmail) ?>"><?= View::e($code->submitterEmail) ?></a></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Edit button (opens out-of-flow dialog) -->
        <div style="margin-top:.1rem;padding-top:.25rem;border-top:1px solid var(--border-soft);text-align:center">
          <button type="button" class="btn btn-tertiary btn-sm code-edit-btn"
                  data-dialog="edit-dialog-<?= (int)$code->id ?>"
                  style="font-size:.7rem;width:100%">
            &#9881; Edit
          </button>
        </div>

      </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

<?php endif; ?>

