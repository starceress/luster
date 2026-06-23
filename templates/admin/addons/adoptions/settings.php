<?php

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/adoptions" class="link">&larr; Back to Adoptions</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card card--gradient">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('gear') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Display Settings</div>
      <div class="pc-head-sub">Configure the default view and pagination for the Adoptions admin panel.</div>
    </div>
  </div>
  <form method="POST" action="<?= $b ?>/admin/adoptions/settings">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div class="card-body">
      <div class="field-row field-row--top">
        <label class="field-label">Default View</label>
        <div>
          <div class="radio-group">
            <label class="radio-label">
              <input type="radio" name="adoption_default_landing" value="applications"
                     <?= ($adoption_default_landing ?? 'applications') === 'applications' ? 'checked' : '' ?>> Applications View
            </label>
            <label class="radio-label">
              <input type="radio" name="adoption_default_landing" value="listings"
                     <?= ($adoption_default_landing ?? 'applications') === 'listings' ? 'checked' : '' ?>> Listing View
            </label>
          </div>
          <p class="form-hint">Which view opens when you click Adoptions in the sidebar.</p>
        </div>
      </div>
      <div class="field-row">
        <label class="field-label" for="adopt-per-page">Entries Per Page</label>
        <div>
          <input id="adopt-per-page" type="number" name="adoption_per_page"
                 class="form form--compact" min="5" max="25"
                 value="<?= (int) ($adoption_per_page ?? 10) ?>">
          <p class="form-hint">How many applications to show per page. Min 5, max 25, default 10.</p>
        </div>
      </div>
    </div>
    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>
  </form>
</div>
