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
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
      No add-ons found. Place add-on directories inside <code>src/Addons/</code> to make them appear here.
    </div>
  </div>
<?php else: ?>
  <div class="addon-grid">
    <?php foreach ($discovered as $key => $addon):
      $isInstalled = isset($installed[$key]);
    ?>
    <div class="addon-card<?= $isInstalled ? ' addon-card--installed' : '' ?>">

      <div class="addon-card-header">
        <span class="addon-name"><?= \Luster\Core\View::e($addon->getName()) ?></span>
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
          <?php elseif ($key === 'Updates'): ?>
            <a href="<?= $b ?>/admin/updates" class="btn btn-primary btn-sm">Manage</a>
          <?php endif; ?>
          <form method="POST" action="<?= $b ?>/admin/addons/uninstall" class="js-uninstall-form"
                data-addon-name="<?= \Luster\Core\View::e($addon->getName()) ?>">
            <input type="hidden" name="_csrf"      value="<?= \Luster\Core\View::e($csrf_token) ?>">
            <input type="hidden" name="addon_key"  value="<?= \Luster\Core\View::e($key) ?>">
            <button type="button" class="btn btn-danger btn-sm js-uninstall-btn">Uninstall</button>
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

  <!-- Uninstall confirmation modal -->
  <div id="addon-uninstall-modal" class="neb-modal-backdrop" style="display:none" role="dialog" aria-modal="true" aria-labelledby="addon-modal-title">
    <div class="neb-modal">
      <div class="neb-modal-header">
        <h3 class="neb-modal-title" id="addon-modal-title">Uninstall Add-on</h3>
      </div>
      <div class="neb-modal-body">
        <p>You are about to uninstall <strong id="addon-modal-name"></strong>.</p>
        <div class="addon-modal-warning">
          <strong>Warning:</strong> This will permanently delete all data created by this add-on. This action cannot be undone.
        </div>
      </div>
      <div class="neb-modal-footer">
        <button type="button" class="btn btn-danger" id="addon-modal-confirm">Uninstall</button>
        <button type="button" class="btn btn-tertiary" id="addon-modal-cancel">Cancel</button>
      </div>
    </div>
  </div>
<?php endif; ?>


