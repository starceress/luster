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
 * @var bool                               $is_edit
 * @var string                             $error
 * @var string                             $base_url
 * @var string                             $csrf_token
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

// Strip "Owned › " prefix for display; leave "Collective" as-is
$ctxDisplay = preg_replace('/^Owned\s*›\s*/u', '', (string) ($ctx_label ?? '')) ?: 'Collective';
?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="page-topbar" style="margin-bottom:1.25rem">
  <a href="<?= View::e($back_url) ?>" class="back-link link">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
    Back
  </a>
  <?php if ($is_edit): ?>
  <div class="topbar-actions">
    <form method="POST"
          action="<?= $b ?>/admin/updates/entry/<?= (int) $update->id ?>/delete"
          data-confirm="Delete this update? This cannot be undone.">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <button type="submit" class="btn btn-danger btn-sm">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
        Delete Update
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>

<form method="POST" action="<?= View::e($formAction) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="edit-layout">

    <!-- ── Main card: title + body ── -->
    <div class="main-card">
      <div class="card-section">
        <span class="neb-section-label">Content</span>
        <div class="form-group">
          <label class="form-label" for="upd-title">
            Title <span class="req">*</span>
          </label>
          <input type="text" id="upd-title" name="title" class="form-input"
                 maxlength="255" required
                 value="<?= $is_edit ? View::e($update->title) : '' ?>"
                 placeholder="What&rsquo;s new?">
        </div>
        <div class="form-group" style="margin-bottom:0">
          <label class="form-label" for="upd-body">
            Body <span class="req">*</span>
          </label>
          <textarea id="upd-body" name="body" class="body-textarea-large" required
                    placeholder="Write your update here&hellip;"><?= $is_edit ? View::e($update->body) : '' ?></textarea>
        </div>
      </div>
    </div>

    <!-- ── Panel column ── -->
    <div class="panel-col">

      <!-- Publish panel -->
      <div class="panel-card">
        <div class="card-head">
          <div class="card-head-title">Publish</div>
        </div>
        <div class="panel-body">
          <div class="panel-field">
            <label class="panel-label" for="upd-posted-at">
              Date &amp; Time <span style="color:var(--danger);margin-left:2px">*</span>
            </label>
            <input type="datetime-local" id="upd-posted-at" name="posted_at"
                   class="panel-input" required
                   value="<?= $is_edit ? View::e($editPostedAt) : $defaultPostedAt ?>">
            <div class="panel-hint">You can backdate or future-date entries.</div>
          </div>
          <button type="submit" class="btn-publish" style="margin-top:.75rem">
            <?php if ($is_edit): ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
              Save Changes
            <?php else: ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              Post Update
            <?php endif; ?>
          </button>
          <a href="<?= View::e($back_url) ?>"
             class="btn btn-ghost" style="width:100%;justify-content:center;margin-top:.375rem;font-size:.8rem">
            Cancel
          </a>
        </div>
      </div>

      <!-- Categories panel -->
      <div class="panel-card">
        <div class="card-head">
          <div class="card-head-title">Categories</div>
        </div>
        <div class="panel-body">
          <input type="hidden" id="upd-category" name="category"
                 value="<?= $is_edit ? View::e($update->category) : '' ?>">
          <div class="tag-input-wrap" data-tag-hidden="upd-category">
            <input type="text" class="tag-input-field"
                   placeholder="Add category&hellip;"
                   list="upd-category-list">
          </div>
          <datalist id="upd-category-list">
            <?php foreach ($known_categories as $cat): ?>
              <option value="<?= View::e($cat) ?>">
            <?php endforeach; ?>
          </datalist>
          <div class="panel-hint" style="margin-top:.45rem">Press Enter or comma to add. Multiple categories allowed.</div>
        </div>
      </div>

      <!-- Context panel -->
      <div class="panel-card">
        <div class="card-head">
          <div class="card-head-title">Context</div>
        </div>
        <div class="panel-body">
          <div class="panel-field">
            <div style="display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;background:var(--bg-subtle);border:1px solid var(--border);border-radius:8px">
              <?php if ($isCollective): ?>
                <span class="badge badge-info" style="flex-shrink:0">Collective</span>
                <span style="font-size:.825rem;font-weight:600;color:var(--text)">Site-wide</span>
              <?php else: ?>
                <span style="font-size:.825rem;font-weight:600;color:var(--text)"><?= View::e($ctxDisplay) ?></span>
              <?php endif; ?>
            </div>
            <div class="panel-hint" style="margin-top:.4rem">
              <?= $isCollective ? 'This update will appear in the collective feed.' : 'This update will appear in this listing\'s feed.' ?>
            </div>
          </div>
          <?php if ($is_edit && $update->author !== ''): ?>
          <div class="panel-field" style="border-top:1px solid var(--border-soft);padding-top:.625rem;margin-top:.25rem">
            <label class="panel-label">Posted by</label>
            <span style="font-size:.825rem;color:var(--text)"><?= View::e($update->author) ?></span>
            <div class="panel-hint">Set at post time, not editable.</div>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /.panel-col -->
  </div><!-- /.edit-layout -->
</form>
