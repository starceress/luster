<?php

declare(strict_types=1);

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
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Import Repair Centre</div>
      <div class="pc-head-sub">Diagnostics for the <code>lstr_listing_external_refs</code> table. Lists all stored external ID mappings from past imports, highlights listings with no mappings, and shows any FansFansFans network IDs.</div>
    </div>
  </div>

  <?php
  $summary      = $summary ?? [];
  $refsBySource = $refs_by_source ?? [];
  $allListings  = $all_listings ?? [];
  $noRefs       = $listings_no_refs ?? [];
  $fansFans     = $fansfans_refs ?? [];
  ?>

  <!-- Summary cards -->
  <div class="pc-section">
    <div class="pc-section-label">External Ref Coverage</div>
    <?php if (empty($summary)): ?>
      <p class="text-dim">No external refs found. Run an import to populate this table.</p>
    <?php else: ?>
      <div class="stats-grid stats-grid-row" style="gap:.75rem;margin-bottom:1rem">
        <?php foreach ($summary as $src => $counts): ?>
          <div class="stat-card">
            <div class="stat-header"><?= View::e(ucfirst($src)) ?></div>
            <div class="stat-value"><?= (int) $counts['count'] ?></div>
            <div class="text-dim-copy-sm"><?= (int) $counts['listings'] ?> listing(s)</div>
          </div>
        <?php endforeach; ?>
        <div class="stat-card">
          <div class="stat-header">No Refs</div>
          <div class="stat-value"><?= count($noRefs) ?></div>
          <div class="text-dim-copy-sm">listing(s) untracked</div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Listings with no external refs -->
  <?php if (!empty($noRefs)): ?>
    <div class="pc-section">
      <div class="pc-section-label">Listings With No External Refs</div>
      <p class="form-hint mb-sm">These listings were not imported from a tracked source, or the import pre-dated Phase 3. No action required — refs are created on the next import run.</p>
      <table class="admin-table">
        <thead>
          <tr><th>Listing</th><th>URL</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($noRefs as $fl): ?>
            <tr>
              <td><a href="<?= View::e($b . '/admin/listings/' . $fl->id . '/edit') ?>" class="link"><?= View::e($fl->name) ?></a></td>
              <td class="text-dim-copy-sm"><?= View::e($fl->url) ?></td>
              <td>
                <?php if ($fl->trouble): ?>
                  <span class="badge badge-inactive">Pending</span>
                <?php elseif ($fl->upcoming): ?>
                  <span class="badge badge-upcoming">Upcoming</span>
                <?php else: ?>
                  <span class="badge badge-current">Active</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- FansFansFans IDs -->
  <?php if (!empty($fansFans)): ?>
    <div class="pc-section">
      <div class="pc-section-label">FansFansFans Network IDs</div>
      <p class="form-hint mb-sm">These listings have FansFansFans IDs stored from a Fanatically import. Useful for cross-referencing with fansfansfans.com.</p>
      <table class="admin-table">
        <thead>
          <tr><th>Listing</th><th>FansFansFans ID</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($fansFans as $ref): ?>
            <?php $fl = $allListings[(int) $ref['listing_id']] ?? null; ?>
            <tr>
              <td>
                <?php if ($fl !== null): ?>
                  <a href="<?= View::e($b . '/admin/listings/' . $fl->id . '/edit') ?>" class="link"><?= View::e($fl->name) ?></a>
                <?php else: ?>
                  <span class="text-dim">ID <?= (int) $ref['listing_id'] ?> (not found)</span>
                <?php endif; ?>
              </td>
              <td><code><?= View::e($ref['external_id']) ?></code></td>
              <td>
                <form method="POST" action="<?= $b ?>/admin/import/repair/clear-ref">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="ref_id" value="<?= (int) $ref['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm btn-danger-ghost"
                          onclick="return confirm('Delete this external ref?')">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <!-- All external refs by source -->
  <?php foreach ($refsBySource as $src => $refs): ?>
    <?php if ($src === 'fansfansfans') continue; // shown above ?>
    <div class="pc-section">
      <div class="pc-section-label"><?= View::e(ucfirst($src)) ?> Refs
        <span class="badge badge-inactive" style="margin-left:.5rem"><?= count($refs) ?></span>
      </div>
      <table class="admin-table">
        <thead>
          <tr><th>Listing</th><th>External ID</th><th>Recorded</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($refs as $ref): ?>
            <?php $fl = $allListings[(int) $ref['listing_id']] ?? null; ?>
            <tr>
              <td>
                <?php if ($fl !== null): ?>
                  <a href="<?= View::e($b . '/admin/listings/' . $fl->id . '/edit') ?>" class="link"><?= View::e($fl->name) ?></a>
                <?php else: ?>
                  <span class="text-dim">ID <?= (int) $ref['listing_id'] ?> (not found)</span>
                <?php endif; ?>
              </td>
              <td><code><?= View::e($ref['external_id']) ?></code></td>
              <td class="text-dim-copy-sm">
                <?= $ref['created_at'] ? View::e(date('j M Y', strtotime((string) $ref['created_at']))) : '&mdash;' ?>
              </td>
              <td>
                <form method="POST" action="<?= $b ?>/admin/import/repair/clear-ref">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="ref_id" value="<?= (int) $ref['id'] ?>">
                  <button type="submit" class="btn btn-ghost btn-sm btn-danger-ghost"
                          onclick="return confirm('Delete this external ref?')">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:.75rem">
        <form method="POST" action="<?= $b ?>/admin/import/repair/clear-all">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
          <input type="hidden" name="source" value="<?= View::e($src) ?>">
          <button type="submit" class="btn btn-ghost btn-sm btn-danger-ghost"
                  onclick="return confirm('Delete all <?= View::e($src) ?> refs?')">
            Clear all <?= View::e(ucfirst($src)) ?> refs
          </button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

</div>
