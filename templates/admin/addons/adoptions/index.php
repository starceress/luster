<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Adoptions admin — tabbed overview.
 *
 * @var string   $tab              'pending' | 'listings' | 'settings'
 * @var array[]  $pending          (tab=pending) application rows from lstr_adoption_applications
 * @var array    $listing_map      (tab=pending) [id => Listing]
 * @var array[]  $adoption_listings (tab=listings) each: ['listing'=>Listing, 'count_new'=>int, 'count_approved'=>int, 'count_all'=>int]
 * @var string   $success
 * @var string   $error
 * @var string   $base_url
 * @var string   $csrf_token
 */

$b = rtrim(View::e($base_url), '/');
$pendingTotal = isset($pending) ? count($pending) : 0;
?>

<?php if ($success !== ''): ?>
<div class="alert alert-success">
  <?= View::icon('check') ?><div><?= View::e($success) ?></div>
</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="alert alert-error">
  <?= View::icon('warning') ?><div><?= View::e($error) ?></div>
</div>
<?php endif; ?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('adoptions') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Adoption Applications</div>
      <div class="pc-head-sub">
        Manage adoption applications across all listings. Mark a listing available for adoption on its
        <a href="<?= $b ?>/admin/listings" class="link">edit page</a>.
      </div>
    </div>
  </div>

  <!-- Tab nav -->
  <div class="adopt-tabs">
    <a href="?tab=pending"
       class="tabs-btn <?= $tab === 'pending' ? 'active' : '' ?>">
      Pending
      <?php if ($pendingTotal > 0): ?>
        <span class="badge badge-pending" style="margin-left:.25rem"><?= $pendingTotal ?></span>
      <?php endif; ?>
    </a>
    <a href="?tab=listings" class="tabs-btn <?= $tab === 'listings' ? 'active' : '' ?>">Listings</a>
    <a href="?tab=settings" class="tabs-btn <?= $tab === 'settings' ? 'active' : '' ?>">Settings</a>
  </div>

  <?php if ($tab === 'pending'): ?>
  <!-- ── PENDING TAB ──────────────────────────────────────────────────── -->
  <?php if ($pendingTotal === 0): ?>
  <div class="empty">
    <div class="empty-icon"><?= View::icon('check-badge', ['width' => 32, 'height' => 32]) ?></div>
    <div class="empty-title">No pending applications.</div>
    <div>All caught up. New applications will appear here when fans apply.</div>
  </div>
  <?php else: ?>
  <div style="padding:0">
    <table class="data-table">
      <thead>
        <tr>
          <th>Applicant</th>
          <th>Listing</th>
          <th>Message</th>
          <th class="nowrap">Applied</th>
          <th class="table-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending as $app): ?>
        <?php $fl = $listing_map[(int) $app['fanlisting_id']] ?? null; ?>
        <tr>
          <td>
            <div class="text-strong"><?= View::e($app['name']) ?></div>
            <div class="form-hint">
              <a href="mailto:<?= View::e($app['email']) ?>" class="link"><?= View::emailWrap($app['email']) ?></a>
            </div>
            <?php if ($app['url'] !== ''): ?>
            <div class="form-hint">
              <a href="<?= View::e($app['url']) ?>" target="_blank" rel="noopener" class="link link-break"><?= View::e($app['url']) ?></a>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($fl !== null): ?>
              <a href="<?= $b ?>/admin/adoptions/<?= (int) $fl->id ?>?status=new" class="link"><?= View::e($fl->name) ?></a>
              <?php if ($fl->subject !== ''): ?>
              <div class="form-hint"><?= View::e($fl->subject) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-dim">#<?= (int) $app['fanlisting_id'] ?></span>
            <?php endif; ?>
          </td>
          <td class="cell-truncate">
            <?php if (($app['message'] ?? '') !== ''): ?>
              <span title="<?= View::e((string) $app['message']) ?>">
                <?= View::e(mb_strimwidth((string) $app['message'], 0, 100, '…')) ?>
              </span>
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>
          <td class="nowrap text-dim"><?= View::e(date('Y-m-d', strtotime((string) $app['applied_at']))) ?></td>
          <td class="table-actions">
            <div class="data-actions">
              <form method="POST" action="<?= $b ?>/admin/adoptions/<?= (int) $app['id'] ?>/approve"
                    class="action-form-inline">
                <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
                <input type="hidden" name="_return" value="pending">
                <button type="submit" class="btn btn-success btn-xs">Approve</button>
              </form>
              <form method="POST" action="<?= $b ?>/admin/adoptions/<?= (int) $app['id'] ?>/reject"
                    class="action-form-inline"
                    data-confirm="Reject this application from <?= View::e($app['name']) ?>?">
                <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
                <input type="hidden" name="_return" value="pending">
                <button type="submit" class="btn btn-ghost btn-xs">Reject</button>
              </form>
              <a href="mailto:<?= View::e($app['email']) ?>" class="btn btn-secondary btn-xs">Email</a>
              <?php if ($fl !== null): ?>
              <a href="<?= $b ?>/admin/adoptions/<?= (int) $fl->id ?>?status=new"
                 class="btn btn-ghost btn-xs">Review</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php elseif ($tab === 'listings'): ?>
  <!-- ── LISTINGS TAB ─────────────────────────────────────────────────── -->
  <?php if (empty($adoption_listings)): ?>
  <div class="empty">
    <div class="empty-icon"><?= View::icon('adoptions', ['width' => 32, 'height' => 32]) ?></div>
    <div class="empty-title">No listings marked for adoption.</div>
    <div>Open a listing&rsquo;s edit page and enable &ldquo;Available for adoption&rdquo; to start accepting applications.</div>
    <a href="<?= $b ?>/admin/listings" class="btn btn-primary btn-sm"><?= View::icon('star') ?> Go to Listings</a>
  </div>
  <?php else: ?>
  <div style="padding:0">
    <table class="data-table">
      <thead>
        <tr>
          <th class="table-num">#</th>
          <th>Listing</th>
          <th class="table-num">Pending</th>
          <th class="table-num">Approved</th>
          <th class="table-num">Total</th>
          <th class="table-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($adoption_listings as $i => $item): ?>
        <?php $fl = $item['listing']; ?>
        <tr>
          <td class="table-num text-dim"><?= $i + 1 ?></td>
          <td>
            <a href="<?= View::e($fl->url) ?>" target="_blank" rel="noopener" class="link">
              <?= View::e($fl->name) ?>
            </a>
            <?php if ($fl->subject !== ''): ?>
              <span class="form-hint" style="display:inline;margin-left:.4rem"><?= View::e($fl->subject) ?></span>
            <?php endif; ?>
          </td>
          <td class="table-num">
            <?php if ($item['count_new'] > 0): ?>
              <span class="badge badge-pending"><?= (int) $item['count_new'] ?></span>
            <?php else: ?>
              <span class="text-dim">0</span>
            <?php endif; ?>
          </td>
          <td class="table-num">
            <?php if ($item['count_approved'] > 0): ?>
              <span class="badge badge-current"><?= (int) $item['count_approved'] ?></span>
            <?php else: ?>
              <span class="text-dim">0</span>
            <?php endif; ?>
          </td>
          <td class="table-num text-dim"><?= (int) $item['count_all'] ?></td>
          <td class="table-actions">
            <a href="<?= $b ?>/admin/adoptions/<?= (int) $fl->id ?>" class="btn btn-ghost btn-xs">
              View Applications
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- ── SETTINGS TAB ─────────────────────────────────────────────────── -->
  <div class="pc-section">
    <div class="listing-field-section">
      <div class="listing-field-row">
        <label class="listing-field-label">Per-listing adoption</label>
        <div>
          <p style="margin:0 0 .5rem">To accept adoption applications for a listing, open its
          edit page and enable <strong>Available for adoption</strong>. Fans can then submit
          applications via the public adopt embed.</p>
          <a href="<?= $b ?>/admin/listings" class="btn btn-ghost btn-sm">Go to Listings &rarr;</a>
        </div>
      </div>
      <div class="listing-field-row">
        <label class="listing-field-label">Public embed</label>
        <div>
          <p style="margin:0 0 .5rem">Drop the adopt embed into any page to show the application form:</p>
          <pre style="margin:0;font-size:var(--fs-xs);background:var(--bg-subtle);padding:.6rem .8rem;border-radius:var(--radius-sm);border:1px solid var(--border);overflow-x:auto">$luster_fanlisting_id = 1;
require '/path/to/luster/public/embed/adopt.php';</pre>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
