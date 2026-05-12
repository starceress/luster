<?php
use Luster\Core\View;
/** @var array[] $adoption_listings  Each: ['listing' => Listing, 'count_new' => int, 'count_all' => int] */
/** @var string  $base_url */
/** @var string  $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('heart') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Adoption Applications</div>
      <div class="pc-head-sub">
        Listings marked available for adoption, and applications received.
        Mark a listing for adoption on its
        <a href="<?= $b ?>/admin/listings" class="link">edit page</a>.
      </div>
    </div>
  </div>

  <?php if (empty($adoption_listings)): ?>
  <div class="pc-section">
    <p class="form-hint">No listings are currently marked for adoption. Open a listing's edit page and enable &ldquo;Available for adoption&rdquo; to start accepting applications.</p>
  </div>
  <?php else: ?>
  <div class="pc-section" style="padding:0">
    <table class="data-table">
      <thead>
        <tr>
          <th>Listing</th>
          <th class="table-num">New</th>
          <th class="table-num">Total</th>
          <th class="table-actions"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($adoption_listings as $item): ?>
        <?php $fl = $item['listing']; ?>
        <tr>
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
              <span class="badge badge-pending"><?= $item['count_new'] ?></span>
            <?php else: ?>
              0
            <?php endif; ?>
          </td>
          <td class="table-num"><?= $item['count_all'] ?></td>
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
</div>
