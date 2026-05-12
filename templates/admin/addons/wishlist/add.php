<?php
use Luster\Core\View;
/** @var \Luster\Addons\Wishlist\Wishlist|null $entry   null = add mode */
/** @var array  $old     re-population on validation failure */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b      = rtrim(View::e($base_url), '/');
$isEdit = $entry !== null;
$action = $isEdit
    ? $b . '/admin/wishlist/' . (int) $entry->id . '/edit'
    : $b . '/admin/wishlist/add';

/** Helper: returns POST repopulation value, falls back to model value, then default. */
$val = static function (string $key, mixed $default = '') use ($old, $entry): mixed {
    if (!empty($old)) {
        return $old[$key] ?? $default;
    }
    return $entry?->$key ?? $default;
};
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/wishlist" class="link">&larr; Back to Wishlist</a>
</div>

<?php if ($error !== ''): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= View::e($action) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="gradient-card">
    <div class="listing-field-section">

      <!-- Subject -->
      <div class="listing-field-row">
        <label class="listing-field-label" for="wl-subject">
          Subject <span class="req">*</span>
        </label>
        <div>
          <input id="wl-subject" type="text" name="subject" class="form" maxlength="255"
                 value="<?= View::e((string) $val('subject')) ?>" required>
          <p class="form-hint">The fanlisting subject or topic you want to join.</p>
        </div>
      </div>

      <!-- URL -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="wl-url">URL</label>
        <div>
          <input id="wl-url" type="url" name="url" class="form" maxlength="255"
                 placeholder="https://"
                 value="<?= View::e((string) $val('url')) ?>">
          <p class="form-hint">Link to the fanlisting (if it exists already).</p>
        </div>
      </div>

      <!-- Category -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="wl-category">Category</label>
        <div>
          <input id="wl-category" type="text" name="category" class="form" maxlength="100"
                 value="<?= View::e((string) $val('category')) ?>">
          <p class="form-hint">Optional grouping (e.g. Anime, Music, Actor).</p>
        </div>
      </div>

      <!-- Type -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="wl-type">Type</label>
        <div>
          <?php $currentType = (string) $val('type', 'wanted'); ?>
          <div class="select-wrap select-auto">
            <select id="wl-type" name="type">
              <option value="wanted"   <?= $currentType === 'wanted'   ? 'selected' : '' ?>>Wanted</option>
              <option value="top"      <?= $currentType === 'top'      ? 'selected' : '' ?>>Top Wanted</option>
              <option value="granted"  <?= $currentType === 'granted'  ? 'selected' : '' ?>>Granted</option>
              <option value="upcoming" <?= $currentType === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
            </select>
          </div>
          <p class="form-hint">
            <strong>Wanted</strong> — standard wishlist item.
            <strong>Top Wanted</strong> — highest priority.
            <strong>Granted</strong> — you already opened it.
            <strong>Upcoming</strong> — planning to open.
          </p>
        </div>
      </div>

      <!-- Description -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="wl-description">Description</label>
        <div>
          <textarea id="wl-description" name="description" class="form" rows="4"
                    maxlength="2000"><?= View::e((string) $val('description')) ?></textarea>
          <p class="form-hint">Optional notes (max 2000 characters).</p>
        </div>
      </div>

      <!-- Image URL -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="wl-image">Image URL</label>
        <div>
          <input id="wl-image" type="url" name="image" class="form" maxlength="255"
                 placeholder="https://"
                 value="<?= View::e((string) $val('image')) ?>">
          <p class="form-hint">Optional image link to display with this entry on your site.</p>
        </div>
      </div>

      <!-- Added Date -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="wl-added-at">Date Added</label>
        <div>
          <input id="wl-added-at" type="date" name="added_at" class="form form--compact"
                 value="<?= View::e((string) $val('added_at')) ?>">
          <p class="form-hint">Leave blank to record today's date automatically.</p>
        </div>
      </div>

      <!-- Sort Order -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="wl-sort-order">Sort Order</label>
        <div>
          <input id="wl-sort-order" type="number" name="sort_order" class="form form--compact"
                 min="0" max="9999" value="<?= (int) $val('sort_order', 0) ?>">
          <p class="form-hint">Lower numbers appear first. Entries with the same order are sorted by ID.</p>
        </div>
      </div>

      <!-- Active -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label">Visible</label>
        <div>
          <?php $isActive = !empty($old) ? ($old['active'] ?? false) : ($entry?->active ?? true); ?>
          <label class="toggle-wrap">
            <input type="checkbox" name="active" value="1" <?= $isActive ? 'checked' : '' ?>>
            <span class="toggle-label">Show on public wishlist</span>
          </label>
        </div>
      </div>

    </div><!-- /.listing-field-section -->

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">
        <?= $isEdit ? 'Save Changes' : 'Add Entry' ?>
      </button>
      <a href="<?= $b ?>/admin/wishlist" class="btn btn-ghost">Cancel</a>
      <?php if (!$isEdit): ?>
        <button type="reset" class="btn btn-ghost">Clear</button>
      <?php endif; ?>
    </div>

  </div><!-- /.gradient-card -->
</form>
