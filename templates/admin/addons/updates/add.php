<?php

declare(strict_types=1);

/**
 * Updates add-on — add / edit form.
 *
 * @var \Luster\Addons\Updates\Update|null $update           null when adding
 * @var int|null                           $fanlisting_id    null for collective
 * @var string[]                           $known_categories for datalist
 * @var string                             $back_url         absolute URL
 * @var string                             $ctx_label        context label for panel
 * @var string                             $back_label       context label for back link
 * @var bool                               $is_edit
 * @var string                             $error
 * @var string                             $base_url
 * @var string                             $csrf_token
 * @var string                             $author
 * @var string                             $admin_date_format
 * @var string                             $admin_time_format
 */

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

$defaultPostedAt = date('Y-m-d\TH:i');

$editPostedAt = '';
if ($update !== null) {
    $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $update->postedAt)
       ?: \DateTime::createFromFormat('Y-m-d H:i',   $update->postedAt);
    $editPostedAt = $dt ? $dt->format('Y-m-d\TH:i') : $defaultPostedAt;
}

$formAction = $is_edit
    ? $b . '/admin/updates/entry/' . (int) $update->id . '/edit'
    : ($fanlisting_id !== null
        ? $b . '/admin/updates/' . (int) $fanlisting_id . '/add'
        : $b . '/admin/updates/collective/add');

$isCollective = $fanlisting_id === null;

$ctxDisplay = trim((string) ($ctx_label ?? '')) ?: 'Collective';
$backLabel  = trim((string) ($back_label ?? $ctxDisplay)) ?: 'Collective';
?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <a href="<?= View::e($back_url) ?>" class="link">&larr; Back to <?= View::e($backLabel) ?> News Feed</a>
</div>

<form method="POST" action="<?= View::e($formAction) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

<!-- ──── MAIN CARD ────────────────────────────────────────────────────── -->
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('updates') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title"><?= $is_edit ? 'Edit Update' : 'Post Update' ?></div>
        </div>
        <span class="badge badge-info2 pc-head-badge"><?= View::e($ctxDisplay) ?></span>
      </div>

      <div class="pc-section">
        <div class="field-row">
          <label class="field-label" for="upd-title">Title <span class="req">*</span></label>
          <div>
            <input type="text" id="upd-title" name="title" class="form"
                   maxlength="255" required
                   value="<?= $is_edit ? View::e($update->title) : '' ?>"
                   placeholder="What's new?">
          </div>
        </div>
        <div class="field-row">
          <label class="field-label" for="upd-posted-at">Date<span class="req">*</span></label>
          <div>
            <input type="datetime-local" id="upd-posted-at" name="posted_at"
                   class="form form--auto" required
                   value="<?= $is_edit ? View::e($editPostedAt) : $defaultPostedAt ?>">
            <p class="form-hint">You can backdate or future-date entries.</p>
          </div>
        </div>
        <div class="field-row">
          <label class="field-label">Category</label>
          <div>
            <input type="hidden" id="upd-category" name="category"
                   value="<?= $is_edit ? View::e($update->category) : '' ?>">
            <div data-tag-hidden="upd-category" data-tag-chips="upd-chips" data-tag-chip-class="badge badge-inactive">
              <input type="text" class="form"
                     placeholder="Add category&hellip;"
                     list="upd-category-list">
            </div>
            <datalist id="upd-category-list">
              <?php foreach ($known_categories as $cat): ?>
                <option value="<?= View::e($cat) ?>">
              <?php endforeach; ?>
            </datalist>
            <p class="form-hint">Press Enter or comma to add. Multiple categories allowed. Start typing to see suggestions.</p>
            <div id="upd-chips" class="tag-chips-list"></div>
          </div>
        </div>

        <div class="field-group field-group--compact" style="margin-top:1.25rem">
          <textarea id="upd-body" name="body" class="form"
                    rows="9" required
                    placeholder="Write your update here&hellip;"><?= $is_edit ? View::e($update->body) : '' ?></textarea>
        </div>
      </div>

      <div class="pc-section">
        <div class="field-row">
          <label class="field-label">Posted by</label>
          <div>
            <span class="field-value"><?= View::e($is_edit ? $update->author : $author) ?></span>
            <p class="form-hint">The admin account posting this entry. Not displayed publicly &mdash; stored for record-keeping only.</p>
          </div>
        </div>
      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">
          <?= $is_edit ? 'Save Changes' : 'Post Update' ?>
        </button>
        <button type="button" class="btn btn-preview" id="upd-preview-btn"><?= View::icon('eye-on') ?> Preview</button>
        <button type="button" class="btn btn-quaternary js-form-reset"><?= $is_edit ? ' Reset to Original' : 'Clear' ?></button>
        
        <?php if ($is_edit): ?>
          <div class="composer-footer-spacer"></div>
          <form method="POST"
                action="<?= $b ?>/admin/updates/entry/<?= (int) $update->id ?>/delete"
                class="action-form-inline"
                data-del-modal
                data-del-entity="update"
                data-del-name="<?= View::e($update->title) ?>">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
            <button type="submit" class="btn btn-danger">Delete</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    
</form>

<!-- ──── PREVIEW ─────────────────────────────────────────────────────── -->
<div class="modal-backdrop is-hidden" id="upd-preview-modal"
     data-date-format="<?= View::e($admin_date_format) ?>"
     data-time-format="<?= View::e($admin_time_format) ?>">
  <div class="modal modal--wide">
    <div class="modal-header">
      <h3 class="modal-title">Update Preview</h3>
      <button type="button" class="modal-close" id="upd-preview-close" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="composer-block">
        <div class="composer-row">
          <span class="composer-lbl">Title</span>
          <span id="upd-preview-title" class="composer-name"></span>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">Date</span>
          <span id="upd-preview-date" class="composer-val"></span>
        </div>
        <div class="composer-row" id="upd-preview-cats-row">
          <span class="composer-lbl">Category</span>
          <span id="upd-preview-cats"></span>
        </div>
      </div>
      <div id="upd-preview-body" class="body-area"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-quaternary" id="upd-preview-close-btn">Close</button>
    </div>
  </div>
</div>
