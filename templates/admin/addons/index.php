<?php

declare(strict_types=1);

use Luster\Core\View;

/** @var \Luster\Core\AddonInterface[] $discovered  keyed by addon_key */
/** @var array                         $installed   addon_key => true */
/** @var string                        $csrf_token */
/** @var string                        $base_url */
/** @var string                        $success */
/** @var string                        $error */

$b = rtrim(View::e($base_url), '/');
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<?php if (empty($discovered)): ?>
  <div class="card">
    <div class="empty"><span class="empty-icon"><?= View::icon('addon') ?></span>
      No add-ons found. Place add-on directories inside <code>src/Addons/</code> to make them appear here.
    </div>
  </div>
<?php else: ?>
  <div class="addon-grid">
    <?php foreach ($discovered as $key => $addon):
      $isInstalled = isset($installed[$key]);
    ?>
    <div class="import-source-card<?= $isInstalled ? ' addon-card--installed' : '' ?>">

      <div class="import-source-header">
        <span class="pc-head-title"><?= View::e($addon->getName()) ?></span>
        <?php if ($isInstalled): ?>
          <span class="badge badge-current">Installed</span>
        <?php else: ?>
          <span class="badge badge-inactive">Not installed</span>
        <?php endif; ?>
      </div>

      <p class="addon-card-desc"><?= View::e($addon->getDescription()) ?></p>

      <div class="import-source-note">
        <div class="addon-card-actions">
          <?php if ($isInstalled): ?>
            <?php if ($key === 'Kim'): ?>
              <a href="<?= $b ?>/admin/kim" class="btn btn-primary btn-sm">Manage</a>
            <?php elseif ($key === 'Updates'): ?>
              <a href="<?= $b ?>/admin/updates" class="btn btn-primary btn-sm">Manage</a>
            <?php elseif ($key === 'Wishlist'): ?>
              <a href="<?= $b ?>/admin/wishlist" class="btn btn-primary btn-sm">Manage</a>
            <?php elseif ($key === 'Adoption'): ?>
              <a href="<?= $b ?>/admin/adoptions" class="btn btn-primary btn-sm">Manage</a>
            <?php elseif ($key === 'Rules'): ?>
              <a href="<?= $b ?>/admin/rules" class="btn btn-primary btn-sm">Manage</a>
            <?php endif; ?>
            <form method="POST" action="<?= $b ?>/admin/addons/uninstall" class="js-uninstall-form"
                  data-addon-name="<?= View::e($addon->getName()) ?>">
              <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
              <input type="hidden" name="addon_key"  value="<?= View::e($key) ?>">
              <button type="button" class="btn btn-danger btn-sm js-uninstall-btn">Disable</button>
            </form>
          <?php else: ?>
            <form method="POST" action="<?= $b ?>/admin/addons/install">
              <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
              <input type="hidden" name="addon_key"  value="<?= View::e($key) ?>">
              <button type="submit" class="btn btn-primary btn-sm">Enable</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

    </div>
    <?php endforeach; ?>
  </div>

  <div id="addon-uninstall-modal" class="modal-backdrop is-hidden" role="dialog" aria-modal="true" aria-labelledby="addon-modal-title">
    <div class="modal">
      <div class="modal-header">
        <h3 class="modal-title" id="addon-modal-title">Disable Add-on</h3>
        <button type="button" class="modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <p>You are about to disable <strong id="addon-modal-name"></strong>.</p>
        <div class="callout callout-warn" style="margin-top:.5rem">The add-on will be removed from the admin panel and its public routes will stop working. All data is kept &mdash; reinstalling it later will restore full access.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-danger" id="addon-modal-confirm">Disable</button>
        <button type="button" class="btn btn-quaternary" id="addon-modal-cancel">Cancel</button>
      </div>
    </div>
  </div>
<?php endif; ?>
