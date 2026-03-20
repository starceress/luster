<?php

declare(strict_types=1);

/**
 * Updates add-on — add / edit form.
 *
 * @var \Luster\Addons\Updates\Update|null $update           null when adding
 * @var int|null                           $fanlisting_id    null for collective
 * @var string[]                           $known_categories for <datalist>
 * @var string                             $back_url         absolute URL
 * @var bool                               $is_edit
 * @var string                             $error
 * @var string                             $base_url
 * @var string                             $csrf_token
 */

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

// Default posted_at to current datetime in the browser's local format
$defaultPostedAt = date('Y-m-d\TH:i');

// For edit, convert stored datetime (Y-m-d H:i:s) to the datetime-local format
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
?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<p style="margin-bottom:1.25rem">
  <a href="<?= View::e($back_url) ?>" class="neb-link">&larr; Back</a>
</p>

<div class="neb-card" style="padding:1.5rem;max-width:760px">
  <form method="POST" action="<?= View::e($formAction) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <!-- Title -->
    <div class="fg" style="margin-bottom:1rem">
      <label for="upd-title">
        Title <span class="required-star">*</span>
      </label>
      <input type="text" id="upd-title" name="title"
             maxlength="255" required
             value="<?= $is_edit ? View::e($update->title) : '' ?>">
    </div>

    <!-- Author (read-only on edit) -->
    <?php if ($is_edit && $update->author !== ''): ?>
    <div class="fg" style="margin-bottom:1rem">
      <label>Author</label>
      <p class="form-hint" style="margin:.25rem 0 0;font-size:.9rem;color:var(--text-muted)">
        <?= View::e($update->author) ?>
        <span style="font-size:.8rem;color:var(--text-dim);margin-left:.25rem">(set at post time, not editable)</span>
      </p>
    </div>
    <?php endif; ?>

    <!-- Posted At -->
    <div class="fg" style="margin-bottom:1rem">
      <label for="upd-posted-at">
        Date &amp; Time <span class="required-star">*</span>
      </label>
      <input type="datetime-local" id="upd-posted-at" name="posted_at"
             required
             value="<?= $is_edit ? View::e($editPostedAt) : $defaultPostedAt ?>">
      <p class="form-hint">You can backdate or future-date entries.</p>
    </div>

    <!-- Category -->
    <div class="fg" style="margin-bottom:1rem">
      <label for="upd-category">Category</label>
      <input type="text" id="upd-category" name="category"
             maxlength="500" list="upd-category-list"
             placeholder="e.g. Site News, Fanlisting Update"
             value="<?= $is_edit ? View::e($update->category) : '' ?>">
      <datalist id="upd-category-list">
        <?php foreach ($known_categories as $cat): ?>
          <option value="<?= View::e($cat) ?>">
        <?php endforeach; ?>
      </datalist>
      <p class="form-hint">Separate multiple categories with commas.</p>
    </div>

    <!-- Body -->
    <div class="fg" style="margin-bottom:1.5rem">
      <label for="upd-body">
        Body <span class="required-star">*</span>
      </label>
      <textarea id="upd-body" name="body" rows="10" required
                style="font-family:inherit"><?= $is_edit ? View::e($update->body) : '' ?></textarea>
    </div>

    <div style="display:flex;gap:.75rem;align-items:center">
      <button type="submit" class="btn btn-primary">
        <?= $is_edit ? 'Save Changes' : 'Post Update' ?>
      </button>
      <a href="<?= View::e($back_url) ?>" class="btn btn-secondary">Cancel</a>
    </div>

  </form>
</div>
