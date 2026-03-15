<?php

declare(strict_types=1);

/** @var \Luster\Core\AddonInterface[] $discovered  keyed by addon_key */
/** @var array                         $installed   addon_key => true */
/** @var string                        $csrf_token */
/** @var string                        $base_url */
/** @var string                        $success */
/** @var string                        $error */

$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<?php if (empty($discovered)): ?>
  <div class="neb-card">
    <p class="neb-empty">
      No add-ons found. Place add-on directories inside
      <code>src/Addons/</code> to make them appear here.
    </p>
  </div>
<?php else: ?>
  <div class="addon-grid">
    <?php foreach ($discovered as $key => $addon):
      $isInstalled = isset($installed[$key]);
    ?>
    <div class="addon-card<?= $isInstalled ? ' addon-card--installed' : '' ?>">

      <div class="addon-card-header">
        <div class="addon-meta">
          <span class="addon-name"><?= \Luster\Core\View::e($addon->getName()) ?></span>
          <span class="addon-version">v<?= \Luster\Core\View::e($addon->getVersion()) ?></span>
        </div>
        <?php if ($isInstalled): ?>
          <span class="neb-badge badge-current">Installed</span>
        <?php else: ?>
          <span class="neb-badge badge-inactive">Not installed</span>
        <?php endif; ?>
      </div>

      <p class="addon-desc"><?= \Luster\Core\View::e($addon->getDescription()) ?></p>

      <div class="addon-actions">
        <?php if ($isInstalled): ?>
          <?php if ($key === 'Kim'): ?>
            <a href="<?= $b ?>/admin/kim" class="btn btn-primary btn-sm">Manage</a>
          <?php endif; ?>
          <form method="POST" action="<?= $b ?>/admin/addons/uninstall"
                onsubmit="return confirm('Uninstall <?= \Luster\Core\View::e(addslashes($addon->getName())) ?>? This will permanently remove all data created by this add-on.')">
            <input type="hidden" name="_csrf"      value="<?= \Luster\Core\View::e($csrf_token) ?>">
            <input type="hidden" name="addon_key"  value="<?= \Luster\Core\View::e($key) ?>">
            <button type="submit" class="btn btn-danger btn-sm">Uninstall</button>
          </form>
        <?php else: ?>
          <form method="POST" action="<?= $b ?>/admin/addons/install">
            <input type="hidden" name="_csrf"      value="<?= \Luster\Core\View::e($csrf_token) ?>">
            <input type="hidden" name="addon_key"  value="<?= \Luster\Core\View::e($key) ?>">
            <button type="submit" class="btn btn-primary btn-sm">Install</button>
          </form>
        <?php endif; ?>
      </div>

    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<style>
.addon-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 1rem;
}
.addon-card {
  background: var(--bg-card);
  border: 1px solid var(--border-soft);
  border-radius: 16px;
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: .75rem;
  box-shadow: var(--shadow);
  transition: border-color .2s, box-shadow .2s;
}
.addon-card--installed {
  border-color: var(--accent);
  box-shadow: var(--shadow), 0 0 0 1px var(--accent-glow);
}
.addon-card-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: .5rem;
}
.addon-meta {
  display: flex;
  flex-direction: column;
  gap: .2rem;
}
.addon-name {
  font-weight: 700;
  font-size: .9375rem;
  color: var(--text);
}
.addon-version {
  font-size: .75rem;
  color: var(--text-muted);
  font-family: 'DM Mono', monospace;
}
.addon-desc {
  font-size: .875rem;
  color: var(--text-muted);
  line-height: 1.5;
  margin: 0;
  flex: 1;
}
.addon-actions {
  display: flex;
  gap: .5rem;
  flex-wrap: wrap;
  margin-top: auto;
  padding-top: .25rem;
  border-top: 1px solid var(--border-soft);
}
</style>
