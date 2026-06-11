<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var array<int,array{listing_id:int,listing_title:string,listing_subject:string,rules_id:int|null,rules_enabled:bool|null,rules_version:int|null,rules_updated_at:string|null}> $rows
 * @var string $success
 * @var string $error
 * @var string $base_url
 * @var string $csrf_token
 */

$b = rtrim(View::e($base_url), '/');
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <div class="actions-row actions-row--right">
    <p class="form-hint" style="margin:0">Rules are per-listing. Click <strong>Edit Rules</strong> to create or update rules for a listing.</p>
  </div>
</div>

<?php if (empty($rows)): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('rules', ['width' => 32, 'height' => 32]) ?></div>
    <div class="empty-title">No listings found.</div>
    <p>Add a listing first, then come back here to write join rules for it.</p>
  </div>
</div>
<?php else: ?>
<div class="card">
  <div class="card-head">All Listings
    <span class="row-sub"><?= count($rows) ?> <?= count($rows) === 1 ? 'listing' : 'listings' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Listing</th>
          <th>Status</th>
          <th>Version</th>
          <th>Last Updated</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
        <tr>
          <td data-label="Listing">
            <span class="text-strong"><?= View::e($row['listing_subject']) ?></span>
          </td>

          <td data-label="Status">
            <?php if ($row['rules_id'] === null): ?>
              <span class="badge badge-inactive">No rules</span>
            <?php elseif ($row['rules_enabled']): ?>
              <span class="badge badge-current">Enabled</span>
            <?php else: ?>
              <span class="badge badge-pending">Disabled</span>
            <?php endif; ?>
          </td>

          <td data-label="Version" class="text-muted">
            <?= $row['rules_version'] !== null ? 'v' . (int) $row['rules_version'] : '<span class="text-dim">&mdash;</span>' ?>
          </td>

          <td data-label="Last Updated" class="text-muted">
            <?php if ($row['rules_updated_at'] !== null): ?>
              <?= View::e(date('Y-m-d', strtotime($row['rules_updated_at']))) ?>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>

          <td class="act-col" data-label="">
            <div class="data-actions">
              <a href="<?= $b ?>/admin/rules/<?= (int) $row['listing_id'] ?>/edit"
                 class="btn btn-tertiary btn-xs">Edit Rules</a>

              <?php if ($row['rules_id'] !== null): ?>
                <form method="POST"
                      action="<?= $b ?>/admin/rules/<?= (int) $row['listing_id'] ?>/toggle">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <button type="submit" class="btn btn-quaternary btn-xs">
                    <?= $row['rules_enabled'] ? 'Disable' : 'Enable' ?>
                  </button>
                </form>
                <button type="button"
                        class="btn btn-danger btn-xs js-rules-del-btn"
                        data-listing-title="<?= View::e($row['listing_subject']) ?>"
                        data-form-id="del-rules-<?= (int) $row['listing_id'] ?>">
                  Delete
                </button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($rows as $row): if ($row['rules_id'] === null) continue; ?>
<form id="del-rules-<?= (int) $row['listing_id'] ?>"
      method="POST"
      action="<?= $b ?>/admin/rules/<?= (int) $row['listing_id'] ?>/delete">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
</form>
<?php endforeach; ?>

<div id="rules-del-modal" class="modal-backdrop is-hidden" role="dialog" aria-modal="true" aria-labelledby="rules-modal-title">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="rules-modal-title">Delete Rules</h3>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <p>Delete rules for <strong id="rules-modal-name"></strong>?</p>
      <div class="modal-warning">
        <strong>Warning:</strong> This permanently removes the rules text. This action cannot be undone.
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-danger" id="rules-modal-confirm">Delete</button>
      <button type="button" class="btn btn-quaternary" id="rules-modal-cancel">Cancel</button>
    </div>
  </div>
</div>

<script>
(function () {
  var modal   = document.getElementById('rules-del-modal');
  var nameEl  = document.getElementById('rules-modal-name');
  var confirm = document.getElementById('rules-modal-confirm');
  var cancel  = document.getElementById('rules-modal-cancel');
  var close   = modal.querySelector('.modal-close');
  var pendingForm = null;

  function openModal(listingTitle, formId) {
    pendingForm = document.getElementById(formId);
    nameEl.textContent = listingTitle;
    modal.classList.remove('is-hidden');
    confirm.focus();
  }
  function closeModal() {
    modal.classList.add('is-hidden');
    pendingForm = null;
  }

  document.querySelectorAll('.js-rules-del-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      openModal(btn.dataset.listingTitle, btn.dataset.formId);
    });
  });
  confirm.addEventListener('click', function () { if (pendingForm) pendingForm.submit(); });
  cancel.addEventListener('click', closeModal);
  close.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
}());
</script>
<?php endif; ?>
