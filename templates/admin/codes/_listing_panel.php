<?php

declare(strict_types=1);

use Luster\Core\View;

/*
 * Shared per-listing codes panel.
 * Included by templates/admin/codes/index.php and templates/admin/listings/edit.php.
 *
 * Required variables set by caller:
 *   $b, $csrf_token, $codes, $uploads_url
 *   $_fid              int     listing ID
 *   $_from             string  'codes' (standalone) | 'edit' (listing edit tab)
 *   $_codesBaseUrl     ?string non-null = standalone mode URL; null = edit-tab mode
 *   $_hasCats          bool
 *   $_hasDonors        bool
 *   $_codeDonors       array   donor objects
 *   $_codeCats         array   category objects
 *   $_codeDonorMap     array   id => donor object
 *   $_codeCatIds       array   code_id => int[]
 *   $_codeStatusFilter string
 *   $_totalCodes       int
 *   $_approvedCount    int
 *   $_pendingCount     int
 *   $_rejectedCount    int
 *   $_groupedCodes     array
 *   $_groupCount       int
 *   $_uploadSubmitters array   past submitter name strings
 */

// Precompute tab URLs so HTML stays clean
$_tabApproved = $_codesBaseUrl !== null
    ? View::e($_codesBaseUrl . '&tab=approved')
    : '?tab=codes&status_filter=approved';
$_tabPending  = $_codesBaseUrl !== null
    ? View::e($_codesBaseUrl . '&tab=pending')
    : '?tab=codes&status_filter=pending';
$_tabRejected = $_codesBaseUrl !== null
    ? View::e($_codesBaseUrl . '&tab=rejected')
    : '?tab=codes&status_filter=rejected';
?>

<form id="codes-bulk-form" method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/bulk">
  <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_from"  value="<?= View::e($_from) ?>">
</form>
<form id="codes-reorder-form" method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/reorder">
  <input type="hidden" name="_csrf"     value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_from"     value="<?= View::e($_from) ?>">
  <input type="hidden" id="reorder-ids" name="ordered_ids" value="">
</form>

<?php foreach ($codes as $code):
  $_dDonor = ($code->donorId !== null && isset($_codeDonorMap[$code->donorId])) ? $_codeDonorMap[$code->donorId] : null;
  $_dLabel = ($code->name !== null && $code->name !== '') ? $code->name : null;
?>
<dialog id="edit-dialog-<?= (int)$code->id ?>" class="modal code-edit-dialog">
  <div class="modal-header modal-header-sticky">
    <span class="modal-title">Edit code &mdash; <?= View::e($code->filename) ?></span>
    <button type="button" class="modal-close" data-close-dialog aria-label="Close">&times;</button>
  </div>
  <div class="modal-body">
    <div class="code-preview">
      <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
           alt="<?= View::e($code->filename) ?>"
           class="code-preview-img">
      <?php if ($code->width > 0 && $code->height > 0): ?>
      <div class="code-preview-meta"><?= (int)$code->width ?>&times;<?= (int)$code->height ?>px</div>
      <?php endif; ?>
    </div>
    <form method="POST"
          action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/<?= (int)$code->id ?>/update"
          class="code-modal-form">
      <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
      <input type="hidden" name="_from"  value="<?= View::e($_from) ?>">
      <div>
        <label class="field-label code-modal-label">Label <span class="text-dim">(optional)</span></label>
        <input class="form" type="text" name="name" value="<?= View::e($code->name ?? '') ?>"
               placeholder="<?= View::e($code->filename) ?>">
      </div>
      <div>
        <label class="field-label code-modal-label">Status</label>
        <div class="select-wrap">
          <select name="status">
            <option value="approved" <?= $code->status === 'approved' ? 'selected' : '' ?>>Live</option>
            <option value="pending"  <?= $code->status === 'pending'  ? 'selected' : '' ?>>Pending</option>
            <option value="rejected" <?= $code->status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
          </select>
        </div>
      </div>
      <?php if ($_hasDonors): ?>
      <div>
        <label class="field-label code-modal-label">Donor</label>
        <div class="select-wrap">
          <select name="donor_id">
            <option value="">None</option>
            <?php foreach ($_codeDonors as $dn): ?>
              <option value="<?= (int)$dn->id ?>" <?= $code->donorId === $dn->id ? 'selected' : '' ?>><?= View::e($dn->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($_hasCats): ?>
      <div>
        <label class="field-label code-modal-label">Category</label>
        <div class="select-wrap">
          <select name="cat_ids[]">
            <option value="">None</option>
            <?php
              $_mCatIds = $_codeCatIds[$code->id] ?? [];
              foreach ($_codeCats as $cat):
            ?>
              <option value="<?= (int)$cat->id ?>" <?= in_array($cat->id, $_mCatIds, true) ? 'selected' : '' ?>>
                <?= $cat->parentId !== null ? '&#x2514; ' : '' ?><?= View::e($cat->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>
      <?php $_hasDonorInfo = $code->donorId !== null || $code->submitterName !== null || $code->submitterEmail !== null || $code->submitterUrl !== null; ?>
      <details<?= $_hasDonorInfo ? ' open' : '' ?> class="code-donor-details">
        <summary class="link" style="font-size:var(--fs-sm);margin-bottom:.5rem;display:inline-block;list-style:none;cursor:pointer"><?= $_hasDonorInfo ? 'Donor information' : '+ Add Donor Information' ?></summary>
        <div>
          <label class="field-label code-modal-label">Donor Name</label>
          <input class="form" type="text" name="submitter_name"
                 value="<?= View::e($code->submitterName ?? '') ?>" placeholder="Display name&hellip;">
        </div>
        <div style="margin-top:.5rem">
          <label class="field-label code-modal-label">Donor Email</label>
          <input class="form" type="email" name="submitter_email"
                 value="<?= View::e($code->submitterEmail ?? '') ?>" placeholder="Optional">
        </div>
        <div style="margin-top:.5rem">
          <label class="field-label code-modal-label">Donor Website</label>
          <input class="form" type="url" name="submitter_url"
                 value="<?= View::e($code->submitterUrl ?? '') ?>" placeholder="https://&hellip;">
        </div>
      </details>
      <div class="composer-footer" style="margin-top:1rem">
        <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
        <button type="button" class="btn btn-danger btn-sm code-dialog-delete-btn">Delete</button>
      </div>
    </form>
    <form class="code-delete-form"
          method="POST"
          action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/<?= (int)$code->id ?>/delete"
          data-del-modal data-del-entity="code"
          data-del-name="<?= View::e($code->filename) ?>" style="display:none">
      <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
      <input type="hidden" name="_from"  value="<?= View::e($_from) ?>">
    </form>
  </div>
</dialog>
<?php endforeach; ?>

<div class="codes-page-layout">

<!-- ── Upload Card ─────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">Upload Codes</div>
  <form method="POST" action="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/upload" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <input type="hidden" name="_from" value="<?= View::e($_from) ?>">
    <div class="pc-section">
      <div class="file-drop-zone">
        <input type="file" id="codes-input" name="codes[]" multiple accept="image/jpeg,image/png,image/gif,image/webp">
        <span class="file-drop-icon"><?= View::icon('image') ?></span>
        <strong>Drag &amp; drop or click to browse</strong>
        <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 500 KB each</span>
      </div>
      <p class="panel-hint" style="margin-top:.5rem">Multiple files allowed. Dimensions are detected automatically.</p>
    </div>

    <div class="pc-section">
      <div class="panel-field">
        <label class="panel-label" for="upload-donor">Donor</label>
        <div class="select-wrap">
          <select id="upload-donor" name="donor_id">
            <option value="">None</option>
            <?php if (!empty($_codeDonors)): ?>
              <?php if (!empty($_uploadSubmitters)): ?><optgroup label="Registered Donors"><?php endif; ?>
              <?php foreach ($_codeDonors as $dn): ?>
                <option value="<?= (int)$dn->id ?>"><?= View::e($dn->name) ?></option>
              <?php endforeach; ?>
              <?php if (!empty($_uploadSubmitters)): ?></optgroup><?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($_uploadSubmitters)): ?>
              <optgroup label="Past Submitters">
                <?php foreach ($_uploadSubmitters as $sn): ?>
                  <option value="s:<?= View::e($sn) ?>"><?= View::e($sn) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          </select>
        </div>
        <p class="panel-hint"><a href="#" id="upload-add-donor-toggle" class="link btn"><?= View::icon('add') ?> Add New Donor</a></p>
      </div>

      <!-- Inline new donor fields -->
      <div id="upload-new-donor-wrap" class="is-hidden">
        <div class="panel-field">
          <label class="panel-label" for="upload-donor-name">Donor Name <span class="req">*</span></label>
          <input class="form" type="text" id="upload-donor-name" name="new_donor_name" placeholder="Display name&hellip;">
        </div>
        <div class="panel-field">
          <label class="panel-label" for="upload-donor-email">Email</label>
          <input class="form" type="email" id="upload-donor-email" name="new_donor_email" placeholder="Optional">
        </div>
        <div class="panel-field">
          <label class="panel-label" for="upload-donor-url">Website</label>
          <input class="form" type="url" id="upload-donor-url" name="new_donor_url" placeholder="https://&hellip;">
        </div>
      </div>

      <?php if ($_hasCats): ?>
      <div class="panel-field">
        <label class="panel-label" for="upload-category">Category</label>
        <div class="select-wrap">
          <select id="upload-category" name="cat_ids[]">
            <option value="">None</option>
            <?php foreach ($_codeCats as $cat): ?>
              <option value="<?= (int)$cat->id ?>"><?= $cat->parentId !== null ? '&#x2514; ' : '' ?><?= View::e($cat->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Add</button>
      <button type="reset" class="btn btn-ghost">Clear</button>
    </div>
  </form>
</div>

<!-- ── Current Codes Card ───────────────────────────────────────────── -->
<div class="card codes-browser" style="min-width:0" data-initial-code-filter="<?= View::e($_codeStatusFilter) ?>" data-codes-base-url="<?= View::e($_codesBaseUrl ?? '') ?>">
    <div class="card-head">Current Codes
      <span class="badge badge-<?= $_approvedCount > 0 ? 'current' : 'inactive' ?>"><?= $_approvedCount ?> Approved</span>
      <?php if ($_pendingCount > 0): ?><span class="badge badge-pending"><?= $_pendingCount ?> Pending</span><?php endif; ?>
      <?php if ($_rejectedCount > 0): ?><span class="badge badge-error"><?= $_rejectedCount ?> Rejected</span><?php endif; ?>
      <?php if ($_approvedCount > 0): ?><a href="<?= $b ?>/admin/listings/<?= $_fid ?>/codes/export?from=<?= View::e($_from) ?>" class="btn btn-preview btn-xs" style="margin-left:auto"><?= View::icon('download') ?> Export Codes (ZIP)</a><?php endif; ?>
      <?php if ($_groupCount > 0): ?><span class="card-head-sub">Click and drag the top left corner to reorder codes &mdash; changes are effective immediately.</span><?php endif; ?>
    </div>

    <div class="codes-tabs">
      <a href="<?= $_tabApproved ?>" class="tabs-btn<?= $_codeStatusFilter === 'approved' ? ' active' : '' ?>" data-code-filter="approved">
        Approved <span class="badge badge-<?= $_approvedCount > 0 ? 'current' : 'inactive' ?>"><?= $_approvedCount ?></span>
      </a>
      <a href="<?= $_tabPending ?>" class="tabs-btn<?= $_codeStatusFilter === 'pending' ? ' active' : '' ?>" data-code-filter="pending">
        Pending <span class="badge badge-<?= $_pendingCount > 0 ? 'pending' : 'inactive' ?>"><?= $_pendingCount ?></span>
      </a>
      <a href="<?= $_tabRejected ?>" class="tabs-btn<?= $_codeStatusFilter === 'rejected' ? ' active' : '' ?>" data-code-filter="rejected">
        Rejected <span class="badge badge-<?= $_rejectedCount > 0 ? 'error' : 'inactive' ?>"><?= $_rejectedCount ?></span>
      </a>
    </div>

    <div class="codes-bulk">
      <label class="codes-bulk-label">
        <input type="checkbox" id="codes-select-all">Select all </label>
      <div class="select-wrap">
        <select id="bulk-action-sel" class="select-xs">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="delete">Delete</option>
          <option value="approve">Approve</option>
          <option value="reject">Reject</option>
          <?php if ($_hasCats): ?>
            <option value="categorize">Add to Categories&hellip;</option>
          <?php endif; ?>
        </select>
      </div>
      <?php if ($_hasCats): ?>
      <div id="bulk-cats-wrap" class="is-hidden">
        <select id="bulk-cats-sel" name="bulk_cat_ids[]" multiple size="<?= min(4, count($_codeCats)) ?>">
          <?php foreach ($_codeCats as $cat): ?>
            <option value="<?= (int)$cat->id ?>"><?= $cat->parentId !== null ? '  &#x2514; ' : '' ?><?= View::e($cat->name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <button type="button" id="bulk-apply-btn" class="btn btn-quaternary btn-sm">Apply to Selected</button>
      <div class="codes-bulk-right">
        <button type="button" id="save-order-btn" class="btn btn-quaternary btn-sm is-hidden">Save Order</button>
      </div>
    </div>

    <?php if ($_totalCodes === 0): ?>
      <div class="card-empty-state">
        <div class="empty">
        <div class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></div>
        <div class="empty-title">No codes yet. Use the Upload Codes panel to add some!</div>
        </div>
      </div>
    <?php else: ?>
      <?php $_filterEmptyInitial = ($_codeStatusFilter === 'approved' && $_approvedCount === 0) || ($_codeStatusFilter === 'pending' && $_pendingCount === 0) || ($_codeStatusFilter === 'rejected' && $_rejectedCount === 0); ?>
      <div class="card-empty-state<?= $_filterEmptyInitial ? '' : ' is-hidden' ?>" id="codes-filter-empty">
        <div class="empty">
          <div class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></div>
          <div class="empty-title">No codes match this filter &mdash; <a href="<?= $_tabApproved ?>" class="link" data-code-filter="approved">show approved</a>.</div>
        </div>
      </div>
      <?php foreach ($_groupedCodes as $group): ?>
        <?php if (empty($group['codes'])) continue; ?>
        <?php
          $firstCode = reset($group['codes']);
          $gridMin   = max((int)$firstCode->width + 32, 80) . 'px';
        ?>
        <div class="pc-section" data-code-group>
          <div class="pc-section-label"><?= View::e($group['label']) ?>
            <span class="badge badge-inactive" data-code-group-count data-total-count="<?= count($group['codes']) ?>"><?= count($group['codes']) ?> code<?= count($group['codes']) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="code-grid" data-code-group-grid style="grid-template-columns:repeat(auto-fill,minmax(<?= $gridMin ?>,1fr))">
          <?php foreach ($group['codes'] as $code): ?>
          <?php
            $_codeCatIdsForCode = $_codeCatIds[$code->id] ?? [];
            $_codeDonorForCode  = ($code->donorId !== null && isset($_codeDonorMap[$code->donorId])) ? $_codeDonorMap[$code->donorId] : null;
            $_codeLabelForCode  = ($code->name !== null && $code->name !== '') ? $code->name : null;
            $_isDonated         = $_codeDonorForCode !== null || $code->submitterName !== null;
          ?>
          <div class="code-tile code-card"
               data-code-status="<?= View::e($code->status) ?>"
               data-code-id="<?= (int)$code->id ?>">
            <input type="checkbox" form="codes-bulk-form" name="selected[]" value="<?= (int)$code->id ?>" class="code-tile-cb code-check">
            <span class="code-drag-handle drag-handle" title="Drag to reorder">&#8942;&#8942;</span>
            <img src="<?= View::e($uploads_url . '/' . $code->filename) ?>"
                 alt="<?= (int)$code->width ?>&times;<?= (int)$code->height ?>px"
                 class="code-tile-img" loading="lazy">
            <?php if ($_codeLabelForCode !== null): ?>
            <div class="text-muted"><?= View::e($_codeLabelForCode) ?></div>
            <?php endif; ?>
            <?php if ($_codeDonorForCode !== null): ?>
            <div class="badge badge-info2">&hearts; <?= View::e($_codeDonorForCode->name) ?></div>
            <?php elseif ($code->submitterName !== null): ?>
            <div class="badge badge-info2">&hearts; <?= View::e($code->submitterName) ?></div>
            <?php endif; ?>
            <?php if ($code->status === 'pending'): ?>
            <span class="badge badge-pending">Pending</span>
            <?php endif; ?>
            <button type="button" class="btn btn-quaternary btn-sm code-edit-btn" data-dialog="edit-dialog-<?= (int)$code->id ?>">
              Edit
            </button>
          </div>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div><!-- /codes-browser -->

</div><!-- /codes-page-layout -->
