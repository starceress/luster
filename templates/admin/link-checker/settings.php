<?php
declare(strict_types=1);
use Luster\Core\View;
/** @var string $lc_default_sort */
/** @var int    $lc_per_page */
/** @var bool   $cron_enabled */
/** @var string $cron_token */
/** @var string $cron_last_run */
/** @var string $cron_url */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/link-checker" class="link">&larr; Back to Link Checker</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ── Display Settings ───────────────────────────────────────────────────── -->
<form method="POST" action="<?= $b ?>/admin/link-checker/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('gear') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Display Settings</div>
        <div class="pc-head-sub">Controls the default sort order and page size for the Link Checker tables.</div>
      </div>
    </div>

    <div class="card-body">
      <div class="listing-field-row">
        <label class="listing-field-label" for="lc_default_sort">Default Sort</label>
        <div>
          <div class="select-wrap select-auto">
            <select id="lc_default_sort" name="lc_default_sort">
              <option value="name"<?=        $lc_default_sort === 'name'        ? ' selected' : '' ?>>Name</option>
              <option value="link_status"<?= $lc_default_sort === 'link_status' ? ' selected' : '' ?>>Status (OK, Dead…)</option>
            </select>
          </div>
          <p class="form-hint">The default column used to sort both the Affiliates and Joined Listings tables when no column header has been clicked.</p>
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="lc_per_page">Items Per Page</label>
        <div>
          <input type="number" id="lc_per_page" name="lc_per_page" class="form form--compact"
                 min="5" max="100" value="<?= (int) $lc_per_page ?>">
          <p class="form-hint">How many rows to show per page in the Affiliates and Joined Listings tables. Min 5, max 100.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <button type="submit" name="reset_defaults" value="1" class="btn btn-quaternary">Reset to Default</button>
    </div>
  </div>
</form>

<!-- ── Reset Link Statuses ────────────────────────────────────────────────── -->
<details class="card chevron" style="margin-top:var(--sp-6)">
  <summary class="card-head">Reset Link Statuses
    <div class="card-head-sub">Clears all stored link check results.</div>
  </summary>
  <div class="card-body">
    <p>Clicking <strong>Reset All Link Statuses</strong> sets all link statuses back to <i>Unchecked</i>, as if the checker had never been run.<br><br>
      <strong>When would you use this?</strong> Mostly when something has changed in bulk — for example, you migrated to a new domain, imported a fresh set of affiliates, debugging purposes, or for starting from a clean slate. Under normal day-to-day use you wouldn't need it at all.</p>
  </div>
  <form method="POST" action="<?= $b ?>/admin/link-checker/reset-all">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div class="composer-footer">
      <button type="submit" class="btn btn-danger">Reset All Link Statuses</button>
    </div>
  </form>
</details>

<!-- ── Auto Link Check ────────────────────────────────────────────────────── -->
<details class="card chevron" style="margin-top:var(--sp-6)" <?= $cron_enabled ? 'open' : '' ?>>
  <summary class="card-head">Auto Link Check
    <div class="card-head-sub">For technical users: set up a server cron job to run link checks automatically once per month.</div>
  </summary>
  <form method="POST" action="<?= $b ?>/admin/link-checker/save-cron-settings">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <?php
        $cronLastTs = $cron_last_run !== '' ? (int) strtotime($cron_last_run) : null;
        $cronIsDue  = $cronLastTs !== null && ($cronLastTs + 30 * 24 * 3600) <= time();
      ?>
      <div class="listing-field-section">
        <div class="listing-field-row">
          <label class="listing-field-label">Last Run</label>
          <div>
            <?php if ($cronLastTs === null): ?>N/A
            <?php else: ?>
              <?= View::e(date($admin_date_format, $cronLastTs)) ?>
              <?php if ($cronIsDue): ?>
                &nbsp;<span class="badge badge-redirect">Due now</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="listing-field-row">
          <label class="listing-field-label" for="lc-auto-enable">Enable Auto-Check</label>
          <div>
            <label class="toggle-wrap">
              <input type="checkbox" id="lc-auto-enable" name="auto_link_check_enabled"
                     value="1" class="toggle" <?= $cron_enabled ? 'checked' : '' ?>>
              <span class="toggle-label">Run link checks automatically once per month</span>
            </label>
            <p class="form-hint">The script only runs when 30 days have elapsed since the last check.</p>
          </div>
        </div>
        <div class="listing-field-row">
          <label class="listing-field-label">Cron URL</label>
          <div>
            <div class="ro-field embed-snippet"><?= View::e($cron_url) ?></div>
            <p class="form-hint">Call this URL from your cron manager once daily. Keep the token secret.</p>
          </div>
        </div>
      </div>
    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <button type="submit" name="regenerate_token" value="1" class="btn btn-secondary">Regenerate Token</button>
    </div>
  </form>
</details>
