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
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('updatesparkle') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Luster Update Wizard</div>
        <div class="pc-head-sub">This wizard will help you transition to the new Luster update automatically with just a few clicks!
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
                      </div>
            <?php if ($manual_url !== ''): ?>
              <div><a href="<?= View::e($manual_url) ?>" class="btn btn-download" target="_blank" rel="noopener noreferrer">Download Release on GitHub &rarr;</a></div>
            <?php endif; ?>

        </div>
      </div>
    <?php endif; ?>

    <?php if ($staging_valid): ?>
      <div class="pc-section">
        <div class="pc-section-label">Prepared Package</div>
        <div class="update-stage-summary">
        <?php if ($release_title !== ''): ?>
          <h2><?= View::e($release_title) ?></h2>
        <?php endif; ?>

        <?php if ($release_url !== ''): ?>
            <p class="pc-section"><a href="<?= View::e($release_url) ?>" class="btn btn-tertiary btn-sm" target="_blank" rel="noopener noreferrer">Release Notes &rarr;</a></p>
          <?php endif; ?>

          <span class="badge badge-info">Version <?= View::e($update_version) ?></span>
          <span class="badge <?= $preflight_ok ? 'badge-current' : 'badge-error' ?>">
            <?= $preflight_ok ? 'Pre-Flight Passed' : 'Pre-flight blocked' ?>
          </span>
          <?php if ($backup_downloaded): ?>
            <span class="badge badge-info2">Backup Downloaded</span>
          <?php endif; ?>
          
        </div>
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

          
        <?php if (!$preflight_ok): ?>
          <p style="color: var(--warn); text-align: center; font-size: var(--fs-lg);" class="mt-md">
            Refresh this page after fixing the blocked checks. Luster will re-run pre-flight checks without re-downloading the package.
          </p>
        <?php endif; ?>
        </div>
      </div>

      <div class="pc-section">
        <div class="pc-section-label">Backup And Apply</div>
        <div class="update-action-grid">
          <form method="POST" action="<?= $b ?>/admin/update/backup" class="update-action-card">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
            <h3>Download Backup <span class="badge badge-info">Important!</badge></h3>
            <span>Includes application files, Luster database tables, and all member tables.</span>
            <p class="form-hint">Save this file before applying the update. If anything goes wrong, you can restore your site to exactly how it was. Without a backup, lost data cannot be recovered.</p>
            <button type="submit" class="btn btn-primary"<?= !$preflight_ok ? ' disabled' : '' ?>>
              <?= View::icon('download') ?> Download Backup
            </button>
          </form>

          <form method="POST" action="<?= $b ?>/admin/update/apply" class="update-action-card">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
            <h3>Apply Update <span class="badge badge-error">Required</badge></h3>
            <label class="toggle-wrap update-confirm">
              <input type="checkbox" name="backup_confirmed" value="1" required<?= !$preflight_ok ? ' disabled' : '' ?>>
              <span class="toggle-label">I have saved the backup file to my computer.</span>
            </label>
            <button type="submit" class="btn btn-download-fill"<?= !$preflight_ok ? ' disabled' : '' ?>>
              Apply Update
            </button>
          </form>
        </div>
      </div>

      <div class="composer-footer">
        <form method="POST" action="<?= $b ?>/admin/update/discard" class="action-form-inline">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
          <button type="submit" class="btn btn-quaternary btn-block-center">Nevermind, I'll Update Later</button>
        </form>
      </div>
    <?php else: ?>
      <div class="pc-section update-version-section">
        <div class="pc-section-label">Version Check</div>
          <p><strong>Installed Version:</strong> <?= View::e($current) ?></p>

          <p><strong>Latest Version:</strong>
          <?php if (($update_status->latest ?? null) !== null): ?><?= View::e((string) $update_status->latest) ?>
          <?php endif; ?>
          </p>
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
            <span class="badge badge-pending">Update Available</span>
            <span>Version <?= View::e((string) $update_status->latest) ?> can be prepared.</span>
          </div>
        <?php elseif ($update_status->neverChecked): ?>
          <div class="update-ready">
            <span class="badge badge-inactive">Not Checked</span>
            <span>No update check has been run yet.</span>
          </div>
        <?php else: ?>
          <div class="update-ready">
            <span class="badge badge-current">Up To Date</span>
            <span>You are running the latest known release.</span>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($lock_exists): ?>
      <div class="pc-section">
        <div class="callout callout-warn">
          <?= View::icon('caution') ?>
          <div>
            <strong>Update lock detected.</strong>
            A previous update was interrupted before it could finish &mdash; this can happen if the server timed out or the browser was closed mid-update.
            The lock file prevents a new update from starting until it is cleared. &nbsp; <form method="POST" action="<?= $b ?>/admin/update/discard" class="action-form-inline" style="margin-top:.75rem">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button type="submit" class="btn btn-sm btn-tertiary">Clear Update Lock &rarr;</button>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="composer-footer" style="justify-content:center">
        <form method="POST" action="<?= $b ?>/admin/update/prepare" class="action-form-inline">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
          <button type="submit" class="btn btn-primary">
            <?= $update_status->updateAvailable ? 'Prepare Update' : 'Check And Prepare Update' ?>
          </button>
        </form>
        <?php if (($update_status->releaseUrl ?? null) !== null): ?>
          <a href="<?= View::e((string) $update_status->releaseUrl) ?>" class="btn btn-secondary" target="_blank" rel="noopener noreferrer">
            View on GitHub
          </a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
