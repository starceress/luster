<?php

declare(strict_types=1);

/** @var string $display_mode */
/** @var int    $per_page */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<p style="margin-bottom:1.25rem">
  <a href="<?= $b ?>/admin/joined" class="neb-link">&larr; Back to Joined FLs</a>
</p>

<div class="neb-card" style="max-width:520px;overflow:hidden">
  <div class="neb-card-header">Display Preferences</div>
  <div style="padding:1.25rem">
    <form method="POST" action="<?= $b ?>/admin/joined/settings">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

      <div class="form-group">
        <label class="form-label" for="display_mode">Sort Listings By</label>
        <div class="select-wrap">
          <select id="display_mode" name="display_mode" class="form-select">
            <option value="all"<?= ($display_mode ?? 'all') === 'all' ? ' selected' : '' ?>>Show All</option>
            <option value="network"<?= ($display_mode ?? 'all') === 'network' ? ' selected' : '' ?>>Group by Network</option>
            <option value="category"<?= ($display_mode ?? 'all') === 'category' ? ' selected' : '' ?>>Group by Category</option>
            <option value="status"<?= ($display_mode ?? 'all') === 'status' ? ' selected' : '' ?>>Group by Status</option>
          </select>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="per_page">Items Per Page</label>
        <div class="select-wrap">
          <select id="per_page" name="per_page" class="form-select">
            <?php foreach ([5, 10, 25, 50] as $n): ?>
              <option value="<?= $n ?>"<?= (int) ($per_page ?? 10) === $n ? ' selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="section-divider"></div>
      <div style="display:flex;gap:.75rem;align-items:center">
        <button type="submit" class="btn btn-primary">Save Settings</button>
        <a href="<?= $b ?>/admin/joined" class="btn btn-ghost">Cancel</a>
      </div>
    </form>
  </div>
</div>
