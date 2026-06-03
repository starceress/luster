<?php

declare(strict_types=1);

use Luster\Core\NetworkDirectoryService;
use Luster\Core\View;

$b = rtrim(View::e($base_url ?? ''), '/');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/settings#panel-system" class="link">&larr; Back to Settings</a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('star') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Network Directory Status</div>
      <div class="pc-head-sub">Checks whether your active listings are registered on TFL and TAFL. Results are cached for 7 days. Click Refresh to force a live check for a single listing.</div>
    </div>
  </div>

  <?php if (empty($rows ?? [])): ?>
    <div class="pc-section">
      <p class="text-dim">No active listings found.</p>
    </div>
  <?php else: ?>
    <div class="pc-section">
      <div class="tpl-reset-row" style="margin-bottom:1rem">
        <form method="POST" action="<?= $b ?>/admin/network-directory/refresh-all">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
          <button type="submit" class="btn btn-secondary btn-sm">
            <?= View::icon('refresh') ?> Check All Listings
          </button>
        </form>
      </div>

      <table class="admin-table">
        <thead>
          <tr>
            <th>Listing</th>
            <?php foreach ($networks as $network): ?>
              <th><?= View::e(NetworkDirectoryService::networkLabel($network)) ?></th>
            <?php endforeach; ?>
            <th>Last Checked</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
            $listing  = $row['listing'];
            $statuses = $row['statuses'];

            // Determine most recent check time across all networks
            $lastChecked = null;
            foreach ($statuses as $st) {
                if ($st !== null && ($lastChecked === null || $st['checked_at'] > $lastChecked)) {
                    $lastChecked = $st['checked_at'];
                }
            }
            ?>
            <tr>
              <td>
                <a href="<?= View::e($b . '/admin/listings/' . $listing->id . '/edit') ?>" class="link">
                  <?= View::e($listing->name) ?>
                </a>
              </td>

              <?php foreach ($networks as $network): ?>
                <?php $st = $statuses[$network]; ?>
                <td>
                  <?php if ($st === null): ?>
                    <span class="badge badge-inactive">Unchecked</span>
                  <?php elseif ($st['error'] !== null): ?>
                    <span class="badge badge-error" title="<?= View::e($st['error']) ?>">Error</span>
                  <?php elseif ($st['found']): ?>
                    <span class="badge badge-current">Registered</span>
                  <?php else: ?>
                    <span class="badge badge-inactive">Not Found</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>

              <td class="text-dim-copy-sm">
                <?= $lastChecked !== null ? date('j M Y', $lastChecked) : '&mdash;' ?>
              </td>

              <td>
                <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                  <?php foreach ($networks as $network): ?>
                    <form method="POST" action="<?= $b ?>/admin/network-directory/refresh" style="display:contents">
                      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                      <input type="hidden" name="listing_id" value="<?= (int) $listing->id ?>">
                      <input type="hidden" name="network" value="<?= View::e($network) ?>">
                      <button type="submit" class="btn btn-ghost btn-sm" title="Refresh <?= View::e(NetworkDirectoryService::networkLabel($network)) ?> status">
                        <?= View::e(NetworkDirectoryService::networkLabel($network)) ?>
                      </button>
                    </form>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="callout callout-info" style="margin-top:1rem">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>
  </svg>
  <div>
    Checks search for your listing URL on each network's public search page. A "Registered" status means
    the URL was found in the search results; "Not Found" means it was not present. Results depend on
    the network's search index and may not reflect real-time approval status.
  </div>
</div>
