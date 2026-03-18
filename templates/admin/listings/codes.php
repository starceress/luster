<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var \Luster\Models\Listing $listing
 * @var object[]               $codes            Codes for display (may be status-filtered)
 * @var object[]               $sizes            Size groups (CodeSizesRepo result objects)
 * @var object[]               $categories       Category groups (CodeCategoriesRepo result objects)
 * @var object[]               $donors           Donors (CodeDonorsRepo result objects)
 * @var array                  $grouped          ['size_id|0' => ['size' => object|null, 'codes' => object[]]]
 * @var array<int,int[]>       $code_categories  code_id → [category_id, ...]
 * @var array<int,object>      $category_map     category_id → category object
 * @var array<int,object>      $donor_map        donor_id → donor object
 * @var int                    $edit_size_id     Which size row to show in edit mode (0 = none)
 * @var int                    $edit_cat_id      Which category row to show in edit mode (0 = none)
 * @var int                    $edit_donor_id    Which donor row to show in edit mode (0 = none)
 * @var string                 $status_filter    Active status filter ('' = all)
 * @var array<string,int>      $status_counts    Per-status totals for all codes in listing
 * @var int                    $total_codes      Total codes regardless of filter
 * @var string|null             $code->name       Per-code label (null if unset)
 * @var string                 $uploads_url      Base URL for /uploads/codes (no trailing slash)
 * @var string                 $success
 * @var string                 $error
 * @var string                 $base_url
 * @var string                 $csrf_token
 */

$b            = rtrim(View::e($base_url), '/');
$fid          = (int) $listing->id;
$hasSizes     = !empty($sizes);
$hasCats      = !empty($categories);
$hasDonors    = !empty($donors);
$totalDisplay = count($codes);
$pendingCount = $status_counts['pending'] ?? 0;

// Build a lookup: sizeId → label
$sizeLabels = [];
foreach ($sizes as $sz) {
    $sizeLabels[$sz->id] = $sz->label;
}

// Separate top-level categories from children for the add-category parent dropdown
$topLevelCats = array_filter($categories, fn($c) => $c->parentId === null);
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
  <a href="<?= $b ?>/admin/listings/<?= $fid ?>/edit" class="neb-link">
    &larr; Back to <?= View::e($listing->name) ?>
  </a>
  <div style="display:flex;align-items:center;gap:.75rem">
    <?php if (($status_counts['approved'] ?? 0) > 0): ?>
    <a href="<?= $b ?>/admin/listings/<?= $fid ?>/codes/export"
       class="btn btn-secondary btn-sm">&#8659; Export ZIP</a>
    <?php endif; ?>
    <span style="font-size:.8rem;color:var(--text-dim)">
      <?= $total_codes ?> code<?= $total_codes !== 1 ? 's' : '' ?>
      <?php if ($pendingCount > 0): ?>
        &mdash; <span style="color:#f59e0b;font-weight:600"><?= $pendingCount ?> pending</span>
      <?php endif; ?>
      <?php if ($hasSizes):   ?> &mdash; <?= count($sizes)      ?> size<?=      count($sizes)      !== 1 ? 's' : '' ?><?php endif; ?>
      <?php if ($hasCats):    ?> &mdash; <?= count($categories) ?> categor<?=   count($categories) !== 1 ? 'ies' : 'y' ?><?php endif; ?>
      <?php if ($hasDonors):  ?> &mdash; <?= count($donors)     ?> donor<?=     count($donors)     !== 1 ? 's' : '' ?><?php endif; ?>
    </span>
  </div>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ── Status filter tabs ─────────────────────────────────────────────────── -->
<?php
$filterTabs = [
    ''         => 'All',
    'approved' => '&#10003; Approved',
    'pending'  => '&#8987; Pending',
    'rejected' => '&#10007; Rejected',
];
?>
<div style="display:flex;gap:.4rem;margin-bottom:1.25rem;flex-wrap:wrap">
  <?php foreach ($filterTabs as $sf => $label): ?>
    <?php
      $sfCount = $sf === '' ? $total_codes : ($status_counts[$sf] ?? 0);
      $active  = $status_filter === $sf;
      $href    = $sf === '' ? '?' : '?status_filter=' . rawurlencode($sf);
    ?>
    <a href="<?= $href ?>" class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-secondary' ?>">
      <?= $label ?> (<?= $sfCount ?>)
    </a>
  <?php endforeach; ?>
</div>


<!-- ── Management panels (3-column grid on wider screens) ──────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.25rem;margin-bottom:1.25rem">

  <!-- ── Size Groups ──────────────────────────────────────────────────── -->
  <div class="neb-card">
    <div class="panel-header" style="border-bottom:1px solid var(--border)">
      <div class="panel-title" style="font-size:.875rem">
        <svg class="panel-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        Code Sizes
      </div>
    </div>

    <!-- Add size -->
    <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border-soft)">
      <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/sizes/add"
            style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <div style="flex:1;min-width:100px">
          <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">Label</label>
          <input class="form-input" type="text" name="label" placeholder="e.g. 50×50" required>
        </div>
        <div style="width:58px">
          <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">W</label>
          <input class="form-input" type="number" name="width" placeholder="px" min="1">
        </div>
        <div style="width:58px">
          <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">H</label>
          <input class="form-input" type="number" name="height" placeholder="px" min="1">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:1px;white-space:nowrap">+ Add</button>
      </form>
    </div>

    <?php if ($hasSizes): ?>
    <table class="data-table" style="margin:0;font-size:.82rem">
      <tbody>
        <?php foreach ($sizes as $sz): ?>
        <?php $isEditing = $edit_size_id === $sz->id; ?>
        <tr <?= $isEditing ? 'style="display:none"' : '' ?>>
          <td class="text-strong" style="padding:.5rem .75rem"><?= View::e($sz->label) ?></td>
          <td class="text-dim" style="padding:.5rem .25rem;font-size:.75rem;white-space:nowrap">
            <?= ($sz->width !== null && $sz->height !== null) ? (int)$sz->width.'×'.(int)$sz->height : '—' ?>
          </td>
          <td class="text-dim" style="padding:.5rem .25rem;text-align:center">
            <?= count(array_filter($codes, fn($c) => $c->sizeId === $sz->id)) ?>
          </td>
          <td class="act-col" style="padding:.5rem .75rem">
            <a href="?edit_size=<?= (int)$sz->id ?>" class="btn btn-secondary btn-sm">Edit</a>
            <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/sizes/<?= (int)$sz->id ?>/delete"
                  style="display:contents"
                  data-confirm="Delete &ldquo;<?= View::e($sz->label) ?>&rdquo;? Codes in this group become Unsized.">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <tr <?= !$isEditing ? 'style="display:none"' : '' ?>>
          <td colspan="4" style="padding:.6rem .75rem;background:var(--bg-subtle)">
            <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/sizes/<?= (int)$sz->id ?>/edit"
                  style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <div style="flex:1;min-width:80px">
                <input class="form-input" type="text" name="label" value="<?= View::e($sz->label) ?>" required>
              </div>
              <div style="width:58px">
                <input class="form-input" type="number" name="width"
                       value="<?= $sz->width !== null ? (int)$sz->width : '' ?>" placeholder="W" min="1">
              </div>
              <div style="width:58px">
                <input class="form-input" type="number" name="height"
                       value="<?= $sz->height !== null ? (int)$sz->height : '' ?>" placeholder="H" min="1">
              </div>
              <button class="btn btn-primary btn-sm">Save</button>
              <a href="?" class="btn btn-secondary btn-sm">Cancel</a>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="padding:.75rem 1rem;color:var(--text-dim);font-size:.8rem;margin:0">
      No sizes yet. Codes will show as a flat list.
    </p>
    <?php endif; ?>
  </div>

  <!-- ── Categories ────────────────────────────────────────────────────── -->
  <div class="neb-card">
    <div class="panel-header" style="border-bottom:1px solid var(--border)">
      <div class="panel-title" style="font-size:.875rem">
        <svg class="panel-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
        Categories
      </div>
    </div>

    <!-- Add category -->
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
          <td class="text-dim" style="padding:.5rem .25rem;text-align:center;font-size:.75rem">
            <?= $cat->parentId !== null && isset($category_map[$cat->parentId]) ? View::e($category_map[$cat->parentId]->name) : '—' ?>
          </td>
          <td class="text-dim" style="padding:.5rem .25rem;text-align:center">
            <?php
              $catCodeCount = count(array_filter($code_categories, fn($ids) => in_array($cat->id, $ids, true)));
            ?>
            <?= $catCodeCount ?>
          </td>
          <td class="act-col" style="padding:.5rem .75rem">
            <a href="?edit_cat=<?= (int)$cat->id ?>" class="btn btn-secondary btn-sm">Edit</a>
            <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/categories/<?= (int)$cat->id ?>/delete"
                  style="display:contents"
                  data-confirm="Delete &ldquo;<?= View::e($cat->name) ?>&rdquo;? Assignments to this category will be removed.">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <tr <?= !$isCatEditing ? 'style="display:none"' : '' ?>>
          <td colspan="4" style="padding:.6rem .75rem;background:var(--bg-subtle)">
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
              <a href="?" class="btn btn-secondary btn-sm">Cancel</a>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
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

    <!-- Add donor -->
    <div style="padding:.75rem 1rem;border-bottom:1px solid var(--border-soft)">
      <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/donors/add"
            style="display:flex;gap:.4rem;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <div style="flex:1;min-width:100px">
          <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">Name</label>
          <input class="form-input" type="text" name="name" placeholder="Display name" required>
        </div>
        <div style="flex:1;min-width:110px">
          <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">Email</label>
          <input class="form-input" type="email" name="email" placeholder="optional">
        </div>
        <div style="flex:1;min-width:110px">
          <label class="form-label" style="font-size:.72rem;margin-bottom:.2rem">URL</label>
          <input class="form-input" type="url" name="url" placeholder="optional">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-bottom:1px;white-space:nowrap">+ Add</button>
      </form>
    </div>

    <?php if ($hasDonors): ?>
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
            <a href="?edit_donor=<?= (int)$dn->id ?>" class="btn btn-secondary btn-sm">Edit</a>
            <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/donors/<?= (int)$dn->id ?>/delete"
                  style="display:contents"
                  data-confirm="Delete donor &ldquo;<?= View::e($dn->name) ?>&rdquo;? Their codes will be unattributed.">
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
              <a href="?" class="btn btn-secondary btn-sm">Cancel</a>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p style="padding:.75rem 1rem;color:var(--text-dim);font-size:.8rem;margin:0">
      No donors yet. Credit the people who made codes for this listing.
    </p>
    <?php endif; ?>
  </div>

</div><!-- /management panels grid -->


<!-- ── Upload ──────────────────────────────────────────────────────────── -->
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

      <?php if ($hasSizes): ?>
      <div>
        <label class="form-label" for="upload-size">Size</label>
        <div class="toolbar-select-wrap">
          <select id="upload-size" name="size_id" class="neb-select">
            <option value="">No size</option>
            <?php foreach ($sizes as $sz): ?>
              <option value="<?= (int)$sz->id ?>"><?= View::e($sz->label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>

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
      <label class="form-label" style="margin-bottom:.35rem">
        Categories
        <span style="font-weight:400;color:var(--text-dim)">(hold Ctrl/Cmd to select multiple)</span>
      </label>
      <select name="cat_ids[]" multiple size="<?= min(6, count($categories)) ?>"
              class="form-input" style="height:auto;min-height:60px">
        <?php foreach ($categories as $cat): ?>
          <option value="<?= (int)$cat->id ?>">
            <?= $cat->parentId !== null ? '  └ ' : '' ?><?= View::e($cat->name) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <p class="form-hint" style="margin-top:.5rem">
      Select multiple files at once. Dimensions are detected automatically.
      Attributes above apply to all files in this upload batch.
    </p>
  </form>
</div>


<!-- ── Codes grid ──────────────────────────────────────────────────────── -->

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

  <!-- Standalone bulk form (no rendered content; buttons reference it by id) -->
  <form id="codes-bulk-form" method="POST"
        action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/bulk">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  </form>

  <!-- Standalone per-code delete forms -->
  <?php foreach ($codes as $code): ?>
  <form id="del-<?= (int)$code->id ?>" method="POST"
        action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/<?= (int)$code->id ?>/delete"
        data-confirm="Delete this code? This cannot be undone.">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  </form>
  <?php endforeach; ?>

  <!-- Reorder form (submitted by JS after drag-drop) -->
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
          <?php if ($hasSizes): ?>
            <option value="reassign">Move to size&hellip;</option>
          <?php endif; ?>
          <?php if ($hasCats): ?>
            <option value="categorize">Add to categories&hellip;</option>
          <?php endif; ?>
        </select>
      </div>
      <?php if ($hasSizes): ?>
      <div class="toolbar-select-wrap" id="bulk-size-wrap" style="display:none">
        <select id="bulk-size-sel" class="neb-select">
          <option value="">No size</option>
          <?php foreach ($sizes as $sz): ?>
            <option value="<?= (int)$sz->id ?>"><?= View::e($sz->label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
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
      <button type="button" id="bulk-apply-btn" class="btn btn-secondary btn-sm">Apply to selected</button>
      <button type="button" id="save-order-btn" class="btn btn-secondary btn-sm" style="display:none">Save order</button>
    </div>
  </div>

  <!-- Grid, grouped by size (flat when no sizes defined) -->
  <?php $groupsToRender = $hasSizes ? $grouped : [0 => ['size' => null, 'codes' => $codes]]; ?>

  <?php foreach ($groupsToRender as $group): ?>
    <?php if (empty($group['codes'])) continue; ?>

    <?php if ($hasSizes): ?>
    <div style="display:flex;align-items:baseline;gap:.75rem;margin:1.25rem 0 .6rem">
      <?php if ($group['size'] !== null): ?>
        <span style="font-weight:600;color:var(--text);font-size:.95rem"><?= View::e($group['size']->label) ?></span>
        <?php if ($group['size']->width !== null && $group['size']->height !== null): ?>
          <span style="font-size:.75rem;color:var(--text-dim)"><?= (int)$group['size']->width ?>&times;<?= (int)$group['size']->height ?>px</span>
        <?php endif; ?>
      <?php else: ?>
        <span style="font-weight:600;color:var(--text-muted);font-size:.95rem">Unsized</span>
      <?php endif; ?>
      <span style="font-size:.75rem;color:var(--text-dim)"><?= count($group['codes']) ?> code<?= count($group['codes']) !== 1 ? 's' : '' ?></span>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:1rem;margin-bottom:1.25rem">
      <?php foreach ($group['codes'] as $code): ?>
      <?php
        $codeCatIds = $code_categories[$code->id] ?? [];
        $codeDonor  = ($code->donorId !== null && isset($donor_map[$code->donorId])) ? $donor_map[$code->donorId] : null;

        $codeLabel = ($code->name !== null && $code->name !== '') ? $code->name : null;

        [$statusColor, $statusLabel] = match ($code->status) {
            'approved' => ['color:#22c55e',              '&#10003; Live'],
            'pending'  => ['color:#f59e0b',              '&#8987; Pending'],
            'rejected' => ['color:var(--error,#ef4444)', '&#10007; Rejected'],
            default    => ['color:var(--text-dim)',       View::e($code->status)],
        };
      ?>
      <div class="neb-card code-card" data-code-id="<?= (int)$code->id ?>"
           style="padding:.75rem;display:flex;flex-direction:column;gap:.45rem">

        <!-- Drag handle (for sort reorder) -->
        <div class="code-drag-handle" title="Drag to reorder"
             style="cursor:grab;text-align:center;color:var(--text-dim);font-size:.85rem;line-height:1;padding:.1rem 0">
          &#8597;
        </div>

        <!-- Checkbox -->
        <div>
          <input type="checkbox" form="codes-bulk-form" name="selected[]"
                 value="<?= (int)$code->id ?>" class="code-check" style="cursor:pointer">
        </div>

        <!-- Preview image -->
        <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
             alt="Code #<?= (int)$code->id ?>"
             style="max-width:100%;max-height:80px;object-fit:contain;display:block;margin:0 auto"
             loading="lazy">

        <!-- Name label (if set) -->
        <?php if ($codeLabel !== null): ?>
        <div style="font-size:.72rem;font-weight:600;color:var(--text);text-align:center;word-break:break-word;line-height:1.3">
          <?= View::e($codeLabel) ?>
        </div>
        <?php endif; ?>

        <!-- Dimensions + size badge -->
        <div style="font-size:.7rem;color:var(--text-dim);text-align:center;line-height:1.5">
          <?php if ($code->width > 0 && $code->height > 0): ?>
            <?= (int)$code->width ?>&times;<?= (int)$code->height ?>
          <?php endif; ?>
          <?php if ($hasSizes): ?>
            <?php if ($code->sizeId !== null && isset($sizeLabels[$code->sizeId])): ?>
              <span style="display:block;background:var(--bg-subtle);border-radius:3px;padding:.1rem .35rem;color:var(--text-muted)"><?= View::e($sizeLabels[$code->sizeId]) ?></span>
            <?php else: ?>
              <span style="display:block;color:var(--text-dim);font-style:italic">unsized</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <!-- Category badges -->
        <?php if (!empty($codeCatIds)): ?>
        <div style="display:flex;flex-wrap:wrap;gap:3px;justify-content:center">
          <?php foreach ($codeCatIds as $catId): ?>
            <?php if (isset($category_map[$catId])): ?>
            <span class="neb-badge" style="font-size:.65rem;background:var(--accent-dim, var(--bg-subtle));color:var(--accent)">
              <?= View::e($category_map[$catId]->name) ?>
            </span>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Donor badge -->
        <?php if ($codeDonor !== null): ?>
        <div style="font-size:.68rem;color:var(--text-dim);text-align:center">
          &hearts; <?= View::e($codeDonor->name) ?>
        </div>
        <?php endif; ?>

        <!-- Status badge -->
        <div style="font-size:.65rem;text-align:center;<?= $statusColor ?>">
          <?= $statusLabel ?>
        </div>

        <!-- Submitter info (shown on pending codes only) -->
        <?php if ($code->status === 'pending' && ($code->submitterName !== null || $code->submitterEmail !== null)): ?>
        <div style="font-size:.65rem;color:var(--text-dim);text-align:center;line-height:1.5;word-break:break-all">
          <?php if ($code->submitterName !== null): ?>
            <div><?= View::e($code->submitterName) ?></div>
          <?php endif; ?>
          <?php if ($code->submitterEmail !== null): ?>
            <div><a class="neb-link" href="mailto:<?= View::e($code->submitterEmail) ?>"><?= View::e($code->submitterEmail) ?></a></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Metadata edit (size + categories + donor + status) -->
        <details style="margin-top:.15rem">
          <summary style="font-size:.7rem;color:var(--text-muted);cursor:pointer;list-style:none;text-align:center;padding:.2rem 0;border-top:1px solid var(--border-soft)">
            &#9881; Edit
          </summary>
          <div style="display:flex;flex-direction:column;gap:.5rem;padding:.4rem 0 .1rem">

            <!-- Name / label -->
            <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/<?= (int)$code->id ?>/name"
                  style="display:flex;gap:.3rem;align-items:center">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <input class="form-input" type="text" name="name"
                     value="<?= View::e($code->name ?? '') ?>"
                     placeholder="Label (optional)"
                     style="font-size:.7rem;padding:.2rem .4rem;flex:1;min-width:0">
              <button class="btn btn-secondary btn-sm" style="font-size:.68rem;padding:.2rem .4rem">Label</button>
            </form>

            <?php if ($hasSizes): ?>
            <!-- Size reassign -->
            <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/<?= (int)$code->id ?>/size"
                  style="display:flex;gap:.3rem;align-items:center">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <div class="toolbar-select-wrap" style="flex:1;min-width:0">
                <select name="size_id" class="neb-select" style="font-size:.7rem;padding:.2rem .4rem;width:100%">
                  <option value="">No size</option>
                  <?php foreach ($sizes as $sz): ?>
                    <option value="<?= (int)$sz->id ?>" <?= $code->sizeId === $sz->id ? 'selected' : '' ?>>
                      <?= View::e($sz->label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn-secondary btn-sm" style="font-size:.68rem;padding:.2rem .4rem">Size</button>
            </form>
            <?php endif; ?>

            <?php if ($hasCats): ?>
            <!-- Category reassign -->
            <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/<?= (int)$code->id ?>/categories">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <select name="cat_ids[]" multiple size="<?= min(4, count($categories)) ?>"
                      class="form-input" style="height:auto;font-size:.7rem;padding:.2rem;width:100%">
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= (int)$cat->id ?>" <?= in_array($cat->id, $codeCatIds, true) ? 'selected' : '' ?>>
                    <?= $cat->parentId !== null ? '  └ ' : '' ?><?= View::e($cat->name) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-secondary btn-sm" style="width:100%;margin-top:.3rem;font-size:.7rem">Save categories</button>
            </form>
            <?php endif; ?>

            <?php if ($hasDonors): ?>
            <!-- Donor reassign -->
            <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/<?= (int)$code->id ?>/donor"
                  style="display:flex;gap:.3rem;align-items:center">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <div class="toolbar-select-wrap" style="flex:1;min-width:0">
                <select name="donor_id" class="neb-select" style="font-size:.7rem;padding:.2rem .4rem;width:100%">
                  <option value="">No donor</option>
                  <?php foreach ($donors as $dn): ?>
                    <option value="<?= (int)$dn->id ?>" <?= $code->donorId === $dn->id ? 'selected' : '' ?>>
                      <?= View::e($dn->name) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn-secondary btn-sm" style="font-size:.68rem;padding:.2rem .4rem">Donor</button>
            </form>
            <?php endif; ?>

            <!-- Status change -->
            <form method="POST" action="<?= $b ?>/admin/listings/<?= $fid ?>/codes/<?= (int)$code->id ?>/status"
                  style="display:flex;gap:.3rem;align-items:center">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <div class="toolbar-select-wrap" style="flex:1;min-width:0">
                <select name="status" class="neb-select" style="font-size:.7rem;padding:.2rem .4rem;width:100%">
                  <option value="approved" <?= $code->status === 'approved' ? 'selected' : '' ?>>Live</option>
                  <option value="pending"  <?= $code->status === 'pending'  ? 'selected' : '' ?>>Pending</option>
                  <option value="rejected" <?= $code->status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
              </div>
              <button class="btn btn-secondary btn-sm" style="font-size:.68rem;padding:.2rem .4rem">Status</button>
            </form>

          </div>
        </details>

        <!-- Delete button -->
        <button type="submit" form="del-<?= (int)$code->id ?>"
                class="btn btn-danger btn-sm" style="width:100%">
          Delete
        </button>

      </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

<?php endif; ?>

<script>
(function () {
  'use strict';

  // ── Select-all checkbox ────────────────────────────────────────────────
  var selAll = document.getElementById('codes-select-all');
  if (selAll) {
    selAll.addEventListener('change', function () {
      document.querySelectorAll('.code-check').forEach(function (cb) { cb.checked = selAll.checked; });
    });
  }

  // ── Bulk action toolbar ────────────────────────────────────────────────
  var bulkSel    = document.getElementById('bulk-action-sel');
  var sizeWrap   = document.getElementById('bulk-size-wrap');
  var catsWrap   = document.getElementById('bulk-cats-wrap');
  var sizeSel    = document.getElementById('bulk-size-sel');
  var catsSel    = document.getElementById('bulk-cats-sel');
  var applyBtn   = document.getElementById('bulk-apply-btn');
  var saveOrdBtn = document.getElementById('save-order-btn');
  var bulkForm   = document.getElementById('codes-bulk-form');

  if (bulkSel) {
    bulkSel.addEventListener('change', function () {
      if (sizeWrap) { sizeWrap.style.display = bulkSel.value === 'reassign'   ? '' : 'none'; }
      if (catsWrap) { catsWrap.style.display = bulkSel.value === 'categorize' ? '' : 'none'; }
    });
  }

  if (applyBtn && bulkForm) {
    applyBtn.addEventListener('click', function () {
      var action  = bulkSel ? bulkSel.value : 'delete';
      var checked = document.querySelectorAll('.code-check:checked');

      if (checked.length === 0) { alert('No codes selected.'); return; }

      if (action === 'delete') {
        if (!confirm('Delete ' + checked.length + ' code(s)? This cannot be undone.')) return;
      }

      bulkForm.querySelectorAll('.js-bulk-inject').forEach(function (el) { el.remove(); });

      var addHidden = function (name, val) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = name; inp.value = val; inp.className = 'js-bulk-inject';
        bulkForm.appendChild(inp);
      };

      addHidden('bulk_action', action);
      if (action === 'reassign' && sizeSel) {
        addHidden('bulk_size_id', sizeSel.value);
      }
      if (action === 'categorize' && catsSel) {
        Array.from(catsSel.selectedOptions).forEach(function (opt) {
          addHidden('bulk_cat_ids[]', opt.value);
        });
      }

      bulkForm.submit();
    });
  }

  // ── Drag-and-drop reorder ──────────────────────────────────────────────
  var reorderForm = document.getElementById('codes-reorder-form');
  var reorderIds  = document.getElementById('reorder-ids');

  if (reorderForm && reorderIds && saveOrdBtn) {
    var cards = Array.from(document.querySelectorAll('.code-card'));

    if (cards.length > 1) {
      saveOrdBtn.style.display = '';

      var dragSrc = null;

      cards.forEach(function (card) {
        card.setAttribute('draggable', 'true');

        card.addEventListener('dragstart', function (e) {
          dragSrc = card;
          card.style.opacity = '0.5';
          e.dataTransfer.effectAllowed = 'move';
        });

        card.addEventListener('dragend', function () {
          card.style.opacity = '';
          document.querySelectorAll('.code-card').forEach(function (c) {
            c.style.outline = '';
          });
        });

        card.addEventListener('dragover', function (e) {
          e.preventDefault();
          e.dataTransfer.dropEffect = 'move';
          card.style.outline = '2px solid var(--accent, #6366f1)';
        });

        card.addEventListener('dragleave', function () {
          card.style.outline = '';
        });

        card.addEventListener('drop', function (e) {
          e.preventDefault();
          card.style.outline = '';
          if (dragSrc && dragSrc !== card) {
            var parent = card.parentNode;
            var srcIdx = Array.from(parent.children).indexOf(dragSrc);
            var tgtIdx = Array.from(parent.children).indexOf(card);
            if (srcIdx < tgtIdx) {
              parent.insertBefore(dragSrc, card.nextSibling);
            } else {
              parent.insertBefore(dragSrc, card);
            }
          }
        });
      });

      saveOrdBtn.addEventListener('click', function () {
        var ordered = Array.from(document.querySelectorAll('.code-card')).map(function (c) {
          return parseInt(c.getAttribute('data-code-id'), 10);
        }).filter(function (id) { return id > 0; });

        reorderIds.value = JSON.stringify(ordered);
        reorderForm.submit();
      });
    }
  }
}());
</script>
