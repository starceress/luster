<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin update page. Shows the current Luster version, checks for
 available updates from GitHub releases, and walks the admin through
 the download-stage-preflight-apply workflow. Handles both automatic
 and manual update paths.
*/
use Luster\Core\Version;
use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
$current = Version::CURRENT;
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('updatesparkle') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Luster Update Wizard</div>
        <div class="panel-header-sub">This wizard will help you transition to the new Luster update automatically with just a few clicks!
        </div>
      </div>
    </div>

    <?php if ($manual_reason !== ''): ?>
      <div class="card-body">
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
      <div class="card-body">
        <div class="section-label">Prepared Package</div>
        <div class="update-stage-summary">
        <?php if ($release_title !== ''): ?>
          <h2><?= View::e($release_title) ?></h2>
        <?php endif; ?>

        <?php if ($release_url !== ''): ?>
            <p class="card-body"><a href="<?= View::e($release_url) ?>" class="btn btn-tertiary btn-sm" target="_blank" rel="noopener noreferrer">Release Notes &rarr;</a></p>
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

      <div class="card-body">
        <div class="section-label">Changes In This Update</div>
        <div class="callout callout-info">
          <?= View::icon($obsolete_count > 0 ? 'delete' : 'check') ?>
          <div>
            <strong><?= $obsolete_count ?> file<?= $obsolete_count === 1 ? '' : 's' ?> will be removed</strong>
            <?php if ($obsolete_count > 0): ?>
              No longer part of this release — old add-on files, templates, or dependencies that were dropped.
            <?php else: ?>
              Nothing to remove — every file on this install is still part of this release.
            <?php endif; ?>

            <?php if ($obsolete_count > 0): ?>
              <details class="form-details" style="margin-top:.5rem">
                <summary>View files to be removed</summary>
                <div class="form-details-body">
                  <textarea class="form form-mono" rows="8" readonly><?= View::e(implode("\n", $obsolete_paths)) ?><?php if ($obsolete_count > count($obsolete_paths)): ?>
…and <?= $obsolete_count - count($obsolete_paths) ?> more.<?php endif; ?></textarea>
                </div>
              </details>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($htaccess_status === 'kept_customized' || $htaccess_status === 'kept_customized_reviewed'): ?>
          <div class="callout <?= $htaccess_status === 'kept_customized' ? 'callout-warn' : 'callout-info' ?>" style="margin-top:1rem">
            <?= View::icon('caution') ?>
            <div>
              <strong>public/.htaccess will be left alone.</strong>
              <?php if ($htaccess_status === 'kept_customized'): ?>
                This release ships a new default public/.htaccess, but your live file has been customized, so it won't be touched automatically. Review the copy below and merge anything you need by hand.
              <?php else: ?>
                This release ships a new default public/.htaccess. Your live file is customized and you've already told Luster you've reviewed it — still left alone, just no longer flagged below.
              <?php endif; ?>
              <details class="form-details" style="margin-top:.5rem">
                <summary>Preview the new default public/.htaccess</summary>
                <div class="form-details-body">
                  <textarea class="form form-mono" rows="8" readonly><?= View::e((string) ($htaccess_preview_content ?? '')) ?></textarea>
                </div>
              </details>
              <div style="margin-top:.5rem">
                <a href="<?= $b ?>/admin/update/htaccess-new" class="btn btn-tertiary"><?= View::icon('download', ['width' => 20, 'height' => 20]) ?> Download public.htaccess.new</a>
                <?php if ($htaccess_status === 'kept_customized'): ?>
                  <form method="POST" action="<?= $b ?>/admin/update/htaccess/accept-current" class="action-form-inline" style="display:inline-block">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                    <button type="submit" class="btn btn-quaternary">I've reviewed and merged this myself</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php elseif ($htaccess_status === 'updated'): ?>
          <p class="form-hint" style="margin-top:.75rem">public/.htaccess will be updated automatically — it hasn't been customized since Luster last wrote it.</p>
        <?php endif; ?>
      </div>

      <div class="card-body">
        <div class="section-label">Pre-flight Checks</div>
        <?php if ($preflight_ok): ?>
          <details class="form-details">
            <summary style="color: var(--success)">All checks passed</summary>
            <div class="form-details-body">
              <div class="update-check-list">
                <?php foreach ($preflight as $check): ?>
                  <div class="surface update-check-row is-ok">
                    <span class="update-check-icon"><?= View::icon('check') ?></span>
                    <span class="update-check-main">
                      <strong><?= View::e((string) ($check['label'] ?? 'Check')) ?></strong>
                      <span><?= View::e((string) ($check['detail'] ?? '')) ?></span>
                    </span>
                    <span class="badge badge-current">Pass</span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </details>
        <?php else: ?>
          <?php
            $failingChecks = array_filter($preflight, static fn(array $c): bool => ($c['ok'] ?? false) !== true);
            $passingChecks = array_filter($preflight, static fn(array $c): bool => ($c['ok'] ?? false) === true);
          ?>
          <div class="update-check-list">
            <?php foreach ($failingChecks as $check): ?>
              <div class="surface update-check-row is-blocked">
                <span class="update-check-icon"><?= View::icon('caution') ?></span>
                <span class="update-check-main">
                  <strong><?= View::e((string) ($check['label'] ?? 'Check')) ?></strong>
                  <span><?= View::e((string) ($check['detail'] ?? '')) ?></span>
                </span>
                <span class="badge badge-error">Fix</span>
              </div>
            <?php endforeach; ?>
          </div>
          <p style="color: var(--warn); text-align: center; font-size: var(--fs-lg); margin-top:1rem">
            Refresh this page after fixing the blocked checks. Luster will re-run pre-flight checks without re-downloading the package.
          </p>
          <?php if ($passingChecks !== []): ?>
            <details class="form-details" style="margin-top:.75rem">
              <summary><?= count($passingChecks) ?> check<?= count($passingChecks) === 1 ? '' : 's' ?> already passing</summary>
              <div class="form-details-body">
                <div class="update-check-list">
                  <?php foreach ($passingChecks as $check): ?>
                    <div class="surface update-check-row is-ok">
                      <span class="update-check-icon"><?= View::icon('check') ?></span>
                      <span class="update-check-main">
                        <strong><?= View::e((string) ($check['label'] ?? 'Check')) ?></strong>
                        <span><?= View::e((string) ($check['detail'] ?? '')) ?></span>
                      </span>
                      <span class="badge badge-current">Pass</span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </details>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="card-body">
        <div class="section-label">Backup And Apply</div>
        <div class="update-action-grid">
          <form method="POST" action="<?= $b ?>/admin/update/backup" class="surface update-action-card">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
            <h3>Download Backup <span class="badge badge-info">Important!</span></h3>
            <span>Includes application files, Luster database tables, and all member tables.</span>
            <p class="form-hint">Save this file before applying the update. If anything goes wrong, you can restore your site to exactly how it was. Without a backup, lost data cannot be recovered.</p>
            <button type="submit" class="btn btn-primary"<?= !$preflight_ok ? ' disabled' : '' ?>>
              <?= View::icon('download') ?> Download Backup
            </button>
          </form>

          <div class="surface update-action-card">
            <form method="POST" action="<?= $b ?>/admin/update/apply">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <h3>Apply Update <span class="badge badge-error">Required</span></h3>
              <label class="toggle-wrap update-confirm">
                <input type="checkbox" name="backup_confirmed" value="1" required<?= !$preflight_ok ? ' disabled' : '' ?>>
                <span class="toggle-label">I have saved the backup file to my computer.</span>
              </label>
              <button type="submit" class="btn btn-download-fill btn-block-center"<?= !$preflight_ok ? ' disabled' : '' ?>>
                Apply Update
              </button>
            </form>
            <form method="POST" action="<?= $b ?>/admin/update/discard">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button type="submit" class="btn btn-quaternary btn-block-center">Nevermind, I'll Update Later</button>
            </form>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="card-body update-version-section">
        <div class="section-label">Version Check</div>
          <p><strong>Installed Version:</strong> <?= View::e($current) ?></p>

          <p><strong>Latest Version:</strong>
          <?php if (($update_status->latest ?? null) !== null): ?><?= View::e((string) $update_status->latest) ?><?php endif; ?>
          <?php if ($update_status->updateAvailable): ?>
            <span class="badge badge-pending">Update Available</span>
          <?php elseif ($update_status->neverChecked): ?>
            <span class="badge badge-inactive">Not Checked</span>
          <?php elseif (!$update_status->checkFailed): ?>
            <span class="badge badge-current">Up To Date</span>
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
            <span>Version <?= View::e((string) $update_status->latest) ?> can be prepared.</span>
          </div>
        <?php elseif ($update_status->neverChecked): ?>
          <div class="update-ready">
            <span>No update check has been run yet.</span>
          </div>
        <?php else: ?>
          <div class="update-ready">
            <span>You are running the latest known release.</span>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($lock_exists): ?>
      <div class="card-body">
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
