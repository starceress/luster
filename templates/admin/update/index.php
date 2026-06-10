<?php
use Luster\Core\Version;
use Luster\Core\View;

/**
 * @var string $base_url
 * @var string $csrf_token
 * @var string $success
 * @var string $error
 * @var \Luster\Core\UpdateStatus $update_status
 * @var bool $staging_valid
 * @var string $update_version
 * @var string $release_url
 * @var string $release_title
 * @var array $preflight
 * @var bool $preflight_ok
 * @var bool $backup_downloaded
 * @var string $manual_reason
 * @var string $manual_version
 * @var string $manual_url
 * @var bool $lock_exists
 */

$b = rtrim(View::e($base_url), '/');
$current = Version::CURRENT;
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="update-flow">
  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('download') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Luster Update</div>
        <div class="pc-head-sub">
          Installed version <?= View::e($current) ?>
          <?php if (($update_status->latest ?? null) !== null): ?>
            - latest known version <?= View::e((string) $update_status->latest) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($manual_reason !== ''): ?>
      <div class="pc-section">
        <div class="callout callout-warn">
          <?= View::icon('caution') ?>
          <div>
            <strong>Manual update required.</strong>
            <?= View::e($manual_reason) ?>
            <?php if ($manual_url !== ''): ?>
              <a href="<?= View::e($manual_url) ?>" class="link" target="_blank" rel="noopener noreferrer">Open release &rarr;</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($staging_valid): ?>
      <div class="pc-section">
        <div class="pc-section-label">Prepared Package</div>
        <div class="update-stage-summary">
          <span class="badge badge-info">Version <?= View::e($update_version) ?></span>
          <span class="badge <?= $preflight_ok ? 'badge-current' : 'badge-error' ?>">
            <?= $preflight_ok ? 'Pre-flight passed' : 'Pre-flight blocked' ?>
          </span>
          <?php if ($backup_downloaded): ?>
            <span class="badge badge-info-pink">Backup downloaded</span>
          <?php endif; ?>
          <?php if ($release_url !== ''): ?>
            <a href="<?= View::e($release_url) ?>" class="link" target="_blank" rel="noopener noreferrer">Release notes &rarr;</a>
          <?php endif; ?>
        </div>
        <?php if ($release_title !== ''): ?>
          <p class="form-hint mt-sm"><?= View::e($release_title) ?></p>
        <?php endif; ?>
      </div>

      <div class="pc-section">
        <div class="pc-section-label">Pre-flight Checks</div>
        <div class="update-check-list">
          <?php foreach ($preflight as $check): ?>
            <?php $ok = ($check['ok'] ?? false) === true; ?>
            <div class="update-check-row<?= $ok ? ' is-ok' : ' is-blocked' ?>">
              <span class="update-check-icon"><?= $ok ? View::icon('check') : View::icon('caution') ?></span>
              <span class="update-check-main">
                <strong><?= View::e((string) ($check['label'] ?? 'Check')) ?></strong>
                <span><?= View::e((string) ($check['detail'] ?? '')) ?></span>
              </span>
              <span class="badge <?= $ok ? 'badge-current' : 'badge-error' ?>"><?= $ok ? 'Pass' : 'Fix' ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="pc-section">
        <div class="pc-section-label">Backup And Apply</div>
        <div class="update-action-grid">
          <form method="POST" action="<?= $b ?>/admin/update/backup" class="update-action-card">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
            <strong>Download Backup</strong>
            <span>Includes application files and Luster database tables. Member tables are not included.</span>
            <button type="submit" class="btn btn-secondary btn-sm"<?= !$preflight_ok ? ' disabled' : '' ?>>
              <?= View::icon('download') ?> Download Backup
            </button>
          </form>

          <form method="POST" action="<?= $b ?>/admin/update/apply" class="update-action-card">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
            <strong>Apply Update</strong>
            <label class="toggle-wrap update-confirm">
              <input type="checkbox" name="backup_confirmed" value="1" required<?= !$preflight_ok ? ' disabled' : '' ?>>
              <span class="toggle-label">I have saved the backup file to my computer.</span>
            </label>
            <button type="submit" class="btn btn-primary btn-sm"<?= !$preflight_ok ? ' disabled' : '' ?>>
              Apply Update
            </button>
          </form>
        </div>

        <?php if (!$preflight_ok): ?>
          <p class="form-hint form-hint--warn mt-sm">
            Refresh this page after fixing the blocked checks. Luster will re-run pre-flight checks without re-downloading the package.
          </p>
        <?php endif; ?>
      </div>

      <div class="composer-footer">
        <form method="POST" action="<?= $b ?>/admin/update/discard" class="action-form-inline">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
          <button type="submit" class="btn btn-quaternary btn-sm">Discard Prepared Update</button>
        </form>
      </div>
    <?php else: ?>
      <div class="pc-section">
        <div class="pc-section-label">Status</div>

        <?php if ($update_status->checkFailed): ?>
          <div class="callout callout-warn">
            <?= View::icon('caution') ?>
            <div>
              <strong>Update check failed.</strong>
              <?= View::e($update_status->error ?? 'GitHub could not be reached.') ?>
            </div>
          </div>
        <?php elseif ($update_status->updateAvailable): ?>
          <div class="update-ready">
            <span class="badge badge-error">Update available</span>
            <span>Version <?= View::e((string) $update_status->latest) ?> can be prepared.</span>
          </div>
        <?php elseif ($update_status->neverChecked): ?>
          <div class="update-ready">
            <span class="badge badge-inactive">Not checked</span>
            <span>No update check has been run yet.</span>
          </div>
        <?php else: ?>
          <div class="update-ready">
            <span class="badge badge-current">Up to date</span>
            <span>You are running the latest known version.</span>
          </div>
        <?php endif; ?>
      </div>

      <div class="composer-footer">
        <form method="POST" action="<?= $b ?>/admin/update/prepare" class="action-form-inline">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
          <button type="submit" class="btn btn-primary">
            <?= $update_status->updateAvailable ? 'Prepare Update' : 'Check And Prepare Update' ?>
          </button>
        </form>
        <?php if (($update_status->releaseUrl ?? null) !== null): ?>
          <a href="<?= View::e((string) $update_status->releaseUrl) ?>" class="btn btn-secondary" target="_blank" rel="noopener noreferrer">
            Open Release
          </a>
        <?php endif; ?>
        <?php if ($lock_exists): ?>
          <form method="POST" action="<?= $b ?>/admin/update/discard" class="action-form-inline">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
            <button type="submit" class="btn btn-quaternary">Clear Update Lock</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
