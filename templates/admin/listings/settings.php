<?php
use Luster\Core\View;
/**
 * Admin - Owned Listings display settings.
 *
 * @var array  $cfg
 * @var array  $tracking  Site-settings keys: stale_days, overdue_notify_enabled, overdue_days, overdue_notify_last_sent
 * @var string $success
 * @var string $error
 * @var string $base_url
 * @var string $csrf_token
 */
$b = rtrim(View::e($base_url), '/');
function flsCfgVal(array $cfg, string $key, $default = ''): string {
    return (string) ($cfg[$key] ?? $default);
}
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings" class="link">
    &larr; Back to Owned Listings
  </a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/listings/settings" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('gear') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Display Settings</div>
        <div class="pc-head-sub">Choose how owned listings are shown in the admin area and when Luster reminds you to update them.</div>
      </div>
    </div>

    <div class="pc-section">
      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-sort">Sort listings by</label>
        <div>
          <div class="select-wrap select-auto">
            <select id="fl-sort" name="owned_sort" >
              <?php
                $currentSort = flsCfgVal($cfg, 'owned_sort', 'id');
                $sortOptions = [
                    'id'       => 'Listing #',
                    'subject'  => 'Subject (A-Z)',
                    'category' => 'Category (A-Z)',
                    'status'   => 'Status (Current -> Upcoming -> Pending)',
                ];
                foreach ($sortOptions as $val => $lbl):
              ?>
                <option value="<?= View::e($val) ?>" <?= $currentSort === $val ? 'selected' : '' ?>>
                  <?= View::e($lbl) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="pc-section">
      <div class="listing-field-row">
        <label class="listing-field-label" for="fl-per-page">Listings per page</label>
        <div>
          <input type="number" id="fl-per-page" name="owned_per_page" class="form form--compact" min="5" max="50" value="<?= (int) ($cfg['owned_per_page'] ?? 10) ?>">
          <p class="form-hint">How many listings to show on each admin page. Choose 5&ndash;50.</p>
        </div>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Listing Update Tracking</div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="s-stale-days">Dashboard stale warning (days)</label>
        <div>
          <input id="s-stale-days" type="number" name="stale_days" class="form form--compact"
                 min="1" max="365"
                 value="<?= View::e($tracking['stale_days'] ?? '45') ?>">
          <p class="form-hint">Show a dashboard warning when this many days pass without marking a listing updated. Default: 45.</p>
        </div>
      </div>

      <div class="listing-field-row" style="margin-top:.75rem">
        <label class="listing-field-label">Overdue email</label>
        <div>
          <label class="toggle-wrap">
            <input type="checkbox" name="overdue_notify_enabled" value="1"
                   <?= ($tracking['overdue_notify_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
            <span class="toggle-label">Email me when listings haven't been updated in N days</span>
          </label>
          <p class="form-hint">Sends one reminder email per day at most, listing any fanlistings that need an update.</p>
        </div>
      </div>

      <div class="listing-field-row" style="margin-top:.75rem">
        <label class="listing-field-label" for="s-overdue-days">Days until overdue email</label>
        <div>
          <input id="s-overdue-days" type="number" name="overdue_days" class="form form--compact"
                 min="1" max="365"
                 value="<?= View::e($tracking['overdue_days'] ?? '55') ?>">
          <p class="form-hint">Send the reminder when this many days pass without an update. Default: 55. TFL guidelines recommend 55 days.</p>
        </div>
      </div>

      <?php
        $lastSentTs      = (int) ($tracking['overdue_notify_last_sent'] ?? '0');
        $lastSentDisplay = $lastSentTs > 0 ? date('j M Y \a\t H:i', $lastSentTs) : 'Never';
      ?>
      <div class="listing-field-row" style="margin-top:.75rem">
        <label class="listing-field-label">Last notification</label>
        <div><p class="form-hint">Last notification sent: <strong><?= View::e($lastSentDisplay) ?></strong></p></div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Settings</button>
      <button type="button" class="btn btn-quaternary js-form-reset">Reset to Default</button>
      <a href="<?= $b ?>/admin/listings" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
